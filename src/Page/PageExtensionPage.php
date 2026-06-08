<?php

namespace V17Development\FlarumSeo\Page;

use FoF\Pages\PageRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use V17Development\FlarumSeo\SeoMeta\SeoMeta;
use V17Development\FlarumSeo\SeoProperties;

class PageExtensionPage implements PageDriverInterface
{
    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var Container
     */
    protected $container;

    /**
     * The driver class itself is instantiated unconditionally by
     * Extend\SEO's resolving callback (see src/Extend/SEO.php) — so
     * the constructor MUST NOT type-hint `PageRepository` directly,
     * because `fof/pages` is an optional suggest-only dep. If it
     * isn't installed the container's `build()` step would throw
     * `Target class [FoF\Pages\PageRepository] does not exist` and
     * crash the entire extension's boot.
     *
     * We inject the Container itself instead and lazily resolve
     * `PageRepository` inside `handle()`, which only runs when
     * `extensionDependencies()` says `fof-pages` is enabled. This
     * answers the audit's "no resolve() in hot paths" finding by
     * making the dependency explicit at construction time, while
     * keeping the optional-extension semantics the driver needs.
     */
    public function __construct(
        TranslatorInterface $translator,
        Container $container
    ) {
        $this->translator = $translator;
        $this->container  = $container;
    }

    public function extensionDependencies(): array
    {
        return ['fof-pages'];
    }

    public function handleRoutes(): array
    {
        return ['pages.home', 'pages.page'];
    }

    /**
     * @param ServerRequestInterface $request
     */
    public function handle(
        ServerRequestInterface $request,
        SeoProperties $properties
    ) {
        $pageId = Arr::get($request->getQueryParams(), 'id');

        try {
            $page = $this->container->make(PageRepository::class)->findOrFail($pageId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Do nothing, no model found
            return;
        }

        $content = $page->is_html ? $page->content : $page->contentHtml;

        // No e() wrap on the plain-text body: strip_tags() already
        // produces safe plain text, and the downstream consumers
        // double-encode if we pre-encode here. PageListener emits
        // `<meta name="description">` via e() (so &amp; from a
        // pre-encoded value would land in HTML as &amp;amp;), and
        // setSchemaJson() passes through json_encode (which sees
        // pre-encoded entities as literal text in the Schema.org
        // payload that Google's crawler reads).
        $plainText = strip_tags($content);

        $seoMeta = SeoMeta::findByModelOrCreate(
            $page,
            // Meta didn't exist yet, create one
            function (SeoMeta $meta) use ($page, $properties, $plainText) {
                $meta->title = $page->title;

                $meta->created_at = $page->time ?? new \DateTime();

                $meta->updated_at = $page->edit_time;

                $meta->description = $properties->generateDescriptionFromContent($plainText);
            }
        );

        $properties
            // Add Schema.org metadata: WebPage https://schema.org/WebPage
            ->setSchemaJson('@type', 'WebPage')
            ->setSchemaJson('text', $plainText)

            // Tag URL
            ->setUrl('/p/' . $page->getAttribute('id') . '-' . $page->getAttribute('slug'))

            // Canonical url
            ->setCanonicalUrl('/p/' . $page->getAttribute('id'))

            ->generateTagsFromMetaData($seoMeta);
    }
}
