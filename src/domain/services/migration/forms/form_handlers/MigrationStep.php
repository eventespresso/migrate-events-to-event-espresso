<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use DomainException;
use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_Proper;
use EE_Hidden_Input;
use EE_Registry;
use EEH_Template;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use InvalidArgumentException;

/**
 * Class MigrationStep
 *
 * Base class for the migration wizard's steps. The wizard's state lives in one
 * MigrationConfig; a step reads it with the config store's compare-and-set
 * helpers and carries the config's staging revision in a hidden input, so a
 * page that was open while another tab changed the settings is refused.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
abstract class MigrationStep extends SequentialStepForm
{
    protected MigrationConfigStore $store;

    protected bool $has_help_tab = false;


    /**
     * @param int                  $order       The step's position in the wizard.
     * @param string               $name        The step's name in the progress bar.
     * @param string               $longer_name The step's long name.
     * @param string               $slug        The step's URL slug.
     * @param string               $form_action The form action; empty to use the wizard's.
     * @param string               $form_config A FormHandler form-tag configuration constant.
     * @param EE_Registry          $registry    The EE registry.
     * @param MigrationConfigStore $store       The wizard state store.
     * @throws DomainException
     * @throws InvalidArgumentException
     * @throws InvalidDataTypeException
     */
    public function __construct(
        int $order,
        string $name,
        string $longer_name,
        string $slug,
        string $form_action,
        string $form_config,
        EE_Registry $registry,
        MigrationConfigStore $store
    ) {
        $this->store = $store;
        parent::__construct($order, $name, $longer_name, $slug, $form_action, $form_config, $registry);
    }


    public function hasHelpTab(): bool
    {
        return $this->has_help_tab;
    }


    public function getHelpTabLink(): string
    {
        return EEH_Template::get_help_tab_link(
            'event_migrator_migration_' . $this->slug(),
            EVENT_MIGRATOR_PG_SLUG,
            'show_migration_step'
        );
    }


    protected function config(): MigrationConfig
    {
        return $this->store->config();
    }


    /**
     * The hidden input that carries the staging revision the page was built from.
     */
    protected function revisionInput(): EE_Hidden_Input
    {
        return new EE_Hidden_Input(['default' => $this->config()->getStagingRevision()]);
    }


    /**
     * Whether a submitted revision is the config's current, non-empty revision.
     */
    protected function revisionIsCurrent(array $valid_data): bool
    {
        $submitted = (string) ($valid_data['staging_revision'] ?? '');
        return $submitted !== '' && $submitted === $this->config()->getStagingRevision();
    }


    /**
     * Whether the current user may use the migrator; adds the error notice when not.
     */
    protected function userMayMigrate(): bool
    {
        if (current_user_can('ee_migrate_events')) {
            return true;
        }
        $this->addError(esc_html__('You do not have permission to migrate events.', 'migrate-events-to-event-espresso'));
        return false;
    }


    /**
     * Adds an error notice. The message must already be escaped.
     */
    protected function addError(string $escaped_message): void
    {
        EE_Error::add_error($escaped_message, __FILE__, __FUNCTION__, __LINE__);
    }


    /**
     * The step's form when it cannot be shown, carrying the reason as an inline notice. generate() runs while the
     * page body is rendered, after the admin notices were printed, so a notice added there would never be seen.
     *
     * @param string $name            The form section name.
     * @param string $escaped_message The reason; must already be escaped (it may contain a link).
     * @throws EE_Error
     */
    protected function unavailableForm(string $name, string $escaped_message): EE_Form_Section_Proper
    {
        return new EE_Form_Section_Proper(
            [
                'name'        => $name,
                'subsections' => [
                    'notice' => new EE_Form_Section_HTML(
                        '<div class="notice notice-warning inline"><p>' . $escaped_message . '</p></div>'
                    ),
                ],
            ]
        );
    }


    protected function staleSettingsMessage(): string
    {
        return esc_html__(
            'The migration settings have changed since this page was loaded. Please start again from the first step.',
            'migrate-events-to-event-espresso'
        );
    }
}
// End of file MigrationStep.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/MigrationStep.php
