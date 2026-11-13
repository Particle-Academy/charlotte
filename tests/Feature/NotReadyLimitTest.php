<?php

declare(strict_types=1);

use Charlotte\SiteWorkspace;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tools\PageTools;
use Charlotte\Workspace;
use Prism\Prism\Enums\FinishReason;

/*
 * Prism maintainers' answer on repeated refusals: every not_ready defect is
 * concrete and locatable (a node id and the rule it breaks), and Charlotte
 * counts consecutive not_ready results itself. At the limit (3 by default,
 * charlotte.finish.max_not_ready) it stops: the result is not_ready_limit with
 * the remaining defects, and further edits and finishes are refused until new
 * work starts (a new turn, a new page sub-agent, a successful finish).
 */

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
});

/** A page with a heading only: it fails meta_description and responsive_layout. */
function barePage(\Charlotte\CharlotteSession $session): string
{
    return tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
}

test('every not_ready defect names a node and the rule it breaks', function (): void {
    $session = openSession();
    $rev = barePage($session);

    $defects = tool($session, 'cms_finish', ['basedOn' => $rev])['error']['defects'];
    $docId = json_decode(json_encode($session->state()['doc']), true)['id'];
    $byCode = [];
    foreach ($defects as $d) {
        $byCode[$d['code']][] = $d;
    }

    expect($defects)->not->toBe([]);
    foreach ($defects as $d) {
        expect($d['node'] ?? null)->toBeString()->not->toBe('')
            ->and($d['rule'] ?? null)->toBeString()->not->toBe('');
    }
    expect($byCode['meta_description'][0]['node'])->toBe('page:'.$docId)
        ->and($byCode['meta_description'][0]['field'])->toBe('meta.seo.description')
        // The page-wide responsive defect points at the content section to change, not at nothing.
        ->and(array_unique(array_column($byCode['responsive_layout'], 'node')))->toBe(['hero']);
});

test('the third consecutive refusal stops: not_ready_limit with the remaining defects, and no more retries', function (): void {
    $session = openSession();
    $rev = barePage($session);

    $first = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $second = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $third = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $edit = tool($session, 'cms_apply', ['ops' => [textOp('more', 'hero', 'More copy')], 'basedOn' => $rev]);
    $again = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $read = tool($session, 'cms_get');

    expect([$first['error']['code'], $second['error']['code']])->toBe(['not_ready', 'not_ready'])
        ->and($first['error']['refusals'])->toBe(1)
        ->and($third['error']['code'])->toBe('not_ready_limit')
        ->and($third['error']['refusals'])->toBe(3)
        ->and($third['error']['limit'])->toBe(3)
        ->and(array_column($third['error']['defects'], 'code'))->toContain('meta_description')
        ->and($third['error']['message'])->toContain('Stop')
        ->and($edit['error']['code'])->toBe('not_ready_limit')
        ->and($again['error']['code'])->toBe('not_ready_limit')
        ->and($read['revision'])->toBe($rev)
        ->and(eventsOf($session, 'draft.ready'))->toBe([]);
});

test('the limit is configurable', function (int $limit, array $codes): void {
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.finish.max_not_ready' => $limit]);
    $session = openSession();
    $rev = barePage($session);

    $seen = [];
    foreach (range(1, count($codes)) as $ignored) {
        $seen[] = tool($session, 'cms_finish', ['basedOn' => $rev])['error']['code'];
    }

    expect($seen)->toBe($codes);
})->with([
    'one' => [1, ['not_ready_limit', 'not_ready_limit']],
    'five' => [5, ['not_ready', 'not_ready', 'not_ready', 'not_ready', 'not_ready_limit']],
]);

test('refusals that are not consecutive do not add up: a successful finish starts the count again', function (): void {
    $session = openSession();
    $rev = barePage($session);
    tool($session, 'cms_finish', ['basedOn' => $rev]);
    tool($session, 'cms_finish', ['basedOn' => $rev]);
    $good = tool($session, 'cms_apply', ['ops' => [['t' => 'remove_node', 'id' => 'hero'], ...finishablePageOps()], 'basedOn' => $rev])['revision'];
    tool($session, 'cms_finish', ['basedOn' => $good]);
    $bad = tool($session, 'cms_apply', ['ops' => [['t' => 'set_meta', 'patch' => ['seo' => ['description' => '']]]], 'basedOn' => $good])['revision'];

    expect(tool($session, 'cms_finish', ['basedOn' => $bad])['error'])->toMatchArray(['code' => 'not_ready', 'refusals' => 1]);
});

test('a new turn starts the count again', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Hello.']]]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();
    $rev = barePage($session);
    foreach (range(1, 3) as $ignored) {
        tool($session, 'cms_finish', ['basedOn' => $rev]);
    }

    $session->send('Try again with the full brief.', 'user-7');

    expect(tool($session, 'cms_finish', ['basedOn' => $rev])['error'])->toMatchArray(['code' => 'not_ready', 'refusals' => 1]);
});

test('a page sub-agent at the limit gets its draft reference to return, and selecting a page starts the next child fresh', function (): void {
    $site = openSite();
    tool($site, 'site_page_create', ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']);
    $child = new PageTools(app(Workspace::class), $site->id(), app(SiteWorkspace::class), child: true);
    $rev = json_decode($child->call('cms_get'), true)['revision'];
    foreach (range(1, 2) as $ignored) {
        $child->call('cms_finish', ['basedOn' => $rev]);
    }

    $limit = json_decode($child->call('cms_finish', ['basedOn' => $rev]), true);
    tool($site, 'site_page_select', ['page' => 'home']);
    $fresh = json_decode($child->call('cms_finish', ['basedOn' => $rev]), true);

    expect($limit['error']['code'])->toBe('not_ready_limit')
        ->and($limit['error']['draft'])->toBe(['page' => 'home', 'revision' => $rev])
        ->and($fresh['error'])->toMatchArray(['code' => 'not_ready', 'refusals' => 1]);
});

test('two successive page children on the same page each get a fresh allowance: the count is per child run', function (): void {
    $finish = fn (array $calls) => ['basedOn' => json_decode(end($calls)['result'], true)['revision']];
    $child = [
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['text' => 'Stopped with defects.'],
    ];
    $provider = new ScriptedProvider([[
        ['tools' => [['name' => 'site_page_create', 'args' => ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']]]],
        // Two children on the same selected page, with no site_page_select between them.
        ['tools' => [['name' => \Charlotte\Guide\BuilderMode::SITE_SUBAGENT, 'args' => ['task' => 'Build the home page.']]]],
        ['tools' => [['name' => \Charlotte\Guide\BuilderMode::SITE_SUBAGENT, 'args' => ['task' => 'Try the home page again.']]]],
        ['text' => 'Done.'],
    ], $child, $child]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $site = openSite();

    $site->send('Build.', 'user-7');

    $finishes = array_map(fn (array $c) => json_decode($c['result'], true)['error'], array_values(array_filter($provider->calls, fn (array $c) => $c['name'] === 'cms_finish')));
    expect(array_column($finishes, 'code'))->toBe(['not_ready', 'not_ready', 'not_ready_limit', 'not_ready', 'not_ready', 'not_ready_limit'])
        ->and(array_column($finishes, 'refusals'))->toBe([1, 2, 3, 1, 2, 3]);
});

test('a turn at the limit is not continued after an output-limit stop', function (): void {
    $finish = fn (array $calls) => ['basedOn' => json_decode(end($calls)['result'], true)['revision']];
    $provider = new ScriptedProvider([[
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => ['ops' => sectionOps(), 'basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['tools' => [['name' => 'cms_finish', 'args' => $finish]]],
        ['text' => 'The page still has defects.', 'finish' => FinishReason::Length],
    ], [['text' => 'Never reached.']]]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();

    $session->send('Build it.', 'lab');

    expect($provider->requests)->toHaveCount(1)
        ->and(eventsOf($session, 'run.finished')[0]['data']['finishReason'])->toBe('length')
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1)
        ->and($session->record()->status)->toBe('idle');
});
