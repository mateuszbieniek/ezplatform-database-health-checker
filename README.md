## Description
This bundle allows you to check your database against know database corruption and fix them. 
Also, you can perform a Smoke Test on your project to determine if all Contents are accessible (ignoring permissions).
The additional functionality is cleaning up your database from leftovers left by `ezplatform-page-fieldtype` bundle. 

### Supported database corruptions:
- Content without version (fixed by removing corrupted content)
- Content without attributes (fixed by removing corrupted content)
- Content with duplicated attributes (fixed by removing duplicated attributes)
- Page FieldType related records which are unnecessary and cause flooding

## Usage
The following bundle introduces two commands: `ezplatform:database-health-check` and `ezplatform:page-fieldtype-cleanup`.

*Fixing corruptions will modify your database! Always perform the database backup before running those commands!*

After running those commands it is recommended to [regenerate URL aliases](https://doc.ezplatform.com/en/2.5/guide/url_management/#regenerating-url-aliases), clear persistence cache and [reindex](https://doc.ezplatform.com/en/2.5/guide/search/#reindexing).

### ezplatform:database-health-check
Bundle adds `db-checker` SiteAccess with `cache_pool` set to [NullAdapter](https://github.com/symfony/symfony/blob/3.4/src/Symfony/Component/Cache/Adapter/NullAdapter.php)
so no SPI cache is used when retrieving Content from the database during Smoke Test.
If corruption is found, you will be asked if you want to fix it.

All Content's location will be checked for subitems, before removing it. In the case of existing subitems, you will be 
presented with an option to swap location with a different one, so subitems are preserved (Content won't be deleted after
swap so script has to be re-run if you wish to delete corrupted Content).

```
php -d memory_limit=-1 bin/console ezplatform:database-health-check --siteaccess=db-checker
```
Please note that Command may run for a long time (depending on project size). You can speed it up by skipping Smoke Testing with `--skip-smoke-test` option.

### ezplatform:page-fieldtype-cleanup
*Warning! This command is only available for Enterprise versions of the platform.*

This command searches your database for `ezpage_*` records which are leftovers from https://issues.ibexa.co/browse/EZEE-3430
and deletes them if necessary to prevent uncontrolled growth of the database.

```
php -d memory_limit=-1 bin/console ezplatform:page-fieldtype-cleanup
```

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