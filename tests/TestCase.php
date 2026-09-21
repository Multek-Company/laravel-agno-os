<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Tests;

use Multek\AgnoOS\AgnoOSServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AgnoOSServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('agno-os.url', 'https://agentos.test');
        $app['config']->set('agno-os.auth.driver', 'jwt');
        $app['config']->set('agno-os.auth.algorithm', 'HS256');
        $app['config']->set('agno-os.auth.signing_key', 'test-secret-at-least-32-characters');
        $app['config']->set('agno-os.auth.audience', 'agentos-test');
        $app['config']->set('agno-os.http.throw', false);
    }
}
