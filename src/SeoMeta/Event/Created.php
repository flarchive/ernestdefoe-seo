<?php

namespace Ernestdefoe\Seo\SeoMeta\Event;

use Ernestdefoe\Seo\SeoMeta\SeoMeta;

class Created
{
    // Basic meta info
    public $objectType;
    public $objectId;

    // SeoMeta object
    public $seoMeta;

    public function __construct(SeoMeta $seoMeta)
    {
        $this->seoMeta = $seoMeta;
        $this->objectType = $seoMeta->object_type;
        $this->objectId = $seoMeta->object_id;
    }
}
