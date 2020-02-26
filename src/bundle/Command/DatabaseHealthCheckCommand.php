<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\Repository;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use eZ\Publish\SPI\Persistence\Content\Handler as ContentHandler;
use eZ\Publish\SPI\Persistence\Content\Location\Handler as LocationHandler;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway\GatewayInterface as ContentGateway;
use eZ\Publish\Core\Persistence\Legacy\Content\Gateway as EzContentGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseHealthCheckCommand extends Command
{
    private const CONTENT_LIMIT = 100;
    private const ACTION_SKIP = 'skip';
    private const ACTION_DELETE = 'delete';

    /** @var ContentGateway */
    private $contentGateway;

    /** @var EzContentGateway */
    private $ezContentGateway;

    /** @var \eZ\Publish\API\Repository\ContentService */
    private $contentService;

    /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess */
    private $siteAccess;

    /** @var \eZ\Publish\API\Repository\PermissionResolver */
    private $permissionResolver;

    /** @var \eZ\Publish\API\Repository\Repository */
    private $repository;

    /** @var ContentHandler */
    private $contentHandler;

    /** @var LocationHandler */
    private $locationHandler;

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;

    public function __construct(
        ContentGateway $contentGateway,
        EzContentGateway $ezContentGateway,
        ContentService $contentService,
        SiteAccess $siteAccess,
        PermissionResolver $permissionResolver,
        ContentHandler $contentHandler,
        LocationHandler $locationHandler,
        Repository $repository
    ) {
        $this->contentGateway = $contentGateway;
        $this->ezContentGateway = $ezContentGateway;
        $this->contentService = $contentService;
        $this->siteAccess = $siteAccess;
        $this->permissionResolver = $permissionResolver;
        $this->contentHandler = $contentHandler;
        $this->locationHanlder = $locationHandler;
        $this->repository = $repository;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ezplatform:database-health-check')
            ->setDescription(
                'This command allows you to check your database against know database corruption and fixes them.'
            )
            ->addOption(
                'skip-smoke-test',
                null,
                InputOption::VALUE_NONE,
                'Skip Smoke Test'
            )
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> allows you to check your database against know database corruption and fixes them.

Please note that Command may run for a long time (depending on project size). You can speed it up by skipping Smoke 
Testing with --skip-smoke-test option.

After running command is recommended to regenerate URL aliases, clear persistence cache and reindex.

!As the script directly modifies the Database always perform a backup before running it!

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
        $this->io->title('eZ Platform Database Health Checker');
        $this->io->warning('Fixing corruption will modify your database! Always perform the database backup before running this command!');

        if (!$this->io->confirm('Are you sure that you want to proceed?', false)) {
            return;
        }

        if ($this->siteAccess->name !== 'db-checker') {
            if (!$this->io->confirm('It is recommended to run this command in "db-checker" SiteAccess. Are you sure that you want to continue?', false)) {
                return;
            }
        }

        if (!$input->getOption('skip-smoke-test')) {
            $this->smokeTest();
        }

        $this->checkContentWithoutAttributes($input, $output);
        $this->checkContentWithoutVersions();
        $this->checkDuplicatedAttributes();

        $this->io->success('Done');
    }

    private function checkContentWithoutAttributes(InputInterface $input, OutputInterface $output)
    {
        $this->io->section('Searching for Content without attributes.');
        $this->io->text('It may take some time. Please wait...');

        $contentWithoutAttributes = $this->contentGateway->findContentWithoutAttributes();
        $contentWithoutAttributesCount = count($contentWithoutAttributes);

        if ($contentWithoutAttributesCount <= 0) {
            $this->io->success('Found: 0');

            return;
        }
        $this->io->caution(sprintf('Found: %d', $contentWithoutAttributesCount));
        $this->io->table(
            ['Content ID', 'Name'],
            array_map(
                function (CorruptedContent $content) {
                    return [
                        $content->id,
                        $content->name,
                    ];
                },
                $contentWithoutAttributes
            )
        );

        $this->io->warning('Fixing this corruption will result in removing affected content from the database.');

        if ($this->io->confirm('Do you want to proceed?', false)) {
            $progressBar = $this->io->createProgressBar($contentWithoutAttributesCount);

            $helper = $this->getHelper('question');
            $corruptedParentLocationQuestion = new ChoiceQuestion(
                'Please select what you want to do with this Content:',
                [self::ACTION_SKIP, self::ACTION_DELETE],
                self::ACTION_SKIP
            );
            $corruptedParentLocationQuestion->setErrorMessage('Option %s is invalid.');

            foreach ($contentWithoutAttributes as $corruptedContent) {
                $corruptedLocations = $this->ezContentGateway->getAllLocationIds($corruptedContent->id);
                $action = self::ACTION_DELETE;

                foreach ($corruptedLocations as $corruptedLocation) {
                    if ($this->hasLocationChildren($corruptedLocation)) {
                        $this->io->warning(
                            sprintf('Location %d of Content %d has children, which will be removed as well.',
                                $corruptedLocation,
                                $corruptedContent->id
                            )
                        );
                        $action = $helper->ask($input, $output, $corruptedParentLocationQuestion);

                        if ($action === self::ACTION_SKIP) {
                            break;
                        }
                    }
                }

                if($action === self::ACTION_DELETE) {
                    $this->contentHandler->deleteContent($corruptedContent->id);
                }
                $progressBar->advance(1);
            }

            $this->io->text('');
        }
    }

    private function checkContentWithoutVersions(): void
    {
        $this->io->section('Searching for Content without versions.');
        $this->io->text('It may take some time. Please wait...');

        $contentWithoutVersions = $this->contentGateway->findContentWithoutVersions();
        $contentWithoutVersionsCount = count($contentWithoutVersions);

        if ($contentWithoutVersionsCount <= 0) {
            $this->io->success('Found: 0');

            return;
        }
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

        $this->io->warning('Fixing this corruption will result in removing affected content from the database.');

        if ($this->io->confirm('Do you want to proceed?', false)) {
            $progressBar = $this->io->createProgressBar($contentWithoutVersionsCount);

            foreach ($contentWithoutVersions  as $corruptedContent) {
                $this->contentHandler->
                $this->contentHandler->deleteContent($corruptedContent->id);
                $progressBar->advance(1);
            }

            $this->io->text('');
        }
    }

    private function checkDuplicatedAttributes(): void
    {
        $this->io->section('Searching for duplicated content\'s attributes.');
        $this->io->text('It may take some time. Please wait...');

        $duplicatedAttributes = $this->contentGateway->findDuplicatedAttributes();
        $duplicatedAttributesCount = count($duplicatedAttributes);

        if ($duplicatedAttributesCount <= 0) {
            $this->io->success('Found: 0');

            return;
        }
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

        $this->io->warning('Fixing this corruption will result in removing duplicated attribute from the database, leaving one with higher ID.');
        if ($this->io->confirm('Do you want to proceed?', false)) {
            $progressBar = $this->io->createProgressBar($duplicatedAttributesCount);

            foreach ($duplicatedAttributes  as $corruptedAttribute) {
                $this->deleteDuplicatedAttribute($corruptedAttribute);
                $progressBar->advance(1);
            }

            $this->io->text('');
        }
    }

    private function deleteDuplicatedAttribute(CorruptedAttribute $attribute): void
    {
        $this->contentGateway->deleteAttributeDuplicate(
            $attribute->id,
            $attribute->corrutpedContent->id,
            $attribute->corrutpedContent->version,
            $attribute->corrutpedContent->languageCode
        );
    }

    private function smokeTest(): void
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
        if ($erroredContentsCount <= 0) {
            $this->io->success('Smoke test did not find broken Content');

            return;
        }

        $this->io->warning(
            sprintf('Potentialy broken Content found: %d', $erroredContentsCount)
        );

        $this->io->table(
            ['Content ID', 'Error message'],
            $erroredContents
        );
    }

    private function hasLocationChildren(int $locationId): bool
    {
        if(!empty($this->locationHandler->loadSubtreeIds($locationId))) {
            return true;
        }

        return false;
    }
}
