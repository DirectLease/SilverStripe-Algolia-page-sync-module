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
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TractorCow\Fluent\State\FluentState;

// Assuming these DataObjects exist in your project
use Your\NameSpace\AlgoliaSyncLog;
use Your\NameSpace\DeletedPageAlgoliaObjectIDHolder;
use Your\NameSpace\PageAlgoliaObjectIDHolder;


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
    protected static string $commandName = 'app:algolia-index'; // A more conventional command name
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
        // Use the logger passed to the execute method for consistent logging.
        $this->logger = $output->getLogger();

        try {
            // Create Algolia client based on the config variables
            $client = SearchClient::create(
                Config::inst()->get('AlgoliaKeys', 'applicationId'),
                Config::inst()->get('AlgoliaKeys', 'adminApiKey')
            );
            $index = $client->initIndex(
                Config::inst()->get('AlgoliaKeys', 'indexName')
            );

            // Check if Fluent is installed
            $this->fluent_enabled = class_exists(FluentState::class) && \Page::has_extension("TractorCow\Fluent\Extension\FluentExtension");

            // Check the --fullsync option from the input
            if ($input->getOption('fullsync')) {
                $this->fullSync($index);
            } else {
                $this->syncChanges($index);
            }

            $output->writeln("Task finished. Check the logs (e.g., silverstripe.log) and the 'AlgoliaSyncLog' database table for details.");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logError("A critical error occurred in AlgoliaIndexTask: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // ... (All other private methods from your original class remain largely the same, but now use $this->logInfo() and $this->logError())

    /**
     * Remove state and remove all objects in Algolia index. Then add them again for a fresh state.
     */
    private function fullSync($index): void
    {
        try {
            $this->logInfo("Starting full sync...");
            $index->clearObjects(); // remove all existing objects in algolia
            $deletedCount = $this->deleteAllPageAlgoliaObjectIDHolder(); // remove
