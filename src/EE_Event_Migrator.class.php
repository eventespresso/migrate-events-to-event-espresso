<?php

use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;

/**
 * Class EE_Event_Migrator
 *
 * A free Event Espresso add-on that migrates events, venues, tickets, attendees
 * and registrations from other WordPress event plugins into Event Espresso.
 * It registers no licence and no entitlement check and runs on Decaf.
 *
 * @package     Event Espresso
 * @subpackage  eea-event-migrator
 * @author      Event Espresso
 * @since       0.0.1.000
 */
class EE_Event_Migrator extends EE_Addon
{
    /**
     * !!! IMPORTANT !!!
     * this is not the place to perform any logic or add any other filter or action callbacks
     * this is just to bootstrap your addon; and keep in mind the addon might be DE-registered
     * in which case your callbacks should probably not be executed.
     *
     * @throws EE_Error
     */
    public static function register_addon(): void
    {
        if (! defined('EE_EVENT_MIGRATOR_BASENAME')) {
            define('EE_EVENT_MIGRATOR_BASENAME', plugin_basename(EE_EVENT_MIGRATOR_PLUGIN_FILE));
            define('EE_EVENT_MIGRATOR_PATH', plugin_dir_path(__FILE__));
            define('EE_EVENT_MIGRATOR_URL', plugin_dir_url(__FILE__));
            define('EE_EVENT_MIGRATOR_ADMIN', EE_EVENT_MIGRATOR_PATH . 'ui/admin/event_migrator/');
        }

        $new_caps = [
            'ee_migrate_events',
        ];
        // register addon via Plugin API. No 'license' key: the add-on is free.
        EE_Register_Addon::register(
            'Event_Migrator',
            [
                'version'          => EE_EVENT_MIGRATOR_VERSION,
                'plugin_slug'      => 'espresso_event_migrator',
                'min_core_version' => EE_EVENT_MIGRATOR_CORE_VERSION_REQUIRED,
                'main_file_path'   => EE_EVENT_MIGRATOR_PLUGIN_FILE,
                'namespace'        => [
                    'FQNS' => 'EventEspresso\EventMigrator',
                    'DIR'  => __DIR__,
                ],
                'admin_path'       => EE_EVENT_MIGRATOR_ADMIN,
                'autoloader_paths' => [
                    'Event_Migrator_Admin_Page'      => EE_EVENT_MIGRATOR_ADMIN . 'Event_Migrator_Admin_Page.core.php',
                    'Event_Migrator_Admin_Page_Init' => EE_EVENT_MIGRATOR_ADMIN . 'Event_Migrator_Admin_Page_Init.core.php',
                ],
                'capabilities'     => [
                    'administrator'           => $new_caps,
                    'ee_events_administrator' => $new_caps,
                ],
            ]
        );
    }


    /**
     * Registers the add-on's dependency map (every service with constructor dependencies, and each source
     * adapter's TableAnalysis), after EE_Register_Addon has registered the add-on.
     *
     * @since 0.0.1.000
     */
    public function after_registration(): void
    {
        // Classes with no constructor dependencies (SourceAdapterRegistry, Crosswalk, MigrationLog)
        // get NO entry: EE_Dependency_Map::registerDependencies() rejects an empty array.
        $dependencies = [
            // Phase 1: lock, run store.
            'EventEspresso\EventMigrator\domain\services\migration\MigrationLock'                   => [
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\options\JsonWpOptionManager'                      => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'           => [
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog' => EE_Dependency_Map::load_from_cache,
            ],
            // Phase 2 group B: resolvers (Crosswalk and GeoResolver have no constructor dependencies).
            'EventEspresso\EventMigrator\domain\services\migration\normalize\VenueResolver'         => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\GeoResolver' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\normalize\AttendeeResolver'      => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\GeoResolver' => EE_Dependency_Map::load_from_cache,
            ],
            // Phase 2 group C: mappers and the event graph persister.
            'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceTicketMapper'      => [
                'EventEspresso\EventMigrator\domain\services\migration\normalize\TicketSynthesizer' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceEventMapper'       => [
                'EventEspresso\EventMigrator\domain\services\migration\normalize\StatusMapper'          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\VenueResolver'         => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\AuthorResolver'        => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceTicketMapper'      => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\UnmappedConceptWriter' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceBookingMapper'     => [
                'EventEspresso\EventMigrator\domain\services\migration\normalize\StatusMapper'          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\AttendeeResolver'      => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\mapping\SourcePaymentMapper'     => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\normalize\UnmappedConceptWriter' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\EventGraphWriter'        => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\EventGraphPersister'     => [
                'EventEspresso\EventMigrator\domain\services\migration\persist\EventGraphWriter'  => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\EventGraphRemover' => EE_Dependency_Map::load_from_cache,
            ],
            // Phase 2 group C2: booking persistence and the real Persister.
            'EventEspresso\EventMigrator\domain\services\migration\persist\BookingWriter'           => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\commands\CommandBusInterface'                  => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\payments\PaymentProcessor'                     => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\AnswerWriter'            => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\BookingAnswersPersister' => [
                'EventEspresso\EventMigrator\domain\services\migration\persist\AnswerWriter' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\BookingPersister'        => [
                'EventEspresso\EventMigrator\domain\services\migration\persist\BookingWriter'           => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\PaymentMethodResolver'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\BookingAnswersPersister' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\BookingGraphRemover'     => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\NotificationSuppressor'  => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\persist\ModelPersister'          => [
                'EventEspresso\EventMigrator\domain\services\migration\persist\EventGraphPersister' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\BookingPersister'    => EE_Dependency_Map::load_from_cache,
            ],
            // Phase 3: run engine, report, rollback and the two batch jobs (RunReportBuilder and RunQueries have no
            // constructor dependencies). BatchRequestProcessor builds the jobs with loader->getNew().
            'EventEspresso\EventMigrator\domain\services\migration\run\EventStep' => [
                'EventEspresso\EventMigrator\domain\services\migration\normalize\RecurrenceStrategy' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceEventMapper'    => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk'          => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunner' => [
                'EventEspresso\EventMigrator\domain\services\migration\run\EventStep'                  => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\mapping\SourceBookingMapper'    => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk'            => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\NotificationSuppressor' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog'               => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecorder' => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk'     => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog'        => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\report\RunReportBuilder' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\report\CrosswalkExporter' => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityIndex' => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\rollback\RollbackPreflight' => [
                'EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityIndex' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\rollback\RunQueries'     => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityRemover' => [
                'EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationBatchJob' => [
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig'          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\options\JsonWpOptionManager'                               => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\MigrationLock'                   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'           => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecorder'        => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunner'             => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\ModelPersister'          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\DryRunPersister'         => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\persist\BookingAnswersPersister' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationRollbackBatchJob' => [
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig'     => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\options\JsonWpOptionManager'                          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\MigrationLock'              => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'      => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog'           => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityIndex'    => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\rollback\RollbackPreflight' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityRemover'  => EE_Dependency_Map::load_from_cache,
            ],
            // Phase 4: admin wizard. MigrationStepsManager has the same shape as the CSV importer's StepsManager.
            'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationStepsManager' => [
                null,
                null,
                null,
                null,
                null,
                'EventEspresso\core\services\request\RequestInterface' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore' => [
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig' => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\ui\MigrationLauncher' => [
                'EventEspresso\EventMigrator\domain\services\migration\MigrationLock'               => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'       => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\ChooseSource' => [
                'EE_Registry'                                                                         => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\MigrationLock'                 => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\request\RequestInterface'                                => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationOptionsStep' => [
                'EE_Registry'                                                                         => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\request\RequestInterface'                                => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\ConfirmMigration' => [
                'EE_Registry'                                                                         => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore'   => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\ui\MigrationLauncher'          => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\request\RequestInterface'                                => EE_Dependency_Map::load_from_cache,
            ],
            'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationReport' => [
                'EE_Registry'                                                                       => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore' => EE_Dependency_Map::load_from_cache,
                'EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore'       => EE_Dependency_Map::load_from_cache,
                'EventEspresso\core\services\request\RequestInterface'                              => EE_Dependency_Map::load_from_cache,
            ],
        ];
        // Every adapter file under sources/ gets its TableAnalysis entry (REQ-023).
        $dependencies += SourceAdapterRegistry::adapterDependencies();
        foreach ($dependencies as $class => $deps) {
            if (! EE_Dependency_Map::register_dependencies($class, $deps)) {
                EE_Error::add_error(
                    sprintf(
                        /* translators: %1$s: the class whose dependencies could not be registered */
                        esc_html__('Could not register dependencies for "%1$s"', 'migrate-events-to-event-espresso'),
                        esc_html($class)
                    ),
                    __FILE__,
                    __FUNCTION__,
                    __LINE__
                );
            }
        }
    }
}
// End of file EE_Event_Migrator.class.php
// Location: wp-content/plugins/eea-event-migrator/src/EE_Event_Migrator.class.php
