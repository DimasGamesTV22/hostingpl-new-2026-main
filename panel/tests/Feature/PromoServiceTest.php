<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Node;
use App\Models\PromoCode;
use App\Models\PromoUse;
use App\Models\Referral;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Promo\PromoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromoService $promo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promo = app(PromoService::class);
    }

    private function makeServer(User $user, ?Game $game = null, ?Tariff $tariff = null): Server
    {
        return Server::factory()
            ->ownedBy($user, $game ?? Game::factory()->create(), Node::factory()->create(), $tariff ?? Tariff::factory()->create())
            ->expiringIn(30)
            ->create();
    }

    // ── Проверка ────────────────────────────────────────────────────────

    public function test_validate_accepts_a_good_code_regardless_of_case(): void
    {
        PromoCode::factory()->code('WELCOME10')->percent(10)->create();
        $user = User::factory()->create();

        $result = $this->promo->validate('welcome10', $user, 1000);

        $this->assertTrue($result['ok']);
        $this->assertSame(100.0, $result['discount']);
    }

    public function test_validate_reports_unknown_code(): void
    {
        $result = $this->promo->validate('НЕТ-ТАКОГО', User::factory()->create(), 1000);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['reason']);
        $this->assertSame(0.0, $result['discount']);
    }

    public function test_validate_rejects_inactive_code(): void
    {
        PromoCode::factory()->code('OFF')->percent(10)->inactive()->create();

        $this->assertFalse($this->promo->validate('OFF', User::factory()->create(), 1000)['ok']);
    }

    public function test_validate_rejects_expired_code(): void
    {
        PromoCode::factory()->code('OLD')->percent(10)->expired()->create();

        $this->assertFalse($this->promo->validate('OLD', User::factory()->create(), 1000)['ok']);
    }

    public function test_validate_rejects_code_that_has_not_started(): void
    {
        PromoCode::factory()->code('SOON')->percent(10)->notYetValid()->create();

        $this->assertFalse($this->promo->validate('SOON', User::factory()->create(), 1000)['ok']);
    }

    public function test_discount_respects_max_discount_cap(): void
    {
        PromoCode::factory()
            ->code('CAP')
            ->percent(50)
            ->create(['max_discount' => 300]);

        $result = $this->promo->validate('CAP', User::factory()->create(), 1000);

        $this->assertTrue($result['ok']);
        $this->assertSame(300.0, $result['discount'], 'скидка ограничена потолком');
    }

    public function test_discount_below_min_order_is_rejected(): void
    {
        PromoCode::factory()->code('BIG')->percent(10)->withMinOrder(500)->create();

        $this->assertFalse($this->promo->validate('BIG', User::factory()->create(), 100)['ok']);
        $this->assertTrue($this->promo->validate('BIG', User::factory()->create(), 500)['ok']);
    }

    public function test_type_can_be_disabled_from_settings(): void
    {
        PromoCode::factory()->code('OFF')->duration(30)->create();

        app(\App\Support\SettingRepository::class)->set('hosting.marketing.promo_duration.enabled', false);

        $this->assertFalse($this->promo->validate('OFF', User::factory()->create())['ok']);
    }

    // ── Активация ───────────────────────────────────────────────────────

    public function test_redeem_duration_promo_extends_server(): void
    {
        $user = User::factory()->create();
        $server = $this->makeServer($user);
        $before = $server->expires_at->copy();

        PromoCode::factory()->code('MONTH')->duration(30)->create();

        $result = $this->promo->redeem('MONTH', $user, $server);

        $this->assertTrue($result['ok']);
        $this->assertSame(30, $result['applied']['days_added']);
        $this->assertTrue($server->fresh()->expires_at->equalTo($before->copy()->addDays(30)));
    }

    public function test_redeem_rub_bonus_credits_wallet(): void
    {
        $user = User::factory()->withBalance(100)->create();
        PromoCode::factory()->code('GIFT')->bonusRub(250)->create();

        $result = $this->promo->redeem('GIFT', $user);

        $this->assertTrue($result['ok']);
        $this->assertSame(250.0, $result['applied']['rub']);
        $this->assertSame(350.0, (float) $user->fresh()->balance);
    }

    public function test_redeem_writes_usage_and_bumps_counter(): void
    {
        $user = User::factory()->create();
        $promo = PromoCode::factory()->code('ONCE')->duration(7)->create();

        $this->promo->redeem('ONCE', $user, $this->makeServer($user));

        $this->assertSame(1, (int) $promo->fresh()->used_count);
        $this->assertSame(1, PromoUse::where('promo_code_id', $promo->id)->count());
    }

    public function test_per_user_limit_stops_second_redeem(): void
    {
        $user = User::factory()->create();
        PromoCode::factory()->code('ONCE')->duration(7)->withPerUserLimit(1)->create();

        $this->assertTrue($this->promo->redeem('ONCE', $user, $this->makeServer($user))['ok']);

        $second = $this->promo->redeem('ONCE', $user, $this->makeServer($user));
        $this->assertFalse($second['ok']);
        $this->assertSame(1, PromoUse::count());
    }

    public function test_total_limit_stops_redeem_after_exhaustion(): void
    {
        PromoCode::factory()->code('LIMIT')->duration(7)->withTotalLimit(1)->create();

        $this->assertTrue($this->promo->redeem(
            'LIMIT',
            User::factory()->create(),
            $this->makeServer(User::factory()->create()),
        )['ok']);

        $this->assertFalse($this->promo->redeem(
            'LIMIT',
            User::factory()->create(),
            $this->makeServer(User::factory()->create()),
        )['ok']);
    }

    public function test_first_payment_only_blocks_user_who_already_paid(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['total_deposited' => 500])->save();

        PromoCode::factory()->code('FIRST')->duration(7)->create(['first_payment_only' => true]);

        $this->assertFalse($this->promo->redeem('FIRST', $user)['ok']);
    }

    public function test_promo_restricted_to_another_game_is_rejected(): void
    {
        $user = User::factory()->create();
        $allowed = Game::factory()->create();
        $other = Game::factory()->create();

        PromoCode::factory()->code('ONLY')->duration(7)->onlyForGame($allowed->id)->create();

        $this->assertFalse($this->promo->redeem('ONLY', $user, $this->makeServer($user, $other))['ok']);
        $this->assertTrue($this->promo->redeem('ONLY', $user, $this->makeServer($user, $allowed))['ok']);
    }

    public function test_failed_redeem_does_not_touch_wallet(): void
    {
        $user = User::factory()->withBalance(10)->create();
        PromoCode::factory()->code('NOPE')->bonusRub(500)->inactive()->create();

        $this->promo->redeem('NOPE', $user);

        $this->assertSame(10.0, (float) $user->fresh()->balance);
    }

    // ── Реферальная программа ───────────────────────────────────────────

    public function test_attach_referral_links_users(): void
    {
        $referrer = User::factory()->withReferralCode('FRIEND1')->create();
        $user = User::factory()->create();

        $this->assertTrue($this->promo->attachReferral($user, 'friend1'));
        $this->assertSame($referrer->id, $user->fresh()->referred_by);
        $this->assertSame(1, Referral::where('referred_id', $user->id)->count());
    }

    public function test_user_cannot_refer_themselves(): void
    {
        $user = User::factory()->withReferralCode('SELF')->create();

        $this->assertFalse($this->promo->attachReferral($user, 'SELF'));
    }

    public function test_referral_is_not_overwritten(): void
    {
        $first = User::factory()->withReferralCode('ONE')->create();
        $second = User::factory()->withReferralCode('TWO')->create();
        $user = User::factory()->create();

        $this->promo->attachReferral($user, 'ONE');
        $this->assertFalse($this->promo->attachReferral($user, 'TWO'));
        $this->assertSame($first->id, $user->fresh()->referred_by);
        unset($second);
    }

    public function test_unknown_referral_code_is_ignored(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->promo->attachReferral($user, 'НЕТ'));
        $this->assertNull($user->fresh()->referred_by);
    }

    public function test_referral_reward_paid_after_first_deposit(): void
    {
        $referrer = User::factory()->withReferralCode('BUDDY')->create();
        $user = User::factory()->create();

        $this->promo->attachReferral($user, 'BUDDY');

        // Первая оплата ниже порога — награды нет
        $this->promo->completeReferralIfNeeded($user, 50);
        $this->assertSame(Referral::STATUS_PENDING, Referral::where('referred_id', $user->id)->first()->status);

        // Оплата выше порога — награда начислена обоим
        $this->promo->completeReferralIfNeeded($user, 1000);

        $referral = Referral::where('referred_id', $user->id)->first();

        $this->assertSame(Referral::STATUS_COMPLETED, $referral->status);
        $this->assertNotNull($referral->completed_at);
        $this->assertSame(1, (int) $referrer->fresh()->referral_count);
        $this->assertSame(1, UserTransaction::where('type', UserTransaction::TYPE_REFERRAL)->count());
    }

    public function test_referral_is_not_paid_twice(): void
    {
        $referrer = User::factory()->withReferralCode('BUDDY')->create();
        $user = User::factory()->create();

        $this->promo->attachReferral($user, 'BUDDY');
        $this->promo->completeReferralIfNeeded($user, 1000);
        $this->promo->completeReferralIfNeeded($user, 1000);

        $this->assertSame(1, UserTransaction::where('type', UserTransaction::TYPE_REFERRAL)->count());
        $this->assertSame(1, (int) $referrer->fresh()->referral_count);
    }

    public function test_referral_can_be_disabled_globally(): void
    {
        app(\App\Support\SettingRepository::class)->set('hosting.marketing.referral.enabled', false);

        $referrer = User::factory()->withReferralCode('BUDDY')->create();
        $user = User::factory()->create();

        $this->assertFalse($this->promo->attachReferral($user, 'BUDDY'));
        $this->assertNull($user->fresh()->referred_by);
        unset($referrer);
    }

    public function test_generate_many_creates_unique_codes(): void
    {
        $count = $this->promo->generateMany(20, ['type' => PromoCode::TYPE_DURATION, 'days' => 7], 'PROMO');

        $this->assertSame(20, $count);
        $this->assertSame(20, PromoCode::where('code', 'like', 'PROMO-%')->count());
    }

    public function test_available_for_marks_code_ok_or_not_with_reason(): void
    {
        $user = User::factory()->create();

        // Лимит 5 — код виден в выдаче, но уже исчерпан для пользователя
        PromoCode::factory()->code('OK')->duration(7)->withPerUserLimit(5)->create();
        PromoCode::factory()->code('USED')->duration(7)->withPerUserLimit(1)->create();

        $this->promo->redeem('USED', $user);

        $codes = collect($this->promo->availableFor($user))->keyBy('code');

        $this->assertTrue($codes['OK']['ok']);
        $this->assertFalse($codes['USED']['ok']);
        $this->assertNotNull($codes['USED']['reason']);
    }
}
