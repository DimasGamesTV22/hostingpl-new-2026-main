FROM node:20-alpine

WORKDIR /app

# Зависимостей нет — образ минимальный. Копируем только исходники.
COPY package.json ./
COPY src ./src

RUN addgroup -g 1000 gamedock \
 && adduser -u 1000 -D -G gamedock gamedock \
 && chown -R gamedock:gamedock /app

USER gamedock

ENV NODE_ENV=production \
    PORT=8090

EXPOSE 8090

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD node -e "process.exit(0)"

CMD ["node", "src/index.js"]
