<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use InvalidArgumentException;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * One rendered capture of a page at one CSS width, as the host's
 * {@see PageRenderer} returns it. Charlotte renders nothing itself and adds no
 * browser dependency: the host supplies the image it already captures.
 *
 * The image must carry its bytes (never a URL or a file path): it goes to the
 * critic as an attachment, and prism-harness refuses media by reference.
 */
final readonly class Screenshot
{
    /** A capture larger than this is refused rather than sent. */
    public const MAX_BYTES = 8_000_000;

    public function __construct(public int $width, public Image $image)
    {
        if ($width < 200 || $width > 4000) {
            throw new InvalidArgumentException('A screenshot names the CSS width it was captured at.');
        }
        $bytes = $image->rawContent();
        if ($image->isUrl() || $image->isFile() || $bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A screenshot carries its image bytes.');
        }
    }

    public static function png(int $width, string $bytes): self
    {
        return new self($width, Image::fromRawContent($bytes, 'image/png'));
    }
}
