<?php

namespace Ernestdefoe\Seo;

use Illuminate\Support\Collection;
use Ernestdefoe\Seo\Page\PageDriverInterface;

interface SeoExtenderManagerInterface
{
    public function addExtender(string $name, PageDriverInterface $extender): void;

    public function getExtenders(string $routeName = null): array;

    public function getActiveExtenders(): Collection;
}
