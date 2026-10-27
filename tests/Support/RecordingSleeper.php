<?php

declare(strict_types=1);

namespace Charlotte\Tests\Support;

use Charlotte\Transport\Sleeper;
use Closure;

/** Records how long a long-poll waited, and can inject events mid-wait. */
final class RecordingSleeper implements Sleeper
{
    public int $slept = 0;

    public int $calls = 0;

    /** @param (Closure(int): void)|null $onSleep called with the call number */
    public function __construct(private ?Closure $onSleep = null) {}

    public function sleep(int $milliseconds): void
    {
        $this->slept += $milliseconds;
        $this->calls++;
        if ($this->onSleep !== null) {
            ($this->onSleep)($this->calls);
        }
    }
}
