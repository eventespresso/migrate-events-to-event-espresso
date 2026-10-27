<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

/**
 * Class ResolvedGeo
 *
 * The outcome of resolving a source state and country to EE records. Text
 * that could not be resolved is kept so it can be preserved rather than lost.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ResolvedGeo
{
    private string $country_iso;

    private int $state_id;

    private string $unresolved_country;

    private string $unresolved_state;


    /**
     * @param string $country_iso        The resolved country ISO code, or '' when none.
     * @param int    $state_id           The resolved EE state id, or 0 when none.
     * @param string $unresolved_country The source country text that matched no EE country.
     * @param string $unresolved_state   The source state text that matched no EE state.
     */
    public function __construct(
        string $country_iso,
        int $state_id,
        string $unresolved_country = '',
        string $unresolved_state = ''
    ) {
        $this->country_iso        = $country_iso;
        $this->state_id           = $state_id;
        $this->unresolved_country = $unresolved_country;
        $this->unresolved_state   = $unresolved_state;
    }


    public function countryIso(): string
    {
        return $this->country_iso;
    }


    public function stateId(): int
    {
        return $this->state_id;
    }


    public function unresolvedCountry(): string
    {
        return $this->unresolved_country;
    }


    public function unresolvedState(): string
    {
        return $this->unresolved_state;
    }


    public function hasUnresolved(): bool
    {
        return $this->unresolved_country !== '' || $this->unresolved_state !== '';
    }


    /**
     * The given address line with any unresolved state and country text appended, so the text survives
     * in a plain address field.
     */
    public function appendUnresolvedTo(string $address_line): string
    {
        $parts = array_filter(
            [trim($address_line), $this->unresolved_state, $this->unresolved_country],
            static fn(string $part): bool => $part !== ''
        );
        return implode(', ', $parts);
    }
}
// End of file ResolvedGeo.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/ResolvedGeo.php
