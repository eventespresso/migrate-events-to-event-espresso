<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use DateTimeImmutable;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\domain\exceptions\MigrationLaunchException;

/**
 * Class OptionsSubmission
 *
 * Turns the options step's submitted values into validated MigrationOptions
 * (REQ-004, REQ-005, REQ-006). The source's capabilities decide what may be
 * chosen: bookings only when the source provides them, recurrence only when
 * it has recurring events, and "preserve the source publish state" only for
 * a user who may publish events. A value the form could not have offered is
 * ignored rather than trusted.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class OptionsSubmission
{
    public const HORIZON_AFTER_CHOICE = 'after';


    /**
     * @param string             $source       The chosen source slug.
     * @param array              $data         The options step's valid form data.
     * @param SourceCapabilities $capabilities The source's capabilities.
     * @param bool               $can_publish  Whether the current user may publish events.
     * @throws MigrationLaunchException When the choices cannot form valid options.
     */
    public static function toOptions(
        string $source,
        array $data,
        SourceCapabilities $capabilities,
        bool $can_publish
    ): MigrationOptions {
        $domains = (string) ($data['domains'] ?? MigrationOptions::DOMAINS_EVENTS);
        if (! $capabilities->provides(SourceCapabilities::DOMAIN_BOOKINGS)) {
            $domains = MigrationOptions::DOMAINS_EVENTS;
        }
        $recurrence = (string) ($data['recurrence'] ?? MigrationOptions::RECURRENCE_SINGLE_EVENT);
        if (! $capabilities->hasRecurrence()) {
            $recurrence = MigrationOptions::RECURRENCE_SINGLE_EVENT;
        }
        try {
            return MigrationOptions::fromArray(
                [
                    'source'          => $source,
                    'horizon'         => self::horizon($data),
                    'domains'         => $domains,
                    'recurrence'      => $recurrence,
                    'preserve_status' => $can_publish && filter_var($data['preserve_status'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'dry_run'         => false,
                ]
            );
        } catch (InvalidDataTypeException $exception) {
            throw new MigrationLaunchException(
                esc_html__('One of the migration options is not valid. Please review your choices.', 'migrate-events-to-event-espresso')
            );
        }
    }


    /**
     * @throws MigrationLaunchException When "after a date" was chosen without a real YYYY-MM-DD date.
     */
    private static function horizon(array $data): string
    {
        $choice = (string) ($data['horizon'] ?? MigrationOptions::HORIZON_ALL);
        if ($choice !== self::HORIZON_AFTER_CHOICE) {
            return $choice;
        }
        $date   = trim((string) ($data['horizon_date'] ?? ''));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new MigrationLaunchException(
                esc_html__('Enter the date in the form YYYY-MM-DD to migrate events that start after it.', 'migrate-events-to-event-espresso')
            );
        }
        return MigrationOptions::HORIZON_AFTER . $date;
    }


    /**
     * The form default for the horizon radio and date input, from stored options.
     *
     * @return array{choice: string, date: string}
     */
    public static function horizonDefaults(MigrationOptions $options): array
    {
        $date = $options->horizonDate();
        return $date !== null
            ? ['choice' => self::HORIZON_AFTER_CHOICE, 'date' => $date]
            : ['choice' => $options->horizon(), 'date' => ''];
    }
}
// End of file OptionsSubmission.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/OptionsSubmission.php
