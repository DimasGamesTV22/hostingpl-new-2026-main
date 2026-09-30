<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Базовый класс тестов. Приложение поднимает сам Laravel 11
 * (Illuminate\Foundation\Testing\TestCase вызывает createApplication()).
 */
abstract class TestCase extends BaseTestCase
{
    //
}
