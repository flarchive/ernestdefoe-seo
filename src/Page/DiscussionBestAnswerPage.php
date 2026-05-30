<?php

namespace V17Development\FlarumSeo\Page;

use Flarum\Database\Eloquent\Collection;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Http\UrlGenerator;
use Flarum\Http\SlugManager;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Flarum\User\UserRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use V17Development\FlarumSeo\SeoMeta\SeoMeta;
use V17Development\FlarumSeo\SeoProperties;

class DiscussionBestAnswerPage implements PageDriverInterface
{
    use DispatchEventsTrait;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settingsRepositoryInterface;

    /**
     * @var DiscussionRepository
     */
    protected $discussionRepository;

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
     * @var Discussion
     */
    protected $discussionFallback;

    /**
     * @var SlugManager
     */
    protected $slugManager;

    /**
     * @param SettingsRepositoryInterface $settingsRepositoryInterface
     * @param DiscussionRepository $discussionRepository
     * @param TranslatorInterface $translator
     * @param ExtensionManager $extensionManager
     * @param UrlGenerator $urlGenerator
     * @param Discussion $discussionFallback
     */
    public function __construct(
        SettingsRepositoryInterface $settingsRepositoryInterface,
        DiscussionRepository $discussionRepository,
        UserRepository $userRepository,
        ExtensionManager $extensionManager,
        UrlGenerator $urlGenerator,
        DiscussionPage $discussionFallback,
        Dispatcher $events,
        SlugManager $slugManager
    ) {
        $this->settingsRepositoryInterface = $settingsRepositoryInterface;
        $this->discussionRepository = $discussionRepository;
        $this->userRepository = $userRepository;
        $this->extensionManager = $extensionManager;
        $this->urlGenerator = $urlGenerator;
        $this->discussionFallback = $discussionFallback;
        $this->events = $events;
        $this->slugManager = $slugManager;
    }

    public function extensionDependencies(): array
    {
        return ['flarum-tags'];
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
    ) {
        // Simple discussion tags is set up
        if ($this->settingsRepositoryInterface->get('seo_post_crawler', 0) == 0) return;

        // Get discussion ID from params
        $discussionId = Arr::get($request->getQueryParams(), 'id');

        try {
            // Find discussion
            $discussion = $this->discussionRepository->findOrFail($discussionId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Do nothing, no model found
            return;
        }

        // Fallback to simple discussions for not-answer tags
        $enableBestAnswer = $this->extensionManager->isEnabled('fof-best-answer');

        /** @var Collection<Tag> $discussionTags */
        $discussionTags = $discussion->tags;

        if (!$enableBestAnswer || !$discussionTags->contains(fn(Tag $tag) => (bool)$tag->is_qna )) {
            $this->discussionFallback->handle($request, $properties);
            return;
        }

        $enableLikes = $this->extensionManager->isEnabled('flarum-likes');

        // Get seo-meta-date
        $seoMeta = SeoMeta::findByModelOrCreate($discussion);

        // Run events in case the model was created
        $this->dispatchEventsFor($seoMeta);

        $firstPost = $discussion->firstPost;

        // Update ld-json
        $properties
            ->setSchemaJson('@type', "QAPage")

            // Set page type article
            ->setMetaPropertyTag('og:type', 'article');

        // Generate data
        $properties->generateTagsFromMetaData($seoMeta);

        // Get posted on and Last posted on
        $bestAnswerId = $enableBestAnswer ? $discussion->best_answer_post_id : null;

        // Update topic url
        $properties->setUrl($this->urlGenerator->to('forum')->route('discussion', ['id' => $discussion->id . '-' . $discussion->slug]), false);

        // Schema
        $mainEntity = [
            '@type' => 'Question',
            'name' => $seoMeta->title,
            // Use formatContent() to get the rendered HTML, not the
            // raw TextFormatter XML stored in `posts.content`. The XML
            // form leaves bbcode / mention / quote artifacts behind
            // when stripped (raw `@username` text, stray `<s>`/`<e>`
            // wrapper punctuation), and those land verbatim in the
            // Schema.org payload that Google's structured-data crawler
            // reads. DiscussionSubscriber.php already uses
            // formatContent() for the same purpose; keep it consistent.
            'text' => $firstPost !== null ? strip_tags($firstPost->formatContent()) : '',
            'dateCreated' => $seoMeta->created_at,
            'author' => [
                "@type" => "Person",
                "name" => $discussion->user?->getDisplayNameAttribute(),
                "url" => $discussion->user ? $this->urlGenerator->to('forum')->route('user', ['username' => $this->slugManager->forResource(User::class)->toSlug($discussion->user)]) : null,
            ],
            'answerCount' => $discussion->comment_count - 1
        ];

        // Generate a breadcrumb if discussion has tags
        if ($discussionTags->count() >= 1) {
            $properties->generateSchemaBreadcrumb(
                $discussionTags->map(fn(Tag $tag) => [
                    'name' => $tag->name,
                    'url' => $this->urlGenerator->to('forum')->route('tag', ['slug' => $tag->slug])
                ])->toArray()
            );
        }

        // Only add suggested answers property if there are posts
        $mainEntity['suggestedAnswer'] = [];

        // Get all public comments for this discussion.
        //
        // Eager-load `user` so the per-post Schema.org author lookup
        // (`$post->user->display_name` + URL) doesn't fire N+1 user
        // queries; withCount('likes') gives us $post->likes_count as
        // an aggregate column so we can read the upvoteCount without
        // hydrating every Like row into memory. On a 200-reply Q&A
        // page this avoids ~400 extra DB roundtrips per render — and
        // this render is the one Google's structured-data crawler
        // hits, so latency here is visible to ranking.
        /** @var Collection<Post> $posts */
        $posts = $discussion->posts()
            ->with('user')
            ->withCount('likes')
            ->where('number', '>', '1')
            ->get();

        foreach ($posts as $post) {
            /** @var Post $post */
            if ($post->is_private || $post->type !== 'comment') {
                continue;
            }

            // Temp post — same reasoning as firstPost above: render
            // through formatContent() so mention/bbcode/quote nodes
            // are resolved before strip_tags() runs.
            $generatedPost = [
                '@type' => 'Answer',
                'text' => strip_tags($post->formatContent()),
                'dateCreated' => $post->created_at->toIso8601String(),
                'url' => $this->urlGenerator->to('forum')->route('discussion', ['id' => $discussion->id . '-' . $discussion->slug, 'near' => $post->number]),
                'author' => [
                    "@type" => "Person",
                    "name" => $post->user ? $post->user->display_name : null,
                    "url" => $post->user ? $this->urlGenerator->to('forum')->route('user', ['username' => $this->slugManager->forResource(User::class)->toSlug($post->user)]) : null,
                ]
            ];

            // Upvote/like count — reads the aggregate `likes_count`
            // column populated by `withCount('likes')` above instead
            // of `$post->likes->count()` which hydrates the full
            // collection. ?? 0 covers the case where flarum/likes is
            // installed but the column wasn't loaded (e.g. a future
            // refactor that drops the withCount).
            $generatedPost['upvoteCount'] = $enableLikes ? (int) ($post->likes_count ?? 0) : 0;

            // Set accepted answer
            if ($bestAnswerId === $post->id) {
                $mainEntity['acceptedAnswer'] = $generatedPost;
            }
            // Add to answers
            else {
                $mainEntity['suggestedAnswer'][] = $generatedPost;
            }
        }

        $properties->setSchemaJson('mainEntity', $mainEntity);
    }
}
