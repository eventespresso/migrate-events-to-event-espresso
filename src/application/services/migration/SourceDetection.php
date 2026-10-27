<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class SourceDetection
 *
 * The result of asking an adapter whether its source plugin is present.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceDetection
{
    private bool $installed;

    private bool $active;

    private string $version;

    private bool $schema_verified;

    private string $reason;


    /**
     * @param bool   $installed       The plugin files (or its tables) are present.
     * @param bool   $active          The plugin is active.
     * @param string $version         The detected plugin version, or '' when unknown.
     * @param bool   $schema_verified The detected version is one this migration was profiled against.
     * @param string $reason          Untranslated note shown when the source cannot be selected.
     */
    public function __construct(
        bool $installed,
        bool $active,
        string $version,
        bool $schema_verified,
        string $reason = ''
    ) {
        $this->installed       = $installed;
        $this->active          = $active;
        $this->version         = $version;
        $this->schema_verified = $schema_verified;
        $this->reason          = $reason;
    }


    public function isInstalled(): bool
    {
        return $this->installed;
    }


    public function isActive(): bool
    {
        return $this->active;
    }


    public function version(): string
    {
        return $this->version;
    }


    public function isSchemaVerified(): bool
    {
        return $this->schema_verified;
    }


    public function reason(): string
    {
        return $this->reason;
    }


    /**
     * A source can be chosen only when it is installed and active (REQ-001).
     */
    public function isSelectable(): bool
    {
        return $this->installed && $this->active;
    }
}
// End of file SourceDetection.php
// Location: EventEspresso\EventMigrator\application\services\migration/SourceDetection.php
