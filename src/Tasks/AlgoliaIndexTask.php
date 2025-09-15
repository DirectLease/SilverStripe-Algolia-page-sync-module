<?php

namespace AlgoliaSyncModuleDirectLease;

use Algolia\AlgoliaSearch\SearchClient;
use Exception;
use Page; // Assuming Page is in the root namespace
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TractorCow\Fluent\State\FluentState;

// Assuming these DataObjects exist in your project.
// You might need to adjust the namespace.
use App\Models\AlgoliaSyncLog;
use App\Models\DeletedPageAlgoliaObjectIDHolder;
use App\Models\PageAlgoliaObjectIDHolder;

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
    protected static string $commandName = 'app:algolia-index';
    protected string $title = 'DirectLease AlgoliaIndexTask';
    protected static string $description = "Synchronizes all published Pages to Algolia where ShowInSearch is enabled.";

    private bool $fluent_enabled = false;
    private ?LoggerInterface $logger = null;

    /**
     * Define command-line options like --fullsync.
     */
    public function getOptions(): array
    {
        return [
            new InputOption('fullsync', null, InputOption::VALUE_NONE, 'Perform a full sync, clearing all existing objects first.'),
        ];
    }

    /**
     * The main execution logic, replacing the old run() method.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->logger = $output->getLogger();

        try {
            $client = SearchClient::create(
                Config::inst()->get('AlgoliaKeys', 'applicationId'),
                Config::inst()->get('AlgoliaKeys', 'adminApiKey')
            );
            $index = $client->initIndex(
                Config::inst()->get('AlgoliaKeys', 'indexName')
            );

            $this->fluent_enabled = class_exists(FluentState::class) && Page::has_extension("TractorCow\Fluent\Extension\FluentExtension");

            if ($input->getOption('fullsync')) {
                $this->fullSync($index);
            } else {
                $this->syncChanges($index);
            }

            $output->writeln("✅ Task finished. Check the logs and the 'AlgoliaSyncLog' database table for details.");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logError("A critical error occurred in AlgoliaIndexTask: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Remove state and remove all objects in Algolia index. Then add them again for a fresh state.
     */
    private function fullSync($index): void
    {
        try {
            $this->logInfo("Starting full sync...");
            $index->clearObjects();
            $deletedCount = $this->deleteAllPageAlgoliaObjectIDHolder();
            $this->deleteAllDeletedPageAlgoliaObjectIDHolder();
            $pages = Versioned::get_by_stage('Page', 'Live')->filter('ShowInSearch', true);
            $syncCount = $this->syncPagesWithIndex($index, $pages);
            $this->createLogDataObject(true, $syncCount, 0, $deletedCount);
            $this->logInfo("Successfully did a full sync with page count: " . $syncCount);
        } catch (Exception $e) {
            $this->logError("Error during full Algolia SYNC with message: " . $e->getMessage());
        }
    }

    /**
     * Sync only pages with changes since the last sync, removed pages and added pages
     */
    private function syncChanges($index): void
    {
        try {
            if (AlgoliaSyncLog::get()->count() === 0) {
                $this->logInfo("A normal sync has been requested but there is no sync history. A fullSync will now run to create a sync history.");
                $this->fullSync($index);
                return;
            }

            $this->logInfo("Starting incremental sync...");
            $deletedCount = $this->deleteAlgoliaObjectsForIDs($index);
            $updatedCount = $this->getChangedPagesAndUpdateAlgolia($index);
            $addedCount = $this->addNewCreatedPagesToAlgolia($index);
            $this->createLogDataObject(false, $addedCount, $updatedCount, $deletedCount);
            $this->logInfo("Incremental sync finished. Added: $addedCount, Updated: $updatedCount, Deleted: $deletedCount");
        } catch (Exception $e) {
            $this->logError("Error during Algolia SYNC with message: " . $e->getMessage());
        }
    }

    /**
     * Add pages to the Algolia index
     * @throws ValidationException
     */
    private function syncPagesWithIndex($index, $pages, bool $update = false): int
    {
        $dataForAlgolia = [];
        foreach ($pages as $page) {
            $algoliaObject = [];
            $algoliaObject = $this->addFieldDataToObjectIfsetOnPage($page, Config::inst()->get('AlgoliaSyncFieldsNonLocalised'), $algoliaObject);
            $algoliaObject = $this->addImageLinkToObjectIfSetOnPage($page, Config::inst()->get('AlgoliaSyncImagesNonLocalised'), $algoliaObject);

            if ($this->fluent_enabled) {
                $algoliaObject = $this->addDataForEveryLocale($page, $algoliaObject);
            } else {
                $algoliaObject = $this->addDefaultData($page, $algoliaObject);
            }

            $algoliaObject['objectID'] = $page->ID;
            $algoliaObject['ClassName'] = $page->ClassName;
            $dataForAlgolia[] = $algoliaObject;
        }

        if (!empty($dataForAlgolia)) {
            $index->saveObjects($dataForAlgolia, ['autoGenerateObjectIDIfNotExist' => true]);
        }
        
        if (!$update) {
            $this->syncCreatedPagesWithPageAlgoliaObjectIDHolders($pages);
        }
        return count($dataForAlgolia);
    }

    /**
     * Add localised data for every locale to the Algolia object
     */
    private function addDataForEveryLocale($page, array $algoliaObject): array
    {
        $locales = $page->getLocaleInstances();
        $algoliaObject['Locales'] = [];
        foreach ($locales as $locale) {
            $algoliaObject['Locales'][$locale->Locale] = [];
            $algoliaObject['Locales'][$locale->Locale] = FluentState::singleton()
                ->withState(function (FluentState $state) use ($locale, $page) {
                    $state->setLocale($locale->Locale);
                    $objectForLocalisedData = [];
                    $pageInLocale = Versioned::get_by_stage('Page', 'Live')->byID($page->ID);
                    if (!$pageInLocale) {
                        return [];
                    }
                    $objectForLocalisedData = $this->addDefaultData($pageInLocale, $objectForLocalisedData);
                    $objectForLocalisedData = $this->addFieldDataToObjectIfsetOnPage($pageInLocale, Config::inst()->get('AlgoliaSyncFieldsLocalised'), $objectForLocalisedData);
                    return $this->addImageLinkToObjectIfSetOnPage($pageInLocale, Config::inst()->get('AlgoliaSyncImagesLocalised'), $objectForLocalisedData);
                });
        }
        return $algoliaObject;
    }

    /**
     * Add default data to the algoliaObject
     */
    private function addDefaultData($page, array $algoliaObject): array
    {
        $algoliaObject['Title'] = $page->Title;
        if ($page instanceof RedirectorPage) {
            $link = $page->Link();
            $link = str_replace("/?stage=Stage", "", $link);
            $algoliaObject['Url'] = $link;
        } else {
            $algoliaObject['Url'] = $page->Link();
        }
        $algoliaObject['MenuTitle'] = $page->MenuTitle;
        return $algoliaObject;
    }

    /**
     * For every field defined in the config yml, check if the page has that field.
     */
    private function addFieldDataToObjectIfsetOnPage($page, ?array $config, array $object): array
    {
        if ($config) {
            foreach ($config as $value) {
                if (isset($page->{$value}) && $pageValue = $page->{$value}) {
                    $object[$value] = $pageValue;
                }
            }
        }
        return $object;
    }

    /**
     * For every image in the config yml, check if the page has that Image.
     */
    private function addImageLinkToObjectIfSetOnPage($page, ?array $config, array $object): array
    {
        if ($config) {
            foreach ($config as $value) {
                if (isset($page->{$value . "ID"}) && $page->{$value}()) {
                    if ($link = $page->{$value}()->Link()) {
                        $object[$value] = $link;
                    }
                }
            }
        }
        return $object;
    }

    /**
     * Create PageAlgoliaObjectIDHolder for every added page in Algolia
     * @throws ValidationException
     */
    private function syncCreatedPagesWithPageAlgoliaObjectIDHolders($pages): void
    {
        foreach ($pages as $page) {
            $pageAlgoliaObjectHolder = PageAlgoliaObjectIDHolder::create();
            $pageAlgoliaObjectHolder->AlgoliaObjectID = $page->ID;
            $pageAlgoliaObjectHolder->write();
        }
    }

    /**
     * remove all AlgoliaObjects of which the page has been removed, or ShowInSearch in the CMS has been set to false
     */
    private function deleteAlgoliaObjectsForIDs($index): int
    {
        $deletedPageAlgoliaObjectIDHolders = DeletedPageAlgoliaObjectIDHolder::get();
        $arrayDeletedAlgoliaObjectIDs = $deletedPageAlgoliaObjectIDHolders ? $deletedPageAlgoliaObjectIDHolders->map('ID', 'AlgoliaObjectID')->values() : [];

        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        if (!empty($syncedPages)) {
            $pagesWithShowInSearchSetToFalse = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => false, 'ID' => $syncedPages]);
            foreach ($pagesWithShowInSearchSetToFalse as $page) {
                $arrayDeletedAlgoliaObjectIDs[] = $page->ID;
            }
        }
        
        $deletedCount = count($arrayDeletedAlgoliaObjectIDs);
        if (!empty($arrayDeletedAlgoliaObjectIDs)) {
            $index->deleteObjects($arrayDeletedAlgoliaObjectIDs);
            foreach ($deletedPageAlgoliaObjectIDHolders as $holder) {
                $holder->delete();
            }
            if (!empty($syncedPages)) {
                foreach ($pagesWithShowInSearchSetToFalse as $page) {
                    $holder = PageAlgoliaObjectIDHolder::get()->filter('AlgoliaObjectID', $page->ID)->first();
                    if ($holder) {
                        $holder->delete();
                    }
                }
            }
        }

        $this->logInfo("Successfully removed pages from Algolia. Number of deleted page objects: " . $deletedCount);
        return $deletedCount;
    }

    /**
     * Add newly created Pages to Algolia
     * @throws ValidationException
     */
    private function addNewCreatedPagesToAlgolia($index): int
    {
        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        $pages = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => true])->exclude('ID', $syncedPages);
        $syncCount = $this->syncPagesWithIndex($index, $pages);
        $this->logInfo('Successfully synced new created pages. Number of new pages synced: ' . $syncCount);
        return $syncCount;
    }

    /**
     * All the pages that have been synced to Algolia and which have changed since the last sync will be updated.
     * @throws ValidationException
     */
    private function getChangedPagesAndUpdateAlgolia($index): int
    {
        $syncedPages = PageAlgoliaObjectIDHolder::get()->map("ID", 'AlgoliaObjectID')->values();
        if ($syncedPages) {
            $date = AlgoliaSyncLog::get()->sort("SyncDate", "DESC")->first()->SyncDate;
            $pages = Versioned::get_by_stage('Page', 'Live')->filter(['ShowInSearch' => true, 'ID' => $syncedPages, 'LastEdited:GreaterThan' => $date]);
            $count = $pages->count();
            if ($count > 0) {
                $this->syncPagesWithIndex($index, $pages, true);
            }
            $this->logInfo('Successfully updated pages. Number of pages updated: ' . $count);
            return $count;
        }
        $this->logInfo('No pages to update. This might be because no pages have ever been synced.');
        return 0;
    }

    /**
     * Empty table PageAlgoliaObjectIDHolder
     */
    private function deleteAllPageAlgoliaObjectIDHolder(): int
    {
        $deleteCount = PageAlgoliaObjectIDHolder::get()->count();
        DB::query("DELETE FROM PageAlgoliaObjectIDHolder");
        $this->logInfo('Successfully deleted all PageAlgoliaObjectIDHolder. Number of PageAlgoliaObjectIDHolder deleted: ' . $deleteCount);
        return $deleteCount;
    }

    /**
     * Empty table DeletedAlgoliaObjectIDHolder
     */
    private function deleteAllDeletedPageAlgoliaObjectIDHolder(): void
    {
        DB::query("DELETE FROM DeletedAlgoliaObjectIDHolder");
        $this->logInfo('Successfully deleted all DeletedPageAlgoliaObjectIDHolders.');
    }

    /**
     * Create a log object containing information about the task
     * @throws ValidationException
     */
    private function createLogDataObject(bool $fullSync, int $addedCount, int $updatedCount, int $deletedCount): void
    {
        $log = AlgoliaSyncLog::create();
        $log->FullSync = $fullSync;
        $log->SyncDate = date('Y-m-d H:i:s');
        $log->AddedCount = $addedCount;
        $log->UpdatedCount = $updatedCount;
        $log->DeletedCount = $deletedCount;
        $log->write();
    }

    /**
     * Append error log message using the injected logger.
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        }
    }

    /**
     * Append info log message using the injected logger.
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }
}
