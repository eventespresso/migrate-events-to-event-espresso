<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceDateTime
 *
 * A date/time value exactly as the source plugin stored it, together with the
 * timezone model that explains how to interpret it. Normalisation to UTC is
 * the TimezoneNormalizer's job; this record carries no behaviour.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceDateTime
{
    private string $raw;

    private string $model;

    private ?string $timezone;

    private bool $all_day;


    /**
     * @param string      $raw      The stored value: a date-time string or a unix timestamp.
     * @param string      $model    One of the TimezoneModel constants.
     * @param string|null $timezone Per-event timezone identifier when the model is PER_EVENT.
     * @param bool        $all_day  Whether the source flagged this as an all-day value.
     */
    public function __construct(string $raw, string $model, ?string $timezone = null, bool $all_day = false)
    {
        $this->raw      = $raw;
        $this->model    = $model;
        $this->timezone = $timezone;
        $this->all_day  = $all_day;
    }


    public function raw(): string
    {
        return $this->raw;
    }


    public function model(): string
    {
        return $this->model;
    }


    public function timezone(): ?string
    {
        return $this->timezone;
    }


    public function isAllDay(): bool
    {
        return $this->all_day;
    }
}
// End of file SourceDateTime.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceDateTime.php
