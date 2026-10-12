<?php

namespace Ernestdefoe\Seo\SeoMeta;

use Carbon\Carbon;
use Ernestdefoe\Seo\SeoMeta\Event\Created;
use Flarum\Database\AbstractModel;
use Flarum\Foundation\EventGeneratorTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * @property int $id
 * @property int $object_id
 * @property string $object_type
 *
 * @property bool $auto_update_data
 *
 * @property ?string $title
 * @property ?string $description
 * @property ?string $keywords
 *
 * @property bool $robots_noindex
 * @property bool $robots_nofollow
 * @property bool $robots_noarchive
 * @property bool $robots_noimageindex
 * @property bool $robots_nosnippet
 *
 * @property ?string $twitter_title
 * @property ?string $twitter_description
 * @property ?string $twitter_image
 * @property ?string $twitter_image_source
 *
 * @property ?string $open_graph_title
 * @property ?string $open_graph_description
 * @property ?string $open_graph_image
 * @property ?string $open_graph_image_source
 *
 * @property ?int $estimated_reading_time
 *
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class SeoMeta extends AbstractModel
{
    use EventGeneratorTrait;

    protected $table = 'seo_meta';

    /**
     * Switched from an explicit `$fillable` list to guarding only the
     * primary key. The previous list left every robots-* column, every
     * Twitter / OpenGraph field, `auto_update_data`, and
     * `estimated_reading_time` off the allowlist — and the array-defaults
     * path of `findByModelOrCreate(model, [...])` (line 234) hands its
     * second argument to `create()`, which silently dropped any of those
     * keys passed by a caller. Inverting to `$guarded = ['id']` makes
     * every legitimate column mutable while still blocking PK
     * tampering. Mass-assignment defence against external input lives
     * one layer up at the JSON:API Schema `writable()` allowlist
     * (CLAUDE.md §7) — internal callers that build this model never
     * see request bodies directly.
     */
    protected $guarded = ['id'];

    /**
     * Laravel 9+ (which Flarum 2 ships) deprecated `$dates` in favor of
     * `$casts` for datetime hydration. Without this, raw column values
     * come out of Eloquent as strings — and any `->toIso8601String()`
     * call downstream throws "method on string".
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'auto_update_data' => 'boolean',
        'robots_noindex' => 'boolean',
        'robots_nofollow' => 'boolean',
        'robots_noarchive' => 'boolean',
        'robots_noimageindex' => 'boolean',
        'robots_nosnippet' => 'boolean',
    ];

    public static function build(string $objectType, int $objectId, bool $autoUpdate = true): static
    {
        $seoMeta = new static();
        $seoMeta->object_id = $objectId;
        $seoMeta->object_type = $objectType;
        $seoMeta->auto_update_data = $autoUpdate;
        $seoMeta->created_at = Carbon::now();

        return $seoMeta;
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        static::created(function (self $seoMeta) {
            $seoMeta->raise(new Created($seoMeta));
        });
    }

    /**
     * Find the SEO meta by object type.
     *
     * @param string $objectType Name of the object
     * @param int $objectId ID of the object
     */
    public static function findByObjectTypeOrFail(string $objectType, int $objectId): self
    {
        return self::where([
            ['object_type', '=', $objectType],
            ['object_id', '=', $objectId]
        ])->firstOrFail();
    }

    /**
     * Find the SEO meta by object type.
     *
     * @param string $objectType Name of the object
     * @param int $objectId ID of the object
     */
    public static function findByObjectTypeOrCreate(string $objectType, int $objectId, ?callable $fillables = null): self
    {
        return self::selectOrInsert($objectType, $objectId, $fillables);
    }

    /**
     * Shared "find existing, otherwise insert" for the (object_type, object_id)
     * unique key, with the concurrent-insert race handled in one place (was
     * copy-pasted between findByObjectTypeOrCreate and findByModelOrCreate).
     *
     * Under concurrent first-time loads for the same object, two requests can
     * both pass the SELECT and both attempt the INSERT — the loser hits the
     * unique index and throws a QueryException with SQLSTATE 23000. We catch
     * only that, re-run the SELECT to return the winning row, and let any other
     * DB error surface. The "Created" event fires only for the request that
     * actually inserted, which is the desired semantics.
     */
    private static function selectOrInsert(string $objectType, int $objectId, ?callable $fillables): self
    {
        $existing = self::where([
            ['object_type', '=', $objectType],
            ['object_id', '=', $objectId]
        ])->first();

        if ($existing !== null) {
            return $existing;
        }

        $data = SeoMeta::build($objectType, $objectId);

        if ($fillables !== null) {
            $fillables($data);
        }

        try {
            $data->save();

            return $data;
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $winner = self::where([
                ['object_type', '=', $objectType],
                ['object_id', '=', $objectId]
            ])->first();
            if ($winner !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Find by slug.
     *
     * Could be used to add dynamic tags to pages that do not have a database row
     * For example: a blog home/overview page, knowledge base page, tags overview page etc.
     *
     * @param string $pageSlug Slug of the page
     */
    public static function findOrCreateBySlug(string $pageSlug, ?callable $fillables = null): self
    {
        return self::findByObjectTypeOrCreate(str_replace('-', '_', $pageSlug), -1, $fillables);
    }

    /**
     * Find the SEO meta of an object from a model.
     *
     * @param Model $model The model
     */
    public static function findOneByModel(Model $model): ?self
    {
        return self::where([
            'object_type' => $model->getTable(),
            'object_id' => $model->getKey()
        ])->first();
    }

    /**
     * Find the SEO meta of an object from a model.
     *
     * @param Model $model The model
     */
    public static function buildByModel(Model $model): self
    {
        return self::build($model->getTable(), $model->getKey());
    }

    /**
     * Find or create the SEO meta of an object from a model.
     *
     * @param Model $model The model
     */
    /** @param array<string, mixed>|callable $fillables */
    public static function findByModelOrCreate(Model $model, array|callable $fillables = []): self
    {
        $objectType = $model->getTable();
        $objectId = $model->getKey();

        // Array defaults are filled onto the row build() starts, like the
        // callable path. This used to be firstOrCreate(), which skipped
        // build() and so never set created_at — NOT NULL, and Flarum models
        // keep no timestamps of their own — so the first view of any page
        // without a row yet (everything older than the extension) was a 500.
        // selectOrInsert also handles the unique-index collision two
        // concurrent first-time requests would otherwise 500 on.
        if (! is_callable($fillables)) {
            $defaults = $fillables;
            $fillables = fn (self $meta) => $meta->fill($defaults);
        }

        return self::selectOrInsert($objectType, $objectId, $fillables);
    }
}
