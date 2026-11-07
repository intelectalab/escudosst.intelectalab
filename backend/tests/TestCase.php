<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Las pruebas usan la base escudo_main_test (phpunit.xml), cargada con el esquema,
 * las semillas y docker/postgres/dev-seed.sql. Cada prueba corre dentro de una
 * transacción que se revierte (DatabaseTransactions en tests/Pest.php).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            // Migraciones de Laravel sobre el esquema generado, con el rol dueño.
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$migrated = true;
        }
    }
}
