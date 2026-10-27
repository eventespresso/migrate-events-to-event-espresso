<?php

namespace EventEspresso\EventMigrator\domain\exceptions;

use RuntimeException;

/**
 * Class MigrationLaunchException
 *
 * A run could not be started or a wizard submission could not be accepted. The
 * message is a translated sentence written by this add-on (never source data)
 * and is already escaped for HTML output, so it can go straight to an admin
 * notice.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationLaunchException extends RuntimeException
{
}
// End of file MigrationLaunchException.php
// Location: EventEspresso\EventMigrator\domain\exceptions/MigrationLaunchException.php
