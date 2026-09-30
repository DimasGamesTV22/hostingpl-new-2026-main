<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Node;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация агента для HTTP-вебхуков (альтернатива WSS).
 * Токен ноды передаётся в заголовке X-Node-Token или X-GameDock-Token.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Node-Token')
            ?: $request->header('X-GameDock-Token')
            ?: (str_starts_with((string) $request->bearerToken(), 'node_')
                ? $request->bearerToken()
                : null);

        if (blank($token)) {
            return response()->json(['message' => 'Missing node token'], 401);
        }

        $node = Node::where('token_hash', hash('sha256', $token))->first();

        if (! $node || ! $node->is_active) {
            Log::warning('Agent webhook: неизвестный токен', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid node token'], 401);
        }

        $node->forceFill([
            'last_heartbeat_at' => now(),
            'status' => Node::STATUS_ONLINE,
            'inbound_ip' => $request->ip(),
        ])->save();

        $request->attributes->set('agent_node', $node);

        return $next($request);
    }
}
