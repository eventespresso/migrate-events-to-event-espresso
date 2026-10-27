<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EE_Base_Class;
use EE_Datetime;
use EE_Event;
use EE_Price;
use EE_Term_Taxonomy;
use EE_Ticket;
use EE_Venue;
use EEM_CPT_Base;
use EEM_Datetime_Ticket;
use EEM_Event;
use EEM_Ticket_Price;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use RuntimeException;
use WP_Post;

/**
 * Class EventGraphWriter
 *
 * The model writes behind EventGraphPersister. Objects it saves are kept for
 * the duration of one event graph (until reset()), so crosswalk records and
 * relation links can be made on the same instances; callers deal in ids.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGraphWriter
{
    /**
     * EE model name => entity class created by insert().
     */
    private const ENTITY_CLASSES = [
        CrosswalkKeys::MODEL_VENUE    => EE_Venue::class,
        CrosswalkKeys::MODEL_EVENT    => EE_Event::class,
        CrosswalkKeys::MODEL_DATETIME => EE_Datetime::class,
        CrosswalkKeys::MODEL_TICKET   => EE_Ticket::class,
        CrosswalkKeys::MODEL_PRICE    => EE_Price::class,
    ];

    private Crosswalk $crosswalk;

    /**
     * Objects saved since the last reset(), model => id => object.
     *
     * @var array<string, array<int, EE_Base_Class>>
     */
    private array $saved = [];


    public function __construct(Crosswalk $crosswalk)
    {
        $this->crosswalk = $crosswalk;
    }


    /**
     * Forgets the objects of the previous graph.
     */
    public function reset(): void
    {
        $this->saved = [];
    }


    /**
     * Saves a new entity and returns its id.
     *
     * @throws RuntimeException When the model is not one this persister creates or the save fails.
     */
    public function insert(string $model, array $fields): int
    {
        $class  = self::ENTITY_CLASSES[ $model ] ?? '';
        $object = $class !== '' ? $class::new_instance($fields) : null;
        if (! $object instanceof EE_Base_Class || ! $object->save()) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: Event Espresso model name */
                    esc_html__('The migrated %s could not be saved.', 'migrate-events-to-event-espresso'),
                    esc_html($model)
                )
            );
        }
        $id                           = (int) $object->ID();
        $this->saved[ $model ][ $id ] = $object;
        return $id;
    }


    /**
     * Writes the crosswalk keys; the fingerprint is read from the saved record (Crosswalk::fingerprintOf()).
     */
    public function recordCrosswalk(
        string $model,
        int $id,
        string $type,
        string $source_id,
        MigrationContext $context
    ): void {
        $object = $this->saved($model, $id);
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
     * Keeps the source's cancelled or postponed state on an event written as a draft (CrosswalkKeys::SRC_STATUS).
     */
    public function addSourceStatus(int $event_id, string $status): void
    {
        $this->saved(CrosswalkKeys::MODEL_EVENT, $event_id)->add_extra_meta(CrosswalkKeys::SRC_STATUS, $status, true);
    }


    public function recordAlias(string $model, int $id, string $type, string $source_id, MigrationContext $context): void
    {
        $this->crosswalk->recordAlias($this->saved($model, $id), $context->source(), $type, $source_id, $context);
    }


    /**
     * Links an existing category by name or slug, or creates it (EEM_CPT_Base::add_event_category()).
     *
     * @return array{id: int, created: bool} The term_taxonomy id and whether this call created the category.
     * @throws RuntimeException When the category cannot be linked.
     */
    public function addCategory(int $event_id, string $name): array
    {
        $taxonomy      = EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY;
        $existed       = $this->categoryExists($taxonomy, $name);
        $term_taxonomy = EEM_Event::instance()->add_event_category($this->saved(CrosswalkKeys::MODEL_EVENT, $event_id), $name);
        if (! $term_taxonomy instanceof EE_Term_Taxonomy) {
            throw new RuntimeException(esc_html__('An event category could not be linked.', 'migrate-events-to-event-espresso'));
        }
        $this->saved[ CrosswalkKeys::MODEL_TERM_TAXONOMY ][ (int) $term_taxonomy->ID() ] = $term_taxonomy;
        return ['id' => (int) $term_taxonomy->ID(), 'created' => ! $existed];
    }


    /**
     * Whether the taxonomy already has a term with this name or slug. Read without WordPress's term query cache:
     * event categories are created during the run, and a cached "not found" from an earlier check made a second
     * event naming the same category count it as created again.
     */
    protected function categoryExists(string $taxonomy, string $name): bool
    {
        foreach (['slug' => sanitize_title($name), 'name' => $name] as $field => $value) {
            $ids = get_terms(
                [
                    'taxonomy'      => $taxonomy,
                    'hide_empty'    => false,
                    'fields'        => 'ids',
                    'number'        => 1,
                    'cache_results' => false,
                    $field          => $value,
                ]
            );
            if (is_array($ids) && $ids !== []) {
                return true;
            }
        }
        return false;
    }


    public function assignFeaturedImage(int $event_id, int $attachment_id): bool
    {
        $attachment = get_post($attachment_id);
        if (! $attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
            return false;
        }
        return set_post_thumbnail($event_id, $attachment_id) !== false;
    }


    /**
     * EE_HABTM_Relation::add_relation_to() returns its argument even when the join write failed, so the join
     * row is re-queried.
     *
     * @throws RuntimeException When the join row is missing.
     */
    public function linkTicketToPrice(int $ticket_id, int $price_id): void
    {
        $this->saved(CrosswalkKeys::MODEL_TICKET, $ticket_id)->_add_relation_to(
            $this->saved(CrosswalkKeys::MODEL_PRICE, $price_id),
            'Price'
        );
        if (EEM_Ticket_Price::instance()->count([['TKT_ID' => $ticket_id, 'PRC_ID' => $price_id]]) === 0) {
            throw new RuntimeException(esc_html__('A migrated ticket could not be linked to its price.', 'migrate-events-to-event-espresso'));
        }
    }


    /**
     * @throws RuntimeException When the join row is missing.
     */
    public function linkDatetimeToTicket(int $datetime_id, int $ticket_id): void
    {
        $this->saved(CrosswalkKeys::MODEL_DATETIME, $datetime_id)->_add_relation_to(
            $this->saved(CrosswalkKeys::MODEL_TICKET, $ticket_id),
            'Ticket'
        );
        if (EEM_Datetime_Ticket::instance()->count([['DTT_ID' => $datetime_id, 'TKT_ID' => $ticket_id]]) === 0) {
            throw new RuntimeException(esc_html__('A migrated ticket could not be linked to its date.', 'migrate-events-to-event-espresso'));
        }
    }


    /**
     * @throws RuntimeException When the object was not saved by this call.
     */
    private function saved(string $model, int $id): EE_Base_Class
    {
        if (! isset($this->saved[ $model ][ $id ])) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %1$s: Event Espresso model name, %2$d: record id */
                    esc_html__('The migrated %1$s %2$d is not known to this step.', 'migrate-events-to-event-espresso'),
                    esc_html($model),
                    esc_html((string) $id)
                )
            );
        }
        return $this->saved[ $model ][ $id ];
    }
}
// End of file EventGraphWriter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/EventGraphWriter.php
