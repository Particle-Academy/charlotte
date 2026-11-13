<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\Design\Examples;
use Charlotte\Facades\Charlotte;
use Charlotte\Guide\BuilderMode;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\User;
use Prism\Harness\Facades\PrismHarness;

/*
 * How a session's two examples reach the model: outside the static guide, so
 * the guide hash and the cached system prompt are the same for every session,
 * and never in a tool result (tool results are framed as data). A page
 * session's first run carries them ahead of the person's message; a page
 * SUB-AGENT's fresh conversation opens with them.
 */

/** The text of every message in a request. */
function messageTexts(\Prism\Prism\Text\Request $request): array
{
    return array_map(fn ($m) => $m->content, $request->messages());
}

test('a session records its two sampled examples when it opens', function (): void {
    charlotteApp();
    $session = openSession();
    $site = openSite();

    expect($session->record()->examples())->toBe(Examples::sample($session->id()))
        ->and($session->record()->guide['examples'])->toBe(Examples::sample($session->id()))
        ->and($site->record()->examples())->toBe(Examples::sample($site->id()));
});

test('a page session\'s first run carries its examples ahead of the person\'s message, once; later turns replay it from the thread', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Built.']], [['text' => 'Warmer.']]]);
    charlotteApp([], $provider);
    $session = openSession();
    $ids = $session->record()->examples();

    $session->send('Build the page.', 'user-7');
    $session->send('Make it warmer.', 'user-7');

    $first = messageTexts($provider->requests[0]);
    $second = messageTexts($provider->requests[1]);

    expect($first)->toBe([Examples::delivered($ids, 'Build the page.')])
        ->and($first[0])->toStartWith(Examples::promptSection($ids))
        ->and($first[0])->toEndWith('Build the page.')
        ->and($second[0])->toBe($first[0])
        ->and(end($second))->toBe('Make it warmer.')
        ->and(substr_count(implode("\n", $second), Examples::promptSection($ids)))->toBe(1)
        // The log keeps the person's own words, and every run names the examples it ran with.
        ->and(array_column(array_column(eventsOf($session, 'message.created'), 'data'), 'text'))->toBe(['Build the page.', 'Make it warmer.'])
        ->and(array_column(array_column(eventsOf($session, 'run.started'), 'data'), 'examples'))->toBe([$ids, $ids])
        ->and(eventsOf($session, 'run.failed'))->toBe([]);
});

test('the system prompt is the same for sessions with different examples, and carries none of them', function (): void {
    $provider = new ScriptedProvider([[['text' => 'One.']], [['text' => 'Two.']]]);
    charlotteApp([], $provider);
    $a = openSession();
    $b = null;
    for ($i = 0; $i < 30 && $b === null; $i++) {
        $candidate = openSession(['page' => 'page-'.$i]);
        if ($candidate->record()->examples() !== $a->record()->examples()) {
            $b = $candidate;
        }
    }
    expect($b)->not->toBeNull();

    $a->send('Go.', 'user-7');
    $b->send('Go.', 'user-7');
    $system = fn (int $i): string => implode("\n", array_map(fn ($m) => $m->content, $provider->requests[$i]->systemPrompts()));

    expect($system(0))->toBe($system(1))
        ->and($a->record()->guideHash)->toBe($b->record()->guideHash);
    foreach (Examples::motifs() as $motif) {
        expect($system(0))->not->toContain('"id":"'.$motif['id'].'"');
    }
});

test('a site\'s page sub-agent opens every fresh conversation with the session\'s examples; the orchestrator never gets them', function (): void {
    $provider = new ScriptedProvider([
        [
            ['tools' => [['name' => 'site_page_create', 'args' => ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']]]],
            ['tools' => [['name' => BuilderMode::SITE_SUBAGENT, 'args' => ['task' => 'Build the home page from the brief.']]]],
            ['tools' => [['name' => 'site_page_create', 'args' => ['page' => 'pricing', 'title' => 'Pricing', 'kind' => 'page', 'route' => '/pricing']]]],
            ['tools' => [['name' => BuilderMode::SITE_SUBAGENT, 'args' => ['task' => 'Build the pricing page from the brief.']]]],
            ['text' => 'Both pages are drafted.'],
        ],
        childBuild('Acme Ledger'),
        childBuild('Pricing'),
    ]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $site = Charlotte::for(User::create(['name' => 'Ada']))->tenant('acme')->site('acme-site')->brief(Brief::fromArray(briefArray()))->open();
    $section = Examples::promptSection($site->record()->examples());

    $site->send('Build the site.', 'user-7');

    expect(messageTexts($provider->textRequests[0]))->toBe([$section, 'Build the home page from the brief.'])
        ->and(messageTexts($provider->textRequests[4]))->toBe([$section, 'Build the pricing page from the brief.'])
        ->and(messageTexts($provider->requests[0]))->toBe(['Build the site.'])
        ->and(eventsOf($site, 'run.started')[0]['data']['examples'])->toBe($site->record()->examples());
});

test('a host\'s delegated Charlotte sub-agent opens with its session\'s examples too', function (): void {
    $provider = new ScriptedProvider([
        [['tools' => [['name' => 'charlotte', 'args' => ['task' => 'Build the Q3 pipeline dashboard from the brief.']]]], ['text' => 'Drafted.']],
        childBuild('Q3 pipeline'),
    ]);
    charlotteApp([
        'charlotte.autonomy' => 'full',
        'prism-harness.agent.provider' => 'scripted',
        'prism-harness.agent.model' => 'test-model',
        'prism-harness.agent.modes' => ['host_reports' => [
            'system_prompt' => 'You are the reports assistant.', 'tools' => [], 'skills' => [], 'max_steps' => 60,
            'subagents' => ['charlotte' => BuilderMode::subagent()],
        ]],
    ], $provider);
    $user = User::create(['name' => 'Ada']);
    $parent = PrismHarness::session($user, 'reports:42')->usingMode('host_reports');

    $page = Charlotte::delegate($user, $parent->scope(), 'acme', 'q3-dashboard', Brief::fromArray(briefArray()));
    // Binding again (a host delegating a second time) never stacks a second copy.
    $page->bindAsSubagent($parent->scope());
    $parent->send('Make me a Q3 dashboard.');

    // textRequests[0] is the host's own agent; [1] is Charlotte's first call.
    expect(messageTexts($provider->textRequests[0]))->toBe(['Make me a Q3 dashboard.'])
        ->and(messageTexts($provider->textRequests[1]))->toBe([Examples::promptSection($page->record()->examples()), 'Build the Q3 pipeline dashboard from the brief.']);
});
