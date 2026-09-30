/**
 * Фабрика рантаймов: создаёт драйвер по имени и проверяет доступность.
 */

import { DockerRuntime } from './docker.js';
import { NativeRuntime } from './native.js';
import { LxcRuntime } from './lxc.js';

export async function createRuntimes(config, logger) {
    const runtimes = new Map();

    // Docker
    const docker = new DockerRuntime(config, logger, {
        socket: config.docker.socket,
        networkPrefix: config.docker.networkPrefix,
        defaultImage: config.docker.defaultImage,
        variant: 'docker',
    });

    runtimes.set('docker', docker);

    // Podman — тот же код, другой сокет
    const podman = new DockerRuntime(config, logger, {
        socket: config.podman.socket,
        networkPrefix: config.podman.networkPrefix,
        defaultImage: config.podman.defaultImage,
        variant: 'podman',
    });

    runtimes.set('podman', podman);

    // Proxmox LXC
    runtimes.set('lxc', new LxcRuntime(config, logger));

    // Native (systemd + cgroup v2)
    runtimes.set('native', new NativeRuntime(config, logger));

    return runtimes;
}

/**
 * Проверяет все рантаймы и возвращает карту «имя → {ok, reason}».
 * Панель показывает в админке, что реально доступно на ноде.
 */
export async function probeRuntimes(runtimes, logger) {
    const result = {};

    await Promise.all(
        [...runtimes.entries()].map(async ([name, runtime]) => {
            try {
                const probe = await runtime.isAvailable();
                result[name] = { ...probe, name };

                if (probe.ok) {
                    logger.info(`Рантайм ${name}: доступен${probe.version ? ` (${probe.version})` : ''}`);
                } else {
                    logger.warn(`Рантайм ${name}: недоступен — ${probe.reason}`);
                }
            } catch (e) {
                result[name] = { ok: false, reason: e.message, name };
                logger.warn(`Рантайм ${name}: ошибка проверки — ${e.message}`);
            }
        }),
    );

    return result;
}

export { DockerRuntime, NativeRuntime, LxcRuntime };
