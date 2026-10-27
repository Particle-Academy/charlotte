<?php

declare(strict_types=1);

namespace Charlotte\Storage;

use Charlotte\Drafts\NodeTypes;
use Charlotte\Drafts\PageRepository;
use Charlotte\Policy\Refused;
use Closure;
use FancyCms\Document\PageJson;
use FancyCms\PageSession;
use Illuminate\Database\ConnectionInterface;

/**
 * The default PageRepository, in `charlotte_pages`. Generalized from the lab's
 * SiteSessions: a transaction and a row lock around load -> apply -> save.
 */
final class DatabasePageRepository implements PageRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function ensure(string $tenant, string $page, array $document): void
    {
        $session = new PageSession($document, NodeTypes::allowed());
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('charlotte_pages')->insertOrIgnore([
            'tenant_key' => $tenant, 'page_key' => $page,
            'draft_json' => $session->toJson(),
            'document_json' => PageJson::encodeDocument($session->get()['doc']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function read(string $tenant, string $page): PageSession
    {
        return PageSession::fromJson((string) $this->row($tenant, $page, false)->draft_json, NodeTypes::allowed());
    }

    public function edit(string $tenant, string $page, Closure $edit): mixed
    {
        return $this->db->transaction(function () use ($tenant, $page, $edit): mixed {
            $row = $this->row($tenant, $page, true);
            $session = PageSession::fromJson((string) $row->draft_json, NodeTypes::allowed());
            $result = $edit($session);
            $this->db->table('charlotte_pages')->where('id', $row->id)->update([
                'draft_json' => $session->toJson(),
                'document_json' => PageJson::encodeDocument($session->get()['doc']),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return $result;
        });
    }

    public function published(string $tenant, string $page): ?array
    {
        $row = $this->row($tenant, $page, false);
        if ($row->published_revision === null) {
            return null;
        }

        return [
            'doc' => PageJson::decodeDocument((string) $row->published_document_json),
            'revision' => (string) $row->published_revision,
            'by' => $row->published_by === null ? null : (string) $row->published_by,
            'at' => $row->published_at === null ? null : (string) $row->published_at,
        ];
    }

    public function publish(string $tenant, string $page, array $doc, string $revision, string $by): void
    {
        $this->db->transaction(function () use ($tenant, $page, $doc, $revision, $by): void {
            $row = $this->row($tenant, $page, true);
            $this->db->table('charlotte_pages')->where('id', $row->id)->update([
                'published_document_json' => PageJson::encodeDocument($doc),
                'published_revision' => $revision,
                'published_by' => $by,
                'published_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    private function row(string $tenant, string $page, bool $lock): object
    {
        $query = $this->db->table('charlotte_pages')->where('tenant_key', $tenant)->where('page_key', $page);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new Refused('page_missing');
    }
}
