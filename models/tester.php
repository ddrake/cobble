<?php

Loader::model('cobble_page_theme');
$cpt = new CobblePageTheme();
$cpt->refresh();
  
Loader::model('cobble_page_type');
$cct = new CobblePageType();
$cct->refresh();

Loader::model('cobble_page');
$cp = new CobblePage();
$cp->refresh();
  
  
Loader::model('cobble_page');
$cp = new CobblePage();
 
$b = 'C:/wamp/www/NewCadbury/single_pages';  
$c = 'C:/wamp/www/NewCadbury/single_pages/dashboard/example_faq';  
$s = 'C:/wamp/www/NewCadbury/single_pages/dashboard/example_faq/view.php';
$cp->pp($cp->getcFilename($b, $c, $s));

$b = 'C:/wamp/www/NewCadbury/single_pages';  
$c = 'C:/wamp/www/NewCadbury/single_pages';  
$s = 'C:/wamp/www/NewCadbury/single_pages/_help.php';
$cp->pp($cp->getcFilename($b, $c, $s));


Loader::model('cobble_template_area');
$cta = new CobbleTemplateArea();
$cta->refresh();
 
Loader::model('cobble_area');
$ca = new CobbleArea();
$ca->refresh();
 
 
select cp.cID, cp.cblCID, cta.cblTaID, cta.arHandle from CobblePages cp inner join CobbleTemplateAreas cta on cp.filePath = cta.filePath 
union all select cp.cID, cp.cblCID, cta.cblTaID, cta.arHandle from CobblePages cp inner join CobbleTemplateAreas cta on cp.wrapperPath = cta.filePath
order by cID, arHandle
?>