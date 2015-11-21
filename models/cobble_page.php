<?php
defined('C5_EXECUTE') or die(_("Access Denied."));

class CobblePage extends Object
{

    // array with hard-coded theme paths from theme_paths.php and site_theme_paths.php
    public $hardThemePaths;
    // array with paths to all single pages in the file system, registered or not.
    public $singlePagePaths;
    // the directory of the site default theme (the home page theme) used for page defaults (templates)
    public $defaultSiteTheme;
    public $defaultSiteThemeCobbleID;

    // refresh the CobblePageThemes table.
    function refresh()
    {
        $this->initialize();
        $this->AddFromPages();
        $this->AddFromFilesystem();
        $this->UpdateCobblePageType();
        $this->UpdatePathsForThemeIndependentPageTypes();
        //$this->displaySinglePagePaths();
        //$this->displayHardThemePaths();
    }
    /****************************************************/
    /*               INITIALIZATION                     */
    /****************************************************/

    function initialize()
    {
        // clear the CobblePageThemes table
        $this->truncate();
        // get the hard-coded theme paths into an array
        $this->ParseHardThemePaths();
        // get paths to all single pages (registered or not) into an array
        $this->listAllSinglePages();
        // set the site default theme directory
        $this->setDefaultWrapperPath();

    }

    // Clear the table
    function truncate()
    {
        $db = Loader::db();
        $q = "truncate table CobblePages";
        $db->query($q);
    }

    // Get the path to the site theme
    // We assume that the CobblePageThemes table has already been refreshed.
    function setDefaultWrapperPath()
    {
        $db = Loader::db();
        $q = "select ptDirectory, cblPtID from CobblePageThemes where isSiteTheme";
        $r = $db->query($q);
        if ($row = $r->fetchRow()) {
            $this->defaultSiteTheme = $row['ptDirectory'];
            $this->defaultSiteThemeCobbleID = $row['cblPtID'];
        }
    }

    // Parse theme_paths and site_theme_paths to construct an array $this->hardThemePaths
    function ParseHardThemePaths()
    {
        // Open and parse theme_paths and site_theme_paths
        // handling wildcards, constants, etc.
        $themePaths = $this->ParseHardThemePath($this->stdpath(DIR_BASE_CORE . '/' . 'config' . '/' . 'theme_paths.php'));
        $themePaths = array_merge($themePaths,
            $this->ParseHardThemePath($this->stdpath(DIR_BASE . '/' . 'config' . '/' . 'site_theme_paths.php')));
        $this->hardThemePaths = $themePaths;
    }

    // parse the supplied theme_paths or site_theme_paths file and return an
    // array with key: path and value: themeHandle
    function ParseHardThemePath($file)
    {
        // read the template into a string
        $input = file_get_contents($file);

        // tokenize the string
        $tokens = token_get_all($input);

        // remove whitespace tokens and pack
        $tokens = $this->RemoveWhitespace($tokens);

        return $this->GetThemePaths($tokens);
    }

    // Given a token array, find -> T_OBJECT_OPERATOR followed by T_STRING = 'setThemeByPath'
    // followed by ( followed by T_CONSTANT_ENCAPSED_STRING (the path) followed by ,
    // followed by either a T_CONSTANT_ENCAPSED_STRING OR a T_STRING (the theme)
    // if the theme is a T_STRING, we need to eval() it.
    function GetThemePaths($ta)
    {
        $ct = count($ta);
        $i = 0;
        $themePaths = array();
        while ($i < $ct - 5) {
            $val = $ta[$i];
            $val1 = $ta[$i + 1];
            if (is_array($val) && is_array($val1)) {
                if ($val[0] == T_OBJECT_OPERATOR && $val1[0] == T_STRING && $val1[1] == 'setThemeByPath') {
                    $tmp = $this->getThemePathInfo($ta, $i);
                    if (!empty($tmp)) {
                        $path = $this->stripQuotes($tmp['path']);
                        $theme = $this->stripQuotes($tmp['theme']);
                        $themePaths[$path] = $theme;
                    }
                    $i += 5;
                }
            }
            $i++;
        }

        return $themePaths;
    }

    function getThemePathInfo($ta, $i)
    {
        $valP = $ta[$i + 3];
        $valT = $ta[$i + 5];
        if ($valP[0] != T_CONSTANT_ENCAPSED_STRING && $valT[0] != T_CONSTANT_ENCAPSED_STRING && $valT[0] != T_STRING) {
            return null;
        }
        $theme = $valT[0] == T_STRING ? constant($valT[1]) : $valT[1];

        return array('path' => $valP[1], 'theme' => $theme);
    }

    function listAllSinglePages()
    {
        // Look in the four possible single pages directories
        // populate the array singlePagePaths by scanning the filesystem for single pages
        // the array elements should be sub-arrays with the filePath and the cFilename
        $list = array();
        $newList = $this->listSinglePagesForDirectory(DIR_FILES_CONTENT, DIR_FILES_CONTENT);
        $list = array_merge($list, $newList);

        $packagedirs = $this->listSinglePageDirs_forPackageDir(DIR_PACKAGES);
        foreach ($packagedirs as $dir) {
            $newList = $this->listSinglePagesForDirectory($dir, $dir, array());
            $list = array_merge($list, $newList);
        }
        $packagedirs = $this->listSinglePageDirs_forPackageDir(DIR_PACKAGES_CORE);
        foreach ($packagedirs as $dir) {
            $newList = $this->listSinglePagesForDirectory($dir, $dir, array());
            $list = array_merge($list, $newList);
        }
        $newList = $this->listSinglePagesForDirectory(DIR_FILES_CONTENT_REQUIRED, DIR_FILES_CONTENT_REQUIRED, array());
        $list = array_merge($list, $newList);
        // set the member variable

        $this->singlePagePaths = $list;
    }

    // Return an array of theme folders within the given packages directory
    function listSinglePageDirs_forPackageDir($startDir)
    {
        $spdirs = array();
        $packages = $this->listSubdirectories($startDir);
        foreach ($packages as $pkg) {
            $spdirs = array_merge($spdirs, $this->listSubdirectories($pkg, true));
        }

        return $spdirs;
    }

    // Recursively search the specified single_pages directory for templates.
    // Return an array of arrays which contain info about each template found.
    function listSinglePagesForDirectory($basedir, $curdir)
    {
        $basedir = $this->stdpath($basedir);
        $curdir = $this->stdpath($curdir);
        $list = array();
        // get an array of paths to all files in the current directory.
        $files = $this->listTemplates($curdir);
        // for each of these, create a sub-array with the appropriate filePath and cFilename, add it to $list.
        foreach ($files as $file) {
            $ar = $this->getTemplateInfo($basedir, $curdir, $file);
            if (!empty($ar)) {
                $list[] = $ar;
            }
        }
        // get an array of subdirectories of the current directory.
        $subdirs = $this->listSubdirectories($curdir);
        // for each of these, recursively call this function.
        foreach ($subdirs as $dir) {
            $newlist = $this->listSinglePagesForDirectory($basedir, $dir);
            $list = array_merge($list, $newlist);
        }

        return $list;
    }

    // Return an array with info about the template located at $filePath.
    function getTemplateInfo($basedir, $curdir, $filePath)
    {
        // get the cHandle - we may need to override later
        $cHandle = basename($filePath, '.php');
        if ($basedir == $curdir && $cHandle == 'view') {
            // if $basedir equals $curdir and $fileprefix is 'view' it's NOT a single page.
            return null;
        } else {
            // $cFilename is just the string obtained by chopping the basedir off the left side of curdir
            $cFilename = str_replace($basedir, '', $filePath);
            if ($cHandle == 'view') {
                $ar = explode('/', $cFilename);
                $cHandle = $ar[sizeof($ar) - 2];
            }

            return array('filePath' => $filePath, 'cFilename' => $cFilename, 'cHandle' => $cHandle);
        }
    }


    /****************************************************/
    /*               DB INSERTS AND UPDATES             */
    /****************************************************/
    // Todo: fix this so that a page that references a non-existent page type can still get its file path set.
    // Get a recordset with one record for each page in the database
    // Left join the Pages table with PagePaths to get the cPath,
    // Left join with PageTypes to get the ctHandle
    // Left join to the CobblePageThemes table for non-overridden themes to get the themeFolder
    function AddFromPages()
    {
        $db = Loader::db();

        $q = "SELECT P.cID, CV.ctID, P.cIsTemplate, P.cFilename, P.pkgID, CV.ptID, PKG.pkgHandle, PP.cPath, CT.ctID AS ctID_ct, CT.ctHandle, CPT.ptDirectory, CPT.cblPtID, CV.cvHandle AS cHandle, P.cPointerID, P.cPointerExternalLink
            FROM Pages P
            LEFT JOIN PagePaths PP ON P.cID = PP.cID
            LEFT JOIN CollectionVersions CV ON P.cID = CV.cID AND CV.cvIsApproved = 1
            LEFT JOIN PageTypes CT ON CV.ctID = CT.ctID
            LEFT JOIN CobblePageThemes CPT ON CV.ptID = CPT.ptID AND CPT.isOverridden = 0
            LEFT JOIN Packages PKG ON P.pkgID = PKG.pkgID
            WHERE PP.ppIsCanonical = 1 OR PP.cPath IS NULL";
        $db->query($q);
        $r = $db->query($q);
        while ($row = $r->fetchRow()) {
            $this->AddFromRow($row);
        }
    }

    function AddFromRow($row)
    {
        // Handle single pages (i.e. ctID is empty and cFilename is specified)
        // Check for a template in the page's theme with prefix matching the cHandle of the page record
        //   if found, the single page will be rendered completely by this so we don't need to check the single pages directories
        //     just update the filePath, wrapperPath will be null.
        //   if not found, set the wrapper path as the view.php in the page's theme.  To set the filePath, follow the same logic as
        //     that used in View->render().
        extract($row);
        $isThemeFromPath = 0;
        $isSinglePage = 0;
        $isFileMissing = 0;
        $isWrapperMissing = 0;
        $wrapperPath = null;
        $filePath = null;

        if ($ctID == 0 && !empty($cFilename)) {
            $isSinglePage = 1;
            // SINGLE PAGE
            // Get the wrapper
            // First try to get it from theme_paths or site_theme_paths
            $wrapperPath = $this->GetWrapperPath($cPath);
            if (!empty($wrapperPath)) {
                $isThemeFromPath = 1;
            } else {
                // Look for a view.php in the page's theme.
                $wrapperPath = $ptDirectory . '/' . 'view.php';
                if (!file_exists($wrapperPath)) {
                    $wrapperPath = $this->GetThemeForSinglePageWithThemeNotSet($cHandle . '.php');
                    if (!file_exists($wrapperPath)) {
                        $isWrapperMissing = 1;
                    }
                }
            }
            $file1 = $ptDirectory . '/' . $cHandle . '.php';
            if (!$isThemeFromPath && file_exists($file1)) {
                // we have a template in the page's theme matching the cHandle so render the page with that template.
                $filePath = $file1;
            } else {
                // No template in the page's theme or path-specified theme.
                // Get the inner content
                $filePath = $this->GetInnerContentPath($cFilename, $pkgHandle);
                if (empty($filePath)) {
                    $isFileMissing = 1;
                }
            }
        } else {
            if (!empty($ctHandle)) {
                // PAGE TYPE
                // Check for a Theme-independent page type.  These will be wrapped in view.php from the current theme.
                $filePath = $this->GetSpecialPageTypeInnerContentPath($ctHandle . '.php', $pkgHandle);
                if (!empty($filePath)) {
                    // Found a theme-independent path for the page type's inner content.
                    $wrapperPath = $ptDirectory . '/' . 'view.php';
                    if (empty($ptDirectory) || !file_exists($wrapperPath)) {
                        $isWrapperMissing = 1;
                    }
                } else {
                    // It's a regular page type.
                    if (empty($ptDirectory)) {
                        // page defaults (templates) don't specify a theme - they're rendered with the current site theme.
                        $ptDirectory = $this->defaultSiteTheme;
                        $cblPtID = $this->defaultSiteThemeCobbleID;
                    }
                    $wrapperPath = null;
                    $filePath = $this->GetRegularPageTypePath($ctHandle, $ptDirectory);
                    if (empty($filePath)) {
                        $isFileMissing = 1;
                    }
                }
            } else {
                if (!empty($ctID) && empty($ctID_ct) && !empty($ptDirectory)) {
                    // handle the case of a deleted page type
                    $filePath = $this->GetRegularPageTypePath('default', $ptDirectory);
                    if (empty($filePath)) {
                        $isFileMissing = 1;
                    }
                } else {
                    // not a single page and no page type specified, so it should be either an external link or an alias of another page.
                    if ($row['cPointerID'] == 0 && empty($row['cPointerExternalLink'])) {
                        $isFileMissing = 1;
                    }
                }
            }
        }

        // Actually insert the record into cobblePages
        $db = Loader::db();
        $v = array(
            $cID,
            $ctID,
            $cblPtID,
            $this->stdpath($filePath),
            $this->stdpath($wrapperPath),
            $isThemeFromPath,
            $isSinglePage,
            $cIsTemplate,
            $isWrapperMissing,
            $isFileMissing,
            $cFilename,
            $cHandle
        );
        $q = "insert into CobblePages (cID, ctID, cblPtID, filePath, wrapperPath, isThemeFromPath, isSinglePage, isTemplate, isWrapperMissing, isFileMissing, cFilename, cHandle) " .
            "values ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $db->query($q, $v);

    }

    function GetThemeForSinglePageWithThemeNotSet($cHandle)
    {
        if (file_exists(DIR_FILES_THEMES . '/' . 'default' . '/' . $cHandle)) {
            $theme = DIR_FILES_THEMES . "/" . 'default' . '/' . $cHandle;
        } else {
            if (file_exists(DIR_FILES_THEMES . '/' . 'default' . '/' . FILENAME_THEMES_VIEW)) {
                $theme = DIR_FILES_THEMES . "/" . 'default' . '/' . FILENAME_THEMES_VIEW;
            } else {
                if (file_exists(DIR_FILES_THEMES . '/' . DIRNAME_THEMES_CORE . '/' . 'default' . '.php')) {
                    $theme = DIR_FILES_THEMES . '/' . DIRNAME_THEMES_CORE . "/" . 'default' . '.php';
                } else {
                    if (file_exists(DIR_FILES_THEMES_CORE . "/" . 'default' . '/' . $cHandle)) {
                        $theme = DIR_FILES_THEMES_CORE . "/" . 'default' . '/' . $cHandle;
                    } else {
                        if (file_exists(DIR_FILES_THEMES_CORE . "/" . 'default' . '/' . FILENAME_THEMES_VIEW)) {
                            $theme = DIR_FILES_THEMES_CORE . "/" . 'default' . '/' . FILENAME_THEMES_VIEW;
                        } else {
                            if (file_exists(DIR_FILES_THEMES_CORE_ADMIN . "/" . 'default' . '.php')) {
                                $theme = DIR_FILES_THEMES_CORE_ADMIN . "/" . 'default' . '.php';
                            }
                        }
                    }
                }
            }
        }

        return $this->stdpath($theme);
    }

    // Get the path to the template for a regular page type
    function GetRegularPageTypePath($ctHandle, $ptDirectory)
    {
        $fileName = $ctHandle . '.php';
        if (file_exists($ptDirectory . '/' . $fileName)) {
            return ($ptDirectory . '/' . $fileName);
        } else {
            if (file_exists($ptDirectory . '/' . 'default.php')) {
                return $ptDirectory . '/' . 'default.php';
            } else {
                return null;
            }
        }
    }

    // Get the path to the inner content template for a theme-independent page type
    function GetSpecialPageTypeInnerContentPath($ctHandle, $pkgHandle)
    {

        if (file_exists(DIR_BASE . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php')) {
            $content = DIR_BASE . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php';
        } else {
            if (file_exists(DIR_BASE_CORE . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php')) {
                $content = DIR_BASE_CORE . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php';
            } else {
                if (!empty($pkgHandle)) {
                    $file1 = DIR_PACKAGES . '/' . $pkgHandle . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php';
                    $file2 = DIR_PACKAGES_CORE . '/' . $pkgHandle . '/' . DIRNAME_PAGE_TYPES . '/' . $ctHandle . '.php';
                    if (file_exists($file1)) {
                        $content = $file1;
                    } else {
                        if (file_exists($file2)) {
                            $content = $file2;
                        }
                    }
                } else {
                    return null;
                }
            }
        }

        return $content;
    }

    // Get the path to the inner content template for a single page
    function GetInnerContentPath($cFilename, $pkgHandle)
    {
        if (empty($cFilename)) {
            return null;
        }
        // locate inner content using the same rules as View->render()
        if (file_exists(DIR_FILES_CONTENT . "{$cFilename}")) {
            $filePath = DIR_FILES_CONTENT . "{$cFilename}";
        } else {
            if (!empty($pkgHandle)) {
                $file1 = DIR_PACKAGES . '/' . $pkgHandle . '/' . DIRNAME_PAGES . $cFilename;
                $file2 = DIR_PACKAGES_CORE . '/' . $pkgHandle . '/' . DIRNAME_PAGES . $cFilename;
                if (file_exists($file1)) {
                    $filePath = $file1;
                } else {
                    if (file_exists($file2)) {
                        $filePath = $file2;
                    }
                }
            } else {
                if (file_exists(DIR_FILES_CONTENT_REQUIRED . "{$cFilename}")) {
                    $filePath = DIR_FILES_CONTENT_REQUIRED . "{$cFilename}";
                }
            }
        }

        return $filePath;
    }

    // Get the path to the wrapper template for a single page
    // in the special case where the theme is mapped by the path
    function GetWrapperPath($cPath)
    {
        $pl = $this->getThemeFromPath($cPath);
        if (empty($pl)) {
            return null;
        } else {
            if (file_exists(DIR_FILES_THEMES . '/' . $pl . '/' . FILENAME_THEMES_VIEW)) {
                $theme = DIR_FILES_THEMES . "/" . $pl . '/' . FILENAME_THEMES_VIEW;
            } else {
                if (file_exists(DIR_FILES_THEMES . '/' . DIRNAME_THEMES_CORE . '/' . $pl . '.php')) {
                    $theme = DIR_FILES_THEMES . '/' . DIRNAME_THEMES_CORE . "/" . $pl . '.php';
                } else {
                    if (file_exists(DIR_FILES_THEMES_CORE . "/" . $pl . '/' . FILENAME_THEMES_VIEW)) {
                        $theme = DIR_FILES_THEMES_CORE . "/" . $pl . '/' . FILENAME_THEMES_VIEW;
                    } else {
                        if (file_exists(DIR_FILES_THEMES_CORE_ADMIN . "/" . $pl . '.php')) {
                            $theme = DIR_FILES_THEMES_CORE_ADMIN . "/" . $pl . '.php';
                        }
                    }
                }
            }

            return $theme;
        }
    }

    /**
     * This grabs the theme for a particular path, if one exists in the themePaths array
     * @access private
     * @param string $path
     * @return string $theme
     */
    private function getThemeFromPath($path)
    {
        // there's probably a more efficient way to do this
        $theme = false;
        $txt = Loader::helper('text');
        foreach ($this->hardThemePaths as $lp => $layout) {
            // Note: fnmatch checks if the passed string would match the given shell wildcard pattern.
            if ($txt->fnmatch($lp, $path)) {
                $theme = $layout;
                break;
            }
        }

        return $theme;
    }

    // Append new cobble records for any single pages not in the database (i.e. not installed)
    // Note: we only check single_pages directories.  The other kind of single page is indistinguishable from a page type
    // until it's installed.  Also, we can't specify the wrapper since that will depend on the theme assigned to the page
    // once it's installed.
    function AddFromFilesystem()
    {
        $db = Loader::db();
        $dbPages = array();
        $q = "select distinct filePath from CobblePages where filePath is not null and isFileMissing = 0";
        $r = $db->query($q);
        while ($row = $r->fetchRow()) {
            $dbPages[] = $row['filePath'];
        }
        foreach ($this->singlePagePaths as $p) {
            if (!in_array($p['filePath'], $dbPages)) {
                $this->AppendUninstalledPage($p);
            }
        }
    }

    // Append a single page cobblePage with the given filePath, cFilename and cHandle
    function AppendUninstalledPage($pageInfo)
    {
        extract($pageInfo);
        $db = Loader::db();
        $v = array($filePath, $cFilename, $cHandle, 1);
        $q = "insert into CobblePages (filePath, cFilename, cHandle, isSinglePage) " .
            "values ( ?, ?, ?, ?)";
        $db->query($q, $v);
    }

    function UpdateCobblePageType()
    {
        $db = Loader::db();
        $q = "update CobblePages cp inner join CobblePageTypes cpt on cp.ctID = cpt.ctID and cp.cblPtID = cpt.cblPtID set cp.cblCtID = cpt.cblCtID";
        $db->query($q);
    }

    // Pages based on theme-independent page types need some special handling.
    function UpdatePathsForThemeIndependentPageTypes()
    {
        $db = Loader::db();
        $q = "update CobblePages cp inner join CobblePageTypes cct on cp.ctID = cct.ctID and cct.cblPtID is NULL " .
            " set cp.filePath = cct.filePath, cp.wrapperPath = cct.wrapperPath, cp.cblCtID = cct.cblCtID";
        $db->query($q);
    }

    /****************************************************/
    /*               UTILITIES                          */
    /****************************************************/
    // remove quotes from around a token value
    function stripQuotes($s)
    {
        return str_replace("'", "", str_replace('"', '', $s));
    }

    // clean up the path to a consistent unix style absolute path
    function stdpath($path)
    {
        if (empty($path)) {
            return null;
        }

        return str_replace('\\', '/', realpath($path));
    }

    // List all subdirectories (except ., ..) in the specified directory.)
    // if $singlePageOnly is true, we only list subdirectories with basename 'single_pages'
    function listSubdirectories($dir, $singlePageOnly = false)
    {
        $dirList = array();
        $dirs = glob("$dir/*", GLOB_ONLYDIR);
        if (is_array($dirs)) {
            foreach ($dirs as $subdir) {
                if (($singlePageOnly == true && basename($subdir) == 'single_pages') || ($singlePageOnly == false && !in_array($subdir,
                            array('.', '..')))
                ) {
                    // the str_replace is to fix for windows.  Shouldn't be any backslashes in a unix path, right?
                    $dirList[] = $this->stdpath($subdir);
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
                    $tplList[] = $this->stdpath($file);
                }
            }
        }

        return $tplList;
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

        return (trim($tmp[sizeof($tmp) - 3]));
    }

    // remove whitespace tokens from a token array and re-pack.
    function RemoveWhitespace($ta)
    {
        $res = array();
        foreach ($ta as $val) {
            if (is_array($val)) {
                if ($val[0] == T_WHITESPACE) {
                    continue;
                }
            }
            $res[] = $val;
        }

        return $res;
    }

    /**
     * Returns a compact dump of a token array
     * @param $ta Token array to dump
     * @param Boolean $stripWhitespaces If true, T_WHITESPACE tokens are not included in returned array
     */
    function readableArray($ta, $stripWhitespaces = true)
    {
        while (list($key, $val) = each($ta)) {
            if (is_array($val)) {
                if ($stripWhitespaces && $val[0] == T_WHITESPACE) {
                    continue;
                }
                $val2 = $val[1] . ' - ' . token_name($val[0]) . ' : ' . $val[2];
                $res[$key] = $val2;
            } else {
                $res[$key] = $val;
            }
        }

        return $res;
    }// end readableArray


    /****************************************************/
    /*               DISPLAY FOR DEBUGGING              */
    /****************************************************/
    function displaySinglePagePaths()
    {
        echo $this->pp($this->singlePagePaths);
    }

    function displayHardThemePaths()
    {
        echo $this->pp($this->hardThemePaths);
    }

    // Pretty print array
    function pp($arr)
    {
        $retStr = '<ul>';
        if (is_array($arr)) {
            foreach ($arr as $key => $val) {
                if (is_array($val)) {
                    $retStr .= '<li>' . $key . ' => ' . $this->pp($val) . '</li>';
                } else {
                    $retStr .= '<li>' . $key . ' => ' . htmlspecialchars($val) . '</li>';
                }
            }
        }
        $retStr .= '</ul>';

        return $retStr;
    }

}

