<?php

declare(strict_types=1);

namespace Charlotte\Sessions;

use Charlotte\Autonomy;
use Charlotte\Brief\Brief;

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
    ) {}

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
            'provider' => $this->provider, 'model' => $this->model, 'guideHash' => $this->guideHash];
    }
}
