<?php

use EventEspresso\core\domain\entities\admin\menu\AdminMenuGroup;
use EventEspresso\core\domain\entities\admin\menu\AdminMenuItem;
use EventEspresso\core\domain\entities\admin\menu\AdminMenuTopLevel;

/**
 * Event_Migrator_Admin_Page_Init class
 *
 * This is the init for the Event Migrator Addon Admin Pages.
 *
 * @package      Event Espresso (event migrator addon)
 * @subpackage   admin/Event_Migrator_Admin_Page_Init.core.php
 * @author       Event Espresso
 * @since        0.0.1.000
 */
class Event_Migrator_Admin_Page_Init extends EE_Admin_Page_Init
{
    public function __construct()
    {
        if (! defined('EE_EVENT_MIGRATOR_ADMIN_URL')) {
            define('EE_EVENT_MIGRATOR_ADMIN_URL', admin_url('admin.php?page=' . EVENT_MIGRATOR_PG_SLUG));
            define('EE_EVENT_MIGRATOR_ADMIN_ASSETS_URL', EE_EVENT_MIGRATOR_URL . 'ui/admin/event_migrator/assets/');
        }

        parent::__construct();
        $this->_folder_path = EE_EVENT_MIGRATOR_ADMIN;
    }


    protected function _set_init_properties(): void
    {
        $this->label = __('Event Migrator', 'migrate-events-to-event-espresso');
    }


    public function getMenuProperties(): array
    {
        return defined('EventEspresso\core\domain\entities\admin\menu\AdminMenuTopLevel::MENU_PARENT_ACTIVE')
            ? [
                'menu_type'       => AdminMenuItem::TYPE_MENU_SUB_ITEM,
                'menu_group'      => AdminMenuGroup::MENU_SLUG_ADDONS,
                'menu_order'      => 27,
                'show_on_menu'    => AdminMenuItem::DISPLAY_BLOG_ONLY,
                'parent_slug'     => AdminMenuTopLevel::MENU_PARENT_ACTIVE,
                'menu_slug'       => EVENT_MIGRATOR_PG_SLUG,
                'menu_label'      => __('Event Migrator', 'migrate-events-to-event-espresso'),
                'capability'      => 'ee_migrate_events',
                'admin_init_page' => $this,
            ]
            : [
                'menu_group'      => 'addons',
                'menu_order'      => 27,
                'show_on_menu'    => EE_Admin_Page_Menu_Map::BLOG_ADMIN_ONLY,
                'parent_slug'     => 'espresso_events',
                'menu_slug'       => EVENT_MIGRATOR_PG_SLUG,
                'menu_label'      => __('Event Migrator', 'migrate-events-to-event-espresso'),
                'capability'      => 'ee_migrate_events',
                'admin_init_page' => $this,
            ];
    }
}
// End of file Event_Migrator_Admin_Page_Init.core.php
// Location: /wp-content/plugins/eea-event-migrator/src/ui/admin/event_migrator/Event_Migrator_Admin_Page_Init.core.php
