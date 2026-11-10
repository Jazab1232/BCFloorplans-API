<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailTemplateSanitizerService
{
    /**
     * Properties injected by browser clipboard / Chrome that add dead weight to email HTML.
     */
    protected array $browserJunkCssProperties = [
        'box-sizing',
        'overflow',
        'text-indent',
        'text-transform',
        'font-variant',
        'font-variant-ligatures',
        'font-variant-caps',
        'font-variant-numeric',
        'font-variant-east-asian',
        'font-variant-alternates',
        'font-variant-position',
        'letter-spacing',
        'word-spacing',
        'orphans',
        'widows',
        'white-space',
        'border-image',
        'border-image-source',
        'border-image-slice',
        'border-image-width',
        'border-image-outset',
        'border-image-repeat',
        'text-decoration-thickness',
        'text-decoration-style',
        'text-decoration-color',
        'text-decoration-line',
        'margin-block',
        'margin-block-start',
        'margin-block-end',
        'margin-inline',
        'margin-inline-start',
        'margin-inline-end',
        'padding-block',
        'padding-block-start',
        'padding-block-end',
        'padding-inline',
        'padding-inline-start',
        'padding-inline-end',
    ];

    /**
     * Sanitize HTML content by extracting Base64 images to cloud storage,
     * stripping browser clipboard & Tailwind CSS bloat, and minifying structure.
     *
     * @param string|null $html
     * @return string
     */
    public function sanitize(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // 1. Extract and store any inline Base64 images to S3 / storage
        $html = $this->extractAndStoreBase64Images($html);

        // 2. Migrate any existing localhost storage URLs to public S3 URLs if available
        $html = $this->migrateLocalhostStorageUrlsToS3($html);

        // 3. Strip SunEditor internal metadata attributes (data-size, data-rotate, etc.)
        $html = $this->stripEditorMetadataAttributes($html);

        // 4. Strip browser clipboard style junk & Tailwind CSS variables
        $html = $this->stripCssBloat($html);

        // 5. Minify HTML whitespace
        $html = $this->minifyHtml($html);

        return $html;
    }

    /**
     * Check if HTML content contains inline Base64 image data.
     */
    public function hasBase64Images(string $html): bool
    {
        return (bool) preg_match('/data:image\/[a-zA-Z0-9\+\-\.]+;base64,/i', $html);
    }

    /**
     * Check if HTML content contains Tailwind CSS variable bloat.
     */
    public function hasTailwindBloat(string $html): bool
    {
        return (bool) preg_match('/--tw-[a-z0-9_-]+:/i', $html);
    }

    /**
     * Check if HTML is considered bloated (> 25KB or has Base64/Tailwind).
     */
    public function isBloated(string $html): bool
    {
        return strlen($html) > 25 * 1024 || $this->hasBase64Images($html) || $this->hasTailwindBloat($html);
    }

    /**
     * Extract all inline Base64 images, save them to storage (S3 with local fallback),
     * and replace the Base64 data with the hosted public URL.
     */
    public function extractAndStoreBase64Images(string $html): string
    {
        if (!$this->hasBase64Images($html)) {
            return $html;
        }

        $pattern = '/data:image\/([a-zA-Z0-9\+\-\.]+);base64,([A-Za-z0-9+\/=\s]+)/i';

        return preg_replace_callback($pattern, function ($matches) {
            $rawMime = strtolower($matches[1]);
            $base64Data = preg_replace('/\s+/', '', $matches[2]);
            $binary = base64_decode($base64Data, true);

            if ($binary === false || strlen($binary) === 0) {
                // Invalid base64, return original
                return $matches[0];
            }

            // Normalize file extension
            $extension = match ($rawMime) {
                'jpeg', 'pjpeg' => 'jpg',
                'svg+xml'       => 'svg',
                'x-icon'        => 'ico',
                default         => $rawMime,
            };

            // Deduplicate by hashing the binary content
            $hash = md5($binary);
            $fileName = "{$hash}.{$extension}";
            $path = "email-assets/{$fileName}";

            $url = $this->storeAsset($path, $binary);

            return $url ?: $matches[0];
        }, $html);
    }

    /**
     * Store binary data to S3 or public disk without existence check (idempotent PUT).
     */
    protected function storeAsset(string $path, string $binary): ?string
    {
        $hasS3 = !empty(config('filesystems.disks.s3.bucket')) && !empty(config('filesystems.disks.s3.key'));
        $preferredDisk = $hasS3 ? 's3' : (config('filesystems.default') ?: 'public');

        // Try S3 first if configured
        if ($hasS3) {
            try {
                $disk = Storage::disk('s3');
                $disk->put($path, $binary, 'public');
                return $disk->url($path);
            } catch (\Throwable $e) {
                Log::warning("EmailTemplateSanitizer: S3 put failed: " . $e->getMessage());
            }
        }

        // Fallback to local public disk
        try {
            $publicDisk = Storage::disk('public');
            $publicDisk->put($path, $binary, 'public');
            return $publicDisk->url($path);
        } catch (\Throwable $e) {
            Log::error("EmailTemplateSanitizer: Public disk put also failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Convert any localhost storage URLs (http://localhost:.../storage/email-assets/...)
     * to public S3 URLs if S3 is available and file exists on disk.
     */
    public function migrateLocalhostStorageUrlsToS3(string $html): string
    {
        $hasS3 = !empty(config('filesystems.disks.s3.bucket')) && !empty(config('filesystems.disks.s3.key'));
        if (!$hasS3) {
            return $html;
        }

        $pattern = '/(?:https?:\/\/[^\/"\'>\s]+)?\/storage\/(email-assets\/[a-zA-Z0-9_\-\.]+)/i';

        return preg_replace_callback($pattern, function ($m) {
            $path = $m[1];
            try {
                if (Storage::disk('public')->exists($path)) {
                    $binary = Storage::disk('public')->get($path);
                    Storage::disk('s3')->put($path, $binary, 'public');
                }
                return Storage::disk('s3')->url($path);
            } catch (\Throwable $e) {
                Log::warning("EmailTemplateSanitizer: Failed migrating {$path} to S3: " . $e->getMessage());
                return $m[0];
            }
        }, $html);
    }

    /**
     * Remove SunEditor internal data attributes from tags.
     */
    public function stripEditorMetadataAttributes(string $html): string
    {
        $html = preg_replace('/\s+data-(?:rotate|proportion|size|align|file-name|file-size|origin|index|container)[^=]*="[^"]*"/i', '', $html);
        $html = preg_replace('/\s+origin-size="[^"]*"/i', '', $html);
        return $html;
    }

    /**
     * Parse inline style declarations, strip browser clipboard noise & Tailwind variables,
     * and retain essential styling (font-size, color, font-weight, background, border, etc.).
     */
    public function stripCssBloat(string $html): string
    {
        // 1. Strip Tailwind CSS variables like --tw-border-spacing-x: 0;
        $html = preg_replace('/--tw-[^;:"\'>]+:[^;:"\'>]*;?/i', '', $html);

        // 2. Parse inline styles and remove browser clipboard junk
        $html = preg_replace_callback('/style="([^"]*)"/i', function ($matches) {
            $styles = $matches[1];
            $cleaned = $this->cleanStyleDeclarations($styles);
            return empty($cleaned) ? '' : 'style="' . $cleaned . '"';
        }, $html);

        return $html;
    }

    /**
     * Clean an individual style="..." declaration list.
     */
    protected function cleanStyleDeclarations(string $styles): string
    {
        $declarations = explode(';', $styles);
        $kept = [];

        foreach ($declarations as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }

            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val = trim($val);

            if ($prop === '' || $val === '') {
                continue;
            }

            // Ignore CSS variables (--tw-*, etc.)
            if (str_starts_with($prop, '--')) {
                continue;
            }

            // Ignore vendor prefixes (-webkit-*, -moz-*, -ms-*)
            if (str_starts_with($prop, '-webkit-') || str_starts_with($prop, '-moz-') || str_starts_with($prop, '-ms-')) {
                continue;
            }

            // Ignore browser default properties that add bloat
            if (in_array($prop, $this->browserJunkCssProperties)) {
                continue;
            }

            // Ignore zero borders
            if ($prop === 'border' && preg_match('/^0(?:px)?(?:\s+solid)?(?:\s+rgb.*|\s+#.*)?$/i', $val)) {
                continue;
            }

            // Ignore initial/normal values on properties that default to normal
            if (in_array($val, ['initial', 'inherit', 'normal']) && in_array($prop, ['font-style', 'text-align', 'display'])) {
                continue;
            }

            $kept[] = "{$prop}: {$val}";
        }

        return implode('; ', $kept);
    }

    /**
     * Minify HTML structure by collapsing whitespace and stripping non-conditional comments.
     */
    public function minifyHtml(string $html): string
    {
        // Strip non-conditional HTML comments (preserve <!--[if ...]> and <![endif]-->)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);

        // Collapse whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html);

        return trim($html);
    }
}
