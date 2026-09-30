/**
 * Драйвер Proxmox LXC: игровой сервер работает в отдельном контейнере.
 *
 * Требования к ноде:
 *   • установлен Proxmox VE 8+ с включённым pve-container;
 *   • агент ходит по SSH (или по API) к PVE-хосту;
 *   • в настройках указаны pve_host / pve_user / storage / template.
 *
 * Агент сам вызывает `pct create` / `pct start` и тянет логи через `pct console`.
 */

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import fsp from 'node:fs/promises';
import { BaseRuntime, delay } from './base.js';
import { directorySize } from '../utils/fsx.js';

const exec = promisify(execFile);

export class LxcRuntime extends BaseRuntime {
    constructor(config, logger) {
        super(config, logger);

        this.pveHost = config.lxc.pveHost;
        this.pveUser = config.lxc.pveUser;
        this.template = config.lxc.template;
        this.storage = config.lxc.storage;
        this.bridge = config.lxc.bridge;

        this.user = config.systemUser;
        this.memorySwap = 2048; // под гид на игру внутри контейнера
        this.diskCache = null;
    }

    get name() {
        return 'lxc';
    }

    vmidFor(serverId) {
        // Диапазон 90000+, чтобы не пересекаться с системными контейнерами
        return 90000 + Number(serverId);
    }

    containerName(serverId) {
        return `gamedock-${serverId}`;
    }

    // ── Проверка доступности ──────────────────────────────────────────

    async isAvailable() {
        if (!this.pveHost) {
            return { ok: false, reason: 'Не задан lxc.pveHost (адрес Proxmox-хоста)' };
        }

        try {
            const { stdout } = await exec('ssh', [
                '-o', 'BatchMode=yes',
                '-o', 'ConnectTimeout=8',
                `${this.pveUser}@${this.pveHost}`,
                'pveversion',
            ], { timeout: 12000 });

            return { ok: true, version: stdout.trim() };
        } catch (e) {
            return {
                ok: false,
                reason: `Нет SSH-доступа к Proxmox (${this.pveUser}@${this.pveHost}): ${e.message}`,
            };
        }
    }

    // ── Выполнение команд на PVE ─────────────────────────────────────

    async pct(args, { timeout = 60000, raw = false } = {}) {
        const { stdout, stderr } = await exec('ssh', [
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'StrictHostKeyChecking=accept-new',
            `${this.pveUser}@${this.pveHost}`,
            `pct ${args}`,
        ], { timeout, maxBuffer: 8 * 1024 * 1024 });

        if (stderr && stderr.trim() && !stdout.trim()) {
            throw new Error(stderr.trim().slice(0, 500));
        }

        return raw ? { stdout, stderr } : stdout;
    }

    // ── Жизненный цикл ───────────────────────────────────────────────

    async create(spec) {
        const serverId = spec.server.id;
        const vmid = this.vmidFor(serverId);
        const name = this.containerName(serverId);
        const resources = spec.server.resources || {};

        // Если контейнер уже есть — пересоздаём
        await this.destroyIfExists(vmid);

        const memory = Math.max(512, (resources.memory_mb || 1024) + this.memorySwap);
        const diskGb = Math.max(8, Math.ceil(((resources.disk_mb || 10240) + 5120) / 1024));

        const ports = serverPorts(spec);

        // Порты пробрасываем внутрь контейнера тем же номером — снаружи NAT не нужен
        const args = [
            `create ${vmid} ${this.template}`,
            `--hostname ${name}`,
            `--cores ${Math.max(1, Math.round((resources.cpu_percent || 50) / 100))}`,
            `--memory ${memory}`,
            `--swap 512`,
            `--rootfs ${this.storage}:${diskGb}`,
            `--net0 name=eth0,bridge=${this.bridge},ip=dhcp,firewall=0`,
            '--unprivileged 1',
            '--features nesting=1,keyctl=1,fuse=1',
            '--onboot 0',
            '--ostype debian',
            '--tags gamedock;managed',
        ];

        if (ports.game) args.push(`--mp0 ${ports.game}/tcp,mp=${ports.game}`);
        if (ports.query) args.push(`--mp1 ${ports.query}/tcp,mp=${ports.query}`);
        if (ports.rcon) args.push(`--mp2 ${ports.rcon}/tcp,mp=${ports.rcon}`);

        this.log.info(`lxc: создаём контейнер ${vmid} (${name})`);

        try {
            await this.pct(args.join(' '), { timeout: 180000 });
        } catch (e) {
            return { ok: false, error: `Не удалось создать LXC-контейнер: ${e.message}` };
        }

        // Устанавливаем Java/JRE внутри, если игра на ней
        if (this.requiresJava(spec)) {
            await this.installJava(vmid);
        }

        // Копируем каталог сервера внутрь
        await this.pushDirectory(vmid, spec.path);

        return { ok: true, external_id: String(vmid), name };
    }

    requiresJava(spec) {
        const exec1 = spec.startup?.exec || '';
        const args = JSON.stringify(spec.startup?.args || []);

        return exec1.includes('java') || args.includes('java');
    }

    async installJava(vmid) {
        this.log.info(`lxc: устанавливаем JRE в контейнер ${vmid}`);

        try {
            await this.pct(
                `exec ${vmid} -- bash -lc "apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openjdk-21-jre-headless procps net-tools curl > /dev/null 2>&1 && echo done"`,
                { timeout: 600000 },
            );
        } catch (e) {
            this.log.warn(`lxc: не удалось установить JRE: ${e.message}`);
        }
    }

    async start(spec) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            await this.pct(`start ${vmid}`, { timeout: 120000 });
            await this.pct(`exec ${vmid} -- sh -c 'nohup /root/gamedock/run.sh > /root/gamedock/console.log 2>&1 &'`, { timeout: 30000 });

            this.log.info(`lxc: сервер ${spec.server.id} запущен`);

            return { ok: true, started_at: Math.floor(Date.now() / 1000) };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async stop(spec, options = {}) {
        const vmid = this.vmidFor(spec.server.id);
        const timeout = options.timeout || 30;

        try {
            // Сначала корректная остановка игры
            await this.pct(`exec ${vmid} -- sh -c "pkill -TERM -f 'java|server' 2>/dev/null; sleep ${Math.min(timeout, 20)}"`, { timeout: 40000 })
                .catch(() => null);

            await this.pct(`shutdown ${vmid} --timeout ${timeout}`, { timeout: (timeout + 30) * 1000 });

            return { ok: true };
        } catch (e) {
            if (/not running|no such/i.test(e.message)) return { ok: true };
            return { ok: false, error: e.message };
        }
    }

    async kill(spec) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            await this.pct(`stop ${vmid} --skiplock 1`, { timeout: 30000 });
            return { ok: true };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async isRunning(spec) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            const status = await this.pct(`status ${vmid}`, { timeout: 15000 });
            return /running/i.test(status);
        } catch {
            return false;
        }
    }

    async stats(spec) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            const output = await this.pct(`exec ${vmid} -- sh -c "ps -eo pcpu,rss --no-headers 2>/dev/null || true"`, { timeout: 20000 });

            let cpu = 0;
            let memoryKb = 0;

            for (const line of output.trim().split('\n')) {
                const [cpuPart, rssPart] = line.trim().split(/\s+/);
                cpu += parseFloat(cpuPart) || 0;
                memoryKb += parseInt(rssPart, 10) || 0;
            }

            return {
                cpu: Number(cpu.toFixed(2)),
                memory_mb: Math.round(memoryKb / 1024),
                disk_mb: spec.lastDiskMb || 0,
                net_in: 0,
                net_out: 0,
                players: spec.metrics?.players || 0,
                uptime: spec.startedAt ? Math.floor(Date.now() / 1000) - spec.startedAt : 0,
            };
        } catch {
            return { cpu: 0, memory_mb: 0, disk_mb: spec.lastDiskMb || 0, net_in: 0, net_out: 0, players: 0, uptime: 0 };
        }
    }

    async delete(spec, { purge = true } = {}) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            await this.pct(`destroy ${vmid} --force 1 --purge 1`, { timeout: 120000 });
        } catch (e) {
            if (!/no such/i.test(e.message)) {
                return { ok: false, error: e.message };
            }
        }

        return { ok: true, purged: purge };
    }

    async setLimits(spec, resources) {
        const vmid = this.vmidFor(spec.server.id);
        const running = await this.isRunning(spec);

        const args = [`set ${vmid}`];

        if (resources.memory_mb) {
            args.push(`--memory ${resources.memory_mb + this.memorySwap}`);
        }

        if (resources.cpu_percent) {
            args.push(`--cores ${Math.max(1, Math.round(resources.cpu_percent / 100))}`);
        }

        if (args.length === 1) {
            return { ok: true, message: 'Нечего обновлять' };
        }

        try {
            await this.pct(args.join(' '), { timeout: 30000 });

            return {
                ok: true,
                requires_restart: running,
                message: running ? 'Лимиты применятся после перезапуска контейнера' : 'Лимиты обновлены',
            };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async exec(spec, command) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            const output = await this.pct(`exec ${vmid} -- sh -c ${JSON.stringify(String(command))}`, { timeout: 30000 });
            return { ok: true, output };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async writeConsole(spec, command) {
        const vmid = this.vmidFor(spec.server.id);

        try {
            await this.pct(
                `exec ${vmid} -- sh -c "printf '%s\\n' ${JSON.stringify(command)} >> /root/gamedock/console.in"`,
                { timeout: 15000 },
            );

            return { ok: true };
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    async logsTail(serverId, lines = 200) {
        const vmid = this.vmidFor(serverId);

        try {
            return await this.pct(`exec ${vmid} -- tail -n ${lines} /root/gamedock/console.log`, { timeout: 20000 });
        } catch {
            return '';
        }
    }

    // ── Вспомогательное ──────────────────────────────────────────────

    async destroyIfExists(vmid) {
        try {
            await this.pct(`destroy ${vmid} --force 1 --purge 1`, { timeout: 60000 });
        } catch {
            // Контейнера нет — это нормально
        }
    }

    /** Раскладывает локальный каталог сервера внутрь контейнера через tar по SSH. */
    async pushDirectory(vmid, localPath) {
        this.log.debug(`lxc: копируем ${localPath} в контейнер ${vmid}`);

        return new Promise((resolve) => {
            const child = execFile('ssh', [
                '-o', 'BatchMode=yes',
                `${this.pveUser}@${this.pveHost}`,
                `pct exec ${vmid} -- sh -c "mkdir -p /root/gamedock && tar -x -C /root/gamedock"`,
            ], { timeout: 600000, maxBuffer: 1024 * 1024 });

            child.on('error', () => resolve(false));
            child.on('close', (code) => resolve(code === 0));
        });
    }
}

function serverPorts(spec) {
    const ports = spec.server.ports || {};
    return { game: ports.game, query: ports.query, rcon: ports.rcon };
}
