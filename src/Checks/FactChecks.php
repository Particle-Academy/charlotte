<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;

/**
 * Content truth, computed from the document and the structured brief.
 *
 * - `missing_required_fact` (error): a required fact's value does not appear
 *   in the page's visible copy. Code-derived and exact after normalization.
 * - `unsupported_claim`: a high-risk claim shape (a price, a percentage, an
 *   "N+" count, a phone number, an email address, a date, a year, a typed
 *   figure or a quotation) that traces to nothing in the brief. An ERROR,
 *   and a cms_finish gate, for every kind but `quote`: a figure or date the
 *   brief never gave is what the lab's reviewer scored 0 (run 426 invented
 *   "From 1 February 2027"). A quotation stays a warning, because quote marks
 *   are also typography and the pattern cannot tell the two apart.
 *
 * Dates compare by meaning, not spelling: "18 March 2027", "March 18, 2027"
 * and "18th of March" are one date, and "31 Jan" is "31 January".
 *
 * No model and no network, so it is free, deterministic and always available.
 */
final class FactChecks
{
    private const MONTHS = 'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec';

    /** Applied in order; each match is blanked before the next pattern runs. */
    private const CLAIMS = [
        'email' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        'date' => '/\b\d{1,2}(?:st|nd|rd|th)?(?:\s+of)?\s+(?:'.self::MONTHS.')\b\.?(?:,?\s+(?:19|20)\d{2}\b)?/iu',
        'date_md' => '/\b(?:'.self::MONTHS.')\b\.?\s+\d{1,2}(?:st|nd|rd|th)?\b(?:,?\s+(?:19|20)\d{2}\b)?/iu',
        'date_my' => '/\b(?:'.self::MONTHS.')\b\.?,?\s+(?:19|20)\d{2}\b/iu',
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
            $defects[] = ['code' => 'unsupported_claim', 'severity' => 'error', 'kind' => 'figure', 'claim' => $figure, 'node' => $node,
                'message' => "The page shows the figure {$figure}, which no brief fact supports. Bind figures to host data instead of typing them."];
        }

        foreach ($sources as $source) {
            foreach (self::claims($source['text'], $currentYear) as [$kind, $claim]) {
                if (self::supported($kind, $claim, $evidence)) {
                    continue;
                }
                $where = isset($source['node']) ? ['node' => $source['node']] : ['field' => $source['field']];
                $defects[] = ['code' => 'unsupported_claim', 'severity' => $kind === 'quote' ? 'warning' : 'error', 'kind' => $kind, 'claim' => $claim, ...$where,
                    'message' => $kind === 'date'
                        ? "The page states the date {$claim}, which the brief never gives. Remove it, or use a date exactly as the brief states it."
                        : "The page states {$claim}, which no brief fact supports. Remove it, or use the brief's exact value."];
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
                    if (array_key_exists('$bind', $value) || self::isLength($value)) {
                        return; // a binding is host evidence; a {value, unit} length is a style the agent misplaced, not copy
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

    /** A fancy-cms Length: exactly {value: number, unit: px|%|vw|vh|rem|fr}. */
    private static function isLength(array $value): bool
    {
        return count($value) === 2 && (is_int($value['value'] ?? null) || is_float($value['value'] ?? null))
            && in_array($value['unit'] ?? null, ['px', '%', 'vw', 'vh', 'rem', 'fr'], true);
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
                    $found[] = [str_starts_with($kind, 'date') ? 'date' : $kind, $kind === 'currency' ? self::currency($claim) : rtrim($claim, ',.')];
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
        if ($kind === 'date') {
            return self::supportedDate($claim, $evidence['dates']);
        }

        return self::contains($evidence['text'], self::normalize($claim));
    }

    /**
     * Every date in a text as [day|null, month 1-12, year|null], whatever its
     * spelling: "18 March 2027", "March 18, 2027", "18th of Mar", "June 2027".
     *
     * @return list<array{0: ?int, 1: int, 2: ?int}>
     */
    public static function dates(string $text): array
    {
        $found = [];
        foreach (['date', 'date_md', 'date_my'] as $kind) {
            $text = preg_replace_callback(self::CLAIMS[$kind], function (array $m) use (&$found): string {
                $date = self::parseDate($m[0]);
                if ($date !== null) {
                    $found[] = $date;
                }

                return str_repeat(' ', strlen($m[0]));
            }, $text) ?? $text;
        }

        return $found;
    }

    /** @return array{0: ?int, 1: int, 2: ?int}|null */
    private static function parseDate(string $text): ?array
    {
        if (preg_match('/\b('.self::MONTHS.')\b/i', $text, $month) !== 1) {
            return null;
        }
        $index = array_search(substr(strtolower($month[1]), 0, 3), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
        $year = preg_match('/\b((?:19|20)\d{2})\b/', $text, $y) === 1 ? (int) $y[1] : null;
        $rest = $year === null ? $text : str_replace($y[1], '', $text);
        $day = preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\b/i', $rest, $d) === 1 ? (int) $d[1] : null;

        return $index === false ? null : [$day, $index + 1, $year];
    }

    /**
     * A date is supported when the brief names the same day and month (and,
     * when the claim gives one, a year the brief puts with it), or, for a
     * month and year with no day, that month in that year.
     *
     * @param  list<array{0: ?int, 1: int, 2: ?int}>  $dates
     */
    private static function supportedDate(string $claim, array $dates): bool
    {
        $date = self::parseDate($claim);
        if ($date === null) {
            return false;
        }
        [$day, $month, $year] = $date;
        foreach ($dates as [$d, $m, $y]) {
            if ($m !== $month || ($day !== null && $d !== $day)) {
                continue;
            }
            if ($year === null || $y === null || $y === $year) {
                return true;
            }
        }

        return false;
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

        return ['text' => self::normalize(implode("\n", $parts)), 'digits' => $digits, 'dates' => self::dates(implode("\n", $parts))];
    }
}
