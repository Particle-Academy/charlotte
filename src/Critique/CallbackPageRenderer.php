<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use Charlotte\Sessions\SessionRecord;
use Closure;

/** `Charlotte::screenshotsUsing(fn (SessionRecord $s, string $page, string $revision, array $widths): array => ...)`. */
final class CallbackPageRenderer implements PageRenderer
{
    /** @param Closure(SessionRecord, string, string, list<int>): list<Screenshot> $callback */
    public function __construct(private readonly Closure $callback) {}

    public function render(SessionRecord $session, string $page, string $revision, array $widths): array
    {
        $shots = ($this->callback)($session, $page, $revision, $widths);

        return is_array($shots) ? array_values($shots) : [];
    }
}
