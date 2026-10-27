<?php

declare(strict_types=1);

namespace Charlotte\Transport;

/** How a long-poll waits between reads. Injected so a test can count, not sleep. */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
