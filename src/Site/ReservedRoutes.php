<?php

declare(strict_types=1);

namespace Charlotte\Site;

use InvalidArgumentException;

/**
 * Routes the HOST serves, where a site page would never be shown (finding
 * K13: a Fortify host owns /login, /register, /forgot-password and more, and
 * a site page at one of them silently renders the auth screen instead).
 *
 * Host-supplied: config `charlotte.site.reserved_routes`, or bind your own
 * instance. An entry is an exact route (`/login`) or a prefix ending in `/*`
 * (`/reset-password/*` reserves `/reset-password` and everything under it).
 * Matching is exact on the route grammar, which is already lowercase with no
 * trailing slash, query or fragment, so `/Login` and `/login/` are refused as
 * malformed before they are ever compared.
 */
final class ReservedRoutes
{
    /** @var list<string> */
    private array $entries = [];

    /** @param list<string> $entries */
    public function __construct(array $entries = [])
    {
        foreach ($entries as $entry) {
            $base = is_string($entry) && str_ends_with($entry, '/*') ? substr($entry, 0, -2) : $entry;
            if (! is_string($base) || ! Site::validRoute($base === '' ? '/' : $base)) {
                throw new InvalidArgumentException('Reserved routes are site routes such as /login, or prefixes such as /admin/*.');
            }
            $this->entries[] = $entry;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->entries;
    }

    /** The reserved entry a route falls under, or null. */
    public function match(string $route): ?string
    {
        foreach ($this->entries as $entry) {
            if (str_ends_with($entry, '/*')) {
                $base = substr($entry, 0, -2);
                if ($route === $base || str_starts_with($route, $base.'/') || $base === '') {
                    return $entry;
                }
            } elseif ($route === $entry) {
                return $entry;
            }
        }

        return null;
    }
}
