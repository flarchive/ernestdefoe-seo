<?php

namespace V17Development\FlarumSeo\Api\Controllers;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Upload a fallback social-media image used by the SEO meta tags when a
 * page (forum-wide default, discussion, profile, ...) doesn't have its
 * own image to advertise to OpenGraph / Twitter Card consumers.
 *
 * Flarum 2: this is a plain RequestHandlerInterface controller — the v1
 * `Flarum\Api\Controller\ShowForumController` base class it previously
 * extended no longer exists in core. The endpoint authorizes the actor,
 * validates the upload (size + extension + MIME via finfo), writes the
 * blob to the `flarum-assets` public disk under a random filename, and
 * persists both the storage-relative path and the public URL into
 * settings so the meta-tag injector can read them on every page render
 * without re-resolving the URL.
 *
 * Security (CLAUDE.md §11):
 *   - admin-only via assertAdmin()
 *   - size cap + null-size guard
 *   - extension allowlist
 *   - finfo MIME re-detection (defeats client-spoofed Content-Type)
 *   - server-generated filename (never trust client filename)
 */
class UploadSocialMediaImageController implements RequestHandlerInterface
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    public const ALLOWED_EXT_MIME = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
    ];

    protected Cloud $disk;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        Container $container,
        protected LoggerInterface $log,
    ) {
        $this->disk = $container->make('filesystem')->disk('flarum-assets');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            RequestUtil::getActor($request)->assertAdmin();

            /** @var UploadedFileInterface|null $file */
            $file = Arr::get($request->getUploadedFiles(), 'seo_social_media_image');
            if (! $file || $file->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException(['seo_social_media_image' => 'No file uploaded.']);
            }

            $size = $file->getSize();
            if ($size === null || $size <= 0 || $size > self::MAX_BYTES) {
                throw new ValidationException([
                    'seo_social_media_image' => 'Image must be 1 byte to ' . self::MAX_BYTES . ' bytes.',
                ]);
            }

            $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
            if (! isset(self::ALLOWED_EXT_MIME[$ext])) {
                throw new ValidationException([
                    'seo_social_media_image' => 'Allowed extensions: ' . implode(', ', array_keys(self::ALLOWED_EXT_MIME)),
                ]);
            }

            $tmpPath = $file->getStream()->getMetadata('uri');
            $mime = null;
            if (is_string($tmpPath) && is_readable($tmpPath) && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = finfo_file($finfo, $tmpPath) ?: null;
                    finfo_close($finfo);
                }
            }
            if ($mime === null || ! in_array(strtolower($mime), self::ALLOWED_EXT_MIME[$ext], true)) {
                throw new ValidationException([
                    'seo_social_media_image' => 'File contents do not match its extension.',
                ]);
            }

            // Best-effort cleanup of the previous upload — never block the
            // new write on cleanup failure.
            $previousPath = $this->settings->get('seo_social_media_image_path');
            if ($previousPath && $this->disk->exists($previousPath)) {
                try { $this->disk->delete($previousPath); } catch (\Throwable) { /* ignore */ }
            }

            $uploadName = 'seo-social-' . Str::lower(Str::random(12)) . '.' . $ext;
            $this->disk->put($uploadName, $file->getStream()->getContents());

            $url = $this->disk->url($uploadName);
            $this->settings->set('seo_social_media_image_path', $uploadName);
            $this->settings->set('seo_social_media_image_url', $url);

            return new JsonResponse([
                'data' => [
                    'type'       => 'forums',
                    'attributes' => [
                        'seoSocialMediaImageUrl' => $url,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('[seo] UploadSocialMediaImageController failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return new JsonResponse(['errors' => [['status' => '500', 'detail' => 'Upload failed.']]], 500);
        }
    }
}
