<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway\PageFieldTypeGatewayInterface as Gateway;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;

class PageFieldTypeCleanupCommand extends Command
{
    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;

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
        if ($this->gateway->pageFieldTypeGateway === null) {
            $this->io->warning('Page FieldType bundle is missing. Cannot continue.');

            return 0;
        }

        $this->io->title('eZ Platform Database Health Checker');
        $this->io->text(
            sprintf('Using database: <info>%s</info>', $this->gateway->connection->getDatabase())
        );

        $this->io->warning('Always perform the database backup before running this command!');

        if (!$this->io->confirm(
            'Are you sure that you want to proceed and that you have created the database backup?',
            false)
        ) {
            return 0;
        }

        $helper = $this->getHelper('question');

        $question = new Question('Please enter the limit of pages to be iterated:', 100);
        $validation = Validation::createCallable(new Regex([
            'pattern' => '/^\d+$/',
            'message' => 'The input should be an integer',
        ]));
        $question->setValidator($validation);

        $limit = (int) $helper->ask($input, $output, $question);

        $this->deleteOrphanedPageRelations($limit);

        $this->io->success('Done');

        return 0;
    }

    private function countOrphanedPageRelations(): int
    {
        $count = $this->gateway->countOrphanedPageRelations();

        $count <= 0
            ? $this->io->success('Found: 0')
            : $this->io->caution(sprintf('Found: %d orphaned pages', $count));

        return $count;
    }

    private function deleteOrphanedPageRelations(int $limit): void
    {
        if (!$this->io->confirm(
            sprintf('Are you sure that you want to proceed? The maximum number of pages that will be cleaned
             in this iteration is equal to %d.', $limit),
            false)
        ) {
            return;
        }

        $records = $this->gateway->getOrphanedPageRelations($limit);

        $progressBar = $this->io->createProgressBar(count($records));

        for ($i = 0; $i < $limit; ++$i) {
            if (isset($records[$i])) {
                $progressBar->advance(1);
                $this->gateway->removePage((int) $records[$i]);
            }
        }

        $this->io->info('Orphaned blocks and related items which cannot be deleted using the standard procedure will be cleared up now.');

        $orphanedBlocks = $this->gateway->getOrphanedBlockIds($limit);

        $this->io->caution(sprintf('Found %d orphaned blocks within the chosen limit.', count($orphanedBlocks)));

        $orphanedAttributes = $this->gateway->getOrphanedAttributeIds($orphanedBlocks);

        $this->io->caution(
            sprintf('Found %d orphaned attributes related to the found blocks.', count($orphanedAttributes))
        );

        $progressBar = $this->io->createProgressBar(6);

        $this->io->info('Removing orphaned ezpage_map_attributes_blocks records');
        $this->gateway->removeOrphanedBlockAttributes($orphanedAttributes);
        $progressBar->advance();

        $this->io->info('Removing orphaned ezpage_attributes records');
        $this->gateway->removeOrphanedAttributes($orphanedAttributes);
        $progressBar->advance();

        $this->io->info('Removing orphaned ezpage_blocks_design records');
        $this->gateway->removeOrphanedBlockDesigns($orphanedBlocks);
        $progressBar->advance();

        $this->io->info('Removing orphaned ezpage_blocks_visibility records');
        $this->gateway->removeOrphanedBlockVisibilities($orphanedBlocks);
        $progressBar->advance();

        $this->io->info('Removing orphaned ezpage_blocks records');
        $this->gateway->removeOrphanedBlocks($orphanedBlocks);
        $progressBar->advance();

        $this->io->info('Removing orphaned ezpage_map_blocks_zones records');
        $this->gateway->removeOrphanedBlocksZones($orphanedBlocks);
        $progressBar->advance();

        $this->io->newLine();
    }
}
