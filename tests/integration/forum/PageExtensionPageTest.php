<?php

namespace Ernestdefoe\Seo\Tests\integration\forum;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A fof/pages page is described with its own dates.
 */
class PageExtensionPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-pages', 'ernestdefoe-seo');
        $this->prepareDatabase([
            'pages' => [[
                'id' => 1, 'title' => 'House rules', 'slug' => 'rules', 'content' => '<t><p>Be kind.</p></t>',
                'created_at' => '2025-03-01 09:00:00', 'updated_at' => '2025-06-15 18:30:00', 'is_hidden' => 0, 'is_restricted' => 0, 'is_html' => 0,
            ]],
        ]);
    }

    #[Test]
    public function a_page_carries_its_published_and_updated_dates()
    {
        $html = (string) $this->send($this->request('GET', '/p/1-rules'))->getBody();

        $this->assertStringContainsString('<meta property="og:title" content="House rules">', $html);
        $this->assertStringContainsString('<meta name="article:published_time" content="2025-03-01T09:00:00+00:00">', $html);
        $this->assertStringContainsString('<meta name="article:updated_time" content="2025-06-15T18:30:00+00:00">', $html);
    }
}
