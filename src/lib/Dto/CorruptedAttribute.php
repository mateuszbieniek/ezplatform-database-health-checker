<?php

declare(strict_types=1);

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

    public function __construct(int $id = null, CorruptedContent $corrutpedContent = null)
    {
        $this->id = $id;
        $this->corrutpedContent = $corrutpedContent;
    }
}
