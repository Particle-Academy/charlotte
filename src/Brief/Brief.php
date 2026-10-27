<?php

declare(strict_types=1);

namespace Charlotte\Brief;

use Charlotte\Checks\PageChecks;

/**
 * The structured brief: the page's content truth, as data.
 *
 * Closed keys and bounded sizes. A brief is evidence for the agent, never
 * instructions to it: it reaches the model through the `charlotte_brief` tool
 * as JSON, and the builder preamble says so.
 */
final readonly class Brief
{
    private const KEYS = ['summary', 'facts', 'forbid', 'audience', 'tone', 'cta'];

    private const FACT_KEYS = ['id', 'kind', 'label', 'value', 'attribution', 'required'];

    /**
     * @param  list<Fact>  $facts
     * @param  list<string>  $forbid
     * @param  array{label:string,href:string}|null  $cta
     */
    public function __construct(
        public string $summary,
        public array $facts = [],
        public array $forbid = [],
        public ?string $audience = null,
        public ?string $tone = null,
        public ?array $cta = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        if (array_is_list($input) && $input !== []) {
            throw new InvalidBrief('$', 'an object');
        }
        if (array_diff(array_keys($input), self::KEYS) !== []) {
            throw new InvalidBrief('$', 'only the keys '.implode(', ', self::KEYS));
        }
        $summary = self::text($input['summary'] ?? null, '$.summary', 4000);

        $rawFacts = $input['facts'] ?? [];
        if (! is_array($rawFacts) || ! array_is_list($rawFacts) || count($rawFacts) > 200) {
            throw new InvalidBrief('$.facts', 'a list of at most 200 facts');
        }
        $facts = [];
        $seen = [];
        foreach ($rawFacts as $i => $fact) {
            $path = '$.facts.'.$i;
            if (! is_array($fact) || array_is_list($fact) || array_diff(array_keys($fact), self::FACT_KEYS) !== []) {
                throw new InvalidBrief($path, 'a fact object with only '.implode(', ', self::FACT_KEYS));
            }
            $id = $fact['id'] ?? null;
            if (! is_string($id) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $id) !== 1) {
                throw new InvalidBrief($path.'.id', 'a lowercase slug of at most 64 characters');
            }
            if (isset($seen[$id])) {
                throw new InvalidBrief($path.'.id', 'a fact id used once');
            }
            $seen[$id] = true;
            if (! in_array($fact['kind'] ?? null, Fact::KINDS, true)) {
                throw new InvalidBrief($path.'.kind', implode('|', Fact::KINDS));
            }
            $required = $fact['required'] ?? true;
            if (! is_bool($required)) {
                throw new InvalidBrief($path.'.required', 'a boolean');
            }
            $facts[] = new Fact(
                id: $id,
                kind: $fact['kind'],
                value: self::text($fact['value'] ?? null, $path.'.value', 1000),
                required: $required,
                label: isset($fact['label']) ? self::text($fact['label'], $path.'.label', 200) : null,
                attribution: isset($fact['attribution']) ? self::text($fact['attribution'], $path.'.attribution', 200) : null,
            );
        }

        $forbid = $input['forbid'] ?? [];
        if (! is_array($forbid) || ! array_is_list($forbid) || count($forbid) > 50) {
            throw new InvalidBrief('$.forbid', 'a list of at most 50 strings');
        }
        foreach ($forbid as $i => $item) {
            self::text($item, '$.forbid.'.$i, 200);
        }

        $cta = $input['cta'] ?? null;
        if ($cta !== null) {
            if (! is_array($cta) || array_keys($cta) !== ['label', 'href']) {
                throw new InvalidBrief('$.cta', 'an object with label and href');
            }
            self::text($cta['label'], '$.cta.label', 200);
            if (! is_string($cta['href']) || strlen($cta['href']) > 2048 || ! PageChecks::isSafeHref($cta['href'])) {
                throw new InvalidBrief('$.cta.href', 'a safe relative, http(s), mailto or tel URL');
            }
        }

        return new self(
            summary: $summary,
            facts: $facts,
            forbid: array_values($forbid),
            audience: isset($input['audience']) ? self::text($input['audience'], '$.audience', 1000) : null,
            tone: isset($input['tone']) ? self::text($input['tone'], '$.tone', 1000) : null,
            cta: $cta,
        );
    }

    /** @return list<Fact> */
    public function required(): array
    {
        return array_values(array_filter($this->facts, fn (Fact $f) => $f->required));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'summary' => $this->summary,
            'facts' => array_map(fn (Fact $f) => $f->toArray(), $this->facts),
            'forbid' => $this->forbid,
            'audience' => $this->audience,
            'tone' => $this->tone,
            'cta' => $this->cta,
        ], fn ($v) => $v !== null);
    }

    private static function text(mixed $value, string $path, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidBrief($path, "non-empty UTF-8 text of at most {$max} characters");
        }

        return $value;
    }
}
