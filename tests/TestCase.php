<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nome del DB di test locale, atteso da .env.testing/phpunit.xml.
     */
    private const EXPECTED_LOCAL_TEST_DATABASE = 'camminiditalia_testing';

    /**
     * Guardia contro l'esecuzione dei test sul DB di sviluppo locale: se
     * .env.testing non viene caricato per qualsiasi motivo (es. config cache
     * stantia), fallisce rumorosamente qui invece di lasciare che
     * RefreshDatabase esegua migrate:fresh sul DB sbagliato.
     *
     * Disattivata in CI (config('app.running_in_ci')): la CI ha già una
     * propria protezione indipendente (env var esplicite in run-tests.yml,
     * che puntano al DB effimero del servizio Postgres — chiamato
     * "camminiditalia" per design, non "camminiditalia_testing"): applicare
     * qui lo stesso confronto romperebbe ogni test in CI.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->isolateRedisCache();

        if (config('app.running_in_ci')) {
            return;
        }

        $database = config('database.connections.pgsql.database');

        if ($database !== self::EXPECTED_LOCAL_TEST_DATABASE) {
            throw new RuntimeException(
                "I test stanno per girare sul DB '{$database}' invece di '".self::EXPECTED_LOCAL_TEST_DATABASE."'. ".
                "Controlla che .env.testing sia presente e lancia 'php artisan config:clear' se hai una config cache attiva."
            );
        }
    }

    /**
     * Lo store `redis` diventa `array` per tutta la suite (oc:8165, stessa scelta di wm-package
     * in oc:8660).
     *
     * `CACHE_STORE=array` in phpunit.xml non basta: un job che dichiara
     * `uniqueVia() { return Cache::store('redis'); }` chiede quello store per nome e finisce sul
     * Redis vero. I lock di `ShouldBeUnique` ci restano fino alla loro scadenza — un'ora — e
     * sopravvivono alla fine della suite: da lì in poi ogni dispatch con lo stesso identificativo
     * viene scartato **in silenzio**, e il test che lo attende fallisce senza un errore che
     * spieghi perché. Succedeva già prima di questo ticket: in Redis si accumulano lock di
     * `BuildAppPoisGeojsonJob` lasciati dalle esecuzioni dei test.
     */
    private function isolateRedisCache(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
    }
}
