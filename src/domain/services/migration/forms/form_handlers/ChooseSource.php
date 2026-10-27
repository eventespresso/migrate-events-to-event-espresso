<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_Proper;
use EE_Registry;
use EEH_URL;
use EventEspresso\core\exceptions\InvalidFormSubmissionException;
use EventEspresso\core\libraries\form_sections\form_handlers\FormHandler;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm;
use EventEspresso\core\services\request\DataType;
use EventEspresso\core\services\request\RequestInterface;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use EventEspresso\EventMigrator\domain\services\migration\forms\forms\ChooseSourceForm;
use EventEspresso\EventMigrator\domain\services\migration\MigrationLock;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;
use EventEspresso\EventMigrator\domain\services\migration\ui\CsvImporterCard;
use EventEspresso\EventMigrator\domain\services\migration\ui\SourceCards;

/**
 * Class ChooseSource
 *
 * Wizard step 1: pick the installed, active source plugin to migrate from
 * (REQ-001). Shows who holds the migration lock instead of the cards when a
 * run is already going (REQ-002), with a link to clear it once it is stale.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class ChooseSource extends MigrationStep
{
    private SourceAdapterRegistry $adapters;

    private MigrationLock $lock;

    private RequestInterface $request;


    public function __construct(
        EE_Registry $registry,
        MigrationConfigStore $store,
        SourceAdapterRegistry $adapters,
        MigrationLock $lock,
        RequestInterface $request
    ) {
        $this->adapters = $adapters;
        $this->lock     = $lock;
        $this->request  = $request;
        $this->setDisplayable(true);
        $this->has_help_tab = true;
        parent::__construct(
            1,
            esc_html__('Source', 'migrate-events-to-event-espresso'),
            esc_html__('"Source" Event Migrator Step', 'migrate-events-to-event-espresso'),
            'source',
            '',
            FormHandler::ADD_FORM_TAGS_AND_SUBMIT,
            $registry,
            $store
        );
        $this->setSubmitBtnText(esc_html__('Next: Options', 'migrate-events-to-event-espresso'));
    }


    /**
     * {@inheritDoc}
     *
     * @throws EE_Error
     */
    public function generate(): EE_Form_Section_Proper
    {
        $this->store->load();
        if ($this->lock->holder() !== null) {
            return $this->unavailableForm('source_locked', $this->lockMessage());
        }
        $detected = $this->adapters->detected();
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $csv   = CsvImporterCard::build(
            isset(get_plugins()[ CsvImporterCard::PLUGIN_FILE ]),
            class_exists('EE_Event_Importer', false),
            current_user_can('ee_import_events'),
            admin_url('admin.php?page=espresso_event_importer')
        );
        $cards = SourceCards::build($detected);
        $form  = new ChooseSourceForm(
            [
                'cards'         => $cards,
                'csv'           => $csv,
                'selected'      => $this->config()->getSourceSlug(),
                'help_tab_link' => $this->getHelpTabLink(),
            ]
        );
        if ($cards === []) {
            $form->add_subsections(
                [
                    'none_found' => new EE_Form_Section_HTML(
                        '<p>' . esc_html__('No supported event plugin was found on this site.', 'migrate-events-to-event-espresso') . '</p>'
                    ),
                ],
                'cards'
            );
        }
        return $form;
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
        $this->store->load();
        if ($this->lock->holder() !== null) {
            $this->addError($this->lockMessage());
            return false;
        }
        try {
            parent::process($form_data);
        } catch (InvalidFormSubmissionException $exception) {
            return false;
        }
        $slug     = $this->request->getRequestParam(ChooseSourceForm::SOURCE_PARAM, '', DataType::STRING);
        $detected = $this->adapters->detected();
        if (! SourceCards::isSelectable($slug, $detected)) {
            $this->addError(esc_html__('Choose one of the available sources to continue.', 'migrate-events-to-event-espresso'));
            return false;
        }
        $expected_raw = $this->store->load();
        $config       = $this->config();
        // A new source starts a new session: run state and options are reset and the revision rotates.
        $config->clearRun();
        $config->setSourceSlug($slug);
        $config->setOptions(['source' => $slug]);
        if (! $this->store->saveIfUnchanged($expected_raw)) {
            $this->addError($this->staleSettingsMessage());
            return false;
        }
        $this->setRedirectTo(SequentialStepForm::REDIRECT_TO_NEXT_STEP);
        return true;
    }


    /**
     * The "who holds the lock" notice, with a clear-lock link once the lock is stale (never for a CSV import).
     */
    private function lockMessage(): string
    {
        $holder  = $this->lock->holder();
        $message = $this->lock->describeHolder() . ' '
            . esc_html__('Wait for it to finish before starting a new migration.', 'migrate-events-to-event-espresso');
        if ($holder !== null && $holder['stale'] && $holder['kind'] !== MigrationLock::HOLDER_CSV) {
            $clear_url = EEH_URL::add_query_args_and_nonce(
                ['action' => 'clear_migration_lock'],
                EE_EVENT_MIGRATOR_ADMIN_URL
            );
            $message = $this->lock->describeHolder() . ' ' . sprintf(
                /* translators: %1$s: opening anchor tag linking to the clear-lock action, %2$s: closing anchor tag */
                esc_html__(
                    'It has been running for over 2 hours and may be stale. If you are certain nothing is running, you can %1$sclear the lock%2$s.',
                    'migrate-events-to-event-espresso'
                ),
                '<a href="' . esc_url($clear_url) . '">',
                '</a>'
            );
        }
        return $message;
    }
}
// End of file ChooseSource.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/ChooseSource.php
