<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use Prism\Prism\ValueObjects\Media\Image;

/**
 * What {@see DesignLoop::critique()} hands the turn for its revision run: the
 * builder's message (the located items), the captures those items concern
 * (empty when `revision_includes_screenshot` is off), and the ready revision
 * that was critiqued, so the run's end can be recorded against it.
 */
final readonly class Revision
{
    /** @param list<Image> $images */
    public function __construct(
        public string $message,
        public array $images,
        public string $before,
    ) {}
}
