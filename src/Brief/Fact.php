<?php

declare(strict_types=1);

namespace Charlotte\Brief;

/**
 * One piece of content truth. `value` is the exact text the page must carry
 * when `required`; anything priced, counted, quoted or dated on the page must
 * trace back to some fact or it is reported as an unsupported claim.
 */
final readonly class Fact
{
    public const KINDS = ['price', 'contact', 'quote', 'stat', 'name', 'date', 'feature', 'claim', 'other'];

    public function __construct(
        public string $id,
        public string $kind,
        public string $value,
        public bool $required = true,
        public ?string $label = null,
        public ?string $attribution = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id, 'kind' => $this->kind, 'label' => $this->label, 'value' => $this->value,
            'attribution' => $this->attribution, 'required' => $this->required,
        ], fn ($v) => $v !== null);
    }
}
