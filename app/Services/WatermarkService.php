<?php

namespace App\Services;

use Intervention\Image\Image;
use Intervention\Image\Typography\FontFactory;
use Illuminate\Support\Facades\Log;

class WatermarkService
{
    protected string $fontPath;

    public function __construct()
    {
        $this->fontPath = storage_path('fonts/OpenSans-Bold.ttf');
    }

    /**
     * Apply the BCFloorPlans watermark to the given image.
     */
    public function apply(Image $image, ?string $customText = null): Image
    {
        $text = !empty(trim($customText ?? '')) ? trim($customText) : 'Tojuco Solutions';
        $width = $image->width();
        $height = $image->height();

        Log::debug("[WATERMARK] START text='{$text}' dims={$width}x{$height}");

        // 1. Check if font exists, otherwise skip watermarking gracefully
        if (!file_exists($this->fontPath)) {
            Log::critical("[WATERMARK] FONT MISSING: {$this->fontPath}. Skipping watermark to allow variant generation.");
            return $image;
        }

        // 1. Calculate dynamic font sizes
        // Double the scale to 6% for large images, leaving thumbnails (<= 300px) untouched at 16px
        if ($width <= 300) {
            $primarySize = 16;
        } else {
            $primarySize = max(32, min(200, $width * 0.06));
        }
        $secondarySize = $primarySize * 0.6;

        // 2. Center Position only (Removing 4 Quadrants as requested)
        $centerPos = ['x' => $width / 2, 'y' => $height / 2];
        $this->drawSingleLineWatermark($image, $centerPos['x'], $centerPos['y'], $primarySize, $text);

        // 3. Draw Corner Badges (Kept per request: "4 watermarks on corner are fine")
        $this->drawCornerBadges($image, $width, $height, $text);

        $memory = round(memory_get_usage() / 1024 / 1024, 2);
        Log::debug("[WATERMARK] APPLIED text='{$text}' center=1 corners=4 | memory={$memory}MB");

        return $image;
    }

    /**
     * Draw a single line watermark with 45 degree rotation and shadow.
     */
    protected function drawSingleLineWatermark(Image $image, float $x, float $y, float $size, string $text = 'Tojuco Solutions'): void
    {
        $angle = 45; // 45 degrees counter-clockwise

        // Draw Shadow first (Offset 2px)
        $this->renderTextLine($image, $text, $x + 2, $y + 2, $size, '000000', 0.4, $angle);

        // Draw Main Text
        $this->renderTextLine($image, $text, $x, $y, $size, 'ffffff', 0.65, $angle);
    }

    /**
     * Render a single line of text with specific style.
     */
    protected function renderTextLine(Image $image, string $text, float $x, float $y, float $size, string $color, float $opacity, float $angle): void
    {
        $image->text($text, $x, $y, function (FontFactory $font) use ($size, $color, $opacity, $angle) {
            $font->file($this->fontPath);
            $font->size($size);
            $font->color($color);
            $font->stroke($color, 0); // Ensure no stroke unless requested
            $font->align('center');
            $font->valign('middle');
            $font->angle($angle);

            // Opacity is handled by the color if passed as hex with alpha or rgba
            // In v3, color('ffffff') sets solid. We can use rgba for transparency.
            $rgb = $this->hexToRgb($color);
            $font->color(sprintf('rgba(%d, %d, %d, %f)', $rgb[0], $rgb[1], $rgb[2], $opacity));
        });
    }

    /**
     * Draw corner badges for secondary protection.
     */
    protected function drawCornerBadges(Image $image, int $width, int $height, string $badgeText = 'Tojuco Solutions'): void
    {
        // Double the scale to 3% for large images, leaving thumbnails (<= 300px) untouched at 14px
        if ($width <= 300) {
            $badgeFontSize = 14;
        } else {
            $badgeFontSize = max(20, $width * 0.03);
        }
        $padding = 10;

        // Badge dimensions (estimate based on text length)
        $rectWidth = max($badgeFontSize * 4, $badgeFontSize * (mb_strlen($badgeText) * 0.65));
        $rectHeight = $badgeFontSize * 1.5;

        $corners = [
            ['x' => $padding, 'y' => $padding], // Top-Left
            ['x' => $width - $rectWidth - $padding, 'y' => $padding], // Top-Right
            ['x' => $padding, 'y' => $height - $rectHeight - $padding], // Bottom-Left
            ['x' => $width - $rectWidth - $padding, 'y' => $height - $rectHeight - $padding], // Bottom-Right
        ];

        foreach ($corners as $c) {
            // Draw background rectangle (Black 30%)
            $image->drawRectangle($c['x'], $c['y'], function ($draw) use ($rectWidth, $rectHeight) {
                $draw->size($rectWidth, $rectHeight);
                $draw->background('rgba(0, 0, 0, 0.3)');
            });

            // Draw text in center of rectangle
            $image->text($badgeText, $c['x'] + ($rectWidth / 2), $c['y'] + ($rectHeight / 2), function (FontFactory $font) use ($badgeFontSize) {
                $font->file($this->fontPath);
                $font->size($badgeFontSize);
                $font->color('rgba(255, 255, 255, 1)');
                $font->align('center');
                $font->valign('middle');
            });
        }
    }

    protected function hexToRgb($hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $val = hexdec($hex);
        return [($val >> 16) & 0xff, ($val >> 8) & 0xff, $val & 0xff];
    }
}
