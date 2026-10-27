<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use DateTime;
use DateTimeImmutable;
use EE_Answer;
use EE_Base_Class;
use EE_Checkin;
use EE_Event;
use EE_Event_Question_Group;
use EE_Question;
use EE_Question_Group;
use EE_Question_Group_Question;
use EE_Question_Option;
use EEM_Event;
use EEM_Event_Question_Group;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use RuntimeException;

/**
 * Class AnswerWriter
 *
 * The model writes behind BookingAnswersPersister: the per-source question
 * group and questions, their link to each event, answers and check-ins.
 * Objects it saves are kept for the request so crosswalk records can be made
 * on them; callers deal in ids.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AnswerWriter
{
    private Crosswalk $crosswalk;

    /**
     * @var array<string, array<int, EE_Base_Class>> model => id => object saved by this writer
     */
    private array $saved = [];


    public function __construct(Crosswalk $crosswalk)
    {
        $this->crosswalk = $crosswalk;
    }


    /**
     * Forgets saved objects; questions and groups are looked up through the crosswalk afterwards.
     */
    public function reset(): void
    {
        $this->saved = [];
    }


    /**
     * The EE id the crosswalk links a source key to in this or an earlier run, or null.
     */
    public function crosswalkedId(string $model, string $type, string $source_id, MigrationContext $context): ?int
    {
        return $this->crosswalk->targetId($context->source(), $type, $source_id, $model, $context);
    }


    /**
     * @throws RuntimeException When the group cannot be saved.
     */
    public function createQuestionGroup(string $name, string $identifier): int
    {
        return $this->save(
            EE_Question_Group::new_instance([
                'QSG_name'            => $name,
                'QSG_identifier'      => $identifier,
                'QSG_desc'            => '',
                'QSG_show_group_name' => true,
                'QSG_show_group_desc' => false,
                'QSG_system'          => 0,
            ]),
            CrosswalkKeys::MODEL_QUESTION_GROUP
        );
    }


    /**
     * @param array    $fields  EE_Question fields.
     * @param string[] $options Answer options for choice questions.
     * @throws RuntimeException When the question or an option cannot be saved.
     */
    public function createQuestion(array $fields, array $options): int
    {
        $question_id = $this->save(EE_Question::new_instance($fields), CrosswalkKeys::MODEL_QUESTION);
        foreach (array_values($options) as $order => $option) {
            $this->save(
                EE_Question_Option::new_instance([
                    'QST_ID'    => $question_id,
                    'QSO_value' => $option,
                    'QSO_desc'  => '',
                    'QSO_order' => $order,
                ]),
                'Question_Option'
            );
        }
        return $question_id;
    }


    /**
     * @throws RuntimeException When the link cannot be saved.
     */
    public function linkQuestionToGroup(int $group_id, int $question_id, int $order): void
    {
        $this->save(
            EE_Question_Group_Question::new_instance(['QSG_ID' => $group_id, 'QST_ID' => $question_id, 'QGQ_order' => $order]),
            'Question_Group_Question'
        );
    }


    /**
     * Attaches the group to the event for the primary registrant and returns the Event_Question_Group id.
     *
     * @throws RuntimeException When the event or the link row cannot be found.
     */
    public function linkGroupToEvent(int $event_id, int $group_id): int
    {
        $event = EEM_Event::instance()->get_one_by_ID($event_id);
        if (! $event instanceof EE_Event) {
            throw new RuntimeException(esc_html__('A migrated event could not be found to attach its questions.', 'migrate-events-to-event-espresso'));
        }
        $event->add_question_group($group_id, true);
        $link = EEM_Event_Question_Group::instance()->get_one([['EVT_ID' => $event_id, 'QSG_ID' => $group_id]]);
        if (! $link instanceof EE_Event_Question_Group) {
            throw new RuntimeException(esc_html__('A question group could not be attached to a migrated event.', 'migrate-events-to-event-espresso'));
        }
        $this->saved[ CrosswalkKeys::MODEL_EVENT_QUESTION_GROUP ][ (int) $link->ID() ] = $link;
        return (int) $link->ID();
    }


    /**
     * @throws RuntimeException When the answer cannot be saved.
     */
    public function createAnswer(int $registration_id, int $question_id, string $value): int
    {
        return $this->save(
            EE_Answer::new_instance(['REG_ID' => $registration_id, 'QST_ID' => $question_id, 'ANS_value' => $value]),
            CrosswalkKeys::MODEL_ANSWER
        );
    }


    /**
     * @throws RuntimeException When the check-in cannot be saved.
     */
    public function createCheckin(int $registration_id, int $datetime_id, bool $checked_in, DateTimeImmutable $timestamp): int
    {
        return $this->save(
            EE_Checkin::new_instance([
                'REG_ID'        => $registration_id,
                'DTT_ID'        => $datetime_id,
                'CHK_in'        => $checked_in,
                'CHK_timestamp' => DateTime::createFromImmutable($timestamp),
            ]),
            CrosswalkKeys::MODEL_CHECKIN
        );
    }


    /**
     * Writes the crosswalk keys; the fingerprint is taken from the record as saved.
     *
     * @throws RuntimeException When the record was not saved by this writer.
     */
    public function recordCrosswalk(string $model, int $id, string $type, string $source_id, MigrationContext $context): void
    {
        $object = $this->saved[ $model ][ $id ] ?? null;
        if (! $object instanceof EE_Base_Class) {
            throw new RuntimeException(esc_html__('A migrated answer record is not known to this step.', 'migrate-events-to-event-espresso'));
        }
        $this->crosswalk->record(
            $object,
            $context->source(),
            $type,
            $source_id,
            $context->runId(),
            Crosswalk::fingerprintOf($object),
            $context
        );
    }


    /**
     * @throws RuntimeException When the object cannot be saved.
     */
    private function save($object, string $model): int
    {
        if (! $object instanceof EE_Base_Class || ! $object->save()) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: Event Espresso model name */
                    esc_html__('The migrated %s could not be saved.', 'migrate-events-to-event-espresso'),
                    esc_html($model)
                )
            );
        }
        $this->saved[ $model ][ (int) $object->ID() ] = $object;
        return (int) $object->ID();
    }
}
// End of file AnswerWriter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/AnswerWriter.php
