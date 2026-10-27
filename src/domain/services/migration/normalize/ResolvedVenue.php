<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedVenue;
use InvalidArgumentException;

/**
 * Class ResolvedVenue
 *
 * What the VenueResolver decided for one source venue: link an existing EE
 * venue, or create the carried one. Exactly one of the two is set.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ResolvedVenue
{
    private ?int $existing_id;

    private ?MappedVenue $to_create;

    private bool $reused;


    /**
     * @param int|null         $existing_id The EE venue to link, when one exists.
     * @param MappedVenue|null $to_create   The venue to create when none exists.
     * @param bool             $reused      True when the existing venue was matched by name and address and
     *                                      was not created by a migration run.
     * @throws InvalidArgumentException When both or neither of the venue and the venue to create are given.
     */
    public function __construct(?int $existing_id, ?MappedVenue $to_create, bool $reused)
    {
        if (($existing_id === null) === ($to_create === null)) {
            throw new InvalidArgumentException(
                esc_html__('A resolved venue is either an existing venue or one to create.', 'migrate-events-to-event-espresso')
            );
        }
        $this->existing_id = $existing_id;
        $this->to_create   = $to_create;
        $this->reused      = $reused;
    }


    public function existingId(): ?int
    {
        return $this->existing_id;
    }


    public function toCreate(): ?MappedVenue
    {
        return $this->to_create;
    }


    public function isReused(): bool
    {
        return $this->reused;
    }
}
// End of file ResolvedVenue.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/ResolvedVenue.php
