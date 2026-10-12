<?php

namespace Ernestdefoe\Seo\Page;

use Ernestdefoe\Seo\SeoMeta\SeoMeta;
use Ernestdefoe\Seo\SeoProperties;
use Flarum\Database\Eloquent\Collection;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Flarum\User\UserRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class DiscussionPage implements PageDriverInterface
{
    use DispatchEventsTrait;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settingsRepositoryInterface;

    /**
     * @var UserRepository
     */
    protected $userRepository;

    /**
     * @var ExtensionManager
     */
    protected $extensionManager;

    /**
     * @var UrlGenerator
     */
    protected $urlGenerator;

    /**
     * @var SlugManager
     */
    protected $slugManager;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @param SettingsRepositoryInterface $settingsRepositoryInterface
     */
    public function __construct(
        SettingsRepositoryInterface $settingsRepositoryInterface,
        UserRepository $userRepository,
        ExtensionManager $extensionManager,
        UrlGenerator $urlGenerator,
        Dispatcher $events,
        SlugManager $slugManager
    ) {
        $this->settingsRepositoryInterface = $settingsRepositoryInterface;
        $this->userRepository = $userRepository;
        $this->extensionManager = $extensionManager;
        $this->urlGenerator = $urlGenerator;
        $this->events = $events;
        $this->slugManager = $slugManager;
    }

    public function extensionDependencies(): array
    {
        return [];
    }

    public function handleRoutes(): array
    {
        return ['discussion'];
    }

    /**
     * @param ServerRequestInterface $request
     * @param SeoProperties $properties
     */
    public function handle(
        ServerRequestInterface $request,
        SeoProperties $properties
    ): void {
        // With "index all posts" on, DiscussionBestAnswerPage (active whenever
        // tags are) takes every discussion: Q&A ones it describes itself, the
        // rest it hands to describe() below.
        if (
            $this->settingsRepositoryInterface->get('seo_post_crawler', 0) == 1 &&
            $this->extensionManager->isEnabled('flarum-tags')
        ) {
            return;
        }

        $this->describe($request, $properties);
    }

    /**
     * Describe the discussion as an ordinary forum posting.
     */
    public function describe(
        ServerRequestInterface $request,
        SeoProperties $properties
    ): void {
        // Get discussion ID from params
        $discussionId = Arr::get($request->getQueryParams(), 'id');

        if (! is_string($discussionId)) {
            return;
        }

        try {
            // Find discussion — scoped to the requesting actor's visibility so a
            // hidden / tag-restricted / soft-deleted discussion never leaks its
            // title or description into the server-rendered meta tags.
            // Through the slug driver, as core resolves the page: the id
            // parameter is "42-a-title", which only MySQL would cast to 42.
            $discussion = $this->slugManager->forResource(Discussion::class)->fromSlug($discussionId, RequestUtil::getActor($request));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Do nothing, no model found
            return;
        }

        if (! $discussion instanceof Discussion) {
            return;
        }

        $tagsEnabled = $this->extensionManager->isEnabled('flarum-tags');

        /** @var Collection<int, Tag> $discussionTags */
        $discussionTags = $discussion->tags;

        // Get seo-meta-date
        $seoMeta = SeoMeta::findByModelOrCreate(
            $discussion
        );

        // Run events in case the model was created
        $this->dispatchEventsFor($seoMeta);

        // Update ld-json
        $properties
            ->setSchemaJson('@type', 'DiscussionForumPosting')

            // Set page type article
            ->setMetaPropertyTag('og:type', 'article');

        // Generate data
        $properties->generateTagsFromMetaData($seoMeta);

        // Update topic url
        $properties->setUrl($this->urlGenerator->to('forum')->route('discussion', ['id' => $discussion->id.'-'.$discussion->slug]), false);

        try {
            // Add author to the page meta data
            $user = $discussion->user;

            // Set author data if found
            if ($user !== null) {
                // author: https://schema.org/author typeof: https://schema.org/Person
                $properties->setSchemaJson('author', [
                    '@type' => 'Person',
                    'name' => $user->getDisplayNameAttribute(),
                    'url' => $this->urlGenerator->to('forum')->route('user', ['username' => $this->slugManager->forResource(User::class)->toSlug($user)]),
                ]);
            }
        } catch (\Exception $e) {
            // User does not exists anymore
        }

        // Generate a breadcrum if discussion has tags
        if ($tagsEnabled && $discussionTags->count() >= 1) {
            $properties->generateSchemaBreadcrumb(
                $discussionTags->map(fn (Tag $tag) => [
                    'name' => $tag->name,
                    'url' => $this->urlGenerator->to('forum')->route('tag', ['slug' => $tag->slug])
                ])->toArray()
            );
        }
    }
}
