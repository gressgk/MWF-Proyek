<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pastikan seluruh Feature test ber-DatabaseTransactions hanya berjalan
     * pada database wecare_testing sebelum transaksi dibuka.
     */
    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        if (isset($uses[DatabaseTransactions::class])) {
            $configuredDefault = config('database.default');
            $configuredDb = config('database.connections.mysql.database');
            if ($configuredDefault !== 'mysql' || $configuredDb !== 'wecare_testing') {
                throw new \RuntimeException(
                    "SAFETY VIOLATION: Expected mysql/wecare_testing before DatabaseTransactions, got {$configuredDefault}/{$configuredDb}."
                );
            }

            $effective = DB::connection('mysql')->selectOne('SELECT DATABASE() AS db');
            if (($effective->db ?? null) !== 'wecare_testing') {
                throw new \RuntimeException(
                    'SAFETY VIOLATION: Effective SELECT DATABASE() must be wecare_testing, got ' . ($effective->db ?? 'null')
                );
            }
        }

        return parent::setUpTraits();
    }
}
