<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EE_Datetime;
use EE_Event;
use EE_Price;
use EE_Term_Taxonomy;
use EE_Ticket;
use EE_Venue;
use EEM_CPT_Base;
use EEM_Datetime;
use EEM_Event;
use EEM_Price;
use EEM_Term_Taxonomy;
use EEM_Ticket;
use EEM_Venue;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use Throwable;
use EventEspresso\EventMigrator\domain\services\migration\run\ThrowableLogger;

/**
 * Class EventGraphRemover
 *
 * Hard-deletes what one persistEvent() call created, from the ids in its
 * PersistedEvent, and verifies afterwards that every row is gone. Mirrors the
 * CSV importer's remover: blocking relations are detached before
 * delete_permanently(), which silently skips a blocked delete, and success is
 * only proven by exists_by_ID() returning false. Extra meta (the crosswalk
 * keys) is cascaded by delete_permanently(). A venue or category the call
 * created is removed too; ones it only linked are not.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGraphRemover
{
    /**
     * True only when everything the call created provably no longer exists. Any Throwable is logged and
     * becomes false, so callers have a single "could not be proven" signal.
     */
    public function remove(PersistedEvent $persisted): bool
    {
        try {
            foreach ($persisted->ticketIds() as $ticket_id) {
                $ticket = EEM_Ticket::instance()->get_one_by_ID($ticket_id);
                if ($ticket instanceof EE_Ticket) {
                    $ticket->_remove_relations('Price');
                    $ticket->_remove_relations('Datetime');
                    $ticket->delete_permanently();
                }
            }
            foreach ($persisted->priceIds() as $price_id) {
                $price = EEM_Price::instance()->get_one_by_ID($price_id);
                if ($price instanceof EE_Price) {
                    $price->_remove_relations('Ticket');
                    $price->delete_permanently();
                }
            }
            foreach ($persisted->datetimeIds() as $datetime_id) {
                $datetime = EEM_Datetime::instance()->get_one_by_ID($datetime_id);
                if ($datetime instanceof EE_Datetime) {
                    $datetime->delete_permanently();
                }
            }
            $this->removeEvent($persisted->eventId());
            $this->removeCreatedCategories($persisted->createdTermTaxonomyIds());
            $this->removeCreatedVenue($persisted->createdVenueId());
            return $this->allGone($persisted);
        } catch (Throwable $cleanup_exception) {
            ThrowableLogger::log(
                $cleanup_exception,
                sprintf(
                    'EventGraphRemover: failed to clean up partially-created event %d (source %s).',
                    $persisted->eventId(),
                    $persisted->sourceId()
                )
            );
            return false;
        }
    }


    private function removeEvent(int $event_id): void
    {
        if ($event_id === 0) {
            return;
        }
        $event = EEM_Event::instance()->get_one_by_ID($event_id);
        if ($event instanceof EE_Event) {
            $event->_remove_relations('Question_Group');
            wp_delete_object_term_relationships($event_id, EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY);
            $event->delete_permanently();
        }
    }


    /**
     * @param int[] $term_taxonomy_ids
     */
    private function removeCreatedCategories(array $term_taxonomy_ids): void
    {
        foreach ($term_taxonomy_ids as $term_taxonomy_id) {
            $term_taxonomy = EEM_Term_Taxonomy::instance()->get_one_by_ID($term_taxonomy_id);
            if ($term_taxonomy instanceof EE_Term_Taxonomy) {
                // wp_delete_term() bypasses the model, so the crosswalk keys are removed first.
                foreach (CrosswalkKeys::all() as $key) {
                    $term_taxonomy->delete_extra_meta($key);
                }
                wp_delete_term((int) $term_taxonomy->get('term_id'), EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY);
            }
        }
    }


    private function venueInUse(int $venue_id): bool
    {
        return EEM_Event::instance()->count([['VNU_ID' => $venue_id]]) > 0;
    }


    /**
     * Deletes the venue only when no event still links to it.
     */
    private function removeCreatedVenue(?int $venue_id): void
    {
        if ($venue_id === null || $this->venueInUse($venue_id)) {
            return;
        }
        $venue = EEM_Venue::instance()->get_one_by_ID($venue_id);
        if ($venue instanceof EE_Venue) {
            $venue->delete_permanently();
        }
    }


    private function allGone(PersistedEvent $persisted): bool
    {
        // A created venue another event came to use is intentionally kept, so it is not expected to be gone.
        $created_venue = $persisted->createdVenueId();
        $checks        = [
            [EEM_Ticket::instance(), $persisted->ticketIds()],
            [EEM_Price::instance(), $persisted->priceIds()],
            [EEM_Datetime::instance(), $persisted->datetimeIds()],
            [EEM_Term_Taxonomy::instance(), $persisted->createdTermTaxonomyIds()],
            [EEM_Event::instance(), $persisted->eventId() !== 0 ? [$persisted->eventId()] : []],
            [EEM_Venue::instance(), $created_venue !== null && ! $this->venueInUse($created_venue) ? [$created_venue] : []],
        ];
        foreach ($checks as [$model, $ids]) {
            foreach ($ids as $id) {
                if ($model->exists_by_ID($id)) {
                    return false;
                }
            }
        }
        return true;
    }
}
// End of file EventGraphRemover.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/EventGraphRemover.php
