<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;
use eZ\Publish\Core\Persistence\Legacy\Content\Gateway\DoctrineDatabase as ContentGateway;
use eZ\Publish\Core\Persistence\Legacy\Content\Location\Gateway as LocationGateway;
use eZ\Publish\Core\Persistence\Legacy\Content\FieldHandler;

class DoctrineDatabase implements GatewayInterface
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var ContentGateway
     */
    protected $contentGateway;

    /**
     * @var \eZ\Publish\Core\Persistence\Legacy\Content\Location\Gateway
     */
    protected $locationGateway;

    /**
     * @var \eZ\Publish\Core\Persistence\Legacy\Content\FieldHandler
     */
    protected $fieldHandler;

    public function __construct(
        Connection $connection,
        ContentGateway $contentGateway,
        LocationGateway $locationGateway,
        FieldHandler $fieldHandler
    ) {
        $this->connection = $connection;
        $this->contentGateway = $contentGateway;
        $this->locationGateway = $locationGateway;
        $this->fieldHandler = $fieldHandler;
    }

    /**
     * @inheritDoc
     */
    public function findContentWithoutAttributes(): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('c.id, c.name')
            ->from('ezcontentobject', 'c')
            ->leftJoin('c', 'ezcontentobject_attribute', 'a', 'c.id = a.contentobject_id AND current_version = a.version')
            ->where('a.contentobject_id IS NULL');

        $results = $queryBuilder->execute()->fetchAll(FetchMode::ASSOCIATIVE);
        $corruptedContent = [];
        foreach ($results as $result) {
            $corruptedContent[] = new CorruptedContent((int) $result['id'], $result['name']);
        }

        return $corruptedContent;
    }

    /**
     * @inheritDoc
     */
    public function findContentVersionsWithAttributes(int $contentId): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder->select('a.version')
            ->from('ezcontentobject', 'c')
            ->innerJoin('c', 'ezcontentobject_attribute', 'a', 'c.id = a.contentobject_id')
            ->where('a.contentobject_id = ?')
            ->groupBy('a.version')
            ->orderBy('a.version', 'ASC');

        $queryBuilder->setParameter(0, $contentId);

        return $queryBuilder->execute()->fetchAll(FetchMode::COLUMN);
    }

    /**
     * @inheritDoc
     */
    public function findContentWithoutVersions(): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('c.id, c.name')
            ->from('ezcontentobject', 'c')
            ->innerJoin('c', 'ezcontentobject_version', 'v', 'c.id = v.contentobject_id')
            ->where('v.contentobject_id IS NULL');

        $results = $queryBuilder->execute()->fetchAll(FetchMode::ASSOCIATIVE);
        $corruptedContent = [];
        foreach ($results as $result) {
            $corruptedContent[] = new CorruptedContent((int) $result['id'], $result['name']);
        }

        return $corruptedContent;
    }

    /**
     * @inheritDoc
     */
    public function findDuplicatedAttributes(): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('a.contentobject_id, a.version, a.contentclassattribute_id, c.name, a.language_code')
            ->from('ezcontentobject_attribute', 'a')
            ->innerJoin('a', 'ezcontentobject', 'c', 'c.id = a.contentobject_id')
            ->groupBy('a.contentobject_id, a.contentclassattribute_id, a.version, a.language_code')
            ->having('count(a.id) > 1');

        $results = $queryBuilder->execute()->fetchAll(FetchMode::ASSOCIATIVE);

        $duplicatedAttributes = [];
        foreach ($results as $result) {
            $corruptedContent = new CorruptedContent((int) $result['contentobject_id'], $result['name'], (int) $result['version'], $result['language_code']);
            $duplicatedAttributes[] = new CorruptedAttribute((int) $result['contentclassattribute_id'], $corruptedContent);
        }

        return $duplicatedAttributes;
    }

    /**
     * @inheritDoc
     */
    public function countContent(): int
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('COUNT(c.id)')
            ->from('ezcontentobject', 'c');

        return $queryBuilder->execute()->fetch(FetchMode::NUMERIC)[0];
    }

    /**
     * @inheritDoc
     */
    public function getContentIds(int $offset, int $limit): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('c.id')
            ->from('ezcontentobject', 'c')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $queryBuilder->execute()->fetchAll(FetchMode::COLUMN);
    }

    public function deleteAttributeDuplicate(int $attributeId, int $contentId, int $version, string $languageCode): void
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        //Doctrine QueryBuilder does not support deletes with limit
        $sql =
            'DELETE FROM ezcontentobject_attribute ' .
            'WHERE contentobject_id = :content_id ' .
            'AND contentclassattribute_id = :attribute_id ' .
            'AND version = :version ' .
            'AND language_code = :language_code ' .
            'ORDER BY id ASC ' .
            'LIMIT 1';

        $queryBuilder->getConnection()->prepare($sql)->execute([
            ':content_id' => $contentId,
            ':attribute_id' => $attributeId,
            ':version' => $version,
            ':language_code' => $languageCode,
        ]);
    }
}
