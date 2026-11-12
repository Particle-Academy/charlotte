<?php

declare(strict_types=1);

namespace Charlotte\Data;

use FancyCms\Exceptions\PageSessionException;

/**
 * Data bindings against the host's {@see DataCatalogue}, after every write
 * and for every actor, like the asset and island policies: a node binds only
 * to a catalogue stream and its declared fields, with params and scope the
 * stream declares.
 *
 * Only the nodes this write created or changed are judged, so a draft whose
 * bindings predate a narrowed catalogue stays editable; `cms_check` reports
 * those as `invalid_binding`.
 */
final class BindingPolicy
{
    public static function assert(array $before, array $after, DataCatalogue $catalogue, ?string $revision = null): void
    {
        $old = json_decode(json_encode($before['nodes'] ?? []) ?: '[]', true) ?: [];
        $changed = [];
        foreach (json_decode(json_encode($after['nodes'] ?? []) ?: '[]', true) ?: [] as $id => $node) {
            $id = (string) $id;
            if (! isset($old[$id]) || json_encode($old[$id]) !== json_encode($node)) {
                $changed[] = $id;
            }
        }
        if ($changed === []) {
            return;
        }
        $errors = Bindings::validate($after, $catalogue, $changed);
        if ($errors !== []) {
            throw new PageSessionException('binding_invalid',
                array_map(fn (array $e) => ['index' => null] + $e + ['hint' => Bindings::HINT], array_slice($errors, 0, 20)), $revision);
        }
    }
}
