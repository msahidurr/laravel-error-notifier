<?php

namespace Msahidurr\ErrorNotifier;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Msahidurr\ErrorNotifier\Commands\TestCommand;
use Throwable;

class ErrorNotifierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-notifier.php', 'error-notifier');

        $this->app->singleton(ChannelManager::class, fn ($app) => new ChannelManager($app));

        $this->app->singleton(ErrorNotifier::class, function ($app) {
            $config = $app['config']->get('error-notifier', []);

            return new ErrorNotifier(
                $app,
                $app->make(ChannelManager::class),
                new ErrorReportFactory($app, $config),
                $app->make('cache.store'),
                $app->make('log'),
                $config,
            );
        });

        $this->app->alias(ErrorNotifier::class, 'error-notifier');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/error-notifier.php' => config_path('error-notifier.php'),
            ], 'error-notifier-config');

            $this->commands([TestCommand::class]);
        }

        if ($this->app['config']->get('error-notifier.auto_report', true)) {
            $this->registerReportable();
        }
    }

    /**
     * Hook into Laravel's exception handler so every reported exception is
     * forwarded, without touching the app's Handler class.
     */
    protected function registerReportable(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e) {
                try {
                    $this->app->make(ErrorNotifier::class)->report($e);
                } catch (Throwable) {
                    // Never let the notifier interfere with Laravel's own error handling.
                }
            });
        });
    }
}
