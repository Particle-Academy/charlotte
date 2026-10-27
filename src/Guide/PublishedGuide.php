<?php

declare(strict_types=1);

namespace Charlotte\Guide;

use Composer\InstalledVersions;
use FancyCms\Agent;
use RuntimeException;

/**
 * The default guide: what Composer installed, with no private variant.
 *
 * fancy-cms's authoring guide (`Agent::guide()`), then Charlotte's own
 * `agent-guide/charlotte.md`: drafts, autonomy, pinning writes, content truth
 * and the webmaster lessons the flabs Sites lab measured.
 */
final class PublishedGuide implements GuideSource
{
    public function current(): GuideVersion
    {
        $components = [];
        foreach (['charlotte' => 'particle-academy/charlotte', 'fancy-cms' => 'particle-academy/fancy-cms'] as $key => $package) {
            $installed = InstalledVersions::isInstalled($package);
            $components[$key] = [
                'name' => $package,
                'version' => $installed ? InstalledVersions::getPrettyVersion($package) : null,
                'reference' => $installed ? InstalledVersions::getReference($package) : null,
            ];
        }

        return new GuideVersion(Agent::guide()."\n\n".self::charlotteGuide(), $components);
    }

    public static function charlotteGuide(): string
    {
        $path = dirname(__DIR__, 2).'/agent-guide/charlotte.md';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false || trim($text) === '') {
            throw new RuntimeException('[charlotte] agent-guide/charlotte.md is missing from this install.');
        }

        return $text;
    }
}
