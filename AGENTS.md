# AGENTS.md — GameDock (панель игрового хостинга)

## Состав репозитория

Четыре независимых юнита. Общего рантайма и общего пакетного менеджера между ними нет.

| Каталог | Что это | Стек |
|---|---|---|
| `panel/` | Веб-панель, API, биллинг | Laravel 11 (PHP ≥ 8.2), Blade, Alpine.js, Tailwind, Vite |
| `agent/` | Демон ноды: процессы, файлы, бэкапы, метрики | Node ≥ 20, ESM, единственная зависимость `ws` |
| `bot/` | Telegram-бот (магазин + управление серверами) | Node ≥ 20, ESM, **ноль зависимостей** |
| `deploy/` | Установщики под Debian/Ubuntu, nginx, systemd | bash |
| `game-images/` | Скрипты установки игр (`install.sh` / `post-install.sh`) | bash |
| `docs/` | Документация, которую проверяют скрипты из `panel/tools/` | — |

- Корневых `package.json` / `composer.json` нет. Зависимости ставятся в каждом каталоге отдельно (`panel/`, `agent/`, `bot/`).
- CI нет (нет `.github/`), pre-commit нет. Проверки запускаются вручную.
- `panel/app/Modules/<Domain>/Providers/*ServiceProvider.php` — **только** регистрация сервисов в контейнере. Реальный код домена лежит в `panel/app/Services/<Domain>/` и `panel/app/Http/Controllers/<Area>/`. Не ищи логику в `Modules/`.
- Настройки не в `.env`: `setting('hosting.…')` и `config_get('resources.…')` читают дефолты из `panel/config/hosting.php` и переопределения из БД (админка). Локальные правки — `panel/config/hosting.local.php` (в `.gitignore`).

## Проверки запускать из корня репозитория

`cd panel && npm run check` **падает**: `check-api-doc.cjs` читает `docs/api.md` и `panel/routes/api.php` относительно cwd, а npm ставит cwd = `panel/`. Реальная цепочка (проверена — всё зелёное):

```
node panel/tools/check.cjs && node panel/tools/check-php.cjs \
  && node panel/tools/check-distros.cjs && node panel/tools/check-api-doc.cjs
```

`composer check` (из `panel/`) добавляет `php artisan view:cache` — единственный вариант, который ловит синтаксис Blade.

Эти проверки **не входят** в цепочку выше — запускай сам, когда трогаешь их предмет:

- `node panel/tools/check-migration-order.cjs` — порядок внешних ключей (иначе MySQL errno 150) + методы Blueprint, которых нет в Laravel 11.
- `node panel/tools/check-migration-index.cjs` — дублирующиеся индексы в миграциях.
- `node panel/tools/check-integrity.cjs` — потерянные `\`-переносы строк и `${` без `}` в `deploy/*.sh`.
- `node panel/tools/check-php-refs.cjs`, `trunc-check.cjs`, `check-js.cjs`, `check-heredocs.cjs`.
- `bash panel/tools/check-shell.sh` — `bash -n` + heredoc-ы + LF + прогоняет три bash-сьюта (`test-install-logic.sh`, `test-menu.sh`, `test-system-checks.sh`). Нужен bash; на Windows его нет, поэтому здесь эти проверки не гоняются.

⚠️ У `check-migration-order.cjs`, `check-migration-index.cjs`, `check-php-refs.cjs`, `check-integrity.cjs` **захардкожены абсолютные пути `C:/hostingpl/…`**. Работают, только пока репозиторий лежит именно тут.

## Тесты

- `cd panel && ./vendor/bin/phpunit` — SQLite `:memory:`, внешних сервисов не нужно.
- Один тест: `./vendor/bin/phpunit --filter PortAllocatorTest`. Один файл: `./vendor/bin/phpunit tests/Feature/ServerTest.php`.
- В `phpunit.xml` включены `failOnRisky`, `failOnWarning`, `beStrictAboutOutputDuringTests` и `executionOrder="random"` — любой `echo`/`dd` в тесте роняет прогон, а порядок выполнения меняется от запуска к запуску.
- `HOSTING_PANEL_URL`, `HOSTING_AGENT_TOKEN`, `TELEGRAM_BOT_TOKEN` в `phpunit.xml` — мёртвые, код их не читает. Реальный источник токена Telegram: `GD_TELEGRAM_BOT_TOKEN` / `setting('telegram.bot_token')`; в тестах пусто, поэтому `TelegramService` возвращает `null`.
- `cd agent && node --test tests/` (24 теста) и `cd bot && node --test tests/` (7 тестов). Один файл: `node --test tests/config.test.js`.
- PHP, composer и bash в этом окружении **не установлены** — PHP-часть локально проверить нельзя, только статическими скриптами.

## Источники правды

- **Матрица ОС** существует только в `SUPPORTED_SYSTEMS=(...)` в `deploy/install.sh` **и** `deploy/agent.sh`. `check-distros.cjs` сверяет их с `docs/installation.md` и `docs/requirements.md` в обе стороны. Меняешь ОС — правь 4 места.
- **Справочники** (настройки, каталог игр, тарифы, отделения поддержки) засеиваются **миграцией** `panel/database/migrations/2026_01_01_001200_seed_core_data.php`, а не сидером. `DatabaseSeeder` вызывает только `DemoUsersSeeder` и `DemoCatalogueSeeder`. Новая игра или тариф — правишь эту миграцию.
- **API**: новый маршрут в `panel/routes/api.php` должен быть описан в `docs/api.md` и наоборот. `check-api-doc.cjs` печатает расхождения в обе стороны, но **никогда не возвращает ненулевой код** — читай его вывод глазами, зелёного `OK` на автоматике не будет.
- **UI-строки**: `check.cjs` требует каждый `__('…')` и в `resources/lang/ru`, и в `resources/lang/en`; кроме того проверяет, что `view()` указывает на существующий blade, `@include` — на существующий partial, `route('…')` — на реальное имя маршрута, а блоки Blade сбалансированы.
- **Миграции**: `foreignId(...)->constrained()` должен ссылаться на таблицу, созданную в этой же или более ранней миграции.
- **Скрипты установки игр** (`game-images/`) получают только переменные из таблицы в `game-images/README.md`, обязаны `exit 0` при успехе и писать прогресс в stdout (stderr и stdout идут в консоль игрового сервера).

## Жёсткие ограничения

- Никаких новых CSS/UI-фреймворков. В коде есть **Tailwind + Alpine.js**; Bootstrap и jQuery отсутствуют — не добавляй.
- Никакого jQuery и плагинов на нём. Фронтенд — чистый ES6+.
- Не трогай чужие модули: задача по веб-интерфейсу — правки только в `panel/`. Код в `agent/` или `bot/` не меняй, если это явно не требуется.
- БД в `panel/` — только через модели Eloquent. Никаких `mysqli_*` и сырого `PDO` в контроллерах.
- Всё, что уходит в игровой сервер (порты, лимиты, параметры запуска, RCON/SSH, консольные команды), валидируется строго. Не оставляй путей к RCE: командная инъекция, выход за пределы каталога через `../`, неэкранированные аргументы запуска.
- Перед генерацией кода сначала план: в какой именно модуль (`panel` / `agent` / `bot`) пойдут правки, какие конкретно файлы изменятся и почему.
- Пояснения и комментарии к коду — **строго на русском**, как и весь остальной репозиторий.

## Прочее, что легко упустить

- `.gitattributes` жёстко задаёт окончания строк: `* text=auto eol=lf`, `*.sh` / `*.php` / `*.js` / `*.cjs` / `*.json` / `*.yml` / `*.blade.php` → **LF**, `*.md` → **CRLF**. При правках на Windows не дай CRLF попасть в `.sh`: `check-shell.sh` это валит, а установщики идут на Linux.
- `README.md` — валидный UTF-8, но с чередующимися CRLF/LF, поэтому некоторые инструменты читают его как бинарный файл. Читай через `Get-Content -Encoding UTF8` или `cat`.
- Сообщения коммитов — по-русски (вся текущая история: «Описание того, что вы обновили»).
- Агент падает на старте без токена ноды. Приоритет настроек: CLI > env (`GD_TOKEN`, `GD_PANEL`, `GD_NODE_ID`, `GD_RUNTIME`, `GD_SERVERS_ROOT`, `GD_BACKUPS_ROOT`, `GD_TEMPLATES_ROOT`, `GD_DOCKER_SOCKET`) > файл (`$GD_AGENT_CONFIG` → `agent/agent.config.json` → `/etc/gamedock/agent.json` → `~/.gamedock/agent.json`) > дефолты.
- `agent/agent.config.json` **лежит в git** (в `.gitignore` только `agent.local.json`), там `token: "gd-node-REPLACE_ME"` — реальные токены туда не клади.
- Проверка агента: `node bin/gamedock-agent.js doctor` (сеть + рантаймы + cgroup v2), `health` — только локально, без обращения к панели (это HEALTHCHECK в Docker, связь может пропадать временно).
- Связь панели с нодами: `php artisan gamedock:ws-server` (в compose — порт 9222). HTTP-фолбэк для нод без постоянного соединения — группа `agent.*` в `routes/api.php` под middleware `agent.auth`.
- Команды панели: `gamedock` (меню админки в консоли), `gamedock:admin-password`, `gamedock:scheduler`, `gamedock:ws-server`.
- Демо-доступ после `php artisan migrate --seed`: `admin@example.com` / `admin12345`, `demo@example.com` / `demo12345` — только для локального стенда.