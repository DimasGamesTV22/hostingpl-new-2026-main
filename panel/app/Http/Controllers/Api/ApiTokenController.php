<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->apiTokens()
                ->active()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ApiToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'prefix' => $t->token_prefix,
                    'abilities' => $t->abilities,
                    'ip_whitelist' => $t->ip_whitelist,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'last_used_ip' => $t->last_used_ip,
                    'expires_at' => $t->expires_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:40'],
            'expires_in' => ['nullable', 'integer', 'in:0,7,30,90,365,0'],
        ]);

        $plain = 'gd_'.Str::random(48);

        $token = ApiToken::create(array_merge(
            ApiToken::generate($plain),
            [
                'user_id' => $request->user()->id,
                'name' => $data['name'],
                'abilities' => $data['abilities'] ?? ['*'],
                'expires_at' => ($data['expires_in'] ?? 0) > 0 ? now()->addDays((int) $data['expires_in']) : null,
            ],
        ));

        return response()->json([
            'data' => [
                'id' => $token->id,
                'name' => $token->name,
                'token' => $plain,
                'warning' => 'Токен показывается один раз. Сохраните его сейчас.',
            ],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = ApiToken::where('user_id', $request->user()->id)->findOrFail($id);

        $token->forceFill(['revoked_at' => now()])->save();

        return response()->json(null, 204);
    }
}
