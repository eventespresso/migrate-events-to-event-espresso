<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceVenueRecord
 *
 * A source venue or location. State and country are carried as the source's
 * raw text or codes; resolution to EE records happens in the VenueResolver.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceVenueRecord
{
    private string $source_id;

    private string $name;

    private string $description;

    private string $address;

    private string $address2;

    private string $city;

    private string $state;

    private string $country;

    private string $zip;

    private string $phone;

    private string $url;

    private string $virtual_url;

    private ?float $latitude;

    private ?float $longitude;

    private bool $text_only;


    /**
     * @param string $source_id Source identifier of the venue; the event's own id for free-text locations.
     * @param string $name      Venue name, or the free-text location when the source has no venue entity.
     * @param array  $extra     Optional: description, address, address2, city, state, country, zip, phone,
     *                          url, virtual_url (strings), latitude, longitude (floats), text_only (bool).
     */
    public function __construct(string $source_id, string $name, array $extra = [])
    {
        $this->source_id   = $source_id;
        $this->name        = $name;
        $this->description = (string) ($extra['description'] ?? '');
        $this->address     = (string) ($extra['address'] ?? '');
        $this->address2    = (string) ($extra['address2'] ?? '');
        $this->city        = (string) ($extra['city'] ?? '');
        $this->state       = (string) ($extra['state'] ?? '');
        $this->country     = (string) ($extra['country'] ?? '');
        $this->zip         = (string) ($extra['zip'] ?? '');
        $this->phone       = (string) ($extra['phone'] ?? '');
        $this->url         = (string) ($extra['url'] ?? '');
        $this->virtual_url = (string) ($extra['virtual_url'] ?? '');
        $this->latitude    = isset($extra['latitude']) ? (float) $extra['latitude'] : null;
        $this->longitude   = isset($extra['longitude']) ? (float) $extra['longitude'] : null;
        $this->text_only   = (bool) ($extra['text_only'] ?? false);
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function name(): string
    {
        return $this->name;
    }


    public function description(): string
    {
        return $this->description;
    }


    public function address(): string
    {
        return $this->address;
    }


    public function address2(): string
    {
        return $this->address2;
    }


    public function city(): string
    {
        return $this->city;
    }


    public function state(): string
    {
        return $this->state;
    }


    public function country(): string
    {
        return $this->country;
    }


    public function zip(): string
    {
        return $this->zip;
    }


    public function phone(): string
    {
        return $this->phone;
    }


    public function url(): string
    {
        return $this->url;
    }


    public function virtualUrl(): string
    {
        return $this->virtual_url;
    }


    public function latitude(): ?float
    {
        return $this->latitude;
    }


    public function longitude(): ?float
    {
        return $this->longitude;
    }


    /**
     * True when the source only stored a free-text location and no venue entity.
     */
    public function isTextOnly(): bool
    {
        return $this->text_only;
    }
}
// End of file SourceVenueRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceVenueRecord.php
