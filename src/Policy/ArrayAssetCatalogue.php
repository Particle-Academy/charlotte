<?php

declare(strict_types=1);

namespace Charlotte\Policy;

use InvalidArgumentException;

/**
 * A catalogue from an array keyed by handle. Extra host fields (src, variants)
 * may be present for the host's own image resolver; they are never shown to
 * the agent.
 */
final class ArrayAssetCatalogue implements AssetCatalogue
{
    /** @param array<string, array{alt: string, width: int, height: int}> $assets */
    public function __construct(private readonly array $assets)
    {
        foreach ($assets as $handle => $asset) {
            if (! is_string($handle) || preg_match(AssetPolicy::HANDLE, $handle) !== 1
                || ! is_string($asset['alt'] ?? null) || ! is_int($asset['width'] ?? null) || ! is_int($asset['height'] ?? null)
                || $asset['width'] < 1 || $asset['height'] < 1) {
                throw new InvalidArgumentException('Each asset needs a lowercase handle, alt text and positive integer width and height.');
            }
        }
    }

    public function has(string $handle): bool
    {
        return isset($this->assets[$handle]);
    }

    public function catalogue(): array
    {
        $out = [];
        foreach ($this->assets as $handle => $asset) {
            $out[] = ['handle' => (string) $handle, 'alt' => $asset['alt'], 'width' => $asset['width'], 'height' => $asset['height']];
        }

        return $out;
    }
}
