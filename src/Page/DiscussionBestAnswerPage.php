<?php

namespace Ernestdefoe\Seo\Page;

use Flarum\Database\Eloquent\Collection;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Http\RequestUtil;
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
use Ernestdefoe\Seo\SeoMeta\SeoMeta;
use Ernestdefoe\Seo\SeoProperties;

class DiscussionBestAnswerPage implements PageDriverInterface
{
    use DispatchEventsTrait;

    /**
     * Cap on the number of suggested-answer rows pulled into the JSON-LD, so a
     * thread with thousands of replies can't load every full post (content XML
     * included) into memory and OOM this render.
     */
    private const MAX_SUGGESTED_ANSWERS = 200;

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
            // Find discussion — scoped to the requesting actor's visibility so a
            // hidden / tag-restricted discussion never leaks into the meta tags.
            $discussion = $this->discussionRepository->findOrFail($discussionId, RequestUtil::getActor($request));
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

        $mainEntity['suggestedAnswer'] = [];

        // The accepted answer can sit anywhere in a long thread, so fetch it by
        // id with a targeted query rather than hoping it falls inside the capped
        // suggested-answers set below.
        if ($bestAnswerId) {
            $acceptedPost = $discussion->posts()
                ->with('user')
                ->withCount('likes')
                ->where('id', $bestAnswerId)
                ->where('number', '>', '1')
                ->first();

            if ($acceptedPost && !$acceptedPost->is_private && $acceptedPost->type === 'comment') {
                $mainEntity['acceptedAnswer'] = $this->buildAnswer($acceptedPost, $discussion, $enableLikes);
            }
        }

        // Suggested answers — capped (see MAX_SUGGESTED_ANSWERS). Eager-load
        // `user` so the per-post author lookup isn't N+1; withCount('likes')
        // exposes $post->likes_count as an aggregate so upvoteCount reads it
        // without hydrating every Like row.
        /** @var Collection<Post> $posts */
        $posts = $discussion->posts()
            ->with('user')
            ->withCount('likes')
            ->where('number', '>', '1')
            ->when($bestAnswerId, fn ($query) => $query->where('id', '!=', $bestAnswerId))
            ->limit(self::MAX_SUGGESTED_ANSWERS)
            ->get();

        foreach ($posts as $post) {
            /** @var Post $post */
            if ($post->is_private || $post->type !== 'comment') {
                continue;
            }

            $mainEntity['suggestedAnswer'][] = $this->buildAnswer($post, $discussion, $enableLikes);
        }

        $properties->setSchemaJson('mainEntity', $mainEntity);
    }

    /**
     * Build one Schema.org Answer node from a post. formatContent() renders the
     * stored TextFormatter XML to HTML so mention/bbcode/quote nodes resolve
     * before strip_tags(); upvoteCount reads the aggregate likes_count column.
     *
     * @return array<string, mixed>
     */
    private function buildAnswer(Post $post, Discussion $discussion, bool $enableLikes): array
    {
        return [
            '@type' => 'Answer',
            'text' => strip_tags($post->formatContent()),
            'dateCreated' => $post->created_at->toIso8601String(),
            'url' => $this->urlGenerator->to('forum')->route('discussion', ['id' => $discussion->id . '-' . $discussion->slug, 'near' => $post->number]),
            'author' => [
                '@type' => 'Person',
                'name' => $post->user ? $post->user->display_name : null,
                'url' => $post->user ? $this->urlGenerator->to('forum')->route('user', ['username' => $this->slugManager->forResource(User::class)->toSlug($post->user)]) : null,
            ],
            'upvoteCount' => $enableLikes ? (int) ($post->likes_count ?? 0) : 0,
        ];
    }
}
