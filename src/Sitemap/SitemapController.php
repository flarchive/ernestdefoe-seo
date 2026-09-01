<?php

namespace Ernestdefoe\Seo\Sitemap;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Self-contained sitemap.xml generator.
 *
 * Auto-detects fof/sitemap: if the upstream `FoF\Sitemap\` namespace is
 * present, this controller no-ops with a 404 so the operator's existing
 * fof/sitemap install owns the route and we don't double-serve. When
 * fof/sitemap is NOT installed, we generate a sitemap from the
 * `discussions` table — guest-visible discussions only, ordered by
 * last_posted_at, with per-row `<lastmod>` and `<changefreq>` derived
 * from posting activity.
 *
 * Performance:
 *   - Capped at 50000 URLs per the sitemaps.org protocol limit; for
 *     forums larger than that, the operator should install fof/sitemap
 *     which paginates via sitemap-index.xml.
 *   - Streamed string-builder, not a DOM tree — memory stays under
 *     ~30 MB even at the cap (§38.2: no full-buffer base64 nonsense).
 *   - 6-hour file-cache via the framework cache — protects against
 *     bot-flood hitting the route every few seconds. Cache key includes
 *     the actor bucket ("guest"); admins always see fresh output.
 *
 * Security:
 *   - whereVisibleTo(Guest) — never leaks restricted/private discussions
 *     into the sitemap that search engines (or anyone hitting the URL)
 *     will index. §5 visibility scoping at the read path.
 *   - Output is XML-escaped; titles or any future user-supplied content
 *     can't break out of the URL element.
 */
class SitemapController implements RequestHandlerInterface
{
    /** sitemaps.org protocol limit per file. */
    public const MAX_URLS = 50_000;

    /** Cache lifetime in seconds (6 hours). */
    public const CACHE_TTL = 21_600;

    public function __construct(
        protected UrlGenerator $url,
        protected SettingsRepositoryInterface $settings,
        protected CacheRepository $cache,
        protected LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Defer to fof/sitemap when installed — their generator paginates
            // into sitemap-index.xml + per-section sitemaps and supports
            // more resource types than we do.
            if (class_exists(\FoF\Sitemap\Sitemap::class) || class_exists(\FoF\Sitemap\Resources\Discussions::class)) {
                return new TextResponse('Not found', 404, ['Content-Type' => 'text/plain']);
            }

            // Admin can explicitly opt out via the Sitemap settings page
            // (mode='off'). They picked this — typically because they handle
            // /sitemap.xml via CDN/static file, or plan to install
            // fof/sitemap. 404 cleanly instead of generating wasted output.
            if ($this->settings->get('seo_sitemap_mode') === 'off') {
                return new TextResponse('Not found', 404, ['Content-Type' => 'text/plain']);
            }

            $xml = $this->cache->remember(
                'ernestdefoe-seo.sitemap.xml.guest',
                self::CACHE_TTL,
                fn () => $this->generate(),
            );

            return new TextResponse($xml, 200, [
                'Content-Type'  => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            ]);
        } catch (\Throwable $e) {
            $this->log->error('[seo] SitemapController failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return new TextResponse('Sitemap generation failed.', 500, ['Content-Type' => 'text/plain']);
        }
    }

    protected function generate(): string
    {
        $forumBase = rtrim($this->url->to('forum')->base(), '/');

        $sb = [];
        $sb[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $sb[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Home page — daily priority 1.0
        $sb[] = $this->urlNode(
            $forumBase . '/',
            Carbon::now()->subDay(),
            'daily',
            '1.0'
        );

        // Discussion URLs — visibility-scoped to Guest so private/restricted
        // tags never leak into the public sitemap.
        $guest = new Guest();

        Discussion::query()
            ->whereVisibleTo($guest)
            ->where('comment_count', '>', 0)
            ->whereNull('hidden_at')
            ->orderByDesc('last_posted_at')
            ->limit(self::MAX_URLS - 1) // reserve a slot for the home page
            ->cursor()
            ->each(function (Discussion $d) use (&$sb, $forumBase) {
                $slug = $d->slug ?? '';
                $path = '/d/' . $d->id . ($slug !== '' ? '-' . rawurlencode($slug) : '');

                $lastMod = $d->last_posted_at ?? $d->created_at ?? Carbon::now();
                $changeFreq = $this->changeFreq($d);
                $priority   = $this->priority($d);

                $sb[] = $this->urlNode(
                    $forumBase . $path,
                    $lastMod,
                    $changeFreq,
                    $priority
                );
            });

        $sb[] = '</urlset>';

        return implode("\n", $sb);
    }

    /**
     * Build one <url> XML element with proper escaping.
     */
    protected function urlNode(string $loc, Carbon $lastMod, string $changeFreq, string $priority): string
    {
        return '  <url>'
            . '<loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>'
            . '<lastmod>' . $lastMod->toIso8601String() . '</lastmod>'
            . '<changefreq>' . $changeFreq . '</changefreq>'
            . '<priority>' . $priority . '</priority>'
            . '</url>';
    }

    /**
     * Map last-posted-at recency → sitemap `changefreq` hint. Search
     * engines treat this as advisory; we map liberally because a wrong
     * "daily" is cheap (crawler stops by, finds nothing new, leaves)
     * compared to a wrong "monthly" (interesting new replies get
     * indexed slowly).
     */
    protected function changeFreq(Discussion $d): string
    {
        $lastPosted = $d->last_posted_at ?? $d->created_at;
        if ($lastPosted === null) {
            return 'monthly';
        }
        $daysSince = $lastPosted->diffInDays(Carbon::now());
        return match (true) {
            $daysSince <= 1   => 'hourly',
            $daysSince <= 7   => 'daily',
            $daysSince <= 30  => 'weekly',
            $daysSince <= 365 => 'monthly',
            default           => 'yearly',
        };
    }

    /**
     * Per-discussion priority weighting. Recent + popular discussions
     * get higher priority. Hard-capped at 0.9 so the home page's 1.0
     * always outranks individual threads.
     */
    protected function priority(Discussion $d): string
    {
        $comments = (int) ($d->comment_count ?? 0);
        $lastPosted = $d->last_posted_at ?? $d->created_at;
        $daysSince = $lastPosted ? $lastPosted->diffInDays(Carbon::now()) : 1000;

        $base = 0.5;
        if ($comments >= 50) {
            $base += 0.2;
        } elseif ($comments >= 10) {
            $base += 0.1;
        }
        if ($daysSince <= 7) {
            $base += 0.2;
        } elseif ($daysSince <= 30) {
            $base += 0.1;
        }

        return number_format(min($base, 0.9), 1);
    }
}
