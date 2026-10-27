<?php

declare(strict_types=1);

namespace Charlotte\Transport;

final class UsleepSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }
}
