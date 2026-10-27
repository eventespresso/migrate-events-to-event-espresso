<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class MigrationContext
 *
 * Per-run state handed to resolvers, mappers, persisters and the crosswalk:
 * who is running what, the warning and reuse sinks, and the in-memory
 * source-key => EE id map that lets the crosswalk answer "already migrated?"
 * without a database lookup per record.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MigrationContext
{
    private string $run_id;

    private string $source;

    private MigrationOptions $options;

    private string $site_timezone;

    private int $user_id;

    private bool $dry_run;

    private string $site_currency;

    /**
     * @var MigrationWarning[]
     */
    private array $warnings = [];

    /**
     * @var array<int, array{model: string, id: int, src_key: string}>
     */
    private array $reuse_notes = [];

    /**
     * model name => src_key => EE object id, or null when the key was looked up and found absent
     *
     * @var array<string, array<string, int|null>>
     */
    private array $lookup = [];

    /**
     * @var string[] source keys whose crosswalk entry exists but whose EE record is gone
     */
    private array $target_deleted = [];


    /**
     * @param string           $run_id        The 16-hex run id.
     * @param string           $source        The source adapter slug.
     * @param MigrationOptions $options       The validated run options.
     * @param string           $site_timezone The site timezone identifier, e.g. "America/Denver".
     * @param int              $user_id       The WordPress user running the migration.
     * @param bool             $dry_run       True when no writes may happen.
     * @param string           $site_currency The site's ISO currency code, e.g. "USD"; '' when unknown.
     */
    public function __construct(
        string $run_id,
        string $source,
        MigrationOptions $options,
        string $site_timezone,
        int $user_id,
        bool $dry_run,
        string $site_currency = ''
    ) {
        $this->run_id        = $run_id;
        $this->source        = $source;
        $this->options       = $options;
        $this->site_timezone = $site_timezone;
        $this->user_id       = $user_id;
        $this->dry_run       = $dry_run;
        $this->site_currency = strtoupper(trim($site_currency));
    }


    public function siteCurrency(): string
    {
        return $this->site_currency;
    }


    public function runId(): string
    {
        return $this->run_id;
    }


    public function source(): string
    {
        return $this->source;
    }


    public function options(): MigrationOptions
    {
        return $this->options;
    }


    public function siteTimezone(): string
    {
        return $this->site_timezone;
    }


    public function userId(): int
    {
        return $this->user_id;
    }


    public function isDryRun(): bool
    {
        return $this->dry_run;
    }


    public function addWarning(MigrationWarning $warning): void
    {
        $this->warnings[] = $warning;
    }


    /**
     * @return MigrationWarning[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }


    /**
     * Hands the accumulated warnings to the caller and empties the sink, so a
     * batch job can flush them to the run record after every record.
     *
     * @return MigrationWarning[]
     */
    public function drainWarnings(): array
    {
        $warnings       = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }


    /**
     * Records that a pre-existing EE record was reused by de-duplication. Such
     * records never receive the run's crosswalk meta, so rollback cannot
     * delete them; the note is what the run report shows instead.
     */
    public function noteReuse(string $model, int $id, string $src_key): void
    {
        $this->reuse_notes[] = ['model' => $model, 'id' => $id, 'src_key' => $src_key];
    }


    /**
     * @return array<int, array{model: string, id: int, src_key: string}>
     */
    public function reuseNotes(): array
    {
        return $this->reuse_notes;
    }


    /**
     * @return array<int, array{model: string, id: int, src_key: string}>
     */
    public function drainReuseNotes(): array
    {
        $notes             = $this->reuse_notes;
        $this->reuse_notes = [];
        return $notes;
    }


    /**
     * Caches the EE id a source key resolved to for one model.
     */
    public function remember(string $src_key, string $model, int $id): void
    {
        $this->lookup[ $model ][ $src_key ] = $id;
    }


    /**
     * Caches that a source key has no crosswalk entry for one model, so a
     * later lookup() miss is answered from memory rather than the database.
     */
    public function rememberAbsent(string $src_key, string $model): void
    {
        if (! isset($this->lookup[ $model ][ $src_key ])) {
            $this->lookup[ $model ][ $src_key ] = null;
        }
    }


    /**
     * Whether the key was prefetched or remembered for this model (present or absent).
     */
    public function hasLookup(string $src_key, string $model): bool
    {
        return isset($this->lookup[ $model ]) && array_key_exists($src_key, $this->lookup[ $model ]);
    }


    /**
     * The cached EE id for a source key, or null when unknown or known absent.
     */
    public function lookup(string $src_key, string $model): ?int
    {
        return $this->lookup[ $model ][ $src_key ] ?? null;
    }


    /**
     * Drops every remembered key that points at one of the given ids of a model; used after compensation
     * removed those records, so later lookups in the run do not resolve to deleted rows.
     *
     * @param string $model The EE model name.
     * @param int[]  $ids   The removed record ids.
     */
    public function forgetIds(string $model, array $ids): void
    {
        foreach ($this->lookup[ $model ] ?? [] as $src_key => $id) {
            if ($id !== null && in_array($id, $ids, true)) {
                unset($this->lookup[ $model ][ $src_key ]);
            }
        }
    }


    /**
     * The remembered keys of the given models, for carrying a dry run's lookups to the next batch request.
     *
     * @param string[] $models EE model names.
     * @return array<string, array<string, int>> model => source key => id (known-absent entries omitted)
     */
    public function exportLookup(array $models): array
    {
        $exported = [];
        foreach ($models as $model) {
            foreach ($this->lookup[ $model ] ?? [] as $src_key => $id) {
                if ($id !== null) {
                    $exported[ $model ][ (string) $src_key ] = $id;
                }
            }
        }
        return $exported;
    }


    /**
     * Restores keys exported by exportLookup(); malformed entries are ignored.
     */
    public function importLookup(array $lookup): void
    {
        foreach ($lookup as $model => $keys) {
            foreach ((array) $keys as $src_key => $id) {
                if (is_numeric($id)) {
                    $this->remember((string) $src_key, (string) $model, (int) $id);
                }
            }
        }
    }


    public function noteTargetDeleted(string $src_key): void
    {
        if (! in_array($src_key, $this->target_deleted, true)) {
            $this->target_deleted[] = $src_key;
        }
    }


    public function isTargetDeleted(string $src_key): bool
    {
        return in_array($src_key, $this->target_deleted, true);
    }


    /**
     * @return string[]
     */
    public function targetDeleted(): array
    {
        return $this->target_deleted;
    }
}
// End of file MigrationContext.php
// Location: EventEspresso\EventMigrator\application\services\migration/MigrationContext.php
