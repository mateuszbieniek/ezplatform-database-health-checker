<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use EzSystems\EzPlatformPageFieldType\FieldType\Page\Storage\Gateway;

class PageFieldTypeDoctrineDatabase implements PageFieldTypeGatewayInterface
{
    /** @var \Doctrine\DBAL\Connection */
    public $connection;

    /** @var \EzSystems\EzPlatformPageFieldType\FieldType\Page\Storage\Gateway|null */
    public $pageFieldTypeGateway;

    public function __construct(Connection $connection, ?Gateway $pageFieldTypeGateway = null)
    {
        $this->connection = $connection;
        $this->pageFieldTypeGateway = $pageFieldTypeGateway;
    }

    public function countOrphanedPageRelations(): int
    {
        $pagesQuery = $this->connection->createQueryBuilder();
        $pagesQuery = $pagesQuery->select('id')
            ->from('ezpage_pages')
            ->getSQL();

        $countQuery = $this->connection->createQueryBuilder();
        $countQuery->select('COUNT(page_id)')
            ->from('ezpage_map_zones_pages', 'p')
            ->where(
                $countQuery->expr()->notIn(
                    'page_id',
                    $pagesQuery
                )
            );

        return (int) $countQuery->execute()->fetch(FetchMode::NUMERIC)[0];
    }

    public function getOrphanedPageRelations(int $limit): array
    {
        $pagesQuery = $this->connection->createQueryBuilder();
        $pagesQuery = $pagesQuery->select('id')
            ->from('ezpage_pages')
            ->getSQL();

        $orphanedPagesQuery = $this->connection->createQueryBuilder();
        $orphanedPagesQuery->select('page_id')
            ->from('ezpage_map_zones_pages', 'p')
            ->where(
                $orphanedPagesQuery->expr()->notIn(
                    'page_id',
                    $pagesQuery
                )
            )
            ->setMaxResults($limit);

        return $orphanedPagesQuery->execute()->fetchAll(FetchMode::COLUMN);
    }

    public function removePage(int $pageId): void
    {
        $removedBlocks = [];
        $removedZones = [];

        foreach ($this->pageFieldTypeGateway->loadAttributesAssignedToPage($pageId) as $attribute) {
            $this->pageFieldTypeGateway->unassignAttributeFromBlock((int) $attribute['id'], (int) $attribute['block_id']);
            $this->pageFieldTypeGateway->removeAttribute((int) $attribute['id']);

            if (!\in_array($attribute['block_id'], $removedBlocks, true)) {
                $this->pageFieldTypeGateway->unassignBlockFromZone((int) $attribute['block_id'], (int) $attribute['zone_id']);
                $this->pageFieldTypeGateway->removeBlock((int) $attribute['block_id']);
                $this->pageFieldTypeGateway->removeBlockDesign((int) $attribute['block_id']);
                $this->pageFieldTypeGateway->removeBlockVisibility((int) $attribute['block_id']);
                $removedBlocks[] = $attribute['block_id'];
            }

            if (!\in_array($attribute['zone_id'], $removedZones, true)) {
                $this->pageFieldTypeGateway->unassignZoneFromPage((int) $attribute['zone_id'], $pageId);
                $this->pageFieldTypeGateway->removeZone((int) $attribute['zone_id']);
            }
        }

        foreach ($this->pageFieldTypeGateway->loadZonesAssignedToPage($pageId) as $zone) {
            $this->pageFieldTypeGateway->unassignZoneFromPage((int) $zone['id'], $pageId);
            $this->pageFieldTypeGateway->removeZone((int) $zone['id']);
        }
    }

    /**
     * @inheritDoc
     */
    public function getOrphanedBlockIds(int $limit): array
    {
        //fetching the first set via ezpage_map_blocks_zones table
        $zonesQuery = $this->connection->createQueryBuilder();
        $zonesQuery = $zonesQuery->select('id')
            ->from('ezpage_zones')
            ->getSQL();

        $orphanedBlocksQuery = $this->connection->createQueryBuilder();
        $orphanedBlocksQuery->select('block_id')
            ->from('ezpage_map_blocks_zones', 'bz')
            ->where(
                $orphanedBlocksQuery->expr()->notIn(
                    'zone_id',
                    $zonesQuery
                )
            )
            ->setMaxResults($limit);

        $firstResult = $orphanedBlocksQuery->execute()->fetchAll(FetchMode::COLUMN);

        //fetching the second set via ezpage_map_zones_pages table
        $pagesQuery = $this->connection->createQueryBuilder();
        $pagesQuery = $pagesQuery->select('id')
            ->from('ezpage_pages')
            ->getSQL();

        $secondZonesQuery = $this->connection->createQueryBuilder();
        $secondZonesQuery->select('zone_id')
            ->from('ezpage_map_zones_pages', 'zp')
            ->where(
                $secondZonesQuery->expr()->notIn(
                    'page_id',
                    $pagesQuery
                )
            );

        $secondOrphanedBlocksQuery = $this->connection->createQueryBuilder();
        $secondOrphanedBlocksQuery->select('block_id')
            ->from('ezpage_map_blocks_zones', 'bz')
            ->where(
                $secondOrphanedBlocksQuery->expr()->in(
                    'zone_id',
                    $secondZonesQuery->getSQL()
                )
            )
            ->setMaxResults($limit);

        $secondResult = $secondOrphanedBlocksQuery->execute()->fetchAll(FetchMode::COLUMN);

        return array_unique(array_merge($firstResult, $secondResult));
    }

    /**
     * @inheritDoc
     */
    public function getOrphanedAttributeIds(array $blockIds): array
    {
        $orphanedAttributesQuery = $this->connection->createQueryBuilder();
        $orphanedAttributesQuery->select('attribute_id')
            ->from('ezpage_map_attributes_blocks')
            ->where(
                $orphanedAttributesQuery->expr()->in(
                    'block_id',
                    $orphanedAttributesQuery->createPositionalParameter($blockIds, Connection::PARAM_INT_ARRAY)
                )
            );

        return $orphanedAttributesQuery->execute()->fetchAll(FetchMode::COLUMN);
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedBlockAttributes(array $attributeIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_map_attributes_blocks')
            ->where(
                $query->expr()->in(
                    'attribute_id',
                    $query->createPositionalParameter($attributeIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedAttributes(array $attributeIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_attributes')
            ->where(
                $query->expr()->in(
                    'id',
                    $query->createPositionalParameter($attributeIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedBlockDesigns(array $blockIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_blocks_design')
            ->where(
                $query->expr()->in(
                    'block_id',
                    $query->createPositionalParameter($blockIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedBlockVisibilities(array $blockIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_blocks_visibility')
            ->where(
                $query->expr()->in(
                    'block_id',
                    $query->createPositionalParameter($blockIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedBlocksZones(array $blockIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_map_blocks_zones')
            ->where(
                $query->expr()->in(
                    'block_id',
                    $query->createPositionalParameter($blockIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }

    /**
     * @inheritDoc
     */
    public function removeOrphanedBlocks(array $blockIds): void
    {
        $query = $this->connection->createQueryBuilder();
        $query->delete('ezpage_blocks')
            ->where(
                $query->expr()->in(
                    'id',
                    $query->createPositionalParameter($blockIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $query->execute();
    }
}
