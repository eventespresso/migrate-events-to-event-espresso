<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\exceptions\MigrationCleanupFailedException;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedEventGraph;
use Throwable;

/**
 * Interface Persister
 *
 * What the migration batch job writes through, so a dry run can swap in a
 * persister that writes nothing (REQ-007). Both methods either complete or
 * leave nothing behind: a failure is compensated, then rethrown; a failure
 * whose compensation cannot be proven raises MigrationCleanupFailedException,
 * which stops the run.
 *
 * Implementations: the model-writing persister assembled from
 * EventGraphPersister and the booking persister (Task 2.11), and
 * DryRunPersister (Task 2.14).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
interface Persister
{
    /**
     * Creates the event graph and crosswalks every created record.
     *
     * @throws MigrationCleanupFailedException When a failure could not be fully compensated.
     * @throws Throwable The original failure, after compensation succeeded.
     */
    public function persistEvent(MappedEventGraph $graph, MigrationContext $context): PersistedEvent;


    /**
     * Creates one booking's transaction, registrations, payments, answers and check-ins on an event that
     * has been persisted (now or by an earlier iteration).
     *
     * @throws MigrationCleanupFailedException When a failure could not be fully compensated.
     * @throws Throwable The original failure, after compensation succeeded.
     */
    public function persistBooking(MappedBooking $booking, PersistedEvent $event, MigrationContext $context): PersistedBooking;
}
// End of file Persister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/Persister.php
