<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use EE_Error;
use EE_Form_Section_Proper;
use EE_Hidden_Input;
use EE_Registry;
use EventEspresso\core\exceptions\InvalidFormSubmissionException;
use EventEspresso\core\libraries\form_sections\form_handlers\FormHandler;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm;
use EventEspresso\core\services\request\DataType;
use EventEspresso\core\services\request\RequestInterface;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\domain\exceptions\MigrationLaunchException;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use EventEspresso\EventMigrator\domain\services\migration\forms\forms\MigrationOptionsForm;
use EventEspresso\EventMigrator\domain\services\migration\sources\HorizonExclusion;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;
use EventEspresso\EventMigrator\domain\services\migration\ui\InventoryRows;
use EventEspresso\EventMigrator\domain\services\migration\ui\OptionsSubmission;
use Throwable;

/**
 * Class MigrationOptionsStep
 *
 * Wizard step 2: the pre-flight inventory and the run options. The counts
 * follow the chosen options, so "Update the counts" saves the choices and
 * shows the table again; "Next" saves them and moves on. Either way the
 * staging revision rotates so a confirm page built from older choices is
 * refused.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationOptionsStep extends MigrationStep
{
    private SourceAdapterRegistry $adapters;

    private RequestInterface $request;


    public function __construct(
        EE_Registry $registry,
        MigrationConfigStore $store,
        SourceAdapterRegistry $adapters,
        RequestInterface $request
    ) {
        $this->adapters = $adapters;
        $this->request  = $request;
        $this->setDisplayable(true);
        $this->has_help_tab = true;
        parent::__construct(
            2,
            esc_html__('Options', 'migrate-events-to-event-espresso'),
            esc_html__('"Options" Event Migrator Step', 'migrate-events-to-event-espresso'),
            'options',
            '',
            FormHandler::ADD_FORM_TAGS_AND_SUBMIT,
            $registry,
            $store
        );
        $this->setSubmitBtnText(esc_html__('Next: Confirm', 'migrate-events-to-event-espresso'));
    }


    /**
     * {@inheritDoc}
     *
     * @throws EE_Error
     */
    public function generate(): EE_Form_Section_Proper
    {
        $this->store->load();
        $config = $this->config();
        if ($config->getSourceSlug() === '' || ! $this->adapters->has($config->getSourceSlug())) {
            return $this->emptyForm(esc_html__('Choose a source first.', 'migrate-events-to-event-espresso'));
        }
        try {
            $adapter   = $this->adapters->get($config->getSourceSlug());
            $options   = MigrationOptions::fromArray($config->getOptions() ?: ['source' => $adapter->slug()]);
            // detect() is computed once per adapter; inventory() reuses it for the version.
            $detection = $adapter->detect();
            $inventory = $adapter->inventory($options);
            $excluded  = HorizonExclusion::count($adapter, $options, $inventory);
        } catch (Throwable $throwable) {
            return $this->emptyForm(
                sprintf(
                    /* translators: %s: the reason the source could not be read */
                    esc_html__('The source could not be read: %s', 'migrate-events-to-event-espresso'),
                    esc_html($throwable->getMessage())
                )
            );
        }
        return new MigrationOptionsForm(
            [
                'inventory'     => [
                    'source_name'  => $adapter->name(),
                    'version'      => $inventory->version(),
                    'rows'         => InventoryRows::build($inventory),
                    'is_empty'     => $inventory->isEmpty(),
                    'not_migrated' => $inventory->notMigrated(),
                    'timezone'     => InventoryRows::timezoneLabel($inventory->timezoneModel()),
                    'unverified'   => ! $detection->isSchemaVerified(),
                    'excluded'     => $excluded,
                    'events_only'  => ! $options->includesBookings()
                        && $adapter->capabilities()->provides(SourceCapabilities::DOMAIN_BOOKINGS),
                ],
                'capabilities'  => $adapter->capabilities(),
                'options'       => $options,
                'can_publish'   => current_user_can('ee_publish_events'),
                'revision'      => $config->getStagingRevision(),
                'help_tab_link' => $this->getHelpTabLink(),
            ]
        );
    }


    /**
     * {@inheritDoc}
     *
     * @throws EE_Error
     */
    public function process($form_data = []): bool
    {
        if (! $this->userMayMigrate()) {
            return false;
        }
        $config = $this->config();
        try {
            $valid_data = (array) parent::process($form_data);
        } catch (InvalidFormSubmissionException $exception) {
            return false;
        }
        $expected_raw = $this->store->load();
        if (! $this->revisionIsCurrent($valid_data) || ! $this->adapters->has($config->getSourceSlug())) {
            $this->addError($this->staleSettingsMessage());
            return false;
        }
        $adapter = $this->adapters->get($config->getSourceSlug());
        try {
            $options = OptionsSubmission::toOptions(
                $adapter->slug(),
                $valid_data,
                $adapter->capabilities(),
                current_user_can('ee_publish_events')
            );
        } catch (MigrationLaunchException $exception) {
            $this->addError($exception->getMessage());
            return false;
        }
        $config->setOptions($options->toArray());
        $config->setStagingRevision(MigrationConfig::generateStagingRevision());
        if (! $this->store->saveIfUnchanged($expected_raw)) {
            $this->addError($this->staleSettingsMessage());
            return false;
        }
        $refresh = $this->request->getRequestParam(MigrationOptionsForm::REFRESH_PARAM, '', DataType::STRING) !== '';
        $this->setRedirectTo(
            $refresh ? SequentialStepForm::REDIRECT_TO_CURRENT_STEP : SequentialStepForm::REDIRECT_TO_NEXT_STEP
        );
        return true;
    }


    /**
     * The options form when the options cannot be shown: the reason, and the staging revision field the
     * submission handler reads.
     *
     * @param string $escaped_message The reason; must already be escaped.
     * @throws EE_Error
     */
    private function emptyForm(string $escaped_message): EE_Form_Section_Proper
    {
        $form = $this->unavailableForm('migration_options_unavailable', $escaped_message);
        $form->add_subsections(['staging_revision' => new EE_Hidden_Input(['default' => ''])]);
        return $form;
    }
}
// End of file MigrationOptionsStep.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/MigrationOptionsStep.php
