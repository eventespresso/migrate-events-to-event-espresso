<?php

namespace EventEspresso\EventMigrator\domain\services\migration\config;

use EventEspresso\core\services\options\JsonWpOptionSerializableInterface;

/**
 * Class MigrationConfig
 *
 * Wizard state for the migration path, persisted to its own WP option. The
 * run lock itself is MigrationLock::LOCK_OPTION; the lock fields here mirror
 * it for display.
 *
 * The option name and the MODE_* values are persisted (wizard state, run records): never change them.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationConfig implements JsonWpOptionSerializableInterface
{
    public const OPTION_NAME = 'ee_event_migrator_config';

    public const MODE_DRY_RUN  = 'dry_run';

    public const MODE_MIGRATE  = 'migrate';

    public const MODE_ROLLBACK = 'rollback';

    private string $source_slug = '';

    /**
     * @var array the MigrationOptions::toArray() shape
     */
    private array $options = [];

    private string $mode = '';

    private string $rollback_target_run = '';

    private string $run_id = '';

    private bool $import_in_progress = false;

    private string $lock_acquired_at = '';

    private string $staging_revision = '';


    public function getSourceSlug(): string
    {
        return $this->source_slug;
    }


    public function setSourceSlug(string $source_slug): void
    {
        $this->source_slug = $source_slug;
    }


    public function getOptions(): array
    {
        return $this->options;
    }


    public function setOptions(array $options): void
    {
        $this->options = $options;
    }


    public function getMode(): string
    {
        return $this->mode;
    }


    /**
     * Unknown modes are stored as '' so a forged value can never select a job.
     */
    public function setMode(string $mode): void
    {
        $this->mode = self::isValidMode($mode) ? $mode : '';
    }


    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, [self::MODE_DRY_RUN, self::MODE_MIGRATE, self::MODE_ROLLBACK], true);
    }


    public function getRollbackTargetRun(): string
    {
        return $this->rollback_target_run;
    }


    public function setRollbackTargetRun(string $rollback_target_run): void
    {
        $this->rollback_target_run = $rollback_target_run;
    }


    public function getRunId(): string
    {
        return $this->run_id;
    }


    public function setRunId(string $run_id): void
    {
        $this->run_id = $run_id;
    }


    public function isImportInProgress(): bool
    {
        return $this->import_in_progress;
    }


    public function setImportInProgress(bool $import_in_progress): void
    {
        $this->import_in_progress = $import_in_progress;
    }


    public function getLockAcquiredAt(): string
    {
        return $this->lock_acquired_at;
    }


    public function setLockAcquiredAt(string $lock_acquired_at): void
    {
        $this->lock_acquired_at = $lock_acquired_at;
    }


    public function getStagingRevision(): string
    {
        return $this->staging_revision;
    }


    public function setStagingRevision(string $staging_revision): void
    {
        $this->staging_revision = $staging_revision;
    }


    /**
     * Resets every run-scoped field once a run has finished or been abandoned,
     * rotating the staging revision so a stale wizard page cannot re-launch it.
     */
    public function clearRun(): void
    {
        $this->mode                = '';
        $this->rollback_target_run = '';
        $this->run_id              = '';
        $this->import_in_progress  = false;
        $this->lock_acquired_at    = '';
        $this->staging_revision    = self::generateStagingRevision();
    }


    public function getWpOptionName(): string
    {
        return self::OPTION_NAME;
    }


    /**
     * An opaque revision token for the wizard's staged state.
     */
    public static function generateStagingRevision(): string
    {
        return substr(md5(uniqid((string) wp_rand(), true)), 0, 12);
    }


    public function toJsonSerializableData(): array
    {
        return [
            'source_slug'         => $this->source_slug,
            'options'             => $this->options,
            'mode'                => $this->mode,
            'rollback_target_run' => $this->rollback_target_run,
            'run_id'              => $this->run_id,
            'import_in_progress'  => $this->import_in_progress,
            'lock_acquired_at'    => $this->lock_acquired_at,
            'staging_revision'    => $this->staging_revision,
        ];
    }


    public function fromJsonSerializedData($data): bool
    {
        if (! is_object($data) && ! is_array($data)) {
            return false;
        }
        $data = (array) $data;
        if (isset($data['source_slug'])) {
            $this->source_slug = (string) $data['source_slug'];
        }
        if (isset($data['options'])) {
            $this->options = (array) $data['options'];
        }
        if (isset($data['mode'])) {
            $this->setMode((string) $data['mode']);
        }
        if (isset($data['rollback_target_run'])) {
            $this->rollback_target_run = (string) $data['rollback_target_run'];
        }
        if (isset($data['run_id'])) {
            $this->run_id = (string) $data['run_id'];
        }
        if (isset($data['import_in_progress'])) {
            $this->import_in_progress = (bool) $data['import_in_progress'];
        }
        if (isset($data['lock_acquired_at'])) {
            $this->lock_acquired_at = (string) $data['lock_acquired_at'];
        }
        if (isset($data['staging_revision'])) {
            $this->staging_revision = (string) $data['staging_revision'];
        }
        return true;
    }
}
// End of file MigrationConfig.php
// Location: EventEspresso\EventMigrator\domain\services\migration\config/MigrationConfig.php
