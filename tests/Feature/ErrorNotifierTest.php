<?php

namespace Msahidurr\ErrorNotifier\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Msahidurr\ErrorNotifier\ErrorReport;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Msahidurr\ErrorNotifier\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ErrorNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_it_sends_exception_details_to_telegram(): void
    {
        ErrorNotifier::report(new RuntimeException('Something <broke>'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '-100123'
                && $request['parse_mode'] === 'HTML'
                && str_contains($request['text'], 'RuntimeException')
                && str_contains($request['text'], 'Something &lt;broke&gt;')
                && str_contains($request['text'], 'Test App');
        });
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        $this->reconfigure(['enabled' => false]);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertNothingSent();
    }

    public function test_it_skips_ignored_environments(): void
    {
        $this->reconfigure(['ignored_environments' => ['testing']]);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertNothingSent();
    }

    public function test_it_skips_excluded_exceptions(): void
    {
        ErrorNotifier::report(new NotFoundHttpException());

        Http::assertNothingSent();
    }

    public function test_it_skips_telegram_when_credentials_are_missing(): void
    {
        $this->reconfigure(['drivers.telegram.bot_token' => null]);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertNothingSent();
    }

    public function test_it_throttles_duplicate_exceptions(): void
    {
        for ($i = 0; $i < 3; $i++) {
            ErrorNotifier::report(new RuntimeException('same'));
        }

        Http::assertSentCount(1);
    }

    public function test_it_auto_reports_exceptions_thrown_during_requests(): void
    {
        Route::get('boom', fn () => throw new RuntimeException('boom'));

        $this->get('boom')->assertStatus(500);

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'GET http://localhost/boom'));
    }

    public function test_it_skips_excluded_paths(): void
    {
        Route::get('api/v2/otp/generate/{x?}', fn () => throw new RuntimeException('otp'));

        $this->get('api/v2/otp/generate')->assertStatus(500);
        $this->get('api/v2/otp/generate/abc')->assertStatus(500);

        Http::assertNothingSent();
    }

    public function test_it_never_throws_when_a_channel_is_unreachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        ErrorNotifier::report(new RuntimeException('x'));

        $this->assertTrue(true);
    }

    public function test_it_supports_custom_channels_alongside_telegram(): void
    {
        $this->reconfigure([
            'channels' => ['telegram', 'memory'],
            'drivers.memory' => ['label' => 'from-config'],
        ]);

        $channel = new class implements Channel {
            public array $reports = [];

            public array $config = [];

            public function send(ErrorReport $report): void
            {
                $this->reports[] = $report;
            }
        };

        ErrorNotifier::extend('memory', function ($app, array $config) use ($channel) {
            $channel->config = $config;

            return $channel;
        });

        ErrorNotifier::report(new RuntimeException('multi'));

        Http::assertSentCount(1);
        $this->assertCount(1, $channel->reports);
        $this->assertSame('multi', $channel->reports[0]->message);
        $this->assertSame(['label' => 'from-config'], $channel->config);
    }

    public function test_a_failing_channel_does_not_block_the_others(): void
    {
        $this->reconfigure(['channels' => ['broken', 'telegram']]);

        ErrorNotifier::extend('broken', fn () => new class implements Channel {
            public function send(ErrorReport $report): void
            {
                throw new RuntimeException('nope');
            }
        });

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSentCount(1);
    }

    /** @test */
    public function test_command_sends_to_configured_channels(): void
    {
        $this->artisan('error-notifier:test')
            ->expectsOutputToContain('telegram: sent')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'error-notifier:test'));
    }

    public function test_it_redacts_the_bot_token_from_logged_errors(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 28: timed out for https://api.telegram.org/bottest-token/sendMessage'
        ));

        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        ErrorNotifier::report(new RuntimeException('x'));

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('test-token', implode("\n", $logged));
        $this->assertStringContainsString('bot***', implode("\n", $logged));
    }

    public function test_long_reports_fit_telegrams_limit_with_valid_html(): void
    {
        $this->reconfigure(['trace_lines' => 100]);

        ErrorNotifier::report(new RuntimeException(str_repeat('a&b<"c"> ', 2000)));

        Http::assertSent(function (Request $request) {
            $text = $request['text'];

            return mb_strlen($text) <= 4096
                && substr_count($text, '<code>') === substr_count($text, '</code>')
                && substr_count($text, '<pre>') === substr_count($text, '</pre>')
                && ! preg_match('/&(?!lt;|gt;|amp;)/', $text);
        });
    }

    public function test_it_sends_a_json_payload_with_typed_options(): void
    {
        $this->reconfigure(['drivers.telegram.message_thread_id' => '42']);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['message_thread_id'] === 42
                && $body['link_preview_options'] === ['is_disabled' => true];
        });
    }

    public function test_paths_are_relative_to_the_project_root(): void
    {
        $this->reconfigure(['trace_lines' => 5]);
        $exception = new class('x') extends RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->file = base_path('app/Services/Foo.php');
            }
        };

        ErrorNotifier::report($exception);

        Http::assertSent(function (Request $request) {
            return str_contains($request['text'], '<code>app/Services/Foo.php</code>')
                && ! str_contains($request['text'], base_path().'/');
        });
    }

    public function test_duplicate_channel_names_send_once(): void
    {
        $this->reconfigure(['channels' => ['telegram', 'telegram']]);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSentCount(1);
    }

    public function test_it_includes_the_authenticated_user(): void
    {
        $this->actingAs(new class extends \Illuminate\Foundation\Auth\User {
            protected $attributes = ['id' => 7, 'name' => 'Rahim'];
        });

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], '7 (Rahim)'));
    }

    public function test_a_non_string_user_name_does_not_drop_the_report(): void
    {
        $this->actingAs(new class extends \Illuminate\Foundation\Auth\User {
            protected $attributes = ['id' => 7];

            public function getNameAttribute()
            {
                return ['first' => 'A'];
            }
        });

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], '<code>7</code>'));
    }

    public function test_a_broken_notifier_does_not_break_laravels_error_handling(): void
    {
        $this->app->forgetInstance(\Msahidurr\ErrorNotifier\ErrorNotifier::class);
        $this->app->bind(\Msahidurr\ErrorNotifier\ErrorNotifier::class, fn () => throw new RuntimeException('container broke'));
        Route::get('boom', fn () => throw new RuntimeException('boom'));

        $this->get('boom')->assertStatus(500);
    }

    public function test_command_reports_failures_per_channel(): void
    {
        $this->reconfigure(['channels' => ['telegram', 'missing']]);

        $this->artisan('error-notifier:test')
            ->expectsOutputToContain('telegram: sent')
            ->expectsOutputToContain('missing: Driver [missing] not supported.')
            ->assertFailed();
    }

    protected function reconfigure(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            config()->set("error-notifier.{$key}", $value);
        }

        $this->app->forgetInstance(\Msahidurr\ErrorNotifier\ErrorNotifier::class);
        $this->app->forgetInstance(\Msahidurr\ErrorNotifier\ChannelManager::class);
        ErrorNotifier::clearResolvedInstances();
    }
}
