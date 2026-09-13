<?php

namespace Ernestdefoe\Seo\Page;

use Flarum\Http\RequestUtil;
use Flarum\User\UserRepository;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ernestdefoe\Seo\SeoProperties;

class ProfilePage implements PageDriverInterface
{
    /**
     * @var UserRepository
     */
    protected $userRepository;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @param UserRepository $userRepository
     * @param TranslatorInterface $translator
     */
    public function __construct(UserRepository $userRepository, TranslatorInterface $translator)
    {
        $this->userRepository = $userRepository;
        $this->translator = $translator;
    }

    public function extensionDependencies(): array
    {
        return [];
    }

    public function handleRoutes(): array
    {
        return ['user'];
    }

    /**
     * @param ServerRequestInterface $request
     */
    public function handle(
        ServerRequestInterface $request,
        SeoProperties $properties
    ) {
        $username = Arr::get($request->getQueryParams(), 'username');

        // Scope the lookup to the requesting actor's visibility so that, on a
        // forum where the user list isn't public, an anonymous request can't
        // pull a hidden user's name/counts/bio/avatar into the meta tags.
        // findOrFailByUsername (unlike findByIdentification) applies that scope.
        $actor = RequestUtil::getActor($request);

        try {
            $user = is_numeric($username)
                ? $this->userRepository->findOrFail($username, $actor)
                : $this->userRepository->findOrFailByUsername($username, $actor);

            // Make sure there's a user
            if ($user === null) return;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Do nothing. It just did not work
            return;
        }

        // Profile title
        $profileTitle = $this->translator->trans("ernestdefoe-seo.forum.profile_title", [
            'username' => $user->getAttribute('display_name'),
        ]);

        // Profile description
        $profileDescription = $this->translator->trans("ernestdefoe-seo.forum.profile_description", [
            'username' => $user->getAttribute('display_name'),
            'discussion_count' => $user->getAttribute('discussion_count'),
            'comment_count' => $user->getAttribute('comment_count')
        ]);

        // Schema
        $mainEntity = [
            "@type" => "Person",
            'name' => $user->getAttribute('username')
        ];

        $properties
            // Page type
            ->setMetaPropertyTag('og:type', 'profile')
            ->setMetaPropertyTag('profile:username', $user->getAttribute('username'))

            // Add Schema.org metadata: ProfilePage https://schema.org/ProfilePage
            ->setSchemaJson('@type', 'ProfilePage')
            ->setSchemaJson('mainEntity', $mainEntity)
            ->setSchemaJson('name', $user->getAttribute('display_name'))
            ->setSchemaJson('dateCreated', $user->joined_at->toIso8601String());

        // Add avatar
        if ($user->getAttribute('avatar_url') !== null) {
            $properties->setImage($user->getAttribute('avatar_url'));
        }

        // Add bio if exists
        if ($user->getAttribute('bio') !== null) {
            $properties->setSchemaJson('about', $user->getAttribute('bio'));
        }

        $properties
            ->setSchemaJson('commentCount', $user->getAttribute('comment_count'))

            // Description
            ->setTitle($profileTitle)

            // Description
            ->setDescription($profileDescription)

            // Profile URL
            ->setUrl('/u/' . $user->getAttribute('username'))

            // Canonical url
            ->setCanonicalUrl('/u/' . $user->getAttribute('username'));
    }
}
