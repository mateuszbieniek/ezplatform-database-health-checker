<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\Repository;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\GatewayInterface as ContentGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseHealthCheckCommand extends Command
{
    private const CONTENT_LIMIT = 100;

    /** @var ContentGateway */
    private $contentGateway;

    /** @var \eZ\Publish\API\Repository\ContentService */
    private $contentService;

    /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess */
    private $siteAccess;

    /** @var \eZ\Publish\API\Repository\PermissionResolver */
    private $permissionResolver;

    /** @var \eZ\Publish\API\Repository\Repository */
    private $repository;

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;

    public function __construct(
        ContentGateway $contentGateway,
        ContentService $contentService,
        SiteAccess $siteAccess,
        PermissionResolver $permissionResolver,
        Repository $repository
    ) {
        $this->contentGateway = $contentGateway;
        $this->contentService = $contentService;
        $this->siteAccess = $siteAccess;
        $this->permissionResolver = $permissionResolver;
        $this->repository = $repository;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ezplatform:database-health-check')
            ->setDescription(
                'This command checks the database against possible corruption.'
            )
            ->addOption(
                'skip-smoke-test',
                'sst',
                InputOption::VALUE_NONE,
                'Skip Smoke testing Content'
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

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->io->title("eZ Platform Database Health Checker");
        $this->io->warning('This script is working directly on the database! Remember to create a backup before running it.');

        if ($this->siteAccess->name !== 'db-checker') {
            if (!$this->io->confirm('You should run this command in "db-checker" SiteAccess. Are you sure that you want to continue?', false)) {
                return;
            }
        }

        if (!$this->io->confirm('Are you sure that you want to proceed?', false)) {
            return;
        }

        if (!$input->getOption('skip-smoke-test')) {
            $this->smokeTest();
        }

        $this->checkContentWithoutAttributes();
        $this->checkContentWithoutVersions();
        $this->checkContentWithDuplicatedAttributes();

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

    private function smokeTest()
    {
        $this->io->section('Smoke testing Content');

        $contentCount = $this->contentGateway->countContent();
        $this->io->text('Searching for a Content that throws Exception on load. It may take some time on big databases.');

        $progressBar = $this->io->createProgressBar($contentCount);
        $erroredContents = [];

        for ($i = 0; $i <= $contentCount; $i += self::CONTENT_LIMIT) {
            $contentIds = $this->contentGateway->getContentIds($i, self::CONTENT_LIMIT);
            foreach ($contentIds as $contentId) {
                try {
                    $contentService = $this->contentService;
                    $this->permissionResolver->sudo(
                        function () use ($contentService, $contentId) {
                            return $contentService->loadContent($contentId);
                        },
                        $this->repository
                    );
                } catch (\Exception $e) {
                    $erroredContents[] = [
                        'id' => $contentId,
                        'exception' => $e->getMessage(),
                    ];
                }
                $progressBar->advance(1);
            }
        }

        $this->io->text('');

        $erroredContentsCount = count($erroredContents);
        if ($erroredContentsCount > 0) {
            $this->io->warning(
                sprintf('Potentialy broken Content found: %d', $erroredContentsCount)
            );

            $this->io->table(
                ['Content ID', 'Error message'],
                $erroredContents
            );
        } else {
            $this->io->success('Smoke test did not find broken Content');
        }
    }
}
