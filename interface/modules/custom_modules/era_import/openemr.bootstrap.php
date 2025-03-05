<?php

/**
 *  package OpenEMR
 *  link    https://www.open-emr.org
 *  author  Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c) 2022.
 *  All Rights Reserved
 */

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Services\Globals\GlobalSetting;

function oe_module_era_import_add_menu_item(MenuEvent $event)
{
    $menu = $event->getMenu();
    $menuItem = new stdClass();
    $menuItem->requirement = 0;
    $menuItem->target = 'adm0';
    $menuItem->menu_id = 'adm';
    $menuItem->label = xlt("ERA Auto Import");
    $menuItem->url = "/interface/modules/custom_modules/era_import/era_cron.php";
    $menuItem->children = [];
    $menuItem->acl_req = ["admin", "super"];
    $menuItem->global_req = [];

    foreach ($menu as $key => $item) {
        if ($item->menu_id == 'admimg') {
            $item->children[] = $menuItem;
            break;
        }
    }

    $event->setMenu($menu);

    return $event;
}

$eventDispatcher->addListener(MenuEvent::MENU_UPDATE, 'oe_module_era_import_add_menu_item');
