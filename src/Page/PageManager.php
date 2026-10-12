<?php

namespace Ernestdefoe\Seo\Page;

use Ernestdefoe\Seo\SeoExtenderManagerInterface;
use Flarum\Extension\ExtensionManager;
use Illuminate\Support\Collection;

class PageManager implements SeoExtenderManagerInterface
{
    /**
     * @var array
     */
    protected $extenders = [];

    /**
     * @var ExtensionManager
     */
    protected $extensionManager;

    /**
     * @param ExtensionManager $extensionManager
     */
    public function __construct(ExtensionManager $extensionManager)
    {
        $this->extensionManager = $extensionManager;
    }

    /**
     * Add page extender.
     *
     * @param string $name Extender name
     * @param PageDriverInterface $extender Extender
     */
    public function addExtender(string $name, PageDriverInterface $extender): void
    {
        $this->extenders[$name] = $extender;
    }

    /**
     * Get all extenders.
     */
    /** @return array<string, PageDriverInterface> */
    public function getExtenders(?string $routeName = null): array
    {
        return $this->getActiveExtenders()
            ->filter(function (PageDriverInterface $driver) use ($routeName) {
                return $routeName === null || in_array($routeName, $driver->handleRoutes());
            })
            ->toArray();
    }

    /**
     * Filter on active extenders.
     */
    public function getActiveExtenders(): Collection
    {
        return collect($this->extenders)
            // Filter drivers that require extensions to be enabled
            ->filter(function (PageDriverInterface $extender) {
                foreach ($extender->extensionDependencies() as $extensionId) {
                    if (! $this->extensionManager->isEnabled($extensionId)) {
                        return false;
                    }
                }

                return true;
            });
    }
}
