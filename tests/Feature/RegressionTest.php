<?php

namespace Msahidurr\ErrorNotifier\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Msahidurr\ErrorNotifier\ErrorNotifier as Notifier;
use Msahidurr\ErrorNotifier\ErrorReport;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Msahidurr\ErrorNotifier\Jobs\SendErrorReport;
use Msahidurr\ErrorNotifier\Tests\TestCase;
use RuntimeException;

class RegressionTest extends TestCase
{
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true])]);
        Log::listen(function ($e) {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });
    }

    protected function use(array $settings): void
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

    protected function longReport(string $char): ErrorReport
    {
        $long = str_repeat($char, 5000);

        return new ErrorReport('T', $long, 'production', 'App\\'.str_repeat('X', 400), $long, $long, 1,
            ['Request' => 'GET https://x.test/'.$long, 'User' => $long, 'IP' => '1.1.1.1'], $long, now(), 'fp');
    }

    public function test_teams_payload_bytes_on_the_wire_stay_under_limit(): void
    {
        $this->use(['teams' => ['webhook_url' => 'https://t.example/hook']]);
        app(Notifier::class)->sendReport($this->longReport('ঢ'));
        Http::assertSent(function (Request $r) {
            return strlen($r->body()) <= 20000;
        });
    }

    public function test_discord_embed_total_stays_under_6000_characters(): void
    {
        $this->use(['discord' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a']]);
        app(Notifier::class)->sendReport($this->longReport('a'));
        Http::assertSent(function (Request $r) {
            $e = $r['embeds'][0];
            $total = mb_strlen($e['title']) + mb_strlen($e['description'])
                + array_sum(array_map(fn ($f) => mb_strlen($f['name']) + mb_strlen($f['value']), $e['fields']));
            return $total <= 6000;
        });
    }

    public function test_discord_never_sends_empty_field_values(): void
    {
        config()->set('app.name', '');
        $this->use(['discord' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a']]);
        ErrorNotifier::report(new RuntimeException('x'));
        Http::assertSent(fn (Request $r) => collect($r['embeds'][0]['fields'])->every(fn ($f) => trim($f['value'], '` ') !== ''));
    }

    public function test_pagerduty_respects_field_limits_and_omits_empty_dedup_key(): void
    {
        $this->use(['pagerduty' => ['routing_key' => 'k']]);
        $r = $this->longReport('a');
        $r = new ErrorReport($r->title, $r->appName, $r->environment, $r->exceptionClass, $r->message, $r->file, 1, [], null, now(), '');
        app(Notifier::class)->sendReport($r);
        Http::assertSent(function (Request $req) {
            $body = json_decode($req->body(), true);
            return mb_strlen($body['payload']['source']) <= 255
                && mb_strlen($body['payload']['component']) <= 255
                && mb_strlen($body['payload']['summary']) <= 1024
                && ! (array_key_exists('dedup_key', $body) && $body['dedup_key'] === null);
        });
    }

    public function test_harmless_webhook_headers_are_not_redacted_from_logs(): void
    {
        $this->use(['webhook' => ['url' => 'https://hooks.example.com/in', 'headers' => ['X-Team' => 'api']]]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.example.com'));
        ErrorNotifier::report(new RuntimeException('x'));
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('api.example.com', $log);
    }

    public function test_rate_limit_warning_is_logged_once_per_window(): void
    {
        $this->use(['slack' => ['webhook_url' => 'https://hooks.slack.com/x', 'rate_limit' => 1]]);
        foreach (range(1, 20) as $i) {
            ErrorNotifier::report(new RuntimeException("e$i"));
        }
        $warnings = count(array_filter($this->logged, fn ($l) => str_contains($l, 'rate limit')));
        $this->assertLessThanOrEqual(1, $warnings);
    }

    public function test_report_is_queued_even_when_the_transaction_rolls_back(): void
    {
        config()->set('database.connections.queue_db', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        \Illuminate\Support\Facades\Schema::connection('queue_db')->create('jobs', function ($t) {
            $t->bigIncrements('id'); $t->string('queue'); $t->longText('payload');
            $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
        });
        config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'queue_db', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => true]);
        config()->set('queue.default', 'database');
        $this->use(['slack' => ['webhook_url' => 'https://hooks.slack.com/x'], 'queue' => ['enabled' => true]]);

        try {
            DB::connection('testing')->transaction(function () {
                ErrorNotifier::report(new RuntimeException('inside transaction'));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $jobs = DB::connection('queue_db')->table('jobs')->count();
        $this->assertSame(1, $jobs);
    }
}
