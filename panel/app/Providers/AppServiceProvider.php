<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Node;
use App\Models\Server;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentConnection;
use App\Services\Agent\AgentRegistry;
use App\Services\Billing\PaymentManager;
use App\Services\Billing\WalletService;
use App\Services\Games\ConfigFileService;
use App\Services\Monitoring\AlertService;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Nodes\NodeScheduler;
use App\Services\Nodes\PortAllocator;
use App\Services\Notify\Notifier;
use App\Services\Notify\TelegramService;
use App\Services\Servers\Provisioner;
use App\Support\SettingRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingRepository::class);
        $this->app->singleton(AgentRegistry::class);
        $this->app->singleton(AgentConnection::class);
        $this->app->singleton(AgentClient::class);
        $this->app->singleton(NodeScheduler::class);
        $this->app->singleton(PortAllocator::class);
        $this->app->singleton(WalletService::class);
        $this->app->singleton(PaymentManager::class);
        $this->app->singleton(Provisioner::class);
        $this->app->singleton(ConfigFileService::class);
        $this->app->singleton(ConsoleBroadcaster::class);
        $this->app->singleton(MetricsCollector::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(TelegramService::class);
        $this->app->singleton(Notifier::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading($this->app->environment('production'));
        Model::unguard(false);

        $this->configureUrl();
        $this->configureDates();
        $this->configurePasswords();
        $this->configureBlade();
        $this->configureViewComposers();
    }

    private function configureUrl(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    private function configureDates(): void
    {
        Date::use(\Illuminate\Support\Carbon::class);
        Date::serializeUsing(fn ($date) => $date->toIso8601String());
    }

    private function configurePasswords(): void
    {
        $min = (int) config('hosting.auth.password.min', 8);

        Password::defaults(fn () => Password::min($min)
            ->mixedCase((bool) config('hosting.auth.password.require_mixed_case', true))
            ->numbers((bool) config('hosting.auth.password.require_numbers', true))
            ->symbols((bool) config('hosting.auth.password.require_symbols', false))
            ->uncompromised(false));
    }

    private function configureBlade(): void
    {
        // Тёмная тема фиксирована, но оставляем переключатель: класс на <html>
        Blade::directive('money', fn ($expr) => "<?php echo money($expr); ?>");
        Blade::directive('setting', fn ($expr) => "<?php echo e(setting($expr)); ?>");
        Blade::directive('bytes', fn ($expr) => "<?php echo bytes_human($expr); ?>");
    }

    private function configureViewComposers(): void
    {
        // Общие данные для верхнего меню: кошелёк, уведомления, непрочитанные тикеты
        View::composer(['layouts.app', 'layouts.dashboard'], function ($view) {
            $user = auth()->user();

            $view->with([
                'currentUser' => $user,
                'unreadNotifications' => $user
                    ? \App\Models\PanelNotification::where('user_id', $user->id)->whereNull('read_at')->count()
                    : 0,
                'openTickets' => $user
                    ? \App\Models\Ticket::where('user_id', $user->id)->open()->count()
                    : 0,
            ]);
        });
    }
}
