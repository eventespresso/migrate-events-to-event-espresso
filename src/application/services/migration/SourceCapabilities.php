<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class SourceCapabilities
 *
 * What a source can provide: which data domains, whether it has a recurrence
 * concept, and which timezone model its stored dates use.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceCapabilities
{
    public const DOMAIN_EVENTS        = 'events';

    public const DOMAIN_BOOKINGS      = 'bookings';

    public const DOMAIN_PAYMENTS      = 'payments';

    public const DOMAIN_CUSTOM_FIELDS = 'custom_fields';

    public const DOMAIN_CHECKINS      = 'checkins';

    private array $domains;

    private bool $has_recurrence;

    private string $timezone_model;


    /**
     * @param string[] $domains        The DOMAIN_* values the source provides.
     * @param bool     $has_recurrence Whether the source has a recurring-series concept.
     * @param string   $timezone_model A TimezoneModel constant.
     */
    public function __construct(array $domains, bool $has_recurrence, string $timezone_model)
    {
        $this->domains        = array_values(array_intersect(self::allDomains(), array_map('strval', $domains)));
        $this->has_recurrence = $has_recurrence;
        $this->timezone_model = $timezone_model;
    }


    /**
     * @return string[]
     */
    public static function allDomains(): array
    {
        return [
            self::DOMAIN_EVENTS,
            self::DOMAIN_BOOKINGS,
            self::DOMAIN_PAYMENTS,
            self::DOMAIN_CUSTOM_FIELDS,
            self::DOMAIN_CHECKINS,
        ];
    }


    /**
     * @return string[]
     */
    public function domains(): array
    {
        return $this->domains;
    }


    public function provides(string $domain): bool
    {
        return in_array($domain, $this->domains, true);
    }


    public function hasRecurrence(): bool
    {
        return $this->has_recurrence;
    }


    public function timezoneModel(): string
    {
        return $this->timezone_model;
    }
}
// End of file SourceCapabilities.php
// Location: EventEspresso\EventMigrator\application\services\migration/SourceCapabilities.php
