<?php


namespace AlgoliaSyncModuleDirectLease;


use SilverStripe\ORM\DataObject;

/**
 * Used to track which pages have been deleted. Used in the onBeforeDelete hook in the PageAlgoliaExtension.
 * @package AlgoliaSyncModuleDirectLease
 */
class DeletedPageAlgoliaObjectIDHolder extends DataObject
{
    private static $table_name = 'DeletedAlgoliaObjectIDHolder';
    private static $singular_name = 'DeletedAlgoliaObjectIDHolder';
    private static $plural_name = 'DeletedAlgoliaObjectIDHolder';
    
    private static $db = [
        'AlgoliaObjectID' => 'Int'
    ];
    
    
}