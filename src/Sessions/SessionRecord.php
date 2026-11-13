<?php

declare(strict_types=1);

namespace Charlotte\Sessions;

use Charlotte\Autonomy;
use Charlotte\Brief\Brief;
use Charlotte\Design\Examples;

/** One builder session as stored. Page, tenant and participant are host-owned, never model input. */
final readonly class SessionRecord
{
    public const STATUSES = ['idle', 'queued', 'running', 'stopping', 'stopped', 'failed'];

    public function __construct(
        public string $id,
        public string $tenant,
        public string $page,
        public string $participantType,
        public string $participantId,
        public Autonomy $autonomy,
        public string $provider,
        public string $model,
        public Brief $brief,
        public string $guideHash,
        public array $guide,
        public string $status,
        public ?string $stopRequestedAt,
        public ?string $readyRevision,
        public ?string $site = null,
    ) {}

    /** A whole-site session: `page` is then the selected document's key. */
    public function isSite(): bool
    {
        return $this->site !== null;
    }

    /**
     * The same session addressed at another document of its site. Host code
     * only (the site tools resolve the key from the bound site); every
     * Workspace method reads `page` from the record it is given.
     */
    public function onPage(string $page): self
    {
        return new self($this->id, $this->tenant, $page, $this->participantType, $this->participantId, $this->autonomy,
            $this->provider, $this->model, $this->brief, $this->guideHash, $this->guide, $this->status,
            $this->stopRequestedAt, $this->readyRevision, $this->site);
    }

    /**
     * The session's two worked examples ({@see Examples}): the ids recorded in
     * `guide.examples` when it opened, else (a session opened before they
     * were recorded) sampled from its id, which is what it would have drawn.
     *
     * @return list<string>
     */
    public function examples(): array
    {
        $ids = $this->guide['examples'] ?? null;

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : Examples::sample($this->id);
    }

    public function stopped(): bool
    {
        return $this->stopRequestedAt !== null;
    }

    /** The harness scope this session's agent conversation lives under. */
    public function scope(): string
    {
        return 'charlotte:'.$this->id;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'page' => $this->page, 'status' => $this->status, 'autonomy' => $this->autonomy->value,
            'provider' => $this->provider, 'model' => $this->model, 'guideHash' => $this->guideHash]
            + ($this->site === null ? [] : ['site' => $this->site]);
    }
}
