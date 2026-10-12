<?php

namespace Ernestdefoe\Seo;

/**
 * Stateless helpers for deriving SEO metadata from post content (description,
 * first image, estimated reading time).
 *
 * Extracted from SeoProperties / PageListener so the event subscribers — which
 * only need these pure string operations — no longer have to construct a
 * PageListener (which opens the flarum-assets disk and resolves UrlGenerator,
 * PageManager and ExtensionManager) on every Discussion/Post/Tag event.
 */
class SeoContentUtils
{
    /**
     * A trimmed, ~157-character plain-text description from (HTML) content.
     */
    public function generateDescriptionFromContent(?string $content): string
    {
        // Rendered HTML escapes & < > " — decode them, or the description
        // carries "&amp;" into meta tags (escaped again there) and JSON-LD.
        $description = html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', mb_substr($description, 0, 157)))
            .(mb_strlen($description) > 157 ? '...' : '');
    }

    /**
     * The first usable image URL found in the content, or null.
     */
    public function getImageFromContent(?string $content = null): ?string
    {
        if ($content === null) {
            return null;
        }

        $pattern = '/(?<=src=")((http.*?\.)(jpe?g|png|[tg]iff?|svg|webp)(\?[a-zA-Z0-9\_\-\=\&]*)?)(?=")/';

        if (preg_match_all($pattern, $content, $matches) && count($matches) > 0) {
            return $matches[0][0];
        }

        return null;
    }

    /**
     * Estimated reading time in seconds, at ~200 words per minute.
     */
    public function getEstimatedReadingTime(?string $content = null): int
    {
        $words = str_word_count(strip_tags((string) $content));
        $minutes = floor($words / 200);
        $seconds = floor($words % 200 / (200 / 60));

        return (int) (($minutes * 60) + $seconds);
    }
}
