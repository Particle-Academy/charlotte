<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;

/**
 * Content truth, computed from the document and the structured brief.
 *
 * - `missing_required_fact` (error): a required fact's value does not appear
 *   in the page's visible copy. Code-derived and exact after normalization.
 * - `unsupported_claim` (warning): a high-risk claim shape (a price, a
 *   percentage, an "N+" count, a phone number, an email address, a year or a
 *   quotation) that traces to nothing in the brief. A warning rather than an
 *   error, because a pattern cannot prove a claim was invented; it can only
 *   show that the brief does not support it.
 *
 * No model and no network, so it is free, deterministic and always available.
 */
final class FactChecks
{
    /** Applied in order; each match is blanked before the next pattern runs. */
    private const CLAIMS = [
        'email' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        'currency' => '/(?:[$€£¥]\s?\d[\d,]*(?:\.\d+)?|\b\d[\d,]*(?:\.\d+)?\s?(?:usd|eur|gbp|dollars|euros|pounds)\b)/iu',
        'percentage' => '/\b\d+(?:\.\d+)?\s?%/',
        'phone' => '/\+?\(?\d[\d\s().-]{6,}\d/',
        'count' => '/\b\d[\d,.]*\s?[km]?\+|\b\d[\d,.]*[km]\b/i',
        'year' => '/\b(?:19|20)\d{2}\b/',
    ];

    private const QUOTE = '/["\x{201C}]([^"\x{201C}\x{201D}]{3,300})["\x{201D}]/u';

    /** @return list<array<string, mixed>> */
    public static function check(array $doc, Brief $brief, ?int $currentYear = null): array
    {
        $currentYear ??= (int) gmdate('Y');
        $copy = [...PageChecks::copy($doc), ...self::islandCopy($doc)];
        $visible = self::normalize(implode("\n", array_column($copy, 'text')));
        $evidence = self::evidence($brief);
        $defects = [];

        foreach ($brief->required() as $fact) {
            if (! self::contains($visible, self::normalize($fact->value))) {
                $defects[] = ['code' => 'missing_required_fact', 'severity' => 'error', 'fact' => $fact->id,
                    'message' => "The page does not carry the required fact \"{$fact->id}\"."];
            }
        }

        $sources = array_map(fn (array $c) => ['node' => $c['node'], 'text' => $c['text']], $copy);
        $meta = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        if (is_string($meta['title'] ?? null)) {
            $sources[] = ['field' => 'meta.title', 'text' => $meta['title']];
        }
        foreach (is_array($meta['seo'] ?? null) ? $meta['seo'] : [] as $key => $value) {
            if (is_string($value)) {
                $sources[] = ['field' => 'meta.seo.'.$key, 'text' => $value];
            }
        }

        foreach (self::figures($doc) as [$node, $figure]) {
            if (self::supportedFigure($figure, $evidence)) {
                continue;
            }
            $defects[] = ['code' => 'unsupported_claim', 'severity' => 'warning', 'kind' => 'figure', 'claim' => $figure, 'node' => $node,
                'message' => "The page shows the figure {$figure}, which no brief fact supports. Bind figures to host data instead of typing them."];
        }

        foreach ($sources as $source) {
            foreach (self::claims($source['text'], $currentYear) as [$kind, $claim]) {
                if (self::supported($kind, $claim, $evidence)) {
                    continue;
                }
                $where = isset($source['node']) ? ['node' => $source['node']] : ['field' => $source['field']];
                $defects[] = ['code' => 'unsupported_claim', 'severity' => 'warning', 'kind' => $kind, 'claim' => $claim, ...$where,
                    'message' => "The page states {$claim}, which no brief fact supports."];
            }
        }

        return $defects;
    }

    /**
     * Lowercase, one space, straight quotes removed, no thousands separators,
     * no zero cents, and no spaces around a slash.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            "\u{2018}" => '', "\u{2019}" => '', "\u{201C}" => '', "\u{201D}" => '', '"' => '', "'" => '',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
        ]);
        $text = preg_replace('/(?<=\d),(?=\d{3}(?!\d))/', '', $text) ?? $text;
        $text = preg_replace('/(?<=\d)\.00(?!\d)/', '', $text) ?? $text;
        $text = preg_replace('/\s*\/\s*/', '/', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Island prop keys that hold identifiers or targets, not visible copy. */
    private const ISLAND_NON_COPY = ['href', 'asset', 'value', 'id', 'type', 'variant', 'as', 'charttype', 'language', 'form', 'src', 'name', 'key', 'icon', 'defaulttab', 'method', 'action'];

    /**
     * Visible copy inside island props: every string leaf except identifier
     * and target keys. An island's tab body or chart caption is page copy,
     * so a required fact there counts and an invented one there is caught.
     *
     * @return list<array{node: string, text: string}>
     */
    private static function islandCopy(array $doc): array
    {
        $out = [];
        foreach ((array) ($doc['nodes'] ?? []) as $id => $node) {
            $node = json_decode(json_encode($node) ?: '[]', true);
            if (! is_array($node) || ($node['island'] ?? false) !== true) {
                continue;
            }
            $walk = function (mixed $value, string|int $key) use (&$walk, &$out, $id): void {
                if (is_array($value) && array_key_exists('$bind', $value)) {
                    return; // a data binding: evidence from the host, not copy (its $scope dates included)
                }
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $walk($v, is_int($k) ? $key : $k);
                    }
                } elseif (is_string($value) && trim($value) !== '' && ! in_array(strtolower((string) $key), self::ISLAND_NON_COPY, true)) {
                    $out[] = ['node' => (string) $id, 'text' => trim($value)];
                }
            };
            $walk($node['props'] ?? [], '');
        }

        return $out;
    }

    /**
     * Prop keys whose numbers are FIGURES the page shows (a KPI value, chart
     * data, table rows), as opposed to presentation (decimals, limit, level).
     * Every number below one of these is a figure, at any depth.
     */
    private const FIGURE_KEYS = ['value', 'delta', 'data', 'series', 'rows', 'categories', 'target', 'goal', 'amount', 'total', 'totals'];

    /**
     * Literal numbers in data props, by node. A value bound with `$bind` is
     * skipped whole: it comes from the host's data, so it is evidence rather
     * than a claim the agent made up. A literal the agent typed is not.
     *
     * @return list<array{0: string, 1: string}> [node, figure]
     */
    private static function figures(array $doc): array
    {
        $out = [];
        foreach ((array) ($doc['nodes'] ?? []) as $id => $node) {
            $node = json_decode(json_encode($node) ?: '[]', true);
            if (! is_array($node) || ! is_array($node['props'] ?? null)) {
                continue;
            }
            $walk = function (mixed $value, bool $figure) use (&$walk, &$out, $id): void {
                if (is_array($value)) {
                    if (array_key_exists('$bind', $value)) {
                        return;
                    }
                    foreach ($value as $k => $v) {
                        $walk($v, $figure || (is_string($k) && in_array(strtolower($k), self::FIGURE_KEYS, true)));
                    }
                } elseif ($figure && (is_int($value) || is_float($value))) {
                    $out[] = [(string) $id, self::number($value)];
                }
            };
            $walk($node['props'], false);
        }

        return $out;
    }

    private static function number(int|float $value): string
    {
        if (is_int($value) || floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    /** A figure is supported when the brief carries it, or carries it as a percentage of a ratio. */
    private static function supportedFigure(string $figure, array $evidence): bool
    {
        if (self::contains($evidence['text'], $figure)) {
            return true;
        }
        $ratio = (float) $figure;
        if (abs($ratio) <= 1 && $ratio !== 0.0) {
            return self::contains($evidence['text'], self::number($ratio * 100).'%');
        }

        return false;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function claims(string $text, int $currentYear): array
    {
        $found = [];
        if (preg_match_all(self::QUOTE, $text, $quotes) > 0) {
            foreach ($quotes[1] as $inner) {
                $found[] = ['quote', trim($inner)];
            }
        }
        foreach (self::CLAIMS as $kind => $pattern) {
            $text = preg_replace_callback($pattern, function (array $m) use ($kind, $currentYear, &$found): string {
                $claim = trim($m[0]);
                $keep = match ($kind) {
                    'phone' => strlen(preg_replace('/\D/', '', $claim) ?? '') >= 7,
                    'year' => (int) $claim !== $currentYear,
                    default => true,
                };
                if ($keep) {
                    $found[] = [$kind, $kind === 'currency' ? self::currency($claim) : $claim];
                }

                return str_repeat(' ', strlen($m[0]));
            }, $text) ?? $text;
        }

        return $found;
    }

    private static function currency(string $claim): string
    {
        $claim = preg_replace('/\s+|,/', '', $claim) ?? $claim;

        return preg_replace('/\.00$/', '', $claim) ?? $claim;
    }

    /** @param array{text: string, digits: list<string>} $evidence */
    private static function supported(string $kind, string $claim, array $evidence): bool
    {
        if ($kind === 'phone') {
            $digits = preg_replace('/\D/', '', $claim) ?? '';
            foreach ($evidence['digits'] as $candidate) {
                if ($candidate !== '' && str_contains($candidate, $digits)) {
                    return true;
                }
            }

            return false;
        }

        return self::contains($evidence['text'], self::normalize($claim));
    }

    /** Substring match that will not let "$1" pass on the strength of "$19". */
    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return preg_match('/(?<!\d)'.preg_quote($needle, '/').'(?!\d)/u', $haystack) === 1;
    }

    /** @return array{text: string, digits: list<string>} */
    private static function evidence(Brief $brief): array
    {
        $parts = [$brief->summary, $brief->audience ?? '', $brief->tone ?? '', $brief->cta['label'] ?? '', $brief->cta['href'] ?? ''];
        $digits = [];
        foreach ($brief->facts as $fact) {
            array_push($parts, $fact->value, $fact->label ?? '', $fact->attribution ?? '');
            $digits[] = preg_replace('/\D/', '', $fact->value) ?? '';
        }

        return ['text' => self::normalize(implode("\n", $parts)), 'digits' => $digits];
    }
}
