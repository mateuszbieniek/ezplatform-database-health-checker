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
    }
}
