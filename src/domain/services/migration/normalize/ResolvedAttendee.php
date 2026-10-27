<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use InvalidArgumentException;

/**
 * Class ResolvedAttendee
 *
 * What the AttendeeResolver decided for one source person: link an existing
 * EE contact, or create one from the carried fields. Exactly one of the two
 * is set.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ResolvedAttendee
{
    private ?int $existing_id;

    private ?array $fields_to_create;

    private bool $reused;

    private ?int $wp_user_id;


    /**
     * @param int|null   $existing_id      The EE contact to link, when one exists.
     * @param array|null $fields_to_create EE_Attendee fields to write when no contact exists.
     * @param bool       $reused           True when the existing contact was matched by email and was not
     *                                     created by a migration run.
     * @param int|null   $wp_user_id       The source's WordPress user id, recorded on a created contact.
     * @throws InvalidArgumentException When both or neither of the contact and the fields are given.
     */
    public function __construct(
        ?int $existing_id,
        ?array $fields_to_create,
        bool $reused,
        ?int $wp_user_id = null
    ) {
        if (($existing_id === null) === ($fields_to_create === null)) {
            throw new InvalidArgumentException(
                esc_html__('A resolved attendee is either an existing contact or one to create.', 'migrate-events-to-event-espresso')
            );
        }
        $this->existing_id      = $existing_id;
        $this->fields_to_create = $fields_to_create;
        $this->reused           = $reused;
        $this->wp_user_id       = $wp_user_id;
    }


    public function existingId(): ?int
    {
        return $this->existing_id;
    }


    public function fieldsToCreate(): ?array
    {
        return $this->fields_to_create;
    }


    public function isReused(): bool
    {
        return $this->reused;
    }


    public function wpUserId(): ?int
    {
        return $this->wp_user_id;
    }
}
// End of file ResolvedAttendee.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/ResolvedAttendee.php
