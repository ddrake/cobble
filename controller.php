<?php
defined('C5_EXECUTE') or die(_("Access Denied."));

class CobblePackage extends Package
{

    protected $pkgHandle = 'cobble';
    protected $appVersionRequired = '5.3.2';
    protected $pkgVersion = '1.09';

    public function getPackageDescription()
    {
        return t('Analyzes the site\'s themes, page types, pages and areas from both file system and database perspectives; Provides queries for help with troubleshooting.');
    }

    public function getPackageName()
    {
        return t('Cobble');
    }

    public function install()
    {
        $pkg = parent::install();

        //Install dashboard page
        Loader::model('single_page');
        $newC = SinglePage::add('/dashboard/cobble', $pkg);
        $newC->update(array('cDescription' => 'A Diagnostic Tool for Concrete 5 '));
    }

    public function upgrade()
    {

        // In case the schema changed, drop all cobble tables
        $db = Loader::db();
        $db->Execute('DROP TABLE CobblePageThemes, CobblePageTypes, CobbleAreas, CobblePages, CobbleTemplateAreas');

        // This needs to be called AFTER dropping tables!
        parent::upgrade();

        // not using the job any more so remove it if it exists...
        Loader::model('job');
        $job = Job::getByHandle('cobble_refresh');
        if (!empty($job)) {
            $job->uninstall();
        }
    }

    public function uninstall()
    {
        parent::uninstall();
        $db = Loader::db();
        $db->Execute('DROP TABLE CobblePageThemes, CobblePageTypes, CobbleAreas, CobblePages, CobbleTemplateAreas');
    }

}