<?php

declare(strict_types=1);

namespace Charlotte\Storage;

use Illuminate\Database\ConnectionInterface;

/**
 * `charlotte_proposals`: the pending change set under approve_on_save.
 *
 * At most one open change set per session. Its working copy is a fancy-cms
 * PageSession seeded from the draft when the set opened, so revisions the
 * agent sees stay continuous while it works.
 */
final class ProposalStore
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function openFor(string $session, bool $lock = false): ?object
    {
        $query = $this->db->table('charlotte_proposals')->where('session_id', $session)->where('state', 'open')->orderBy('created_at');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function find(string $id, bool $lock = false): ?object
    {
        $query = $this->db->table('charlotte_proposals')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function create(string $id, string $session, string $baseRevision, string $workingJson, array $ops): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('charlotte_proposals')->insert([
            'id' => $id, 'session_id' => $session, 'state' => 'open', 'base_revision' => $baseRevision,
            'working_json' => $workingJson, 'ops_json' => self::encode($ops),
            'batches' => 1, 'op_count' => count($ops), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function append(string $id, string $workingJson, array $allOps, int $batches): void
    {
        $this->db->table('charlotte_proposals')->where('id', $id)->update([
            'working_json' => $workingJson, 'ops_json' => self::encode($allOps),
            'batches' => $batches, 'op_count' => count($allOps), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function resolve(string $id, string $state, string $by): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('charlotte_proposals')->where('id', $id)->update([
            'state' => $state, 'resolved_by' => $by, 'resolved_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Resolutions the agent has not been told about yet; marked told as they
     * are returned, so each reaches the agent once.
     *
     * @return list<array{proposalId: string, state: string}>
     */
    public function takeNotices(string $session): array
    {
        $rows = $this->db->table('charlotte_proposals')->where('session_id', $session)
            ->where('state', '!=', 'open')->where('agent_notified', false)->orderBy('resolved_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $this->db->table('charlotte_proposals')->whereIn('id', $rows->pluck('id')->all())->update(['agent_notified' => true]);

        return $rows->map(fn (object $row) => ['proposalId' => (string) $row->id, 'state' => (string) $row->state])->values()->all();
    }

    /** @return list<array> */
    public static function ops(object $row): array
    {
        return json_decode((string) $row->ops_json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function encode(array $ops): string
    {
        return json_encode($ops, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
