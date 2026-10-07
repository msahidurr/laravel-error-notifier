<?php

namespace Msahidurr\ErrorNotifier\Tests;

use Msahidurr\ErrorNotifier\ErrorNotifierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ErrorNotifierServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Test App');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('error-notifier.enabled', true);
        $app['config']->set('error-notifier.channels', ['telegram']);
        $app['config']->set('error-notifier.ignored_environments', []);
        $app['config']->set('error-notifier.excluded_paths', ['api/v2/otp/generate']);
        $app['config']->set('error-notifier.drivers.telegram.bot_token', 'test-token');
        $app['config']->set('error-notifier.drivers.telegram.chat_id', '-100123');
    }
}
