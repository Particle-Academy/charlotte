<?php

declare(strict_types=1);

namespace Charlotte\Design;

/**
 * The rotating worked design examples: a pool of contrasting brands, each a
 * few real fancy-cms ops on the {@see Starter} skeleton, of which every
 * session sees TWO, sampled from its id.
 *
 * Until 0.5 the guide carried the same two examples for every session, and
 * paid flabs runs showed builders copying their motifs (stamp badges, tile
 * panels, ids like `hero-media-panel`) onto unrelated briefs. So the examples
 * left the static guide: the guide hash and the cached system prompt are the
 * same for every session, and a session's two arrive in its conversation
 * (TurnRunner prefixes a page session's first run; PageSubagents opens every
 * fresh page sub-agent conversation with them). Never a tool result: tool
 * results are framed as data (Prism maintainers).
 *
 * The public shape, read by host labs to measure motif reuse:
 *
 *     Examples::pool(): list<array{
 *         id: string,              // stable slug, e.g. "warm-local-shop"
 *         label: string,           // "Warm local shop"
 *         description: string,     // one line
 *         ops: list<array>,        // fancy-cms ops: set_theme first, applied after Starter::skeleton()
 *         motifs: list<Motif>,     // its DISTINCTIVE nodes
 *     }>
 *
 *     Motif = array{
 *         id: string,              // the node id the example inserts
 *         type: string,            // fancy-cms node type
 *         layout: ?string,         // the node's layout, or null
 *         signature: array<string, int|float|string>,  // subset of shapeOf($node)['signature']
 *     }
 *
 *     Examples::motifs(): list<array{example: string} + Motif>
 *
 * A node REUSES a motif when its id equals the motif id (`by: "id"`), or when
 * its type and layout equal the motif's and every signature key equals the
 * node's normalised base style (`by: "shape"`; see {@see self::shapeOf()}).
 * {@see self::reuse()} applies exactly that rule to a document.
 */
final class Examples
{
    /** How many examples a session sees. */
    public const PER_SESSION = 2;

    /** The base-style keys a signature may hold, normalised by {@see self::shapeOf()}. */
    public const SIGNATURE_KEYS = ['radius', 'columns', 'border', 'minHeight', 'maxWidth', 'direction', 'justify', 'background', 'transform', 'aspectRatio'];

    /** Between the examples and the person's message in a page session's first run. */
    public const SEPARATOR = "\n---\n\n";

    /**
     * @return list<array{id: string, label: string, description: string, ops: list<array<string, mixed>>,
     *     motifs: list<array{id: string, type: string, layout: ?string, signature: array<string, int|float|string>}>}>
     */
    public static function pool(): array
    {
        $px = fn (int|float $v): array => ['value' => $v, 'unit' => 'px'];
        $theme = fn (array $color): array => ['t' => 'set_theme', 'patch' => ['color' => $color]];
        $title = fn (array $patch): array => ['t' => 'set_style', 'id' => 'title', 'breakpoint' => 'base', 'patch' => $patch];
        $node = fn (string $id, string $type, string $parent, string $order, ?string $layout, array $props, array $base): array => ['t' => 'insert_node',
            'node' => ['id' => $id, 'type' => $type] + ($layout === null ? [] : ['layout' => $layout]) + ['parent' => $parent, 'order' => $order, 'props' => $props, 'style' => ['base' => $base]]];

        return [
            [
                'id' => 'warm-local-shop',
                'label' => 'Warm local shop',
                'description' => 'A serif face; a round stamp holds an icon and a brief line.',
                'ops' => [
                    $theme(['primary' => '#9a3412', 'tint' => '#fdf1e7', 'accent' => '#4d7c0f', 'ink' => '#292524', 'muted' => '#57534e', 'line' => '#ead7c3']),
                    $title(['fontFamily' => 'Georgia, Cambria, serif', 'color' => 'var(--cms-color-primary)']),
                    $node('hero-stamp', 'shape', 'hero-media', 'a0', 'stack', [],
                        ['background' => 'var(--cms-color-primary)', 'color' => '#ffffff', 'radius' => $px(999), 'padding' => $px(40), 'align' => 'center']),
                ],
                'motifs' => [['id' => 'hero-stamp', 'type' => 'shape', 'layout' => 'stack', 'signature' => ['radius' => '999px']]],
            ],
            [
                'id' => 'clean-b2b-product',
                'label' => 'Clean B2B product',
                'description' => 'A tight sans; a panel of 2 or 4 fact tiles.',
                'ops' => [
                    $theme(['primary' => '#1d4ed8', 'tint' => '#eef4ff', 'accent' => '#0f766e', 'ink' => '#0f172a', 'muted' => '#475569', 'line' => '#e2e8f0']),
                    $title(['fontFamily' => 'system-ui, sans-serif', 'letterSpacing' => $px(-1)]),
                    $node('hero-panel', 'frame', 'hero-media', 'a0', 'grid', [],
                        ['border' => '1px solid var(--cms-color-line)', 'radius' => $px(16), 'padding' => $px(24), 'columns' => 2, 'gap' => $px(16)]),
                ],
                'motifs' => [['id' => 'hero-panel', 'type' => 'frame', 'layout' => 'grid', 'signature' => ['columns' => 2, 'border' => '1px solid', 'radius' => '16px']]],
            ],
            [
                'id' => 'editorial-practice',
                'label' => 'Editorial practice',
                'description' => 'A light book serif; a short heavy rule above the h1.',
                'ops' => [
                    $theme(['primary' => '#7f1d1d', 'tint' => '#f7f5f0', 'accent' => '#b45309', 'ink' => '#1c1917', 'muted' => '#57534e', 'line' => '#d6d3d1']),
                    $title(['fontFamily' => "Palatino, 'Book Antiqua', serif", 'fontWeight' => 400]),
                    $node('hero-rule', 'shape', 'hero-copy', 'Zz', null, ['decorative' => true],
                        ['background' => 'var(--cms-color-primary)', 'minHeight' => $px(6), 'maxWidth' => $px(64)]),
                ],
                'motifs' => [['id' => 'hero-rule', 'type' => 'shape', 'layout' => null, 'signature' => ['minHeight' => '6px', 'maxWidth' => '64px']]],
            ],
            [
                'id' => 'photo-led-studio',
                'label' => 'Photo-led studio',
                'description' => 'Tracked capitals; a dark caption bar under the photo.',
                'ops' => [
                    $theme(['primary' => '#0e7490', 'tint' => '#ecfeff', 'accent' => '#be123c', 'ink' => '#111827', 'muted' => '#4b5563', 'line' => '#d1d5db']),
                    $title(['fontFamily' => "'Avenir Next', sans-serif", 'textTransform' => 'uppercase', 'letterSpacing' => $px(2)]),
                    $node('hero-caption', 'stack', 'hero-media', 'a1', 'stack', [],
                        ['direction' => 'row', 'justify' => 'between', 'background' => 'var(--cms-color-ink)', 'color' => '#ffffff', 'padding' => $px(16)]),
                ],
                'motifs' => [['id' => 'hero-caption', 'type' => 'stack', 'layout' => 'stack', 'signature' => ['direction' => 'row', 'justify' => 'between', 'background' => 'var(--cms-color-ink)']]],
            ],
            [
                'id' => 'bold-geometric',
                'label' => 'Bold geometric',
                'description' => 'Black capitals; a band of three flat colours.',
                'ops' => [
                    $theme(['primary' => '#dc2626', 'tint' => '#fef9c3', 'accent' => '#2563eb', 'ink' => '#0a0a0a', 'muted' => '#404040', 'line' => '#e5e5e5']),
                    $title(['fontFamily' => "'Arial Black', sans-serif", 'fontWeight' => 900, 'textTransform' => 'uppercase']),
                    $node('hero-band', 'frame', 'hero-media', 'a0', null, ['decorative' => true],
                        ['background' => 'linear-gradient(90deg,var(--cms-color-primary) 33%,var(--cms-color-accent) 0 66%,var(--cms-color-ink) 0)', 'minHeight' => $px(160)]),
                ],
                'motifs' => [['id' => 'hero-band', 'type' => 'frame', 'layout' => null, 'signature' => ['background' => 'gradient', 'minHeight' => '160px']]],
            ],
            [
                'id' => 'soft-card-stack',
                'label' => 'Soft card stack',
                'description' => 'A humanist sans on lilac; a tilted white card with a fact.',
                'ops' => [
                    $theme(['primary' => '#6d28d9', 'tint' => '#f5f3ff', 'accent' => '#be185d', 'ink' => '#1e1b4b', 'muted' => '#4c4a63', 'line' => '#e4dcfb']),
                    $title(['fontFamily' => "'Trebuchet MS', sans-serif", 'fontWeight' => 600]),
                    $node('hero-card', 'card', 'hero-media', 'a0', 'stack', [],
                        ['background' => '#ffffff', 'radius' => $px(24), 'padding' => $px(28), 'transform' => 'rotate(-2deg)']),
                ],
                'motifs' => [['id' => 'hero-card', 'type' => 'card', 'layout' => 'stack', 'signature' => ['radius' => '24px', 'transform' => 'rotate(-2deg)']]],
            ],
        ];
    }

    /** @return list<array{example: string, id: string, type: string, layout: ?string, signature: array<string, int|float|string>}> */
    public static function motifs(): array
    {
        $out = [];
        foreach (self::pool() as $example) {
            foreach ($example['motifs'] as $motif) {
                $out[] = ['example' => $example['id']] + $motif;
            }
        }

        return $out;
    }

    /**
     * A session's examples: PER_SESSION distinct pool ids, ordered by
     * sha256(session id | example id). Deterministic, so a replay of a
     * session sees the same two; adding an example moves only the sessions
     * it now ranks first in.
     *
     * @return list<string>
     */
    public static function sample(string $sessionId, int $count = self::PER_SESSION): array
    {
        $ranked = [];
        foreach (self::pool() as $example) {
            $ranked[$example['id']] = hash('sha256', $sessionId.'|'.$example['id']);
        }
        asort($ranked, SORT_STRING);

        return array_slice(array_keys($ranked), 0, $count);
    }

    /**
     * The section a session's examples are delivered in, in the order given.
     * Ids the pool no longer holds are skipped; none at all is ''.
     *
     * @param  list<string>  $ids
     */
    public static function promptSection(array $ids): string
    {
        $pool = array_column(self::pool(), null, 'id');
        $blocks = [];
        foreach ($ids as $id) {
            if (! isset($pool[$id])) {
                continue;
            }
            $lines = array_map(self::opLine(...), $pool[$id]['ops']);
            $blocks[] = '**'.$pool[$id]['label'].':** '.$pool[$id]['description']."\n\n```json\n".implode("\n", $lines)."\n```";
        }
        if ($blocks === []) {
            return '';
        }

        return "## Session examples\n\n"
            ."From Charlotte, not the person. Never reuse their motifs, shapes or node ids.\n\n"
            .implode("\n\n", $blocks)."\n";
    }

    /**
     * One op as the model reads it: compact JSON, with empty `props` as the
     * object fancy-cms documents (a PHP [] would encode as a JSON list).
     *
     * @param  array<string, mixed>  $op
     */
    public static function opLine(array $op): string
    {
        if (($op['node']['props'] ?? null) === []) {
            $op['node']['props'] = new \stdClass;
        }

        return json_encode($op, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A page session's first prompt: the examples, then the person's message.
     *
     * @param  list<string>  $ids
     */
    public static function delivered(array $ids, string $text): string
    {
        $section = self::promptSection($ids);

        return $section === '' ? $text : $section.self::SEPARATOR.$text;
    }

    /** The most bytes any pair of examples adds to a session's prompt (`delivered()` minus the message). */
    public static function maxPromptBytes(): int
    {
        $ids = array_column(self::pool(), 'id');
        $max = 0;
        foreach ($ids as $a) {
            foreach ($ids as $b) {
                if ($a !== $b) {
                    $max = max($max, strlen(self::delivered([$a, $b], '')));
                }
            }
        }

        return $max;
    }

    /**
     * A node's shape for motif matching: its type, its layout, and the
     * SIGNATURE_KEYS of its base style, normalised. Lengths become "<value><unit>"
     * ("999px"); a border keeps its width and style ("1px solid"); a background
     * holding a CSS gradient is "gradient"; anything else is kept as it is.
     *
     * @param  array<string, mixed>  $node
     * @return array{type: ?string, layout: ?string, signature: array<string, int|float|string>}
     */
    public static function shapeOf(array $node): array
    {
        $base = is_array($node['style']['base'] ?? null) ? $node['style']['base'] : [];
        $signature = [];
        foreach (self::SIGNATURE_KEYS as $key) {
            $value = $base[$key] ?? null;
            if (is_array($value) && isset($value['value'], $value['unit']) && is_numeric($value['value']) && is_string($value['unit'])) {
                $signature[$key] = $value['value'].$value['unit'];
            } elseif ($key === 'border' && is_string($value)) {
                $signature[$key] = implode(' ', array_slice(preg_split('/\s+/', trim($value)) ?: [], 0, 2));
            } elseif ($key === 'background' && is_string($value) && str_contains(strtolower($value), 'gradient(')) {
                $signature[$key] = 'gradient';
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                $signature[$key] = $value;
            }
        }

        return [
            'type' => is_string($node['type'] ?? null) ? $node['type'] : null,
            'layout' => is_string($node['layout'] ?? null) ? $node['layout'] : null,
            'signature' => $signature,
        ];
    }

    /**
     * Every node in `$doc['nodes']` that reuses a motif, by id first, else by
     * shape. `$examples` limits the check to those example ids (null: all).
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>|null  $examples
     * @return list<array{example: string, motif: string, node: string, by: 'id'|'shape'}>
     */
    public static function reuse(array $doc, ?array $examples = null): array
    {
        $motifs = array_values(array_filter(self::motifs(), fn (array $m): bool => $examples === null || in_array($m['example'], $examples, true)));
        $out = [];
        foreach (is_array($doc['nodes'] ?? null) ? $doc['nodes'] : [] as $id => $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? $id);
            $shape = self::shapeOf($node);
            foreach ($motifs as $motif) {
                $by = match (true) {
                    $id === $motif['id'] => 'id',
                    self::sameShape($shape, $motif) => 'shape',
                    default => null,
                };
                if ($by !== null) {
                    $out[] = ['example' => $motif['example'], 'motif' => $motif['id'], 'node' => $id, 'by' => $by];
                }
            }
        }

        return $out;
    }

    /** Type and layout equal, and every signature key present with the same value (strictly). */
    private static function sameShape(array $shape, array $motif): bool
    {
        if ($motif['signature'] === [] || $shape['type'] !== $motif['type'] || $shape['layout'] !== $motif['layout']) {
            return false;
        }
        foreach ($motif['signature'] as $key => $value) {
            if (! array_key_exists($key, $shape['signature']) || $shape['signature'][$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
