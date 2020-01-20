<?php

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;

class DoctrineDatabase implements GatewayInterface
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

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
            $corruptedContent[] = new CorruptedContent($result['id'], $result['name']);
        }

        return $corruptedContent;
    }

    public function findContentWithoutVersions(): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('c.id, c.name')
            ->from('ezcontentobject', 'c')
            ->leftJoin('c', 'ezcontentobject_version', 'v', 'c.id = v.contentobject_id')
            ->where('v.contentobject_id IS NULL');

        $results = $queryBuilder->execute()->fetchAll(FetchMode::ASSOCIATIVE);
        $corruptedContent = [];
        foreach ($results as $result) {
            $corruptedContent[] = new CorruptedContent($result['id'], $result['name']);
        }

        return $corruptedContent;
    }

    public function findDuplicatedAttributes(): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $queryBuilder->select('a.contentobject_id, a.version, a.contentclassattribute_id, c.name, a.language_code')
            ->from('ezcontentobject_attribute', 'a')
            ->leftJoin('a', 'ezcontentobject', 'c', 'c.id = a.contentobject_id')
            ->groupBy('a.contentobject_id, a.contentclassattribute_id, a.version, a.language_code')
            ->having('count(a.id) > 1');

        $results = $queryBuilder->execute()->fetchAll(FetchMode::ASSOCIATIVE);

        $duplicatedAttributes = [];
        foreach ($results as $result) {
            $corruptedContent = new CorruptedContent($result['contentobject_id'], $result['name'], $result['version'], $result['language_code']);
            $duplicatedAttributes[] = new CorruptedAttribute($result['contentclassattribute_id'], $corruptedContent);
        }

        return $duplicatedAttributes;
    }
}
