<?php
// Prueba: ¿qué devuelve getObjectsInCateg para la categoría 2 (Conos)?
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');
define('NOTOKENRENEWAL', '1');
require 'C:/laragon/www/sabrooski/main.inc.php';

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

$catId = (int) (isset($argv[1]) ? $argv[1] : 2);

$object = new Categorie($db);
$result = $object->fetch($catId);
echo "fetch categoria $catId => result=$result\n";
if ($result > 0) {
	$prods = $object->getObjectsInCateg("product", 0, 0, 0, 'label', 'ASC', '(o.tosell:=:1)');
	if (is_array($prods)) {
		echo "productos: ".count($prods)."\n";
		foreach ($prods as $p) {
			echo " - id={$p->id} ref={$p->ref} label={$p->label} price_ttc={$p->price_ttc}\n";
		}
	} else {
		echo "error: ".$object->error."\n";
	}
} else {
	echo "categoria no encontrada\n";
}
$db->close();
