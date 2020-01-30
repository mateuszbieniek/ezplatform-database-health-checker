<?php

declare(strict_types=1);

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

    public function __construct(int $id = null, string $name = null, int $version = null, string $languageCode = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->version = $version;
        $this->languageCode = $languageCode;
    }
}
