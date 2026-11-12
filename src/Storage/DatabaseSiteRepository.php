<?php

declare(strict_types=1);

namespace Charlotte\Storage;

use Charlotte\Site\Site;
use Charlotte\Site\SiteRepository;
use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** The default SiteRepository, in `charlotte_sites`. */
final class DatabaseSiteRepository implements SiteRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function ensure(string $tenant, string $site): Site
    {
        $empty = new Site($site, 0, []);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('charlotte_sites')->insertOrIgnore([
            'tenant_key' => $tenant, 'site_key' => $site,
            'structure_json' => self::encode($empty), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($tenant, $site) ?? throw new RuntimeException('[charlotte] the site row was not created.');
    }

    public function find(string $tenant, string $site): ?Site
    {
        $row = $this->db->table('charlotte_sites')->where('tenant_key', $tenant)->where('site_key', $site)->first();

        return $row === null ? null : self::decode((string) $row->structure_json);
    }

    public function edit(string $tenant, string $site, Closure $edit): Site
    {
        return $this->db->transaction(function () use ($tenant, $site, $edit): Site {
            $row = $this->db->table('charlotte_sites')->where('tenant_key', $tenant)->where('site_key', $site)->lockForUpdate()->first()
                ?? throw new RuntimeException('[charlotte] no such site.');
            $next = $edit(self::decode((string) $row->structure_json));
            $this->db->table('charlotte_sites')->where('id', $row->id)->update([
                'structure_json' => self::encode($next), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return $next;
        });
    }

    private static function encode(Site $site): string
    {
        return json_encode($site->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $json): Site
    {
        return Site::fromArray(json_decode($json, true, 16, JSON_THROW_ON_ERROR));
    }
}
