<?php

namespace Ernestdefoe\Seo\Listeners;

// Flarum classes
use Ernestdefoe\Seo\Page\PageManager;
use Ernestdefoe\Seo\SeoContentUtils;
use Ernestdefoe\Seo\SeoMeta\SeoMeta;
use Ernestdefoe\Seo\SeoProperties;
use Flarum\Extension\ExtensionManager;
use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
// Laravel classes
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class PageListener.
 */
class PageListener
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var PageManager
     */
    protected $pageManager;

    /** @var string */
    protected $applicationUrl;

    /** @var Document */
    protected $flarumDocument;

    /** @var string|null */
    private $canonicalUrl = null;

    /**
     * Schema.org LD JSON.
     *
     * @var array<string, mixed>
     */
    protected $schemaArray = [
        '@context' => 'http://schema.org',
        '@type' => 'WebPage'
    ];

    /** @var array<string, mixed> */
    protected $schemaBreadcrumb = [];

    /**
     * Meta data with property tags.
     *
     * @var array<string, mixed>
     */
    protected $metaProperty = [];

    protected Cloud $assets;

    protected bool $ogImageActive = false;

    public function __construct(
        SettingsRepositoryInterface $settings,
        UrlGenerator $url,
        PageManager $pageManager,
        Container $container,
        ExtensionManager $extensions
    ) {
        $this->settings = $settings;
        $this->pageManager = $pageManager;
        $this->applicationUrl = $url->to('forum')->base();
        $this->assets = $container->make('filesystem')->disk('flarum-assets');
        $this->ogImageActive = $extensions->isEnabled('ernestdefoe-og-image');
    }

    /**
     * Get current Flarum document and current Server Request.
     *
     * @param Document $flarumDocument
     * @param ServerRequestInterface $serverRequestInterface
     */
    public function __invoke(Document $flarumDocument, ServerRequestInterface $serverRequestInterface): void
    {
        // Flarum document
        $this->flarumDocument = $flarumDocument;

        // Default site tags
        $this->setSiteTags();

        // Check out type of page
        $this->determine($serverRequestInterface);

        $this->finish($serverRequestInterface);
    }

    /**
     * Determine the current page type.
     */
    private function determine(ServerRequestInterface $serverRequest): void
    {
        // Request type
        $routeName = $serverRequest->getAttribute('routeName');

        // Initialize SEO Properties container
        $seoPropertiesExtender = new SeoProperties($this);

        // Handle through drivers
        foreach ($this->pageManager->getExtenders($routeName) as $extender) {
            $extender->handle($serverRequest, $seoPropertiesExtender);
        }
    }

    /**
     * Has another extension already written this page's Open Graph tags?
     *
     * `og:title` is the marker because it is the one tag every description
     * has: a page can legitimately have no image and no description, but
     * nothing sets out to describe a page without naming it.
     */
    private function alreadyDescribed(): bool
    {
        foreach ($this->flarumDocument->head as $entry) {
            if (is_string($entry) && str_contains($entry, 'property="og:title"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default site meta tags
     * Available for all webpages.
     */
    private function setSiteTags(): void
    {
        $applicationName = $this->settings->get('forum_title');
        $applicationDescription = $this->settings->get('forum_description');
        $applicationFavicon = $this->settings->get('favicon_path');
        $applicationLogo = $this->settings->get('logo_path');
        $applicationSeoSocialMediaImage = $this->settings->get('seo_social_media_image_path');
        $twitterCardLargeSize = $this->settings->get('seo_twitter_card_size', 'large') === 'large';

        // Add application name
        $this->setMetaTag('application-name', $applicationName)

            // Robots, follow please! :)
            ->setMetaTag('robots', 'index, follow');

        /*
         * 🚨 The SOCIAL tags are skipped when another extension has already
         * described this page.
         *
         * This listener runs on every document, including routes that belong
         * to somebody else — Atrium's gallery pages, for one, which set an
         * og:type of `article` and an og:image of the actual photograph. The
         * generic pair below would then add a second og:type of `website` and
         * a second og:site_name on top of that, and the page would tell
         * Facebook one thing and Slack another. Nothing errors; it only shows
         * up in somebody's link preview.
         *
         * Only these three are skipped. `application-name`, `robots` and the
         * schema.org block below are this extension's own job on every page,
         * and an extension that writes og:title is not claiming to do them —
         * so this is a guard around three lines, NOT an early return.
         */
        if (! $this->alreadyDescribed()) {
            $this
                ->setMetaPropertyTag('og:site_name', $applicationName)
                ->setMetaPropertyTag('og:type', 'website')
                ->setMetaTag('twitter:card', $twitterCardLargeSize ? 'summary_large_image' : 'summary');
        }

        // Add application information
        $this->setSchemaJson('publisher', [
            '@type' => 'Organization',
            'name' => $applicationName,
            'url' => $this->applicationUrl,
            'description' => $applicationDescription,
            'logo' => $applicationLogo ? $this->applicationUrl.'/assets/'.$applicationLogo : null
        ]);

        // Skip og:image when ernestdefoe/og-image is active — it handles image tags
        if (! $this->ogImageActive) {
            if ($applicationSeoSocialMediaImage !== null) {
                $this->setImage($this->assets->url($applicationSeoSocialMediaImage));
            } elseif ($applicationLogo !== null) {
                $this->setImage($this->assets->url($applicationLogo));
            } elseif ($applicationFavicon !== null) {
                $this->setImage($this->assets->url($applicationFavicon));
            }
        }
    }

    /**
     * Finish process and output language, meta property tags, canonical urls & Schema.org json.
     */
    public function finish(ServerRequestInterface $serverRequest): void
    {
        // Add language attribute to html tag
        $this->flarumDocument->language = $serverRequest->getAttribute('locale');

        // Write meta property tags
        foreach ($this->metaProperty as $name => $content) {
            $this->flarumDocument->head[] = '<meta property="'.e($name).'" content="'.e($content).'">';
        }

        // Override Flarum default canonical url
        if ($this->canonicalUrl !== null) {
            $this->flarumDocument->canonicalUrl = $this->canonicalUrl;
        }

        // Add schema.org json
        $this->flarumDocument->head[] = $this->writeSchemesOrgJson();
    }

    /**
     * Schema.org json.
     */
    private function writeSchemesOrgJson(): string
    {
        $show = [];
        $show[] = $this->schemaArray;

        if (count($this->schemaBreadcrumb) > 0) {
            $show[] = $this->schemaBreadcrumb;
        }

        $show[] = $this->addSearchBar();

        // History note: the original code used `json_encode($show, true)`.
        // PHP coerces `true` to int 1, which silently happens to equal
        // `JSON_HEX_TAG`. A previous "fix" replaced `true` with
        // `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` and dropped
        // `JSON_HEX_TAG` — that change made the LD+JSON Unicode- and
        // slash-friendly but RE-INTRODUCED a stored XSS: an actor who
        // creates a discussion (or tag) titled
        // `</script><script>alert(1)</script>` lands their payload in
        // `seo_meta.title`, which gets serialised into the LD+JSON
        // block on every subsequent page view. The browser's HTML
        // parser doesn't care that we're inside a `<script>` element
        // — `</script>` inside a JSON string still terminates the
        // script tag and the next `<script>` runs.
        //
        // `JSON_HEX_TAG` encodes `<` as `<` and `>` as `>`
        // inside string values. JSON parsers (including Google's
        // structured-data crawler) decode the escape transparently,
        // so search engines see the same content while the HTML
        // parser sees no `</script>` token. Keep this flag combined
        // with the unicode + slash flags from before — the
        // human-readability win was the original intent and is
        // unaffected by the breakout-safe escaping.
        return '<script type="application/ld+json">'
            .json_encode(
                $show,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
            )
            .'</script>';
    }

    /**
     * Add the potential search bar.
     *
     * @return array<string, mixed>
     */
    private function addSearchBar(): array
    {
        return [
            '@context' => 'http://schema.org',
            '@type' => 'WebSite',
            'url' => $this->applicationUrl.'/',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $this->applicationUrl.'/?q={search_term_string}',
                'query-input' => 'required name=search_term_string'
            ]
        ];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMetaPropertyTag($key, $value)
    {
        $this->metaProperty[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMetaTag($key, $value)
    {
        $this->flarumDocument->meta[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setSchemaJson($key, $value)
    {
        $this->schemaArray[$key] = $value;

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $tagList
     * @param string $listOrderType https://schema.org/ItemListOrderType
     * @return void
     */
    public function setSchemaBreadcrumb($tagList = [], $listOrderType = 'ItemListUnordered')
    {
        // Don't add the list, there were no tags
        if (count($tagList) === 0) {
            return;
        }

        $list = [];

        // Loop through all tags
        foreach ($tagList as $index => $tag) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => ($index + 1),
                'item' => [
                    '@type' => Arr::get($tag, 'type', 'Thing'),
                    '@id' => Arr::get($tag, 'url', $this->applicationUrl),
                    'name' => Arr::get($tag, 'name', 'Unknown'),
                    'url' => Arr::get($tag, 'url', $this->applicationUrl)
                ]
            ];
        }

        // Empty list
        if (count($list) === 0) {
            return;
        }

        $this->schemaBreadcrumb = [
            '@context' => 'http://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
            'itemListOrder' => $listOrderType,
            'numberOfItems' => count($list)
        ];
    }

    /**
     * Current page URL.
     *
     * @param string $path
     * @param bool $prependApplicationUrl
     * @return $this
     */
    public function setUrl($path = '', $prependApplicationUrl = true)
    {
        // Prepend application URL
        if ($prependApplicationUrl) {
            $path = $this->applicationUrl.$path;
        }

        $this->setMetaTag('twitter:url', $path);
        $this->setMetaPropertyTag('og:url', $path);
        $this->setSchemaJson('url', $path);

        return $this;
    }

    /**
     * Set canonical url.
     *
     * @param string $path
     * @param bool $prependApplicationUrl
     * @return $this
     */
    public function setCanonicalUrl($path, $prependApplicationUrl = true)
    {
        // Prepend application URL
        if ($prependApplicationUrl) {
            $path = $this->applicationUrl.$path;
        }

        $this->canonicalUrl = $path;

        return $this;
    }

    /**
     * @param string $path
     * @return string
     */
    public function getApplicationPath($path)
    {
        return $this->applicationUrl.$path;
    }

    /**
     * Set title.
     *
     * @param string|null $title
     * @param bool $headline
     * @return $this
     */
    public function setTitle($title, $headline = false)
    {
        $this
            ->setMetaPropertyTag('og:title', $title)
            ->setMetaTag('twitter:title', $title);

        // Set headline
        if ($headline === true) {
            $this->setSchemaJson('headline', $title);
        }

        return $this;
    }

    /**
     * Set description.
     *
     * @param string|null $description
     * @return $this
     */
    public function setDescription($description)
    {
        $this
            ->setMetaPropertyTag('og:description', $description)
            ->setMetaTag('description', $description)
            ->setMetaTag('twitter:description', $description)
            ->setSchemaJson('description', $description);

        return $this;
    }

    /**
     * Set page keywords.
     *
     * @param string|array<int, string>|null $keywords
     * @return void
     */
    public function setKeywords($keywords)
    {
        if (! $keywords) {
            return;
        }

        // Possible array of keywords
        if (is_array($keywords)) {
            $keywords = implode(', ', $keywords);
        }

        // Set keywords meta tag
        $this->setMetaTag('keywords', $keywords);
    }

    /**
     * Get image from content.
     *
     * @param string|null $content
     * @return string|null
     */
    public function getImageFromContent(?string $content = null): ?string
    {
        // Delegated to the stateless SeoContentUtils (single source of truth).
        return (new SeoContentUtils())->getImageFromContent($content);
    }

    /**
     * Get estimated reading time.
     */
    public function getEstimatedReadingTime(?string $content = null): int
    {
        // Delegated to the stateless SeoContentUtils (single source of truth).
        return (new SeoContentUtils())->getEstimatedReadingTime($content);
    }

    /**
     * Set published on.
     *
     * @param \DateTimeInterface|string|null $published
     * @return $this
     */
    public function setPublishedOn($published)
    {
        $date = (new \DateTime($published))->format('c');

        $this
            ->setMetaTag('article:published_time', $date)
            ->setSchemaJson('datePublished', $date);

        return $this;
    }

    /**
     * Set updated time
     * Only used when a discussion has newer posts.
     *
     * @param \DateTimeInterface|string|null $updated
     * @return $this
     */
    public function setUpdatedOn($updated)
    {
        $date = (new \DateTime($updated))->format('c');

        $this
            ->setMetaTag('article:updated_time', $date)
            ->setSchemaJson('dateModified', $date);

        return $this;
    }

    /**
     * Set page image.
     *
     * @param string $imagePath
     * @return $this
     */
    public function setImage($imagePath)
    {
        return $this
            ->setMetaPropertyTag('og:image', $imagePath)
            ->setMetaTag('twitter:image', $imagePath)
            ->setSchemaJson('image', $imagePath);
    }

    /**
     * Auto set meta tags and data from received SeoMeta object.
     *
     * @param SeoMeta $seoMeta
     *
     * @return PageListener
     */
    public function generateTagsFromMetaData(SeoMeta $seoMeta): self
    {
        // Set title
        $this->setPageTitle($seoMeta->title);

        $this
            ->setMetaPropertyTag('og:title', $seoMeta->open_graph_title ?? $seoMeta->title)
            ->setMetaTag('twitter:title', $seoMeta->twitter_title ?? $seoMeta->title);

        // Description
        if ($seoMeta->description) {
            $this
                ->setMetaTag('description', $seoMeta->description)
                ->setSchemaJson('description', $seoMeta->description)
                ->setMetaPropertyTag('og:description', $seoMeta->open_graph_description ?? $seoMeta->description)
                ->setMetaTag('twitter:description', $seoMeta->twitter_description ?? $seoMeta->description);
        }

        // Image — skip when ernestdefoe/og-image is active
        if (! $this->ogImageActive && $seoMeta->open_graph_image) {
            $this
                ->setMetaPropertyTag('og:image', $seoMeta->open_graph_image)
                ->setMetaTag('twitter:image', $seoMeta->twitter_image ?? $seoMeta->open_graph_image)
                ->setSchemaJson('image', $seoMeta->open_graph_image);
        }

        // Keywords
        if ($seoMeta->keywords) {
            $this->setKeywords($seoMeta->keywords);
        }

        // Published on/created at
        $this->setPublishedOn($seoMeta->created_at);

        // Updated at
        if ($seoMeta->updated_at) {
            $this->setUpdatedOn($seoMeta->updated_at);
        }

        // Generate robots tags for this page
        $robotTags = [
            $seoMeta->robots_noindex ? 'noindex' : 'index',
            $seoMeta->robots_nofollow ? 'nofollow' : 'follow'
        ];

        if ($seoMeta->robots_noarchive) {
            $robotTags[] = 'noarchive';
        }

        if ($seoMeta->robots_noimageindex) {
            $robotTags[] = 'noimageindex';
        }

        if ($seoMeta->robots_nosnippet) {
            $robotTags[] = 'nosnippet';
        }

        $this->setMetaTag('robots', implode(', ', $robotTags));

        return $this;
    }

    /**
     * Set page title.
     *
     * @param string|null $title
     * @return $this
     */
    public function setPageTitle($title)
    {
        $this->flarumDocument->title = $title;

        return $this;
    }
}
