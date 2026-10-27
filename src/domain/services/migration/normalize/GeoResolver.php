<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EE_Country;
use EE_State;
use EEM_Country;
use EEM_State;

/**
 * Class GeoResolver
 *
 * Resolves a source's state and country text or codes to Event Espresso
 * country ISO codes and state ids. Shared by the venue and attendee
 * resolvers. The three protected lookups are the only places that touch the
 * models, so tests substitute them.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class GeoResolver
{
    /**
     * @var array<string, ResolvedGeo> "country|state" => the resolved place
     */
    private array $resolved = [];

    /**
     * @param string $country The source country: an ISO 3166 alpha-2 code or an English name.
     * @param string $state   The source state: an abbreviation or a name.
     */
    public function resolve(string $country, string $state): ResolvedGeo
    {
        // Memoized per exact (trimmed) pair: a source with thousands of contacts repeats the same few places.
        // The key keeps the original case because an unresolved value is copied into the address as written.
        $key = trim($country) . '|' . trim($state);
        if (! isset($this->resolved[ $key ])) {
            $this->resolved[ $key ] = $this->resolveUncached($country, $state);
        }
        return $this->resolved[ $key ];
    }


    private function resolveUncached(string $country, string $state): ResolvedGeo
    {
        $country = trim($country);
        $state   = trim($state);
        $iso     = '';
        if ($country !== '') {
            $iso = $this->resolveCountry($country);
        }
        $unresolved_country = $country !== '' && $iso === '' ? $country : '';
        $state_id           = 0;
        $unresolved_state   = '';
        if ($state !== '') {
            // A state is only matched under a resolved country, or on its own when the source gave no country.
            $match = $unresolved_country === '' ? $this->resolveState($iso !== '' ? $iso : null, $state) : null;
            if ($match === null) {
                $unresolved_state = $state;
            } else {
                $state_id = $match['id'];
                $iso      = $iso !== '' ? $iso : $match['iso'];
            }
        }
        return new ResolvedGeo($iso, $state_id, $unresolved_country, $unresolved_state);
    }


    /**
     * The ISO code for a country given as an alpha-2 code or a name, or '' when no EE country matches.
     */
    private function resolveCountry(string $country): string
    {
        if (preg_match('/^[A-Za-z]{2}$/D', $country) === 1 && $this->countryExists(strtoupper($country))) {
            return strtoupper($country);
        }
        return $this->countryIsoByName($country) ?? '';
    }


    /**
     * The single state matching the text. Without a country an ambiguous match (the same abbreviation in
     * several countries) is treated as unresolved.
     *
     * @return array{id: int, iso: string}|null
     */
    private function resolveState(?string $iso, string $state): ?array
    {
        $matches = $this->stateMatches($iso, $state);
        if (count($matches) === 1 || ($iso !== null && $matches !== [])) {
            return reset($matches);
        }
        return null;
    }


    protected function countryExists(string $iso): bool
    {
        return EEM_Country::instance()->get_one_by_ID($iso) instanceof EE_Country;
    }


    protected function countryIsoByName(string $name): ?string
    {
        $country = EEM_Country::instance()->getCountryByName($name);
        return $country instanceof EE_Country ? (string) $country->ID() : null;
    }


    /**
     * @return array<int, array{id: int, iso: string}> States whose abbreviation or name equals the text,
     *                                                  scoped to the country when one is given.
     */
    protected function stateMatches(?string $iso, string $state): array
    {
        $where = ['OR' => ['STA_abbrev' => $state, 'STA_name' => $state]];
        if ($iso !== null) {
            $where['CNT_ISO'] = $iso;
        }
        $matches = [];
        foreach (EEM_State::instance()->get_all([$where, 'limit' => 5]) as $found) {
            if ($found instanceof EE_State) {
                $matches[] = ['id' => (int) $found->ID(), 'iso' => (string) $found->country_iso()];
            }
        }
        return $matches;
    }
}
// End of file GeoResolver.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/GeoResolver.php
