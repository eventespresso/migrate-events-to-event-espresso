<?php

namespace EventEspresso\EventMigrator\domain\services\migration\report;

use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use InvalidArgumentException;

/**
 * Class CrosswalkExporter
 *
 * Writes a run's crosswalk as CSV (REQ-022): one row per record the run
 * created, `source,source_type,source_id,ee_model,ee_id,fingerprint`, read
 * from the extra meta in pages. Ids and hashes only, no personal data
 * (REQ-107); cells that a spreadsheet would read as a formula are prefixed
 * with an apostrophe (REQ-109).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class CrosswalkExporter
{
    public const COLUMNS = ['source', 'source_type', 'source_id', 'ee_model', 'ee_id', 'fingerprint'];

    public const PAGE_SIZE = 500;

    private Crosswalk $crosswalk;


    public function __construct(Crosswalk $crosswalk)
    {
        $this->crosswalk = $crosswalk;
    }


    /**
     * The download filename for a run.
     *
     * @throws InvalidArgumentException When the run id is malformed.
     */
    public static function filename(string $run_id): string
    {
        if (! MigrationRunStore::isValidRunId($run_id)) {
            throw new InvalidArgumentException(esc_html__('Invalid migration run id.', 'migrate-events-to-event-espresso'));
        }
        return 'migration-crosswalk-' . $run_id . '.csv';
    }


    /**
     * Sends the download headers and writes the CSV to the response body.
     *
     * @throws InvalidArgumentException When the run id is malformed.
     */
    public function stream(string $run_id): int
    {
        $filename = self::filename($run_id);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        // phpcs:disable WordPress.WP.AlternativeFunctions -- php://output is the HTTP response body, not a file on disk.
        $output = fopen('php://output', 'wb');
        $rows   = $this->write($output, $run_id);
        fclose($output);
        // phpcs:enable WordPress.WP.AlternativeFunctions
        return $rows;
    }


    /**
     * Writes the header and every row to an open stream. Returns the number of data rows.
     *
     * @param resource $stream
     * @throws InvalidArgumentException When the run id is malformed or the stream is not a resource.
     */
    public function write($stream, string $run_id): int
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException(esc_html__('The crosswalk export stream is not open.', 'migrate-events-to-event-espresso'));
        }
        self::filename($run_id);
        fputcsv($stream, self::COLUMNS, ',', '"', '\\');
        $rows = 0;
        foreach (CrosswalkKeys::models() as $model) {
            $offset = 0;
            do {
                $entries = $this->crosswalk->entriesForRun($run_id, $model, $offset, self::PAGE_SIZE);
                foreach ($entries as $entry) {
                    fputcsv($stream, $this->row($model, $entry), ',', '"', '\\');
                    $rows++;
                }
                $offset += self::PAGE_SIZE;
            } while (count($entries) === self::PAGE_SIZE);
        }
        return $rows;
    }


    /**
     * @param string                                      $model The EE model name.
     * @param array{id: int, src_key: string, fp: string} $entry A crosswalk entry of the run.
     * @return string[]
     */
    private function row(string $model, array $entry): array
    {
        $parts = explode(':', (string) $entry['src_key'], 3);
        return array_map(
            [self::class, 'guardCell'],
            [
                $parts[0] ?? '',
                $parts[1] ?? '',
                $parts[2] ?? '',
                $model,
                (string) $entry['id'],
                (string) $entry['fp'],
            ]
        );
    }


    /**
     * Prefixes a cell a spreadsheet would evaluate as a formula.
     */
    public static function guardCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
// End of file CrosswalkExporter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\report/CrosswalkExporter.php
