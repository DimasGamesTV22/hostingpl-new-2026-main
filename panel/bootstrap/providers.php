<?php

use App\Modules\Agent\Providers\AgentServiceProvider;
use App\Modules\Backups\Providers\BackupsServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Games\Providers\GamesServiceProvider;
use App\Modules\Monitoring\Providers\MonitoringServiceProvider;
use App\Modules\Nodes\Providers\NodesServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Promo\Providers\PromoServiceProvider;
use App\Modules\Public\Providers\PublicServiceProvider;
use App\Modules\Referral\Providers\ReferralServiceProvider;
use App\Modules\SecretCodes\Providers\SecretCodesServiceProvider;
use App\Modules\Servers\Providers\ServersServiceProvider;
use App\Modules\Support\Providers\SupportServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    UsersServiceProvider::class,
    GamesServiceProvider::class,
    NodesServiceProvider::class,
    ServersServiceProvider::class,
    BillingServiceProvider::class,
    PaymentsServiceProvider::class,
    MonitoringServiceProvider::class,
    BackupsServiceProvider::class,
    PromoServiceProvider::class,
    ReferralServiceProvider::class,
    SecretCodesServiceProvider::class,
    SupportServiceProvider::class,
    AgentServiceProvider::class,
    PublicServiceProvider::class,
];
