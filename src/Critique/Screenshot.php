<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use InvalidArgumentException;
use Prism\Harness\Support\TurnAttachments;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * One rendered capture of a page at one CSS width, as the host's
 * {@see PageRenderer} returns it. Charlotte renders nothing itself and adds no
 * browser dependency: the host supplies the image it already captures.
 *
 * A capture is ONE VIEWPORT at NATIVE resolution: the image is exactly the
 * CSS width times a whole device pixel ratio (1 to 3) wide, never scaled
 * down. A page taller than one viewport comes back as several captures at
 * the same width, `part` 1, 2, ... from the top (sections). One tall strip
 * squeezed to fit is what this refuses: its text is too small for a critic to
 * read, which is the defect the Prism maintainers flagged.
 *
 * The image must carry its bytes (never a URL, a path or a provider file id):
 * the header is read to check the resolution, and the harness's own
 * attachment rules ({@see TurnAttachments}) are applied here, so nothing it
 * would refuse is ever built.
 */
final readonly class Screenshot
{
    /** A capture larger than this is refused rather than sent. */
    public const MAX_BYTES = 8_000_000;

    /** The largest device pixel ratio a native capture may use. */
    public const MAX_SCALE = 3;

    public int $pixelWidth;

    public int $pixelHeight;

    /** The device pixel ratio: pixel width / CSS width. */
    public int $scale;

    public function __construct(public int $width, public Image $image, public int $part = 1)
    {
        if ($width < 200 || $width > 4000) {
            throw new InvalidArgumentException('A screenshot names the CSS width it was captured at.');
        }
        if ($part < 1 || $part > 50) {
            throw new InvalidArgumentException('A screenshot section is numbered from 1, top of the page first.');
        }
        // DUPLICATE OF THE HARNESS'S MEDIA BOUNDS, until G-265: delegated, not copied.
        TurnAttachments::admit('screenshot', [$image]);
        $bytes = $image->rawContent();
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A screenshot carries its image bytes, at most '.self::MAX_BYTES.'.');
        }
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || ! is_int($size[0] ?? null) || ! is_int($size[1] ?? null) || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidArgumentException('A screenshot is a PNG, JPEG or WebP image.');
        }
        if ($size[0] % $width !== 0 || intdiv($size[0], $width) < 1 || intdiv($size[0], $width) > self::MAX_SCALE) {
            throw new InvalidArgumentException('A screenshot is captured at native resolution: its pixel width is the CSS width times a whole device pixel ratio of 1 to '
                .self::MAX_SCALE.'. Capture one viewport (or one section of a tall page) at a time, never a whole page scaled down.');
        }
        $this->pixelWidth = $size[0];
        $this->pixelHeight = $size[1];
        $this->scale = intdiv($size[0], $width);
    }

    public static function png(int $width, string $bytes, int $part = 1): self
    {
        return new self($width, Image::fromRawContent($bytes, 'image/png'), $part);
    }

    /** The capture's height in CSS pixels. */
    public function cssHeight(): int
    {
        return (int) ceil($this->pixelHeight / $this->scale);
    }

    /** How the critic and the builder name this capture: `<width>-<part>`, e.g. `375-2`. */
    public function label(): string
    {
        return $this->width.'-'.$this->part;
    }

    /** @return array{label: string, part: int, pixelHeight: int, pixelWidth: int, scale: int, width: int} */
    public function describe(): array
    {
        return ['label' => $this->label(), 'part' => $this->part, 'pixelHeight' => $this->pixelHeight,
            'pixelWidth' => $this->pixelWidth, 'scale' => $this->scale, 'width' => $this->width];
    }
}
