<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

interface PageFieldTypeGatewayInterface
{
    public function countOrphanedPageRelations(): int;

    public function getOrphanedPageRelations(int $limit): array;

    /**
     * @return int[]
     */
    public function getOrphanedBlockIds(int $limit): array;

    /**
     * @param int[] $blockIds
     * @return int[]
     */
    public function getOrphanedAttributeIds(array $blockIds): array;

    /**
     * @param int[] $attributeIds
     */
    public function removeOrphanedBlockAttributes(array $attributeIds): void;

    /**
     * @param int[] $attributeIds
     */
    public function removeOrphanedAttributes(array $attributeIds): void;

    /**
     * @param int[] $blockIds
     */
    public function removeOrphanedBlockDesigns(array $blockIds): void;

    /**
     * @param int[] $blockIds
     */
    public function removeOrphanedBlockVisibilities(array $blockIds): void;

    /**
     * @param int[] $blockIds
     */
    public function removeOrphanedBlocksZones(array $blockIds): void;

    /**
     * @param int[] $blockIds
     */
    public function removeOrphanedBlocks(array $blockIds): void;
}
