<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\Command;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\Repository;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use eZ\Publish\SPI\Persistence\Handler;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedAttribute;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Dto\CorruptedContent;
use MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway\GatewayInterface as ContentGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseHealthCheckCommand extends Command
{
    private const CONTENT_LIMIT = 100;
    private const ACTION_SKIP = 'skip';
    private const ACTION_DELETE = 'delete';
    private const ACTION_SWAP = 'swap';
    private const ACTIONS = [
        self::ACTION_SKIP,
        self::ACTION_DELETE,
        self::ACTION_SWAP,
    ];

    /** @var ContentGateway */
    private $contentGateway;

    /** @var \eZ\Publish\API\Repository\ContentService */
    private $contentService;

    /** @var \eZ\Publish\API\Repository\LocationService */
    private $locationService;

    /** @var \eZ\Publish\Core\MVC\Symfony\SiteAccess */
    private $siteAccess;

    /** @var \eZ\Publish\API\Repository\PermissionResolver */
    private $permissionResolver;

    /** @var \eZ\Publish\API\Repository\Repository */
    private $repository;

    /** @var \eZ\Publish\SPI\Persistence\Handler */
    private $persistenceHandler;

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;

    public function __construct(
        ContentGateway $contentGateway,
        ContentService $contentService,
        LocationService $locationService,
        SiteAccess $siteAccess,
        PermissionResolver $permissionResolver,
        Handler $handler,
        Repository $repository
    ) {
        $this->contentGateway = $contentGateway;
        $this->contentService = $contentService;
        $this->locationService = $locationService;
        $this->siteAccess = $siteAccess;
        $this->permissionResolver = $permissionResolver;
        $this->persistenceHandler = $handler;
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
The command <info>%command.name%</info> allows you to check your database against know database corruption and fixes 
them.

Please note that Command may run for a long time (depending on project size). You can speed it up by skipping Smoke 
Testing with --skip-smoke-test option.

Corrupted Content that is not fixable will be deleted. If any Location of this Content has subitems, you can choose one 
of the above:
- Skip: Content will be skipped.
- Delete: Content and all its Locations (with subitems) will be removed.
- Swap: Provide Location Id, which will be swaped with corrupted Content Location. Use this option to move location\'s 
subtree so corrupted Content can be removed without loosing it. You can re-run this command afterward to safely remove 
corrupted Content. 

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
        $this->io->warning(
            'Fixing corruption will modify your database! Always perform the database backup before running this command!'
        );

        if (!$this->io->confirm('Are you sure that you want to proceed?', false)) {
            return;
        }

        if ($this->siteAccess->name !== 'db-checker') {
            if (!$this->io->confirm(
                'It is recommended to run this command in "db-checker" SiteAccess. Are you sure that you want ' .
                'to continue?',
                false)) {
                return;
            }
        }

        if (!$input->getOption('skip-smoke-test')) {
            $this->smokeTest();
        }

        $this->checkContentWithoutVersions($input, $output);
        $this->checkContentWithoutAttributes($input, $output);
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

        $this->fixCorruptedContent($input, $output, $contentWithoutAttributes, $contentWithoutAttributesCount);
    }

    private function checkContentWithoutVersions(InputInterface $input, OutputInterface $output): void
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

        $this->fixCorruptedContent($input, $output, $contentWithoutVersions, $contentWithoutVersionsCount);
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

        $this->io->warning(
            'Fixing this corruption will result in removing duplicated attribute from the database, leaving one with ' .
            'higher ID.'
        );
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
        $subtreeIds = $this->persistenceHandler->locationHandler()->loadSubtreeIds($locationId);
        if (!empty($subtreeIds) && count($subtreeIds) > 1) {
            return true;
        }

        return false;
    }

    private function swapLocation(int $locationAId, int $locationBId): void
    {
        $locationA = $this->locationService->loadLocation($locationAId);
        $locationB = $this->locationService->loadLocation($locationBId);

        $this->repository->beginTransaction();

        try {
            $this->persistenceHandler->locationHandler()->swap($locationA->id, $locationB->id);
            $this->persistenceHandler->urlAliasHandler()->locationSwapped(
                $locationA->id,
                $locationA->parentLocationId,
                $locationB->id,
                $locationB->parentLocationId
            );
            $this->persistenceHandler->bookmarkHandler()->locationSwapped($locationA->id, $locationB->id);
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    private function fixCorruptedContent(InputInterface $input, OutputInterface $output, array $corruptedContent, int $count)
    {
        $this->io->warning(
            'Fixing this corruption will result in removing affected content from the database!'
        );

        $this->io->text(
            'If one of the locations of corrupted content has subitems, you will be presented with a choice on how to ' .
            'deal with that content.'
        );

        if ($this->io->confirm('Do you want to proceed?', false)) {
            $progressBar = $this->io->createProgressBar($corruptedContent);

            $helper = $this->getHelper('question');
            $corruptedParentLocationQuestion = new ChoiceQuestion(
                'Please select what you want to do with this Content:',
                self::ACTIONS,
                self::ACTION_SKIP
            );
            $corruptedParentLocationQuestion->setErrorMessage('Option %s is invalid.');

            $locationToSwapQuestion = new Question('Please provide ID of Location to swap:');
            $locationToSwapQuestion->setValidator(function ($answer) {
                try {
                    $this->locationService->loadLocation((int) $answer);
                } catch (\Exception $exception) {
                    throw new \RuntimeException(sprintf('Could not load location with ID %s. Please, try again.', $answer));
                }

                return $answer;
            });

            foreach ($corruptedContent as $singleCorruptedContent) {
                $corruptedLocations = $this->contentGateway->getAllLocationIds($singleCorruptedContent->id);

                $action = self::ACTION_DELETE;
                foreach ($corruptedLocations as $corruptedLocation) {
                    if ($this->hasLocationChildren((int) $corruptedLocation)) {
                        $this->io->text('');
                        $this->io->warning(
                            sprintf('Location %d of Content %d has children, which will be removed as well.',
                                $corruptedLocation,
                                $singleCorruptedContent->id
                            )
                        );
                        $action = $helper->ask($input, $output, $corruptedParentLocationQuestion);

                        if ($action === self::ACTION_SKIP) {
                            break;
                        }

                        if ($action === self::ACTION_SWAP) {
                            $locationToSwap = $helper->ask($input, $output, $locationToSwapQuestion);
                            $this->swapLocation((int) $corruptedLocation, (int) $locationToSwap);
                            continue;
                        }
                    }
                }

                if ($action === self::ACTION_DELETE) {
                    $this->persistenceHandler->contentHandler()->deleteContent($singleCorruptedContent->id);
                    $this->contentGateway->removeContentFromTrash($singleCorruptedContent->id);
                }
                $progressBar->advance(1);
            }

            $this->io->text('');
        }
    }
}
