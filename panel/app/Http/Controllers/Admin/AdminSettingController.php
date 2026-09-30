<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SettingRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * Настройки панели. Значения перекрывают config/hosting.php на лету.
 */
class AdminSettingController extends Controller
{
    public function __construct(private readonly SettingRepository $settings) {}

    public function index(): View
    {
        $settings = Setting::orderBy('group')->orderBy('sort')->orderBy('key')->get()
            ->groupBy('group');

        return view('admin.settings', [
            'groups' => $settings,
            'sensitive' => ['mail.password', 'payments.yookassa.secret_key', 'payments.tinkoff.password', 'payments.cryptobot.token'],
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $allowed = Setting::where('group', $group)->pluck('key');

        $input = $request->input('settings', []);

        $changed = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $setting = Setting::where('key', $key)->first();
            $old = $setting->value;
            $value = $input[$key];

            // Пустое значение для секретов = сброс
            if ($setting->type === 'password' && $value === '' && $old !== null) {
                continue;
            }

            $setting->value = match ($setting->type) {
                'bool' => $value ? '1' : '0',
                'json' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                default => is_array($value) ? json_encode($value) : (string) $value,
            };

            $setting->save();

            if ($old !== $setting->value) {
                $changed[] = $key;
            }
        }

        $this->settings->flush();

        if ($changed !== []) {
            Auditor::log('admin.settings', 'Изменены настройки: '.implode(', ', $changed));
        }

        return back()->with('success', __('admin.messages.settings_saved', ['count' => count($changed)]));
    }

    public function clearCache(): RedirectResponse
    {
        $this->settings->flush();
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\View::clearCompiled();

        Artisan::call('view:clear');
        Artisan::call('config:clear');

        return back()->with('success', __('admin.messages.cache_cleared'));
    }
}
