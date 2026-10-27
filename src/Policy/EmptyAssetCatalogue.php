<?php

declare(strict_types=1);

namespace Charlotte\Policy;

/** The default: no images. Any asset handle is refused until a host binds a catalogue. */
final class EmptyAssetCatalogue implements AssetCatalogue
{
    public function has(string $handle): bool
    {
        return false;
    }

    public function catalogue(): array
    {
        return [];
    }
}
