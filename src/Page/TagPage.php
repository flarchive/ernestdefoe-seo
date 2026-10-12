<?php

namespace Ernestdefoe\Seo\Page;

use Ernestdefoe\Seo\SeoMeta\SeoMeta;
use Ernestdefoe\Seo\SeoProperties;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Http\RequestUtil;
use Flarum\Tags\TagRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TagPage implements PageDriverInterface
{
    use DispatchEventsTrait;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var TagRepository
     */
    protected $tagRepository;

    public function __construct(
        TranslatorInterface $translator,
        Dispatcher $events,
        TagRepository $tagRepository
    ) {
        $this->events = $events;
        $this->translator = $translator;
        $this->tagRepository = $tagRepository;
    }

    public function extensionDependencies(): array
    {
        return ['flarum-tags'];
    }

    public function handleRoutes(): array
    {
        return ['tag'];
    }

    /**
     * @param ServerRequestInterface $request
     */
    public function handle(
        ServerRequestInterface $request,
        SeoProperties $properties
    ): void {
        $tagId = Arr::get($request->getQueryParams(), 'slug');

        if (! is_numeric($tagId)) {
            /*
             * 🚨 The route parameter can arrive still percent-encoded: a tag
             * slugged 動力 is requested as /t/%E5%8B%95%E5%8A%9B, and looking that
             * up found nothing. The null then reached findOrFail(int) and the
             * whole tag page returned a 500 on a direct visit (reported by
             * Subarist). Decode first; an unknown slug simply adds no SEO tags.
             */
            $tagId = $this->tagRepository->getIdForSlug(rawurldecode((string) $tagId));
        }

        if ($tagId === null) {
            return;
        }

        try {
            // Scope to the requesting actor so a restricted tag never leaks its
            // name/description into the server-rendered <head> for users who
            // can't see it (mirrors DiscussionPage's actor-scoped lookup).
            $tag = $this->tagRepository->findOrFail($tagId, RequestUtil::getActor($request));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Do nothing, no model found
            return;
        }

        $seoMeta = SeoMeta::findByModelOrCreate($tag);

        // Run events in case the model was created
        $this->dispatchEventsFor($seoMeta);

        $properties->generateTagsFromMetaData($seoMeta);

        $properties
            // Add Schema.org metadata: CollectionPage https://schema.org/CollectionPage
            ->setSchemaJson('@type', 'CollectionPage')
            ->setSchemaJson('about', $seoMeta->description)
            // Tag URL
            ->setUrl('/t/'.$tag->slug)

            // Canonical url
            ->setCanonicalUrl('/t/'.$tag->slug);
    }
}
