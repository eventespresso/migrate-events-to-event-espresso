<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

use DateTimeImmutable;
use DateTimeZone;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceZone;

/**
 * Class EventonScope
 *
 * Which EventON rows a run covers. An event is an `ajde_events` post; a
 * repeating event is still one post, whose dates are the intervals in its
 * `repeat_intervals` meta (index 0 is the first). Trashed posts and auto-drafts
 * and events whose last date ended before the horizon are left out (REQ-004).
 * The dates are wall-clock times written as if they were UTC, so the horizon
 * is tested after reading them in the event's own zone, which needs the meta,
 * and so the events in range are chosen in PHP from the meta of all candidates.
 *
 * In "single event" mode a repeating post is one migrated event (id = the post
 * id) with a datetime per interval; in "event per occurrence" mode each
 * interval of a repeating post is its own event with the id "<post id>:<index>".
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventonScope
{
    private SourceDatabase $db;

    private EventonSettings $settings;


    public function __construct(SourceDatabase $db, EventonSettings $settings)
    {
        $this->db       = $db;
        $this->settings = $settings;
    }


    /**
     * The ids of every `ajde_events` post that is not trash or an auto-draft.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function postIds(): array
    {
        $excluded = EventonSchema::EXCLUDED_POST_STATUSES;
        return array_map(
            'intval',
            $this->db->column(
                'SELECT ID FROM ' . $this->db->table('posts') . ' WHERE post_type = %s AND post_status NOT IN ('
                . SourceDatabase::placeholders($excluded, '%s') . ') ORDER BY ID',
                array_merge([EventonSchema::POST_TYPE_EVENT], $excluded)
            )
        );
    }


    /**
     * The events in range: post id => ['intervals' => [index => [start, end]], 'repeating' => bool]. The intervals are
     * wall-clock integers, first interval first. In "event per occurrence" mode only the intervals that ended on or
     * after the horizon are kept; in "single event" mode an event is kept whole when any of its intervals did.
     *
     * @return array<int, array{intervals: array<int, int[]>, repeating: bool}>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function inRange(MigrationOptions $options): array
    {
        $ids      = $this->postIds();
        $meta     = $this->db->postMeta($ids, ['evcal_srow', 'evcal_erow', '_evo_tz', 'evcal_repeat', 'repeat_intervals']);
        $boundary = $options->isAllEvents() ? null : (int) strtotime(SourceAdapterBase::horizonBoundaryUtc($options) . ' UTC');
        $found    = [];
        foreach ($ids as $id) {
            $fields    = $meta[ $id ] ?? [];
            $intervals = self::intervals($fields);
            $zone      = $this->zone($fields);
            $kept      = array_filter(
                $intervals,
                fn(array $interval): bool => $boundary === null || $this->utcOf($interval[1], $zone) >= $boundary
            );
            if ($kept !== []) {
                $found[ $id ] = [
                    'intervals' => $options->isEventPerOccurrence() ? $kept : $intervals,
                    'repeating' => count($intervals) > 1,
                ];
            }
        }
        return $found;
    }


    /**
     * The migrated event ids, in post order and interval order.
     *
     * @return string[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function eventIds(MigrationOptions $options): array
    {
        $found = [];
        foreach ($this->inRange($options) as $id => $event) {
            if ($options->isEventPerOccurrence() && $event['repeating']) {
                foreach (array_keys($event['intervals']) as $index) {
                    $found[] = $id . ':' . $index;
                }
                continue;
            }
            $found[] = (string) $id;
        }
        return $found;
    }


    /**
     * The date intervals of an event from its meta: the `repeat_intervals` of a repeating event with more than one,
     * otherwise its one start and end. Each is [start, end] as wall-clock integers.
     *
     * @param array $fields The event's meta (evcal_srow, evcal_erow, evcal_repeat, repeat_intervals).
     * @return array<int, int[]>
     */
    public static function intervals(array $fields): array
    {
        $repeats = $fields['repeat_intervals'] ?? [];
        if (($fields['evcal_repeat'] ?? '') === 'yes' && is_array($repeats) && count($repeats) > 1) {
            $found = [];
            foreach (array_values($repeats) as $index => $pair) {
                $pair = is_array($pair) ? array_values($pair) : [];
                if (isset($pair[0])) {
                    $found[ $index ] = [(int) $pair[0], (int) ($pair[1] ?? $pair[0])];
                }
            }
            return $found;
        }
        $start = (int) ($fields['evcal_srow'] ?? 0);
        return $start > 0 ? [0 => [$start, (int) ($fields['evcal_erow'] ?? 0) ?: $start]] : [];
    }


    /**
     * The zone an event's wall-clock dates are in: its `_evo_tz`, else the global zone when EventON applies one to
     * all events, else null (the site's zone).
     *
     * @param array $fields The event's meta.
     * @throws MigrationRecordException
     */
    public function zone(array $fields): ?string
    {
        return SourceZone::normalize((string) ($fields['_evo_tz'] ?? '')) ?? SourceZone::normalize((string) $this->settings->defaultZone());
    }


    /**
     * The true instant (a Unix timestamp) of a wall-clock integer in the given zone (the site's zone when null).
     */
    private function utcOf(int $wall_clock, ?string $zone): int
    {
        $local = new DateTimeZone($zone ?? wp_timezone_string() ?: 'UTC');
        return (new DateTimeImmutable(gmdate('Y-m-d H:i:s', $wall_clock), $local))->getTimestamp();
    }
}
// End of file EventonScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonScope.php
