<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * SAFETY: tests wipe their database. If the config is cached (e.g. after
     * `php artisan optimize` for the local deployment), phpunit.xml's test DB
     * settings are ignored and the REAL database would be wiped. Refuse.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $db = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! str_ends_with((string) $db, '_test')) {
            throw new \RuntimeException("Refusing to run tests against database '{$db}'. Run `php artisan config:clear` first.");
        }

        return $app;
    }

    //
}
