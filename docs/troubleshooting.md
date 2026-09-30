# Решение проблем

## Быстрый диагностический чеклист

```bash
# 1. Панель жива?
curl -sS -o /dev/null -w '%{http_code}\n' https://panel.example.com/

# 2. Фоновые процессы на месте?
systemctl status php8.3-fpm nginx redis-server mysql
systemctl status gamedock-ws        # WebSocket-сервер
systemctl status gamedock-agent@1     # на каждой ноде

# 3. Крон и очередь живы?
cd /opt/gamedock/panel
php artisan about
php artisan schedule:list
php artisan queue:failed

# 4. Логи
tail -f /opt/gamedock/panel/storage/logs/laravel.log
journalctl -u gamedock-ws -f
journalctl -u gamedock-agent@1 -f       # на ноде
```

---

## Панель не открывается

| Симптом | Причина | Решение |
|---|---|---|
| `502 Bad Gateway` от nginx | PHP-FPM не слушает сокет | `systemctl restart php8.3-fpm`; проверить `listen` в pool-конфиге |
| `504 Gateway Time-out` | долгий запрос к БД | проверить `systemctl status mysql`, индексы, `php artisan db:show` |
| Ошибка соединения с Redis | Redis упал или неверный `REDIS_HOST` | `redis-cli ping`; сверить `.env` |
| `Class not found` | не выполнен `composer dump-autoload` | `composer dump-autoload -o` |
| `419 Page Expired` | рассинхрон сессий | `php artisan cache:clear && php artisan config:clear` |
| `The route could not be found` | старый кэш маршрутов | `php artisan optimize:clear` |
| `vite manifest not found` | не собран фронтенд | `cd panel && npm ci && npm run build` |

Полная пересборка кэшей после ручной правки конфигов:

```bash
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## Ноды офлайн

### Агент не подключается

1. На ноде: `systemctl status gamedock-agent@1` и `journalctl -u gamedock-agent@1 -n 100`.
2. Проверить токен: **Админка → Ноды → Показать → Ротация токена** (старый перестанет работать).
3. Проверить сетевую доступность WSS-сервера панели:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://panel.example.com/agent/ws
# 400 или 426 — порт открыт и nginx проксирует (это нормально)
# 000 / timeout — порт закрыт
```

4. Проверить сертификат: `openssl s_client -connect panel.example.com:443 -servername panel.example.com </dev/null`.
5. Убедиться, что `GD_TLS=1` соответствует реальности: для self-signed сертификата
   агент должен доверять CA (`GD_CA_FILE`), либо поставьте нормальный Let's Encrypt.

### WSS-сервер панели не запущен

```bash
systemctl status gamedock-ws
cd /opt/gamedock/panel && php artisan gamedock:ws-server --host=0.0.0.0 --port=9223
```

Прокси nginx обязан отдавать `Upgrade`:

```nginx
location /agent/ws {
    proxy_pass http://127.0.0.1:9223;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

Без `proxy_set_header Connection "upgrade"` соединение будет закрываться сразу после
рукопожатия — агенты будут «подключаться и отваливаться».

### Нода онлайн, но серверы не создаются

- **Нет свободных портов**: `resource_allocations` на ноде кончились. Кнопка
  «Пул портов +» на странице ноды добавляет 2000 портов.
- **Не хватает ресурсов**: `allocatable_percent` слишком мал либо `reserved_memory_mb`
  больше, чем реально занято системой. Уменьшите резерв.
- **Рантайм не поддерживается**: агент сообщает доступные рантаймы в `heartbeat.runtimes`.
  Если нода `docker`, а игра требует образ — подходит; если образ не собран, агент
  попробует собрать его сам (долго) либо вернёт ошибку.

---

## Сервер не запускается

### Статус `crashed` сразу после старта

1. Откройте консоль: **Сервер → Консоль** — там будут последние строки вывода.
2. Типовые причины:

| Сообщение в консоли | Причина |
|---|---|
| `Error: Unable to access jarfile server.jar` | установка не завершилась, файлов нет |
| `java.lang.OutOfMemoryError` | мало RAM: либо уменьшите `-Xmx` в конфиге игры, либо докупите RAM |
| `Address already in use` | порт занят другим процессом (частая история после `kill -9`) |
| `Permission denied` | права на каталог: `chown -R gamedock:gamedock /home/gamedock/servers/<id>` |
| `No such file or directory` | каталог сервера удалён вручную — переустановите сервер |
| `docker: image not found` | образ не собран: `docker pull …` на ноде или смените рантайм |

3. Проверьте лимиты: **Сервер → Настройки → Ресурсы**. Если `memory_mb` меньше, чем
   требуется игре, запускайте с меньшим `-Xmx` — панель не знает требований игры.

### Установка висит на одном шаге

- Откройте прогресс установки на странице сервера — вывод каждого шага виден там.
- На ноде найдите каталог сервера и запустите установочный скрипт вручную:

```bash
cd /home/gamedock/servers/42
sudo -u gamedock GD_SERVER_DIR=$PWD \
  GD_GAME=minecraft-java GD_MEMORY_MB=4096 \
  bash /opt/gamedock/game-images/minecraft/install.sh
```

- Увеличьте таймаут: `installer.timeout` в JSON игры (через админку).
- `ServerReaper` переведёт зависшую установку в `error` через 2 часа.

### Установка падает «мгновенно»

Почти всегда — скрипт вернул ненулевой код. Смотрите вывод шага. Типовые:

| Вывод | Причина |
|---|---|
| `steamcmd: command not found` | в образе/системе нет steamcmd — поставьте пакет `steamcmd` |
| `403 Forbidden` при скачивании | URL плагина требует токен или отдаёт редирект на страницу |
| `No space left on device` | закончился диск ноды |
| `Killed` | OOM на ноде при распаковке — увеличьте `reserved_memory_mb` или ставьте swap |

---

## Нет доступа из интернета

1. **Адрес в панели** задан неверно. **Админка → Ноды → Показать → Внешний адрес**
   (`flagship`) — это IP/домен, который игроки вводят. Если пусто, игроки увидят порт без адреса.
2. **Файрвол**: `ufw allow 25000:25999/tcp`, `26000:26999/udp`, `27000:27199/tcp`.
3. **Docker** по умолчанию не пробрасывает порты, если не указать `-p` — проверьте,
   что рантайм docker передаёт порты (в `runtimes/docker` включён режим `host` или
   явно пробрасываются `game_port`).
4. **NAT / Cloudflare**: обычный Cloudflare (оранжевое облако) не проксирует
   произвольные TCP-порты. Для игровых портов нужен Spectrum или «серый» IP напрямую.
   Лендинг и панель через Cloudflare — можно, игровые порты — напрямую.
5. Проверка снаружи: `nc -vz <ip> <game_port>` с другой машины.

---

## Порты

| Диапазон | Назначение | Протокол |
|---|---|---|
| 25000–25999 | игровые | TCP/UDP (зависит от игры) |
| 26000–26999 | query | UDP |
| 27000–27199 | RCON | TCP |
| 3000–3049 | покупные (дешёвые) | TCP/UDP |
| 3050–3099 | покупные (средние) | TCP/UDP |
| 25565–25575 | покупные (Minecraft) | TCP |

Для Minecraft UDP 19132 нужен, только если включён `enable-status` с query
по UDP — обычно достаточно TCP. Для CS2/Source — UDP обязателен.

Проверить занятость на ноде: `ss -tulpn | grep 25012`.

---

## Биллинг

**Списание не происходит.** Крон: `php artisan schedule:list` — должны быть задачи
`billing:charge`, `billing:freeze`, `billing:purge`. Проверить:

```bash
php artisan tinker
>>> \App\Models\ServerCharge::where('status','pending')->count();
>>> \App\Models\Server::whereNotNull('expires_at')->where('expires_at','<',now())->count();
```

Если начисления `pending`/`failed` — баланс не хватает. Зависшие записи можно
закрыть вручную: `ServerCharge::where('status','pending')->update(['status'=>'waived'])`.

**Ошибка «хотя деньги есть».** Смотрите `user_transactions` — возможно, есть
`charge` со статусом `failed`, который блокирует цикл. Транзакции создаются
только `WalletService::apply()`, вручную их не правьте.

**Баланс списался, сервер не восстановился.** `billing:freeze` переводит в
`suspended`. Проверьте, отработал ли крон и не заблокирован ли нода.

---

## Бэкапы

- Не хватает места → увеличьте `GD_BACKUPS_ROOT` или включите выгрузку в S3.
- Ротация (`keep_daily`/`keep_weekly`/`keep_monthly`) чистит только незащищённые
  бэкапы; помеченные «защитить» копятся. Снимите защиту с лишних.
- Восстановление требует `is_locked = false` и остановки на время распаковки —
  агент останавливает процесс сам, если он запущен.

---

## 2FA и вход

**Потерян доступ к 2FA.** Суперадмин может сбросить: в БД

```sql
UPDATE users SET two_factor_enabled = 0, two_factor_confirmed = 0,
                 two_factor_secret = NULL, two_factor_recovery = NULL
WHERE id = <id>;
```

**«Слишком много попыток входа».** Счётчик в Redis:

```bash
redis-cli --scan --pattern 'gd:login:*' | xargs -r redis-cli del
```

**Капча не пропускает.** Проверьте, что `GD_TURNSTILE_SITE_KEY` совпадает с
`secret_key` и домен панели добавлен в список сайтов Turnstile.

---

## WebSocket-консоль не обновляется

1. Через nginx не буферизуется SSE — в конфиге должен быть:

```nginx
proxy_buffering off;
proxy_cache off;
proxy_read_timeout 3600s;
chunked_transfer_encoding on;
```

2. Таймауты PHP-FPM: `fastcgi_read_timeout 3600;` в секции `location ~ \.php$`.
3. Проверить, что буфер не растёт бесконечно: `ConsoleBroadcaster` хранит
   `realtime_buffer` строк (по умолчанию 2000).
4. Если поток обрывается каждые 60 с — скорее всего, прокси обрывает соединение.
   Увеличьте `proxy_read_timeout` и `max_execution_time` для этого маршрута.

---

## Метрики не обновляются

- Проверьте `metrics_at` в `servers`: если старше 60 секунд — агент не шлёт батчи.
- Кольцевой буфер в Redis имеет TTL `raw_ttl` (7 дней) — после перезапуска
  графики начнутся заново, это нормально.
- Агрегаты за прошлые дни в `metric_hourly` — их собирает задача
  `metrics:aggregate` раз в час. Проверьте, что она в `schedule:list`.

---

## Почта не отходит

```bash
cd /opt/gamedock/panel
php artisan tinker
>>> Mail::raw('test', fn($m) => $m->to('you@example.com')->subject('test'));
```

Панель использует PHP `mail()` через `App\Services\Mail\Mailer`. На сервере без
 MTA (mail() вернёт `false`) письма не уходят — поставьте `msmtp`/`postfix` или
 переключитесь на SMTP, если добавите `symfony/mailer` в `composer.json`.

Проверить системный MTA: `systemctl status postfix` / `ss -tlnp | grep :25`.

---

## Docker-хост

| Симптом | Решение |
|---|---|
| `Cannot connect to the Docker daemon` | `systemctl start docker`; добавить пользователя в группу `docker` |
| `cgroup v2 unified` нужен | Debian 12/13 и Ubuntu — из коробки; на Debian 11 включите `systemd.unified_cgroup_hierarchy=1` в `/etc/default/grub` и перезагрузитесь |
| `no space left on device` на `/var/lib/docker` | `docker system prune -af`; расширьте раздел |
| Публичные образы недоступны | проверьте `curl -I https://registry-1.docker.io/v2/` и DNS |
| Сеть контейнера изолирована | для серверов используйте `--network host` (уже настроено в рантайме) |

---

## Полезные команды

```bash
# Панель
cd /opt/gamedock/panel
php artisan migrate:status
php artisan queue:work --queue=agent,install,backup,default
php artisan schedule:work
php artisan gamedock:ws-server
php artisan gamedock:billing:charge
php artisan db:seed --class=Database\\Seeders\\CoreSeeder
php artisan gamedock:admin:password admin@example.com

# Нода
systemctl restart gamedock-agent@1
cat /etc/gamedock/agent.json | jq .
docker ps -a --filter "label=gamedock.server"
docker logs --tail 100 gamedock-42
du -sh /home/gamedock/servers/* | sort -h | tail
```

## Логи и как их читать

| Файл | Что внутри |
|---|---|
| `storage/logs/laravel.log` | HTTP-ошибки, исключения, планировщик |
| `journalctl -u gamedock-ws` | WSS-сервер: подключения, RPC, таймауты |
| `journalctl -u gamedock-agent@1` | агент: рантайм, установка, watchdog |
| `/home/gamedock/servers/<id>/logs/` | логи самой игры |
| `server_events` в БД | журнал действий панели над сервером |

Быстрый поиск по всем:

```bash
grep -n "ERROR\|CRITICAL" /opt/gamedock/panel/storage/logs/laravel.log | tail -50
journalctl -u gamedock-agent@1 --since '1 hour ago' -p err
```

Если после всех проверок проблема осталась — соберите вывод
`php artisan about`, `php artisan schedule:list`, статусы сервисов и последние
50 строк логов: этого достаточно для диагностики в 9 случаях из 10.
