<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use EEH_File;

/**
 * Class MigrationLog
 *
 * The per-run diagnostics log: an append-only ".log" file under the EE
 * uploads directory, named by a server-side secret, in a folder with a
 * deny-all .htaccess and an empty index.html (as WooCommerce keeps its logs).
 * Every line is "[timestamp] [rule] [type:id]" - ids and rule ids only,
 * never names or emails (REQ-109).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationLog
{
    /**
     * Folder under EVENT_ESPRESSO_UPLOAD_DIR, owned by this add-on alone.
     */
    public const LOG_SUBDIR = 'event-migrator/logs';

    /**
     * A filename secret is 16 lower-case hex characters, like a run id.
     */
    public const SECRET_PATTERN = '/^[a-f0-9]{16}$/D';


    public static function logDir(): string
    {
        return wp_normalize_path(EVENT_ESPRESSO_UPLOAD_DIR . self::LOG_SUBDIR);
    }


    /**
     * Unguessability, not cryptographic strength, is all the secret needs.
     */
    public static function generateSecret(): string
    {
        return substr(md5(uniqid((string) wp_rand(), true)), 0, 16);
    }


    public static function isValidSecret(string $log_secret): bool
    {
        return preg_match(self::SECRET_PATTERN, $log_secret) === 1;
    }


    /**
     * The absolute log path for a secret, or '' when the secret is malformed.
     */
    public static function filePath(string $log_secret): string
    {
        if (! self::isValidSecret($log_secret)) {
            return '';
        }
        return self::logDir() . '/' . sanitize_file_name('migration-' . $log_secret . '.log');
    }


    public function exists(string $log_secret): bool
    {
        $path = self::filePath($log_secret);
        return $path !== '' && is_file($path);
    }


    /**
     * Best-effort append of one entry. Log failures never fail a migration;
     * the run record still carries totals and bounded samples.
     *
     * @param string $log_secret The run's filename secret.
     * @param string $rule       The WarningRule id (or a status word such as "started").
     * @param string $type       The source entity type, when the entry concerns a record.
     * @param string $id         The source entity id, when the entry concerns a record.
     */
    public function append(string $log_secret, string $rule, string $type = '', string $id = ''): bool
    {
        $log_file = self::filePath($log_secret);
        if ($log_file === '') {
            return false;
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . '] [' . $this->plain($rule) . ']';
        if ($type !== '' || $id !== '') {
            $line .= ' [' . $this->plain($type) . ':' . $this->plain($id) . ']';
        }
        $line .= "\n";
        if (! is_file($log_file)) {
            $log_dir = self::logDir();
            if (! EEH_File::ensure_folder_exists_and_is_writable($log_dir)) {
                return false;
            }
            EEH_File::add_htaccess_deny_from_all($log_dir);
            self::addIndexFile($log_dir);
        }
        // phpcs:disable WordPress.WP.AlternativeFunctions -- an append-only log in the uploads folder: WP_Filesystem has no append, and rewriting the whole file for every line would be quadratic over a run.
        $handle = @fopen($log_file, 'ab');
        if ($handle === false) {
            return false;
        }
        $written = @fwrite($handle, $line);
        @fclose($handle);
        // phpcs:enable WordPress.WP.AlternativeFunctions
        return $written !== false;
    }


    /**
     * A run's log, read through WP_Filesystem; null when there is no such log.
     */
    public static function contents(string $log_secret): ?string
    {
        $log_file = self::filePath($log_secret);
        if ($log_file === '' || ! is_file($log_file)) {
            return null;
        }
        return EEH_File::get_file_contents($log_file);
    }


    /**
     * An empty index.html so a server that ignores the .htaccess still shows no directory listing.
     */
    private static function addIndexFile(string $log_dir): void
    {
        $index = EEH_File::standardise_and_end_with_directory_separator($log_dir) . 'index.html';
        if (! EEH_File::exists($index)) {
            EEH_File::write_to_file($index, '', '.html');
        }
    }


    /**
     * Removes a run's log file. True when the file is gone afterwards.
     */
    public function delete(string $log_secret): bool
    {
        $log_file = self::filePath($log_secret);
        if ($log_file === '' || ! is_file($log_file)) {
            return true;
        }
        wp_delete_file($log_file);
        return ! is_file($log_file);
    }


    /**
     * Strips line breaks and brackets so a value can never forge an entry boundary.
     */
    private function plain(string $value): string
    {
        return str_replace(["\r", "\n", '[', ']'], ' ', $value);
    }
}
// End of file MigrationLog.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationLog.php
