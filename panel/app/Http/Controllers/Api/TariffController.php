<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TariffController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(['data' => $this->list(onlyPublic: true)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->list(
            onlyPublic: $request->boolean('public_only', true),
            includeTrial: $request->boolean('include_trial'),
        )]);
    }

    public function show(Tariff $tariff): JsonResponse
    {
        return response()->json(['data' => $this->one($tariff)]);
    }

    private function list(bool $onlyPublic = true, bool $includeTrial = false): array
    {
        return Tariff::query()
            ->when($onlyPublic, fn ($q) => $q->public())
            ->when(! $includeTrial, fn ($q) => $q->where('is_trial', false))
            ->ordered()
            ->with('prices')
            ->get()
            ->map(fn (Tariff $t) => $this->one($t))
            ->all();
    }

    private function one(Tariff $tariff): array
    {
        return [
            'id' => $tariff->id,
            'slug' => $tariff->slug,
            'name' => $tariff->name,
            'model' => $tariff->model,
            'description' => $tariff->description,
            'short_description' => $tariff->short_description,
            'price' => (float) $tariff->price,
            'currency' => setting('hosting.billing.currency', 'RUB'),
            'billing_period' => $tariff->billing_period,
            'duration_days' => $tariff->duration_days,
            'included' => [
                'slots' => $tariff->slots,
                'memory_mb' => $tariff->memory_mb,
                'cpu_percent' => $tariff->cpu_percent,
                'disk_mb' => $tariff->disk_mb,
                'network_mbps' => $tariff->network_mbps,
                'pids' => $tariff->pids,
                'backups' => $tariff->backups,
                'max_servers' => $tariff->max_servers,
            ],
            'options' => [
                'sub_accounts' => $tariff->allow_sub_accounts,
                'custom_port' => $tariff->allow_custom_port,
                'rcon' => $tariff->allow_rcon,
                'priority_support' => $tariff->priority_support,
            ],
            'features' => $tariff->features,
            'badge' => $tariff->badge,
            'is_popular' => $tariff->is_popular,
            'is_trial' => $tariff->is_trial,
            'extras' => $tariff->prices->map(fn ($p) => [
                'resource' => $p->resource,
                'label' => $p->label,
                'unit' => $p->unit,
                'unit_quantity' => $p->unit_quantity,
                'price' => (float) $p->price,
                'min' => $p->min_quantity,
                'max' => $p->max_quantity,
            ])->all(),
        ];
    }
}
