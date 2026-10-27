<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class MappedDatetime
 *
 * The EE_Datetime fields a persister will write for one source occurrence.
 * Start and end are UTC DateTimeImmutable; the persister converts them with
 * DateTime::createFromImmutable() at the model boundary. EVT_ID is added by
 * the persister.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedDatetime
{
    private string $source_id;

    private array $fields;


    /**
     * @param string            $source_id   The source identifier of the occurrence.
     * @param DateTimeImmutable $start       The UTC start.
     * @param DateTimeImmutable $end         The UTC end.
     * @param string            $name        DTT_name.
     * @param string            $description DTT_description (sanitised).
     * @param float             $reg_limit   DTT_reg_limit; EE_INF for unlimited.
     */
    public function __construct(
        string $source_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $name,
        string $description,
        float $reg_limit
    ) {
        $this->source_id = $source_id;
        $this->fields    = [
            'DTT_name'        => $name,
            'DTT_description' => $description,
            'DTT_EVT_start'   => $start,
            'DTT_EVT_end'     => $end,
            'DTT_reg_limit'   => $reg_limit,
        ];
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function start(): DateTimeImmutable
    {
        return $this->fields['DTT_EVT_start'];
    }


    public function end(): DateTimeImmutable
    {
        return $this->fields['DTT_EVT_end'];
    }


    /**
     * EE_Datetime field name => value; dates are DateTimeImmutable.
     */
    public function fields(): array
    {
        return $this->fields;
    }


    public function fingerprint(): string
    {
        return Crosswalk::fingerprint($this->fields, CrosswalkKeys::MODEL_DATETIME);
    }
}
// End of file MappedDatetime.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedDatetime.php
