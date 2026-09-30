<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentWebhookController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\PublicStatusController as ApiPublicStatusController;
use App\Http\Controllers\Api\SecretCodeController as ApiSecretCodeController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API панели
|--------------------------------------------------------------------------
| Два способа авторизации:
|   1) Sanctum-токен из раздела «Профиль → API-токены»  (Authorization: Bearer)
|   2) Сессия панели + Sanctum stateful (для внутренних SPA)
*/

Route::middleware('auth:sanctum')->group(function () {

    // ── Пользователь ────────────────────────────────────────────────────
    Route::get('/me', [UserController::class, 'me'])->name('api.me');
    Route::put('/me', [UserController::class, 'update'])->name('api.me.update');
    Route::get('/me/summary', [UserController::class, 'summary'])->name('api.me.summary');

    // ── Серверы ─────────────────────────────────────────────────────────
    Route::get('/servers', [ServerController::class, 'index'])->name('api.servers.index');
    Route::post('/servers', [ServerController::class, 'store'])->name('api.servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('api.servers.show');
    Route::put('/servers/{server}', [ServerController::class, 'update'])->name('api.servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('api.servers.destroy');

    Route::post('/servers/{server}/start', [ServerController::class, 'start'])->name('api.servers.start');
    Route::post('/servers/{server}/stop', [ServerController::class, 'stop'])->name('api.servers.stop');
    Route::post('/servers/{server}/restart', [ServerController::class, 'restart'])->name('api.servers.restart');
    Route::post('/servers/{server}/kill', [ServerController::class, 'kill'])->name('api.servers.kill');

    Route::get('/servers/{server}/console', [ServerController::class, 'console'])->name('api.servers.console');
    Route::post('/servers/{server}/console', [ServerController::class, 'sendConsole'])->name('api.servers.console.send');
    Route::get('/servers/{server}/metrics', [ServerController::class, 'metrics'])->name('api.servers.metrics');
    Route::get('/servers/{server}/backups', [ServerController::class, 'backups'])->name('api.servers.backups');
    Route::post('/servers/{server}/backups', [ServerController::class, 'createBackup'])->name('api.servers.backups.create');
    Route::get('/servers/{server}/files', [ServerController::class, 'files'])->name('api.servers.files');
    Route::get('/servers/{server}/events', [ServerController::class, 'events'])->name('api.servers.events');

    // ── Каталог ─────────────────────────────────────────────────────────
    Route::get('/games', [GameController::class, 'index'])->name('api.games');
    Route::get('/games/{game}', [GameController::class, 'show'])->name('api.games.show');
    Route::get('/tariffs', [TariffController::class, 'index'])->name('api.tariffs');
    Route::get('/tariffs/{tariff}', [TariffController::class, 'show'])->name('api.tariffs.show');

    // ── Секретные коды ──────────────────────────────────────────────────
    Route::get('/secret-codes', [ApiSecretCodeController::class, 'index'])->name('api.secret_codes');
    Route::post('/secret-codes/redeem', [ApiSecretCodeController::class, 'redeem'])->name('api.secret_codes.redeem');

    // ── Токены ──────────────────────────────────────────────────────────
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('api.tokens');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('api.tokens.store');
    Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy'])->name('api.tokens.destroy');
});

/*
|--------------------------------------------------------------------------
| Входящие вебхуки от агентов (альтернатива WSS: для нод без постоянного
| соединения — агент шлёт heartbeat и статус по HTTP)
|--------------------------------------------------------------------------
*/

Route::prefix('agent')->name('api.agent.')->middleware('agent.auth')->group(function () {
    Route::post('/heartbeat', [AgentWebhookController::class, 'heartbeat'])->name('heartbeat');
    Route::post('/status', [AgentWebhookController::class, 'serverStatus'])->name('status');
    Route::post('/metrics', [AgentWebhookController::class, 'metrics'])->name('metrics');
    Route::post('/console', [AgentWebhookController::class, 'console'])->name('console');
    Route::post('/install', [AgentWebhookController::class, 'install'])->name('install');
    Route::post('/chat', [AgentWebhookController::class, 'chat'])->name('chat');
    Route::post('/backup', [AgentWebhookController::class, 'backup'])->name('backup');
});

/*
|--------------------------------------------------------------------------
| Публичное API (без авторизации)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/games', [GameController::class, 'publicIndex'])->name('games');
    Route::get('/tariffs', [TariffController::class, 'publicIndex'])->name('tariffs');
    Route::get('/status', [ApiPublicStatusController::class, 'index'])->name('status');
    Route::get('/status/nodes', [ApiPublicStatusController::class, 'nodes'])->name('status.nodes');
    Route::get('/stats', [ApiPublicStatusController::class, 'stats'])->name('stats');
});
