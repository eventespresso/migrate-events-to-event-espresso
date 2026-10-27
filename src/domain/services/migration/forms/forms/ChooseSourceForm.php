<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\forms;

use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_HTML_From_Template;
use EE_Form_Section_Proper;
use EE_Hidden_Input;
use EEH_HTML;
use EventEspresso\EventMigrator\domain\services\migration\ui\CsvImporterCard;

/**
 * Class ChooseSourceForm
 *
 * The first wizard step's form: a card per source (REQ-001). The radio buttons
 * are drawn by the card template under the name ChooseSource reads; the hidden
 * input only lets the form framework recognise the submission.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class ChooseSourceForm extends EE_Form_Section_Proper
{
    public const SOURCE_PARAM = 'ee-migration-source';


    /**
     * @param array $options_array The form's data: cards (array), csv (CsvImporterCard::build()), selected and
     *                             help_tab_link (strings).
     * @throws EE_Error
     */
    public function __construct(array $options_array = [])
    {
        parent::__construct(
            [
                'name'        => 'choose_source',
                'subsections' => [
                    'header'    => new EE_Form_Section_HTML(
                        EEH_HTML::h2(
                            esc_html__('Choose what to migrate from', 'migrate-events-to-event-espresso')
                            . ($options_array['help_tab_link'] ?? '')
                        )
                        . EEH_HTML::p(
                            esc_html__(
                                'Event Espresso found these event plugins on this site. Pick one to migrate its events, venues, tickets and bookings into Event Espresso. Nothing is changed until you confirm on the last step.',
                                'migrate-events-to-event-espresso'
                            )
                        )
                    ),
                    'cards'     => new EE_Form_Section_HTML_From_Template(
                        dirname(__DIR__) . '/templates/migration_source_cards.template.php',
                        [
                            'cards'        => $options_array['cards'] ?? [],
                            'csv'          => $options_array['csv'] ?? CsvImporterCard::build(false, false, false, ''),
                            'selected'     => $options_array['selected'] ?? '',
                            'source_param' => self::SOURCE_PARAM,
                        ]
                    ),
                    'form_step' => new EE_Hidden_Input(['default' => 'source']),
                ],
            ]
        );
    }
}
// End of file ChooseSourceForm.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\forms/ChooseSourceForm.php
