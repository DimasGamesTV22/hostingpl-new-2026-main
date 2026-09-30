/**
 * Сбор системных метрик ноды: CPU, память, диск, нагрузка, сеть.
 * Работает и в контейнере, и на голом Debian.
 */

import os from 'node:os';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const exec = promisify(execFile);

let lastCpu = null;
let lastNet = null;

export async function collectSystem() {
    const [loadavg, meminfo, disk, net, version, bootTime] = await Promise.all([
        readLoadAvg(),
        readMemInfo(),
        readDiskInfo(),
        readNetInfo(),
        readOsVersion(),
        readBootTime(),
    ]);

    const totalMem = Math.round(os.totalmem() / 1048576);
    const freeMem = Math.round(os.freemem() / 1048576);

    return {
        cpu_cores: os.cpus().length,
        cpu_threads: os.cpus().length * (os.cpus()[0]?.model?.includes('HT') ? 2 : 1) || os.cpus().length,
        cpu_model: (os.cpus()[0]?.model || 'unknown').trim(),
        cpu_percent: await cpuUsage(),
        load_1: loadavg[0],
        load_5: loadavg[1],
        load_15: loadavg[2],

        memory_total_mb: totalMem,
        memory_used_mb: totalMem - freeMem,
        memory_free_mb: freeMem,

        disk_total_mb: disk.totalMb,
        disk_used_mb: disk.usedMb,
        disk_free_mb: disk.freeMb,
        disk_mount: disk.mount,
        disk_percent: disk.percent,

        network_in_mbps: net.inMbps,
        network_out_mbps: net.outMbps,

        os: `${os.type()} ${os.release()}`,
        kernel: `${os.type().toLowerCase()}-${os.release()}`,
        arch: os.arch(),
        hostname: os.hostname(),

        node_version: process.version,
        uptime_seconds: Math.floor(os.uptime()),
        boot_time: bootTime,
        agent_uptime: Math.floor(process.uptime()),

        // cgroup v2 — ограничения контейнера, если мы внутри него
        cgroup: await readCgroupLimits(),
    };
}

function readLoadAvg() {
    return new Promise((resolve) => {
        try {
            const [one = 0, five = 0, fifteen = 0] = os.loadavg();
            resolve([one, five, fifteen]);
        } catch {
            resolve([0, 0, 0]);
        }
    });
}

/**
 * Загрузка CPU: сравниваем счётчики с предыдущим замером.
 */
export async function cpuUsage() {
    const cpus = os.cpus();
    let idle = 0;
    let total = 0;

    for (const cpu of cpus) {
        for (const [type, value] of Object.entries(cpu.times)) {
            total += value;
            if (type === 'idle') idle += value;
        }
    }

    if (lastCpu) {
        const idleDiff = idle - lastCpu.idle;
        const totalDiff = total - lastCpu.total;
        const usage = totalDiff > 0 ? 100 - (100 * idleDiff) / totalDiff : 0;

        lastCpu = { idle, total };

        return Math.max(0, Math.min(100, Number(usage.toFixed(2))));
    }

    lastCpu = { idle, total };
    return 0;
}

async function readMemInfo() {
    try {
        const content = await fsp.readFile('/proc/meminfo', 'utf8');
        const get = (key) => {
            const match = content.match(new RegExp(`^${key}:\\s+(\\d+)`, 'm'));
            return match ? Math.round(Number(match[1]) / 1024) : 0; // kB → MB
        };

        return {
            total: get('MemTotal'),
            available: get('MemAvailable'),
            used: get('MemTotal') - get('MemAvailable'),
            swapTotal: get('SwapTotal'),
            swapUsed: get('SwapTotal') - get('SwapFree'),
        };
    } catch {
        return { total: 0, available: 0, used: 0, swapTotal: 0, swapUsed: 0 };
    }
}

async function readDiskInfo() {
    try {
        const { stdout } = await exec('df', ['-BM', '--output=size,used,avail,pcent,target', '/']);
        const lines = stdout.trim().split('\n').slice(1);

        // Берём строку с наибольшим размером (корень)
        let best = { sizeMb: 0, usedMb: 0, freeMb: 0, percent: 0, mount: '/' };

        for (const line of lines) {
            const [size, used, avail, , mount] = line.trim().split(/\s+/);
            const sizeMb = parseInt(size, 10) || 0;

            if (sizeMb > best.sizeMb) {
                best = {
                    sizeMb,
                    usedMb: parseInt(used, 10) || 0,
                    freeMb: parseInt(avail, 10) || 0,
                    percent: parseInt((pcent || '0').replace('%', ''), 10) || 0,
                    mount,
                };
            }
        }

        return { totalMb: best.sizeMb, usedMb: best.usedMb, freeMb: best.freeMb, percent: best.percent, mount: best.mount };
    } catch {
        return { totalMb: 0, usedMb: 0, freeMb: 0, percent: 0, mount: '/' };
    }
}

async function readNetInfo() {
    let rx = 0;
    let tx = 0;

    try {
        const content = await fsp.readFile('/proc/net/dev', 'utf8');

        for (const line of content.split('\n').slice(2)) {
            const [iface, data] = line.split(':');
            if (!data) continue;

            // Пропускаем виртуальные интерфейсы
            if (['lo', 'docker', 'veth', 'br-', 'virbr'].some((p) => iface.trim().startsWith(p))) {
                continue;
            }

            const values = data.trim().split(/\s+/).map(Number);
            rx += values[0] || 0;
            tx += values[8] || 0;
        }
    } catch {
        return { inMbps: 0, outMbps: 0 };
    }

    if (!lastNet) {
        lastNet = { rx, tx, ts: Date.now() };
        return { inMbps: 0, outMbps: 0 };
    }

    const now = Date.now();
    const seconds = (now - lastNet.ts) / 1000;
    const inMbps = seconds > 0 ? Math.max(0, ((rx - lastNet.rx) / 1024 / 1024) / seconds) : 0;
    const outMbps = seconds > 0 ? Math.max(0, ((tx - lastNet.tx) / 1024 / 1024) / seconds) : 0;

    lastNet = { rx, tx, ts: now };

    return { inMbps: Number(inMbps.toFixed(3)), outMbps: Number(outMbps.toFixed(3)) };
}

async function readOsVersion() {
    try {
        const content = await fsp.readFile('/etc/os-release', 'utf8');
        const name = content.match(/^PRETTY_NAME="?([^"\n]+)"?/m);
        return name ? name[1] : `${os.type()} ${os.release()}`;
    } catch {
        return `${os.type()} ${os.release()}`;
    }
}

async function readBootTime() {
    try {
        const stat = await fsp.stat('/proc/1');
        return Math.floor(stat.birthtimeMs / 1000) || null;
    } catch {
        return null;
    }
}

/**
 * Лимиты cgroup v2 — нужны, если агент сам работает в контейнере.
 */
async function readCgroupLimits() {
    try {
        const v2 = '/sys/fs/cgroup';

        if (!fs.existsSync(`${v2}/memory.max`)) {
            return { version: 1, memoryMax: null, cpuMax: null, pidsMax: null };
        }

        const read = async (file) => {
            try {
                const value = await fsp.readFile(`${v2}/${file}`, 'utf8');
                return value.trim();
            } catch {
                return null;
            }
        };

        return {
            version: 2,
            memoryMax: await read('memory.max'),
            memoryCurrent: await read('memory.current'),
            cpuMax: await read('cpu.max'),
            pidsMax: await read('pids.max'),
        };
    } catch {
        return { version: null };
    }
}

/**
 * Список процессов, запущенных агентом (для проверок и отладки).
 */
export function listManagedProcesses(registry) {
    const out = [];

    for (const [serverId, instance] of registry.entries()) {
        out.push({
            server_id: serverId,
            runtime: instance.runtime,
            pid: instance.pid,
            running: instance.isRunning(),
            started_at: instance.startedAt,
            restarts: instance.restarts,
        });
    }

    return out;
}
