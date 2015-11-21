<?php
defined('C5_EXECUTE') or die(_("Access Denied."));

// We need to consider two different kinds of page types - both kinds can be defined as page types in the database:
// 1. Regular page types: templates in the directory of each theme excepting view.php.
//   Templates with the same name as a single page handle will be flagged as possible conflict.
// 2. Theme-independent page types: these should be defined both in the database and in the file system (one of the single pages directories)
// They are wrapped in view.php of the current theme (i.e. the home page theme)
// The handles of the single pages for the site

// If any page type handles have the same name as a single page handle, they are still counted as page types,
// but are flagged because of the potential for conflict.

class CobblePageType extends Object
{
    var $cblCtID;
    var $cblPtID;
    var $ctID;
    var $ctFilePrefix;
    var $ctHandle;
    var $filePath;
    var $wrapperPath;
    var $ctIsSinglePageHandle;
    var $pkgHandle;

    // Lists of 'special' i.e. theme-independent page types.
    var $packageSpecialPageTypes;
    var $nonPackageSpecialPageTypes;

    // The wrapper path - view.php in the current theme.
    var $defaultWrapperPath;

    // refresh the CobblePageThemes table.
    function refresh()
    {
        $this->initialize();
        $this->addFromPageTypes();
        $this->updateSinglepageFlags();
    }

    /****************************************************/
    /*               INITIALIZATION                     */
    /****************************************************/
    function initialize()
    {
        // Set the wrapper path.  This is the wrapper used by any theme-independent page types.
        $this->setDefaultWrapperPath();
        // Fill the arrays with all special (theme-independent) page types.
        $this->setSpecialPageTypeLists();
        // Get an instance of the CobblePageThemes class and call its setThemeLists
        // so we have them available for searching.
        //Loader::model('cobble_page_theme','cobble');
        //$cpt = new CobblePageTheme();
        //$cpt->setThemeLists();
        // Fill the lists of package and non-package regular page types.
        $this->truncate();
    }

    // Set the path to the default wrapper (i.e. view.php in the site theme)
    // We assume that the CobblePageThemes table has already been refreshed.
    function setDefaultWrapperPath()
    {
        $db = Loader::db();
        $q = "select ptDirectory from CobblePageThemes where isSiteTheme";
        $r = $db->query($q);
        if ($row = $r->fetchRow()) {
            $this->defaultWrapperPath = $row['ptDirectory'] . '/' . 'view.php';
        }
    }

    // get separate lists of packaged and non-packaged theme paths.
    // DIR_PACKAGES HAS higher priority than DIR_PACKAGES_CORE, etc..
    function setSpecialPageTypeLists()
    {
        $this->packageSpecialPageTypes = array_merge(
            $this->listSpecialPageTypes_forPackageDir(DIR_PACKAGES),
            $this->listSpecialPageTypes_forPackageDir(DIR_PACKAGES_CORE)
        );
        $this->nonPackageSpecialPageTypes = array_merge(
            $this->listSpecialPageTypes_forNonPackageDir(DIR_BASE . '/' . DIRNAME_PAGE_TYPES),
            $this->listSpecialPageTypes_forNonPackageDir(DIR_BASE_CORE . '/' . DIRNAME_PAGE_TYPES)
        );
    }

    // Return an array of special page type templates in the given special pages directory
    function listSpecialPageTypes_forNonPackageDir($startDir)
    {
        $tplList = $this->listTemplates($startDir);

        return $tplList;
    }

    // Return an array of special page type templates in the given packages directory
    function listSpecialPageTypes_forPackageDir($startDir)
    {
        $pagetypes = array();
        $pagetypedirs = array();
        $packages = $this->listSubdirectories($startDir);
        foreach ($packages as $pkg) {
            $pagetypedirs = array_merge($pagetypedirs, $this->listSubdirectories($pkg, true));
        }
        foreach ($pagetypedirs as $dir) {
            $pagetypes = array_merge($pagetypes, $this->listTemplates($dir));
        }

        return $pagetypes;
    }

    // Clear the table
    function truncate()
    {
        $db = Loader::db();
        $q = "truncate table CobblePageTypes";
        $db->query($q);
    }

    /****************************************************/
    /*               DB INSERTS AND UPDATES             */
    /****************************************************/
    function addFromPageTypes()
    {
        // get a recordset of the page types defined in the database
        $db = Loader::db();
        $q = "select ctID, ctHandle, pkgHandle from PageTypes left join Packages on PageTypes.pkgID = Packages.pkgID";
        $r = $db->query($q);

        while ($row = $r->fetchRow()) {
            // for each record, first check for a theme-independent page type in the filesystem matching the ctHandle
            //  if found, insert the appropriate record in CobblePageTypes (note: wrapperPath is view.php in current theme)
            //    and move to the next record.
            if (!empty($row['pkgHandle'])) {
                $ctp = $this->GetSpecialPageType($this->packageSpecialPageTypes, $row['ctHandle'], $row['pkgHandle']);
            } else {
                $ctp = $this->GetSpecialPageType($this->nonPackageSpecialPageTypes, $row['ctHandle']);
            }
            if (!empty($ctp)) {
                // Insert appropriate record in CobblePageTypes
                $v = array(
                    basename($ctp, '.php'),
                    $ctp,
                    $row['ctID'],
                    $this->defaultWrapperPath,
                    $row['pkgHandle'],
                    $row['ctHandle']
                );
                $q = "insert into CobblePageTypes (ctFilePrefix, filePath, ctID, wrapperPath, pkgHandle, ctHandle) values (?, ?, ?, ?, ?, ?)";
                $db->query($q, $v);
            } else {
                // Insert one record for each possible template path for this page type.
                // ie. if the page type is in a package, we only look at themes in that package
                $this->insertPageTypes($row['ctID'], $row['ctHandle'], $row['pkgHandle']);
            }
        }
    }

    function insertPageTypes($ctID, $handle, $pkgHandle)
    {
        $db = Loader::db();
        // Look at all themes, just because a page type is associated with a specific package,
        // doesn't mean it only uses themes in that package.  See the blog package for an example.
        $q = "select cblPtID, ptDirectory from CobblePageThemes";
        $r = $db->query($q, $v);
        while ($row = $r->fetchRow()) {
            $this->insertPageType($row['cblPtID'], $ctID, $handle, $pkgHandle, $row['ptDirectory']);
        }
    }

    // Go through all templates, checking for a match and the presence of 'default.php'.
    // If we find a match insert it.  If we've checked all and haven't found a match, insert linked to 'default.php'
    // if no 'default.php' insert linked with null paths.
    function insertPageType($cblPtID, $ctID, $handle, $pkgHandle, $ptDirectory)
    {
        $db = Loader::db();
        $defaultPath = null;
        $inserted = false;
        // list all templates in the directory except view.php
        $list = $this->listTemplates($ptDirectory);
        foreach ($list as $ctp) {
            $base = basename($ctp, '.php');
            if ($base == $handle) {
                // found a match -- add it linked
                $v = array($cblPtID, $ctID, $handle, $pkgHandle, $ctp, $handle);
                $q = "insert into CobblePageTypes (cblPtID, ctID, ctFilePrefix, pkgHandle, filePath, ctHandle) values (?, ?, ?, ?, ?, ?)";
                $db->query($q, $v);
                $inserted = true;
            } elseif ($base == 'default') {
                // don't insert -- set this for later...
                $defaultPath = $ctp;
            }
        }
        if (!$inserted && !empty($defaultPath)) {
            // Not found, but we have a default.php so add it linked to that.
            $v = array($cblPtID, $ctID, 'default', $pkgHandle, $defaultPath, $handle);
            $q = "insert into CobblePageTypes (cblPtID, ctID, ctFilePrefix, pkgHandle, filePath, ctHandle) values (?, ?, ?, ?, ?, ?)";
            $db->query($q, $v);
        } elseif (!$inserted) {
            // Not found and no default.php so
            // Add it with a null filePath.
            $v = array($cblPtID, $ctID, null, $pkgHandle, null, $handle);
            $q = "insert into CobblePageTypes (cblPtID, ctID, ctFilePrefix, pkgHandle, filePath, ctHandle) values (?, ?, ?, ?, ?, ?)";
            $db->query($q, $v);
        }
    }

    // Update the IsSinglePage flags for any page types whose handles match a single page handle
    function updateSinglepageFlags()
    {
        $db = Loader::db();
        $q = "update CobblePageTypes set isSinglePageHandle = 1 where ctHandle collate utf8_general_ci in (" .
            "select cHandle collate utf8_general_ci from Collections inner join Pages on Collections.cID = Pages.cID inner join CollectionVersions on CollectionVersions.cID = Pages.cID and CollectionVersions.cvIsApproved=1 where CollectionVersions.ctID = 0)";
        $db->query($q);
    }

    /****************************************************/
    /*               UTILITIES                          */
    /****************************************************/
    // Get the special page type for a handle and a list of special page types
    function GetSpecialPageType($list, $handle, $pkgHandle = null)
    {
        foreach ($list as $ctp) {
            if (!empty($pkgHandle)) {
                if (basename($ctp, '.php') == $handle && $this->getPackageHandle_forPageTypePath($ctp) == $pkgHandle) {
                    return $ctp;
                }
            } else {
                if (basename($ctp, '.php') == $handle) {
                    return $ctp;
                }
            }
        }

        return null;
    }

    // List all subdirectories (except ., ..) in the specified directory.
    // if $pageTypeOnly is true, we only list subdirectories with basename 'page_types'
    function listSubdirectories($dir, $pageTypeOnly = false)
    {
        $dirList = array();
        $dirs = glob("$dir/*", GLOB_ONLYDIR);
        if (is_array($dirs)) {
            foreach ($dirs as $subdir) {
                if (($pageTypeOnly == true && basename($subdir) == 'page_types') || ($pageTypeOnly == false && !in_array($subdir,
                            array('.', '..')))
                ) {
                    // the str_replace is to fix for windows.  Shouldn't be any backslashes in a unix path, right?
                    $dirList[] = str_replace('\\', '/', realpath($subdir));
                }
            }
        }

        return $dirList;
    }

    // List all templates in a directory
    // if $excludeView is true, we exclude templates with name view.php.
    // If $excludeFromArray is an array, we exclude any templates with file prefix in the array
    function listTemplates($dir, $excludeView = false)
    {
        $tplList = array();
        $dirs = glob($dir . '/*.php');
        if (is_array($dirs)) {
            foreach ($dirs as $file) {
                if (($excludeView == false || basename($file) != 'view.php')) {
                    $tplList[] = str_replace('\\', '/', realpath($file));
                }
            }
        }

        return $tplList;
    }

    // Given a path to a page type template in a package, return the package handle (2 levels up)
    function getPackageHandle_forPageTypePath($tp)
    {
        $tmp = explode('/', $tp);

        return (trim($tmp[sizeof($tmp) - 3]));
    }
    /****************************************************/
    /*               DISPLAY FOR DEBUGGING              */
    /****************************************************/
    function displayAll()
    {
        $this->displayPackageSpecialPageTypes();
        $this->displayNonPackageSpecialPageTypes();
    }

    function displayPackageSpecialPageTypes()
    {
        echo '<br />Package Special Page Types: <br />';
        foreach ($this->packageSpecialPageTypes as $ctp) {
            echo $ctp . '<br />';
        }
    }

    function displayNonPackageSpecialPageTypes()
    {
        echo '<br />Non-package Special Page Types: <br />';
        foreach ($this->nonPackageSpecialPageTypes as $ctp) {
            echo $ctp . '<br />';
        }
    }

}
