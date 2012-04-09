<?php 
defined('C5_EXECUTE') or die(_("Access Denied."));

class CobbleArea extends Object {
    
  public $templatePaths;
  
  // refresh the CobbleAreas table.
  function refresh() {
    $this->initialize();
    $this->AddFromDatabase();
    $this->AddFromDatabase_LayoutAreas();
    $this->AddFromDatabase_GlobalScrapbooks();
    // just to be extra sure.. probably not needed
    $this->UpdateAreaIDs();
    
    $this->AddFromDatabase_RemainingAreas();
    
    $this->UpdateIsHandleDuplicated();
  }
  /****************************************************/
  /*               INITIALIZATION                     */
  /****************************************************/
  
  function initialize() {
    // clear the CobbleTemplateAreas table
    $this->truncate();
  }

  // Clear the table
  function truncate() {
    $db = Loader::db();
    $q = "truncate table CobbleAreas";
		$db->query($q);
  }
  
  
  /****************************************************/
  /*               DB INSERTS AND UPDATES             */
  /****************************************************/
  // Get a recordset with unique paths from CobblePages in the database that have a cID set
  function AddFromDatabase() {
    $db = Loader::db();
    $q = "insert into CobbleAreas (cID, cblCID, cblTaID, arHandle, arID) " .
      " select cp.cID, cp.cblCID, cta.cblTaID, cta.arHandle, a.arID " .
      " from (CobblePages cp inner join CobbleTemplateAreas cta on cp.filePath = cta.filePath) " .
      " left join Areas a on cp.cID = a.cID and cta.arHandle collate utf8_general_ci = a.arHandle collate utf8_general_ci " .
      " union select cp.cID, cp.cblCID, cta.cblTaID, cta.arHandle, a.arID " .
      " from (CobblePages cp inner join CobbleTemplateAreas cta on cp.wrapperPath = cta.filePath) " .
      " left join Areas a on cp.cID = a.cID and cta.arHandle collate utf8_general_ci = a.arHandle collate utf8_general_ci " .
      " order by cID, arHandle";
    $db->query($q);    
  }
  
  function AddFromDatabase_LayoutAreas() {
    $db = Loader::db();
    $q = "insert into CobbleAreas (cID, cblCID, cblTaID, arHandle, arID) " .
      "select ca.cID, ca.cblCID, ca.cblTaID, a.arHandle, a.arID " .
      " from CobbleAreas ca inner join Areas a " .
      " on concat(ca.arHandle, ' :') collate utf8_general_ci = left(a.arHandle, length(ca.arHandle) + 2) collate utf8_general_ci " .
      " and ca.cID = a.cID";
    $db->query($q);    
  }
  
  function AddFromDatabase_GlobalScrapbooks() {
    $db = Loader::db();
    $q = "insert into CobbleAreas (arHandle, arID, isGlobalScrapbook) " .
      " SELECT a.arHandle, a.arID, 1 FROM CobblePages cp inner join Areas a on cp.cID = a.cID WHERE cHandle = 'scrapbook'";
    $db->query($q);    
  }
  
  function UpdateAreaIDs() {
    $db = Loader::db();
    $q = "update CobbleAreas ca inner join Areas a " .
    " on ca.arHandle collate utf8_general_ci = a.arHandle collate utf8_general_ci and ca.cID = a.cID " .
    " set ca.arID = a.arID ";
    $db->query($q);
  }
  
  function AddFromDatabase_RemainingAreas() {
    $db = Loader::db();
    $q = "insert into CobbleAreas (arHandle, arID, cID, cblCID) " .
      " select a.arHandle, a.arID, a.cID, cp.cblCID " .
      " from (Areas a left join CobbleAreas ca on a.arID = ca.arID ) " .
      " left join CobblePages cp on a.cID = cp.cID " .
      " where ca.arID is null ";
    $db->query($q);    
  }
  
  function UpdateIsHandleDuplicated() {
    $db = Loader::db();
    $q = "SELECT cID, arHandle, count(cblArID) FROM CobbleAreas group by cID, arHandle having count(cblArID) > 1";
    $r = $db->query($q);
    while($row = $r->fetchRow()) {
      extract($row);
      $v = array($cID, $arHandle);
      $q1 = "update CobbleAreas set isHandleDuplicated = 1 where cID = ? and arHandle = ? ";
      $db->query($q1, $v);
    }    
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

