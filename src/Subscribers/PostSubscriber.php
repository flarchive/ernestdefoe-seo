<?php

namespace Ernestdefoe\Seo\Subscribers;

use Ernestdefoe\Seo\SeoMeta\SeoMeta;
use Flarum\Post\Event as PostEvent;
use Psr\Log\LoggerInterface;

/**
 * Subscribe to post deleting, posted or revised
 */
class PostSubscriber
{
    // Only needs DiscussionSubscriber::updateMeta(); the previous SeoProperties
    // dependency was unused and pulled a PageListener into the event hot path.
    public function __construct(
        private DiscussionSubscriber $discussionSubscriber,
        private LoggerInterface $log
    ) {}

    /**
     * Subscribe to events
     * 
     * @param $events
     */
    public function subscribe($events)
    {
        $events->listen(PostEvent\Deleting::class, [$this, 'onModelEvent']);
        $events->listen(PostEvent\Posted::class, [$this, 'onModelEvent']);
        $events->listen(PostEvent\Revised::class, [$this, 'onModelEvent']);
    }

    /**
     * Handle model event
     *
     * @param $event
     */
    public function onModelEvent($event)
    {
        // The discussion relationship is lazy-loaded and can be null on
        // an orphaned post — one whose parent Discussion row was
        // hard-deleted while the Post row survives (rare, but happens
        // in dev fixtures, in partial database restores, and when a
        // moderation tool deletes the discussion without cascading
        // posts). Passing null into findOneByModel reaches getTable()
        // / getKey() on null and surfaces as a 500 for whichever event
        // (Post\Posted, Post\Revised, Post\Deleting) fired for the
        // orphan. Bail cleanly instead.
        $discussion = $event->post->discussion;
        if ($discussion === null) return;

        // SEO meta maintenance must never break the post action itself. A meta
        // save() can fail (DB hiccup, constraint, etc.); catch + log it rather
        // than letting it surface as an uncontrolled 500 on post/reply/edit.
        try {
            // Find meta
            $meta = SeoMeta::findOneByModel($discussion);

            // Create new meta by model
            if (!$meta) {
                $meta = SeoMeta::buildByModel($discussion);
            }

            // Do not auto update
            if (!$meta->auto_update_data) {
                return;
            }

            $this->discussionSubscriber->updateMeta($meta, $discussion);

            // Update
            $meta->save();
        } catch (\Throwable $e) {
            $this->log->warning('[seo] failed to update discussion SEO meta for discussion '
                . $discussion->id . ': ' . $e->getMessage());
        }
    }
}
