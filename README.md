## Description
This bundle allows you to check your database against know database corruption and fixes them. 
Also, you can perform a Smoke Test on your project to determine if all Contents are accessible (ignoring permissions).

### Supported database corruptions:
- Content without version (fixed by removing corrupted content)
- Content without attributes (fixed by removing corrupted content)
- Content with duplicated attributes (fixed by removing duplicated attributes)

## Usage
Bundle adds `db-checker` SiteAccess with `cache_pool` set to [NullAdapter](https://github.com/symfony/symfony/blob/3.4/src/Symfony/Component/Cache/Adapter/NullAdapter.php)
so no SPI cache is used when retrieving Content from the database during Smoke Test.
If corruption is found, you will be asked if you want to fix it.

*Fixing corruption will modify your database! Always perform the database backup before running this command!*

```
php -d memory_limit=-1 bin/console ezplatform:database-health-check --siteaccess=db-checker
```
Please note that Command may run for a long time (depending on project size). You can speed it up by skipping Smoke Testing with `--skip-smoke-test` option.

After running command is recommended to [regenerate URL aliases](https://doc.ezplatform.com/en/2.5/guide/url_management/#regenerating-url-aliases), clear persistence cache and [reindex](https://doc.ezplatform.com/en/2.5/guide/search/#reindexing).

## Installation
### Requirements
This bundle requires eZ Platform 2.5+

### 1. Enable `EzPlatformDatabaseHealthCheckerBundle`
Edit `app/AppKernel.php`, and add 
```
                $bundles[] = new MateuszBieniek\EzPlatformDatabaseHealthCheckerBundle\EzPlatformDatabaseHealthCheckerBundle();
```
at the end of list of bundles in `dev` environment.

### 2. Install `mateuszbieniek/ezplatform-database-health-checker`
```
composer require mateuszbieniek/ezplatform-database-health-checker
```