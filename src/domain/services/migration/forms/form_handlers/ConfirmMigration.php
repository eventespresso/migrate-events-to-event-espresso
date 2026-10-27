<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use EE_Checkbox_Multi_Input;
use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_HTML_From_Template;
use EE_Form_Section_Proper;
use EE_Registry;
use EE_Submit_Input;
use EEH_HTML;
use EventEspresso\core\exceptions\ExceptionLogger;
use EventEspresso\core\exceptions\InvalidFormSubmissionException;
use EventEspresso\core\libraries\form_sections\form_handlers\FormHandler;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm;
use EventEspresso\core\services\request\DataType;
use EventEspresso\core\services\request\RequestInterface;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationLaunchException;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;
use EventEspresso\EventMigrator\domain\services\migration\ui\MigrationLauncher;
use EventEspresso\EventMigrator\domain\services\migration\ui\OptionsSummary;
use Exception;
use Throwable;

/**
 * Class ConfirmMigration
 *
 * Wizard step 3: summary, then "Dry run" or "Begin migration". Beginning needs
 * the backup box ticked. Either button takes the migration lock through
 * MigrationLauncher (REQ-002, REQ-007) and sends the browser to the EE batch
 * runner, which returns to the report step.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class ConfirmMigration extends MigrationStep
{
    public const DRY_RUN_PARAM = 'ee-migration-dry-run';

    public const BEGIN_PARAM = 'ee-migration-begin';

    private SourceAdapterRegistry $adapters;

    private MigrationLauncher $launcher;

    private RequestInterface $request;


    public function __construct(
        EE_Registry $registry,
        MigrationConfigStore $store,
        SourceAdapterRegistry $adapters,
        MigrationLauncher $launcher,
        RequestInterface $request
    ) {
        $this->adapters = $adapters;
        $this->launcher = $launcher;
        $this->request  = $request;
        $this->setDisplayable(true);
        $this->has_help_tab = true;
        parent::__construct(
            3,
            esc_html__('Confirm', 'migrate-events-to-event-espresso'),
            esc_html__('"Confirm" Event Migrator Step', 'migrate-events-to-event-espresso'),
            'confirm',
            '',
            FormHandler::ADD_FORM_TAGS_ONLY,
            $registry,
            $store
        );
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
        $slug   = $config->getSourceSlug();
        try {
            $options = MigrationOptions::fromArray($config->getOptions());
        } catch (Exception $exception) {
            $options = null;
        }
        if ($options === null || ! $this->adapters->has($slug)) {
            return $this->unavailableForm(
                'confirm_unavailable',
                esc_html__('Choose a source and its options first.', 'migrate-events-to-event-espresso')
            );
        }
        return new EE_Form_Section_Proper(
            [
                'name'        => 'confirm',
                'subsections' => [
                    'header'           => new EE_Form_Section_HTML(
                        EEH_HTML::h2(esc_html__('Confirm', 'migrate-events-to-event-espresso') . $this->getHelpTabLink())
                    ),
                    'summary'          => new EE_Form_Section_HTML_From_Template(
                        dirname(__DIR__) . '/templates/migration_confirm_summary.template.php',
                        ['lines' => OptionsSummary::lines($options, $this->adapters->get($slug)->name())]
                    ),
                    'backup'           => new EE_Checkbox_Multi_Input(
                        ['yes' => esc_html__('I have a database backup.', 'migrate-events-to-event-espresso')],
                        ['html_label_text' => esc_html__('Backup', 'migrate-events-to-event-espresso')]
                    ),
                    'staging_revision' => $this->revisionInput(),
                    'dry_run_btn'      => $this->button(self::DRY_RUN_PARAM, esc_html__('Dry run', 'migrate-events-to-event-espresso'), 'button'),
                    'begin_btn'        => $this->button(
                        self::BEGIN_PARAM,
                        esc_html__('Begin migration', 'migrate-events-to-event-espresso'),
                        'button button-primary'
                    ),
                ],
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
        try {
            $valid_data = (array) parent::process($form_data);
        } catch (InvalidFormSubmissionException $exception) {
            return false;
        }
        $begin = $this->request->getRequestParam(self::BEGIN_PARAM, '', DataType::STRING) !== '';
        $dry   = $this->request->getRequestParam(self::DRY_RUN_PARAM, '', DataType::STRING) !== '';
        if ($begin === $dry) {
            $this->addError(esc_html__('Choose "Dry run" or "Begin migration".', 'migrate-events-to-event-espresso'));
            return false;
        }
        if ($begin && ! in_array('yes', (array) ($valid_data['backup'] ?? []), true)) {
            $this->addError(
                esc_html__('Confirm that you have a database backup before beginning the migration.', 'migrate-events-to-event-espresso')
            );
            return false;
        }
        $mode = $begin ? MigrationConfig::MODE_MIGRATE : MigrationConfig::MODE_DRY_RUN;
        try {
            $token = $this->launcher->launch($mode, (string) ($valid_data['staging_revision'] ?? ''));
        } catch (MigrationLaunchException $exception) {
            $this->addError($exception->getMessage());
            return false;
        } catch (Throwable $throwable) {
            if ($throwable instanceof Exception) {
                new ExceptionLogger($throwable);
            }
            $this->addError(esc_html__('The migration could not be started. See the error log for details.', 'migrate-events-to-event-espresso'));
            return false;
        }
        $this->setRedirectTo(SequentialStepForm::REDIRECT_TO_OTHER);
        $this->removeRedirectArgs(['ee-form-step']);
        $this->setRedirectUrl(
            MigrationLauncher::batchUrl($mode, $token, MigrationLauncher::reportUrl(EE_EVENT_MIGRATOR_ADMIN_URL, $token))
        );
        return true;
    }


    private function button(string $name, string $label, string $class): EE_Submit_Input
    {
        return new EE_Submit_Input(
            [
                'html_name'  => $name,
                'html_id'    => $name,
                'html_class' => $class,
                'default'    => $label,
                'no_label'   => true,
            ]
        );
    }
}
// End of file ConfirmMigration.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/ConfirmMigration.php
