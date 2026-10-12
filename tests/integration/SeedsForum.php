<?php

namespace Ernestdefoe\Seo\Tests\integration;

use Carbon\Carbon;

/**
 * A public discussion, one hidden by a moderator, one in a tag guests cannot
 * see, and one whose post tries to break out of the JSON-LD script block.
 */
trait SeedsForum
{
    protected function seedForum(): void
    {
        $this->extension('flarum-tags', 'ernestdefoe-seo');

        $at = Carbon::parse('2026-09-01 12:00:00');
        $discussion = fn (int $id, string $title, array $extra = []) => $extra + [
            'id' => $id, 'title' => $title, 'slug' => 'd'.$id, 'user_id' => 2, 'created_at' => $at,
            'last_posted_at' => $at, 'comment_count' => 1, 'participant_count' => 1, 'first_post_id' => $id, 'last_post_id' => $id,
        ];
        $post = fn (int $id, string $text) => [
            'id' => $id, 'discussion_id' => $id, 'number' => 1, 'user_id' => 2, 'type' => 'comment',
            'content' => '<t><p>'.$text.'</p></t>', 'created_at' => $at,
        ];

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'tags' => [
                ['id' => 10, 'name' => 'Open', 'slug' => 'open', 'position' => 0],
                ['id' => 11, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
            ],
            'discussions' => [
                $discussion(1, 'A public question about gardening'),
                $discussion(2, 'Hidden by a moderator', ['hidden_at' => $at]),
                $discussion(3, 'Staff only planning'),
                $discussion(4, 'A post that tries to break out'),
            ],
            'posts' => [
                $post(1, 'How deep should I plant tulip bulbs &amp; crocuses?'),
                $post(2, 'Gone'),
                $post(3, 'Secret'),
                $post(4, '&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;'),
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 10],
                ['discussion_id' => 3, 'tag_id' => 11],
                ['discussion_id' => 4, 'tag_id' => 10],
            ],
        ]);
    }
}
