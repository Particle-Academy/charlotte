<?php

declare(strict_types=1);

namespace Charlotte\Policy;

use RuntimeException;

/**
 * An expected refusal with a stable code. Tool results and events carry the
 * code and this fixed message, never an exception message from below.
 *
 * `details` are agent-facing facts Charlotte itself computed (the route that
 * was refused, the reserved entry it matched); never text from an exception.
 */
final class Refused extends RuntimeException
{
    public const MESSAGES = [
        'image_source_refused' => 'Use a host-supplied image asset handle; authored image URLs, dimensions and CSS fetches are refused.',
        'link_refused' => 'Links may target this site, an in-page anchor, mailto: or tel:, or an external origin the host allowlisted.',
        'stopped' => 'The session was stopped by a person. Do not continue; end your turn.',
        'proposal_missing' => 'No such pending change set.',
        'proposal_resolved' => 'That change set was already resolved.',
        'page_missing' => 'This session has no page.',
        'session_missing' => 'No such session.',
        'turn_in_progress' => 'A turn is already running for this session.',
        'invalid_request' => 'The request was malformed.',
        'guide_mode_stale' => 'The builder mode does not embed the current guide exactly once.',
        'route_reserved' => 'That route belongs to the host (for example its sign-in and registration pages), so a site page there would never be shown. Choose another route, such as /registration instead of /register; site_get lists every reserved route.',
        'route_invalid' => 'A route is "/" or lowercase path segments of letters, digits and hyphens, such as /pricing or /work/case-study: no trailing slash, query, fragment, dot segment or scheme. A not_found page takes no route (send "").',
        'route_taken' => 'Another page in this site already has that route.',
        'site_page_missing' => 'This site has no page with that id. site_get lists the pages.',
        'site_page_exists' => 'This site already has a page with that id.',
        'site_page_unrouted' => 'That page has no route, so nothing can link to it. Give it one with site_page_route first.',
        'site_not_found_exists' => 'This site already has its not_found (404) page.',
        'site_full' => 'This site already has the most pages the host allows.',
        'not_a_site' => 'This session edits one page, not a site.',
    ];

    /** @param array<string, scalar|null> $details */
    public function __construct(public readonly string $reason, public readonly ?string $revision = null, public readonly array $details = [])
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'The request was refused.');
    }
}
