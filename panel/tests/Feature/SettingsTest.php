<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\SettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Приоритет источников настроек: БД (админка) → config/hosting.php → default.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        app()->forgetInstance(SettingRepository::class);
    }

    public function test_falls_back_to_config_hosting_file(): void
    {
        // В config/hosting.php есть пресеты тарифов — они должны читаться без БД
        $this->assertSame('auto', setting('hosting.node_mode', 'auto'));
        $this->assertSame('RUB', setting('hosting.billing.currency', 'RUB'));
    }

    public function test_returns_default_for_unknown_key(): void
    {
        $this->assertSame('запасное', setting('hosting.nope.missing', 'запасное'));
        $this->assertNull(setting('hosting.nope.without_default'));
    }

    public function test_database_value_overrides_config(): void
    {
        $repo = app(SettingRepository::class);
        $repo->set('hosting.billing.currency', 'USD');

        $this->assertSame('USD', setting('hosting.billing.currency', 'RUB'));
    }

    public function test_forget_removes_override(): void
    {
        $repo = app(SettingRepository::class);
        $repo->set('hosting.billing.currency', 'USD');
        $this->assertSame('USD', setting('hosting.billing.currency'));

        $repo->forget('hosting.billing.currency');

        $this->assertSame('RUB', setting('hosting.billing.currency', 'RUB'));
    }

    public function test_values_are_cast_by_declared_type(): void
    {
        Setting::create(['group' => 'test', 'key' => 't.flag', 'value' => '1', 'type' => 'bool']);
        Setting::create(['group' => 'test', 'key' => 't.count', 'value' => '42', 'type' => 'int']);
        Setting::create(['group' => 'test', 'key' => 't.rate', 'value' => '1.5', 'type' => 'float']);
        Setting::create(['group' => 'test', 'key' => 't.list', 'value' => '{"a":1}', 'type' => 'json']);
        Setting::create(['group' => 'test', 'key' => 't.text', 'value' => 'как есть', 'type' => 'string']);

        app(SettingRepository::class)->flush();

        $this->assertTrue(setting_bool('t.flag'));
        $this->assertSame(42, setting_int('t.count'));
        $this->assertSame(1.5, setting_float('t.rate'));
        $this->assertSame(['a' => 1], setting_array('t.list'));
        $this->assertSame('как есть', setting('t.text'));
    }

    public function test_helper_shortcuts_cast_to_expected_php_types(): void
    {
        Setting::create(['group' => 'test', 'key' => 't.flag', 'value' => 'true', 'type' => 'bool']);
        Setting::create(['group' => 'test', 'key' => 't.count', 'value' => '7', 'type' => 'int']);

        app(SettingRepository::class)->flush();

        $this->assertIsBool(setting_bool('t.flag'));
        $this->assertIsInt(setting_int('t.count'));
        $this->assertIsFloat(setting_float('t.count'));
        $this->assertIsArray(setting_array('t.list', ['default']));
    }

    public function test_available_runtimes_come_from_config(): void
    {
        $runtimes = app(SettingRepository::class)->availableRuntimes();

        $this->assertContains('docker', $runtimes);
        $this->assertContains('native', $runtimes);
    }

    public function test_node_modes_are_the_three_documented_ones(): void
    {
        $this->assertSame(['single', 'manual', 'auto'], app(SettingRepository::class)->nodeModes());
    }
}
