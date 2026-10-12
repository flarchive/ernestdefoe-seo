<?php

namespace Ernestdefoe\Seo\Tests\integration\forum;

use Ernestdefoe\Seo\Tests\integration\SeedsForum;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RobotsAndSitemapTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsForum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
    }

    #[Test]
    public function robots_txt_points_crawlers_at_the_sitemap()
    {
        $this->setting('seo_robots_text', 'Disallow: /admin');

        $response = $this->send($this->request('GET', '/robots.txt'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("User-agent: *\nAllow: /\n\nSitemap: http://localhost/sitemap.xml\nDisallow: /admin", str_replace(PHP_EOL, "\n", (string) $response->getBody()));
    }

    #[Test]
    public function the_sitemap_lists_only_what_a_guest_can_read()
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => 2, 'permission' => 'tag10.viewForum']]]);

        $response = $this->send($this->request('GET', '/sitemap.xml'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/xml', $response->getHeaderLine('Content-Type'));

        preg_match_all('~<loc>([^<]+)</loc>~', (string) $response->getBody(), $m);
        $this->assertEqualsCanonicalizing(
            ['http://localhost/', 'http://localhost/d/1-d1', 'http://localhost/d/4-d4'],
            $m[1],
            'Not the hidden discussion, not the one in a tag guests cannot see'
        );
    }

    #[Test]
    public function the_sitemap_can_be_switched_off()
    {
        $this->setting('seo_sitemap_mode', 'off');

        $this->assertSame(404, $this->send($this->request('GET', '/sitemap.xml'))->getStatusCode());
    }
}
