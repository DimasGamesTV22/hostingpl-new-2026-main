<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\AdminGameController;
use App\Http\Controllers\Admin\AdminIpBanController;
use App\Http\Controllers\Admin\AdminNodeController;
use App\Http\Controllers\Admin\AdminPromoController;
use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminSettingController;
use App\Http\Controllers\Admin\AdminTariffController;
use App\Http\Controllers\Admin\AdminTicketController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Billing\DepositController;
use App\Http\Controllers\Billing\StoreController;
use App\Http\Controllers\Billing\SubscriptionController;
use App\Http\Controllers\Billing\TransactionController;
use App\Http\Controllers\Public\AnnouncementController;
use App\Http\Controllers\Public\FaqController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PublicStatusController;
use App\Http\Controllers\Server\BackupController;
use App\Http\Controllers\Server\ConsoleController;
use App\Http\Controllers\Server\FileController;
use App\Http\Controllers\Server\GameTemplateController;
use App\Http\Controllers\Server\ScheduleController;
use App\Http\Controllers\Server\SecretCodeController;
use App\Http\Controllers\Server\ServerController;
use App\Http\Controllers\Server\SubAccountController;
use App\Http\Controllers\Server\TransferController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\NotificationController;
use App\Http\Controllers\Panel\ProfileController;
use App\Http\Controllers\Panel\PromoController;
use App\Http\Controllers\Panel\ReferralController;
use App\Http\Controllers\Panel\SecurityController;
use App\Http\Controllers\Panel\TicketController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Публичная часть: лендинг, страницы, статус, SEO
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');

Route::get('/status', [PublicStatusController::class, 'index'])->name('status');
Route::get('/status/server/{server}', [PublicStatusController::class, 'show'])->name('status.server');

Route::get('/games', [HomeController::class, 'games'])->name('games');
Route::get('/tariffs', [HomeController::class, 'tariffs'])->name('tariffs');
Route::get('/games/{game}', [HomeController::class, 'game'])->name('games.show');

Route::get('/faq', FaqController::class)->name('faq');
Route::get('/news', AnnouncementController::class)->name('news');
Route::get('/news/{announcement}', [AnnouncementController::class, 'show'])->name('news.show');
Route::get('/page/{page}', [PageController::class, 'show'])->name('page');

Route::view('/legal/terms', 'public.terms')->name('legal.terms');
Route::view('/legal/privacy', 'public.privacy')->name('legal.privacy');

/*
|--------------------------------------------------------------------------
| Аутентификация
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/auth/google', [OAuthController::class, 'redirectGoogle'])->name('oauth.google');
    Route::get('/auth/google/callback', [OAuthController::class, 'handleGoogle'])->name('oauth.google.callback');
    Route::get('/auth/vk', [OAuthController::class, 'redirectVk'])->name('oauth.vk');
    Route::get('/auth/vk/callback', [OAuthController::class, 'handleVk'])->name('oauth.vk.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // Двухфакторная аутентификация
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'create'])->name('2fa.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'store'])->name('2fa.verify');
    Route::post('/two-factor/recovery', [TwoFactorController::class, 'recover'])->name('2fa.recover');
    Route::post('/two-factor/resend', [TwoFactorController::class, 'resend'])->name('2fa.resend');
});

Route::get('/reset-password/{token}', [SessionController::class, 'resetPassword'])->name('password.reset');
Route::post('/reset-password', [SessionController::class, 'updatePassword'])->name('password.update');

// Верификация email: и по ссылке, и по коду из письма
Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
    ->middleware('throttle:6,1')
    ->name('verification.send');
Route::get('/email/verify-code', [EmailVerificationController::class, 'codeForm'])->name('verification.code');
Route::post('/email/verify-code', [EmailVerificationController::class, 'verifyCode'])->name('verification.code.check');

/*
|--------------------------------------------------------------------------
| Возврат с оплаты
|--------------------------------------------------------------------------
*/

Route::get('/payment/return/{deposit}', [DepositController::class, 'return'])
    ->name('payment.return');
Route::get('/payment/success', [DepositController::class, 'success'])->name('payment.success');
Route::get('/payment/cancel', [DepositController::class, 'cancel'])->name('payment.cancel');

/*
|--------------------------------------------------------------------------
| Вебхуки платёжных систем (без CSRF и без авторизации)
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/yookassa', [PaymentWebhookController::class, 'yookassa'])->name('webhooks.yookassa');
Route::post('/webhooks/tinkoff', [PaymentWebhookController::class, 'tinkoff'])->name('webhooks.tinkoff');
Route::post('/webhooks/cryptobot', [PaymentWebhookController::class, 'cryptobot'])->name('webhooks.cryptobot');
Route::post('/webhooks/{provider}', [PaymentWebhookController::class, 'generic'])
    ->where('provider', '[a-z0-9\-_]+')
    ->name('webhooks.generic');

/*
|--------------------------------------------------------------------------
| Приватная область /panel
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'panel.access'])
    ->prefix('panel')
    ->name('panel.')
    ->group(function () {

        // ── Обзор ───────────────────────────────────────────────────────
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');

        // ── Игровые серверы ─────────────────────────────────────────────
        Route::resource('servers', ServerController::class)
            ->parameters(['servers' => 'server']);

        Route::prefix('servers/{server}')->name('server.')->group(function () {
            // Питание
            Route::post('/start', [ServerController::class, 'start'])->name('start');
            Route::post('/stop', [ServerController::class, 'stop'])->name('stop');
            Route::post('/restart', [ServerController::class, 'restart'])->name('restart');
            Route::post('/kill', [ServerController::class, 'kill'])->name('kill');

            // Консоль
            Route::get('/console', ConsoleController::class)->name('console');
            Route::get('/console/stream', [ConsoleController::class, 'stream'])->name('console.stream');
            Route::post('/console', [ConsoleController::class, 'send'])->name('console.send');
            Route::get('/logs', [ConsoleController::class, 'logs'])->name('logs');
            Route::get('/logs/download', [ConsoleController::class, 'download'])->name('logs.download');
            Route::get('/logs/search', [ConsoleController::class, 'search'])->name('logs.search');

            // Файлы
            Route::get('/files', [FileController::class, 'index'])->name('files');
            Route::get('/files/list', [FileController::class, 'list'])->name('files.list');
            Route::get('/files/read', [FileController::class, 'read'])->name('files.read');
            Route::post('/files/save', [FileController::class, 'save'])->name('files.save');
            Route::post('/files/upload', [FileController::class, 'upload'])->name('files.upload');
            Route::post('/files/mkdir', [FileController::class, 'mkdir'])->name('files.mkdir');
            Route::post('/files/rename', [FileController::class, 'rename'])->name('files.rename');
            Route::post('/files/delete', [FileController::class, 'destroy'])->name('files.delete');
            Route::get('/files/download', [FileController::class, 'download'])->name('files.download');
            Route::get('/files/search', [FileController::class, 'search'])->name('files.search');

            // Настройки игры
            Route::get('/settings', [ServerController::class, 'settings'])->name('settings');
            Route::post('/settings/resources', [ServerController::class, 'updateResources'])->name('settings.resources');
            Route::post('/settings/config', [ServerController::class, 'updateConfig'])->name('settings.config');
            Route::post('/settings/general', [ServerController::class, 'updateGeneral'])->name('settings.general');
            Route::post('/reinstall', [ServerController::class, 'reinstall'])->name('reinstall');
            Route::post('/update', [ServerController::class, 'updateGame'])->name('update');
            Route::get('/builds', [ServerController::class, 'builds'])->name('builds');

            // Плагины и моды
            Route::get('/plugins', [GameTemplateController::class, 'index'])->name('plugins');
            Route::post('/plugins/{template}/install', [GameTemplateController::class, 'install'])->name('plugins.install');
            Route::post('/plugins/{template}/remove', [GameTemplateController::class, 'remove'])->name('plugins.remove');

            // Бэкапы
            Route::get('/backups', [BackupController::class, 'index'])->name('backups');
            Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
            Route::post('/backups/{snapshot}/restore', [BackupController::class, 'restore'])->name('backups.restore');
            Route::post('/backups/{snapshot}/download', [BackupController::class, 'download'])->name('backups.download');
            Route::post('/backups/{snapshot}/toggle', [BackupController::class, 'toggleLock'])->name('backups.lock');
            Route::delete('/backups/{snapshot}', [BackupController::class, 'destroy'])->name('backups.destroy');

            // Планировщик
            Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules');
            Route::post('/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
            Route::post('/schedules/{schedule}/toggle', [ScheduleController::class, 'toggle'])->name('schedules.toggle');
            Route::post('/schedules/{schedule}/run', [ScheduleController::class, 'run'])->name('schedules.run');
            Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');

            // Дополнительные пользователи
            Route::get('/sub-accounts', [SubAccountController::class, 'index'])->name('sub_accounts');
            Route::post('/sub-accounts', [SubAccountController::class, 'store'])->name('sub_accounts.store');
            Route::post('/sub-accounts/{subAccount}/update', [SubAccountController::class, 'update'])->name('sub_accounts.update');
            Route::delete('/sub-accounts/{subAccount}', [SubAccountController::class, 'destroy'])->name('sub_accounts.destroy');

            // Секретные коды
            Route::get('/secret-codes', [SecretCodeController::class, 'index'])->name('secret_codes');
            Route::post('/secret-codes', [SecretCodeController::class, 'store'])->name('secret_codes.store');
            Route::delete('/secret-codes/{secretCode}', [SecretCodeController::class, 'destroy'])->name('secret_codes.destroy');
            Route::get('/secret-codes/usages', [SecretCodeController::class, 'usages'])->name('secret_codes.usages');

            // Перенос на другую ноду
            Route::get('/transfer', [TransferController::class, 'index'])->name('transfer');
            Route::post('/transfer', [TransferController::class, 'store'])->name('transfer.store');

            // Журнал
            Route::get('/events', [ServerController::class, 'events'])->name('events');
        });

        // Список всех серверов
        Route::get('/all-servers', [ServerController::class, 'all'])->name('servers.all');

        // ── Кошелёк и оплата ────────────────────────────────────────────
        Route::get('/billing', [SubscriptionController::class, 'index'])->name('billing');
        Route::get('/billing/deposit', [DepositController::class, 'create'])->name('billing.deposit.create');
        Route::post('/billing/deposit', [DepositController::class, 'store'])->name('billing.deposit.store');
        Route::get('/billing/deposits/{deposit}', [DepositController::class, 'show'])
            ->name('billing.deposits.show');
        Route::get('/billing/transactions', [TransactionController::class, 'index'])->name('billing.transactions');
        Route::get('/billing/transactions/export', [TransactionController::class, 'export'])->name('billing.transactions.export');
        Route::post('/billing/withdraw', [TransactionController::class, 'withdraw'])->name('billing.withdraw');

        // Магазин доп. услуг
        Route::get('/store', [StoreController::class, 'index'])->name('store');
        Route::post('/store', [StoreController::class, 'store'])->name('store.store');
        Route::get('/store/orders', [StoreController::class, 'orders'])->name('store.orders');
        Route::post('/store/orders/{order}/pay', [StoreController::class, 'pay'])->name('store.orders.pay');

        // ── Промокоды, рефералка ────────────────────────────────────────
        Route::get('/promo', PromoController::class)->name('promo');
        Route::post('/promo/activate', [PromoController::class, 'activate'])->name('promo.activate');
        Route::get('/referral', ReferralController::class)->name('referral');
        Route::post('/referral/apply', [ReferralController::class, 'apply'])->name('referral.apply');

        // ── Поддержка ───────────────────────────────────────────────────
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
        Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');

        // ── Профиль и безопасность ──────────────────────────────────────
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar');
        Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.delete');
        Route::get('/profile/api-tokens', [ProfileController::class, 'apiTokens'])->name('profile.api_tokens');
        Route::post('/profile/api-tokens', [ProfileController::class, 'createApiToken'])->name('profile.api_tokens.create');
        Route::delete('/profile/api-tokens/{token}', [ProfileController::class, 'destroyApiToken'])->name('profile.api_tokens.destroy');
        Route::get('/profile/webhooks', [ProfileController::class, 'webhooks'])->name('profile.webhooks');
        Route::post('/profile/webhooks', [ProfileController::class, 'createWebhook'])->name('profile.webhooks.create');
        Route::delete('/profile/webhooks/{webhook}', [ProfileController::class, 'destroyWebhook'])->name('profile.webhooks.destroy');

        Route::get('/security', [SecurityController::class, 'index'])->name('security');
        Route::post('/security/2fa/enable', [SecurityController::class, 'enable2fa'])->name('security.2fa.enable');
        Route::post('/security/2fa/confirm', [SecurityController::class, 'confirm2fa'])->name('security.2fa.confirm');
        Route::post('/security/2fa/disable', [SecurityController::class, 'disable2fa'])->name('security.2fa.disable');
        Route::post('/security/2fa/regenerate', [SecurityController::class, 'regenerateRecovery'])->name('security.2fa.regenerate');
        Route::get('/security/sessions', [SecurityController::class, 'sessions'])->name('security.sessions');
        Route::delete('/security/sessions/{session}', [SecurityController::class, 'destroySession'])->name('security.sessions.destroy');
        Route::post('/security/sessions/revoke-all', [SecurityController::class, 'revokeAll'])->name('security.sessions.revoke_all');
        Route::get('/security/login-history', [SecurityController::class, 'loginHistory'])->name('security.login_history');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read_all');
    });

/*
|--------------------------------------------------------------------------
| Админка
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:support,moderator,admin,superadmin', 'admin.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get('/', [AdminReportController::class, 'index'])->name('dashboard');

        // Пользователи
        Route::get('/users', [AdminUserController::class, 'index'])->name('users');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/role', [AdminUserController::class, 'changeRole'])->name('users.role');
        Route::post('/users/{user}/block', [AdminUserController::class, 'block'])->name('users.block');
        Route::post('/users/{user}/unblock', [AdminUserController::class, 'unblock'])->name('users.unblock');
        Route::post('/users/{user}/balance', [AdminUserController::class, 'adjustBalance'])->name('users.balance');
        Route::post('/users/{user}/impersonate', [AdminUserController::class, 'impersonate'])->name('users.impersonate');
        Route::post('/users/bulk', [AdminUserController::class, 'bulk'])->name('users.bulk');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        // Ноды
        Route::get('/nodes', [AdminNodeController::class, 'index'])->name('nodes');
        Route::get('/nodes/create', [AdminNodeController::class, 'create'])->name('nodes.create');
        Route::post('/nodes', [AdminNodeController::class, 'store'])->name('nodes.store');
        Route::get('/nodes/{node}/edit', [AdminNodeController::class, 'edit'])->name('nodes.edit');
        Route::get('/nodes/{node}', [AdminNodeController::class, 'show'])->name('nodes.show');
        Route::put('/nodes/{node}', [AdminNodeController::class, 'update'])->name('nodes.update');
        Route::post('/nodes/{node}/ping', [AdminNodeController::class, 'ping'])->name('nodes.ping');
        Route::post('/nodes/{node}/token', [AdminNodeController::class, 'rotateToken'])->name('nodes.token');
        Route::post('/nodes/{node}/ports', [AdminNodeController::class, 'seedPorts'])->name('nodes.ports');
        Route::post('/nodes/{node}/sync', [AdminNodeController::class, 'sync'])->name('nodes.sync');
        Route::delete('/nodes/{node}', [AdminNodeController::class, 'destroy'])->name('nodes.destroy');

        // Игры
        Route::get('/games', [AdminGameController::class, 'index'])->name('games');
        Route::get('/games/create', [AdminGameController::class, 'create'])->name('games.create');
        Route::post('/games', [AdminGameController::class, 'store'])->name('games.store');
        Route::get('/games/{game}/edit', [AdminGameController::class, 'edit'])->name('games.edit');
        Route::put('/games/{game}', [AdminGameController::class, 'update'])->name('games.update');
        Route::post('/games/{game}/duplicate', [AdminGameController::class, 'duplicate'])->name('games.duplicate');
        Route::post('/games/{game}/toggle', [AdminGameController::class, 'toggle'])->name('games.toggle');
        Route::delete('/games/{game}', [AdminGameController::class, 'destroy'])->name('games.destroy');

        Route::get('/games/{game}/templates', [AdminGameController::class, 'templates'])->name('games.templates');
        Route::post('/games/{game}/templates', [AdminGameController::class, 'storeTemplate'])->name('games.templates.store');
        Route::put('/games/templates/{template}', [AdminGameController::class, 'updateTemplate'])->name('games.templates.update');
        Route::delete('/games/templates/{template}', [AdminGameController::class, 'destroyTemplate'])->name('games.templates.destroy');

        // Тарифы
        Route::get('/tariffs', [AdminTariffController::class, 'index'])->name('tariffs');
        Route::get('/tariffs/create', [AdminTariffController::class, 'create'])->name('tariffs.create');
        Route::post('/tariffs', [AdminTariffController::class, 'store'])->name('tariffs.store');
        Route::get('/tariffs/{tariff}/edit', [AdminTariffController::class, 'edit'])->name('tariffs.edit');
        Route::put('/tariffs/{tariff}', [AdminTariffController::class, 'update'])->name('tariffs.update');
        Route::post('/tariffs/{tariff}/duplicate', [AdminTariffController::class, 'duplicate'])->name('tariffs.duplicate');
        Route::post('/tariffs/{tariff}/toggle', [AdminTariffController::class, 'toggle'])->name('tariffs.toggle');
        Route::delete('/tariffs/{tariff}', [AdminTariffController::class, 'destroy'])->name('tariffs.destroy');

        // Настройки
        Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings');
        Route::put('/settings/{group}', [AdminSettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/clear-cache', [AdminSettingController::class, 'clearCache'])->name('settings.cache');

        // Промокоды
        Route::get('/promo', [AdminPromoController::class, 'index'])->name('promo');
        Route::get('/promo/create', [AdminPromoController::class, 'create'])->name('promo.create');
        Route::post('/promo', [AdminPromoController::class, 'store'])->name('promo.store');
        Route::get('/promo/{promo}/edit', [AdminPromoController::class, 'edit'])->name('promo.edit');
        Route::put('/promo/{promo}', [AdminPromoController::class, 'update'])->name('promo.update');
        Route::post('/promo/generate', [AdminPromoController::class, 'generate'])->name('promo.generate');
        Route::post('/promo/{promo}/toggle', [AdminPromoController::class, 'toggle'])->name('promo.toggle');
        Route::delete('/promo/{promo}', [AdminPromoController::class, 'destroy'])->name('promo.destroy');
        Route::get('/promo/{promo}/usages', [AdminPromoController::class, 'usages'])->name('promo.usages');

        // Рефералка
        Route::get('/referrals', AdminReferralController::class)->name('referrals');
        Route::post('/referrals/{referral}/complete', [AdminReferralController::class, 'complete'])->name('referrals.complete');
        Route::post('/referrals/{referral}/reject', [AdminReferralController::class, 'reject'])->name('referrals.reject');

        // Тикеты
        Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/reply', [AdminTicketController::class, 'reply'])->name('tickets.reply');
        Route::post('/tickets/{ticket}/assign', [AdminTicketController::class, 'assign'])->name('tickets.assign');
        Route::post('/tickets/{ticket}/status', [AdminTicketController::class, 'setStatus'])->name('tickets.status');

        // Аудит и безопасность
        Route::get('/audit', AdminAuditController::class)->name('audit');
        Route::get('/audit/{auditLog}', [AdminAuditController::class, 'show'])->name('audit.show');
        Route::get('/ip-bans', [AdminIpBanController::class, 'index'])->name('ip_bans');
        Route::post('/ip-bans', [AdminIpBanController::class, 'store'])->name('ip_bans.store');
        Route::delete('/ip-bans/{ipBan}', [AdminIpBanController::class, 'destroy'])->name('ip_bans.destroy');

        // Отчёты
        Route::get('/reports', [AdminReportController::class, 'reports'])->name('reports');
        Route::get('/reports/revenue', [AdminReportController::class, 'revenue'])->name('reports.revenue');
    });
