<?php

namespace V17Development\FlarumSeo\Content;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits search-engine verification meta tags into the forum head when
 * the operator has configured at least one verification token under
 * Admin → Extensions → SEO → Site Verification.
 *
 * Each search console (Google / Bing / Yandex / Pinterest) hands out
 * a per-site token the operator pastes into the corresponding setting.
 * The console then re-fetches the page and confirms ownership by
 * matching the token in the configured meta tag. After verification,
 * the operator can leave the meta tag in place permanently — search
 * consoles re-verify periodically and removing the tag risks losing
 * ownership.
 *
 * Tokens are restricted to base64url-safe characters at the input
 * layer so they can't smuggle HTML out of the meta-tag's attribute
 * (admin-controlled but defense-in-depth — see §47.3).
 */
class SearchEngineVerification
{
    /**
     * Setting key → meta tag `name` attribute.
     */
    public const PROVIDERS = [
        'seo_google_site_verification'    => 'google-site-verification',
        'seo_bing_site_verification'      => 'msvalidate.01',
        'seo_yandex_site_verification'    => 'yandex-verification',
        'seo_pinterest_site_verification' => 'p:domain_verify',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        try {
            foreach (self::PROVIDERS as $settingKey => $metaName) {
                $token = trim((string) $this->settings->get($settingKey, ''));
                if ($token === '') {
                    continue;
                }

                // Sanitize: search-console tokens are URL-safe base64-ish
                // (letters, digits, -, _, =, .). Strip anything outside
                // that charset before interpolating into the tag so an
                // admin can't (intentionally or otherwise) inject markup.
                $safe = preg_replace('/[^A-Za-z0-9._=\-]/', '', $token);
                if ($safe === '') {
                    continue;
                }

                $document->head[] = sprintf(
                    '<meta name="%s" content="%s">',
                    htmlspecialchars($metaName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($safe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
        } catch (\Throwable $e) {
            // A verification meta tag is not worth blocking page render.
            $this->log->error('[seo] SearchEngineVerification failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
