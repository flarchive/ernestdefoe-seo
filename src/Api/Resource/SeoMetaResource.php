<?php

namespace Ernestdefoe\Seo\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Ernestdefoe\Seo\SeoMeta\SeoMeta;

/**
 * Flarum 2 JSON:API Resource replacing the v1 ListSeoMetaController +
 * ShowSeoMetaController + UpdateSeoMetaController + SeoMetaSerializer
 * quartet. Same external endpoints, same wire format, idiomatic v2
 * shape (one Resource class with declarative Endpoints + Schema fields
 * + writable allowlist instead of four scattered classes manually
 * stitched together).
 *
 * Authorization: every endpoint is admin-only via ->can('administrate').
 * Field-level writability is gated the same way (any actor-derived
 * field setter has the same guard). The Index endpoint paginates the
 * full table for the admin's "SEO entries" management page.
 *
 * Mass-assignment defense (CLAUDE.md §7): the Schema writable() allowlist
 * is the only path for client input to reach the model — `objectType`
 * and `objectId` are deliberately NOT writable post-create, since the
 * polymorphic pair is set by the auto-create flow (see
 * SeoMeta::findByObjectTypeOrCreate) and changing it after the fact
 * would orphan the linked subject.
 */
class SeoMetaResource extends AbstractDatabaseResource
{
    /**
     * Object types the frontend is allowed to lazy-create SeoMeta rows
     * for via the dash-separated find-or-create lookup. Hard-coded to
     * the known subjects so the route can't be abused to spam rows for
     * arbitrary string types.
     */
    public const ALLOWED_OBJECT_TYPES = ['discussions', 'users', 'tags', 'pages'];

    public function type(): string
    {
        // Match the v1 wire format the existing admin JS bundle expects
        // (app.store.find('seo_meta', ...) + GET /api/seo_meta/...).
        // JSON:API allows any non-empty string as type — underscore is
        // unusual but valid, and keeping it preserves backward compat
        // with the bundled admin frontend without a bundle rebuild.
        return 'seo_meta';
    }

    public function model(): string
    {
        return SeoMeta::class;
    }

    /**
     * Override find() to support the dash-separated polymorphic lookup
     * the admin frontend uses: `GET /api/seo_meta/discussions-42` —
     * which means "find-or-create the SeoMeta row for discussion 42".
     * Numeric ids fall through to the standard primary-key lookup.
     *
     * Authorization is enforced inline because the find-or-create
     * branch creates a row as a side effect of a GET — same security
     * stance the v1 ShowSeoMetaController had.
     */
    public function find(string $id, BaseContext $context): ?object
    {
        if (preg_match('/^([a-z_]+)-(\d+)$/', $id, $m)) {
            $actor = RequestUtil::getActor($context->request);
            $actor->assertCan('seo.canConfigure');

            $objectType = $m[1];
            $objectId   = (int) $m[2];

            if (! in_array($objectType, self::ALLOWED_OBJECT_TYPES, true)) {
                return null;
            }
            return SeoMeta::findByObjectTypeOrCreate($objectType, $objectId);
        }

        return parent::find($id, $context);
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        // Admin-only resource; no per-actor row gating beyond the
        // endpoint-level ->can('administrate'). Sorting by id desc by
        // default matches the v1 ListController's `latest('created_at')`
        // intent.
        $query->orderByDesc('id');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->can('administrate')
                ->paginate(),

            Endpoint\Show::make()
                ->can('administrate'),

            Endpoint\Update::make()
                ->can('administrate'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('objectType')
                ->property('object_type'),

            Schema\Integer::make('objectId')
                ->property('object_id'),

            Schema\Boolean::make('autoUpdateData')
                ->property('auto_update_data')
                ->writable(),

            Schema\Str::make('title')
                ->writable()
                ->nullable(),

            Schema\Str::make('description')
                ->writable()
                ->nullable(),

            Schema\Str::make('keywords')
                ->writable()
                ->nullable(),

            // Robots directives
            Schema\Boolean::make('robotsNoindex')
                ->property('robots_noindex')
                ->writable(),

            Schema\Boolean::make('robotsNofollow')
                ->property('robots_nofollow')
                ->writable(),

            Schema\Boolean::make('robotsNoarchive')
                ->property('robots_noarchive')
                ->writable(),

            Schema\Boolean::make('robotsNoimageindex')
                ->property('robots_noimageindex')
                ->writable(),

            Schema\Boolean::make('robotsNosnippet')
                ->property('robots_nosnippet')
                ->writable(),

            // Twitter Cards
            Schema\Str::make('twitterTitle')
                ->property('twitter_title')
                ->writable()
                ->nullable(),

            Schema\Str::make('twitterDescription')
                ->property('twitter_description')
                ->writable()
                ->nullable(),

            Schema\Str::make('twitterImage')
                ->property('twitter_image')
                ->writable()
                ->nullable(),

            Schema\Str::make('twitterImageSource')
                ->property('twitter_image_source')
                ->writable()
                ->nullable()
                ->get(fn (SeoMeta $m) => $m->twitter_image_source ?? 'auto'),

            // OpenGraph
            Schema\Str::make('openGraphTitle')
                ->property('open_graph_title')
                ->writable()
                ->nullable(),

            Schema\Str::make('openGraphDescription')
                ->property('open_graph_description')
                ->writable()
                ->nullable(),

            Schema\Str::make('openGraphImage')
                ->property('open_graph_image')
                ->writable()
                ->nullable(),

            Schema\Str::make('openGraphImageSource')
                ->property('open_graph_image_source')
                ->writable()
                ->nullable()
                ->get(fn (SeoMeta $m) => $m->open_graph_image_source ?? 'auto'),

            Schema\Integer::make('estimatedReadingTime')
                ->property('estimated_reading_time')
                ->writable()
                ->nullable(),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),
        ];
    }
}
