# Биллинг и платежи

## Модель денег

В панели один кошелёк на пользователя (`users.balance`, валюта `RUB` по умолчанию).
Деньги двигаются только через `WalletService::apply()` — любая операция создаёт строку
в `user_transactions` с `balance_before` / `balance_after`. Это даёт полный аудит:
история операций пользователя всегда сходится с балансом.

Типы операций (`UserTransaction::TYPES`):

| Тип | Направление | Когда |
|---|---|---|
| `deposit` | приход | успешная оплата |
| `charge` | расход | ежедневное/периодическое списание за сервер |
| `purchase` | расход | покупка услуги в магазине |
| `bonus` | приход | промокод типа «бонус» |
| `referral` | приход | награда за приглашение |
| `withdraw` | расход | вывод средств |
| `refund` | приход | возврат |
| `adjustment` | любое | ручная правка администратором |

## Тарифы

Тариф (`tariffs`) — это набор квот и цена. Три модели (`Tariff::MODEL_*`):

| Модель | Формула |
|---|---|
| `package` | `price` за период |
| `slots` | `slots × tariff_prices[extra_slots].price` |
| `hybrid` | `price` + докупки сверх квот |

Квоты тарифа: `slots`, `memory_mb`, `cpu_percent`, `disk_mb`, `network_mbps`, `pids`,
`backups`, `max_servers` (сколько серверов можно создать на этом тарифе).
Пользователь не может выставить больше, чем позволяет тариф — это проверяется и в
`ServerController::store()`, и в `updateResources()`.

Докупки (`tariff_prices`) — отдельные позиции с шагом и ценой:

| Ресурс | Ключ | Единица |
|---|---|---|
| Доп. слоты | `extra_slots` | шт |
| Доп. RAM | `extra_memory` | ГБ (шаг 1024 МБ) |
| Доп. диск | `extra_disk` | ГБ (шаг 10240 МБ) |
| Доп. CPU | `extra_cpu` | 25 % |
| Покупка порта | `port` | шт |
| Доп. бэкап | `backup` | шт |
| Приоритетная поддержка | `support` | мес |

### Сид-тарифы

| Тариф | Модель | Цена | Слоты | RAM | Диск |
|---|---|---|---|---|---|
| `trial` | package | 0 ₽ | 10 | 1 ГБ | 10 ГБ |
| `start` | package | 149 ₽ | 10 | 2 ГБ | 20 ГБ |
| `classic` | package | 349 ₽ | 30 | 4 ГБ | 50 ГБ |
| `pro` | hybrid | 699 ₽ | 40 | 6 ГБ | 80 ГБ |
| `ultra` | hybrid | 1490 ₽ | 100 | 12 ГБ | 150 ГБ |

## Жизненный цикл оплаты сервера

```
создан сервер            expires_at = now + duration_days(тарифа)
        │
        ▼  каждые сутки в charge_hour
BillingService::chargeDueServers()
        │
        ├─ хватает баланса → ServerCharge(paid) → списали → expires_at += период
        │                    → уведомление «оплачено до …»
        │
        └─ не хватает   → ServerCharge(pending, failed)
                           → уведомление «не хватает X ₽»
                           → expires_at остаётся в прошлом
                                  │
                                  ▼  warn_before_expiry_days (3)
                           предупреждение в панели и Telegram
                                  │
                                  ▼  grace_period_days (3)
                           вход в панель ограничен, сервер останавливается
                                  │
                                  ▼  delete_after_stop_days (14)
                           purge_at → данные удаляются
```

Все пороги берутся из `config/hosting.php → billing` и могут быть переопределены
в админке (**Настройки → billing**):

```php
'billing' => [
    'grace_period_days'   => 3,    // сколько дней ждать пополнения
    'warn_before_expiry_days' => 3, // за сколько предупредить
    'stop_on_zero_balance' => true, // авто-остановка при нулевом балансе
    'delete_after_stop_days' => 14,// через сколько удалить остановленные данные
    'charge_interval' => 'daily',
    'charge_hour' => 3,
],
```

Крон: `routes/console.php` → `billing:charge` (раз в 5 минут) +
`billing:freeze` (раз в час) + `billing:purge` (раз в сутки). Все три идёт в очередь.

## Платёжные системы

Все шлюзы написаны вручную на Guzzle, без SDK. Общий интерфейс — `PaymentGateway`:

```php
interface PaymentGateway {
    public function code(): string;
    public function label(): string;
    public function isEnabled(): bool;
    public function createInvoice(Deposit $deposit): array;   // ['url' => …, 'provider_id' => …]
    public function checkStatus(Deposit $deposit): array;
    public function handleWebhook(Request $r): array;
    public function refund(Deposit $deposit): array;
}
```

Реализации: `YooKassaGateway`, `TinkoffGateway`, `CryptoBotGateway`, `ManualGateway`.
Включаются флагами в `config/hosting.php → payments.methods.*.enabled` или в админке.

### ЮKassa

```
.env:
GD_YOOKASSA_SHOP_ID=123456
GD_YOOKASSA_SECRET_KEY=live_...
```

Webhook: `POST /webhooks/yookassa` (без CSRF, без авторизации).
Панель проверяет IP-адреса ЮKassa из белого списка провайдера, затем `POST /v3/webhooks`
на `check` и сверяет `amount` + `metadata.deposit_uuid`.

Идемпотентность: `Idempotence-Key` = `deposits.idempotency_key`, повторный вызов не создаёт
дубль. Повторный вебхук по уже оплаченному депозиту игнорируется.

### Т-Банк (Tinkoff)

```
GD_TINKOFF_TERMINAL_KEY=...
GD_TINKOFF_PASSWORD=...
```

Webhook: `POST /webhooks/tinkoff`. Ответ должен содержать строку `"ok"` —
иначе провайдер повторяет доставку.

### Криптобот (@CryptoBot)

```
GD_CRYPTOBOT_TOKEN=123456:AA...
```

Панель создаёт инвойс через `createInvoice`, получает адрес и `payload`,
показывает его пользователю с таймером (по умолчанию 30 минут). Далее:

- либо панель опрашивает `getInvoices` по расписанию,
- либо бот из каталога `bot/` ловит `CryptoBot_Paid` и дёргает вебхук панели.

Webhook: `POST /webhooks/cryptobot`.

### Ручной приём

Инвойс без внешнего URL: пользователь переводит на указанные реквизиты и отправляет чек
в тикет. Администратор начисляет баланс вручную (**Админка → Пользователи → Баланс**).
Все такие начисления попадают в `audit_logs` с типом `adjustment`.

## Пополнение кошелька

**Панель → Кошелёк → Пополнить**: сумма, способ, промокод, для крипты — актив.
Создаётся `deposits` со статусом `pending`, затем:

- внешние шлюзы → редирект на страницу оплаты, возврат через `GET /payment/return/{uuid}`;
- ручной → сразу страница с реквизитами.

Страница `/panel/billing/deposits/{uuid}` показывает статус и кнопку «Проверить»,
которая перезапрашивает статус у провайдера (`PaymentManager::refresh()`).

Пополнить баланс можно и API-токеном: `POST /api/v1/billing/deposits`.

## Промокоды

Типы (`PromoCode::TYPE_*`):

| Тип | Что делает | Поля |
|---|---|---|
| `discount` | скидка на сумму оплаты | `percent` или `amount`, `max_discount` |
| `duration` | добавляет дни аренды | `days` |
| `bonus` | разовый бонус | `bonus_rub`, `bonus_slots`, `bonus_memory_mb`, `bonus_days` |

Ограничения: `max_uses`, `per_user_limit`, `min_order`, `valid_from`, `valid_until`,
`first_payment_only`, `applies_to_tariffs`, `applies_to_games`, `applies_to_products`.

Массовая генерация: **Админка → Промокоды** → «Массовая генерация»
(до 1000 кодов с префиксом). Пример стартовых кодов: `WELCOME10` (−10 %),
`DEMO3DAY` (+3 дня), `BONUS500` (500 ₽).

## Реферальная программа

1. Пользователь получает код (`users.referral_code`, 8 симв.).
2. Друг регистрируется по ссылке `?ref=КОД` — `referred_by` заполняется.
3. Когда приглашённый пополняет баланс на ≥ `min_payment`, реферал засчитывается:
   обоим начисляется награда (`reward_referrer_rub` / `reward_referred_rub`).
4. Если `reward_after_payment = false` — награда начисляется сразу при регистрации.

Начисления идут транзакциями типа `referral`, поэтому их видно в истории операций.

## Секретные коды (для игроков)

Отдельная механика: игрок пишет код прямо в игровом чате.

```
Игрок: !GOLD
Агент: перехват → console.chat → панель
Панель: SecretCodeResolver::resolve() → проверки → начисление
Агент: печатает в чат "Код активирован! Начислено: 500 ₽"
```

Префиксы настраиваются (`marketing.secret_codes.prefixes`, по умолчанию `//`, `!`, `/promo`).
Типы наград: деньги, слоты, дни, RAM, внутренний кредит.
Ограничения: `max_uses`, `per_player_limit`, `min_rank_level` (для SAMP/MTA), срок.

## Автосъём и автопополнение

`payments.auto_topup`: при падении баланса ниже `when_below` панель создаёт депозит
на `min_amount` выбранным способом и отправляет пользователю ссылку. По умолчанию
выключено — включайте осознанно, чтобы не портить статистику платёжных систем.

## Отчёты

**Админка → Отчёты**: выручка по дням, новые серверы, новые пользователи, разбивка
по играм / нодам / способам оплаты. Период — 7/30/90/365 дней, период в URL.
JSON-версия: `GET /admin/reports/revenue?days=30` (для внешних графиков).

## Частые вопросы по деньгам

**Пользователь не может списать, хотя деньги есть.**
Проверьте `ServerCharge`, у которого `status = pending` и `period_end` в прошлом.
`BillingService` списывает по одной записи; зависшая запись блокирует остальные.
Пометить вручную: `UPDATE server_charges SET status='waived' WHERE id=?;`

**Сервер остановился, хотя срок не кончился.**
Проверьте `status_reason` в `servers`. Обычно это `frozen` из-за нулевого баланса
(см. `billing.stop_on_zero_balance`).

**Платёж пришёл, но баланс не пополнился.**
Вебхук не дошёл. Проверьте `storage/logs/laravel.log` на ошибки провайдера и
запись в `deposits.status`. Ручная отметка: **Админка → Пользователь → Баланс** →
положительная сумма → «пополнить вручную».

**Курс крипты считается неверно.**
Курс берётся из ответа Криптобота на момент создания инвойса и фиксируется в
`deposits.meta.rate`. Если валюта просела после инвойса — пересчёта не будет, это
осознанное поведение (фиксированный курс 30 минут).
