<?php

namespace EventEspresso\EventMigrator\domain\services\migration;

use EventEspresso\core\services\options\JsonWpOptionManager;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use wpdb;

/**
 * Class MigrationLock
 *
 * The migrator's own run lock (REQ-002): one WP option holding
 * "{ts}|{token}" after acquire() and "{ts}|{token}|{job_id}" after claim(),
 * written with atomic SQL so two requests can never both start a run.
 *
 * The CSV Event Importer add-on is a separate, optional product. The two
 * never share a class; the migrator only READS the importer's lock option
 * (by name, when it exists) so a migration refuses to start while a CSV
 * import is running on the same site.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationLock
{
    public const LOCK_OPTION = 'ee_event_migrator_lock';

    /**
     * The CSV Event Importer add-on's lock option. Read only, by name; the
     * importer need not be installed.
     */
    public const CSV_IMPORT_LOCK_OPTION = 'ee_event_importer_import_lock';

    /**
     * Separator between the ts/token/job_id segments of the raw lock value.
     */
    public const LOCK_VALUE_SEPARATOR = '|';

    /**
     * Seconds after which a held lock is reported as stale.
     */
    public const STALE_LOCK_THRESHOLD = 7200;

    public const HOLDER_CSV       = 'csv';

    public const HOLDER_MIGRATION = 'migration';

    public const HOLDER_UNKNOWN   = 'unknown';

    private MigrationConfig $migration_config;

    private JsonWpOptionManager $option_manager;

    private wpdb $wpdb;


    public function __construct(MigrationConfig $migration_config, JsonWpOptionManager $option_manager)
    {
        $this->migration_config = $migration_config;
        $this->option_manager   = $option_manager;
        $this->wpdb             = $GLOBALS['wpdb'];
    }


    /**
     * Atomically acquires the lock. INSERT IGNORE has no UPDATE clause, so at
     * most one racing caller affects a row. Returns the lock's timestamp and
     * run token, or null when the lock is already held or a CSV import is
     * running (best-effort read of the importer's option, not atomic).
     *
     * @return array{ts: string, token: string}|null
     */
    public function acquire(): ?array
    {
        if ($this->csvImportInProgress()) {
            return null;
        }
        $ts         = (string) time();
        $token      = substr(md5(uniqid((string) wp_rand(), true)), 0, 16);
        $lock_value = $ts . self::LOCK_VALUE_SEPARATOR . $token;
        $wpdb       = $this->wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock is one atomic INSERT IGNORE on the options row; the option caches are cleared right after (clearOptionCaches()).
        $acquired   = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                $wpdb->options,
                self::LOCK_OPTION,
                $lock_value
            )
        ) === 1;
        $this->clearOptionCaches();
        return $acquired ? ['ts' => $ts, 'token' => $token] : null;
    }


    /**
     * Binds one batch job to an acquired, not-yet-claimed lock. The WHERE
     * clause re-verifies the unclaimed value, so a reloaded batch URL (same
     * token, new job id) fails to claim and cannot start a second run.
     */
    public function claim(string $ts, string $token, string $job_id): bool
    {
        if ($ts === '' || $token === '' || $job_id === '') {
            return false;
        }
        $wpdb          = $this->wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the lock row; see acquire().
        $rows_affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $wpdb->options,
                $this->lockValue($ts, $token, $job_id),
                self::LOCK_OPTION,
                $this->lockValue($ts, $token)
            )
        );
        $this->clearOptionCaches();
        return $rows_affected === 1;
    }


    /**
     * Releases the lock only when it still holds this exact value, so a stale
     * job's cleanup can never remove a lock a newer run has acquired.
     *
     * @param string $ts     The lock timestamp returned by acquire().
     * @param string $token  The run token returned by acquire().
     * @param string $job_id The claiming job id; empty releases an acquired-but-unclaimed lock.
     */
    public function release(string $ts, string $token, string $job_id = ''): bool
    {
        if ($ts === '' || $token === '') {
            return false;
        }
        $wpdb          = $this->wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete on the lock row; see acquire().
        $rows_affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE option_name = %s AND option_value = %s",
                $wpdb->options,
                self::LOCK_OPTION,
                $this->lockValue($ts, $token, $job_id)
            )
        );
        $this->clearOptionCaches();
        return $rows_affected === 1;
    }


    /**
     * Whether the lock option currently holds exactly this job's claimed value.
     */
    public function isOwnedBy(string $ts, string $token, string $job_id): bool
    {
        return $this->rawValue() === $this->lockValue($ts, $token, $job_id);
    }


    /**
     * Whether the migrator's lock option holds any value (acquired or claimed).
     *
     * @since 0.0.1.000
     */
    public function isHeld(): bool
    {
        return $this->rawValue() !== '';
    }


    /**
     * The raw lock option value, read past the option caches.
     */
    public function rawValue(): string
    {
        $this->clearOptionCaches();
        return (string) get_option(self::LOCK_OPTION, '');
    }


    /**
     * @return array{ts: int, token: string, job_id: string}
     */
    public function parse(): array
    {
        return self::parseLockValue($this->rawValue());
    }


    /**
     * Parses a raw lock value into its ts/token/job_id segments. Missing or
     * empty segments degrade to 0/'' rather than throwing.
     *
     * @return array{ts: int, token: string, job_id: string}
     */
    public static function parseLockValue(string $lock_value): array
    {
        if ($lock_value === '') {
            return ['ts' => 0, 'token' => '', 'job_id' => ''];
        }
        $parts = explode(self::LOCK_VALUE_SEPARATOR, $lock_value, 3);
        return [
            'ts'     => (int) ($parts[0] ?? 0),
            'token'  => (string) ($parts[1] ?? ''),
            'job_id' => (string) ($parts[2] ?? ''),
        ];
    }


    /**
     * Whether the CSV Event Importer add-on holds a non-stale import lock.
     * Reads the option by name only; false when the importer is not installed.
     */
    public function csvImportInProgress(): bool
    {
        wp_cache_delete(self::CSV_IMPORT_LOCK_OPTION, 'options');
        $parsed = self::parseLockValue((string) get_option(self::CSV_IMPORT_LOCK_OPTION, ''));
        return $parsed['ts'] > 0 && (time() - $parsed['ts']) <= self::STALE_LOCK_THRESHOLD;
    }


    /**
     * Who holds a lock, or null when both locks are free. The kind is
     * "migration" when the migration config records this lock's timestamp,
     * "unknown" when the migrator's lock is held but the config does not
     * record it (a request crashed between acquiring the lock and saving its
     * config), and "csv" when only the CSV importer's lock is held.
     *
     * @return array{kind: string, source: string|null, ts: int, stale: bool}|null
     */
    public function holder(): ?array
    {
        $parsed = $this->parse();
        if ($parsed['ts'] === 0 && $parsed['token'] === '') {
            if ($this->csvImportInProgress()) {
                $csv = self::parseLockValue((string) get_option(self::CSV_IMPORT_LOCK_OPTION, ''));
                return ['kind' => self::HOLDER_CSV, 'source' => null, 'ts' => $csv['ts'], 'stale' => false];
            }
            return null;
        }
        $ts     = (string) $parsed['ts'];
        $kind   = self::HOLDER_UNKNOWN;
        $source = null;

        $migration_config = new MigrationConfig();
        $this->option_manager->populateFromDb($migration_config);
        if ($migration_config->isImportInProgress() && $migration_config->getLockAcquiredAt() === $ts) {
            $kind   = self::HOLDER_MIGRATION;
            $source = $migration_config->getSourceSlug();
        }
        return [
            'kind'   => $kind,
            'source' => $source,
            'ts'     => $parsed['ts'],
            'stale'  => $parsed['ts'] > 0 && (time() - $parsed['ts']) > self::STALE_LOCK_THRESHOLD,
        ];
    }


    /**
     * A translated sentence naming the running import (REQ-002), or '' when
     * no lock is held. Translated at call time, never cached.
     */
    public function describeHolder(): string
    {
        $holder = $this->holder();
        if ($holder === null) {
            return '';
        }
        if ($holder['kind'] === self::HOLDER_MIGRATION) {
            return sprintf(
                /* translators: %s: the source plugin slug or name being migrated */
                esc_html__('A migration from %s is in progress.', 'migrate-events-to-event-espresso'),
                (string) $holder['source']
            );
        }
        if ($holder['kind'] === self::HOLDER_CSV) {
            return esc_html__('A CSV import by the Event Importer add-on is in progress.', 'migrate-events-to-event-espresso');
        }
        return esc_html__('A migration is in progress.', 'migrate-events-to-event-espresso');
    }


    /**
     * "ts|token" or "ts|token|job_id".
     */
    private function lockValue(string $ts, string $token, string $job_id = ''): string
    {
        $value = $ts . self::LOCK_VALUE_SEPARATOR . $token;
        if ($job_id !== '') {
            $value .= self::LOCK_VALUE_SEPARATOR . $job_id;
        }
        return $value;
    }


    /**
     * The raw queries bypass the option API, so its caches must be cleared or
     * a later get_option() could return stale data.
     */
    private function clearOptionCaches(): void
    {
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
}
// End of file MigrationLock.php
// Location: EventEspresso\EventMigrator\domain\services\migration/MigrationLock.php
