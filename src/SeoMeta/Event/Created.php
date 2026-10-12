<?php

namespace Ernestdefoe\Seo\SeoMeta\Event;

use Ernestdefoe\Seo\SeoMeta\SeoMeta;

class Created
{
    /** @var string */
    public $objectType;

    /** @var int */
    public $objectId;

    /** @var SeoMeta */
    public $seoMeta;

    public function __construct(SeoMeta $seoMeta)
    {
        $this->seoMeta = $seoMeta;
        $this->objectType = $seoMeta->object_type;
        $this->objectId = $seoMeta->object_id;
    }
}
