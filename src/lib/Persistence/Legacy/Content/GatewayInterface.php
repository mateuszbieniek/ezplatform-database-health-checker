<?php

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content;

interface GatewayInterface
{
    /**
     * @return \MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent[]
     */
    public function findContentWithoutAttributes(): array;

    /**
     * @return \MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent[]
     */
    public function findContentWithoutVersions(): array;

    /**
     * @return \MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute[]
     */
    public function findDuplicatedAttributes(): array;

    public function countContent(): int;

    /**
     * @return int[]
     */
    public function getContentIds(int $offset, int $limit): array;

    public function purgeContent(int $contentId): void;
}
