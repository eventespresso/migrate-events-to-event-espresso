<?php

/*
  Plugin Name: Migrate Events to Event Espresso
  Description: Migrate events, dates, venues, tickets, attendees and registrations into Event Espresso from Events Manager, The Events Calendar, Event Tickets, Amelia, Eventin, Events Made Easy, Event Genius, EventPrime, EventON, WP Event Manager and WP Events Manager.
  Version: 1.0.0
  Author: Event Espresso
  Requires PHP: 7.4
  Requires at least: 6.2
  Author URI: https://www.eventespresso.com
  License: GPLv3
  Text Domain: migrate-events-to-event-espresso
  Copyright 2026 Event Espresso (email : support@eventespresso.com)

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * ------------------------------------------------------------------------
 *
 * Migrate Events to Event Espresso, an add-on for Event Espresso
 *
 * @ package        Event Espresso
 * @ author         Event Espresso
 * @ copyright      (c) 2026 Event Espresso
 * @ license        GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @ link           https://www.eventespresso.com
 */

if (! defined('ABSPATH')) {
    exit;
}

// define versions and this file
const EE_EVENT_MIGRATOR_CORE_VERSION_REQUIRED = '5.0.58.001';
const EE_EVENT_MIGRATOR_VERSION = '1.0.0';
const EE_EVENT_MIGRATOR_PLUGIN_FILE = __FILE__;
const EVENT_MIGRATOR_PG_SLUG = 'espresso_event_migrator';


/**
 * Registers the add-on with EE core. The add-on is free and runs on both the
 * caffeinated and the Decaf builds of Event Espresso: it registers no licence
 * and no entitlement check, and it relies only on core (not caffeinated) code.
 *
 * @throws EE_Error
 */
function espresso_event_migrator_load(): void
{
    if (
        defined('PHP_VERSION_ID')
        && PHP_VERSION_ID >= 70400
        && class_exists('EE_Addon')
    ) {
        EE_Psr4AutoloaderInit::psr4_loader()->addNamespace('EventEspresso\EventMigrator', __DIR__ . '/src');
        require_once(__DIR__ . '/src/EE_Event_Migrator.class.php');
        EE_Event_Migrator::register_addon();
    } else {
        add_action('admin_notices', 'espresso_event_migrator_activation_error');
    }
}
add_action('AHEE__EE_System__load_espresso_addons', 'espresso_event_migrator_load');


/**
 *    displays activation error admin notice
 */
function espresso_event_migrator_activation_error(): void
{
    unset($_GET['activate'], $_REQUEST['activate']);
    if (! function_exists('deactivate_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    deactivate_plugins(plugin_basename(EE_EVENT_MIGRATOR_PLUGIN_FILE));
    ?>
    <div class="error">
        <p>
            <?php printf(
                /* translators: 1: required Event Espresso version, 2: required PHP version */
                esc_html__(
                    'Migrate Events to Event Espresso could not be activated. Please ensure that Event Espresso version %1$s and PHP version %2$s or higher is running',
                    'migrate-events-to-event-espresso'
                ),
                esc_html(EE_EVENT_MIGRATOR_CORE_VERSION_REQUIRED),
                '7.4'
            ); ?>
        </p>
    </div>
    <?php
}


// End of file migrate-events-to-event-espresso.php
// Location: wp-content/plugins/migrate-events-to-event-espresso/migrate-events-to-event-espresso.php
