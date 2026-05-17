<?php

namespace V17Development\FlarumSeo\Api\Controllers;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes the admin-uploaded fallback social-media image and clears both
 * the storage-relative path and the public URL from settings.
 *
 * Flarum 2: was AbstractDeleteController in v1; that class no longer
 * exists. Plain RequestHandlerInterface controller is the v2 shape for
 * non-CRUD mutations.
 */
class DeleteSocialMediaImageController implements RequestHandlerInterface
{
    protected Cloud $disk;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        Container $container,
    ) {
        $this->disk = $container->make('filesystem')->disk('flarum-assets');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $path = $this->settings->get('seo_social_media_image_path');
        $this->settings->set('seo_social_media_image_path', null);
        $this->settings->set('seo_social_media_image_url', null);

        if ($path && $this->disk->exists($path)) {
            try {
                $this->disk->delete($path);
            } catch (\Throwable) {
                /* Path was cleared from settings already; orphan blob is
                 * a known-tolerable cleanup miss. */
            }
        }

        return new EmptyResponse(204);
    }
}
