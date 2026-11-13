<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use JsonException;

/**
 * The critic's reply, parsed: `{"verdict", "items"}`, also when it was cut off.
 *
 * Flabs runs 579 and 580: both Haiku 5.5 critic replies stopped at the output
 * cap in the middle of an item, and refusing the whole reply as invalid threw
 * away every good item before the cut, so no revision ever ran. A cut-off
 * reply now keeps each item that was COMPLETE, and never one built from the
 * partial item after it.
 *
 * How, without guessing: every point where an item object closes is a
 * candidate end. The text up to it, closed with `]}`, must decode as real
 * JSON with a valid verdict; the latest candidate that does wins. A partial
 * item never decodes, so it can never be in the result, and a verdict that
 * comes only after the cut means no usable critique (null).
 */
final class CriticReply
{
    public const VERDICTS = ['revise', 'pass'];

    /**
     * @param  bool  $truncated  the provider reported the output limit (max_tokens)
     * @return array{verdict: string, items: list<mixed>, truncated: bool}|null null when there is no usable critique
     */
    public static function parse(string $text, bool $truncated = false): ?array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $end = strrpos($text, '}');
        if ($end !== false && $end > $start) {
            $whole = self::shape(substr($text, $start, $end - $start + 1));
            if ($whole !== null) {
                return $whole + ['truncated' => $truncated];
            }
        }
        $salvaged = self::salvage(substr($text, $start));

        return $salvaged === null ? null : $salvaged + ['truncated' => true];
    }

    /** @return array{verdict: string, items: list<mixed>}|null */
    private static function shape(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded) || ! in_array($decoded['verdict'] ?? null, self::VERDICTS, true)
            || ! is_array($decoded['items'] ?? []) || ! array_is_list($decoded['items'] ?? [])) {
            return null;
        }

        return ['verdict' => $decoded['verdict'], 'items' => array_values($decoded['items'] ?? [])];
    }

    /**
     * The longest prefix that ends where an item (or the empty items list)
     * ends, closed and decoded.
     *
     * @return array{verdict: string, items: list<mixed>}|null
     */
    private static function salvage(string $text): ?array
    {
        $cuts = [];
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
                if ($char === '[' && $depth === 2) {
                    $cuts[] = $i; // the items list opened: no complete item yet
                }
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($char === '}' && $depth === 2) {
                    $cuts[] = $i; // an item closed
                }
            }
        }
        foreach (array_reverse($cuts) as $cut) {
            $shape = self::shape(substr($text, 0, $cut + 1).']}');
            if ($shape !== null) {
                return $shape;
            }
        }

        return null;
    }
}
