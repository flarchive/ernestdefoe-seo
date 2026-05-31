<?php

namespace V17Development\FlarumSeo\SeoMeta;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Foundation\EventGeneratorTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use V17Development\FlarumSeo\SeoMeta\Event\Created;

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
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'auto_update_data' => 'boolean',
        'robots_noindex'   => 'boolean',
        'robots_nofollow'  => 'boolean',
        'robots_noarchive' => 'boolean',
        'robots_noimageindex' => 'boolean',
        'robots_nosnippet' => 'boolean',
    ];

    public static function build(string $objectType, int $objectId, bool $autoUpdate = true)
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
     * Find the SEO meta by object type
     *
     * @param string $objectType Name of the object
     * @param string $objectId ID of the object
     */
    public static function findByObjectTypeOrFail(string $objectType, int $objectId): Model
    {
        return self::where([
            ['object_type', '=', $objectType],
            ['object_id', '=', $objectId]
        ])->firstOrFail();
    }

    /**
     * Find the SEO meta by object type
     * 
     * @param string $objectType Name of the object
     * @param string $objectId ID of the object
     */
    public static function findByObjectTypeOrCreate(string $objectType, int $objectId, callable|null $fillables = null): Model
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

        // Race condition: under concurrent page loads for the same new
        // discussion (or any new object), two requests can both pass
        // the SELECT above and both attempt the INSERT below — the
        // second request hits the (object_type, object_id) unique
        // index and throws a QueryException with SQLSTATE 23000. We
        // catch only that specific class of error, re-run the SELECT
        // to return the winning row, and let any other DB error
        // surface normally. The "Created" event fires only for the
        // request that actually inserted, which is the desired
        // semantics — we don't want two duplicate created-events
        // firing for the same row.
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
     * Find by slug
     * 
     * Could be used to add dynamic tags to pages that do not have a database row
     * For example: a blog home/overview page, knowledge base page, tags overview page etc.
     * 
     * @param string $objectType Name of the object
     * @param string $objectId ID of the object
     */
    public static function findOrCreateBySlug(string $pageSlug, callable|null $fillables = null): Model
    {
        return self::findByObjectTypeOrCreate(str_replace("-", "_", $pageSlug), -1, $fillables);
    }


    /**
     * Find the SEO meta of an object from a model
     * 
     * @param Model $model The model
     */
    public static function findOneByModel(Model $model): ?Model
    {
        return self::where([
            'object_type' => $model->getTable(),
            'object_id' => $model->getKey()
        ])->first();
    }

    /**
     * Find the SEO meta of an object from a model
     * 
     * @param Model $model The model
     */
    public static function buildByModel(Model $model): ?Model
    {
        return self::build($model->getTable(), $model->getKey());
    }

    /**
     * Find or create the SEO meta of an object from a model
     * 
     * @param Model $model The model
     */
    public static function findByModelOrCreate(Model $model, array|callable $fillables = []): Model
    {
        $objectType = $model->getTable();
        $objectId   = $model->getKey();

        // Array-defaults path: firstOrCreate is atomic on the (type, id)
        // unique index, so the race is already handled by the DB layer.
        if (!is_callable($fillables)) {
            return self::firstOrCreate([
                'object_type' => $objectType,
                'object_id'   => $objectId,
            ], $fillables);
        }

        // Callable path: same shape as findByObjectTypeOrCreate — SELECT,
        // then INSERT on miss, then catch the unique-index collision that
        // happens when two concurrent first-time requests for the same
        // model (e.g. two bots hitting a tag page) both pass the SELECT
        // and both attempt the INSERT. Without this guard, the loser
        // surfaces a 500 because the closure inside firstOr() has no
        // catch block.
        $existing = self::where([
            'object_type' => $objectType,
            'object_id'   => $objectId,
        ])->first();

        if ($existing !== null) {
            return $existing;
        }

        $data = SeoMeta::build($objectType, $objectId);
        $fillables($data);

        try {
            $data->save();
            return $data;
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $winner = self::where([
                'object_type' => $objectType,
                'object_id'   => $objectId,
            ])->first();
            if ($winner !== null) {
                return $winner;
            }
            throw $e;
        }
    }
}
