<?php

namespace Ernestdefoe\Seo\Tests\integration\api;

use Ernestdefoe\Seo\Tests\integration\SeedsForum;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Editing a discussion's SEO from the forum: the "Configure SEO" control is
 * shown to whoever holds the seo.canConfigure permission, so the API must
 * let exactly them through.
 */
class SeoMetaTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsForum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForum();
        $this->prepareDatabase([
            'users' => [['id' => 3, 'username' => 'seoeditor', 'email' => 'seoeditor@machine.local', 'is_email_confirmed' => 1] + $this->normalUser()],
            'groups' => [['id' => 5, 'name_singular' => 'SEO', 'name_plural' => 'SEO']],
            'group_user' => [['user_id' => 3, 'group_id' => 5]],
            'group_permission' => [['group_id' => 5, 'permission' => 'seo.canConfigure']],
        ]);
    }

    private function open(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/seo_meta/discussions-1', ['authenticatedAs' => $as]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function a_member_without_the_permission_is_refused()
    {
        [$status] = $this->open(2);

        $this->assertSame(403, $status);
        $this->assertSame(0, $this->database()->table('seo_meta')->count());
    }

    #[Test]
    public function the_permission_lets_them_open_and_edit_a_discussions_seo()
    {
        [$status, $body] = $this->open(3);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('A public question about gardening', $body['data']['attributes']['title']);

        $response = $this->send($this->request('PATCH', '/api/seo_meta/'.$body['data']['id'], [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'seo_meta', 'id' => $body['data']['id'], 'attributes' => ['description' => 'Planting depth for spring bulbs', 'robotsNoindex' => true]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->database()->table('seo_meta')->where('object_type', 'discussions')->where('object_id', 1)->first();
        $this->assertSame('Planting depth for spring bulbs', $row->description);
        $this->assertTrue((bool) $row->robots_noindex);
    }

    #[Test]
    public function an_unknown_object_type_is_not_found()
    {
        $response = $this->send($this->request('GET', '/api/seo_meta/settings-1', ['authenticatedAs' => 1]));

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function only_an_admin_may_change_the_social_media_image()
    {
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/seo_social_media_image', ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/seo_social_media_image', ['authenticatedAs' => 3]))->getStatusCode());
    }

    /**
     * A new discussion gets its description written as it is posted, and a
     * rename carries through to the title.
     */
    #[Test]
    public function a_discussion_keeps_its_seo_in_step_as_it_is_started_and_renamed()
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Compost bins', 'content' => 'Which bin works for a small garden?'],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '10']]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) json_decode((string) $response->getBody(), true)['data']['id'];

        $meta = fn () => $this->database()->table('seo_meta')->where('object_type', 'discussions')->where('object_id', $id)->first();
        $this->assertSame(['Compost bins', 'Which bin works for a small garden?'], [$meta()->title, $meta()->description]);

        $this->send($this->request('PATCH', "/api/discussions/$id", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => (string) $id, 'attributes' => ['title' => 'Compost bins for small gardens']]],
        ]));
        $this->assertSame('Compost bins for small gardens', $meta()->title);
    }

    #[Test]
    public function only_seo_editors_are_told_they_can_edit_and_see_a_discussions_seo()
    {
        foreach ([2 => false, 3 => true] as $userId => $editor) {
            $forum = json_decode((string) $this->send($this->request('GET', '/api', ['authenticatedAs' => $userId]))->getBody(), true);
            $this->assertSame($editor, $forum['data']['attributes']['canConfigureSeo']);

            $discussion = json_decode((string) $this->send(
                $this->request('GET', '/api/discussions/1', ['authenticatedAs' => $userId])->withQueryParams(['include' => 'seoMeta'])
            )->getBody(), true);
            $this->assertSame($editor, isset($discussion['data']['relationships']['seoMeta']), "user $userId");
        }
    }
}
