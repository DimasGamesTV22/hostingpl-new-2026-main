FROM node:20-alpine

# Агент ноды. Внешних зависимостей ровно одна — ws (клиент WebSocket);
# всё остальное (работа с unix-сокетом, HTTP к Docker Engine, игровые query)
# реализовано вручную на встроенных модулях Node.
#
# В compose он запускается вместе с Docker-сокетом хоста, поэтому
# «контейнер-в-контейнере» здесь нужен осознанно: агенту нужно создавать
# контейнеры игровых серверов на той же ноде.
WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev --no-audit --no-fund

COPY bin ./bin
COPY src ./src

# Каталоги серверов и бэкапов создаём заранее, чтобы их можно было
# подмонтировать как volumes (права выставляются на хосте).
RUN addgroup -g 1000 gamedock \
 && adduser -u 1000 -D -G gamedock gamedock \
 && mkdir -p /home/gamedock/servers /home/gamedock/backups /opt/gamedock/game-images \
 && chown -R gamedock:gamedock /app /home/gamedock /opt/gamedock

USER gamedock

ENV NODE_ENV=production \
    GD_SERVERS_ROOT=/home/gamedock/servers \
    GD_BACKUPS_ROOT=/home/gamedock/backups \
    GD_TEMPLATES_ROOT=/opt/gamedock/game-images \
    GD_DOCKER_SOCKET=/var/run/docker.sock

VOLUME ["/home/gamedock/servers", "/home/gamedock/backups"]

# Агент не слушает TCP: он сам подключается к панели по WSS,
# поэтому EXPOSE здесь не нужен.

HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
    CMD node bin/gamedock-agent.js health >/dev/null 2>&1 || exit 1

CMD ["node", "src/index.js"]
