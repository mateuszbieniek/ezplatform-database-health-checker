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

        if ($this->countOrphanedPageRelations() <= 0) {
            return 0;
        }

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
    }
}
