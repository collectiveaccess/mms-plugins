#!/usr/bin/env php
<?php
/**
 * Helferscript zur Übertragung von Bundle-Level Access Eigenschaften aus OpenOffice CALC Tabelle ("Profilvorlage_Prototype") in CollectiveAccess
 * zum späteren Export in XML-Profil.
 */

$_SERVER['HTTP_HOST'] = "mmsd001.stm.kul.muenchen.de";

require_once('../setup.php');

$g_ui_locale = "de_DE";
initializeLocale($g_ui_locale);

require_once(__CA_MODELS_DIR__.'/ca_editor_ui_screens.php');
require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');
require_once(__CA_MODELS_DIR__.'/ca_user_roles.php');

// build big array with relevant bundlable stuff
$va_mms_tables = array('ca_objects', 'ca_entities', 'ca_occurrences', 'ca_collections', 'ca_object_lots', 'ca_storage_locations', 'ca_loans', 'ca_object_representations', 'ca_representation_annotations');
$va_bundle_list = array();
$t_screen = new ca_editor_ui_screens();
$o_dm = Datamodel::load();
foreach($va_mms_tables as $vs_table) {
	$t_instance = $o_dm->getInstanceByTableName($vs_table, true);

	$va_available_bundles = $t_screen->getAvailableBundles($vs_table);
	foreach($va_available_bundles as $vs_bundle_name => $va_bundle_info) {
					
		$vn_access = isset($va_bundle_access_settings[$vs_table.'.'.$vs_bundle_name]) ? $va_bundle_access_settings[$vs_table.'.'.$vs_bundle_name] : $vn_default_bundle_access_level;
		$va_bundle_list[trim(strip_tags($va_bundle_info['display']))] = $va_bundle_info;
		$va_bundle_list[trim(strip_tags($va_bundle_info['display']))]['table'] = $vs_table;
	}
}

$vs_dm = "/home/stefan/Profilvorlage_Prototype_V4.2.2.ods";

print "Loading ODS ...\n";

/**  Create a new Reader of the type that has been identified  **/

$o_excel = \PhpOffice\PhpSpreadsheet\IOFactory::load($vs_dm);
$o_sheet = $o_excel->getSheetByName('Rollen-Feldzugriff');

print "Got sheet from ODS ...\n";

$vn_row = 0;
$va_column_nums_to_role_codes = array();
$va_rw_bundles = array();
foreach ($o_sheet->getRowIterator() as $o_row) {
	if ($vn_row++ == 0) {	// first row (headers)

		for($i=1; $i<=30; $i++) {
			$vs_potential_code = trim((string)$o_sheet->getCellByColumnAndRow($i, $vn_row));
			if(strlen($vs_potential_code)>0) {
				$va_column_nums_to_role_codes[$i] = $vs_potential_code;	
			}
		}

		continue;
	}

	$vs_bundle_label = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row));
	if(strlen($vs_bundle_label) == 0) { continue; } // skip empty rows
	if(!isset($va_bundle_list[$vs_bundle_label])) { continue; } // skip rows we can't find

	// loop through all columns
	foreach($va_column_nums_to_role_codes as $vn_i => $vs_code) {
		if(trim((string)$o_sheet->getCellByColumnAndRow($vn_i, $vn_row)) == "l/s") {
			$va_rw_bundles[$vs_code][] = array(
				'table' => $va_bundle_list[$vs_bundle_label]['table'],
				'bundle' => $va_bundle_list[$vs_bundle_label]['bundle']
			);
		}
	}
}

foreach($va_rw_bundles as $vs_role_code => $va_bundles) {
	$t_role = new ca_user_roles();
	if(!$t_role->load(array('code' => $vs_role_code))) { print "Couldn't load role $vs_role_code\n"; continue; }
	foreach($va_bundles as $va_bundle){
		$t_role->setAccessSettingForBundle($va_bundle['table'], $va_bundle['bundle'], __CA_BUNDLE_ACCESS_EDIT__);
	}
	unset($t_role);
}
