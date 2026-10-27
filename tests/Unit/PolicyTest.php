<?php

declare(strict_types=1);

use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Policy\AssetPolicy;
use Charlotte\Policy\EmptyAssetCatalogue;
use Charlotte\Policy\LinkPolicy;
use Charlotte\Policy\Refused;

function imageDoc(array $props, array $style = []): array
{
    return ['id' => 'p', 'seq' => 0, 'meta' => [], 'theme' => [], 'breakpoints' => ['base'], 'nodes' => [
        'img' => ['id' => 'img', 'type' => 'image', 'parent' => null, 'order' => 'a0', 'props' => $props, 'style' => ['base' => $style]],
    ]];
}

function refusalOf(Closure $fn): ?string
{
    try {
        $fn();

        return null;
    } catch (Refused $e) {
        return $e->reason;
    }
}

test('an image may only name a handle the host catalogue knows', function (): void {
    $catalogue = new ArrayAssetCatalogue(['brief-hero' => ['alt' => 'A truck at dusk', 'width' => 1600, 'height' => 1067]]);

    expect(refusalOf(fn () => AssetPolicy::assert(imageDoc(['asset' => 'brief-hero', 'alt' => 'x']), $catalogue)))->toBeNull()
        ->and(refusalOf(fn () => AssetPolicy::assert(imageDoc(['asset' => 'invented', 'alt' => 'x']), $catalogue)))->toBe('image_source_refused')
        ->and(refusalOf(fn () => AssetPolicy::assert(imageDoc(['asset' => 'brief-hero']), new EmptyAssetCatalogue)))->toBe('image_source_refused');
});

test('an authored image URL or intrinsic dimension is refused', function (array $props): void {
    expect(refusalOf(fn () => AssetPolicy::assert(imageDoc($props), new EmptyAssetCatalogue)))->toBe('image_source_refused');
})->with([
    'src' => [['src' => 'https://tracker.example/pixel.gif', 'alt' => 'x']],
    'srcset' => [['srcSet' => 'a.jpg 1x', 'alt' => 'x']],
    'width' => [['width' => 10, 'alt' => 'x']],
]);

test('CSS that fetches is refused anywhere in the document', function (string $css): void {
    expect(refusalOf(fn () => AssetPolicy::assert(imageDoc([], ['background' => $css]), new EmptyAssetCatalogue)))->toBe('image_source_refused');
})->with(['url(https://a.example/x.png)', 'image-set("a.png" 1x)', '@import "x"', 'red /* c */', 'u\\72l(x)']);

test('an empty image placeholder is allowed', function (): void {
    expect(refusalOf(fn () => AssetPolicy::assert(imageDoc(['alt' => '']), new EmptyAssetCatalogue)))->toBeNull();
});

test('the catalogue an agent sees is the host catalogue, without any URL', function (): void {
    $catalogue = new ArrayAssetCatalogue(['brief-hero' => ['alt' => 'A truck at dusk', 'width' => 1600, 'height' => 1067, 'src' => '/media/secret-path.jpg']]);

    expect($catalogue->catalogue())->toBe([
        ['handle' => 'brief-hero', 'alt' => 'A truck at dusk', 'width' => 1600, 'height' => 1067],
    ]);
});

test('agent links to external origins must be allowlisted', function (): void {
    $policy = new LinkPolicy(['https://example.com']);
    $ops = fn (string $href) => [['t' => 'insert_node', 'node' => ['id' => 'l', 'type' => 'link', 'parent' => null, 'order' => 'a0', 'props' => ['content' => 'x', 'href' => $href], 'style' => ['base' => []]]]];

    expect(refusalOf(fn () => $policy->assertOps($ops('/pricing'))))->toBeNull()
        ->and(refusalOf(fn () => $policy->assertOps($ops('#plans'))))->toBeNull()
        ->and(refusalOf(fn () => $policy->assertOps($ops('mailto:hello@example.com'))))->toBeNull()
        ->and(refusalOf(fn () => $policy->assertOps($ops('https://example.com/docs'))))->toBeNull()
        ->and(refusalOf(fn () => $policy->assertOps($ops('https://evil.example.net/'))))->toBe('link_refused')
        ->and(refusalOf(fn () => $policy->assertOps($ops('//evil.example.net/'))))->toBe('link_refused')
        ->and(refusalOf(fn () => $policy->assertOps($ops('javascript:alert(1)'))))->toBe('link_refused');
});

test('hrefs are found in prop patches and navigate actions too', function (): void {
    $policy = new LinkPolicy([]);

    expect(refusalOf(fn () => $policy->assertOps([['t' => 'set_node_props', 'id' => 'b', 'patch' => ['href' => 'https://x.example']]])))->toBe('link_refused')
        ->and(refusalOf(fn () => $policy->assertOps([['t' => 'set_action', 'id' => 'b', 'index' => 0, 'action' => ['on' => 'click', 'do' => ['kind' => 'navigate', 'href' => 'https://x.example']]]])))->toBe('link_refused');
});
