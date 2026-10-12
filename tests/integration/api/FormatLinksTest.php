<?php

namespace Ernestdefoe\Seo\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Links in posts: nofollow and a new tab for other sites, except the ones an
 * admin lists as trusted; neither for the forum's own pages.
 */
class FormatLinksTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-seo');
        $this->setting('seo_dofollow_domains', json_encode(['trusted.example']));

        $link = fn (string $url) => '<URL url="'.$url.'">'.$url.'</URL>';
        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'discussions' => [['id' => 1, 'title' => 'Links', 'slug' => 'links', 'user_id' => 2, 'created_at' => Carbon::now(), 'first_post_id' => 1, 'comment_count' => 1]],
            'posts' => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => Carbon::now(),
                'content' => '<r><p>'.$link('https://elsewhere.example/a').' '.$link('https://www.trusted.example/b').' '.$link('http://localhost/d/1').'</p></r>']],
        ]);
    }

    /** @return array<string, array{rel: string, target: string}> */
    private function links(): array
    {
        $response = $this->send($this->request('GET', '/api/posts/1'));
        $html = json_decode((string) $response->getBody(), true)['data']['attributes']['contentHtml'];

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $links[$a->getAttribute('href')] = ['rel' => $a->getAttribute('rel'), 'target' => $a->getAttribute('target')];
        }

        return $links;
    }

    #[Test]
    public function links_are_followed_and_opened_according_to_where_they_go()
    {
        $this->assertSame([
            'https://elsewhere.example/a' => ['rel' => 'ugc noopener nofollow', 'target' => '_blank'],
            'https://www.trusted.example/b' => ['rel' => 'ugc noopener', 'target' => '_blank'],
            'http://localhost/d/1' => ['rel' => 'ugc noopener', 'target' => '_self'],
        ], $this->links());
    }
}
