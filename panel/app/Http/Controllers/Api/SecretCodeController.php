<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecretCode;
use App\Models\Server;
use App\Services\SecretCodes\SecretCodeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Секретные коды через API:бот или игровой плагин может проверить код
 * и сразу получить ответ, без перехвата чата.
 */
class SecretCodeController extends Controller
{
    public function __construct(private readonly SecretCodeResolver $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageSecretCodes', Server::class);

        $codes = $request->user()->secretCodes()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SecretCode $c) => [
                'id' => $c->id,
                'code' => $c->maskedCode(),
                'hint' => $c->hint,
                'reward_type' => $c->reward_type,
                'reward' => $c->rewardLabel(),
                'max_uses' => $c->max_uses,
                'used_count' => $c->used_count,
                'is_active' => $c->is_active,
                'expires_at' => $c->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $codes]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'player_id' => ['nullable', 'string', 'max:64'],
            'player_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        $server = $data['server_id'] ?? null
            ? Server::where('user_id', $user->id)->find($data['server_id'])
            : $user->servers()->first();

        if ($data['server_id'] && ! $server) {
            return response()->json(['message' => 'Сервер не найден'], 404);
        }

        if (! $server) {
            return response()->json(['message' => 'Укажите server_id'], 422);
        }

        $result = $this->resolver->handleChatMessage([
            'server_id' => $server->id,
            'text' => $data['code'],
            'player_id' => $data['player_id'] ?? null,
            'player_name' => $data['player_name'] ?? null,
            'ip' => $request->ip(),
        ]);

        if (! $result['matched']) {
            return response()->json([
                'ok' => false,
                'message' => 'Команда не является секретным кодом',
            ], 400);
        }

        if ($result['code'] === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Код не найден или недоступен',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'reward' => $result['code']->rewardLabel(),
            'server' => $server->name,
        ]);
    }
}
