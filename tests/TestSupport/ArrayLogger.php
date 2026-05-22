<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\TestSupport;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 collecting logger for HttpFetcherTest / CachingFetcherTest.
 *
 * Extends AbstractLogger so the 8 level methods (debug/info/notice/...)
 * all funnel through log(); tests inspect $records to assert the
 * logging contract (event names, levels, context keys).
 *
 * Final class — does not extend further than AbstractLogger.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
