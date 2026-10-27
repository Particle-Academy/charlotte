<?php

declare(strict_types=1);

namespace Charlotte\Policy;

/**
 * The host's images, by handle. The agent only ever sees handles, alt text
 * and measured dimensions; URLs, variants and paths stay with the host and
 * reach the page through fancy-cms's image resolver at render time.
 */
interface AssetCatalogue
{
    public function has(string $handle): bool;

    /** @return list<array{handle: string, alt: string, width: int, height: int}> */
    public function catalogue(): array;
}
