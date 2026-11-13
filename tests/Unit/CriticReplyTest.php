<?php

declare(strict_types=1);

use Charlotte\Critique\CriticReply;

/*
 * The critic's reply, parsed. A reply cut off at max_tokens keeps every item
 * that was complete before the cut, and never one invented from the partial
 * item after it. Flabs runs 579 and 580: both Haiku 5.5 critic replies hit the
 * 2048-token cap mid-item (1408 and 1338 of those tokens were thinking), the
 * whole reply was refused as invalid_output, and no revision ever ran.
 */

test('run 579\'s real reply, cut off mid-item, keeps its four complete items and drops the partial fifth', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__).'/fixtures/critic-reply-run-579.txt');
    expect(json_decode($text, true))->toBeNull('the fixture really is cut off');

    $reply = CriticReply::parse($text, truncated: true);

    expect($reply)->not->toBeNull()
        ->and($reply['verdict'])->toBe('revise')
        ->and($reply['truncated'])->toBeTrue()
        ->and(array_column($reply['items'], 'node'))->toBe(['hero-shape', 'hero-card', 'hero-card', 'hero-shape'])
        ->and(array_column($reply['items'], 'region'))->toBe(['1280-1', '1280-1', '768-1', '375-2'])
        ->and(json_encode($reply['items']))->not->toContain('plan-solo-btn');
});

test('the same reply is salvaged even when the provider did not report the cut', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__).'/fixtures/critic-reply-run-579.txt');

    expect(CriticReply::parse($text))->toMatchArray(['verdict' => 'revise', 'truncated' => true])
        ->and(CriticReply::parse($text)['items'])->toHaveCount(4);
});

test('a complete reply is not truncated, even with prose or a code fence around it', function (): void {
    $json = '{"verdict": "revise", "items": [{"node": "a", "viewport": 375, "issue": "x", "fix": "y"}]}';

    expect(CriticReply::parse($json))->toBe(['verdict' => 'revise', 'items' => [['node' => 'a', 'viewport' => 375, 'issue' => 'x', 'fix' => 'y']], 'truncated' => false])
        ->and(CriticReply::parse("```json\n".$json."\n```")['items'])->toHaveCount(1)
        ->and(CriticReply::parse('{"verdict": "pass", "items": []}'))->toBe(['verdict' => 'pass', 'items' => [], 'truncated' => false]);
});

test('braces and quotes inside strings never close an item early', function (): void {
    $text = '{"verdict": "revise", "items": [{"node": "a", "viewport": 375, "issue": "a } brace and a \" quote", "fix": "set {gap: 8px}"}, {"node": "b", "issue": "cut } he';

    $reply = CriticReply::parse($text, truncated: true);

    expect($reply['items'])->toHaveCount(1)
        ->and($reply['items'][0]['issue'])->toBe('a } brace and a " quote')
        ->and($reply['items'][0]['fix'])->toBe('set {gap: 8px}');
});

test('nothing complete, or no verdict before the cut, is no usable critique', function (): void {
    expect(CriticReply::parse('{"verdict": "revise", "items": [{"node": "a", "iss', truncated: true))
        ->toBe(['verdict' => 'revise', 'items' => [], 'truncated' => true])
        ->and(CriticReply::parse('{"items": [{"node": "a", "issue": "x"}], "verd', truncated: true))->toBeNull()
        ->and(CriticReply::parse('{"verd', truncated: true))->toBeNull()
        ->and(CriticReply::parse('Looks great.'))->toBeNull()
        ->and(CriticReply::parse('{"verdict": "maybe", "items": []}'))->toBeNull();
});
