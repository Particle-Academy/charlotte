<?php

declare(strict_types=1);

use Charlotte\Events\Event;
use Charlotte\Events\EventLog;
use Charlotte\Events\InMemoryEventLog;
use Charlotte\Storage\DatabaseEventLog;
use Charlotte\Tests\Support\TestApp;
use Charlotte\Transport\LongPoll;
use Charlotte\Tests\Support\RecordingSleeper;
use Illuminate\Support\Facades\DB;

/** Both shipped logs must honour the same contract. */
dataset('logs', [
    'in-memory' => [fn (): EventLog => new InMemoryEventLog],
    'database' => [function (): EventLog {
        TestApp::boot();

        return new DatabaseEventLog(DB::connection());
    }],
]);

test('every event carries the v1 envelope', function (Closure $make): void {
    $log = $make();
    $event = $log->append('s1', 'session.status', ['kind' => 'system', 'id' => 'charlotte'], ['status' => 'idle']);
    $array = $event->toArray();

    expect(array_keys($array))->toBe(['v', 'id', 'seq', 'session', 'at', 'type', 'actor', 'data'])
        ->and($array['v'])->toBe(1)
        ->and($array['id'])->toMatch('/\A[0-9A-HJKMNP-TV-Z]{26}\z/')
        ->and($array['seq'])->toBe(1)
        ->and($array['session'])->toBe('s1')
        ->and($array['at'])->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z\z/')
        ->and($array['actor'])->toBe(['kind' => 'system', 'id' => 'charlotte'])
        ->and($array['data'])->toBe(['status' => 'idle']);
})->with('logs');

test('seq is gap-free and ordered per session, independently for each session', function (Closure $make): void {
    $log = $make();
    foreach (range(1, 5) as $i) {
        $log->append('a', 'message.delta', ['kind' => 'agent', 'id' => 'charlotte'], ['text' => (string) $i]);
        if ($i % 2 === 0) {
            $log->append('b', 'message.delta', ['kind' => 'agent', 'id' => 'charlotte'], ['text' => 'b'.$i]);
        }
    }

    expect(array_map(fn (Event $e) => $e->seq, $log->after('a', 0)))->toBe([1, 2, 3, 4, 5])
        ->and(array_map(fn (Event $e) => $e->data['text'], $log->after('a', 0)))->toBe(['1', '2', '3', '4', '5'])
        ->and(array_map(fn (Event $e) => $e->seq, $log->after('b', 0)))->toBe([1, 2])
        ->and($log->latest('a'))->toBe(5)
        ->and($log->latest('nobody'))->toBe(0);
})->with('logs');

test('reading after a cursor resumes exactly where the client left off', function (Closure $make): void {
    $log = $make();
    foreach (range(1, 6) as $i) {
        $log->append('s', 'tool.call', ['kind' => 'agent', 'id' => 'charlotte'], ['n' => $i]);
    }

    expect(array_map(fn (Event $e) => $e->data['n'], $log->after('s', 4)))->toBe([5, 6])
        ->and(array_map(fn (Event $e) => $e->data['n'], $log->after('s', 0, 2)))->toBe([1, 2])
        ->and($log->after('s', 6))->toBe([]);
})->with('logs');

test('a malformed type or actor is refused before it is written', function (Closure $make, string $type, array $actor): void {
    $log = $make();
    try {
        $log->append('s', $type, $actor, []);
        test()->fail('Expected a refusal.');
    } catch (InvalidArgumentException) {
        expect($log->latest('s'))->toBe(0);
    }
})->with('logs')->with([
    'bad type' => ['Session Status', ['kind' => 'system', 'id' => 'x']],
    'bad actor kind' => ['session.status', ['kind' => 'root', 'id' => 'x']],
]);

test('an event written inside a rolled-back transaction leaves no trace', function (): void {
    TestApp::boot();
    $log = new DatabaseEventLog(DB::connection());
    try {
        DB::transaction(function () use ($log): void {
            $log->append('s', 'doc.changed', ['kind' => 'agent', 'id' => 'charlotte'], ['revision' => 'r1']);
            throw new RuntimeException('the mutation failed');
        });
    } catch (RuntimeException) {
    }

    expect($log->latest('s'))->toBe(0)
        ->and($log->append('s', 'doc.changed', ['kind' => 'agent', 'id' => 'charlotte'], [])->seq)->toBe(1);
});

test('long-poll answers at once when events already follow the cursor', function (Closure $make): void {
    $log = $make();
    $log->append('s', 'message.created', ['kind' => 'human', 'id' => 'u1'], ['text' => 'hi']);
    $sleeper = new RecordingSleeper;

    $page = (new LongPoll($log, $sleeper))->poll('s', 0, 20);

    expect($page['cursor'])->toBe(1)
        ->and(array_column($page['events'], 'type'))->toBe(['message.created'])
        ->and($sleeper->calls)->toBe(0);
})->with('logs');

test('long-poll waits, then wakes for an event that arrives during the wait', function (Closure $make): void {
    $log = $make();
    $log->append('s', 'session.status', ['kind' => 'system', 'id' => 'charlotte'], ['status' => 'running']);
    $sleeper = new RecordingSleeper(function (int $call) use ($log): void {
        if ($call === 3) {
            $log->append('s', 'doc.changed', ['kind' => 'agent', 'id' => 'charlotte'], ['revision' => 'r2']);
        }
    });

    $page = (new LongPoll($log, $sleeper, intervalMs: 250))->poll('s', 1, 20);

    expect(array_column($page['events'], 'type'))->toBe(['doc.changed'])
        ->and($page['cursor'])->toBe(2)
        ->and($sleeper->calls)->toBe(3);
})->with('logs');

test('long-poll returns empty with the same cursor when the wait expires, and never waits past 25 seconds', function (): void {
    $log = new InMemoryEventLog;
    $sleeper = new RecordingSleeper;

    $page = (new LongPoll($log, $sleeper))->poll('s', 7, 600);

    expect($page)->toBe(['events' => [], 'cursor' => 7])
        ->and($sleeper->slept)->toBeLessThanOrEqual(25_000)
        ->and($sleeper->slept)->toBeGreaterThan(24_000);
});

test('a zero wait is a plain read', function (): void {
    $sleeper = new RecordingSleeper;

    expect((new LongPoll(new InMemoryEventLog, $sleeper))->poll('s', 0, 0))->toBe(['events' => [], 'cursor' => 0])
        ->and($sleeper->calls)->toBe(0);
});

test('a long backlog is paged, and the cursor lets the client resume', function (): void {
    $log = new InMemoryEventLog;
    foreach (range(1, 5) as $i) {
        $log->append('s', 'message.delta', ['kind' => 'agent', 'id' => 'charlotte'], ['n' => $i]);
    }
    $poll = new LongPoll($log, new RecordingSleeper, limit: 2);

    $first = $poll->poll('s', 0, 0);
    $second = $poll->poll('s', $first['cursor'], 0);
    $third = $poll->poll('s', $second['cursor'], 0);

    expect(array_column(array_column($first['events'], 'data'), 'n'))->toBe([1, 2])
        ->and(array_column(array_column($second['events'], 'data'), 'n'))->toBe([3, 4])
        ->and(array_column(array_column($third['events'], 'data'), 'n'))->toBe([5])
        ->and($third['cursor'])->toBe(5);
});
