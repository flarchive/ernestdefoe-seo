<?php
namespace V17Development\FlarumSeo\Controller;

use Flarum\Settings\SettingsRepositoryInterface;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response;
use Flarum\Http\UrlGenerator;

/**
 * Class Robots
 * @package V17Development\FlarumSeo\Controller
 */
class Robots implements RequestHandlerInterface
{
    protected $settings;
    protected $url;

    /**
     * Robots constructor.
     * @param SettingsRepositoryInterface $settings
     */
    public function __construct(
        SettingsRepositoryInterface $settings,
        UrlGenerator $url
    )
    {
        $this->settings = $settings;
        $this->url = $url;
    }

    /**
     * @return string
     */
    private function output()
    {
        $output = '';

        if($this->settings->get('seo_allow_all_bots') !== '0') {
            $output .= "User-agent: *";
            $output .= PHP_EOL . "Allow: /" . PHP_EOL;
        }

        // /sitemap.xml is always served — either fof-sitemap (preferred,
        // paginates into sitemap-index for large forums) handles it,
        // or our bundled SitemapController generates a single-file
        // sitemap on demand. Either way, the Sitemap: directive
        // points crawlers to the same URL.
        $output .= PHP_EOL . "Sitemap: ". $this->url->to('forum')->base() . "/sitemap.xml" . PHP_EOL;

        // Custom robots txt
        if($this->settings->get('seo_robots_text') !== null && $this->settings->get('seo_robots_text') !== "") {
            $output .= $this->settings->get('seo_robots_text');
        }

        return $output;
    }

    /**
     * @param ServerRequestInterface $request
     * @return mixed
     */
    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write($this->output());
        return $response->withHeader('Content-Type', 'text/plain');
    }
}