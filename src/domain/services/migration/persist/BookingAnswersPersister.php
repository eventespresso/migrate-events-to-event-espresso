<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EEM_Question;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;

/**
 * Class BookingAnswersPersister
 *
 * Writes a booking's custom-field answers (REQ-019) and check-ins (REQ-020).
 * Questions live in one question group per source, created once per run and
 * found again through the crosswalk (`<src>:question_group:<src>`,
 * `<src>:question:<field id>`), and the group is attached to each migrated
 * event once (`<src>:event_question_group:<event id>`). Answers and check-ins
 * go on the booking's primary registration and are tracked in the
 * PersistedBooking for compensation; the run-level questions are not.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class BookingAnswersPersister
{
    /**
     * Source field type => EE question type; anything else is a text question.
     */
    private const QUESTION_TYPES = [
        'textarea'   => EEM_Question::QST_type_textarea,
        'text-area'  => EEM_Question::QST_type_textarea,
        'select'     => EEM_Question::QST_type_dropdown,
        'dropdown'   => EEM_Question::QST_type_dropdown,
        'radio'      => EEM_Question::QST_type_radio,
        'checkbox'   => EEM_Question::QST_type_checkbox,
        'email'      => EEM_Question::QST_type_email,
        'date'       => EEM_Question::QST_type_date,
        'datepicker' => EEM_Question::QST_type_date,
        'url'        => EEM_Question::QST_type_url,
        'number'     => EEM_Question::QST_type_decimal,
    ];

    private AnswerWriter $writer;

    /**
     * @var array<string, SourceCustomFieldRecord> source field id => definition
     */
    private array $definitions = [];

    private int $next_question_order = 1;


    public function __construct(AnswerWriter $writer)
    {
        $this->writer = $writer;
    }


    /**
     * The source's custom field definitions (SourceAdapter::customFieldDefinitions()), used to label and type
     * the questions. A field without a definition becomes a text question labelled with its id.
     *
     * @param SourceCustomFieldRecord[] $fields
     */
    public function useFieldDefinitions(array $fields): void
    {
        $this->definitions = [];
        foreach ($fields as $field) {
            if ($field instanceof SourceCustomFieldRecord) {
                $this->definitions[ $field->sourceId() ] = $field;
            }
        }
    }


    /**
     * The warning for a check-in on a date that was not migrated; the check-in is skipped.
     */
    public static function missingDatetimeWarning(string $checkin_source_id): MigrationWarning
    {
        return new MigrationWarning(
            WarningRule::RECORD_FAILED,
            CrosswalkKeys::TYPE_CHECKIN,
            $checkin_source_id,
            [__('the check-in is for a date that was not migrated', 'migrate-events-to-event-espresso')]
        );
    }


    public static function questionType(string $source_type): string
    {
        return self::QUESTION_TYPES[ strtolower(trim($source_type)) ] ?? EEM_Question::QST_type_text;
    }


    /**
     * Writes the booking's answers (one migration question group per source, one question per source field) and
     * check-ins on its primary registration, with their crosswalk keys.
     *
     * @since 0.0.1.000
     */
    public function persist(
        MappedBooking $booking,
        PersistedEvent $event,
        PersistedBooking $persisted,
        MigrationContext $context
    ): void {
        $registration_id = $persisted->primaryRegistrationId();
        if ($registration_id === null || ($booking->answers() === [] && $booking->checkins() === [])) {
            return;
        }
        if ($booking->answers() !== []) {
            $group_id = $this->questionGroup($context);
            $this->attachToEvent($event, $group_id, $context);
            foreach ($booking->answers() as $answer) {
                $question_id = $this->question($answer->fieldSourceId(), $group_id, $context);
                $answer_id   = $this->writer->createAnswer($registration_id, $question_id, $answer->value());
                $persisted->addAnswer($answer_id);
                $this->writer->recordCrosswalk(
                    CrosswalkKeys::MODEL_ANSWER,
                    $answer_id,
                    CrosswalkKeys::TYPE_ANSWER,
                    $booking->sourceId() . ':' . $answer->fieldSourceId(),
                    $context
                );
            }
        }
        foreach ($booking->checkins() as $checkin) {
            $datetime_id = $event->datetimeIdFor($checkin->datetimeSourceId());
            $source_id   = $booking->sourceId() . ':' . $checkin->datetimeSourceId();
            if ($datetime_id === null) {
                $context->addWarning(self::missingDatetimeWarning($source_id));
                continue;
            }
            $checkin_id = $this->writer->createCheckin($registration_id, $datetime_id, $checkin->isCheckedIn(), $checkin->timestamp());
            $persisted->addCheckin($checkin_id);
            $this->writer->recordCrosswalk(CrosswalkKeys::MODEL_CHECKIN, $checkin_id, CrosswalkKeys::TYPE_CHECKIN, $source_id, $context);
        }
    }


    private function questionGroup(MigrationContext $context): int
    {
        $source   = $context->source();
        $existing = $this->writer->crosswalkedId(
            CrosswalkKeys::MODEL_QUESTION_GROUP,
            CrosswalkKeys::TYPE_QUESTION_GROUP,
            $source,
            $context
        );
        if ($existing !== null) {
            return $existing;
        }
        /* translators: %s: the source plugin slug */
        $group_id = $this->writer->createQuestionGroup(sprintf(__('Migrated from %s', 'migrate-events-to-event-espresso'), $source), 'migration-' . $source);
        $this->writer->recordCrosswalk(
            CrosswalkKeys::MODEL_QUESTION_GROUP,
            $group_id,
            CrosswalkKeys::TYPE_QUESTION_GROUP,
            $source,
            $context
        );
        return $group_id;
    }


    private function attachToEvent(PersistedEvent $event, int $group_id, MigrationContext $context): void
    {
        $type = CrosswalkKeys::TYPE_EVENT_QUESTION_GROUP;
        if ($this->writer->crosswalkedId(CrosswalkKeys::MODEL_EVENT_QUESTION_GROUP, $type, $event->sourceId(), $context) !== null) {
            return;
        }
        $link_id = $this->writer->linkGroupToEvent($event->eventId(), $group_id);
        $this->writer->recordCrosswalk(CrosswalkKeys::MODEL_EVENT_QUESTION_GROUP, $link_id, $type, $event->sourceId(), $context);
    }


    private function question(string $field_id, int $group_id, MigrationContext $context): int
    {
        $existing = $this->writer->crosswalkedId(
            CrosswalkKeys::MODEL_QUESTION,
            CrosswalkKeys::TYPE_QUESTION,
            $field_id,
            $context
        );
        if ($existing !== null) {
            return $existing;
        }
        $definition  = $this->definitions[ $field_id ] ?? null;
        $label       = $definition instanceof SourceCustomFieldRecord && trim($definition->label()) !== ''
            ? trim($definition->label())
            : $field_id;
        $question_id = $this->writer->createQuestion(
            [
                'QST_display_text' => wp_kses_post($label),
                'QST_admin_label'  => sanitize_key('migration-' . $context->source() . '-' . $field_id),
                'QST_type'         => self::questionType($definition instanceof SourceCustomFieldRecord ? $definition->type() : ''),
                'QST_required'     => $definition instanceof SourceCustomFieldRecord && $definition->isRequired(),
                'QST_system'       => '',
                'QST_order'        => $this->next_question_order,
            ],
            $definition instanceof SourceCustomFieldRecord ? $definition->options() : []
        );
        $this->writer->linkQuestionToGroup($group_id, $question_id, $this->next_question_order++);
        $this->writer->recordCrosswalk(CrosswalkKeys::MODEL_QUESTION, $question_id, CrosswalkKeys::TYPE_QUESTION, $field_id, $context);
        return $question_id;
    }
}
// End of file BookingAnswersPersister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/BookingAnswersPersister.php
