<?php

namespace EventEspresso\EventMigrator\domain\exceptions;

use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use RuntimeException;
use Throwable;

/**
 * Class MigrationRecordException
 *
 * A record-level failure: the current source record cannot be migrated, the
 * run reports it under the carried warning rule and continues with the next
 * record. Extends RuntimeException so the batch job's record-level catch
 * treats it like the CSV importer's row errors; job-level failures use
 * ImportCleanupFailedException instead.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRecordException extends RuntimeException
{
    private string $rule;

    private string $source_type;

    private string $source_id;


    /**
     * @param string         $message     Translated, user-facing reason.
     * @param string         $rule        The WarningRule the run report files this failure under.
     * @param string         $source_type The source entity type, when known.
     * @param string         $source_id   The source entity id, when known.
     * @param Throwable|null $previous    The underlying failure, when any.
     */
    public function __construct(
        string $message,
        string $rule = WarningRule::RECORD_FAILED,
        string $source_type = '',
        string $source_id = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->rule        = $rule;
        $this->source_type = $source_type;
        $this->source_id   = $source_id;
    }


    public function rule(): string
    {
        return $this->rule;
    }


    public function sourceType(): string
    {
        return $this->source_type;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }
}
// End of file MigrationRecordException.php
// Location: EventEspresso\EventMigrator\domain\exceptions/MigrationRecordException.php
