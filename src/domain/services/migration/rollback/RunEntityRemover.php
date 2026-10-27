<?php

namespace EventEspresso\EventMigrator\domain\services\migration\rollback;

use EE_Base_Class;
use EE_Default_Where_Conditions;
use EE_Error;
use EE_Registry;
use EE_Term_Taxonomy;
use EE_Ticket;
use EEM_Base;
use EEM_CPT_Base;
use EEM_Event;
use EEM_Event_Question_Group;
use EEM_Registration;
use EEM_Ticket;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\persist\TransactionLineItems;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use ReflectionException;
use RuntimeException;
use Throwable;
use EventEspresso\EventMigrator\domain\services\migration\run\ThrowableLogger;

/**
 * Class RunEntityRemover
 *
 * Deletes one chunk of a run's records of one model, with the recipe that
 * model needs: the join rows and child records that would block
 * EEM_Base::delete_permanently() are removed first, sold counts are reversed
 * for approved registrations, and every delete is verified with
 * exists_by_ID(). Shared reference data the run created (a contact, venue,
 * question, question group or category) that something outside the run now
 * uses is kept and only loses its crosswalk keys. Ids still present after
 * their recipe are returned as failures, which leaves the run's rollback
 * "partial" and retryable.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RunEntityRemover
{
    private Crosswalk $crosswalk;


    public function __construct(Crosswalk $crosswalk)
    {
        $this->crosswalk = $crosswalk;
    }


    /**
     * @param string             $model_name The EE model name.
     * @param int[]              $ids        Ids of the run's records of that model.
     * @param MigrationRunRecord $record     The run, which receives the sold-count reversals.
     * @return int[] the ids that are still present
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function delete(string $model_name, array $ids, MigrationRunRecord $record): array
    {
        if ($ids === []) {
            return [];
        }
        $model   = $this->model($model_name);
        $objects = $model->get_all(
            [
                [$model->primary_key_name() => ['IN', $ids]],
                'default_where_conditions' => EE_Default_Where_Conditions::NONE,
            ]
        );
        $failed  = [];
        foreach ($objects as $object) {
            if (! $object instanceof EE_Base_Class) {
                continue;
            }
            $id = (int) $object->ID();
            try {
                if ($this->keepShared($model_name, $object)) {
                    $this->crosswalk->remove($object);
                    continue;
                }
                $this->remove($model_name, $object, $record);
            } catch (Throwable $throwable) {
                $this->logThrowable($throwable);
            }
            if ($model->exists_by_ID($id)) {
                $failed[] = $id;
            }
        }
        return $failed;
    }


    /**
     * Whether a record the run created is now used by something outside the run.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function keepShared(string $model_name, EE_Base_Class $object): bool
    {
        $id   = (int) $object->ID();
        $none = EE_Default_Where_Conditions::NONE;
        switch ($model_name) {
            case CrosswalkKeys::MODEL_ATTENDEE:
                return EEM_Registration::instance()->count([['ATT_ID' => $id], 'default_where_conditions' => $none]) > 0;
            case CrosswalkKeys::MODEL_VENUE:
                return EEM_Event::instance()->count([['VNU_ID' => $id], 'default_where_conditions' => $none]) > 0;
            case CrosswalkKeys::MODEL_QUESTION:
                return $object->count_related('Answer') > 0;
            case CrosswalkKeys::MODEL_QUESTION_GROUP:
                return EEM_Event_Question_Group::instance()->count([['QSG_ID' => $id]]) > 0;
            case CrosswalkKeys::MODEL_TERM_TAXONOMY:
                // The cached object may predate the event deletes that just lowered the count, so re-read it.
                if (! $object instanceof EE_Term_Taxonomy) {
                    return false;
                }
                $object->refresh_from_db();
                return (int) $object->get('term_count') > 0;
            default:
                return false;
        }
    }


    /**
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function remove(string $model_name, EE_Base_Class $object, MigrationRunRecord $record): void
    {
        switch ($model_name) {
            case CrosswalkKeys::MODEL_PAYMENT:
                $object->delete_related_permanently('Registration_Payment');
                break;
            case CrosswalkKeys::MODEL_REGISTRATION:
                $this->removeRegistration($object, $record);
                return;
            case CrosswalkKeys::MODEL_TRANSACTION:
                TransactionLineItems::deleteAll((int) $object->ID());
                break;
            case CrosswalkKeys::MODEL_TICKET:
                $object->_remove_relations('Price');
                $object->_remove_relations('Datetime');
                break;
            case CrosswalkKeys::MODEL_PRICE:
                $object->_remove_relations('Ticket');
                break;
            case CrosswalkKeys::MODEL_EVENT:
                $object->_remove_relations('Question_Group');
                wp_delete_object_term_relationships((int) $object->ID(), EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY);
                break;
            case CrosswalkKeys::MODEL_QUESTION:
                $object->delete_related_permanently('Question_Option');
                $object->_remove_relations('Question_Group');
                break;
            case CrosswalkKeys::MODEL_QUESTION_GROUP:
                $object->_remove_relations('Question');
                break;
            case CrosswalkKeys::MODEL_TERM_TAXONOMY:
                // wp_delete_term() bypasses the model, so the crosswalk keys are removed first.
                $this->crosswalk->remove($object);
                wp_delete_term((int) $object->get('term_id'), EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY);
                return;
        }
        $object->delete_permanently();
    }


    /**
     * Deletes a registration and its own answers, check-ins and payment links, then reverses the sold count it
     * added when it was approved. The reversal follows a verified delete, so a retried chunk never reverses twice.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function removeRegistration(EE_Base_Class $registration, MigrationRunRecord $record): void
    {
        $id        = (int) $registration->ID();
        $approved  = $registration->get_raw('STS_ID') === 'RAP';
        $ticket_id = (int) $registration->get_raw('TKT_ID');
        $registration->delete_related_permanently('Answer');
        $registration->delete_related_permanently('Checkin');
        $registration->delete_related_permanently('Registration_Payment');
        $registration->delete_permanently();
        if (! $approved || EEM_Registration::instance()->exists_by_ID($id)) {
            return;
        }
        $ticket = EEM_Ticket::instance()->get_one_by_ID($ticket_id);
        if ($ticket instanceof EE_Ticket) {
            $ticket->decreaseSold();
            $record->addSoldAdjustment($ticket_id, -1);
        }
    }


    /**
     * @throws RuntimeException When the model name is unknown.
     */
    private function model(string $model_name): EEM_Base
    {
        $model = EE_Registry::instance()->load_model($model_name);
        if (! $model instanceof EEM_Base) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: EE model name */
                    esc_html__('Unknown Event Espresso model "%s" in migration rollback.', 'migrate-events-to-event-espresso'),
                    esc_html($model_name)
                )
            );
        }
        return $model;
    }


    private function logThrowable(Throwable $throwable): void
    {
        ThrowableLogger::log($throwable);
    }
}
// End of file RunEntityRemover.php
// Location: EventEspresso\EventMigrator\domain\services\migration\rollback/RunEntityRemover.php
