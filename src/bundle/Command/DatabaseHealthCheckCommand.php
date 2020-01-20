<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\GatewayInterface as ContentGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseHealthCheckCommand extends Command
{
    /**
     * @var ContentGateway
     */
    private $contentGateway;

    /**
     * @var \Symfony\Component\Console\Style\SymfonyStyle
     */
    private $io;

    public function __construct(ContentGateway $contentGateway)
    {
        $this->contentGateway = $contentGateway;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ezplatform:database-health-check')
            ->setDescription(
                'This command checks the database against possible corruption.'
            )
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> checks the databased against possible corruptions. When corruption is found is 
fixed automatically.

Since this script can potentially run for a very long time, to avoid memory exhaustion run it in
production environment using <info>--env=prod</info> switch.

EOT
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        parent::initialize($input, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->io->title("eZ Platform Database Health Checker");
        $this->io->warning('This script is working directly on the database! Remember to create a backup before running it.');

        if (!$this->io->confirm('Are you sure that you want to proceed?', false)) {
            return;
        }

        $this->checkContentWithoutAttributes($output);
        $this->checkContentWithoutVersions($output);
        $this->checkContentWithDuplicatedAttributes($output);

        $this->io->success('Done');
    }

    private function checkContentWithoutAttributes()
    {
        $this->io->section('Searching for Content without attributes.');
        $this->io->text('It may take some time. Please wait...');

        $contentWithoutAttibutes = $this->contentGateway->findContentWithoutAttributes();
        $contentWithoutAttibutesCount = count($contentWithoutAttibutes);

        if ($contentWithoutAttibutesCount > 0) {
            $this->io->caution(sprintf('Found: %d', $contentWithoutAttibutesCount));
            $this->io->table(
                ['Content ID', 'Name'],
                array_map(
                    function (CorruptedContent $content) {
                        return [
                            $content->id,
                            $content->name,
                        ];
                    },
                    $contentWithoutAttibutes
                )
            );

            if ($this->io->confirm('Do you want to attempt on repairing it?', false)) {
                // TODO
            }
        } else {
            $this->io->success('Found: 0');
        }
    }

    private function checkContentWithoutVersions()
    {
        $this->io->section('Searching for Content without versions.');
        $this->io->text('It may take some time. Please wait...');

        $contentWithoutVersions = $this->contentGateway->findContentWithoutVersions();
        $contentWithoutVersionsCount = count($contentWithoutVersions);

        if ($contentWithoutVersionsCount > 0) {
            $this->io->caution(sprintf('Found: %d', $contentWithoutVersionsCount));
            $this->io->table(
                ['Content ID', 'Name'],
                array_map(
                    function (CorruptedContent $content) {
                        return [
                            $content->id,
                            $content->name,
                        ];
                    },
                    $contentWithoutVersions
                )
            );

            if ($this->io->confirm('Do you want to attempt on repairing it?', false)) {
                // TODO
            }
        } else {
            $this->io->success('Found: 0');
        }
    }

    private function checkContentWithDuplicatedAttributes()
    {
        $this->io->section('Searching for Content with duplicated attributes.');
        $this->io->text('It may take some time. Please wait...');

        $duplicatedAttributes = $this->contentGateway->findDuplicatedAttributes();
        $duplicatedAttributesCount = count($duplicatedAttributes);

        if ($duplicatedAttributesCount > 0) {
            $this->io->caution(sprintf('Found: %d', $duplicatedAttributesCount));
            $this->io->table(
                ['Content ID', 'Version', 'Attribute ID', 'Name', 'Language code'],
                array_map(
                    function (CorruptedAttribute $attribute) {
                        return [
                            $attribute->corrutpedContent->id,
                            $attribute->corrutpedContent->version,
                            $attribute->id,
                            $attribute->corrutpedContent->name,
                            $attribute->corrutpedContent->languageCode,
                        ];
                    },
                    $duplicatedAttributes
                )
            );

            if ($this->io->confirm('Do you want to attempt on repairing it?', false)) {
                // TODO
            }
        } else {
            $this->io->success('Found: 0');
        }
    }
}
