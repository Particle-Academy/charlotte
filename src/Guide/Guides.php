<?php

declare(strict_types=1);

namespace Charlotte\Guide;

use Closure;
use InvalidArgumentException;

/**
 * The base guide plus the host's overrides, applied in registration order.
 *
 *     Charlotte::guide(fn (GuideVersion $base) => $base->append($houseStyle, 'house-style'));
 */
final class Guides
{
    /** @var list<Closure(GuideVersion): GuideVersion> */
    private array $overrides = [];

    public function __construct(private readonly GuideSource $source) {}

    /** @param Closure(GuideVersion): GuideVersion $override */
    public function override(Closure $override): void
    {
        $this->overrides[] = $override;
    }

    public function current(): GuideVersion
    {
        $guide = $this->source->current();
        foreach ($this->overrides as $override) {
            $guide = $override($guide);
            if (! $guide instanceof GuideVersion) {
                throw new InvalidArgumentException('A guide override must return a GuideVersion.');
            }
        }

        return $guide;
    }
}
