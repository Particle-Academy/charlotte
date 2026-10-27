<?php

declare(strict_types=1);

namespace Charlotte\Guide;

/** Where the builder's guide comes from. Bind your own to swap the base entirely. */
interface GuideSource
{
    public function current(): GuideVersion;
}
