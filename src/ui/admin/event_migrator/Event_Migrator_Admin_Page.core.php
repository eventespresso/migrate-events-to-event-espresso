<?php

use EventEspresso\core\exceptions\ExceptionStackTraceDisplay;
use EventEspresso\core\services\loaders\LoaderFactory;
use EventEspresso\core\services\request\DataType;
use EventEspresso\EventMigrator\domain\exceptions\MigrationLaunchException;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationStepsManager;
use EventEspresso\EventMigrator\domain\services\migration\report\CrosswalkExporter;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;
use EventEspresso\EventMigrator\domain\services\migration\ui\MigrationLauncher;
use EventEspresso\EventMigrator\domain\services\migration\ui\MigrationUrls;
use EventEspresso\EventMigrator\domain\services\migration\ui\RunPresenter;
use EventEspresso\EventMigrator\domain\services\migration\run\ThrowableLogger;

/**
 * Event_Migrator_Admin_Page
 *
 * This contains the logic for setting up the Event Migrator Addon Admin related pages: the migration wizard,
 * the list of previous runs, rollback, and the crosswalk and log downloads. Every route requires the
 * `ee_migrate_events` capability, and every action route is also protected by the route nonce.
 *
 * @package       Event Espresso (event migrator addon)
 * @subpackage    admin/Event_Migrator_Admin_Page.core.php
 * @author        Event Espresso
 * @since         1.0.0.p
 */
class Event_Migrator_Admin_Page extends EE_Admin_Page
{
    protected function _init_page_props(): void
    {
        $this->page_slug        = EVENT_MIGRATOR_PG_SLUG;
        $this->page_label       = __('Event Migrator', 'migrate-events-to-event-espresso');
        $this->_admin_base_url  = EE_EVENT_MIGRATOR_ADMIN_URL;
        $this->_admin_base_path = EE_EVENT_MIGRATOR_ADMIN;
    }


    protected function _ajax_hooks(): void
    {
    }


    protected function _define_page_props(): void
    {
        $this->_admin_page_title = __('Event Migrator', 'migrate-events-to-event-espresso');
        $this->_labels           = [];
    }


    protected function _set_page_routes(): void
    {
        $this->_page_routes = [
            'default'                => [
                'func'               => [$this, 'main'],
                'noheader'           => true,
                'headers_sent_route' => 'show_migration_step',
                'capability'         => 'ee_migrate_events',
            ],
            'migrate'                => [
                'func'               => [$this, 'migrate'],
                'noheader'           => true,
                'headers_sent_route' => 'show_migration_step',
                'capability'         => 'ee_migrate_events',
            ],
            'show_migration_step'    => [
                'func'       => [$this, 'show_migration_step'],
                'capability' => 'ee_migrate_events',
            ],
            'migration_runs'         => [
                'func'       => [$this, 'migration_runs'],
                'capability' => 'ee_migrate_events',
            ],
            'rollback_confirm'       => [
                'func'       => [$this, 'rollback_confirm'],
                'capability' => 'ee_migrate_events',
            ],
            'rollback_run'           => [
                'func'               => [$this, 'rollback_run'],
                'noheader'           => true,
                'headers_sent_route' => 'migration_runs',
                'capability'         => 'ee_migrate_events',
            ],
            'clear_migration_lock'   => [
                'func'       => [$this, 'clear_migration_lock'],
                'noheader'   => true,
                'capability' => 'ee_migrate_events',
            ],
            'download_crosswalk'     => [
                'func'       => [$this, 'download_crosswalk'],
                'noheader'   => true,
                'capability' => 'ee_migrate_events',
            ],
            'download_migration_log' => [
                'func'       => [$this, 'download_migration_log'],
                'noheader'   => true,
                'capability' => 'ee_migrate_events',
            ],
        ];
    }


    protected function _set_page_config(): void
    {
        $help_tabs_data = [
            'event_migrator_migration_overview_help_tab' => [
                'title'    => __('Event Migrator Overview', 'migrate-events-to-event-espresso'),
                'filename' => 'event_migrator_migration_overview',
            ],
        ];
        try {
            $step_manager = $this->getMigrationStepManager();
            foreach ($step_manager->getSteps() as $step) {
                if (! $step->hasHelpTab()) {
                    continue;
                }
                $help_tabs_data[ 'event_migrator_migration_' . $step->slug() ] = [
                    'title'    => $step->formName(),
                    'filename' => 'event_migrator_migration_' . $step->slug(),
                ];
            }
            $step_manager->setCurrentStepFromRequest();
        } catch (Exception $e) {
            // step manager not yet available
        }
        $this->_page_config = [
            'show_migration_step' => [
                'nav'           => [
                    'label' => esc_html__('Migrate', 'migrate-events-to-event-espresso'),
                    'icon'  => 'dashicons-database-import',
                    'order' => 10,
                ],
                'help_tabs'     => $help_tabs_data,
                'require_nonce' => false,
            ],
            'migration_runs'      => [
                'nav'           => [
                    'label' => esc_html__('Previous runs', 'migrate-events-to-event-espresso'),
                    'icon'  => 'dashicons-backup',
                    'order' => 20,
                ],
                'require_nonce' => false,
            ],
            'rollback_confirm'    => [
                'require_nonce' => true,
            ],
        ];
    }


    protected function _add_screen_options(): void
    {
    }


    protected function _add_screen_options_default(): void
    {
    }


    protected function _add_feature_pointers(): void
    {
    }


    public function load_scripts_styles(): void
    {
        wp_enqueue_style(
            'ee-event-migrator-admin',
            EE_EVENT_MIGRATOR_ADMIN_ASSETS_URL . 'css/ee-event-migrator-admin.css',
            [],
            EE_EVENT_MIGRATOR_VERSION
        );
    }


    public function admin_init(): void
    {
    }


    public function admin_notices(): void
    {
    }


    public function admin_footer_scripts(): void
    {
    }


    /**
     * Redirects to the first wizard step.
     */
    protected function main(): void
    {
        wp_safe_redirect(MigrationUrls::wizard(EE_EVENT_MIGRATOR_ADMIN_URL));
        exit;
    }


    /**
     * Handles wizard step submissions (POST).
     *
     * @throws Exception
     * @throws Throwable
     */
    protected function migrate(): void
    {
        if ($this->request->getServerParam('REQUEST_METHOD') === 'POST') {
            try {
                $this->getMigrationStepManager()->processForm($this->request->postParams());
            } catch (Exception $e) {
                new ExceptionStackTraceDisplay($e);
            }
        }
    }


    /**
     * Shows the current wizard step.
     *
     * @throws EE_Error
     * @throws ReflectionException
     * @throws Throwable
     */
    protected function show_migration_step(): void
    {
        try {
            $form_steps_manager = $this->getMigrationStepManager();
            $form_steps_manager->buildForm();
            $this->_template_args['admin_page_content'] = EEH_HTML::h2(esc_html__('Event Migrator', 'migrate-events-to-event-espresso'))
                . $form_steps_manager->displayProgressSteps()
                . $form_steps_manager->displayCurrentStepForm();
        } catch (Exception $e) {
            new ExceptionStackTraceDisplay($e);
        }
        $this->display_admin_page_with_no_sidebar();
    }


    /**
     * Lists every retained run with its report, download and rollback links.
     */
    protected function migration_runs(): void
    {
        $source_names = [];
        foreach (LoaderFactory::getShared(SourceAdapterRegistry::class)->all() as $slug => $adapter) {
            $source_names[ $slug ] = $adapter->name();
        }
        $entries = LoaderFactory::getShared(MigrationRunStore::class)->list();
        $this->_template_args['admin_page_content'] = EEH_Template::display_template(
            EE_EVENT_MIGRATOR_PATH . 'domain/services/migration/forms/templates/migration_runs_list.template.php',
            [
                'rows'       => RunPresenter::rows($entries, $source_names, EE_EVENT_MIGRATOR_ADMIN_URL),
                'wizard_url' => MigrationUrls::wizard(EE_EVENT_MIGRATOR_ADMIN_URL),
            ],
            true
        );
        $this->display_admin_page_with_no_sidebar();
    }


    /**
     * Asks the admin to confirm a rollback.
     */
    protected function rollback_confirm(): void
    {
        $this->assertCapability();
        $run = $this->requestedRun();
        if (! $run instanceof MigrationRunRecord || ! RunPresenter::actionsFor($run->mode(), $run->status())['rollback']) {
            wp_die(esc_html__('This migration run cannot be rolled back.', 'migrate-events-to-event-espresso'));
        }
        $this->_template_args['admin_page_content'] = EEH_Template::display_template(
            EE_EVENT_MIGRATOR_PATH . 'domain/services/migration/forms/templates/migration_rollback_confirm.template.php',
            [
                'source'     => $run->source(),
                'started'    => RunPresenter::dateLabel($run->startedAt()),
                'status'     => RunPresenter::statusLabel($run->status()),
                'action_url' => MigrationUrls::rollbackRun(EE_EVENT_MIGRATOR_ADMIN_URL, $run->runId()),
                'report_url' => MigrationUrls::report(EE_EVENT_MIGRATOR_ADMIN_URL, $run->runId()),
            ],
            true
        );
        $this->display_admin_page_with_no_sidebar();
    }


    /**
     * Starts a rollback (POST only): takes the migration lock and hands the run id to the rollback batch job. A
     * refusal ends up on the run's report page, written there by the batch job.
     */
    protected function rollback_run(): void
    {
        $this->assertCapability();
        $run_id     = $this->request->getRequestParam('run_id', '', DataType::STRING);
        $report_url = MigrationUrls::report(EE_EVENT_MIGRATOR_ADMIN_URL, MigrationRunStore::isValidRunId($run_id) ? $run_id : '');
        $has_backup = $this->request->getRequestParam('ee-migration-rollback-backup', '', DataType::STRING) === 'yes';
        if ($this->request->getServerParam('REQUEST_METHOD') !== 'POST' || ! $has_backup) {
            EE_Error::add_error(
                esc_html__('Confirm the rollback, including that you have a database backup, to continue.', 'migrate-events-to-event-espresso'),
                __FILE__,
                __FUNCTION__,
                __LINE__
            );
            $this->redirectWithNotices($report_url);
        }
        try {
            $token = LoaderFactory::getShared(MigrationLauncher::class)->launch(
                MigrationConfig::MODE_ROLLBACK,
                '',
                $run_id
            );
            $this->redirectWithNotices(MigrationLauncher::batchUrl(MigrationConfig::MODE_ROLLBACK, $token, $report_url));
        } catch (MigrationLaunchException $exception) {
            EE_Error::add_error($exception->getMessage(), __FILE__, __FUNCTION__, __LINE__);
        } catch (Throwable $throwable) {
            EE_Error::add_error(
                esc_html__('The rollback could not be started. See the error log for details.', 'migrate-events-to-event-espresso'),
                __FILE__,
                __FUNCTION__,
                __LINE__
            );
            ThrowableLogger::log($throwable);
        }
        $this->redirectWithNotices($report_url);
    }


    /**
     * Clears a stale migration lock (never a CSV import's).
     */
    protected function clear_migration_lock(): void
    {
        $this->assertCapability();
        if (LoaderFactory::getShared(MigrationLauncher::class)->clearStaleLock()) {
            EE_Error::add_success(
                esc_html__('The migration lock has been cleared. You can now start a new migration.', 'migrate-events-to-event-espresso')
            );
        } else {
            EE_Error::add_error(
                esc_html__('There is no stale migration lock to clear.', 'migrate-events-to-event-espresso'),
                __FILE__,
                __FUNCTION__,
                __LINE__
            );
        }
        $this->redirectWithNotices(MigrationUrls::wizard(EE_EVENT_MIGRATOR_ADMIN_URL));
    }


    /**
     * Streams a run's crosswalk as a CSV download. The route registration enforces the capability and the nonce;
     * the capability is checked again here, and only a stored run's id is accepted.
     */
    protected function download_crosswalk(): void
    {
        $this->assertCapability();
        $run = $this->requestedRun();
        if (! $run instanceof MigrationRunRecord || ! RunPresenter::actionsFor($run->mode(), $run->status())['crosswalk']) {
            wp_die(esc_html__('There is no crosswalk to download for this run.', 'migrate-events-to-event-espresso'));
        }
        LoaderFactory::getShared(CrosswalkExporter::class)->stream($run->runId());
        exit;
    }


    /**
     * Sends a run's log as a download. The log lives in a folder with a deny-all .htaccess and is named by
     * the stored run's secret, never by anything in the request; MigrationLog::contents() reads it through
     * WP_Filesystem.
     */
    protected function download_migration_log(): void
    {
        $this->assertCapability();
        $run     = $this->requestedRun();
        $secret  = $run instanceof MigrationRunRecord ? $run->logSecret() : '';
        $content = MigrationLog::isValidSecret($secret) ? MigrationLog::contents($secret) : null;
        if ($content === null) {
            wp_die(esc_html__('The requested migration log no longer exists.', 'migrate-events-to-event-espresso'));
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-migration-' . $run->runId() . '.log"');
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a text/plain download body (nosniff), built only from bracket- and newline-stripped log fields, never rendered as HTML.
        echo $content;
        exit;
    }


    /**
     * @throws EE_Error
     */
    protected function getMigrationStepManager(): MigrationStepsManager
    {
        return LoaderFactory::getLoader()->getShared(
            MigrationStepsManager::class,
            [
                EE_Admin_Page::add_query_args_and_nonce(['action' => 'migrate'], EE_EVENT_MIGRATOR_ADMIN_URL),
            ]
        );
    }


    /**
     * The stored run named by the `run_id` request argument, or null when the id is malformed or unknown.
     */
    private function requestedRun(): ?MigrationRunRecord
    {
        $run_id = $this->request->getRequestParam('run_id', '', DataType::STRING);
        return MigrationRunStore::isValidRunId($run_id)
            ? LoaderFactory::getShared(MigrationRunStore::class)->load($run_id)
            : null;
    }


    /**
     * Saves the pending notices so the next page shows them, then redirects and ends the request.
     */
    private function redirectWithNotices(string $url): void
    {
        EE_Error::get_notices(false, true);
        wp_safe_redirect($url);
        exit;
    }


    private function assertCapability(): void
    {
        if (! current_user_can('ee_migrate_events')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'migrate-events-to-event-espresso'));
        }
    }
}
// End of file Event_Migrator_Admin_Page.core.php
// Location: /wp-content/plugins/eea-event-migrator/src/ui/admin/event_migrator/Event_Migrator_Admin_Page.core.php
