<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use EzSystems\EzPlatformPageFieldType\FieldType\Page\Storage\Gateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PageFieldTypeCleanupCommand extends Command
{
    const PAGE_LIMIT = 100;

    /** @var \Doctrine\DBAL\Connection */
    private $connection;

    /** @var \EzSystems\EzPlatformPageFieldType\FieldType\Page\Storage\Gateway|null */
    private $pageFieldTypeGateway;

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;

    public function __construct(Connection $connection, ?Gateway $pageFieldTypeGateway = null)
    {
        $this->connection = $connection;
        $this->pageFieldTypeGateway = $pageFieldTypeGateway;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ezplatform:page-fieldtype-cleanup')
            ->setDescription(
                'This command allows you to search your database for orphaned page fieldtype related records
                 and clean them up.'
            )
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> allows you to check your database for orphaned records related to the Page Fieldtype
and clean those records if chosen to do so.

After running command it is recommended to regenerate URL aliases, clear persistence cache and reindex.

!As the script directly modifies the Database always perform a backup before running it!

EOT
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        parent::initialize($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->pageFieldTypeGateway === null) {
            $this->io->warning('Page FieldType bundle is missing. Cannot continue.');

            return 0;
        }

        $this->io->title('eZ Platform Database Health Checker');
        $this->io->text(
            sprintf('Using database: <info>%s</info>', $this->connection->getDatabase())
        );

        $this->io->warning('Always perform the database backup before running this command!');

        if (!$this->io->confirm(
            'Are you sure that you want to proceed and that you have created the database backup?',
            false)
        ) {
            return 0;
        }

        if (!$this->countOrphanedPages() > 0) {
            return 0;
        }

        $this->deleteOrphanedPagesRelations();

        $this->io->success('Done');

        return 0;
    }

    private function countOrphanedPages(): int
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

        $count = (int) $countQuery->execute()->fetch(FetchMode::NUMERIC)[0];

        if ($count <= 0) {
            $this->io->success('Found: 0');

            return $count;
        }

        $this->io->caution(sprintf('Found: %d orphaned pages', $count));

        return $count;
    }

    private function deleteOrphanedPagesRelations(): void
    {
        if (!$this->io->confirm(
            sprintf('Are you sure that you want to proceed? The maximum number of pages that will be cleaned
             in first iteration is equal to %d.', self::PAGE_LIMIT),
            false)
        ) {
            return;
        }

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
            ->setMaxResults(self::PAGE_LIMIT);

        $records = $orphanedPagesQuery->execute()->fetchAll(FetchMode::COLUMN);

        $progressBar = $this->io->createProgressBar(count($records));

        for ($i = 0; $i < self::PAGE_LIMIT; ++$i) {
            if (isset($records[$i])) {
                $progressBar->advance(1);
                $this->removePage((int) $records[$i]);
            }
        }
    }

    private function removePage(int $pageId): void
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
