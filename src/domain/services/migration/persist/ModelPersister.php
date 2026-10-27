<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedEventGraph;

/**
 * Class ModelPersister
 *
 * The Persister a real run writes through: events go to EventGraphPersister,
 * bookings to BookingPersister. DryRunPersister is the other implementation.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class ModelPersister implements Persister
{
    private EventGraphPersister $events;

    private BookingPersister $bookings;


    public function __construct(EventGraphPersister $events, BookingPersister $bookings)
    {
        $this->events   = $events;
        $this->bookings = $bookings;
    }


    /**
     * {@inheritDoc}
     *
     * Writes the event graph through EventGraphPersister.
     *
     * @since 0.0.1.000
     */
    public function persistEvent(MappedEventGraph $graph, MigrationContext $context): PersistedEvent
    {
        return $this->events->persistEvent($graph, $context);
    }


    /**
     * {@inheritDoc}
     *
     * Writes the booking through BookingPersister.
     *
     * @since 0.0.1.000
     */
    public function persistBooking(MappedBooking $booking, PersistedEvent $event, MigrationContext $context): PersistedBooking
    {
        return $this->bookings->persistBooking($booking, $event, $context);
    }
}
// End of file ModelPersister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/ModelPersister.php
