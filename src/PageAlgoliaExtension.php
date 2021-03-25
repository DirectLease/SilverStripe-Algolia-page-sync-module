<?php
namespace AlgoliaSyncModuleDirectLease;

use SilverStripe\ORM\DataExtension;

class PageAlgoliaExtension extends DataExtension
{
    /**
     * To keep track of the state of our SiteTree, we need to track the deleted pages so we can remove those from Algolia.
     * Before a page gets deleted, create an object holding the ID.
     */
    public function onBeforeDelete()
    {
        parent::onBeforeDelete();
        if(DeletedPageAlgoliaObjectIDHolder::get()->filter('AlgoliaObjectID', $this->owner->ID)->count() == 0) {
            $holder = DeletedPageAlgoliaObjectIDHolder::create();
            $holder->AlgoliaObjectID = $this->owner->ID;
            $holder->write();
        }
    }
    
}