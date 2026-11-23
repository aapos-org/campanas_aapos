<?php

namespace App\Services\Campaigns;

use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Runs on every save of template/campaign HTML: sanitize (XSS) and inline CSS
 * (many email clients ignore <style>; the block is kept for media queries).
 */
class ContentPreparer
{
    public function __construct(private readonly EmailHtmlSanitizer $sanitizer) {}

    public function prepare(string $html): string
    {
        $clean = $this->sanitizer->sanitize($html);

        if ($clean === '') {
            return '';
        }

        // Merge tags inside attributes ({{ enlace_baja }}) must survive the inliner untouched.
        [$protected, $tags] = EmailHtmlSanitizer::protectMergeTags($clean);
        $inlined = EmailHtmlSanitizer::restoreMergeTags((new CssToInlineStyles)->convert($protected), $tags);

        return $this->sanitizer->sanitize($inlined);
    }
}
