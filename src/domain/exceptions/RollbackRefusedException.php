<?php

namespace EventEspresso\EventMigrator\domain\exceptions;

use Exception;

/**
 * Class RollbackRefusedException
 *
 * Rollback pre-flight found records the run created that were modified since,
 * or that acquired dependents the run did not create (REQ-010). Carries the
 * refusal list so the report can name each record. Deliberately not a
 * RuntimeException: it must stop the rollback job, not skip a record.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RollbackRefusedException extends Exception
{
    /**
     * @var array<int, array{model: string, id: int, reason: string}>
     */
    private array $refusals;


    /**
     * @param string                                                    $message  Translated, user-facing reason.
     * @param array<int, array{model: string, id: int, reason: string}> $refusals The records that blocked the rollback.
     */
    public function __construct(string $message, array $refusals)
    {
        parent::__construct($message);
        $this->refusals = $refusals;
    }


    /**
     * @return array<int, array{model: string, id: int, reason: string}>
     */
    public function refusals(): array
    {
        return $this->refusals;
    }
}
// End of file RollbackRefusedException.php
// Location: EventEspresso\EventMigrator\domain\exceptions/RollbackRefusedException.php
