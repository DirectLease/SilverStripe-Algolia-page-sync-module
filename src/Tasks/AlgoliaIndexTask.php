<?php

namespace AlgoliaSyncModuleDirectLease;

use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use SilverStripe\ORM\DB;
use Algolia\AlgoliaSearch\SearchClient;
use TractorCow\Fluent\State\FluentState;


/**
 * Class AlgoliaIndexTask
 *
 * This task will connect with your algolia environment based on the provided configuration and sync Pages to algolia.
 * The task creates algolia objects containing data and also provides a solution to sync localised data.
 * For more information about the task see the README.MD
 *
 * @package AlgoliaSyncModuleDirectLease
 */
class AlgoliaIndexTask extends BuildTask
{

    protected $title = 'DirectLease AlgoliaIndexTask';
    protected $description = "This task will synchronize all published Pages with the Page value ShowInSearch(see CMS->Page->Settings) on true to Algolia";

    protected $enabled = true;
    protected $fluent_enabled = false;

    public function run($request)
    {
        // create algolia client based on the config variables
        $client = SearchClient::create(
            Config::inst()->get('AlgoliaKeys', 'applicationId'),
            Config::inst()->get('AlgoliaKeys', 'adminApiKey')
        );
        $index = $client->initIndex(
            Config::inst()->get('AlgoliaKeys', 'indexName')
        );
        // Check if Fluent is installed if it is enabled will add locales object to algolia containing localised data
        $this->fluent_enabled = \Page::has_extension("TractorCow\Fluent\Extension\FluentExtension");
        // do either a fullsync add/remove all algolia data or sync the changes from the last task
        if($request->getVar('fullsync')) {
            $this->fullSync($index);
        }
        else {
            $this->syncChanges($index);
        }
    }

    /**
     * Remove state and remove all objects in Algolia index. Then add them again for a fresh state.
     *
     * @param $index algolia index
     */
    private function fullSync($index) {
        try {
            $index->clearObjects(); // remove all existing objects in algolia
            $deletedCount = $this->deleteAllPageAlgoliaObjectIDHolder(); // remove all existing objects
            $this->deleteAllDeletedPageAlgoliaObjectIDHolder(); // remove all existing objects
            $pages = Versioned::get_by_stage('Page', 'Live')->filter('ShowInSearch', true);
            $syncCount = $this->syncPagesWithIndex($index, $pages);
            $this->createLogDataObject(true, $syncCount, 0, $deletedCount); // create a log object containing information about the sync
            $this->logInfo("Successfully did a full sync with page count:". $syncCount);
        } catch (Exception $e) {
            $this->logError("Error during full Algolia SYNC with message: ".$e->getMessage());
        }
    }

    /**
     * Add pages to the Algolia index
     *
     * For every entry in the AlgoliaSyncFields & AlgoliaSyncImages (for both the Localised and NonLocalised varieties) in the yml, add the data to the Algolia object.
     * These are either datafields or url's of images, and can be localised as well as non-localised values.
     *
     * @param $index algolia index
     * @param $pages pages that need to be added to the index
     * @param $update boolean If the sync is an update, if false it creates a PageAlgoliaObjectIDHolder
     * @return int the count of pages being synced
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function syncPagesWithIndex($index, $pages, $update = false) {
        $dataForAlgolia = [];
        foreach ($pages as $page) {
            $algoliaObject = [];
            $algoliaObject['objectID'] = $page->ID; //PageID used as ID
            $algoliaObject['ClassName'] = $page->ClassName;
            // add fieldvalue for key in config yml array in algolia.yml
            $algoliaObject = $this->addFieldDataToObjectIfsetOnPage($page, Config::inst()->get('AlgoliaSyncFieldsNonLocalised'), $algoliaObject);
            // add image Link if in config yml array in algolia.yml
            $algoliaObject = $this->addImageLinkToObjectIfSetOnPage($page, Config::inst()->get('AlgoliaSyncImagesNonLocalised'), $algoliaObject);
           // If Fluent is installed add localised data
           if ($this->fluent_enabled) {
                $algoliaObject = $this->addDataForEveryLocale($page, $algoliaObject);
           }
           // add default config not for every locale but at the root of algoliaobject
           else {
               $algoliaObject = $this->addDefaultData($page, $algoliaObject);
           }
           $dataForAlgolia[] = $algoliaObject;
    
        }
        $index->saveObjects($dataForAlgolia, ['autoGenerateObjectIDIfNotExist' => true]);

        if (!$update) {
            $this->syncCreatedPagesWithPageAlgoliaObjectIDHolders($pages);
        }
        return sizeof($dataForAlgolia);
    }
    
    /**
     * Add localised data for every locale to the Algolia object
     * 
     * If Fluent is enabled, a page might have DB/Images values that are different in every locale (localised).
     * If these variables are set in AlgoliaSyncFieldslocalised or AlgoliaSyncImageslocalised, they will be added to the algoliaObject by this method.
     * This will result in: $algoliaObject->Locales->Locale->Key => value
     * 
     * @param $page
     * @param $algoliaObject
     * @return mixed
     */
    private function addDataForEveryLocale($page, $algoliaObject) {
        $locales = $page->getLocaleInstances();
        foreach ($locales as $locale) {
            $algoliaObject = FluentState::singleton()
                ->withState(function (FluentState $state) use ($locale, $page, $algoliaObject) {
                    $state->setLocale($locale->Locale);
                    // we need to get the page again since our fluent context is changed and we want to get the localised data
                    $page = Versioned::get_by_stage('Page', 'Live')->byID($page->ID);
                    $algoliaObject['Locales'][$locale->Locale] = $this->addDefaultData($page, $algoliaObject);
                    // add fieldvalue for key in config yml array in algolia.yml
                    $algoliaObject['Locales'][$locale->Locale] = $this->addFieldDataToObjectIfsetOnPage($page, Config::inst()->get('AlgoliaSyncFieldsLocalised'), $algoliaObject['Locales'][$locale->Locale]);
                    // add image Link if in config yml array in algolia.yml
                    $algoliaObject['Locales'][$locale->Locale] = $this->addImageLinkToObjectIfSetOnPage($page, Config::inst()->get('AlgoliaSyncImagesLocalised'), $algoliaObject['Locales'][$locale->Locale]);
                
                    return $algoliaObject;
                });
        }
        return $algoliaObject;
    }
    
    /**
     * Add default data to the algoliaObject
     *
     * Adds title, url and menutitle to the algoliaObject
     *
     * @param $page
     * @param $algoliaObject
     * @return mixed
     */
    private function addDefaultData($page, $algoliaObject) {
        $algoliaObject['Title'] = $page->Title;
        // in current context we get the stage url. we do not want to use this in algolia
        if ($page->ClassName == RedirectorPage::class) {
            $link = $page->Link();
            $link = str_replace("/?stage=Stage", "", $link);
            $algoliaObject['Url'] = $link;
        } // normal pages will return a normal urL
        else {
            $algoliaObject['Url'] = $page->Link();
        }
        $algoliaObject['MenuTitle'] = $page->MenuTitle;
        return $algoliaObject;
    }
    
    /**
     * For every field defined in the config yml, check if the page has that field. If it contains data, add it to the object.
     *
     * @param $page
     * @param $config yaml fieldNames: if they exist on the page, they will be added to the object that will be synced to Algolia
     * @param $object object object to which the data needs to be added
     * @return mixed
     */
    private function addFieldDataToObjectIfsetOnPage($page, $config, $object) {
        if ($config) {
            foreach ($config as $value) {
                if (isset($page->{$value}) && $pageValue = $page->{$value}) {
                    $object[$value] = $pageValue;
                }
            }
            return $object;
        }
        return $object;
    }

    /**
     * For every image in the config yml, check if the page has that Image. If it is set, add the Link() to the object.
     *
     * @param $page
     * @param $config yaml has_one image object relation names: if they exist on the page, the Link() will be added to the object that will be synced to Algolia
     * @param $object object object to which the data needs to be added
     * @return mixed
     */
    private function addImageLinkToObjectIfSetOnPage($page, $config, $object) {
        if ($config) {
            foreach ($config as $value) {
                if (isset($page->{$value."ID"}) && $page->{$value}()) {
                    if($link = $page->{$value}()->Link()) {
                        $object[$value] = $link;
                    }
                }
            }
            return $object;
        }
        return $object;
    }

    /**
     * Create PageAlgoliaObjectIDHolder for every added page in Algolia
     *
     * For every Page create an holder object containing a reference to the AlgoliaObject
     * Since we set the ID of the algolia Object equal to our Page ID we can use Page->ID as the reference
     *
     * @param $pages
     * @param $savedObjectsResponse
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function syncCreatedPagesWithPageAlgoliaObjectIDHolders($pages) {
        foreach($pages as $key => $page) {
            $pageAlgoliaObjectHolder = PageAlgoliaObjectIDHolder::create();
            $pageAlgoliaObjectHolder->AlgoliaObjectID = $page->ID;
            $pageAlgoliaObjectHolder->write();
        }
    }

    /**
     * Sync only pages with changes since the last sync, removed pages and added pages
     *
     * When a full sync has been done, we have a reference point and from their we can sync onl the changes.
     * Holders were created, containing ID's of the pages that have been deleted. We can remove those from algolia.
     * There is now a last sync date. We can get the pages that have been changed since that day and update those in Algolia.
     * All the pages that do not have a holder are not synced. Those are the pages being added since the last sync. Add those to Algolia.
     *
     * @param $index
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function syncChanges($index) {
        try {
            // safety check
            if (AlgoliaSyncLog::get()->count() == 0) {
                $this->logInfo("A normal sync has been requested but there is no sync history. So it is not possible to sync the changes only. A fullSync will now run, in order to create a sync history.");
                return $this->fullSync($index);
            }
            // remove all deleted pages
            $deletedCount = $this->deleteAlgoliaObjectsForIDs($index);
            // update pages that changed in the last 24 hours
            $updatedCount = $this->getChangedPagesAndUpdateAlgolia($index);
            // add new pages
            $addedCount = $this->addNewCreatedPagesToAlgolia($index);

            $this->createLogDataObject(false, $addedCount, $updatedCount, $deletedCount); // create a log object containg information about teh sync

        } catch (Exception $e) {
            $this->logError("Error during Algolia SYNC with message: ".$e->getMessage());
        }
    }

    /**
     * remove all AlgoliaObjects of which the page has been removed, or ShowInSearch in the CMS has been set to false
     *
     * Get all the holders containing ID's of pages that have been removed.
     * Get all the Page->ID's that have been synced and where ShowInSearch has been changed to false.
     * and delete those objects in algolia.
     * Clean up all the holders not needed anymore.
     *
     * @param $index
     * @return int deleted count
     */
    private function deleteAlgoliaObjectsForIDs($index) {
        $deletedPageAlgoliaObjectIDHolders = DeletedPageAlgoliaObjectIDHolder::get();
        $arrayDeletedAlgoliaObjectIDs = $deletedPageAlgoliaObjectIDHolders ? $deletedPageAlgoliaObjectIDHolders->map('ID','AlgoliaObjectID')->values(): [];

        // if showInSearch has been changed we need to delete that pages as well so add it to the list
        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        $pagesWithShowInSearchSetToFalse = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => false, 'ID' => $syncedPages]);
        foreach ($pagesWithShowInSearchSetToFalse as $page) {
            array_push($arrayDeletedAlgoliaObjectIDs, $page->ID);
        }
        $deletedCount = count($arrayDeletedAlgoliaObjectIDs);
        if ($arrayDeletedAlgoliaObjectIDs) {
            $index->deleteObjects($arrayDeletedAlgoliaObjectIDs);
            // delete DB objects since algolia has been synced with removed objects.
            foreach ($deletedPageAlgoliaObjectIDHolders as $holder) {
                $holder->delete();
            }
            // delete holder for page with ShowInSearch set to false
            foreach ($pagesWithShowInSearchSetToFalse as $page){
                $holder = PageAlgoliaObjectIDHolder::get()->filter('AlgoliaObjectID',$page->ID)->first();
                if($holder) {
                    $holder->delete();
                }
            }
        }


        $this->logInfo("Successfully removed pages from Algolia. Number of deleted page objects: ".$deletedCount);
        return $deletedCount;
    }

    /**
     * Add newly created Pages to Algolia
     * 
     * Compare the PageAlgoliaObjectIDHolderIDs against the PageIDs to find pages that have not been synced yet. Sync those pages to Algolia.
     * 
     * @param $index algolia index
     * @return int count of added Pages
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function addNewCreatedPagesToAlgolia($index) {
        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        $pages = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => true, 'ID:not' => $syncedPages]);
        $syncCount = $this->syncPagesWithIndex($index, $pages);
        $this->logInfo('Successfully synced new created pages. Number of new pages synced: '. $syncCount);
        return $pages->count();
    }


    /**
     * All the pages that have been synced to Algolia and which have changed in the past 24 hours will be updated.
     *
     * @param $index
     * @param int updated page count
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function getChangedPagesAndUpdateAlgolia($index) {
        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        if($syncedPages) {
            $date = AlgoliaSyncLog::get()->sort("SyncDate", "DESC")->first()->SyncDate;
            $pages = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => true, 'ID' => $syncedPages, 'LastEdited:GreaterThan' => $date]);
            if($count = $pages->count() > 0) {
                $this->syncPagesWithIndex($index, $pages, true);
            }
            $this->logInfo('Successfully updated pages. Number of pages updated: '. $count);
            return $count;
        }
        $this->logInfo('Successfully updated pages: 0. This is because there were no pages synced so something is going wrong.');
        return 0;

    }

    /**
     * Empty table PageAlgoliaObjectIDHolder
     *
     * @return int count of deleted Items
     */
    private function deleteAllPageAlgoliaObjectIDHolder(){
        $deleteCount = PageAlgoliaObjectIDHolder::get()->count();
        DB::query("DELETE FROM PageAlgoliaObjectIDHolder");
        $this->logInfo('Successfully deleted all PageAlgoliaObjectIDHolder. Number of PageAlgoliaObjectIDHolder deleted: '.$deleteCount);
        return $deleteCount;
    }

    /**
     * Empty table DeletedAlgoliaObjectIDHolder
     */
    private function deleteAllDeletedPageAlgoliaObjectIDHolder(){
        DB::query("DELETE FROM DeletedAlgoliaObjectIDHolder");
        $this->logInfo('Successfully deleted all DeletedPageAlgoliaObjectIDHolders.');
    }

    /**
     * Create a log object containing information about the task
     *
     * @param $fullSync boolean if it is a fullsync or a normal sync
     * @param $addedCount int
     * @param $updatedCount int
     * @param $deletedCount int
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function createLogDataObject($fullSync, $addedCount, $updatedCount, $deletedCount) {
        $log = AlgoliaSyncLog::create();
        $log->FullSync = $fullSync ? true : false;
        $log->SyncDate =  date('Y-m-d H:i:s');
        $log->AddedCount = $addedCount;
        $log->UpdatedCount = $updatedCount;
        $log->DeletedCount = $deletedCount;
        $log->write();
    }

    /**
     * Append error log message to silverstripe.log
     *
     * @param $message string
     */
    private function logError($message) {
        Injector::inst()->get(LoggerInterface::class)->error($message);
    }

    /**
     * Append info log message to silverstripe.log
     *
     * @param $message string
     */
    private function logInfo($message) {
        Injector::inst()->get(LoggerInterface::class)->info($message);
    }

}