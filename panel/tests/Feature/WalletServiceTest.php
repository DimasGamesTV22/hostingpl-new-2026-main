<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerCharge;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Billing\InsufficientFundsException;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
    }

    public function test_credit_increases_balance_and_writes_history(): void
    {
        $user = User::factory()->withBalance(100)->create();

        $tx = $this->wallet->credit($user, 250, UserTransaction::TYPE_DEPOSIT, 'Пополнение');

        $user->refresh();

        $this->assertSame(350.0, (float) $user->balance);
        $this->assertSame(100.0, (float) $tx->balance_before);
        $this->assertSame(350.0, (float) $tx->balance_after);
        $this->assertSame(250.0, (float) $tx->amount);
        $this->assertSame(UserTransaction::DIR_CREDIT, $tx->direction);
    }

    public function test_deposit_total_grows_only_on_real_deposits(): void
    {
        $user = User::factory()->create();

        $this->wallet->credit($user, 500, UserTransaction::TYPE_DEPOSIT);
        $this->wallet->credit($user, 200, UserTransaction::TYPE_BONUS);
        $user->refresh();

        $this->assertSame(500.0, (float) $user->total_deposited);
        $this->assertSame(700.0, (float) $user->balance);
    }

    public function test_debit_reduces_balance_and_tracks_spent(): void
    {
        $user = User::factory()->withBalance(1000)->create();

        $this->wallet->debit($user, 400, UserTransaction::TYPE_PURCHASE, 'Оплата сервера');
        $user->refresh();

        $this->assertSame(600.0, (float) $user->balance);
        $this->assertSame(400.0, (float) $user->total_spent);
        $this->assertSame(0.0, (float) $user->total_deposited);
    }

    public function test_debit_beyond_balance_throws_and_changes_nothing(): void
    {
        $user = User::factory()->withBalance(100)->create();

        try {
            $this->wallet->debit($user, 500);
            $this->fail('Ожидалось InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            $this->assertSame(100.0, $e->balance);
            $this->assertSame(500.0, $e->required);
        }

        $user->refresh();
        $this->assertSame(100.0, (float) $user->balance);
        $this->assertSame(0, UserTransaction::count());
    }

    public function test_zero_amount_is_rejected(): void
    {
        $user = User::factory()->withBalance(100)->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->wallet->apply($user, 0.0, UserTransaction::TYPE_DEPOSIT, 'Пусто');
    }

    public function test_debit_rejects_non_positive_amount(): void
    {
        $user = User::factory()->withBalance(100)->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->wallet->debit($user, 0);
    }

    public function test_balance_is_rounded_to_two_decimals(): void
    {
        $user = User::factory()->withBalance(0)->create();

        $this->wallet->credit($user, 0.1);
        $this->wallet->credit($user, 0.2);
        $user->refresh();

        $this->assertSame(0.3, (float) $user->balance);
    }

    public function test_allow_negative_records_pending_charge(): void
    {
        $user = User::factory()->withBalance(50)->create();

        $this->wallet->allowNegative($user, 100, 'Начисление');
        $user->refresh();

        $this->assertSame(0.0, (float) $user->balance, 'в долг баланс не уходит ниже нуля');

        $tx = UserTransaction::latest('id')->first();
        $this->assertSame(UserTransaction::STATUS_PENDING, $tx->status);
        $this->assertSame(UserTransaction::TYPE_CHARGE, $tx->type);
    }

    // ── Депозиты ────────────────────────────────────────────────────────

    public function test_settle_deposit_credits_wallet_exactly_once(): void
    {
        $user = User::factory()->withBalance(0)->create();
        $deposit = $this->wallet->createDeposit($user, 1000, 'yookassa');

        $this->assertSame(Deposit::STATUS_PENDING, $deposit->status);

        $tx = $this->wallet->settleDeposit($deposit, 'provider-123');
        $this->assertNotNull($tx);

        $user->refresh();
        $deposit->refresh();

        $this->assertSame(1000.0, (float) $user->balance);
        $this->assertSame(1000.0, (float) $user->total_deposited);
        $this->assertSame(Deposit::STATUS_PAID, $deposit->status);
        $this->assertNotNull($deposit->paid_at);
        $this->assertSame('provider-123', $deposit->provider_id);
    }

    public function test_settle_is_idempotent(): void
    {
        $user = User::factory()->create();
        $deposit = $this->wallet->createDeposit($user, 500, 'yookassa');

        $first = $this->wallet->settleDeposit($deposit);
        $second = $this->wallet->settleDeposit($deposit);

        $this->assertNotNull($first);
        $this->assertNull($second, 'повторная обработка webhook не должна ничего делать');

        $user->refresh();
        $this->assertSame(500.0, (float) $user->balance);
        $this->assertSame(1, UserTransaction::count());
    }

    public function test_fail_deposit_increments_attempts(): void
    {
        $user = User::factory()->create();
        $deposit = $this->wallet->createDeposit($user, 100, 'yookassa');

        $this->wallet->failDeposit($deposit, 'Провайдер недоступен');
        $this->wallet->failDeposit($deposit, 'Провайдер недоступен');
        $deposit->refresh();

        $this->assertSame(Deposit::STATUS_FAILED, $deposit->status);
        $this->assertSame(2, (int) $deposit->attempts);
        $this->assertStringContainsString('недоступен', $deposit->error);
    }

    public function test_create_deposit_applies_promo_discount(): void
    {
        $user = User::factory()->create();

        \App\Models\PromoCode::factory()
            ->code('SAVE20')
            ->percent(20)
            ->create();

        $deposit = $this->wallet->createDeposit($user, 1000, 'yookassa', 'save20');

        $this->assertSame(800.0, (float) $deposit->amount, 'скидка 20% вычтена из суммы');
        $this->assertSame(200.0, (float) $deposit->meta['discount']);
        $this->assertSame(1000.0, (float) $deposit->meta['original_amount']);
    }

    public function test_discount_cannot_push_amount_below_zero(): void
    {
        $user = User::factory()->create();

        \App\Models\PromoCode::factory()->code('HUGE')->percent(100)->create();

        $deposit = $this->wallet->createDeposit($user, 100, 'yookassa', 'HUGE');

        $this->assertSame(0.0, (float) $deposit->amount);
    }

    public function test_expired_promo_does_not_discount(): void
    {
        $user = User::factory()->create();

        \App\Models\PromoCode::factory()->code('OLD')->percent(50)->expired()->create();

        $deposit = $this->wallet->createDeposit($user, 1000, 'yookassa', 'OLD');

        $this->assertSame(1000.0, (float) $deposit->amount);
    }

    // ── Начисления ──────────────────────────────────────────────────────

    public function test_create_charge_spans_tariff_duration(): void
    {
        $user = User::factory()->withBalance(5000)->create();
        $server = Server::factory()
            ->ownedBy($user, Game::factory()->create(), null, Tariff::factory()->create(['duration_days' => 30]))
            ->create();

        $charge = $this->wallet->createCharge($user, $server, 450);

        $this->assertSame(450.0, (float) $charge->amount);
        $this->assertSame(ServerCharge::STATUS_PENDING, $charge->status);
        $this->assertSame(30, (int) $charge->period_start->diffInDays($charge->period_end));
    }

    public function test_pay_and_fail_charge_transitions(): void
    {
        $user = User::factory()->withBalance(5000)->create();
        $server = Server::factory()
            ->ownedBy($user, Game::factory()->create(), null, Tariff::factory()->create())
            ->create();

        $charge = $this->wallet->createCharge($user, $server, 100);
        $this->wallet->payCharge($charge);
        $this->assertSame(ServerCharge::STATUS_PAID, $charge->fresh()->status);

        $other = $this->wallet->createCharge($user, $server, 100);
        $this->wallet->failCharge($other);
        $this->assertSame(ServerCharge::STATUS_FAILED, $other->fresh()->status);
    }

    // ── Прочее ──────────────────────────────────────────────────────────

    public function test_can_afford_compares_with_balance(): void
    {
        $user = User::factory()->withBalance(100)->create();

        $this->assertTrue($this->wallet->canAfford($user, 100));
        $this->assertFalse($this->wallet->canAfford($user, 100.01));
    }

    public function test_can_afford_ignores_already_paid_deposit(): void
    {
        $user = User::factory()->withBalance(0)->create();
        $deposit = $this->wallet->createDeposit($user, 300, 'yookassa');
        $this->wallet->settleDeposit($deposit);
        $user->forceFill(['balance' => 0])->save();

        $this->assertTrue($this->wallet->canAfford($user, 300, $deposit->id));
        $this->assertFalse($this->wallet->canAfford($user, 300));
    }

    public function test_summary_separates_income_and_expense(): void
    {
        $user = User::factory()->create();

        $this->wallet->credit($user, 1000, UserTransaction::TYPE_DEPOSIT);
        $this->wallet->debit($user, 300, UserTransaction::TYPE_PURCHASE);

        $summary = $this->wallet->summary($user);

        $this->assertSame(1000.0, $summary['income']);
        $this->assertSame(300.0, $summary['expense']);
        $this->assertSame(700.0, $summary['net']);
        $this->assertSame(700.0, $summary['balance']);
    }

    public function test_summary_ignores_pending_transactions(): void
    {
        $user = User::factory()->withBalance(500)->create();
        $this->wallet->allowNegative($user, 200, 'Начисление');

        $summary = $this->wallet->summary($user);

        $this->assertSame(0.0, $summary['expense'], 'pending-операции не попадают в отчёт');
    }
}
