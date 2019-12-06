//<?php
/**
 * Sberbank Pokupay Installer
 *
 * Payment installer
 *
 * @category    plugin
 * @author      mnoskov
 * @internal    @events OnWebPageInit,OnManagerPageInit,OnPageNotFound
 * @internal    @modx_category Commerce
 * @internal    @installset base
*/

if (empty($modx->commerce) || !defined('COMMERCE_INITIALIZED')) {
    return;
}

$modx->clearCache('full');

$tab_plugins  = $modx->getFullTablename('site_plugins');
$tab_events   = $modx->getFullTablename('site_plugin_events');
$tab_statuses = $modx->getFullTablename('commerce_order_statuses');

$lang = $modx->commerce->getUserLanguage('pokupay');

$plugin = $modx->db->getRow($modx->db->select('*', $tab_plugins, "`name` = 'Payment Sberbank Pokupay'"));

if ($plugin) {
    $properties = json_decode($plugin['properties'], true);

    if ($properties) {
        if (!empty($properties['success_status_id'][0]) && empty($properties['success_status_id'][0]['value'])) {
            $status_id = $modx->db->insert(['title' => $lang['pokupay.confirmed_status']], $tab_statuses);
            $properties['success_status_id'][0]['value'] = $status_id;
        }

        $modx->db->update(['properties' => json_encode($properties, JSON_UNESCAPED_UNICODE)], $tab_plugins, "`id` = '" . $plugin['id'] . "'");
    }
}

// remove installer
$query = $modx->db->select('id', $tab_plugins, "`name` = '" . $modx->event->activePlugin . "'");

if ($id = $modx->db->getValue($query)) {
   $modx->db->delete($tab_plugins, "`id` = '$id'");
   $modx->db->delete($tab_events, "`pluginid` = '$id'");
};
