<?php

namespace V17Development\FlarumSeo;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion as FlarumDiscussion;
use Flarum\Extend;
use V17Development\FlarumSeo\Api\Controllers\DeleteSocialMediaImageController;
use V17Development\FlarumSeo\Api\Controllers\UploadSocialMediaImageController;
use V17Development\FlarumSeo\Api\Resource\SeoMetaResource;
use V17Development\FlarumSeo\ConfigureLinks;
use V17Development\FlarumSeo\Content\SearchEngineVerification;
use V17Development\FlarumSeo\Controller\Robots;
use V17Development\FlarumSeo\Sitemap\SitemapController;
use V17Development\FlarumSeo\Formatter\FormatLinks;
use V17Development\FlarumSeo\Extend\SEO;
use V17Development\FlarumSeo\Listeners\PageListener;
use V17Development\FlarumSeo\Page as SeoPage;
use V17Development\FlarumSeo\SeoMeta\SeoMeta;

/**
 * Flarum 2 wiring for ernestdefoe/seo.
 *
 * Migration from the upstream v17development/flarum-seo (Flarum 1.x):
 *   - Removed:  Extend\ApiSerializer + Extend\ApiController extenders
 *               (those classes don't exist in Flarum 2 core).
 *   - Added:    Extend\ApiResource(...)->fields() callbacks that mount
 *               our SEO fields onto the v2 DiscussionResource and
 *               ForumResource. ApiResource(SeoMetaResource::class)
 *               registers the standalone SEO Meta CRUD surface.
 *   - Replaced: AbstractListController / AbstractShowController /
 *               AbstractDeleteController controllers with
 *               SeoMetaResource (Endpoints) for CRUD, and plain
 *               RequestHandlerInterface controllers for non-CRUD
 *               (image upload/delete + find-or-create by object type).
 *
 * The downstream page rendering pipeline (PageListener Content injector,
 * the per-route Page drivers in src/Page, the event subscribers, and the
 * SeoMeta companion table) was already v2-compatible and is unchanged.
 */

$events = (new Extend\Event())
    ->subscribe(Subscribers\DiscussionSubscriber::class)
    ->subscribe(Subscribers\PostSubscriber::class);

if (class_exists(\Flarum\Tags\Tag::class)) {
    $events->subscribe(Subscribers\TagSubscriber::class);
}

/**
 * Forum routes — /robots.txt and /sitemap.xml ONLY when fof/sitemap is
 * not installed.
 *
 * fof/sitemap registers the same two routes (plus /sitemap-{id}.xml for
 * paginated sets) in its own extend.php. Both extensions registering
 * GET /robots.txt makes FastRoute throw
 *   `Cannot register two routes matching "/robots.txt" for method "GET"`
 * at boot, before either controller ever runs — the whole forum 500s.
 * fof/sitemap does NOT remove the conflicting routes (and even if it did,
 * it would target the upstream `flarum-seo.*` names, not this fork's
 * `ernestdefoe-seo.*`), so the only place to defer is here.
 *
 * Gate on the composer package, NOT a class name: fof/sitemap exposes no
 * top-level `FoF\Sitemap\Sitemap` class (its classes live under
 * sub-namespaces such as `FoF\Sitemap\Extend\Sitemap` /
 * `FoF\Sitemap\Controllers\RobotsController`), so the previous
 * `class_exists(\FoF\Sitemap\Sitemap::class)` check was ALWAYS false and
 * never actually suppressed the routes — the forum crashed whenever both
 * extensions were enabled. `Composer\InstalledVersions::isInstalled` is
 * resolved by autoload time, independent of the extension manager's
 * enabled-list, and immune to fof/sitemap renaming its classes.
 *
 * SitemapController still has a runtime `class_exists(\FoF\Sitemap\...)`
 * 404 fallback as defense-in-depth, but the real fix lives here.
 */
$routes = new Extend\Routes('forum');

if (! \Composer\InstalledVersions::isInstalled('fof/sitemap')) {
    $routes
        ->get('/robots.txt', 'ernestdefoe-seo.robots', Robots::class)
        ->get('/sitemap.xml', 'ernestdefoe-seo.sitemap', SitemapController::class);
}

return [
    (new Extend\Frontend('forum'))
        ->content(PageListener::class)
        ->content(SearchEngineVerification::class)
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/Forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/Admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    $routes,

    (new Extend\Routes('api'))
        ->post('/seo_social_media_image', 'seo.socialmedia.upload', UploadSocialMediaImageController::class)
        ->delete('/seo_social_media_image', 'seo.socialmedia.delete', DeleteSocialMediaImageController::class),
    // Note: the v1 polymorphic find-or-create endpoint
    // `/seo_meta/{object_type}-{id}` is now handled INLINE by
    // SeoMetaResource::find() — when the URL segment looks like
    // `discussions-42`, find() short-circuits to
    // SeoMeta::findByObjectTypeOrCreate. JSON:API resource auto-routes
    // mount this at /api/seo_meta/discussions-42 cleanly.

    (new Extend\Formatter())
        ->render(FormatLinks::class)
        ->configure(ConfigureLinks::class),

    /**
     * Per-discussion SeoMeta relationship (was Extend\Model in v1, but in
     * v2 the relation method goes on the model via Extend\Model AND the
     * resource-level wiring [includable, eager-loaded] lands on the
     * DiscussionResource via Extend\ApiResource.
     */
    (new Extend\Model(FlarumDiscussion::class))
        ->relationship('seoMeta', function (AbstractModel $model) {
            return $model->hasOne(SeoMeta::class, 'object_id', 'id')
                ->where('object_type', 'discussions');
        }),

    /**
     * SeoMeta JSON:API resource — replaces the v1 ListSeoMetaController +
     * ShowSeoMetaController + UpdateSeoMetaController + SeoMetaSerializer
     * quartet with a single Resource that declares Endpoints + Schema
     * fields + writable allowlist inline.
     */
    new Extend\ApiResource(SeoMetaResource::class),

    /**
     * Mount the seoMeta relationship onto DiscussionResource so SPA
     * requests for `?include=seoMeta` resolve cleanly. The endpoint-level
     * `eagerLoad('seoMeta')` plug ensures a single JOIN/companion query
     * per request instead of an N+1 (§38.1).
     */
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            // Type string must match SeoMetaResource::type() — kept as
            // 'seo_meta' (underscore) for backward-compat with the
            // pre-rewritten admin JS bundle. Wrong type here triggers
            // the §46 polymorphic-type-mismatch class of crash:
            // typesForModels returns null for an unregistered type and
            // JSON:API's getCollection() rejects with a TypeError that
            // surfaces as a generic 404.
            Schema\Relationship\ToOne::make('seoMeta')
                ->type('seo_meta')
                ->includable(),
        ])
        ->endpoint(Endpoint\Show::class, fn (Endpoint\Show $endpoint) => $endpoint->eagerLoad('seoMeta'))
        ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint->eagerLoad('seoMeta')),

    /**
     * Expose `canConfigureSeo` on the forum payload so the admin frontend
     * can gate the "Edit SEO" drawer at the menu level. Was an
     * AttachForumSerializerAttributes invokable + Extend\ApiSerializer in
     * v1.
     */
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canConfigureSeo')
                ->get(fn ($model, $context) =>
                    $context->getActor()->hasPermissionLike('seo.canConfigure')),
        ]),

    (new SEO())
        ->addExtender('index', SeoPage\IndexPage::class)
        ->addExtender('profile', SeoPage\ProfilePage::class)
        ->addExtender('tags', SeoPage\TagPage::class)
        ->addExtender('page_extension', SeoPage\PageExtensionPage::class)
        ->addExtender('discussion', SeoPage\DiscussionPage::class)
        ->addExtender('discussion_best_answer', SeoPage\DiscussionBestAnswerPage::class),

    $events,
];
