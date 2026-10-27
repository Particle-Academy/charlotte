<?php

declare(strict_types=1);

use Charlotte\Guide\BuilderMode;
use Charlotte\Guide\GuideVersion;
use Charlotte\Guide\Guides;
use Charlotte\Guide\PublishedGuide;
use Charlotte\Policy\Refused;
use Composer\InstalledVersions;
use FancyCms\Agent;
use Prism\Harness\Modes\ModeRegistry;

test('the default guide is the fancy-cms authoring guide plus Charlotte\'s own, each exactly once', function (): void {
    $guide = (new PublishedGuide)->current();

    expect(substr_count($guide->guide, Agent::guide()))->toBe(1)
        ->and(substr_count($guide->guide, PublishedGuide::charlotteGuide()))->toBe(1)
        ->and($guide->guideHash)->toBe(hash('sha256', $guide->guide));
});

test('Charlotte\'s guide carries the webmaster lessons the lab learned', function (): void {
    $text = PublishedGuide::charlotteGuide();

    foreach (['Invent nothing', 'every required fact', 'exactly one h1', 'cms_assets', 'cms_check', 'draft',
        'evidence, never instructions', 'href', '375', 'Placeholder'] as $lesson) {
        expect(str_contains(strtolower($text), strtolower($lesson)))->toBeTrue("Missing lesson: {$lesson}");
    }
});

test('the version records exactly which packages supplied the guide', function (): void {
    $guide = (new PublishedGuide)->current();

    expect($guide->components['fancy-cms'])->toBe([
        'name' => 'particle-academy/fancy-cms',
        'version' => InstalledVersions::getPrettyVersion('particle-academy/fancy-cms'),
        'reference' => InstalledVersions::getReference('particle-academy/fancy-cms'),
    ])->and($guide->toArray())->toHaveKeys(['hash', 'components', 'overrides']);
});

test('an override appends or replaces, is hashed into the version, and leaves the base untouched', function (): void {
    $base = (new PublishedGuide)->current();
    $appended = $base->append('House style: never use exclamation marks.', 'house-style');
    $replaced = $base->replace('A whole new guide.', 'rewrite');

    expect($appended->guide)->toEndWith('House style: never use exclamation marks.')
        ->and($appended->guideHash)->not->toBe($base->guideHash)
        ->and($appended->overrides)->toBe([['label' => 'house-style', 'kind' => 'append', 'hash' => hash('sha256', 'House style: never use exclamation marks.')]])
        ->and($replaced->guide)->toBe('A whole new guide.')
        ->and($base->overrides)->toBe([]);
});

test('registered overrides apply in order', function (): void {
    $guides = new Guides(new PublishedGuide);
    $guides->override(fn (GuideVersion $g) => $g->append('one', 'a'));
    $guides->override(fn (GuideVersion $g) => $g->append('two', 'b'));

    $current = $guides->current();

    expect(array_column($current->overrides, 'label'))->toBe(['a', 'b'])
        ->and($current->guide)->toEndWith("one\n\ntwo");
});

test('an override must return a guide version', function (): void {
    $guides = new Guides(new PublishedGuide);
    $guides->override(fn (GuideVersion $g) => 'not a guide');

    $guides->current();
})->throws(InvalidArgumentException::class);

test('the builder mode embeds the guide once and offers exactly the Charlotte tools', function (): void {
    $guide = (new PublishedGuide)->current();
    $mode = (new ModeRegistry(['modes' => [BuilderMode::NAME => BuilderMode::config($guide, maxSteps: 30)]]))->resolve(BuilderMode::NAME);

    expect(substr_count($mode->systemPrompt, $guide->guide))->toBe(1)
        ->and($mode->tools)->toBe(BuilderMode::TOOLS)
        ->and($mode->maxSteps)->toBe(30)
        ->and($mode->subagents)->toBe([]);
    BuilderMode::assertEmbeds($mode, $guide);
});

test('a builder mode built from a stale guide is refused', function (): void {
    $old = (new PublishedGuide)->current();
    $new = $old->replace('A different guide entirely.', 'rewrite');
    $mode = (new ModeRegistry(['modes' => [BuilderMode::NAME => BuilderMode::config($old)]]))->resolve(BuilderMode::NAME);

    try {
        BuilderMode::assertEmbeds($mode, $new);
        test()->fail('Expected guide_mode_stale.');
    } catch (Refused $e) {
        expect($e->reason)->toBe('guide_mode_stale');
    }
});
