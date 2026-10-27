<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EE_Venue;
use EEM_Venue;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedVenue;

/**
 * Class VenueResolver
 *
 * Decides, for one source venue, whether to link an existing EE venue or to
 * create a new one (REQ-014). Reads only: creation happens in the persister
 * from the MappedVenue it returns. A venue already migrated by an earlier run
 * is found through the crosswalk; otherwise venues are de-duplicated by
 * normalised name, address and city. A venue reused this way is noted on the
 * context here, so persisters must not note it again.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class VenueResolver
{
    private Crosswalk $crosswalk;

    private GeoResolver $geo_resolver;


    public function __construct(Crosswalk $crosswalk, GeoResolver $geo_resolver)
    {
        $this->crosswalk    = $crosswalk;
        $this->geo_resolver = $geo_resolver;
    }


    /**
     * @throws MigrationRecordException When the venue has no name to create or match it by.
     */
    public function resolve(SourceVenueRecord $venue, MigrationContext $context): ResolvedVenue
    {
        $source    = $context->source();
        $source_id = $venue->sourceId();

        $migrated = $this->crosswalkedVenueId($source, $source_id, $context);
        if ($migrated !== null) {
            return new ResolvedVenue($migrated, null, false);
        }
        if ($this->crosswalk->isTargetDeleted($source, CrosswalkKeys::TYPE_VENUE, $source_id, $context)) {
            // The message is a translated constant; findTarget() already reported the venue as target-deleted.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The venue was migrated earlier and has since been deleted.', 'migrate-events-to-event-espresso'),
                WarningRule::TARGET_DELETED,
                CrosswalkKeys::TYPE_VENUE,
                $source_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $name = trim($venue->name());
        if ($name === '') {
            // The message is a translated constant; the type and id are data carried for the report.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source venue has no name.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_VENUE,
                $source_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $existing = $this->findDuplicate($name, $venue->address(), $venue->city());
        if ($existing !== null) {
            $this->crosswalk->recordReuseById(
                CrosswalkKeys::MODEL_VENUE,
                $existing,
                $source,
                CrosswalkKeys::TYPE_VENUE,
                $source_id,
                $context
            );
            return new ResolvedVenue($existing, null, true);
        }
        if ($venue->isTextOnly()) {
            $context->addWarning(
                new MigrationWarning(WarningRule::VENUE_TEXT_ONLY, CrosswalkKeys::TYPE_VENUE, $source_id)
            );
        }
        return new ResolvedVenue(null, $this->mapVenue($venue, $name, $context), false);
    }


    /**
     * Case-, whitespace- and edge-insensitive comparison key for names and address parts.
     */
    public static function matchKey(string $value): string
    {
        return mb_strtolower(self::collapse($value));
    }


    /**
     * Trimmed text with every run of whitespace reduced to one space.
     */
    public static function collapse(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        return trim($collapsed ?? $value);
    }


    private function findDuplicate(string $name, string $address, string $city): ?int
    {
        $key = [self::matchKey($name), self::matchKey($address), self::matchKey($city)];
        foreach ($this->venueCandidates(self::collapse($name)) as $candidate) {
            $candidate_key = [
                self::matchKey($candidate['name']),
                self::matchKey($candidate['address']),
                self::matchKey($candidate['city']),
            ];
            if ($candidate_key === $key) {
                return $candidate['id'];
            }
        }
        return null;
    }


    private function mapVenue(SourceVenueRecord $venue, string $name, MigrationContext $context): MappedVenue
    {
        $source_id = $venue->sourceId();
        $geo       = $this->geo_resolver->resolve($venue->country(), $venue->state());
        if ($geo->unresolvedCountry() !== '') {
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::COUNTRY_UNRESOLVED,
                    CrosswalkKeys::TYPE_VENUE,
                    $source_id,
                    [$geo->unresolvedCountry()]
                )
            );
        }
        if ($geo->unresolvedState() !== '') {
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::STATE_UNRESOLVED,
                    CrosswalkKeys::TYPE_VENUE,
                    $source_id,
                    [$geo->unresolvedState()]
                )
            );
        }
        return new MappedVenue(
            $source_id,
            [
                'VNU_name'            => $name,
                'VNU_desc'            => wp_kses_post($venue->description()),
                'VNU_short_desc'      => '',
                'status'              => 'publish',
                'VNU_address'         => trim($venue->address()),
                'VNU_address2'        => $geo->appendUnresolvedTo($venue->address2()),
                'VNU_city'            => trim($venue->city()),
                'VNU_zip'             => trim($venue->zip()),
                'VNU_phone'           => trim($venue->phone()),
                'VNU_url'             => esc_url_raw(trim($venue->url())),
                'VNU_virtual_url'     => esc_url_raw(trim($venue->virtualUrl())),
                'VNU_google_map_link' => $this->mapLink($venue->latitude(), $venue->longitude()),
                'STA_ID'              => $geo->stateId(),
                'CNT_ISO'             => $geo->countryIso(),
                'VNU_wp_user'         => $context->userId(),
            ]
        );
    }


    /**
     * EE venues have no coordinate fields; the coordinates survive as a map link.
     */
    private function mapLink(?float $latitude, ?float $longitude): string
    {
        if ($latitude === null || $longitude === null) {
            return '';
        }
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return '';
        }
        return sprintf(
            'https://www.google.com/maps/search/?api=1&query=%s,%s',
            rtrim(rtrim(number_format($latitude, 7, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($longitude, 7, '.', ''), '0'), '.')
        );
    }


    /**
     * The EE venue id a previous run (or earlier in this run) created for the source venue, or null.
     */
    protected function crosswalkedVenueId(string $source, string $source_id, MigrationContext $context): ?int
    {
        return $this->crosswalk->targetId(
            $source,
            CrosswalkKeys::TYPE_VENUE,
            $source_id,
            CrosswalkKeys::MODEL_VENUE,
            $context
        );
    }


    /**
     * Existing venues whose name equals the given one, oldest first. The model applies the
     * posts/venue-meta join; the comparison with address and city happens in findDuplicate().
     *
     * @return array<int, array{id: int, name: string, address: string, city: string}>
     */
    protected function venueCandidates(string $name): array
    {
        $found      = EEM_Venue::instance()->get_all([
            ['VNU_name' => ['LIKE', $GLOBALS['wpdb']->esc_like($name)]],
            'order_by' => ['VNU_ID' => 'ASC'],
            'limit'    => 50,
        ]);
        $candidates = [];
        foreach ($found as $venue) {
            if ($venue instanceof EE_Venue) {
                $candidates[] = [
                    'id'      => (int) $venue->ID(),
                    'name'    => $venue->name(),
                    'address' => $venue->address(),
                    'city'    => $venue->city(),
                ];
            }
        }
        return $candidates;
    }
}
// End of file VenueResolver.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/VenueResolver.php
