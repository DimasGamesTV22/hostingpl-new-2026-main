<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Tariff;
use App\Models\TariffPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminTariffController extends Controller
{
    public function index(): View
    {
        return view('admin.tariffs.index', [
            'tariffs' => Tariff::withCount(['servers', 'prices'])->orderBy('sort')->get(),
            'models' => [
                'package' => 'Фикс-пакет',
                'slots' => 'По слотам',
                'hybrid' => 'Пакет + докупки',
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.tariffs.edit', [
            'tariff' => new Tariff([
                'model' => 'package',
                'billing_period' => 'month',
                'duration_days' => 30,
                'slots' => 10,
                'memory_mb' => 2048,
                'cpu_percent' => 50,
                'disk_mb' => 10240,
                'network_mbps' => 25,
                'pids' => 512,
                'backups' => 3,
                'max_servers' => 1,
                'is_active' => true,
                'is_public' => true,
            ]),
            'prices' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tariff = Tariff::create($this->payload($request));

        $this->syncPrices($request, $tariff);

        Auditor::log('admin.tariff_create', 'Создан тариф «'.$tariff->name.'»', $tariff);

        return redirect()->route('admin.tariffs.edit', $tariff)->with('success', __('admin.messages.tariff_created'));
    }

    public function edit(Tariff $tariff): View
    {
        return view('admin.tariffs.edit', [
            'tariff' => $tariff,
            'prices' => $tariff->prices()->orderBy('sort')->get(),
        ]);
    }

    public function update(Request $request, Tariff $tariff): RedirectResponse
    {
        $tariff->update($this->payload($request));

        $this->syncPrices($request, $tariff);

        Auditor::log('admin.tariff_update', 'Обновлён тариф «'.$tariff->name.'»', $tariff);

        return back()->with('success', __('admin.messages.tariff_updated'));
    }

    public function duplicate(Request $request, Tariff $tariff): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $copy = $tariff->replicate();
        $copy->name = $data['name'];
        $copy->slug = $this->uniqueSlug($data['name']);
        $copy->is_public = false;
        $copy->is_trial = false;
        $copy->save();

        foreach ($tariff->prices as $price) {
            $price->replicate()->tariff_id = $copy->id;
            $price->save();
        }

        return redirect()->route('admin.tariffs.edit', $copy)->with('success', __('admin.messages.tariff_duplicated'));
    }

    public function toggle(Tariff $tariff): RedirectResponse
    {
        $tariff->forceFill(['is_active' => ! $tariff->is_active])->save();

        return back()->with('success', __('admin.messages.tariff_toggled'));
    }

    public function destroy(Tariff $tariff): RedirectResponse
    {
        if ($tariff->servers()->exists() || $tariff->is_trial) {
            return back()->with('error', __('admin.errors.tariff_in_use'));
        }

        $tariff->delete();

        return redirect()->route('admin.tariffs')->with('success', __('admin.messages.tariff_deleted'));
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function payload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80'],
            'model' => ['required', Rule::in(['package', 'slots', 'hybrid'])],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'billing_period' => ['required', Rule::in(['day', 'week', 'month', 'year'])],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'slots' => ['required', 'integer', 'min:0', 'max:10000'],
            'memory_mb' => ['required', 'integer', 'min:0', 'max:65536'],
            'cpu_percent' => ['required', 'integer', 'min:0', 'max:800'],
            'disk_mb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'network_mbps' => ['required', 'integer', 'min:0', 'max:1000'],
            'pids' => ['required', 'integer', 'min:0', 'max:8192'],
            'backups' => ['required', 'integer', 'min:0', 'max:100'],
            'max_servers' => ['required', 'integer', 'min:0', 'max:100'],
            'allow_sub_accounts' => ['nullable', 'boolean'],
            'allow_custom_port' => ['nullable', 'boolean'],
            'priority_support' => ['nullable', 'boolean'],
            'allow_rcon' => ['nullable', 'boolean'],
            'private' => ['nullable', 'boolean'],
            'is_trial' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'badge' => ['nullable', 'string', 'max:32'],
            'accent' => ['nullable', 'string', 'max:16'],
        ]);

        foreach (['allow_sub_accounts', 'allow_custom_port', 'priority_support', 'allow_rcon', 'private', 'is_trial', 'is_active', 'is_public', 'is_popular'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $data['slug'] = $data['slug'] ?: $this->uniqueSlug($data['name']);

        return $data;
    }

    private function syncPrices(Request $request, Tariff $tariff): void
    {
        $rows = $request->input('prices', []);

        if (! is_array($rows)) {
            return;
        }

        $keep = [];

        foreach ($rows as $row) {
            if (blank($row['resource'] ?? null) || blank($row['price'] ?? null)) {
                continue;
            }

            $tariff->prices()->updateOrCreate(
                ['resource' => $row['resource']],
                [
                    'label' => $row['label'] ?? $row['resource'],
                    'unit' => $row['unit'] ?? 'шт',
                    'unit_quantity' => (int) ($row['unit_quantity'] ?? 1),
                    'price' => (float) $row['price'],
                    'min_quantity' => (int) ($row['min_quantity'] ?? 0),
                    'max_quantity' => (int) ($row['max_quantity'] ?? 0),
                    'is_recurring' => (bool) ($row['is_recurring'] ?? true),
                    'sort' => (int) ($row['sort'] ?? 0),
                ],
            );

            $keep[] = $row['resource'];
        }

        if ($keep !== []) {
            $tariff->prices()->whereNotIn('resource', $keep)->delete();
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tariff';
        $slug = $base;
        $i = 1;

        while (Tariff::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
