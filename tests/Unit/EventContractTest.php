<?php

declare(strict_types=1);

use Charlotte\Events\Event;
use Charlotte\Events\EventTypes;

/*
 * The shared v1 fixture (tests/fixtures/events.v1.json) is the contract a
 * client is written against. It is meant to be read by charlotte-ui's suite
 * too, so both sides prove the same bytes.
 */

function fixtureEvents(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/events.v1.json'), true, 64, JSON_THROW_ON_ERROR);
}

function eventSchema(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../resources/schema/events.v1.json'), true, 64, JSON_THROW_ON_ERROR);
}

test('every fixture event round-trips through the envelope unchanged', function (): void {
    foreach (fixtureEvents() as $array) {
        expect(Event::fromArray($array)->toArray())->toBe($array);
    }
});

test('the fixture covers every event type Charlotte emits', function (): void {
    expect(array_values(array_unique(array_column(fixtureEvents(), 'type'))))->toEqualCanonicalizing(EventTypes::ALL);
});

test('the shipped JSON schema names exactly the emitted types and the envelope keys', function (): void {
    $schema = eventSchema();

    expect($schema['properties']['type']['enum'])->toEqualCanonicalizing(EventTypes::ALL)
        ->and($schema['required'])->toBe(['v', 'id', 'seq', 'session', 'at', 'type', 'actor', 'data'])
        ->and($schema['properties']['v']['const'])->toBe(1);
});

test('an unknown type is still a valid envelope, so old clients can skip it', function (): void {
    $array = fixtureEvents()[0];
    $array['type'] = 'future.thing';

    expect(Event::fromArray($array)->type)->toBe('future.thing');
});

test('an envelope from another version is refused rather than misread', function (): void {
    $array = fixtureEvents()[0];
    $array['v'] = 2;

    Event::fromArray($array);
})->throws(InvalidArgumentException::class);
