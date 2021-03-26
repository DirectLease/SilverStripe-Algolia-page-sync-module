# SilverStripe DirectLease Algolia page sync module

This module packs a solution to sync Pages to Algolia via SilverStripe's BuildTask.
It keeps track of the SiteTree to sync only the changes. It uses the build in SilverStripe "ShowInSearch" to determine if a Page needs to be synced. 

## How it works
Via a Task and some ORM Objects the state of the SiteTree and Algolia can be managed.
The PageAlgoliaExtension is hooked on the Page Class to keep track of the deleted pages.
There are 3 ORM objects being created to keep track of the state:
* The removed pages in the DeletedPageAlgoliaObjectIDHolder
* The synced pages in the PageAlgoliaObjectIDHolder
* The last date of the sync in AlgoliaSyncLog, this also contains some useful information from the synctask

We now can sync for the three conditions: Added, removed and updated pages. 
The AlgoliaIndexTask does this. 
If you want to update your Algolia index with search-results every 12 hours, you can schedule this task for every 12 hours. 
The tasks provides a `fullsync` param to do a full sync (rather than processing only the changes).

Via Config it is possible to add fields/images urls that will be synced to an Algolia index.

It is possible to add localised data if you use Fluent. Via config it is possible to add fields/image urls that will be added in the Algolia object in a local object. This makes it possible to use the Algolia search with search-attributes. For example, you can add as search attributes: Locales.nl_NL and this object will contain the data. Algolia is smart enough to include all the variables in this object.

It will log to SilverStripe.log and a a record will be created after the task has run succesfully in the database.

## Requirements

* SilverStripe ^4.0

Fluent support


## Installation
Not available yet on packagist 
```
composer require directlease/silverstripe-algolia-page-sync-module
```

## License
See [License](LICENSE)

## How to run
If a sync hasn't been run yet, a full sync will happen. You can schedule this task for any given time period to keep your algolia data up to date.

#### Via browser
A sync that only pushes the changes:
```markdown
https://mysite.nl/dev/tasks/AlgoliaSyncModuleDirectLease-AlgoliaIndexTask # A sync that only pushes the changes
```
For a full sync:
```markdown
https://mysite.nl/dev/tasks/AlgoliaSyncModuleDirectLease-AlgoliaIndexTask?fullsync=1 # For a full sync
```
### Via Terminal
A sync that only pushes the changes:
```shell
php vendor/silverstripe/framework/cli-script.php /dev/tasks/AlgoliaSyncModuleDirectLease-AlgoliaIndexTask
```
For a full sync:
```shell
php vendor/silverstripe/framework/cli-script.php /dev/tasks/AlgoliaSyncModuleDirectLease-AlgoliaIndexTask fullsync=1
```

## Configuration
Via config an extension will be set on the Page Class. This will add an onBeforeDelete action to keep track of the deleted pages.
Via config it is possible to add fields that will be synced to Algolia. If the field or image exists on the page, it will be added no matter the page type.

The default values that will be added are:
* Title
* MenuTitle
* PageClass
* URL

It is possible to add your own DBFields/functions for localised or non localised fields. It is also possible to add localised or non-localised Images if a DataObject has a $has_one relation to that image. If you want to return your own data via a method, it is also possible to add this to the fields config (for example: via a method called getMyAwesomeCustomLogic).

##### AlgoliaSyncFieldsLocalised & AlgoliaSyncFieldsNonLocalised
An array containing the fields whose values are being synced to Algolia. The difference between the localised and non-localised keys is: if Fluent is installed, it will put the data in a localised object. The localised object in Algolia will be Locales->Locale->Key = Value, and the non-localised will result in Key = Value.

It is also possible to provide your own data. If you create your own function returning data you need to use the "get" prefix. For example a function called getMyCustomData() can be accessed if you add to the config:
- "MyCustomData" 

The return value of function "getMyCustomData()" if available/not null will be added to the algolia object. In case of a localised object Locales->Locale->MyCustomData = Value, and the non-localised will result in MyCustomData = Value. 

##### AlgoliaSyncImagesLocalised & AlgoliaSyncImagesNonLocalised
An array containing the names of $has_one image relations to be synced to Algolia. If the page has an Image relation, and the image is published, the Link() return will be saved in Algolia. The difference between the localised and non-localised keys is: if Fluent is installed, it will put the data in a localised object. The localised object in Algolia will be Locales->Locale->MyImage = URL, and the non-localised will result in MyImage = URL.
- 'MyImage'

## Example configurations

Basic config:
```yaml
---
name: 'my overide'
After:
  - '#algoliapagesyncmoduleconfig'
---
Page:
  Extensions:
    - AlgoliaSyncModuleDirectLease\PageAlgoliaExtension
AlgoliaKeys:
  adminApiKey: '' # Algolia Admin API KEY
  applicationId: '' # Algolia App ID
  indexName: 'sitecontent' # default name of the index the pages will be synced in 
AlgoliaSyncFieldsLocalised:
AlgoliaSyncFieldsNonLocalised:
AlgoliaSyncImagesLocalised:
AlgoliaSyncImagesNonLocalised:
```

A config with Fluent support: 
```yaml
---
name: 'my overide'
After:
  - '#algoliapagesyncmoduleconfig'
---
Page:
 Extensions:
  - AlgoliaSyncModuleDirectLease\PageAlgoliaExtension
AlgoliaKeys:
  adminApiKey: '' # Algolia Admin API KEY
  applicationId: '' # Algolia App ID
  indexName: 'sitecontent' # default name of the index the pages will be synced in 
AlgoliaSyncFieldsLocalised:
 - "MyAwesomeSearchTextHolder"
 - "MyAwesomeSearchTextHolder2"
AlgoliaSyncFieldsNonLocalised:
 - "MyAwesomeSearchTextHolderNonLocalised"
AlgoliaSyncImagesLocalised:
 - "MyAwesomeImage"
AlgoliaSyncImagesNonLocalised:
 - "MyAwesomeImageButNonLocalised"
```
A config without fluent support:

```yaml
---
name: 'my overide'
After:
  - '#algoliapagesyncmoduleconfig'
---
Page:
 Extensions:
  - AlgoliaSyncModuleDirectLease\PageAlgoliaExtension
AlgoliaKeys:
  adminApiKey: '' # Algolia Admin API KEY
  applicationId: '' # Algolia App ID
  indexName: 'sitecontent' # default name of the index the pages will be synced in 
AlgoliaSyncFieldsLocalised:
AlgoliaSyncFieldsNonLocalised:
 - "MyAwesomeSearchTextHolder"
AlgoliaSyncImagesLocalised:
AlgoliaSyncImagesNonLocalised:
 - "MyAwesomeImage"
 - "MyAwesomeImage2"

```
