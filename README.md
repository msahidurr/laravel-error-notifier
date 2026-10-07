# Laravel Error Notifier

Sends your Laravel application's exceptions to the places your team already watches: Telegram, Slack, Discord, Microsoft Teams, Google Chat, Mattermost, email, PagerDuty, or any webhook.

- Hooks into Laravel's exception handler automatically (no changes to `Handler.php` needed)
- Sends one report to several channels at once (`telegram,slack,mail`)
- Filters by environment, request path, and exception class, globally or per channel
- Throttles duplicate exceptions, and caps messages per minute for each channel
- Can send from a queue worker, so failing requests aren't slowed down
- Never breaks your app: a failing channel is logged and the other channels still receive the report
- Never leaks secrets: bot tokens, webhook URLs and routing keys are removed from logged errors
- Long errors are shortened safely to fit each channel's size limit
- Works with Laravel 9, 10, 11, and 12 on PHP 8.1+

## Installation

```bash
composer require msahidurr/laravel-error-notifier
```

### Local path install (before publishing to Packagist)

Add this to the application's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../laravel-error-notifier" }
]
```

```bash
composer require msahidurr/laravel-error-notifier:@dev
```

The service provider and the `ErrorNotifier` facade are auto-discovered.

## Quick start

```dotenv
ERROR_NOTIFIER_ENABLED=true
ERROR_NOTIFIER_CHANNELS=telegram        # comma-separated, e.g. telegram,slack,mail
ERROR_NOTIFIER_TITLE="APIs Error"

TELEGRAM_BOT_TOKEN=123456:ABC-your-bot-token
TELEGRAM_ERROR_CHAT_ID=-1001234567890
```

```bash
php artisan error-notifier:test
```

## Channels

Set the channel's env variables, then add its name to `ERROR_NOTIFIER_CHANNELS`.

| Channel | Name | Env variables | Default rate limit |
|---|---|---|---|
| Telegram | `telegram` | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ERROR_CHAT_ID`, optional `TELEGRAM_ERROR_THREAD_ID`, `TELEGRAM_API_URL` | 20/min |
| Slack | `slack` | `SLACK_ERROR_WEBHOOK_URL` | 50/min |
| Mattermost | `mattermost` | `MATTERMOST_ERROR_WEBHOOK_URL` | 50/min |
| Discord | `discord` | `DISCORD_ERROR_WEBHOOK_URL`, optional `DISCORD_ERROR_USERNAME` | 25/min |
| Google Chat | `google_chat` | `GOOGLE_CHAT_ERROR_WEBHOOK_URL`, optional `GOOGLE_CHAT_ERROR_THREAD_KEY` | 50/min |
| Microsoft Teams | `teams` | `TEAMS_ERROR_WEBHOOK_URL` (a **Workflows** URL, see below) | 30/min |
| Email | `mail` | `ERROR_NOTIFIER_MAIL_TO` (comma-separated), optional `ERROR_NOTIFIER_MAIL_FROM`, `ERROR_NOTIFIER_MAILER` | 10/min |
| Webhook | `webhook` | `ERROR_NOTIFIER_WEBHOOK_URL`, optional `ERROR_NOTIFIER_WEBHOOK_SECRET` | 60/min |
| PagerDuty | `pagerduty` | `PAGERDUTY_ROUTING_KEY`, optional `PAGERDUTY_SEVERITY` (`critical`, `error`, `warning`, `info`) | 60/min |

Each default rate limit sits just under the limit that service enforces. When a channel reaches its limit, extra reports for that minute are dropped and a warning is logged.

### Channel setup notes

- **Telegram:** create a bot with [@BotFather](https://t.me/BotFather) and add it to your group. Send a message in the group, then open `https://api.telegram.org/bot<TOKEN>/getUpdates` and copy `chat.id` (group IDs start with `-`).
- **Slack:** create an app with an [incoming webhook](https://api.slack.com/messaging/webhooks) for the channel.
- **Mattermost:** Integrations → Incoming Webhooks → Add.
- **Discord:** Channel settings → Integrations → Webhooks → New Webhook → Copy URL. Mentions such as `@everyone` in exception messages never ping anyone.
- **Google Chat:** Space → Apps & integrations → Webhooks. Set `GOOGLE_CHAT_ERROR_THREAD_KEY=errors` to keep all reports in one thread.
- **Microsoft Teams:** in the channel, open **Workflows** and use the template "Post to a channel when a webhook request is received". Copy its URL. The old Office 365 connector (Incoming Webhook) URLs were switched off in May 2026 and won't work. Messages appear as "Flow bot".
- **Email:** uses your app's mail setup (`MAIL_*`). Turning on the [queue](#queue) is recommended, because sending mail can be slow.
- **Webhook:** POSTs the report as JSON (`title`, `app`, `environment`, `exception`, `message`, `file`, `line`, `context`, `trace`, `time`, `fingerprint`). With a secret set, the raw body is signed: `X-Error-Notifier-Signature: sha256=<hmac>`. Check it on the receiving side with `hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header)`. Extra headers can be set in `drivers.webhook.headers`.
- **PagerDuty:** create an "Events API v2" integration on a service and copy its routing key. Repeats of the same error update one incident instead of opening new ones.

## Per-channel filters

Every driver accepts `only_exceptions`, `except_exceptions` and `rate_limit` in the published config (subclasses count). For example, send everything to Slack but page only for database errors:

```php
'drivers' => [
    'slack' => [
        'webhook_url' => env('SLACK_ERROR_WEBHOOK_URL'),
        'rate_limit' => 50,
        'except_exceptions' => [\App\Exceptions\NoisyException::class],
    ],
    'pagerduty' => [
        'routing_key' => env('PAGERDUTY_ROUTING_KEY'),
        'only_exceptions' => [\Illuminate\Database\QueryException::class],
    ],
],
```

## Queue

By default, reports are sent while Laravel handles the failing request. With several channels or email, that can add seconds to the request. To send from a queue worker instead:

```dotenv
ERROR_NOTIFIER_QUEUE=true
ERROR_NOTIFIER_QUEUE_CONNECTION=redis   # optional, defaults to QUEUE_CONNECTION
ERROR_NOTIFIER_QUEUE_NAME=alerts        # optional
```

Make sure a worker is running (`php artisan queue:work`). If a report can't be queued (for example, the queue backend is the thing that's failing), it is sent immediately instead. Queued jobs are not retried, so a failing channel doesn't send duplicate alerts. Reports are queued even when your queue connection uses `after_commit` and the error rolls back a database transaction. Otherwise Laravel would discard the job along with the transaction.

## Configuration

### Publishing the config file

The `.env` variables above are enough for most apps. Publish the config file when you need options that have no env variable, such as `excluded_paths`, `excluded_exceptions`, per-channel filters or `trace_lines`.

```bash
php artisan vendor:publish --tag=error-notifier-config
```

This copies the package's default config to `config/error-notifier.php` in your app. Laravel uses your copy from then on. You can also publish by provider name:

```bash
php artisan vendor:publish --provider="Msahidurr\ErrorNotifier\ErrorNotifierServiceProvider"
```

Then edit `config/error-notifier.php`, for example:

```php
'excluded_paths' => [
    'api/v2/otp/generate',
    'health',
],

'excluded_exceptions' => [
    \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    \App\Exceptions\PaymentDeclinedException::class,
],

'trace_lines' => 10,
```

If your app caches its config (usual in production), rebuild the cache after any change:

```bash
php artisan config:cache
```

**Updating the package:** a published file isn't updated when the package is. New top-level options still work with their default values. New channels or settings inside `drivers` won't apply until you add them to your copy, because your `drivers` section replaces the package's. To compare your file with the latest defaults, re-publish with `--force` (this overwrites your file, so commit it first):

```bash
php artisan vendor:publish --tag=error-notifier-config --force
```

If you only change values that have env variables, you don't need to publish the file at all.

### Options

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Master switch (`ERROR_NOTIFIER_ENABLED`) |
| `channels` | `['telegram']` | Channels every report is sent to (`ERROR_NOTIFIER_CHANNELS`) |
| `auto_report` | `true` | Register with Laravel's exception handler automatically |
| `title` | `Application Error` | Message title (`ERROR_NOTIFIER_TITLE`) |
| `ignored_environments` | `['local', 'testing']` | Environments that never report |
| `excluded_paths` | `[]` | `Request::is()` patterns; each also matches its sub-paths |
| `excluded_exceptions` | 404, auth, validation | Exception classes (and their subclasses) to skip |
| `include_user` / `user_name_attribute` | `true` / `name` | Show the authenticated user's ID and name |
| `include_ip` | `true` | Show the client IP |
| `trace_lines` | `0` | Number of stack trace lines to include |
| `throttle_seconds` | `60` | Skip identical exceptions within this window (uses the default cache store) |
| `queue.enabled` / `connection` / `queue` | `false` / default / default | Send reports from a queue worker |
| `drivers.<name>` | | Settings for each channel, plus `rate_limit`, `only_exceptions`, `except_exceptions` |

## Verify the setup

```bash
php artisan error-notifier:test                    # all configured channels
php artisan error-notifier:test --channel=slack    # one channel
```

This sends a test report even when reporting is disabled or the environment is ignored, so you can check credentials anywhere. It bypasses rate limits, filters and the queue.

## Manual usage

```php
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;

try {
    // ...
} catch (\Throwable $e) {
    ErrorNotifier::report($e);   // applies all filters, sends to all channels
}
```

If you set `auto_report` to `false`, register it yourself in `app/Exceptions/Handler.php`:

```php
$this->reportable(function (Throwable $e) {
    app(\Msahidurr\ErrorNotifier\ErrorNotifier::class)->report($e);
});
```

## Adding your own channel

A channel implements one method. It receives an `ErrorReport` with the title, app name, environment, exception class, message, file, line, context (Request / User / IP / Command), trace, time and fingerprint. The channel formats that report and sends it. Throw on failure; the notifier logs the error and moves on to the next channel.

For HTTP services, extend `HttpChannel`. It handles timeouts and removes secrets from error messages:

```php
use Msahidurr\ErrorNotifier\Channels\HttpChannel;
use Msahidurr\ErrorNotifier\ErrorReport;

class NtfyChannel extends HttpChannel
{
    public function send(ErrorReport $report): void
    {
        $this->postJson('https://ntfy.sh', [
            'topic' => $this->required('topic'),
            'title' => "{$report->title} ({$report->environment})",
            'message' => "{$report->exceptionClass}: {$report->message}\n{$report->file}:{$report->line}",
            'priority' => 4,
        ], [$this->config['topic']]);
    }
}
```

Register it in a service provider's `boot()` method. The second argument is `config('error-notifier.drivers.ntfy')`:

```php
ErrorNotifier::extend('ntfy', fn ($app, array $config) => new NtfyChannel($config));
```

Then add its settings under `drivers.ntfy` in the published config and add `ntfy` to `ERROR_NOTIFIER_CHANNELS`. Per-channel filters and rate limits work for custom channels too.

Built-in channels live in `src/Channels` and are wired up in `ChannelManager::create{Name}Driver()`.

## Testing

```bash
composer install
composer test
```

## License

MIT
