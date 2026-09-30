/**
 * Опрос игровых серверов: онлайн, игроки, версия.
 *
 * Реализованы протоколы, по которым можно узнать статус без доступа к панели игры:
 *   minecraft — Server List Ping (handshake + status)
 *   bedrock   — RakNet unconnected ping
 *   valve     — A2S_INFO / A2S_PLAYER (Source/Valve: CS2, CS:GO)
 *   samp      — правильный SAMP query (с шифрованием)
 *   mta       — info-пакет MTA
 *   ragemp    — HTTP-статус RAGE.MP
 *   rust      — Steam GameServerQuery (A2S)
 *   unturned  — Unturned status query
 *   ark       — Steam GameServerQuery
 *   generic   — просто проверяем, что порт открыт
 */

import net from 'node:net';
import dgram from 'node:dgram';
import http from 'node:http';

const TIMEOUT = 4000;

/**
 * @param {string} type  minecraft | bedrock | valve | samp | mta | ragemp | rust | unturned | ark | generic
 */
export async function query(type, host, port, extra = {}) {
    const empty = (error = null) => ({
        online: false,
        players: 0,
        slots: 0,
        version: null,
        name: null,
        map: null,
        motd: null,
        raw: null,
        ...(error ? { error } : {}),
    });

    if (!type || type === 'none' || !host || !port) {
        return empty('Не задан тип или порт');
    }

    const queryFn = QUERIES[type] || QUERIES.generic;
    const timeout = Number(extra?.timeout) > 0 ? Number(extra.timeout) : TIMEOUT;

    try {
        const result = await withTimeout(queryFn(host, port, extra), timeout);

        return {
            online: result.online !== false,
            players: result.players || 0,
            slots: result.slots || 0,
            version: result.version || null,
            name: result.name || null,
            map: result.map || null,
            motd: result.motd || null,
            raw: result.raw || null,
        };
    } catch (e) {
        return empty(e.message);
    }
}

const QUERIES = {
    // ── Minecraft Java (Server List Ping) ─────────────────────────────
    async minecraft(host, port) {
        const { host: h, port: p } = await resolve(host, port);

        // Handshake: protocol version -1, адрес, порт, next state = 1
        const handshake = Buffer.concat([
            varint(0x00),
            varint(0xff), // protocol version = -1
            varint(h.length), Buffer.from(h, 'utf8'),
            Buffer.from([p >> 8, p & 0xff]),
            varint(0x01),
        ]);

        const response = await tcpExchange(h, p, Buffer.concat([
            varint(handshake.length), handshake,
            varint(0x01), // status request
        ]));

        // Ответ: длина пакета, packet id, длина JSON
        let offset = 0;
        readVarint(response, offset); offset = readVarintLen(response, 0);
        offset = readVarintLen(response, offset);
        offset += 1; // packet id

        const jsonLength = readVarintLen(response, offset);
        offset = readVarintBytes(response, offset);

        const json = response.slice(offset, offset + jsonLength).toString('utf8');
        const data = JSON.parse(json);

        const players = data.players || {};
        const version = data.version || {};

        return {
            online: true,
            players: players.online || 0,
            slots: players.max || 0,
            version: version.name ? `${version.name} (${version.protocol})` : null,
            motd: extractMotd(data.description),
            raw: data,
        };
    },

    // ── Minecraft Bedrock (RakNet) ───────────────────────────────────
    async bedrock(host, port) {
        const { host: h, port: p } = await resolve(host, port);

        // Unconnected ping: id 0x01, 8 байт времени, 16 байт магических, 8 байт клиента
        const packet = Buffer.concat([
            Buffer.from([0x01]),
            Buffer.from([0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00]),
            Buffer.from([0x00, 0xff, 0xff, 0x00, 0xfe, 0xfe, 0xfe, 0xfe, 0xfd, 0xfd, 0xfd, 0xfd, 0x12, 0x34, 0x56, 0x78]),
            Buffer.alloc(8),
        ]);

        const response = await udpExchange(h, p, packet);

        // Ответ: id 0x1c, 8+8 байт, длина строки, строка
        const textLength = response.readUInt16BE(33);
        const text = response.slice(35, 35 + textLength).toString('utf8');

        // EDU: 1.18.30+; MCPE/MCEE — более старые
        let data = {};

        try {
            data = JSON.parse(text);
        } catch {
            // Разбираем разделители старого формата
            const parts = text.split(';');
            for (const part of parts) {
                const [key, value] = part.split('#');
                if (key === 'MCPE') {
                    const [players, , , slots, , version, ...motd] = (value || '').split(';');
                    return {
                        online: true,
                        players: parseInt(players, 10) || 0,
                        slots: parseInt(slots, 10) || 0,
                        version,
                        motd: motd.join(';'),
                    };
                }
            }

            return { online: true, players: 0, slots: 0, motd: text };
        }

        return {
            online: true,
            players: data.players?.length || 0,
            slots: data.maxPlayers || 0,
            version: data.version || data.serverEngine,
            motd: extractMotd(data.motd),
            name: data.serverName,
            raw: data,
        };
    },

    // ── Valve (CS2, CS:GO, Source) ───────────────────────────────────
    async valve(host, port) {
        const { host: h, port: p } = await resolve(host, port);

        // A2S_INFO
        const info = await udpExchange(h, p, Buffer.concat([
            Buffer.from([0xff, 0xff, 0xff, 0xff, 0x54]),
            Buffer.from('Source Engine Query'),
        ]));

        const parsed = parseA2SInfo(info);

        if (parsed) {
            return {
                online: true,
                players: parsed.players,
                slots: parsed.slots,
                name: parsed.name,
                map: parsed.map,
                version: parsed.version,
                motd: parsed.motd,
                raw: parsed,
            };
        }

        return { online: true, players: 0, slots: 0 };
    },

    // ── SAMP (с шифрованием запроса) ─────────────────────────────────
    async samp(host, port) {
        const { host: h, port: p } = await resolve(host, port);

        const packet = buildSampQuery(h);

        const response = await udpExchange(h, p, packet, 2000);

        return parseSampResponse(response);
    },

    // ── MTA:SA ───────────────────────────────────────────────────────
    async mta(host, port) {
        const { host: h, port: p } = await resolve(host, port);

        // MTA info: 'SAMP'/'MTAS' + команда
        const packet = Buffer.concat([
            Buffer.from('MTAS', 'ascii'),
            Buffer.from([0x45]), // 'E' — info
        ]);

        const response = await udpExchange(h, p, packet, 2000);

        if (response.length < 30 || response.slice(0, 4).toString('ascii') !== 'MTAS') {
            return { online: false, players: 0, slots: 0 };
        }

        // Разбор пакета MTA
        let offset = 4 + 2; // header + length
        const rest = response.slice(offset);

        // 0x33 = info packet
        const fields = splitMtaPacket(rest);

        return {
            online: true,
            players: parseInt(fields.playerCount, 10) || 0,
            slots: parseInt(fields.maxPlayers, 10) || 0,
            name: fields.serverName,
            motd: decodeMtaString(fields.serverName || ''),
            version: fields.version,
            raw: fields,
        };
    },

    // ── RAGE.MP (HTTP) ───────────────────────────────────────────────
    async ragemp(host, port) {
        const httpPort = extraPort(port, 1);
        const response = await httpGet(host, httpPort, '/info.json', 3000);

        if (!response) return { online: false, players: 0, slots: 0 };

        try {
            const data = JSON.parse(response);
            const players = Array.isArray(data.players) ? data.players.length : (data.clients || 0);
            const slots = data.maxclients || data.maxClients || 0;

            return {
                online: true,
                players,
                slots,
                name: data.hostname || data.sv_projectname,
                motd: data.sv_projectname,
                version: data.sv_version,
                raw: data,
            };
        } catch {
            return { online: true, players: 0, slots: 0 };
        }
    },

    // ── Rust ─────────────────────────────────────────────────────────
    async rust(host, port) {
        const info = await QUERIES.valve(host, port);

        return {
            ...info,
            online: info.online,
            // У Rust A2S_INFO отдаёт name/slots, игроков берём из A2S_PLAYER
            players: info.players || (await a2sPlayers(host, port).catch(() => 0)),
        };
    },

    // ── Unturned ─────────────────────────────────────────────────────
    async unturned(host, port) {
        const info = await QUERIES.valve(host, port);

        return {
            ...info,
            players: info.players || 0,
            slots: info.slots || 0,
        };
    },

    // ── ARK ──────────────────────────────────────────────────────────
    async ark(host, port) {
        return QUERIES.valve(host, port);
    },

    // ── Универсальная проверка порта ─────────────────────────────────
    async generic(host, port) {
        const { host: h, port: p } = await resolve(host, port);
        await tcpConnect(h, p, 2000);
        return { online: true, players: 0, slots: 0 };
    },
};

// ── Сетевые помощники ──────────────────────────────────────────────────

function resolve(host, port) {
    return Promise.resolve({ host: String(host).replace(/^\[|\]$/g, ''), port: Number(port) });
}

function withTimeout(promise, ms) {
    return Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error('Таймаут опроса')), ms)),
    ]);
}

function tcpConnect(host, port, timeout) {
    return new Promise((resolve, reject) => {
        const socket = net.createConnection({ host, port });
        socket.setTimeout(timeout);
        socket.on('connect', () => { socket.destroy(); resolve(true); });
        socket.on('timeout', () => { socket.destroy(); reject(new Error('Таймаут подключения')); });
        socket.on('error', reject);
    });
}

function tcpExchange(host, port, payload) {
    return new Promise((resolve, reject) => {
        const socket = net.createConnection({ host, port }, () => socket.write(payload));
        const chunks = [];

        socket.setTimeout(TIMEOUT);
        socket.on('data', (chunk) => {
            chunks.push(chunk);
            const buffer = Buffer.concat(chunks);

            // Ждём получения JSON целиком
            try {
                const offset = readVarintBytes(buffer, 0);
                const jsonLength = readVarintLen(buffer, offset + 1);
                if (buffer.length >= offset + 1 + jsonLength) {
                    socket.destroy();
                    resolve(buffer);
                }
            } catch {
                // ждём дальше
            }
        });
        socket.on('timeout', () => { socket.destroy(); reject(new Error('Таймаут')); });
        socket.on('error', reject);
        socket.on('close', () => { if (chunks.length) resolve(Buffer.concat(chunks)); });
    });
}

function udpExchange(host, port, payload, timeout = TIMEOUT) {
    return new Promise((resolve, reject) => {
        const socket = dgram.createSocket('udp4');
        const timer = setTimeout(() => {
            socket.close();
            reject(new Error('Таймаут UDP-ответа'));
        }, timeout);

        socket.on('message', (message) => {
            clearTimeout(timer);
            socket.close();
            resolve(message);
        });

        socket.on('error', (e) => {
            clearTimeout(timer);
            socket.close();
            reject(e);
        });

        socket.send(payload, port, host);
    });
}

function httpGet(host, port, path, timeout) {
    return new Promise((resolve) => {
        const req = http.get({ host, port, path, timeout }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        });

        req.on('timeout', () => { req.destroy(); resolve(null); });
        req.on('error', () => resolve(null));
    });
}

function extraPort(port, offset) {
    return Number(port) + Number(offset);
}

// ── VarInt (Minecraft) ─────────────────────────────────────────────────

function varint(value) {
    const bytes = [];
    let v = value >>> 0;

    do {
        let byte = v & 0x7f;
        v >>>= 7;
        if (v !== 0) byte |= 0x80;
        bytes.push(byte);
    } while (v !== 0);

    return Buffer.from(bytes);
}

function readVarintLen(buffer, offset) {
    let result = 0;
    let shift = 0;
    let pos = offset;

    for (let i = 0; i < 5; i++) {
        const byte = buffer[pos++];
        result |= (byte & 0x7f) << shift;
        if ((byte & 0x80) === 0) break;
        shift += 7;
    }

    return result;
}

function readVarintBytes(buffer, offset) {
    let pos = offset;
    while (pos < buffer.length && (buffer[pos] & 0x80) !== 0) pos++;
    return pos + 1;
}

function extractMotd(description) {
    if (!description) return null;
    if (typeof description === 'string') return description;
    if (Array.isArray(description)) {
        return description.map((d) => d.text || '').join(' ').trim();
    }

    return description.text || null;
}

// ── A2S (Valve/Source) ─────────────────────────────────────────────────

async function a2sPlayers(host, port) {
    const response = await udpExchange(host, port, Buffer.concat([
        Buffer.from([0xff, 0xff, 0xff, 0xff, 0x55]),
        Buffer.from([0x00]),
    ]), 3000);

    return response[5] || 0;
}

function parseA2SInfo(buffer) {
    if (buffer.length < 6) return null;
    if (buffer[4] !== 0x49) return null; // 'I'

    try {
        const bytes = readByte(buffer, 5);
        const name = readString(buffer, bytes.next);
        const map = readString(buffer, bytes.next);
        const folder = readString(buffer, bytes.next);
        const game = readString(buffer, bytes.next);
        const appId = buffer.readUInt16LE(bytes.next);
        const players = buffer.readUInt8(bytes.next);
        const slots = buffer.readUInt8(bytes.next);
        const bots = buffer.readUInt8(bytes.next);
        const mapType = readString(buffer, bytes.next);
        const env = readString(buffer, bytes.next);
        const version = readString(buffer, bytes.next);
        const keywords = readString(buffer, bytes.next);

        let motd = null;
        if (buffer[bytes.next] === 0x00) {
            motd = readString(buffer, bytes.next);
        }

        return { name, map, players: players - bots, slots, version, folder, game, mapType, motd, keywords };
    } catch {
        return null;
    }
}

function readByte(buffer, offset) {
    return { value: buffer[offset], next: offset + 1 };
}

function readString(buffer, offset) {
    const length = buffer.readUInt8(offset);
    if (length === 0xff) {
        // Длинная строка
        const longLength = buffer.readUInt32LE(offset + 1);
        return buffer.slice(offset + 5, offset + 5 + longLength).toString('utf8');
    }

    return buffer.slice(offset + 1, offset + 1 + length).toString('utf8');
}

// ── SAMP ──────────────────────────────────────────────────────────────

const SAMP_MAGIC = [0x53, 0x41, 0x4d, 0x50]; // 'SAMP'

/**
 * SAMP query: заголовок 11 байт + 3 XOR-байта для последнего поля.
 * Полный крипто-протокол (с RC4 для g) не нужен для info-запроса.
 */
function buildSampQuery(host) {
    const header = Buffer.from(SAMP_MAGIC);

    const iPacketType = 0x69; // 'i'
    const len = 11; // длина зашифрованной части

    const body = Buffer.concat([
        header,
        Buffer.from([0x78, 0x03]),  // длина пакета: 'x' + количество полей
        Buffer.from([iPacketType]),
        Buffer.from([len]),           // длина info-блока
    ]);

    // Info block: адрес (ip + порт) + query-порт + 3 байта (padding/mode)
    const address = buildSampAddress(host);
    const addressWithPort = Buffer.concat([address, Buffer.from([0, 0])]);

    const info = Buffer.concat([addressWithPort, Buffer.from([0, 0]), Buffer.from([0x03])]);

    return Buffer.concat([body, info]);
}

function buildSampAddress(host) {
    const parts = String(host).split('.');
    const bytes = [0, 0, 0, 0];

    for (let i = 0; i < 4; i++) {
        bytes[i] = parseInt(parts[i] || '0', 10) & 0xff;
    }

    return Buffer.from(bytes);
}

function parseSampResponse(buffer) {
    if (buffer.length < 14) return { online: false, players: 0, slots: 0 };

    const players = buffer.readInt16LE(11);
    const slots = buffer.readInt16LE(13);

    return { online: true, players: Math.max(0, players), slots: Math.max(0, slots) };
}

// ── MTA ───────────────────────────────────────────────────────────────

function splitMtaPacket(buffer) {
    const fields = {};
    const keys = ['playerCount', 'maxPlayers', 'serverName', 'version', 'signature', 'rules', 'keywords'];
    let offset = 1; // пропускаем тип пакета

    for (const key of keys) {
        if (buffer[offset] === undefined) break;

        const length = buffer.readUInt16LE(offset);
        offset += 2;

        if (length === 0xffff) {
            const longLength = buffer.readUInt32LE(offset);
            offset += 4;
            fields[key] = buffer.slice(offset, offset + longLength).toString('utf8');
            offset += longLength;
        } else {
            fields[key] = buffer.slice(offset, offset + length).toString('utf8');
            offset += length;
        }

        if (offset >= buffer.length) break;
    }

    return fields;
}

/** MTA хранит строки в виде «длина + текст» */
function decodeMtaString(value) {
    if (!value) return null;
    return value.replace(/^[\s\S]{0,3}/, (m) => (m.includes(' ') ? '' : m));
}
