<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StoreOrder;
use App\Services\Billing\PaymentManager;
use App\Services\Nodes\PortAllocator;
use App\Services\Servers\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Магазин дополнительных услуг: слоты, RAM, диск, CPU, порт, поддержка.
 */
class StoreController extends Controller
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PortAllocator $ports,
        private readonly Provisioner $provisioner,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $products = (array) setting('hosting.store.products', []);
        $servers = $user->servers()->with('game', 'tariff')->get();

        return view('panel.store.index', [
            'products' => $products,
            'servers' => $servers,
            'balance' => (float) $user->balance,
            'portRanges' => $this->ports->purchasableRanges(),
            'priceList' => $this->priceList($servers),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', Rule::in(array_keys((array) setting('hosting.store.products', [])))],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:65536'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $user = $request->user();

        if ((float) $user->balance <= 0) {
            return back()->with('error', __('billing.errors.no_funds'));
        }

        $server = $data['server_id'] ? Server::where('user_id', $user->id)->find($data['server_id']) : null;
        $quantity = (int) ($data['quantity'] ?? 1);

        $result = match ($data['product']) {
            'extra_slots' => $this->orderExtraSlots($user, $server, $quantity),
            'extra_memory' => $this->orderResource($user, $server, 'memory', $quantity, 'ГБ'),
            'extra_storage' => $this->orderResource($user, $server, 'disk', $quantity, 'ГБ'),
            'extra_cpu' => $this->orderResource($user, $server, 'cpu', $quantity, '%'),
            'port' => $this->orderPort($user, $server, (int) $data['port']),
            'backup_slots' => $this->orderFlat($user, 'backup_slots', __('store.products.backup_slots'), $quantity, 99),
            'support' => $this->orderFlat($user, 'support', __('store.products.support'), $quantity, 199),
            default => null,
        };

        if ($result === null) {
            return back()->with('error', __('store.errors.unknown_product'));
        }

        return back()->with('success', $result);
    }

    public function orders(Request $request): View
    {
        return view('panel.store.orders', [
            'orders' => $request->user()->orders()->with('server')->orderByDesc('created_at')->paginate(30),
        ]);
    }

    /** Оплата заказа с баланса. */
    public function pay(Request $request, StoreOrder $order): RedirectResponse
    {
        if ($order->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($order->status !== StoreOrder::STATUS_PENDING) {
            return back()->with('error', __('store.errors.already_paid'));
        }

        $result = $this->payments->payFromBalance(
            $request->user(),
            (float) $order->total,
            $order->label,
        );

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        $order->forceFill(['status' => StoreOrder::STATUS_PAID, 'paid_at' => now()])->save();

        return back()->with('success', __('store.messages.paid'));
    }

    // ── Заказы по типам ─────────────────────────────────────────────────

    private function orderExtraSlots($user, ?Server $server, int $quantity): ?string
    {
        if (! $server) {
            return __('store.errors.server_required');
        }

        $tariff = $server->tariff;
        $price = $tariff?->prices->firstWhere('resource', 'extra_slots');

        $unitPrice = $price ? (float) $price->price : 12.0;

        $max = (int) ($price?->max_quantity ?: 1000);
        $room = max(0, (int) $server->game->max_slots - (int) $server->slots);
        $quantity = min($quantity, $max, $room);

        if ($quantity < 1) {
            return __('store.errors.limit_reached');
        }

        $total = $unitPrice * $quantity;

        if ((float) $user->balance < $total) {
            return __('billing.errors.insufficient_funds_short', ['amount' => money($total)]);
        }

        $order = $this->wallet()->createOrder(
            $user,
            'extra_slots',
            __('store.order_titles.extra_slots', ['count' => $quantity]),
            $quantity,
            $unitPrice,
            'шт',
            $server,
        );

        $debit = $this->payments->payFromBalance($user, $total, $order->label);

        if (! $debit['ok']) {
            return $debit['error'];
        }

        $order->forceFill(['status' => StoreOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $this->provisioner->updateResources($server, ['slots' => (int) $server->slots + $quantity], $user);

        $this->provisioner->log(
            $server,
            'setting',
            __('store.messages.slots_added', ['count' => $quantity]),
            'success',
            ['total_slots' => (int) $server->fresh()->slots],
            $user,
        );

        return __('store.messages.order_paid');
    }

    private function orderResource($user, ?Server $server, string $resource, int $quantity, string $unit): ?string
    {
        if (! $server) {
            return __('store.errors.server_required');
        }

        $tariff = $server->tariff;
        $key = $resource === 'memory' ? 'extra_memory' : ($resource === 'disk' ? 'extra_disk' : 'extra_cpu');

        $price = $tariff?->prices->firstWhere('resource', $key);

        if (! $price) {
            return __('store.errors.price_not_found');
        }

        $step = (int) $price->unit_quantity;
        $units = (int) ceil($quantity * ($resource === 'memory' ? 1024 : ($resource === 'disk' ? 10240 : 25)) / max(1, $step));
        $total = $units * (float) $price->price;

        if ((float) $user->balance < $total) {
            return __('billing.errors.insufficient_funds_short', ['amount' => money($total)]);
        }

        $label = __('store.order_titles.'.$key, ['count' => $quantity, 'unit' => $unit]);

        $order = $this->wallet()->createOrder(
            $user,
            str_replace('extra_', '', $key),
            $label,
            $units,
            (float) $price->price,
            $step > 1 ? mb_gb($step) : $unit,
            $server,
            ['resource' => $resource, 'units' => $units],
        );

        $debit = $this->payments->payFromBalance($user, $total, $label);

        if (! $debit['ok']) {
            return $debit['error'];
        }

        $order->forceFill(['status' => StoreOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $changes = match ($resource) {
            'memory' => ['memory_mb' => (int) $server->memory_mb + $units * $step],
            'disk' => ['disk_mb' => (int) $server->disk_mb + $units * $step],
            default => ['cpu_percent' => (int) $server->cpu_percent + $units * 25],
        };

        $this->provisioner->updateResources($server, $changes, $user);

        return __('store.messages.order_paid');
    }

    private function orderPort($user, ?Server $server, int $port): ?string
    {
        if (! $server) {
            return __('store.errors.server_required');
        }

        $price = $this->ports->purchasePrice($port);

        if ($price === null) {
            return __('store.errors.port_not_available');
        }

        if ((float) $user->balance < $price) {
            return __('billing.errors.insufficient_funds_short', ['amount' => money($price)]);
        }

        $debit = $this->payments->payFromBalance(
            $user,
            $price,
            __('store.order_titles.port', ['port' => $port]),
        );

        if (! $debit['ok']) {
            return $debit['error'];
        }

        try {
            $this->provisioner->changePort($server, $port, $user);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return __('store.messages.port_changed', ['port' => $port]);
    }

    private function orderFlat($user, string $product, string $label, int $quantity, float $unitPrice): ?string
    {
        $total = $unitPrice * max(1, $quantity);

        if ((float) $user->balance < $total) {
            return __('billing.errors.insufficient_funds_short', ['amount' => money($total)]);
        }

        $order = $this->wallet()->createOrder($user, $product, $label, $quantity, $unitPrice, 'мес');

        $debit = $this->payments->payFromBalance($user, $total, $label);

        if (! $debit['ok']) {
            return $debit['error'];
        }

        $order->forceFill(['status' => StoreOrder::STATUS_PAID, 'paid_at' => now()])->save();

        return __('store.messages.order_paid');
    }

    private function wallet(): \App\Services\Billing\WalletService
    {
        return app(\App\Services\Billing\WalletService::class);
    }

    /**
     * Сводка цен для выбранного сервера.
     */
    private function priceList($servers): array
    {
        $out = [];

        foreach ($servers as $server) {
            $tariff = $server->tariff;

            if (! $tariff) {
                continue;
            }

            $out[$server->id] = $tariff->prices
                ->mapWithKeys(fn ($p) => [$p->resource => [
                    'price' => money($p->price),
                    'unit' => $p->unitLabel(),
                ]])
                ->all();
        }

        return $out;
    }
}
