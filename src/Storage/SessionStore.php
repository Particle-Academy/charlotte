<?php

declare(strict_types=1);

namespace Charlotte\Storage;

use Charlotte\Autonomy;
use Charlotte\Brief\Brief;
use Charlotte\Sessions\SessionRecord;
use Illuminate\Database\ConnectionInterface;

/** `charlotte_sessions`. */
final class SessionStore
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(SessionRecord $record): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('charlotte_sessions')->insert([
            'id' => $record->id, 'tenant_key' => $record->tenant, 'page_key' => $record->page,
            'participant_type' => $record->participantType, 'participant_id' => $record->participantId,
            'autonomy' => $record->autonomy->value, 'provider' => $record->provider, 'model' => $record->model,
            'brief_json' => json_encode($record->brief->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'guide_hash' => $record->guideHash,
            'guide_json' => json_encode($record->guide, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'status' => $record->status, 'stop_requested_at' => null, 'ready_revision' => null, 'site_key' => $record->site,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function find(string $id, bool $lock = false): ?SessionRecord
    {
        $query = $this->db->table('charlotte_sessions')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : new SessionRecord(
            id: (string) $row->id,
            tenant: (string) $row->tenant_key,
            page: (string) $row->page_key,
            participantType: (string) $row->participant_type,
            participantId: (string) $row->participant_id,
            autonomy: Autonomy::from((string) $row->autonomy),
            provider: (string) $row->provider,
            model: (string) $row->model,
            brief: Brief::fromArray(json_decode((string) $row->brief_json, true, 64, JSON_THROW_ON_ERROR)),
            guideHash: (string) $row->guide_hash,
            guide: json_decode((string) $row->guide_json, true, 64, JSON_THROW_ON_ERROR),
            status: (string) $row->status,
            stopRequestedAt: $row->stop_requested_at === null ? null : (string) $row->stop_requested_at,
            readyRevision: $row->ready_revision === null ? null : (string) $row->ready_revision,
            site: ($row->site_key ?? null) === null ? null : (string) $row->site_key,
        );
    }

    /** Consecutive cms_finish refusals in the current piece of work. */
    public function refusals(string $id): int
    {
        return (int) ($this->db->table('charlotte_sessions')->where('id', $id)->value('finish_refusals') ?? 0);
    }

    /** Start counting refusals again: new work began, or a finish succeeded. */
    public function resetRefusals(string $id): void
    {
        $this->db->table('charlotte_sessions')->where('id', $id)->where('finish_refusals', '>', 0)->update(['finish_refusals' => 0]);
    }

    /** @param array<string, mixed> $fields column => value */
    public function update(string $id, array $fields): void
    {
        $this->db->table('charlotte_sessions')->where('id', $id)->update([...$fields, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
}
