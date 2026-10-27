<?php

declare(strict_types=1);

namespace Charlotte\Guide;

use InvalidArgumentException;

/**
 * The exact guide a session ran with: its text, which installed packages
 * supplied it, and every override applied on top. Immutable; `append()` and
 * `replace()` return a new version, and the hash covers the final text.
 *
 * Generalized from the flabs lab's SiteGuideVersion, without the lab's
 * instrument hashing and without refusing a dev install (a package's own
 * checkout reports a dev version, and that is a legitimate place to run it).
 */
final readonly class GuideVersion
{
    public string $guideHash;

    /**
     * @param  array<string, array{name: string, version: ?string, reference: ?string}>  $components
     * @param  list<array{label: string, kind: string, hash: string}>  $overrides
     */
    public function __construct(public string $guide, public array $components = [], public array $overrides = [])
    {
        if (trim($guide) === '') {
            throw new InvalidArgumentException('A guide cannot be empty.');
        }
        $this->guideHash = hash('sha256', $guide);
    }

    public function append(string $text, string $label = 'append'): self
    {
        return $this->with(rtrim($this->guide)."\n\n".$text, 'append', $text, $label);
    }

    public function replace(string $text, string $label = 'replace'): self
    {
        return $this->with($text, 'replace', $text, $label);
    }

    /** @return array{hash: string, components: array, overrides: list<array>} */
    public function toArray(): array
    {
        return ['hash' => $this->guideHash, 'components' => $this->components, 'overrides' => $this->overrides];
    }

    private function with(string $guide, string $kind, string $text, string $label): self
    {
        if (trim($text) === '' || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $label) !== 1) {
            throw new InvalidArgumentException('An override needs text and a short lowercase label.');
        }

        return new self($guide, $this->components, [...$this->overrides, ['label' => $label, 'kind' => $kind, 'hash' => hash('sha256', $text)]]);
    }
}
