<?php

namespace Ernestdefoe\Seo\Extend;

use Ernestdefoe\Seo\Page\PageManager;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;

class SEO implements ExtenderInterface
{
    /** @var array<string, class-string<\Ernestdefoe\Seo\Page\PageDriverInterface>> */
    protected $extenders = [];

    /**
     * Register a new extender.
     *
     * @param string $name Unique extender name
     * @param class-string<\Ernestdefoe\Seo\Page\PageDriverInterface> $extender Extender class
     */
    public function addExtender(string $name, string $extender): self
    {
        $this->extenders[$name] = $extender;

        return $this;
    }

    /**
     * Remove existing extender.
     *
     * @param string $name Extender name
     */
    public function removeExtender(string $name): self
    {
        // Forget extender
        Arr::forget($this->extenders, $name);

        return $this;
    }

    /**
     * Extender.
     */
    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->resolving(PageManager::class, function ($page) use ($container) {
            foreach ($this->extenders as $name => $extender) {
                $page->addExtender($name, $container->make($extender));
            }

            return $page;
        });
    }
}
