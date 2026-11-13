<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use Charlotte\Policy\AssetCatalogue;
use Charlotte\Policy\AssetPolicy;
use Charlotte\Policy\Refused;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\Render\EchartOption;

/**
 * Island nodes against the host's catalogue, after every write and for every
 * actor, like {@see AssetPolicy}:
 *
 * - a host island (`namespace:Name`) must be in the catalogue and carry
 *   `island: true`; a fancy-cms built-in (`kpi`, `input` …) may carry it or
 *   not (it is implied); `island: true` on any other type is refused, and an
 *   unknown type is refused by fancy-cms's own node-type check;
 * - its props must satisfy the type's schema;
 * - its props may hold no image URL (`src`, `srcSet`, `poster`, `image`,
 *   `url` …): images are host asset handles (`asset`), checked against the
 *   AssetCatalogue exactly as an image node's are.
 *
 * Only nodes this write created or changed are judged, so a draft holding an
 * island whose type has since left the catalogue stays editable; adding or
 * changing such an island is what is refused.
 */
final class IslandPolicy
{
    private const IMAGE_KEYS = ['src', 'srcset', 'sizes', 'poster', 'image', 'url', 'backgroundimage'];

    public const HINT = 'Place islands as {type, island:true, props} with props matching that type\'s schema from cms_island_schema. Use asset handles from cms_assets for images.';

    public static function assert(array $before, array $after, IslandCatalogue $catalogue, AssetCatalogue $assets, ?string $revision = null): void
    {
        $known = array_flip($catalogue->types());
        $old = is_array($before['nodes'] ?? null) ? $before['nodes'] : (array) ($before['nodes'] ?? []);
        foreach ((array) ($after['nodes'] ?? []) as $id => $node) {
            $node = PropsSchema::plain($node);
            if (! is_array($node)) {
                continue;
            }
            $id = (string) $id;
            $previous = isset($old[$id]) ? PropsSchema::plain($old[$id]) : null;
            if ($previous !== null && self::same($previous, $node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            $hostType = preg_match(SchemaIslandCatalogue::TYPE, $type) === 1;
            // A fancy-cms built-in (kpi, chart, input …): a bare name the catalogue lists; island:true is implied.
            $builtin = ! $hostType && isset($known[$type]) && $catalogue->builtin($type);
            $flag = ($node['island'] ?? false) === true;
            $base = '$.nodes.'.$id;

            if (! $hostType && ! $builtin && ! $flag) {
                continue; // a native node
            }
            if (! $hostType && ! $builtin) {
                self::refuse([['path' => $base.'.island', 'expected' => 'island:true only on an island type from cms_describe islands', 'got' => "true on {$type}"]], $revision);
            }
            if ($hostType && ! isset($known[$type])) {
                self::refuse([['path' => $base.'.type', 'expected' => 'an island type from cms_describe islands', 'got' => 'unknown island type']], $revision);
            }
            if ($hostType && ! $flag) {
                self::refuse([['path' => $base.'.island', 'expected' => 'true on every host island type (namespace:Name)', 'got' => 'missing or false']], $revision);
            }
            $props = $node['props'] ?? [];
            self::images($props, $assets, $revision);
            $errors = $catalogue->validate($type, $props);
            if ($errors === [] && $type === 'echart') {
                $errors = self::echart($props);
            }
            if ($errors !== []) {
                self::refuse(array_map(fn (array $e) => ['path' => $base.'.props'.substr($e['path'], 1)] + $e, $errors), $revision);
            }
        }
    }

    /**
     * The `echart` node's option against fancy-cms's own safe-subset validator
     * (`EchartOption`, shared with fancy-cms-ui through one fixture), never a
     * copy of it: no functions, markup, links, images or unlisted keys. An
     * option bound as a whole is checked by fancy-cms once it resolves. An
     * install without the validator has no `echart` node type either, so
     * fancy-cms refuses the node before this matters.
     *
     * @return list<array{path: string, expected: string, got: string}>
     */
    private static function echart(mixed $props): array
    {
        $option = is_array($props) ? ($props['option'] ?? null) : null;
        if (! class_exists(EchartOption::class) || $option === null
            || (is_array($option) && is_string($option['$bind'] ?? null) && count($option) === 1)) {
            return [];
        }

        return array_map(fn (string $at): array => ['path' => '$.option'.substr($at, 1),
            'expected' => 'an ECharts option in the safe subset: listed keys and series types only; no functions, markup, links, images, rich text or HTML tooltips',
            'got' => 'a value outside it'], EchartOption::errors($option));
    }

    private static function images(mixed $value, AssetCatalogue $assets, ?string $revision): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (in_array($name, self::IMAGE_KEYS, true) && is_string($item) && $item !== '') {
                throw new Refused('image_source_refused', $revision);
            }
            if ($name === 'asset' && (! is_string($item) || preg_match(AssetPolicy::HANDLE, $item) !== 1 || ! $assets->has($item))) {
                throw new Refused('image_source_refused', $revision);
            }
            self::images($item, $assets, $revision);
        }
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    /** @return never */
    private static function refuse(array $errors, ?string $revision): void
    {
        $errors = array_map(fn (array $e) => ['index' => null] + $e + ['hint' => self::HINT], $errors);

        throw new PageSessionException('island_invalid', $errors, $revision);
    }
}
