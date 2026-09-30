<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\IpBan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminIpBanController extends Controller
{
    public function index(): View
    {
        return view('admin.ip-bans', [
            'bans' => IpBan::with('creator:id,name')->orderByDesc('created_at')->paginate(40),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in(['login', 'api', 'all'])],
            'expires_at' => ['nullable', 'date'],
        ]);

        // Валидируем CIDR
        if (! $this->validCidr($data['cidr'])) {
            return back()->with('error', __('admin.errors.invalid_cidr'));
        }

        IpBan::create(array_merge($data, [
            'is_active' => true,
            'created_by' => $request->user()->id,
            'created_by_name' => $request->user()->name,
        ]));

        IpBan::flushCache($data['cidr']);

        Auditor::log('admin.ip_ban', 'Забанен IP '.$data['cidr'].': '.$data['reason']);

        return back()->with('success', __('admin.messages.ip_banned'));
    }

    public function destroy(IpBan $ipBan): RedirectResponse
    {
        $cidr = $ipBan->cidr;
        $ipBan->delete();

        IpBan::flushCache($cidr);

        return back()->with('success', __('admin.messages.ip_unbanned'));
    }

    private function validCidr(string $value): bool
    {
        if (str_contains($value, '/')) {
            [$ip, $bits] = explode('/', $value, 2);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false
                && is_numeric($bits)
                && (int) $bits >= 0
                && (int) $bits <= (str_contains($ip, ':') ? 128 : 32);
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
