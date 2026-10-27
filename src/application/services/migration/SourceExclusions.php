<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Interface SourceExclusions
 *
 * Optional for a SourceAdapter: source records the adapter leaves out of a run
 * that no migrated record can carry a warning for, e.g. tickets attached to a
 * post that is not an event (REQ-016). The run records one warning for each.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
interface SourceExclusions
{
    /**
     * Warnings for the records left out under the chosen options.
     *
     * @return MigrationWarning[]
     */
    public function exclusions(MigrationOptions $options): array;
}
// End of file SourceExclusions.php
// Location: EventEspresso\EventMigrator\application\services\migration/SourceExclusions.php
