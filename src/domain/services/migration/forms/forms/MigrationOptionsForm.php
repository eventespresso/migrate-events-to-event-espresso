<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\forms;

use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_HTML_From_Template;
use EE_Form_Section_Proper;
use EE_Hidden_Input;
use EE_Radio_Button_Input;
use EE_Submit_Input;
use EE_Text_Input;
use EE_Yes_No_Input;
use EEH_HTML;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\domain\services\migration\ui\OptionsSubmission;

/**
 * Class MigrationOptionsForm
 *
 * The options step's form (REQ-003 to REQ-006): the pre-flight table, then the
 * time horizon, whether bookings come along, how recurring events are
 * represented and, for a user who may publish, whether the source's publish
 * state is kept. Choices the source cannot honour are not offered.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationOptionsForm extends EE_Form_Section_Proper
{
    public const REFRESH_PARAM = 'ee-migration-refresh';


    /**
     * @param array $options_array The form's data: inventory (template arguments), capabilities
     *                             (SourceCapabilities), options (MigrationOptions), can_publish (bool),
     *                             revision (string) and help_tab_link (string).
     * @throws EE_Error
     */
    public function __construct(array $options_array)
    {
        /** @var SourceCapabilities $capabilities */
        $capabilities = $options_array['capabilities'];
        /** @var MigrationOptions $options */
        $options  = $options_array['options'];
        $horizon  = OptionsSubmission::horizonDefaults($options);
        $sections = [
            'header'       => new EE_Form_Section_HTML(
                EEH_HTML::h2(
                    esc_html__('Review and set options', 'migrate-events-to-event-espresso') . ($options_array['help_tab_link'] ?? '')
                )
            ),
            'inventory'    => new EE_Form_Section_HTML_From_Template(
                dirname(__DIR__) . '/templates/migration_inventory.template.php',
                $options_array['inventory']
            ),
            'horizon'      => new EE_Radio_Button_Input(
                [
                    MigrationOptions::HORIZON_ALL      => esc_html__('All events', 'migrate-events-to-event-espresso'),
                    MigrationOptions::HORIZON_UPCOMING => esc_html__('Upcoming events only', 'migrate-events-to-event-espresso'),
                    OptionsSubmission::HORIZON_AFTER_CHOICE => esc_html__(
                        'Events ending on or after a chosen date',
                        'migrate-events-to-event-espresso'
                    ),
                ],
                [
                    'html_label_text' => esc_html__('Which events', 'migrate-events-to-event-espresso'),
                    'default'         => $horizon['choice'],
                    'required'        => true,
                ]
            ),
            'horizon_date' => new EE_Text_Input(
                [
                    'html_label_text' => esc_html__('Date (YYYY-MM-DD)', 'migrate-events-to-event-espresso'),
                    'html_help_text'  => esc_html__(
                        'Only used with "Events ending on or after a chosen date".',
                        'migrate-events-to-event-espresso'
                    ),
                    'default'         => $horizon['date'],
                    'required'        => false,
                ]
            ),
        ];
        $has_bookings        = $capabilities->provides(SourceCapabilities::DOMAIN_BOOKINGS);
        $sections['domains'] = new EE_Radio_Button_Input(
            [
                MigrationOptions::DOMAINS_EVENTS   => esc_html__('Events only', 'migrate-events-to-event-espresso'),
                MigrationOptions::DOMAINS_BOOKINGS => esc_html__(
                    'Events and their bookings (contacts, registrations, payments)',
                    'migrate-events-to-event-espresso'
                ),
            ],
            [
                'html_label_text' => esc_html__('What to migrate', 'migrate-events-to-event-espresso'),
                'html_help_text'  => $has_bookings
                    ? ''
                    : esc_html__('Not available in this source: it has no bookings.', 'migrate-events-to-event-espresso'),
                'default'         => $has_bookings ? $options->domains() : MigrationOptions::DOMAINS_EVENTS,
                'required'        => $has_bookings,
            ]
        );
        if (! $has_bookings) {
            // Shown so the admin sees the domain exists; a disabled input is never submitted and stays "events".
            $sections['domains']->disable();
        }
        if ($capabilities->hasRecurrence()) {
            $sections['recurrence'] = new EE_Radio_Button_Input(
                [
                    MigrationOptions::RECURRENCE_SINGLE_EVENT         => esc_html__(
                        'One event per series, with every date as a datetime',
                        'migrate-events-to-event-espresso'
                    ),
                    MigrationOptions::RECURRENCE_EVENT_PER_OCCURRENCE => esc_html__(
                        'One event for every occurrence',
                        'migrate-events-to-event-espresso'
                    ),
                ],
                [
                    'html_label_text' => esc_html__('Recurring events', 'migrate-events-to-event-espresso'),
                    'default'         => $options->recurrence(),
                    'required'        => true,
                ]
            );
        }
        if ($options_array['can_publish']) {
            $sections['preserve_status'] = new EE_Yes_No_Input(
                [
                    'html_label_text' => esc_html__('Keep the source publish state', 'migrate-events-to-event-espresso'),
                    'html_help_text'  => esc_html__(
                        'No: every event is created as a draft. Yes: events that are published in the source are published here.',
                        'migrate-events-to-event-espresso'
                    ),
                    'default'         => $options->preserveStatus(),
                ]
            );
        }
        $sections['staging_revision'] = new EE_Hidden_Input(['default' => $options_array['revision']]);
        $sections['refresh']          = new EE_Submit_Input(
            [
                'html_name'  => self::REFRESH_PARAM,
                'html_id'    => self::REFRESH_PARAM,
                'html_class' => 'button',
                'default'    => esc_html__('Update the counts', 'migrate-events-to-event-espresso'),
                'no_label'   => true,
            ]
        );
        parent::__construct(['name' => 'migration_options', 'subsections' => $sections]);
    }
}
// End of file MigrationOptionsForm.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\forms/MigrationOptionsForm.php
