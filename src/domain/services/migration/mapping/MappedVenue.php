<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class MappedVenue
 *
 * The EE_Venue fields a persister will write for a source venue, plus the
 * source id the crosswalk records it under. Pure data: the fields are
 * already resolved (state id, country ISO) and sanitised.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedVenue
{
    private string $source_id;

    private array $fields;


    /**
     * @param string $source_id The source identifier of the venue.
     * @param array  $fields    EE_Venue field name => value, ready for EE_Venue::new_instance().
     */
    public function __construct(string $source_id, array $fields)
    {
        $this->source_id = $source_id;
        $this->fields    = $fields;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function fields(): array
    {
        return $this->fields;
    }


    /**
     * The hash of the fingerprinted fields, written to the crosswalk when the venue is created.
     */
    public function fingerprint(): string
    {
        return Crosswalk::fingerprint($this->fields, CrosswalkKeys::MODEL_VENUE);
    }
}
// End of file MappedVenue.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedVenue.php
