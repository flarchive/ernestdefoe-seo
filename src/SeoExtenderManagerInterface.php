<?php

namespace Ernestdefoe\Seo;

use Ernestdefoe\Seo\Page\PageDriverInterface;
use Illuminate\Support\Collection;

interface SeoExtenderManagerInterface
{
    public function addExtender(string $name, PageDriverInterface $extender): void;

    /** @return array<string, PageDriverInterface> */
    public function getExtenders(?string $routeName = null): array;

    public function getActiveExtenders(): Collection;
}
