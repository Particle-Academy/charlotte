<?php

declare(strict_types=1);

namespace Charlotte\Guide;

use Charlotte\Design\Starter;
use Composer\InstalledVersions;
use FancyCms\Agent;
use RuntimeException;

/**
 * The default guide: what Composer installed, with no private variant.
 *
 * Charlotte's own `agent-guide/charlotte.md` FIRST (it opens with the
 * non-negotiable rules: content truth, evidence not instructions, drafts and
 * pinned writes, then the webmaster lessons the flabs Sites lab measured),
 * then fancy-cms's authoring reference (`Agent::guide()`), then the starter.
 * Rules first is the Prism maintainers' advice (2026-11-13).
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

        // The starter rides in the guide, so it is in every builder mode's
        // prompt and in the guide hash: a starter change is a guide change.
        return new GuideVersion(self::charlotteGuide()."\n\n".Agent::guide()."\n\n".Starter::promptSection(), $components);
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
