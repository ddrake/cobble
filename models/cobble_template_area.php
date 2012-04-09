<?php 
defined('C5_EXECUTE') or die(_("Access Denied."));

class CobbleTemplateArea extends Object {
    
  public $templatePaths;
  
  // refresh the CobbleTemplateAreas table.
  function refresh() {
    $this->initialize();
    $this->AddFromCobblePages();
  }
  /****************************************************/
  /*               INITIALIZATION                     */
  /****************************************************/
  
  function initialize() {
    // clear the CobbleTemplateAreas table
    $this->truncate();
    $this->ListTemplatePaths();
    //$this->DisplayTemplatePaths();
  }

  // Clear the table
  function truncate() {
    $db = Loader::db();
    $q = "truncate table CobbleTemplateAreas";
		$db->query($q);
  }
  
  function ListTemplatePaths() {
    $db = Loader::db();
    $this->templatePaths = array();
    
    $q = "select distinct filePath from CobblePages where cID is not null and filePath is not null " .
      " union select distinct wrapperPath as filePath from CobblePages where cID is not null and wrapperPath is not null " .
      " order by filePath";
    $r = $db->query($q);    
		while($row = $r->fetchRow()) {
      extract($row);
      if (!empty($filePath)) {
        $this->templatePaths[] = $filePath;
      }
    }    
  }
  
  function DisplayTemplatePaths() {
    foreach ($this->templatePaths as $filePath) {
      echo $filePath . "<br />";
    }    
  }
  
  /****************************************************/
  /*               DB INSERTS AND UPDATES             */
  /****************************************************/
  // Get a recordset with unique paths from CobblePages in the database that have a cID set
  function AddFromCobblePages() {
    foreach ($this->templatePaths as $filePath) {
      $this->AddForFilePath($filePath);
    }    
  }
  
  // Given the path to a template, parse it for areas and inc()'d areas
  // each element of the areas array contains a className, arHandle and templatePath
  function AddForFilePath($filePath) {
    // Parse the path
    $db = Loader::db();
    
    $areas = $this->GetAreasForPageType($filePath);
    foreach ($areas as $area) {
      extract($area);
      // Insert a record into cobbleTemplateAreas
      $v = array($arHandle, $filePath, $className == 'GlobalArea' ? 1 : 0, $templatePath);
      $q = "insert into CobbleTemplateAreas (arHandle, filePath, isGlobal, globalTemplatePath) " .
            "values ( ?, ?, ?, ?)";
      $db->query($q, $v);    
    }
  }
  
  // Recursive function to get all areas for a specific template
  function GetAreasForPageType($filePath) {
    // get the theme path
    $dir = dirname($filePath);
    
    // read the template into a string
    $input = file_get_contents($filePath);
    
    // tokenize the string
    $tokens = token_get_all($input);
  
    // remove whitespace tokens and pack
    $tokens = $this->RemoveWhitespace($tokens);
    
    // get the results array which contains sub-arrays $areas and $includes
    $results = $this->AreasAndIncludes($tokens);
    extract($results);
    
    // iterate through the includes, recursively calling this function to get any areas
    foreach ($includes as $tpl) {
      $incPath = $dir . '/' . $tpl;
      $new_areas = $this->GetAreasForPageType($incPath);
      $areas = array_merge($areas, $new_areas);
    }
    return $areas;
  }

  // Get an array containing two sub-arrays, one with info on any new Areas or GlobalAreas, the other with info on included files.
  function AreasAndIncludes($ta){
    $ct = count($ta);
    $i = 0;
    $areas = array();
    $includes = array();
    
    while ($i < $ct) {
      $val = $ta[$i];
      if(is_array($val)){
        if($val[0] == T_NEW) {
          $tmp = $this->getAreaInfo($ta, $i);
          if (!empty($tmp)) {
            $areas[] = $tmp;
          }
        }
        elseif($val[0] == T_VARIABLE && $val[1] == '$this') {
          $tmp = $this->getIncludeInfo($ta, $i);
          if (!empty($tmp)) {
            $includes[] = $tmp;
          }
        }
      }
      $i++;
    }
    return array('areas' => $areas, 'includes' => $includes);
  }

  // Given a token array and the index of a T_INCLUDE, T_INCLUDE_ONCE, etc... sub-array, return an include path
  // We expect a T_VARIABLE, a T_OBJECT_OPERATOR, a T_STRING, a left paren, a T_CONSTANT_ENCAPSED_STRING and a right paren
  function getIncludeInfo($ta, $i_start) {
    $ct = count($ta);
    $i = $i_start;
    if ($i > $ct-6) return null;   
    $val = $ta[$i];
    // Ensure first token is $this
    if (!is_array($val) || $val[0] != T_VARIABLE || $val[1] != '$this') {
      return null;
    }
    // Ensure next token is the object operator ->
    $i++;
    $val = $ta[$i];   
    if (!is_array($val) || $val[0] != T_OBJECT_OPERATOR || $val[1] != '->') {
      return null;
    }
    // Ensure next token is a T_STRING with value 'inc'
    $i++;
    $val = $ta[$i];   
    if (!is_array($val) || $val[0] != T_STRING || $val[1] != 'inc') return null;
    
    // Ensure next token is a left parenthesis
    $i++;
    $val = $ta[$i];   
    if ($val != '(' ) return null;

    // Ensure next token is a T_CONSTANT_ENCAPSED_STRING -- currently not supporting variables here...
    $i++;
    $val = $ta[$i];   
    if (!is_array($val) || $val[0] != T_CONSTANT_ENCAPSED_STRING) return null;
    $ret = $this->stripQuotes($val[1]);
    
    // Ensure next token is either a right parenthesis or a comma
    $i++;
    $val = $ta[$i];   
    if ($val != ')' && $val != ',' ) return null;
    return $ret;
  
    // I think that's good enough for now. we don't care about the args...
  }

  // Given a token array and the index of a T_NEW sub-array,
  // return an array with [class_name, arHandle, template_path (optional)] or null
  // We need at least a T_NEW, a T_STRING, an open paren, an arHandle and a close paren.
  function getAreaInfo($ta, $i_start) {
    $ct = count($ta);
    $i = $i_start;
    $done = false;
    $val = $ta[$i];
    $ret = array();
    if ($i > $ct-5) return null;   
    // Ensure first token is a T_NEW
    if (!is_array($val) || $val[0] != T_NEW) return null;
    
    // Ensure next token is a T_STRING with value either Area or GlobalArea
    $i++;
    $val = $ta[$i];
    if (!is_array($val) || $val[0] != T_STRING || !in_array($val[1],array('Area','GlobalArea'))) return null; 
    // add the class name to the return array
    $ret['className'] = $val[1];
    
    // Ensure next token is a left parenthesis
    $i++;
    $val = $ta[$i];   
    if ($val != '(' ) return null;
    
    // Ensure next token is a T_CONSTANT_ENCAPSED_STRING
    $i++;
    $val = $ta[$i];   
    if (!is_array($val) || $val[0] != T_CONSTANT_ENCAPSED_STRING) return null;
    //add the arHandle
    $ret['arHandle'] = $this->stripQuotes($val[1]);
    
    // Ensure next token is either a comma or a right parenthesis
    $i++;
    $val = $ta[$i];   
    if ($val == ')') {
      return $ret;
    }
    elseif ($val != ',') {
      return null;
    }

    // Ensure next token is a T_CONSTANT_ENCAPSED_STRING
    $i++;
    $val = $ta[$i];
    if (!is_array($val) || $val[0] != T_CONSTANT_ENCAPSED_STRING) return null;
    
     //add the template_path
    $ret['templatePath'] = $this->stripQuotes($val[1]);

    // Ensure next token is a right parenthesis
    $i++;
    $val = $ta[$i];   
    if ($val == ')') {
      return $ret;
    }
    else return null;
  }

  /****************************************************/
  /*               UTILITIES                          */
  /****************************************************/

  function stripQuotes($str) {
    return substr($str, 1, strlen($str)-2);
  }
  
  /**
    Returns a compact dump of a token array
    @param $ta Token array to dump
    @param Boolean $stripWhitespaces If true, T_WHITESPACE tokens are not included in returned array
  */
  function readableArray($ta, $stripWhitespaces=true){
    while(list($key, $val) = each($ta)){
      if(is_array($val)){
        if($stripWhitespaces && $val[0] == T_WHITESPACE) continue;
        $val2 = $val[1] . ' - ' . token_name($val[0]) . ' : ' .  $val[2];
        $res[$key] = $val2;
      }
      else{
        $res[$key] = $val;
      }
    }
    return $res;
  }// end readableArray

  // remove whitespace and re-pack.
  function RemoveWhitespace($ta) {
    $res = array();
    foreach ($ta as $val) {
      if(is_array($val)){
        if($val[0] == T_WHITESPACE) continue;
      }
      $res[] = $val;
    }
    return $res;
  }
  

  /****************************************************/
  /*               DISPLAY FOR DEBUGGING              */
  /****************************************************/
  // Pretty print array
  function pp($arr){
      $retStr = '<ul>';
      if (is_array($arr)){
          foreach ($arr as $key=>$val){
              if (is_array($val)){
                  $retStr .= '<li>' . $key . ' => ' . $this->pp($val) . '</li>';
              }else{
                  $retStr .= '<li>' . $key . ' => ' . htmlspecialchars($val) . '</li>';
              }
          }
      }
      $retStr .= '</ul>';
      return $retStr;
  }
  

}

