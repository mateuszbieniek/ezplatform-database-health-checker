<?php

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto;

class CorruptedAttribute
{
    /**
     * @var CorruptedContent|null
     */
    public $corrutpedContent;

    /**
     * @var int|null
     */
    public $id;

    public function __construct($id = null, $corrutpedContent = null)
    {
        $this->id = $id;
        $this->corrutpedContent = $corrutpedContent;
    }
}
