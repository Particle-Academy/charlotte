<?php

declare(strict_types=1);

namespace Charlotte\Brief;

use InvalidArgumentException;

/** A brief that cannot be trusted as the page's source of truth. Names the path. */
final class InvalidBrief extends InvalidArgumentException
{
    public function __construct(public readonly string $path, public readonly string $expected)
    {
        parent::__construct("Invalid brief at {$path}: expected {$expected}.");
    }
}
