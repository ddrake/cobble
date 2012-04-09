<?php 
defined('C5_EXECUTE') or die(_("Access Denied."));

class DashboardCobbleController extends Controller {
	
	public $helpers = array('form');
	
	function view() {
    Loader::model('cobble','cobble');
    $cbl = new Cobble();
    $this->setupForm($cbl);
	}
  
  // Send the specified query results as a CSV file download.
  public function send_csv() {
    Loader::model('cobble','cobble');
    $cbl = new Cobble();
		$query_info = $_REQUEST['query_info'];
		$query_diag = $_REQUEST['query_diag'];
    
    if (empty($query_info) && empty($query_diag)) {
      $this->setupForm($cbl);
    } else {
      $query = empty($query_info) ? $query_diag : $query_info;
      $results = $cbl->GetQueryResult($query);
      if (!empty($query_info)) {
        $colHeads = $cbl->QueryColHeads_info($query);
      } else {
        $colHeads = $cbl->QueryColHeads_diag($query);        
      }
      $csv = $this->sputcsv($colHeads, $results);
      header('Content-type: application/excel');
      header('Content-Disposition: attachment; filename="' . $query . '.csv"');
      echo $csv;
      exit();
    }
  }
  
  // Set up the form data for the single page
  private function setupForm($cbl) {
    $queries_info = array_merge(array('0' => '-- Please Select --'), $cbl->QueryNames_info());
    $queries_diag = array_merge(array('0' => '-- Please Select --'), $cbl->QueryNames_diag());
    $this->set('queries_info',$queries_info);
    $this->set('queries_diag',$queries_diag);
    $this->set('title', t('Cobble - A Diagnostic Tool for Concrete 5'));
		$query_info = $_REQUEST['query_info'];
		$query_diag = $_REQUEST['query_diag'];
    if (empty($query_info) && empty($query_diag)) {
      $cbl->refresh();   
      $this->set('query_info', 0);
      $this->set('query_diag', 0);
    } else if (!empty($query_info)) {
      $this->set('query_info', $query_info);
      $this->set('query_diag', 0);
      $this->set('queryName', $cbl->QueryName_info($query_info));
      $this->set('results', $cbl->GetQueryResult($query_info));
      $this->set('colHeads', $cbl->QueryColHeads_info($query_info));
      $this->set('description', $cbl->QueryDescription_info($query_info));
    } else {
      $this->set('query_diag', $query_diag);
      $this->set('query_info', 0);
      $this->set('queryName', $cbl->QueryName_diag($query_diag));
      $this->set('results', $cbl->GetQueryResult($query_diag));
      $this->set('colHeads', $cbl->QueryColHeads_diag($query_diag));
      $this->set('description', $cbl->QueryDescription_diag($query_diag));
    }
  }
  
  // A quick and dirty method for converting query results to a csv formatted string.
  // Don't pass us "*" as your enclosure character!!
  function sputcsv($colHeads, $data, $delimiter = ',', $enclosure = '"', $eol = "\n")
  {
    $rslt = '';
    foreach($colHeads as $head)
    {
      $rslt .= $this->enclose($head, $enclosure) . $delimiter;
    }
    $rslt = $this->swapTrail($rslt,$eol);
    foreach($data as $row) {
      foreach ($row as $col) {
        $rslt .= $this->enclose($col, $enclosure) . $delimiter;
      }
      $rslt = $this->swapTrail($rslt,$eol);
    }
    return $rslt;
  }

  // Replace the last character in a string with the provided end-of-line string
  private function swapTrail($txt, $eol) {
    return substr($txt,0,strlen($txt)-1) . $eol;
  }
  // Enclose some text in enclosure characters.  If the text contains the enclosure
  // character, it will be replaced by "*"
  private function enclose($txt, $enclosure) {
    return $enclosure . str_replace($enclosure, "*", $txt) . $enclosure;
  }

}

