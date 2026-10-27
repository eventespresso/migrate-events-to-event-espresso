<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use EventEspresso\core\services\database\TableAnalysis;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use stdClass;
use wpdb;

/**
 * Class SourceDatabase
 *
 * The read-only database access a source adapter and its readers share
 * (REQ-102, REQ-109): prepared SELECTs, table and column existence by exact
 * name, IN-list placeholders, and batched reads of WordPress post meta, terms,
 * users and options. Table names are built from the adapter's constants plus
 * the table prefix, never from request data. The prefix can be overridden so
 * tests can read a fixture loaded under its own prefix.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourceDatabase
{
    /**
     * Post ids per meta query, so a large id list never becomes one unbounded IN list.
     */
    public const META_CHUNK = 500;

    private TableAnalysis $table_analysis;

    private wpdb $wpdb;

    private string $prefix;

    /**
     * @var array<string, bool> unprefixed table name => exists, memoised per instance
     */
    private array $known_tables = [];

    /**
     * @var array<string, string[]> unprefixed table name => column names, memoised per instance
     */
    private array $known_columns = [];


    /**
     * @param TableAnalysis $table_analysis Core's table existence check.
     * @param wpdb          $wpdb           The database connection.
     * @param string        $prefix         The table prefix; empty for the site's own.
     */
    public function __construct(TableAnalysis $table_analysis, wpdb $wpdb, string $prefix = '')
    {
        $this->table_analysis = $table_analysis;
        $this->wpdb           = $wpdb;
        $this->prefix         = $prefix !== '' ? $prefix : $wpdb->prefix;
    }


    public function prefix(): string
    {
        return $this->prefix;
    }


    /**
     * The prefixed name of a table, e.g. "em_events" => "wp_em_events", "posts" => "wp_posts".
     *
     * @param string $unprefixed A table-name constant of an adapter, or a WordPress core table name.
     */
    public function table(string $unprefixed): string
    {
        return $this->prefix . $unprefixed;
    }


    /**
     * Whether a table exists, by its exact prefixed name (REQ-103).
     */
    public function tableExists(string $unprefixed): bool
    {
        if (! array_key_exists($unprefixed, $this->known_tables)) {
            $this->known_tables[ $unprefixed ] = $this->table_analysis->tableExists($this->table($unprefixed));
        }
        return $this->known_tables[ $unprefixed ];
    }


    /**
     * Whether every listed table exists.
     *
     * @param string[] $unprefixed
     */
    public function tablesExist(array $unprefixed): bool
    {
        foreach ($unprefixed as $table) {
            if (! $this->tableExists($table)) {
                return false;
            }
        }
        return true;
    }


    /**
     * Whether a table has a column; false when the table does not exist.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    public function columnExists(string $unprefixed, string $column): bool
    {
        if (! $this->tableExists($unprefixed)) {
            return false;
        }
        if (! isset($this->known_columns[ $unprefixed ])) {
            $this->known_columns[ $unprefixed ] = array_column(
                $this->rows('SHOW COLUMNS FROM ' . $this->table($unprefixed)),
                'Field'
            );
        }
        return in_array($column, $this->known_columns[ $unprefixed ], true);
    }


    /**
     * Runs a prepared SELECT and returns associative rows.
     *
     * @param string $sql  SQL with %s/%d placeholders; table names come from table().
     * @param array  $args Values bound to the placeholders, in order.
     * @throws MigrationRecordException When the database reports an error.
     */
    public function rows(string $sql, array $args = []): array
    {
        $this->wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is an adapter's SELECT with %s/%d placeholders; prepared() binds $args with $wpdb->prepare().
        $results                = $this->wpdb->get_results($this->prepared($sql, $args), ARRAY_A);
        $this->failOnDbError();
        return is_array($results) ? $results : [];
    }


    /**
     * Runs a prepared SELECT and returns the first column of every row as strings.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    public function column(string $sql, array $args = []): array
    {
        $this->wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see rows().
        $results                = $this->wpdb->get_col($this->prepared($sql, $args));
        $this->failOnDbError();
        return array_map('strval', is_array($results) ? $results : []);
    }


    /**
     * Runs a prepared SELECT and returns the first column of the first row, or null.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    public function scalar(string $sql, array $args = []): ?string
    {
        $this->wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see rows().
        $value                  = $this->wpdb->get_var($this->prepared($sql, $args));
        $this->failOnDbError();
        return $value === null ? null : (string) $value;
    }


    /**
     * Runs a prepared SELECT COUNT(...) and returns the count.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    public function countRows(string $sql, array $args = []): int
    {
        return (int) $this->scalar($sql, $args);
    }


    /**
     * A comma-separated list of placeholders for an IN (...) clause; "NULL" for an empty list so the clause
     * matches nothing.
     *
     * @param array  $values The values the placeholders will bind.
     * @param string $type   The placeholder: %d or %s.
     */
    public static function placeholders(array $values, string $type = '%d'): string
    {
        return $values === [] ? 'NULL' : implode(',', array_fill(0, count($values), $type === '%s' ? '%s' : '%d'));
    }


    // The add-on's PHP 7.4 floor has no native mixed type, so these two helpers carry it in their docblocks.
    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint,SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
    /**
     * Decodes a serialized value read from a source plugin's tables without instantiating any class: source rows
     * can hold visitor-submitted data, so only stdClass (which has no magic methods) is decoded, and it becomes an
     * array; every other object becomes null, also inside arrays (no PHP object injection). A value that is not
     * serialized, or does not decode, is returned unchanged.
     *
     * @param mixed $value The raw column value.
     * @return mixed The decoded scalar or array, or the value itself.
     */
    public static function safeUnserialize($value)
    {
        if (! is_string($value) || ! is_serialized($value)) {
            return $value;
        }
        $decoded = @unserialize(trim($value), ['allowed_classes' => ['stdClass']]);
        if ($decoded === false && trim($value) !== 'b:0;') {
            return $value;
        }
        return self::withoutObjects($decoded);
    }


    /**
     * @param mixed $value
     * @return mixed The value with stdClass objects as arrays and every other object (an __PHP_Incomplete_Class)
     *               replaced by null.
     */
    private static function withoutObjects($value)
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_object($value)) {
            return null;
        }
        if (is_array($value)) {
            return array_map([self::class, 'withoutObjects'], $value);
        }
        return $value;
    }
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint,SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint


    /**
     * The post meta of several posts in one query, unserialised: post id => meta key => first value.
     *
     * @param int[]    $post_ids The posts.
     * @param string[] $keys     Only these keys; all keys when empty.
     * @return array<int, array<string, mixed>>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function postMeta(array $post_ids, array $keys = []): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $meta     = [];
        foreach (array_chunk($post_ids, self::META_CHUNK) as $chunk) {
            $sql  = 'SELECT post_id, meta_key, meta_value FROM ' . $this->table('postmeta')
                . ' WHERE post_id IN (' . self::placeholders($chunk) . ')';
            $args = $chunk;
            if ($keys !== []) {
                $sql .= ' AND meta_key IN (' . self::placeholders($keys, '%s') . ')';
                $args = array_merge($args, array_values($keys));
            }
            foreach ($this->rows($sql . ' ORDER BY meta_id', $args) as $row) {
                $post_id = (int) $row['post_id'];
                $key     = (string) $row['meta_key'];
                if (! isset($meta[ $post_id ][ $key ])) {
                    $meta[ $post_id ][ $key ] = self::safeUnserialize($row['meta_value']);
                }
            }
        }
        return $meta;
    }


    /**
     * Every value of the listed post meta keys of several posts, for keys a plugin stores in several rows:
     * post id => meta key => values in row order (unserialised).
     *
     * @param int[]    $post_ids The posts.
     * @param string[] $keys     The meta keys.
     * @return array<int, array<string, array>>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function postMetaAll(array $post_ids, array $keys): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $meta     = [];
        if ($keys === []) {
            return $meta;
        }
        foreach (array_chunk($post_ids, self::META_CHUNK) as $chunk) {
            $rows = $this->rows(
                'SELECT post_id, meta_key, meta_value FROM ' . $this->table('postmeta')
                . ' WHERE post_id IN (' . self::placeholders($chunk) . ')'
                . ' AND meta_key IN (' . self::placeholders($keys, '%s') . ') ORDER BY meta_id',
                array_merge($chunk, array_values($keys))
            );
            foreach ($rows as $row) {
                $meta[ (int) $row['post_id'] ][ (string) $row['meta_key'] ][] = self::safeUnserialize($row['meta_value']);
            }
        }
        return $meta;
    }


    /**
     * Posts by id with the fields adapters read: post id => row (ID, post_type, post_status, post_title,
     * post_content, post_excerpt, post_author, post_name, post_date, post_parent).
     *
     * @param int[] $post_ids The posts.
     * @return array<int, array<string, string>>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function posts(array $post_ids): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if ($post_ids === []) {
            return [];
        }
        $posts = [];
        $rows  = $this->rows(
            'SELECT ID, post_type, post_status, post_title, post_content, post_excerpt, post_author, post_name, post_date, post_parent'
            . ' FROM ' . $this->table('posts') . ' WHERE ID IN (' . self::placeholders($post_ids) . ')',
            $post_ids
        );
        foreach ($rows as $row) {
            $posts[ (int) $row['ID'] ] = array_map('strval', $row);
        }
        return $posts;
    }


    /**
     * The names of the terms of one taxonomy attached to several posts: post id => term names in name order.
     *
     * @param int[] $post_ids
     * @return array<int, string[]>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function postTerms(array $post_ids, string $taxonomy): array
    {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if ($post_ids === []) {
            return [];
        }
        $rows  = $this->rows(
            'SELECT tr.object_id, t.name FROM ' . $this->table('term_relationships') . ' tr'
            . ' JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . ' JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.taxonomy = %s AND tr.object_id IN (' . self::placeholders($post_ids) . ')'
            . ' ORDER BY t.name, t.term_id',
            array_merge([$taxonomy], $post_ids)
        );
        $terms = [];
        foreach ($rows as $row) {
            $terms[ (int) $row['object_id'] ][] = (string) $row['name'];
        }
        return $terms;
    }


    /**
     * WordPress users by id with their display name, email and the listed user meta: user id => fields.
     *
     * @param int[]    $user_ids
     * @param string[] $meta_keys
     * @return array<int, array<string, string>>
     * @throws MigrationRecordException When the database reports an error.
     */
    public function users(array $user_ids, array $meta_keys = []): array
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === []) {
            return [];
        }
        $users = [];
        $rows  = $this->rows(
            'SELECT ID, user_email, display_name FROM ' . $this->table('users')
            . ' WHERE ID IN (' . self::placeholders($user_ids) . ')',
            $user_ids
        );
        foreach ($rows as $row) {
            $users[ (int) $row['ID'] ] = [
                'user_email'   => (string) $row['user_email'],
                'display_name' => (string) $row['display_name'],
            ];
        }
        if ($users === [] || $meta_keys === []) {
            return $users;
        }
        $rows = $this->rows(
            'SELECT user_id, meta_key, meta_value FROM ' . $this->table('usermeta')
            . ' WHERE user_id IN (' . self::placeholders(array_keys($users)) . ')'
            . ' AND meta_key IN (' . self::placeholders($meta_keys, '%s') . ') ORDER BY umeta_id',
            array_merge(array_keys($users), array_values($meta_keys))
        );
        foreach ($rows as $row) {
            $user_id = (int) $row['user_id'];
            if (! isset($users[ $user_id ][ $row['meta_key'] ])) {
                $users[ $user_id ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
            }
        }
        return $users;
    }


    /**
     * A WordPress option read straight from the options table, or null when absent.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    public function option(string $name): ?string
    {
        return $this->scalar(
            'SELECT option_value FROM ' . $this->table('options') . ' WHERE option_name = %s LIMIT 1',
            [$name]
        );
    }


    /**
     * Binds the values to the SELECT's placeholders. Every SELECT the adapters pass in is a literal in their
     * own code with %s/%d placeholders and table names from table(); only the values vary.
     */
    private function prepared(string $sql, array $args): string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the adapter's literal query; this is the prepare() call.
        return $args === [] ? $sql : (string) $this->wpdb->prepare($sql, ...$args);
    }


    /**
     * @throws MigrationRecordException
     */
    private function failOnDbError(): void
    {
        if ($this->wpdb->last_error === '') {
            return;
        }
        throw new MigrationRecordException(
            sprintf(
                /* translators: %s: database error text */
                esc_html__('A source database read failed: %s', 'migrate-events-to-event-espresso'),
                esc_html($this->wpdb->last_error)
            )
        );
    }
}
// End of file SourceDatabase.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/SourceDatabase.php
