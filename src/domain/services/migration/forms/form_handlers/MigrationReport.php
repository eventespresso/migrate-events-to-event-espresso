<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_Proper;
use EE_Registry;
use EEH_Template;
use EventEspresso\core\libraries\form_sections\form_handlers\FormHandler;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm;
use EventEspresso\core\services\request\DataType;
use EventEspresso\core\services\request\RequestInterface;
use EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationBatchJob;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use EventEspresso\EventMigrator\domain\services\migration\ui\MigrationUrls;
use EventEspresso\EventMigrator\domain\services\migration\ui\ReportView;

/**
 * Class MigrationReport
 *
 * Wizard step 4: the report of one run (REQ-022). The run id comes from the
 * `run_id` query argument (checked against the 16-hex pattern); without one
 * the run that finished last in this browser session's hour is shown.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationReport extends MigrationStep
{
    private MigrationRunStore $run_store;

    private RequestInterface $request;


    public function __construct(
        EE_Registry $registry,
        MigrationConfigStore $store,
        MigrationRunStore $run_store,
        RequestInterface $request
    ) {
        $this->run_store = $run_store;
        $this->request   = $request;
        $this->setDisplayable(true);
        parent::__construct(
            4,
            esc_html__('Report', 'migrate-events-to-event-espresso'),
            esc_html__('"Report" Event Migrator Step', 'migrate-events-to-event-espresso'),
            'report',
            '',
            FormHandler::ADD_FORM_TAGS_AND_SUBMIT,
            $registry,
            $store
        );
        $this->setSubmitBtnText(esc_html__('Start another migration', 'migrate-events-to-event-espresso'));
        $this->setRedirectTo(SequentialStepForm::REDIRECT_TO_OTHER);
        $this->setRedirectUrl(MigrationUrls::wizard(EE_EVENT_MIGRATOR_ADMIN_URL));
    }


    /**
     * {@inheritDoc}
     *
     * @throws EE_Error
     */
    public function generate(): EE_Form_Section_Proper
    {
        $record = $this->record();
        if (! $record instanceof MigrationRunRecord) {
            $html = '<h2>' . esc_html__('No report to show', 'migrate-events-to-event-espresso') . '</h2><p>'
                . esc_html__('That run could not be found. Open the list of previous runs to find it.', 'migrate-events-to-event-espresso')
                . '</p>';
        } else {
            $html = EEH_Template::display_template(
                dirname(__DIR__) . '/templates/migration_report.template.php',
                ['report' => ReportView::build($record, EE_EVENT_MIGRATOR_ADMIN_URL)],
                true
            );
        }
        $form = new EE_Form_Section_Proper(
            [
                'name'        => $this->slug(),
                'subsections' => ['report' => new EE_Form_Section_HTML($html)],
            ]
        );
        $this->setForm($form);
        return $form;
    }


    /**
     * {@inheritDoc}
     */
    public function process($form_data = []): bool
    {
        $this->setRedirectTo(SequentialStepForm::REDIRECT_TO_OTHER);
        return true;
    }


    private function record(): ?MigrationRunRecord
    {
        $run_id = $this->request->getRequestParam('run_id', '', DataType::STRING);
        if ($run_id === '') {
            $run_id = (string) get_transient(MigrationBatchJob::LAST_RUN_TRANSIENT);
        }
        return MigrationRunStore::isValidRunId($run_id) ? $this->run_store->load($run_id) : null;
    }
}
// End of file MigrationReport.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/MigrationReport.php
