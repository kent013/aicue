<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;
}
