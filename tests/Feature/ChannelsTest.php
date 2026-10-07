<?php

namespace Msahidurr\ErrorNotifier\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Msahidurr\ErrorNotifier\ErrorNotifier as Notifier;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Msahidurr\ErrorNotifier\Jobs\SendErrorReport;
use Msahidurr\ErrorNotifier\Tests\TestCase;
use RuntimeException;

class ChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    public function test_slack_escapes_control_characters_and_mentions(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/secret']]);

        ErrorNotifier::report(new RuntimeException('Hi <!channel> & ```end``` <b>'));

        Http::assertSent(function (Request $request) {
            $text = $request['text'];

            return $request->url() === 'https://hooks.slack.com/services/T0/B0/secret'
                && str_contains($text, '*Exception:* `RuntimeException`')
                && str_contains($text, '&lt;!channel&gt; &amp;')
                && ! str_contains($text, '<!channel>')
                && substr_count($text, '```') === 2;
        });
    }

    public function test_mattermost_uses_standard_markdown(): void
    {
        $this->useChannels(['mattermost' => ['webhook_url' => 'https://chat.example.com/hooks/abc']]);

        ErrorNotifier::report(new RuntimeException('a < b'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], '**Exception:**')
            && str_contains($request['text'], 'a < b'));
    }

    public function test_discord_sends_an_embed_without_mentions_within_limits(): void
    {
        $this->useChannels(['discord' => ['webhook_url' => 'https://discord.com/api/webhooks/1/abc'], 'trace_lines' => 100]);

        ErrorNotifier::report(new RuntimeException(str_repeat('@everyone ``` ', 600)));

        Http::assertSent(function (Request $request) {
            $embed = $request['embeds'][0];
            $total = mb_strlen($embed['title']) + mb_strlen($embed['description'])
                + array_sum(array_map(fn ($f) => mb_strlen($f['name']) + mb_strlen($f['value']), $embed['fields']));

            return $request['allowed_mentions'] === ['parse' => []]
                && mb_strlen($embed['description']) <= 4096
                && $total <= 6000
                && substr_count($embed['description'], '```') % 2 === 0
                && collect($embed['fields'])->every(fn ($f) => mb_strlen($f['value']) <= 1024);
        });
    }

    public function test_google_chat_can_group_reports_in_one_thread(): void
    {
        $this->useChannels(['google_chat' => [
            'webhook_url' => 'https://chat.googleapis.com/v1/spaces/X/messages?key=k&token=t',
            'thread_key' => 'errors',
        ]]);

        ErrorNotifier::report(new RuntimeException('x'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'key=k&token=t&threadKey=errors&messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD')
            && str_contains($request['text'], '*Exception:*'));
    }

    public function test_teams_sends_an_adaptive_card_with_literal_text(): void
    {
        $this->useChannels(['teams' => ['webhook_url' => 'https://prod.workflows.example/webhook'], 'trace_lines' => 100]);

        ErrorNotifier::report(new RuntimeException('**not bold** _x_'));

        Http::assertSent(function (Request $request) {
            $attachment = $request['attachments'][0];
            $body = $attachment['content']['body'];

            return $request['type'] === 'message'
                && $attachment['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && $body[1]['type'] === 'RichTextBlock'
                && $body[1]['inlines'][0]['text'] === '**not bold** _x_'
                && strlen($request->body()) <= 20000;
        });
    }

    public function test_teams_payload_stays_under_the_size_limit(): void
    {
        $this->useChannels(['teams' => ['webhook_url' => 'https://prod.workflows.example/webhook'], 'trace_lines' => 500]);

        ErrorNotifier::report(new RuntimeException(str_repeat('ঢাকা "quoted" ', 2000)));

        Http::assertSent(fn (Request $request) => strlen(json_encode(json_decode($request->body(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 20000);
    }

    public function test_webhook_signs_the_exact_body(): void
    {
        $this->useChannels(['webhook' => [
            'url' => 'https://example.com/hook',
            'secret' => 's3cret',
            'headers' => ['X-Team' => 'api'],
        ]]);

        ErrorNotifier::report(new RuntimeException('signed'));

        Http::assertSent(function (Request $request) {
            $data = json_decode($request->body(), true);

            return $request->hasHeader('X-Error-Notifier-Signature', 'sha256='.hash_hmac('sha256', $request->body(), 's3cret'))
                && $request->hasHeader('X-Team', 'api')
                && $data['message'] === 'signed'
                && strlen($data['fingerprint']) === 40;
        });
    }

    public function test_pagerduty_uses_the_fingerprint_as_dedup_key(): void
    {
        $this->useChannels(['pagerduty' => ['routing_key' => 'R0UT1NG', 'severity' => 'critical']]);

        $exception = new RuntimeException('db down');
        ErrorNotifier::report($exception);

        $fingerprint = app(Notifier::class)->reportFactory()->fingerprint($exception);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://events.pagerduty.com/v2/enqueue'
            && $request['routing_key'] === 'R0UT1NG'
            && $request['event_action'] === 'trigger'
            && $request['dedup_key'] === $fingerprint
            && $request['payload']['severity'] === 'critical'
            && str_contains($request['payload']['summary'], 'RuntimeException: db down'));
    }

    public function test_mail_sends_a_plain_text_email(): void
    {
        config()->set('mail.default', 'array');
        $this->useChannels(['mail' => ['to' => 'dev@example.com, ops@example.com']]);

        ErrorNotifier::report(new RuntimeException('mail me'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertSame(['dev@example.com', 'ops@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertStringContainsString('RuntimeException in testing: mail me', $email->getSubject());
        $this->assertStringContainsString('Exception:   RuntimeException', $email->getTextBody());
    }

    public function test_webhook_urls_are_redacted_from_logged_errors(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/topsecret']]);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: failed for https://hooks.slack.com/services/T0/B0/topsecret'));

        $this->captureLogs();
        ErrorNotifier::report(new RuntimeException('x'));

        $this->assertStringNotContainsString('topsecret', implode("\n", $this->logged));
        $this->assertStringContainsString('https://hooks.slack.com/***', implode("\n", $this->logged));
    }

    public function test_pagerduty_routing_key_is_redacted_from_logged_errors(): void
    {
        $this->useChannels(['pagerduty' => ['routing_key' => 'R0UT1NG']]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('{"status":"invalid event","message":"R0UT1NG is wrong"}', 400)]);

        $this->captureLogs();
        ErrorNotifier::report(new RuntimeException('x'));

        $this->assertNotEmpty($this->logged);
        $this->assertStringNotContainsString('R0UT1NG', implode("\n", $this->logged));
    }

    public function test_rate_limit_drops_extra_reports_per_channel(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/x', 'rate_limit' => 2]]);
        $this->captureLogs();

        foreach (['a', 'b', 'c'] as $message) {
            ErrorNotifier::report(new RuntimeException($message));
        }

        Http::assertSentCount(2);
        $this->assertStringContainsString('rate limit reached', implode("\n", $this->logged));
    }

    public function test_per_channel_exception_filters(): void
    {
        $this->useChannels([
            'slack' => ['webhook_url' => 'https://hooks.slack.com/x', 'except_exceptions' => [LogicException::class]],
            'pagerduty' => ['routing_key' => 'k', 'only_exceptions' => [RuntimeException::class]],
        ]);

        ErrorNotifier::report(new InvalidArgumentException('logic subclass'));
        ErrorNotifier::report(new RuntimeException('runtime'));

        $sent = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST))->all();

        // InvalidArgumentException extends LogicException: skipped by Slack and not wanted by PagerDuty.
        $this->assertSame(['hooks.slack.com', 'events.pagerduty.com'], $sent);
    }

    public function test_reports_are_queued_when_enabled(): void
    {
        Queue::fake();
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/x'], 'queue' => ['enabled' => true, 'queue' => 'alerts']]);

        ErrorNotifier::report(new RuntimeException('queued'));

        Http::assertNothingSent();
        Queue::assertPushedOn('alerts', SendErrorReport::class, function (SendErrorReport $job) {
            // Must survive serialization even when the exception trace holds closures.
            $copy = unserialize(serialize($job));

            return $copy->report->message === 'queued' && $copy->report->exception === null;
        });
    }

    public function test_queued_job_delivers_the_report(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/x'], 'queue' => ['enabled' => true]]);
        config()->set('queue.default', 'sync');

        ErrorNotifier::report(new RuntimeException('via sync queue'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'via sync queue'));
    }

    public function test_it_sends_immediately_when_queueing_fails(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/x'], 'queue' => ['enabled' => true]]);
        $this->app->bind(Dispatcher::class, fn () => throw new RuntimeException('redis down'));

        ErrorNotifier::report(new RuntimeException('fallback'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'fallback'));
    }

    public function test_invalid_utf8_does_not_break_json_channels(): void
    {
        $this->useChannels(['slack' => ['webhook_url' => 'https://hooks.slack.com/x']]);

        ErrorNotifier::report(new RuntimeException("bad \xB1\x31 bytes"));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'bytes'));
    }

    public function test_command_tests_every_new_channel(): void
    {
        config()->set('mail.default', 'array');
        $this->useChannels([
            'slack' => ['webhook_url' => 'https://hooks.slack.com/x'],
            'discord' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a'],
            'teams' => ['webhook_url' => 'https://teams.example/hook'],
            'mail' => ['to' => 'dev@example.com'],
        ]);

        $this->artisan('error-notifier:test')
            ->expectsOutputToContain('slack: sent')
            ->expectsOutputToContain('discord: sent')
            ->expectsOutputToContain('teams: sent')
            ->expectsOutputToContain('mail: sent')
            ->assertSuccessful();
    }

    /**
     * Enable exactly the given channels (with their driver config) plus any
     * extra top-level options, and rebuild the notifier.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function useChannels(array $settings): void
    {
        $channels = [];

        foreach ($settings as $key => $value) {
            if (config()->has("error-notifier.drivers.{$key}")) {
                $channels[] = $key;
                config()->set("error-notifier.drivers.{$key}", array_merge(config("error-notifier.drivers.{$key}"), $value));
            } else {
                config()->set("error-notifier.{$key}", $value);
            }
        }

        config()->set('error-notifier.channels', $channels);

        $this->app->forgetInstance(Notifier::class);
        $this->app->forgetInstance(\Msahidurr\ErrorNotifier\ChannelManager::class);
        ErrorNotifier::clearResolvedInstances();
    }

    /**
     * @var array<int, string>
     */
    protected array $logged = [];

    protected function captureLogs(): void
    {
        Log::listen(function ($event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_SLASHES);
        });
    }
}
