<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$connection.'.database');
        if ($database !== ':memory:' && ! str_ends_with((string) $database, '_test')) {
            throw new \LogicException('Tests require :memory: or a dedicated database whose name ends in _test.');
        }

        return $app;
    }
}
