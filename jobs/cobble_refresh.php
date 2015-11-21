<?php
/**
 *
 * Responsible for loading the indexed search class and initiating the reindex command.
 * @package Utilities
 */

defined('C5_EXECUTE') or die(_("Access Denied."));

class CobbleRefresh extends Job
{

    public function getJobName()
    {
        return t('Refresh Cobble Tables');
    }

    public function getJobDescription()
    {
        return t("Refresh the Cobble Tables - This should be done whenever structural changes are made, such as adding pages, themes or plugins, setting page themes, editing templates, etc...");
    }

    function run()
    {
        Loader::model('cobble', 'cobble');
        $cbl = new Cobble();
        $cbl->refresh();

        return "Cobble Tables were Refreshed";
    }

}
