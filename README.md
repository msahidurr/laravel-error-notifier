# Laravel Error Notifier

Sends your Laravel application's exceptions to chat channels. Telegram is built in, and you can add Slack, Discord, email or any other channel through a simple driver interface.

- Hooks into Laravel's exception handler automatically (no changes to `Handler.php` needed)
- Sends one report to several channels at once (`telegram,slack,...`)
- Filters by environment, request path, and exception class
- Throttles duplicate exceptions to avoid flooding your channels
- Never breaks your app: a failing channel is logged and the other channels still receive the report
- Never leaks secrets: the bot token is removed from logged errors
- Long errors are shortened safely to fit Telegram's 4096-character limit
- File paths are shown relative to the project root
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

## Configuration

```dotenv
ERROR_NOTIFIER_ENABLED=true
ERROR_NOTIFIER_CHANNELS=telegram        # comma-separated, e.g. telegram,slack
ERROR_NOTIFIER_TITLE="APIs Error"
ERROR_NOTIFIER_THROTTLE=60              # seconds; 0 disables throttling
ERROR_NOTIFIER_AUTO_REPORT=true

# Telegram driver
TELEGRAM_BOT_TOKEN=123456:ABC-your-bot-token
TELEGRAM_ERROR_CHAT_ID=-1001234567890
TELEGRAM_ERROR_THREAD_ID=               # optional forum topic ID
TELEGRAM_API_URL=https://api.telegram.org   # optional, for a self-hosted Bot API server
```

To publish the config file:

```bash
php artisan vendor:publish --tag=error-notifier-config
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Master switch |
| `channels` | `['telegram']` | Drivers that every report is sent to |
| `auto_report` | `true` | Register with Laravel's exception handler automatically |
| `ignored_environments` | `['local', 'testing']` | Environments that never report |
| `excluded_paths` | `[]` | `Request::is()` patterns; each also matches its sub-paths |
| `excluded_exceptions` | 404, auth, validation | Exception classes (and their subclasses) to skip |
| `include_user` / `user_name_attribute` | `true` / `name` | Show the authenticated user's ID and name |
| `include_ip` | `true` | Show the client IP |
| `trace_lines` | `0` | Number of stack trace lines to include |
| `throttle_seconds` | `60` | Skip identical exceptions within this window (uses the default cache store) |
| `drivers.<name>` | | Settings for each channel driver |

## Verify the setup

```bash
php artisan error-notifier:test                     # all configured channels
php artisan error-notifier:test --channel=telegram  # one channel
```

This sends a test report even when reporting is disabled or the environment is ignored, so you can check credentials anywhere.

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

## Adding a new channel

A channel implements one method. It receives an `ErrorReport` that holds the title, app name, environment, exception class, message, file, line, context (Request / User / IP / Command), trace and time. The channel formats that report and sends it.

```php
use Illuminate\Support\Facades\Http;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Msahidurr\ErrorNotifier\ErrorReport;

class SlackChannel implements Channel
{
    public function __construct(protected array $config) {}

    public function send(ErrorReport $report): void
    {
        Http::post($this->config['webhook_url'], [
            'text' => "*{$report->title}* ({$report->environment})\n"
                ."`{$report->exceptionClass}`: {$report->message}\n"
                ."{$report->file}:{$report->line}",
        ])->throw();   // throw on failure; the notifier logs it
    }
}
```

Register the channel in a service provider's `boot()` method. The second argument is `config('error-notifier.drivers.slack')`:

```php
ErrorNotifier::extend('slack', fn ($app, array $config) => new SlackChannel($config));
```

Then add the channel to the config:

```php
// config/error-notifier.php
'drivers' => [
    'telegram' => [/* ... */],
    'slack' => ['webhook_url' => env('SLACK_ERROR_WEBHOOK_URL')],
],
```

```dotenv
ERROR_NOTIFIER_CHANNELS=telegram,slack
```

Built-in channels live in `src/Channels` and are wired up in `ChannelManager::create{Name}Driver()`.

## Telegram: getting a chat ID

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy the token.
2. Add the bot to your group or channel and send a message there.
3. Open `https://api.telegram.org/bot<TOKEN>/getUpdates` and copy `chat.id` (group IDs start with `-`).

## Testing

```bash
composer install
composer test
```

## License

MIT
