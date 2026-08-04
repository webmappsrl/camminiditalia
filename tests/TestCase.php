<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guardia contro l'esecuzione dei test sul DB di sviluppo: se .env.testing
     * non viene caricato per qualsiasi motivo (es. config cache stantia),
     * fallisce rumorosamente qui invece di lasciare che RefreshDatabase
     * esegua migrate:fresh sul DB sbagliato.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $database = config('database.connections.pgsql.database');

        if ($database !== 'camminiditalia_testing') {
            throw new RuntimeException(
                "I test stanno per girare sul DB '{$database}' invece di 'camminiditalia_testing'. ".
                "Controlla che .env.testing sia presente e lancia 'php artisan config:clear' se hai una config cache attiva."
            );
        }
    }
}
