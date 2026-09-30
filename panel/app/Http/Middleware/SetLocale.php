<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выбор языка интерфейса: query ?lang=en > cookie > пользователь > по умолчанию.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys((array) setting_array('hosting.locale.available', ['ru', 'en']));
        $default = (string) setting('hosting.locale.default', 'ru');

        $locale = $request->query('lang')
            ?: $request->cookie('lang')
            ?: (auth()->user()?->locale)
            ?: $default;

        if (! in_array($locale, $available, true)) {
            $locale = $default;
        }

        App::setLocale($locale);

        // Часовой пояс пользователя
        $timezone = auth()->user()?->timezone ?: (string) setting('hosting.locale.timezone', 'Europe/Moscow');

        try {
            date_default_timezone_set($timezone);
        } catch (\Throwable) {
            // некорректная зона — оставляем системную
        }

        if ($request->query('lang') !== null && $request->query('lang') !== $locale) {
            return redirect()->back();
        }

        if ($request->query('lang') !== null) {
            cookie()->queue('lang', $locale, 60 * 24 * 365);
        }

        view()->share('locale', $locale);
        view()->share('locales', (array) setting_array('hosting.locale.available', ['ru', 'en']));

        return $next($request);
    }
}
