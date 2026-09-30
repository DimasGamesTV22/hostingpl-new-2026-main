/**
 * Безопасная работа с файлами внутри каталога сервера.
 *
 * Главная задача — не дать агенту выйти за пределы каталога игрового сервера,
 * даже если панель прислала «../../../etc/passwd».
 */

import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';

export class PathGuard {
    constructor(root, logger) {
        this.root = path.resolve(root);
        this.logger = logger;
    }

    /**
     * Нормализует путь относительно корня.
     * Бросает ошибку, если путь ведёт наружу или содержит недопустимые символы.
     */
    resolve(relative = '.') {
        if (relative === undefined || relative === null) relative = '.';

        let input = String(relative).replace(/\\/g, '/').trim();

        if (input === '' || input === '.') return this.root;

        // Отсекаем абсолютные пути: работаем только внутри корня
        input = input.replace(/^\/+/, '');

        if (input.includes('\0')) {
            throw new Error('Недопустимый путь: содержит нулевой байт');
        }

        const target = path.resolve(this.root, input);
        const normalizedRoot = this.root.endsWith(path.sep) ? this.root : this.root + path.sep;

        if (target !== this.root && !target.startsWith(normalizedRoot)) {
            throw new Error(`Путь выходит за пределы каталога сервера: ${relative}`);
        }

        return target;
    }

    /** Относительный путь от корня (для ответа панели). */
    relative(absolute) {
        return path.relative(this.root, absolute).replace(/\\/g, '/') || '.';
    }
}

/**
 * Рекурсивный листинг каталога с метаданными.
 */
export async function listDirectory(dir, options = {}) {
    const { showHidden = true, maxEntries = 2000, maxDepth = 8, depth = 0 } = options;

    let items;
    try {
        items = await fsp.readdir(dir, { withFileTypes: true });
    } catch (e) {
        throw new Error(`Не удалось прочитать каталог: ${e.code || e.message}`);
    }

    const entries = [];

    for (const item of items) {
        if (entries.length >= maxEntries) break;

        const name = item.name;
        if (!showHidden && name.startsWith('.')) continue;

        const full = path.join(dir, name);

        let stat;
        try {
            stat = await fsp.lstat(full);
        } catch {
            continue;
        }

        const isSymlink = item.isSymbolicLink();
        let target = stat;

        // Для символьных ссылок узнаём, куда они ведут
        if (isSymlink) {
            try {
                const real = await fsp.realpath(full);
                const realStat = await fsp.stat(real);
                target = realStat;
            } catch {
                target = stat; // битая ссылка
            }
        }

        let size = target.size;

        // Для директорий считаем содержимое (ограниченно)
        if (target.isDirectory() && depth < maxDepth) {
            const nested = await listDirectory(full, { ...options, depth: depth + 1 });
            size = nested.reduce((sum, e) => sum + (e.size || 0), 0);
        }

        entries.push({
            name,
            path: path.relative(options.root || dir, full).replace(/\\/g, '/'),
            type: target.isDirectory() ? 'dir' : target.isFile() ? 'file' : 'other',
            size: target.isDirectory() ? size : size,
            modified: Math.floor(stat.mtimeMs / 1000),
            mode: stat.mode & 0o7777,
            symlink: isSymlink,
            writable: isWritable(full),
        });
    }

    // Папки первыми, потом файлы, по имени
    entries.sort((a, b) => {
        if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
        return a.name.localeCompare(b.name, 'ru');
    });

    return entries;
}

export function isWritable(target) {
    try {
        fs.accessSync(target, fs.constants.W_OK);
        return true;
    } catch {
        return false;
    }
}

/**
 * Чтение текстового файла с защитой от огромных и бинарных файлов.
 */
export async function readTextFile(file, maxBytes = 2 * 1024 * 1024) {
    const stat = await fsp.stat(file);

    if (stat.size > maxBytes) {
        return { tooBig: true, size: stat.size, content: '', binary: false };
    }

    const buffer = await fsp.readFile(file);

    if (isBinary(buffer)) {
        return { tooBig: false, size: stat.size, content: '', binary: true };
    }

    return { tooBig: false, size: stat.size, content: buffer.toString('utf8'), binary: false };
}

/**
 * Определение бинарного файла: нулевые байты в первых 8 КБ.
 */
export function isBinary(buffer) {
    const length = Math.min(buffer.length, 8192);

    for (let i = 0; i < length; i++) {
        if (buffer[i] === 0) return true;
    }

    return false;
}

/**
 * Запись файла атомарно: пишем во временный файл и переименовываем.
 * Так панель никогда не увидит наполовину записанный конфиг.
 */
export async function writeFileAtomic(file, content) {
    const tmp = `${file}.gd-tmp-${process.pid}-${Date.now()}`;

    await fsp.writeFile(tmp, content, { encoding: 'utf8', mode: 0o644 });
    await fsp.rename(tmp, file);
}

export async function ensureDir(dir, mode = 0o755) {
    await fsp.mkdir(dir, { recursive: true, mode });

    try {
        await fsp.chmod(dir, mode);
    } catch { /* не критично */ }
}

export async function pathExists(target) {
    try {
        await fsp.access(target);
        return true;
    } catch {
        return false;
    }
}

/**
 * Рекурсивный подсчёт размера каталога.
 */
export async function directorySize(dir, maxDepth = 12, depth = 0) {
    if (depth > maxDepth) return 0;

    let total = 0;
    let items;

    try {
        items = await fsp.readdir(dir, { withFileTypes: true });
    } catch {
        return 0;
    }

    for (const item of items) {
        const full = path.join(dir, item.name);

        try {
            if (item.isDirectory()) {
                total += await directorySize(full, maxDepth, depth + 1);
            } else if (item.isFile()) {
                total += (await fsp.stat(full)).size;
            }
        } catch { /* пропускаем */ }
    }

    return total;
}

export async function removeRecursive(target) {
    await fsp.rm(target, { recursive: true, force: true });
}

/**
 * Поиск файлов и папок по имени (с ограничением глубины и результатов).
 */
export async function searchFiles(root, query, { maxDepth = 6, maxResults = 200, depth = 0 } = {}) {
    if (depth > maxDepth) return [];

    const needle = query.toLowerCase();
    const results = [];

    let items;
    try {
        items = await fsp.readdir(root, { withFileTypes: true });
    } catch {
        return [];
    }

    for (const item of items) {
        if (results.length >= maxResults) break;

        const full = path.join(root, item.name);

        if (item.name.toLowerCase().includes(needle)) {
            try {
                const stat = await fsp.lstat(full);
                results.push({
                    name: item.name,
                    path: path.relative(root, full).replace(/\\/g, '/'),
                    type: item.isDirectory() ? 'dir' : 'file',
                    size: stat.size,
                    modified: Math.floor(stat.mtimeMs / 1000),
                });
            } catch { /* noop */ }
        }

        if (item.isDirectory()) {
            results.push(...await searchFiles(full, query, { maxDepth, maxResults, depth: depth + 1 }));
        }
    }

    return results.slice(0, maxResults);
}

export { fsp, path };
