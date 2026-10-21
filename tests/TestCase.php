<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes the database: never let it run against the real one
        $database = (string) config('database.connections.' . config('database.default') . '.database');
        if (!str_ends_with($database, '_test')) {
            $this->fail("Refusing to run tests on database '{$database}'. Use a database whose name ends with _test (see phpunit.xml).");
        }
    }
}
