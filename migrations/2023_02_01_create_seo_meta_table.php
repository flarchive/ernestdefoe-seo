<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Upstream (v17development/flarum-seo) shipped this with
 * `$table->string('object_type', 65535)` followed by a unique index
 * including that column — which silently produces a MEDIUMTEXT column
 * and then fails with "BLOB/TEXT column used in key specification
 * without a key length" the moment Flarum tries to add the unique
 * index. The migration aborted partway, leaving a table with the
 * column but without the unique index.
 *
 * Fix: object_type is a polymorphic type discriminator. The
 * theoretical max length is something like 60 chars
 * (e.g. "discussions", "social_group_posts", a vendor-prefixed type).
 * `varchar(60)` indexes cleanly under utf8mb4_unicode_ci and gives
 * plenty of headroom.
 */
return Migration::createTableIfNotExists('seo_meta', function (Blueprint $table) {
    $table->increments('id');

    // Polymorphic subject pair.
    $table->integer('object_id');
    $table->string('object_type', 60);
    $table->unique(['object_id', 'object_type']);

    // When true (default), the discussion/tag/post subscribers will
    // auto-sync title / description / reading-time / image into this
    // row on subject changes. Admins toggle off per-row to lock in
    // hand-tuned overrides.
    $table->boolean('auto_update_data')->default(true);

    // Default HTML meta surface.
    $table->string('title')->nullable();
    $table->text('description')->nullable();
    $table->text('keywords')->nullable();

    // Robots directives.
    $table->boolean('robots_noindex')->default(false);
    $table->boolean('robots_nofollow')->default(false);
    $table->boolean('robots_noarchive')->default(false);
    $table->boolean('robots_noimageindex')->default(false);
    $table->boolean('robots_nosnippet')->default(false);

    // Twitter Card overrides.
    $table->string('twitter_title')->nullable();
    $table->text('twitter_description')->nullable();
    $table->text('twitter_image')->nullable();
    $table->string('twitter_image_source')->nullable();

    // OpenGraph overrides.
    $table->string('open_graph_title')->nullable();
    $table->text('open_graph_description')->nullable();
    $table->text('open_graph_image')->nullable();
    $table->string('open_graph_image_source')->nullable();

    // Pre-computed reading time so the page renderer doesn't have to
    // re-walk the parsed post on every paint.
    $table->integer('estimated_reading_time')->nullable();

    $table->dateTime('created_at');
    $table->dateTime('updated_at')->nullable();
});
