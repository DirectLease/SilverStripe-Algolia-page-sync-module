<?php


namespace AlgoliaSyncModuleDirectLease;


use SilverStripe\ORM\DataObject;

/**
 * Used for track which pages have been synced to Algolia.
 * @package AlgoliaSyncModuleDirectLease
 */
class PageAlgoliaObjectIDHolder extends DataObject
{
    private static $table_name = 'PageAlgoliaObjectIDHolder';
    private static $singular_name = 'PageAlgoliaObjectIDHolder';
    private static $plural_name = 'PageAlgoliaObjectIDHolder';
    
    private static $db = [
        'AlgoliaObjectID' => 'Int'
    ];
    
    
}