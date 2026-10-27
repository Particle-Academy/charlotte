<?php

declare(strict_types=1);

namespace Charlotte\Policy;

use Charlotte\Checks\PageChecks;
use InvalidArgumentException;

/**
 * Which link targets an AGENT may write.
 *
 * Relative paths, in-page anchors, mailto: and tel: are always allowed.
 * An absolute http(s) or protocol-relative URL is allowed only when its exact
 * origin is on the host's allowlist. Everything else is refused before the
 * write lands. Human edits are not filtered here; the page checks list every
 * external link for review instead.
 */
final class LinkPolicy
{
    /** @var array<string, true> */
    private array $origins = [];

    /** @param list<string> $origins e.g. ['https://example.com'] */
    public function __construct(array $origins = [])
    {
        foreach ($origins as $origin) {
            $normal = is_string($origin) ? PageChecks::externalOrigin($origin) : null;
            if ($normal === null || rtrim(strtolower($origin), '/') !== $normal) {
                throw new InvalidArgumentException('Allowlisted link origins are scheme://host[:port], with no path.');
            }
            $this->origins[$normal] = true;
        }
    }

    /** @return list<string> */
    public function origins(): array
    {
        return array_keys($this->origins);
    }

    public function allows(string $href): bool
    {
        $href = trim($href);
        if (! PageChecks::isSafeHref($href)) {
            return false;
        }
        $origin = PageChecks::externalOrigin($href);

        return $origin === null || isset($this->origins[$origin]);
    }

    /** Every string `href` anywhere in the ops: node props, patches, actions. */
    public function assertOps(array $ops, ?string $revision = null): void
    {
        $walk = function (mixed $value) use (&$walk, $revision): void {
            if (! is_array($value) && ! is_object($value)) {
                return;
            }
            foreach ((array) $value as $key => $item) {
                if ($key === 'href' && is_string($item) && ! $this->allows($item)) {
                    throw new Refused('link_refused', $revision);
                }
                $walk($item);
            }
        };
        $walk($ops);
    }
}
