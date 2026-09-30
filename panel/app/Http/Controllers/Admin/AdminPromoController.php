<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Services\Promo\PromoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminPromoController extends Controller
{
    public function __construct(private readonly PromoService $promo) {}

    public function index(Request $request): View
    {
        $codes = PromoCode::with('creator:id,name')
            ->withCount('uses')
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.promo.index', [
            'codes' => $codes,
            'types' => PromoCode::TYPES,
            'filters' => $request->only(['q', 'type']),
            'features' => [
                'discount' => setting_bool('hosting.marketing.promo_discount.enabled', true),
                'duration' => setting_bool('hosting.marketing.promo_duration.enabled', true),
                'bonus' => setting_bool('hosting.marketing.promo_bonus.enabled', true),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.promo.edit', [
            'promo' => new PromoCode([
                'type' => 'discount',
                'percent' => 10,
                'per_user_limit' => 1,
                'min_order' => 0,
                'is_active' => true,
                'valid_until' => now()->addMonth(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $promo = PromoCode::create($this->payload($request, new PromoCode()));

        Auditor::log('admin.promo_create', 'Создан промокод '.$promo->code, $promo);

        return redirect()->route('admin.promo.edit', $promo)->with('success', __('admin.messages.promo_created'));
    }

    public function edit(PromoCode $promo): View
    {
        return view('admin.promo.edit', compact('promo'));
    }

    public function update(Request $request, PromoCode $promo): RedirectResponse
    {
        $promo->update($this->payload($request, $promo));

        Auditor::log('admin.promo_update', 'Обновлён промокод '.$promo->code, $promo);

        return back()->with('success', __('admin.messages.promo_updated'));
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'prefix' => ['nullable', 'string', 'max:16'],
            'type' => ['required', Rule::in(PromoCode::TYPES)],
            'percent' => ['nullable', 'integer', 'min:1', 'max:90'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'bonus_rub' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $created = $this->promo->generateMany(
            (int) $data['count'],
            [
                'name' => 'Массовая генерация',
                'type' => $data['type'],
                'percent' => $data['percent'] ?? null,
                'days' => $data['days'] ?? null,
                'bonus_rub' => $data['bonus_rub'] ?? null,
                'max_uses' => $data['max_uses'] ?? 1,
                'per_user_limit' => $data['per_user_limit'] ?? 1,
                'valid_until' => $data['valid_until'] ?? now()->addMonth(),
                'is_active' => true,
                'created_by' => $request->user()->id,
            ],
            (string) ($data['prefix'] ?? 'PROMO'),
        );

        Auditor::log('admin.promo_generate', 'Сгенерировано кодов: '.$created);

        return back()->with('success', __('admin.messages.promo_generated', ['count' => $created]));
    }

    public function toggle(PromoCode $promo): RedirectResponse
    {
        $promo->forceFill(['is_active' => ! $promo->is_active])->save();

        return back()->with('success', __('admin.messages.promo_toggled'));
    }

    public function destroy(PromoCode $promo): RedirectResponse
    {
        $promo->delete();

        return redirect()->route('admin.promo')->with('success', __('admin.messages.promo_deleted'));
    }

    public function usages(PromoCode $promo): View
    {
        return view('admin.promo.usages', [
            'promo' => $promo,
            'uses' => $promo->uses()->with(['user:id,name,email', 'server:id,name'])->orderByDesc('created_at')->paginate(50),
        ]);
    }

    private function payload(Request $request, PromoCode $promo): array
    {
        $data = $request->validate([
            'code' => [$promo->exists ? 'sometimes' : 'required', 'string', 'max:40', 'unique:promo_codes,code'.($promo->exists ? ','.$promo->id : '')],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in(PromoCode::TYPES)],
            'percent' => ['nullable', 'integer', 'min:1', 'max:90'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'bonus_rub' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'bonus_slots' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bonus_memory_mb' => ['nullable', 'integer', 'min:0', 'max:65536'],
            'bonus_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'first_payment_only' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        foreach (['first_payment_only', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $data['created_by'] ??= $request->user()->id;

        return $data;
    }
}
