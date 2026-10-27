<?php

declare(strict_types=1);

namespace Charlotte\Http;

/**
 * The dev-supplied authorization hook every route asks.
 *
 * Abilities: `open`, `view` (state and events), `prompt` (messages, stop),
 * `edit` (a person's ops), `approve` (accept/reject a change set), `publish`.
 * The subject is `['tenant' => ..., 'page' => ..., 'session' => id|null]`.
 *
 * The default is {@see DenyAll}: until a host supplies a hook, nothing is
 * allowed. There is no "any signed-in user" default.
 */
interface Authorizer
{
    public const ABILITIES = ['open', 'view', 'prompt', 'edit', 'approve', 'publish'];

    /** @param array{tenant: string, page: string, session: ?string} $subject */
    public function allows(mixed $principal, string $ability, array $subject): bool;
}
