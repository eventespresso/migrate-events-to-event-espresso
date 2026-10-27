<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceAttendeeRecord
 *
 * The person behind a source booking. Any field may be empty; the
 * AttendeeResolver decides how to build or reuse an EE contact from it.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceAttendeeRecord
{
    private string $source_id;

    private string $first_name;

    private string $last_name;

    private string $full_name;

    private string $email;

    private string $phone;

    private string $address;

    private string $address2;

    private string $city;

    private string $state;

    private string $country;

    private string $zip;

    private ?int $wp_user_id;

    private bool $opted_out;


    /**
     * @param string $source_id Source identifier of the customer/attendee, or the booking id for guests.
     * @param array  $extra     Optional: first_name, last_name, full_name, email, phone, address, address2,
     *                          city, state, country, zip (strings), wp_user_id (?int), opted_out (bool).
     */
    public function __construct(string $source_id, array $extra = [])
    {
        $this->source_id  = $source_id;
        $this->first_name = (string) ($extra['first_name'] ?? '');
        $this->last_name  = (string) ($extra['last_name'] ?? '');
        $this->full_name  = (string) ($extra['full_name'] ?? '');
        $this->email      = (string) ($extra['email'] ?? '');
        $this->phone      = (string) ($extra['phone'] ?? '');
        $this->address    = (string) ($extra['address'] ?? '');
        $this->address2   = (string) ($extra['address2'] ?? '');
        $this->city       = (string) ($extra['city'] ?? '');
        $this->state      = (string) ($extra['state'] ?? '');
        $this->country    = (string) ($extra['country'] ?? '');
        $this->zip        = (string) ($extra['zip'] ?? '');
        $this->wp_user_id = isset($extra['wp_user_id']) && (int) $extra['wp_user_id'] > 0
            ? (int) $extra['wp_user_id']
            : null;
        $this->opted_out  = (bool) ($extra['opted_out'] ?? false);
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function firstName(): string
    {
        return $this->first_name;
    }


    public function lastName(): string
    {
        return $this->last_name;
    }


    public function fullName(): string
    {
        return $this->full_name;
    }


    public function email(): string
    {
        return $this->email;
    }


    public function phone(): string
    {
        return $this->phone;
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


    /**
     * The WordPress user the source linked this person to, when the source had one.
     */
    public function wpUserId(): ?int
    {
        return $this->wp_user_id;
    }


    public function hasEmail(): bool
    {
        return $this->email !== '';
    }


    public function isOptedOut(): bool
    {
        return $this->opted_out;
    }
}
// End of file SourceAttendeeRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceAttendeeRecord.php
