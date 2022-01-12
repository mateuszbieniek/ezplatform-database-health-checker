<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

interface PageFieldTypeGatewayInterface
{
    public function countOrphanedPageRelations(): int;

    public function getOrphanedPageRelations(int $limit): array;

    public function getOrphanedBlockIds(int $limit): array;

    public function getOrphanedAttributeIds(array $blockIds): array;

    public function removeOrphanedBlockAttributes(array $attributeIds): void;

    public function removeOrphanedAttributes(array $attributeIds): void;

    public function removeOrphanedBlockDesigns(array $blockIds): void;

    public function removeOrphanedBlockVisibilities(array $blockIds): void;

    public function removeOrphanedBlocksZones(array $blockIds): void;

    public function removeOrphanedBlocks(array $blockIds): void;
}
