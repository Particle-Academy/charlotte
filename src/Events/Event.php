<?php

declare(strict_types=1);

namespace Charlotte\Events;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * The v1 event envelope.
 *
 *     {"v":1,"id":"01J...","seq":412,"session":"01J...","at":"2026-10-27T12:00:00.000Z",
 *      "type":"doc.changed","actor":{"kind":"agent","id":"charlotte"},"data":{...}}
 *
 * `seq` is a per-session, gap-free, monotonic cursor: a client resumes with
 * `after=<seq>`. `id` is unique across sessions.
 */
final readonly class Event implements JsonSerializable
{
    public const VERSION = 1;

    public const MAX_DATA_BYTES = 262144;

    /**
     * @param  array{kind: string, id: string}  $actor
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public int $seq,
        public string $session,
        public string $at,
        public string $type,
        public array $actor,
        public array $data,
    ) {
        self::assertType($type);
        self::assertActor($actor);
        if (! Ulid::valid($id) || $seq < 1 || $session === '' || strlen($session) > 191) {
            throw new InvalidArgumentException('An event needs a ULID id, a positive seq and a session id.');
        }
    }

    public static function assertType(string $type): void
    {
        if (strlen($type) > 64 || preg_match(EventTypes::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('Event types are dotted lowercase words.');
        }
    }

    public static function assertActor(array $actor): void
    {
        if (array_keys($actor) !== ['kind', 'id'] || ! in_array($actor['kind'], EventTypes::ACTOR_KINDS, true)
            || ! is_string($actor['id']) || strlen($actor['id']) > 191) {
            throw new InvalidArgumentException('An actor is {kind: agent|human|system, id: string}.');
        }
    }

    /** @param array<string, mixed> $array */
    public static function fromArray(array $array): self
    {
        if (array_keys($array) !== ['v', 'id', 'seq', 'session', 'at', 'type', 'actor', 'data']) {
            throw new InvalidArgumentException('Not a v1 event envelope.');
        }
        if ($array['v'] !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported event envelope version.');
        }
        if (! is_string($array['id']) || ! is_int($array['seq']) || ! is_string($array['session']) || ! is_string($array['at'])
            || ! is_string($array['type']) || ! is_array($array['actor']) || ! is_array($array['data'])) {
            throw new InvalidArgumentException('Not a v1 event envelope.');
        }

        return new self($array['id'], $array['seq'], $array['session'], $array['at'], $array['type'], $array['actor'], $array['data']);
    }

    /** @return array{v: int, id: string, seq: int, session: string, at: string, type: string, actor: array, data: array} */
    public function toArray(): array
    {
        return ['v' => self::VERSION, 'id' => $this->id, 'seq' => $this->seq, 'session' => $this->session,
            'at' => $this->at, 'type' => $this->type, 'actor' => $this->actor, 'data' => $this->data];
    }

    /** `data` is always a JSON object on the wire, even when empty. */
    public function jsonSerialize(): array
    {
        $array = $this->toArray();
        if ($array['data'] === []) {
            $array['data'] = new stdClass;
        }

        return $array;
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Encode event data, refusing anything that cannot round-trip or is too large. */
    public static function encodeData(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_DATA_BYTES) {
            throw new InvalidArgumentException('Event data exceeds 256 KiB.');
        }

        return $json;
    }
}
