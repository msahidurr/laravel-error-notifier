<?php

namespace Msahidurr\ErrorNotifier\Channels\Concerns;

trait FitsMessageLength
{
    /**
     * Raw (pre-escaping) character limits per field, tried in order until the
     * formatted message fits. Truncating raw values before escaping keeps the
     * markup valid; truncating the final message could cut a tag, entity or
     * code fence in half.
     *
     * @return array<int, array{message: int, trace: int, value: int}>
     */
    protected function budgets(): array
    {
        return [
            ['message' => 1000, 'trace' => 1500, 'value' => 500],
            ['message' => 600, 'trace' => 600, 'value' => 300],
            ['message' => 300, 'trace' => 0, 'value' => 150],
            ['message' => 100, 'trace' => 0, 'value' => 60],
        ];
    }

    /**
     * Format with progressively smaller budgets until the result fits.
     *
     * @param  callable(array{message: int, trace: int, value: int}): string  $format
     * @param  (callable(string): int)|null  $measure  Defaults to characters (mb_strlen).
     */
    protected function fit(callable $format, int $maxLength, ?callable $measure = null): string
    {
        $measure ??= 'mb_strlen';

        foreach ($this->budgets() as $budget) {
            $message = $format($budget);

            if ($measure($message) <= $maxLength) {
                return $message;
            }
        }

        return $message;
    }

    protected function limit(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, max($limit, 0)).'…' : $value;
    }
}
