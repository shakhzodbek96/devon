<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * `RefreshDatabase` wraps each test in a transaction; SQLite reuses
     * auto-increment ids after a rollback, so spatie/laravel-permission's
     * role/permission cache (keyed by those ids) can serve stale
     * role→permission mappings from a previous test. Forgetting the cache
     * before every test forces a fresh lookup against the current
     * transaction's rows.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
