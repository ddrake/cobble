<?php
defined('C5_EXECUTE') or die(_("Access Denied."));

class CobblePageTheme extends Object
{

    // the filesystem themes
    public $packageThemes;
    public $nonPackageThemes;

    // refresh the CobblePageThemes table.
    function refresh()
    {
        $this->initialize();
        $this->addFromPageThemes();
        $this->MergeFromFilesystem();
    }
    /****************************************************/
    /*               INITIALIZATION                     */
    /****************************************************/

    function initialize()
    {
        // clear the CobblePageThemes table
        $this->truncate();
        // Set up the filesystem data
        $this->setThemeLists();
    }

    // Clear the table
    function truncate()
    {
        $db = Loader::db();
        $q = "truncate table CobblePageThemes";
        $db->query($q);
    }

    // get separate lists of packaged and non-packaged theme paths.
    function setThemeLists()
    {
        $this->packageThemes = array_merge($this->listThemes_forPackageDir(DIR_PACKAGES),
            $this->listThemes_forPackageDir(DIR_PACKAGES_CORE));
        $this->nonPackageThemes = array_merge($this->listThemes_forNonPackageDir(DIR_FILES_THEMES),
            $this->listThemes_forNonPackageDir(DIR_FILES_THEMES_CORE));
    }

    // Return an array of theme folders in the given themes directory
    function listThemes_forNonPackageDir($startDir)
    {
        $themes = $this->listSubdirectories($startDir);

        return $themes;
    }

    // Return an array of theme folders within the given packages directory
    function listThemes_forPackageDir($startDir)
    {
        $themes = array();
        $themedirs = array();
        $packages = $this->listSubdirectories($startDir);
        foreach ($packages as $pkg) {
            $themedirs = array_merge($themedirs, $this->listSubdirectories($pkg, true));
        }
        foreach ($themedirs as $dir) {
            $themes = array_merge($themes, $this->listSubdirectories($dir));
        }

        return $themes;
    }


    /****************************************************/
    /*               DB INSERTS AND UPDATES             */
    /****************************************************/
    // Add one new record for each pagetheme in the database
    function addFromPageThemes()
    {
        $db = Loader::db();
        // Note: for some reason, ptName is getting a newline appended by C5
        $q = "insert into CobblePageThemes (ptID, ptHandle, ptName, ptDescription, pkgHandle) select ptID, ptHandle, trim( TRAILING '\n' FROM ptName), ptDescription, pkgHandle from PageThemes left join Packages on PageThemes.pkgID = Packages.pkgID";
        $db->query($q);
    }

    // Get filesystem data and merge it with that from the db.
    function MergeFromFilesystem()
    {
        // Update existing cobble records.
        $db = Loader::db();
        $q = "select ptID, ptHandle, pkgHandle from PageThemes left join Packages on PageThemes.pkgID = Packages.pkgID";
        $r = $db->query($q);
        while ($row = $r->fetchRow()) {
            $this->UpdateFromFilesystem($row);
        }
        // Append new records.
        $this->AppendFromFilesystem();
        // Update the isOverridden flag
        $this->UpdateOverridden();
        $this->UpdateIsSiteTheme();
    }

    // Append new cobble records for any themes not in the database (i.e. not installed)
    function AppendFromFilesystem()
    {
        $db = Loader::db();
        foreach ($this->packageThemes as $tp) {
            $v = array($tp);
            $q = "select count(*) as ct from CobblePageThemes where ptDirectory = ?";
            $r = $db->query($q, $v);
            $row = $r->fetchRow();
            if ($row['ct'] == 0) {
                $ph = $this->getPackageHandle($tp);
                $ptInfo = $this->readThemeInfo($tp);
                $v = array(basename($tp), trim($ptInfo[0]), $ptInfo[1], $tp, $ph);
                $q = "insert into CobblePageThemes (ptHandle, ptName, ptDescription, ptDirectory, pkgHandle) values (?, ?, ?, ?, ?)";
                $db->query($q, $v);
            }
        }

        foreach ($this->nonPackageThemes as $tp) {
            $v = array($tp);
            $q = "select count(*) as ct from CobblePageThemes where ptDirectory = ?";
            $r = $db->query($q, $v);
            $row = $r->fetchRow();
            if ($row['ct'] == 0) {
                $ph = $this->getPackageHandle($tp);
                $ptInfo = $this->readThemeInfo($tp);
                $v = array(basename($tp), $ptInfo[0], $ptInfo[1], $tp);
                $q = "insert into CobblePageThemes (ptHandle, ptName, ptDescription, ptDirectory) values (?, ?, ?, ?)";
                $db->query($q, $v);
            }
        }
    }

    function readThemeInfo($tp)
    {
        if (!file_exists($tp . '/' . 'description.txt')) {
            return false;
        }
        // read the template into a string
        $input = file_get_contents($tp . '/' . 'description.txt');
        $ar = preg_split('/[\n\r]+/', $input);

        return $ar;
    }

    // Update cobble records for any themes that are found in the filesystem
    // setting their ptDirectory to the highest priority appropriate path
    // for themes that are included in packages, the package handles must match.
    function UpdateFromFilesystem($row)
    {
        $db = Loader::db();
        if (!empty($row['pkgHandle'])) {
            $tp = $this->getFirstMatchingPath($this->packageThemes, $row['ptHandle'], $row['pkgHandle']);
            if (!empty($tp)) {
                // update the cobble record with the path
                $v = array($tp, $row['ptID']);
                $q = "update CobblePageThemes set ptDirectory = ? where ptID = ?";
                $db->query($q, $v);
            }
        } else {
            $tp = $this->getFirstMatchingPath($this->nonPackageThemes, $row['ptHandle']);
            if (!empty($tp)) {
                // update the cobble record with the path
                $v = array($tp, $row['ptID']);
                $q = "update CobblePageThemes set ptDirectory = ? where ptID = ?";
                $db->query($q, $v);
            }
        }
    }

    // Update the 'isOverridden' flag for themes that are overridden by other themes
    // If ptId is set, that was the highest priority theme, so it shouldn't be overridden.
    // If two theme with the same name are in separate packages, there's no overriding either.
    function UpdateOverridden()
    {
        $db = Loader::db();
        $q = "select cblPtID, ptDirectory, ptID, pkgHandle from CobblePageThemes";
        $r = $db->query($q);
        while ($row = $r->fetchRow()) {
            $base = basename($row['ptDirectory']);
            if (!empty($row['pkgHandle'])) {
                foreach ($this->packageThemes as $tp) {
                    if ($base == basename($tp) && $this->getPackageHandle($tp) == $row['pkgHandle'] && $row['ptDirectory'] != $tp && empty($row['ptID'])) {
                        $v1 = array($row['cblPtID']);
                        $q1 = "update CobblePageThemes set isOverridden = 1 where cblPtID = ?";
                        $db->query($q1, $v1);
                    }
                }
            } else {
                foreach ($this->nonPackageThemes as $tp) {
                    if ($base == basename($tp) && $row['ptDirectory'] != $tp && empty($row['ptID'])) {
                        $v1 = array($row['cblPtID']);
                        $q1 = "update CobblePageThemes set isOverridden = 1 where cblPtID = ?";
                        $db->query($q1, $v1);
                    }
                }
            }
        }
    }

    // Update the flag 'isSiteTheme'
    function UpdateIsSiteTheme()
    {
        $db = Loader::db();
        $q = "update CobblePageThemes set isSiteTheme = 1 where ptID in (select ptID from Pages where Pages.cID = 1)";
        $db->query($q);
    }

    /****************************************************/
    /*               UTILITIES                          */
    /****************************************************/
    // List all subdirectories (except ., .., and core) in the specified directory.  Core is special (not a standard theme directory)
    // if $themeOnly is true, we only list subdirectories with basename 'themes'
    function listSubdirectories($dir, $themeOnly = false)
    {
        $dirList = array();
        $dirs = glob("$dir/*", GLOB_ONLYDIR);
        if (is_array($dirs)) {
            foreach ($dirs as $subdir) {
                if (($themeOnly == true && basename($subdir) == 'themes') || ($themeOnly == false && basename($subdir) != 'core' && !in_array($subdir,
                            array('.', '..')))
                ) {
                    // the str_replace is to fix for windows.  Shouldn't be any backslashes in a unix path, right?
                    $dirList[] = str_replace('\\', '/', realpath($subdir));
                }
            }
        }

        return $dirList;
    }

    // Get the first matching path for a given theme handle and optional package handle
    function getFirstMatchingPath($list, $ptHandle, $pkgHandle = null)
    {
        foreach ($list as $tp) {
            if ($ptHandle == basename($tp)) {
                if (!empty($pkgHandle)) {
                    if ($pkgHandle == $this->getPackageHandle($tp)) {
                        return $tp;
                    }
                } else {
                    return $tp;
                }

                return $tp;
            }
        }

        return null;
    }

    // Given a path to a theme directory in a package, return the package handle (2 levels up)
    function getPackageHandle($tp)
    {
        $tmp = explode('/', $tp);

        return ($tmp[sizeof($tmp) - 3]);
    }

    /****************************************************/
    /*               DISPLAY FOR DEBUGGING              */
    /****************************************************/
    function displayAllThemes()
    {
        $this->displayPackageThemes();
        $this->displayNonPackageThemes();
    }

    function displayPackageThemes()
    {
        echo 'Package Themes: <br />';
        foreach ($this->packageThemes as $tp) {
            echo $tp . '<br />';
        }
    }

    function displayNonPackageThemes()
    {
        echo 'Non-package Themes: <br />';
        foreach ($this->nonPackageThemes as $tp) {
            echo $tp . '<br />';
        }
    }

}

