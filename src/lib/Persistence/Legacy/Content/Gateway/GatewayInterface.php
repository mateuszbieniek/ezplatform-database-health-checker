<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

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
     * @return int[]
     */
    public function findContentVersionsWithAttributes(int $contentId): array;

    /**
     * @return \MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute[]
     */
    public function findDuplicatedAttributes(): array;

    public function countContent(): int;

    /**
     * @return int[]
     */
    public function getContentIds(int $offset, int $limit): array;

    public function deleteAttributeDuplicate(int $attributeId, int $contentId, int $version, string $languageCode): void;

    public function checkIfContentIsParent(int $contentId): bool;
}
