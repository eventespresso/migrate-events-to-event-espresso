<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EE_Attendee;
use EEM_Attendee;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class AttendeeResolver
 *
 * Decides, for one source person, whether to link an existing EE contact or
 * create one (REQ-015, REQ-025). Reads only: the persister creates the
 * contact from the fields returned. Contacts are de-duplicated by normalised
 * email alone; core's find_existing_attendee() needs first name, last name
 * and email and is not used.
 *
 * Contacts created earlier in the same run are found through the context map
 * under emailKey(): a persister (the dry-run persister included) must call
 * $context->remember(AttendeeResolver::emailKey($email), 'Attendee', $id)
 * after creating a contact, and must map and persist bookings one at a time,
 * otherwise two bookings with the same new email both resolve to "create".
 *
 * A pre-existing contact matched by email is noted as reused here, once per
 * run; persisters must not note it again.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AttendeeResolver
{
    private Crosswalk $crosswalk;

    private GeoResolver $geo_resolver;


    public function __construct(Crosswalk $crosswalk, GeoResolver $geo_resolver)
    {
        $this->crosswalk    = $crosswalk;
        $this->geo_resolver = $geo_resolver;
    }


    public function resolve(SourceAttendeeRecord $attendee, MigrationContext $context): ResolvedAttendee
    {
        $source_id = $attendee->sourceId();

        $migrated = $this->crosswalkedAttendeeId($context->source(), $source_id, $context);
        if ($migrated !== null) {
            return new ResolvedAttendee($migrated, null, false);
        }
        if ($this->crosswalk->isTargetDeleted($context->source(), CrosswalkKeys::TYPE_ATTENDEE, $source_id, $context)) {
            // The message is a translated constant; findTarget() already reported the contact as target-deleted.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The contact was migrated earlier and has since been deleted.', 'migrate-events-to-event-espresso'),
                WarningRule::TARGET_DELETED,
                CrosswalkKeys::TYPE_ATTENDEE,
                $source_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ($attendee->isOptedOut()) {
            $this->warn($context, WarningRule::CONTACT_OPTED_OUT, $source_id);
            return new ResolvedAttendee(null, $this->optedOutFields($context), false);
        }
        $email = self::normaliseEmail($attendee->email());
        if ($email === '') {
            $this->warn($context, WarningRule::CONTACT_NO_EMAIL, $source_id);
        } else {
            $existing = $this->existingContact($email, $source_id, $context);
            if ($existing !== null) {
                return new ResolvedAttendee($existing['id'], null, $existing['reused']);
            }
        }
        return new ResolvedAttendee(
            null,
            $this->fieldsToCreate($attendee, $email, $context),
            false,
            $attendee->wpUserId()
        );
    }


    /**
     * The key under which a contact created or reused in this run is remembered by email.
     */
    public static function emailKey(string $email): string
    {
        return 'email:' . self::normaliseEmail($email);
    }


    /**
     * Lower-cased, trimmed email, or '' when the value is not a usable address.
     */
    public static function normaliseEmail(string $email): string
    {
        // Validated as written: sanitize_email() would silently repair "ann lee@x.com" into a different address.
        $email = strtolower(trim($email));
        return $email !== '' && is_email($email) !== false ? $email : '';
    }


    /**
     * First and last name from the parts the source gave: a lone full name is split at its last space.
     * A source with no name at all gets a translated placeholder.
     *
     * @return string[] The first name at index 0 and the last name at index 1.
     */
    public static function splitName(string $first, string $last, string $full): array
    {
        $first = trim($first);
        $last  = trim($last);
        $full  = trim($full);
        if ($first === '' && $last === '' && $full !== '') {
            $full     = preg_replace('/\s+/u', ' ', $full) ?? $full;
            $position = strrpos($full, ' ');
            return $position === false
                ? [$full, '']
                : [substr($full, 0, $position), substr($full, $position + 1)];
        }
        return [$first, $last];
    }


    /**
     * The existing contact for an email: remembered in this run, else the first pre-existing contact with
     * that email, which is then noted as reused and remembered.
     *
     * @return array{id: int, reused: bool}|null
     */
    private function existingContact(string $email, string $source_id, MigrationContext $context): ?array
    {
        $key = self::emailKey($email);
        if ($context->lookup($key, CrosswalkKeys::MODEL_ATTENDEE) !== null) {
            return ['id' => (int) $context->lookup($key, CrosswalkKeys::MODEL_ATTENDEE), 'reused' => false];
        }
        $found = $this->attendeeIdByEmail($email);
        if ($found === null) {
            return null;
        }
        $this->crosswalk->recordReuseById(
            CrosswalkKeys::MODEL_ATTENDEE,
            $found,
            $context->source(),
            CrosswalkKeys::TYPE_ATTENDEE,
            $source_id,
            $context
        );
        $context->remember($key, CrosswalkKeys::MODEL_ATTENDEE, $found);
        return ['id' => $found, 'reused' => true];
    }


    private function fieldsToCreate(SourceAttendeeRecord $attendee, string $email, MigrationContext $context): array
    {
        [$first, $last] = self::splitName($attendee->firstName(), $attendee->lastName(), $attendee->fullName());
        if ($first === '' && $last === '') {
            $first = __('Unknown registrant', 'migrate-events-to-event-espresso');
        }
        $geo = $this->geo_resolver->resolve($attendee->country(), $attendee->state());
        if ($geo->unresolvedCountry() !== '') {
            $this->warn($context, WarningRule::COUNTRY_UNRESOLVED, $attendee->sourceId(), $geo->unresolvedCountry());
        }
        if ($geo->unresolvedState() !== '') {
            $this->warn($context, WarningRule::STATE_UNRESOLVED, $attendee->sourceId(), $geo->unresolvedState());
        }
        return [
            'ATT_fname'     => $first,
            'ATT_lname'     => $last,
            'ATT_full_name' => trim($first . ' ' . $last),
            'ATT_email'     => $email,
            'ATT_phone'     => trim($attendee->phone()),
            'ATT_address'   => trim($attendee->address()),
            'ATT_address2'  => $geo->appendUnresolvedTo($attendee->address2()),
            'ATT_city'      => trim($attendee->city()),
            'STA_ID'        => $geo->stateId(),
            'CNT_ISO'       => $geo->countryIso(),
            'ATT_zip'       => trim($attendee->zip()),
            'ATT_author'    => $context->userId(),
            'status'        => 'publish',
        ];
    }


    /**
     * An opted-out or anonymised person keeps a registration but no personal details (REQ-025).
     */
    private function optedOutFields(MigrationContext $context): array
    {
        $name = __('Opted-out registrant', 'migrate-events-to-event-espresso');
        return [
            'ATT_fname'     => $name,
            'ATT_lname'     => '',
            'ATT_full_name' => $name,
            'ATT_email'     => '',
            'ATT_phone'     => '',
            'ATT_address'   => '',
            'ATT_address2'  => '',
            'ATT_city'      => '',
            'STA_ID'        => 0,
            'CNT_ISO'       => '',
            'ATT_zip'       => '',
            'ATT_author'    => $context->userId(),
            'status'        => 'publish',
        ];
    }


    private function warn(MigrationContext $context, string $rule, string $source_id, string ...$args): void
    {
        $context->addWarning(new MigrationWarning($rule, CrosswalkKeys::TYPE_ATTENDEE, $source_id, $args));
    }


    /**
     * The EE contact id a previous run (or earlier in this run) created for the source person, or null.
     */
    protected function crosswalkedAttendeeId(string $source, string $source_id, MigrationContext $context): ?int
    {
        return $this->crosswalk->targetId(
            $source,
            CrosswalkKeys::TYPE_ATTENDEE,
            $source_id,
            CrosswalkKeys::MODEL_ATTENDEE,
            $context
        );
    }


    /**
     * The id of the first existing contact with the email. The model applies the posts/attendee-meta join.
     */
    protected function attendeeIdByEmail(string $email): ?int
    {
        $found = EEM_Attendee::instance()->get_one([['ATT_email' => $email], 'order_by' => ['ATT_ID' => 'ASC']]);
        return $found instanceof EE_Attendee ? (int) $found->ID() : null;
    }
}
// End of file AttendeeResolver.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/AttendeeResolver.php
