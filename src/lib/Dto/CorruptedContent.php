<?php

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto;

class CorruptedContent
{
    /**
     * @var int|null
     */
    public $id;

    /**
     * @var string|null
     */
    public $name;

    /**
     * @var int|null
     */
    public $version;

    /**
     * @var string|null
     */
    public $languageCode;

    public function __construct($id = null, $name = null, $version = null, $languageCode = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
        $this->languageCode = $languageCode;
    }
}
