<?php

namespace Ernestdefoe\Seo\Tests\integration\forum;

use Ernestdefoe\Seo\Tests\integration\SeedsForum;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tags a discussion page is served with, for search engines and link
 * previews. One page render per test: each one compiles the forum's assets.
 */
class DiscussionPageTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsForum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    private function page(int $id): string
    {
        $response = $this->send($this->request('GET', "/d/$id-d$id"));

        return (string) $response->getBody();
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(string $html): array
    {
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $this->assertNotEmpty($m, 'The page carries a JSON-LD block');

        return json_decode($m[1], true);
    }

    #[Test]
    public function a_discussion_is_described_as_a_forum_posting()
    {
        $html = $this->page(1);

        $this->assertStringContainsString('<meta property="og:title" content="A public question about gardening">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="How deep should I plant tulip bulbs &amp; crocuses?">', $html);

        $ld = $this->jsonLd($html)[0];
        $this->assertSame('DiscussionForumPosting', $ld['@type']);
        $this->assertSame('normal', $ld['author']['name']);
    }

    #[Test]
    public function a_discussion_a_guest_cannot_see_is_not_described()
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => 2, 'permission' => 'tag10.viewForum']]]);

        $html = $this->page(3);

        $this->assertStringNotContainsString('Staff only planning', $html);
        $this->assertStringNotContainsString('"DiscussionForumPosting"', $html);
    }

    #[Test]
    public function a_post_cannot_break_out_of_the_json_ld_block()
    {
        $html = $this->page(4);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('</script><script>alert(1)</script>', $this->jsonLd($html)[0]['description']);
    }

    /**
     * "Index all posts" hands discussions to the Q&A driver, which describes
     * a discussion that is not a question the ordinary way.
     */
    #[Test]
    public function indexing_all_posts_still_describes_an_ordinary_discussion()
    {
        $this->setting('seo_post_crawler', '1');

        $html = $this->page(1);

        $this->assertStringContainsString('<meta property="og:title" content="A public question about gardening">', $html);
        $this->assertSame('DiscussionForumPosting', $this->jsonLd($html)[0]['@type']);
    }

    #[Test]
    public function with_best_answer_a_discussion_outside_a_qna_tag_is_still_a_forum_posting()
    {
        $this->extension('fof-best-answer');
        $this->setting('seo_post_crawler', '1');

        $this->assertSame('DiscussionForumPosting', $this->jsonLd($this->page(1))[0]['@type']);
    }

    /**
     * With fof/best-answer, a discussion in a Q&A tag is described as a
     * question and its answers.
     */
    #[Test]
    public function indexing_all_posts_describes_a_qna_discussion_as_a_question()
    {
        $this->extension('fof-best-answer');
        $this->setting('seo_post_crawler', '1');
        $this->app();
        $db = $this->database();
        $db->table('tags')->where('id', 10)->update(['is_qna' => true]);
        $db->table('posts')->insert([
            ['id' => 5, 'discussion_id' => 1, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>About three times their height.</p></t>', 'created_at' => '2026-09-02 12:00:00'],
            ['id' => 6, 'discussion_id' => 1, 'number' => 3, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Thanks!</p></t>', 'created_at' => '2026-09-03 12:00:00'],
        ]);
        $db->table('discussions')->where('id', 1)->update(['best_answer_post_id' => 5, 'comment_count' => 3]);

        $ld = $this->jsonLd($this->page(1))[0];

        $this->assertSame('QAPage', $ld['@type']);
        $this->assertArrayNotHasKey('author', $ld, 'Not described as a forum posting as well');
        $this->assertSame('About three times their height.', $ld['mainEntity']['acceptedAnswer']['text']);
        $this->assertSame(['Thanks!'], array_column($ld['mainEntity']['suggestedAnswer'], 'text'));
        $this->assertSame('Question', $ld['mainEntity']['@type']);
        $this->assertSame('A public question about gardening', $ld['mainEntity']['name']);
        $this->assertSame('How deep should I plant tulip bulbs & crocuses?', $ld['mainEntity']['text']);
    }
}
