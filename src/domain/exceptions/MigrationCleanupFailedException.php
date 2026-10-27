<?php

namespace EventEspresso\EventMigrator\domain\exceptions;

use Exception;

/**
 * Class MigrationCleanupFailedException
 *
 * Thrown when compensation after a failed record cannot prove that what the
 * record created is gone. Deliberately not a RuntimeException: the batch job
 * treats RuntimeException (MigrationRecordException) as "skip this record and
 * continue", while this must stop the run, because continuing past an
 * unremovable partial graph would misreport the run as clean.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationCleanupFailedException extends Exception
{
}
// End of file MigrationCleanupFailedException.php
// Location: EventEspresso\EventMigrator\domain\exceptions/MigrationCleanupFailedException.php
