<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Brief\Brief;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 *     $session = Charlotte::for($request->user())
 *         ->tenant((string) $team->getKey())
 *         ->page('home')
 *         ->brief(Brief::fromArray($validated['brief']))
 *         ->open();
 */
final class PendingCharlotteSession
{
    private ?string $tenant = null;

    private ?string $page = null;

    private ?Brief $brief = null;

    private ?Autonomy $autonomy = null;

    private ?string $provider = null;

    private ?string $model = null;

    private ?array $document = null;

    public function __construct(private readonly Charlotte $charlotte, private readonly Model $participant) {}

    /** The host's partition (team, site, account). Every read and write is scoped by it. */
    public function tenant(string $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    /** The host's page key, unique within the tenant. */
    public function page(string $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function brief(Brief|array $brief): self
    {
        $this->brief = is_array($brief) ? Brief::fromArray($brief) : $brief;

        return $this;
    }

    /** Overrides config `charlotte.autonomy` for this session. */
    public function autonomy(Autonomy|string $autonomy): self
    {
        $this->autonomy = is_string($autonomy) ? Autonomy::from($autonomy) : $autonomy;

        return $this;
    }

    /** Any Prism provider; overrides config `charlotte.provider`. */
    public function provider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /** The starting document when this page has no draft yet. Ignored when it has one. */
    public function initialDocument(array $document): self
    {
        $this->document = $document;

        return $this;
    }

    public function open(): CharlotteSession
    {
        if ($this->tenant === null || $this->page === null || $this->brief === null) {
            throw new LogicException('Charlotte needs a tenant, a page and a brief before open().');
        }

        return $this->charlotte->open($this->participant, $this->tenant, $this->page, $this->brief,
            $this->autonomy, $this->provider, $this->model, $this->document);
    }
}
