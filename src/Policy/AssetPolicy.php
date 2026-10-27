<?php

declare(strict_types=1);

namespace Charlotte\Policy;

/**
 * Host-owned images only; a URL the author supplies is never an asset.
 *
 * Extracted from the flabs Sites lab (app/Lab/Sites/SiteImagePolicy.php). The
 * lab's per-lane asset sets became the {@see AssetCatalogue} contract; the
 * rules are unchanged. Runs after every write and before every read.
 */
final class AssetPolicy
{
    public const HANDLE = '/\A[a-z][a-z0-9-]{0,63}\z/D';

    private const MAX_IMAGES = 100;

    public static function assert(array $doc, AssetCatalogue $assets, ?string $revision = null): void
    {
        self::css($doc['theme'] ?? [], $revision);
        $checked = [];
        $count = 0;
        foreach ($doc['nodes'] ?? [] as $node) {
            foreach (['style', 'constraints', 'animations'] as $field) {
                self::css($node[$field] ?? [], $revision);
            }
            if (($node['type'] ?? null) !== 'image') {
                continue;
            }
            if (++$count > self::MAX_IMAGES) {
                throw new Refused('image_source_refused', $revision);
            }
            $props = is_array($node['props'] ?? null) ? $node['props'] : (array) ($node['props'] ?? []);
            $src = $props['src'] ?? '';
            if ($src !== '' || array_intersect(['srcSet', 'srcset', 'sizes', 'width', 'height'], array_keys($props)) !== []) {
                throw new Refused('image_source_refused', $revision);
            }
            // An empty editor placeholder has no src attribute and cannot fetch.
            if (! array_key_exists('asset', $props)) {
                continue;
            }
            $handle = $props['asset'];
            if (! is_string($handle) || preg_match(self::HANDLE, $handle) !== 1) {
                throw new Refused('image_source_refused', $revision);
            }
            if (isset($checked[$handle])) {
                continue;
            }
            if (! $assets->has($handle)) {
                throw new Refused('image_source_refused', $revision);
            }
            $checked[$handle] = true;
        }
    }

    private static function css(mixed $value, ?string $revision): void
    {
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $item) {
                self::css($item, $revision);
            }

            return;
        }
        if (! is_string($value)) {
            return;
        }
        // Declarative tokens, gradients and layout, not CSS URL fetches.
        // Escapes and comments are refused too, rather than inventing a second
        // CSS parser whose canonicalization could disagree with the browser's.
        if (str_contains($value, '\\') || str_contains($value, '/*')
            || preg_match('/(?:\b(?:url|image|image-set)|-webkit-image-set)\s*\(|@import\b/i', $value) === 1) {
            throw new Refused('image_source_refused', $revision);
        }
    }
}
