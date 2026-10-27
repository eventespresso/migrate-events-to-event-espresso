<?php

namespace EventEspresso\EventMigrator\domain\services\migration\crosswalk;

/**
 * Class CrosswalkKeys
 *
 * The extra-meta keys every migrated record carries, the EE model names used
 * as EXM_type, and the source entity type names that appear in source keys.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class CrosswalkKeys
{
    /**
     * "<source>:<type>:<source_id>" - which source record produced this EE record.
     */
    public const SRC = 'ee_migration_src';

    /**
     * The 16-hex id of the run that created the record. Never written on reused records.
     */
    public const RUN = 'ee_migration_run';

    /**
     * sha1 fingerprint of the field values the run wrote (REQ-010 modification check).
     */
    public const FP = 'ee_migration_fp';

    /**
     * The source's WordPress user id, on attendees only (REQ-015).
     */
    public const WP_USER = 'ee_migration_wp_user';

    /**
     * Further "<source>:<type>:<source_id>" keys that resolve to the same EE record: the other occurrences of a
     * collapsed series and the series itself. Repeatable, unlike SRC.
     */
    public const ALIAS = 'ee_migration_alias';

    /**
     * The source's cancelled or postponed state, on events a run created as drafts (REQ-011), so it is not lost.
     */
    public const SRC_STATUS = 'ee_migration_src_status';

    public const MODEL_EVENT                = 'Event';

    public const MODEL_DATETIME             = 'Datetime';

    public const MODEL_TICKET               = 'Ticket';

    public const MODEL_PRICE                = 'Price';

    public const MODEL_VENUE                = 'Venue';

    public const MODEL_ATTENDEE             = 'Attendee';

    public const MODEL_TRANSACTION          = 'Transaction';

    public const MODEL_REGISTRATION         = 'Registration';

    public const MODEL_PAYMENT              = 'Payment';

    public const MODEL_ANSWER               = 'Answer';

    public const MODEL_CHECKIN              = 'Checkin';

    public const MODEL_TERM_TAXONOMY        = 'Term_Taxonomy';

    public const MODEL_QUESTION             = 'Question';

    public const MODEL_QUESTION_GROUP       = 'Question_Group';

    public const MODEL_LINE_ITEM            = 'Line_Item';

    public const MODEL_EVENT_QUESTION_GROUP = 'Event_Question_Group';

    public const TYPE_EVENT          = 'event';

    public const TYPE_DATETIME       = 'datetime';

    public const TYPE_VENUE          = 'venue';

    public const TYPE_TICKET         = 'ticket';

    public const TYPE_PRICE          = 'price';

    public const TYPE_CATEGORY       = 'category';

    public const TYPE_ATTENDEE       = 'attendee';

    public const TYPE_BOOKING        = 'booking';

    public const TYPE_REGISTRATION   = 'registration';

    public const TYPE_PAYMENT        = 'payment';

    public const TYPE_ANSWER         = 'answer';

    public const TYPE_CHECKIN        = 'checkin';

    public const TYPE_QUESTION       = 'question';

    public const TYPE_QUESTION_GROUP = 'question_group';

    public const TYPE_SERIES         = 'series';

    public const TYPE_EVENT_QUESTION_GROUP = 'event_question_group';


    /**
     * The keys the crosswalk writes, in the order they are written.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::SRC, self::RUN, self::FP, self::WP_USER, self::ALIAS, self::SRC_STATUS];
    }


    /**
     * The models a run records crosswalk entries for, in the order a rollback deletes them: dependents before
     * the records they point at. Line items and registration payments carry no entry; they are removed with
     * their transaction and payment.
     *
     * @return string[]
     */
    public static function models(): array
    {
        return [
            self::MODEL_CHECKIN,
            self::MODEL_ANSWER,
            self::MODEL_PAYMENT,
            self::MODEL_REGISTRATION,
            self::MODEL_TRANSACTION,
            self::MODEL_ATTENDEE,
            self::MODEL_TICKET,
            self::MODEL_PRICE,
            self::MODEL_DATETIME,
            self::MODEL_EVENT_QUESTION_GROUP,
            self::MODEL_EVENT,
            self::MODEL_VENUE,
            self::MODEL_QUESTION,
            self::MODEL_QUESTION_GROUP,
            self::MODEL_TERM_TAXONOMY,
        ];
    }
}
// End of file CrosswalkKeys.php
// Location: EventEspresso\EventMigrator\domain\services\migration\crosswalk/CrosswalkKeys.php
