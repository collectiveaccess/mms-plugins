<?php

require_once(__CA_MODELS_DIR__.'/ca_storage_locations.php');
# ---------------------------------------------------------------------
$s_storage_location_type_cache = array();
# ---------------------------------------------------------------------
/**
 * Execute storage location import from spreadsheet
 * @param string $ps_xlsx XLS/XLSX file containing data
 * @return bool success state
 */
function do_storage_location_import($ps_xlsx) {
	$t_locale = new ca_locales();
	global $vn_locale_id;
	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		CLIUtils::addError("Invalid locale code ".__LHM_MMS_DEFAULT_LOCALE__);
		return false;
	}

	// load as PHPExcel
	$o_excel = phpexcel_load_file($ps_xlsx);
	$o_sheet = $o_excel->getActiveSheet();

	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;
	print CLIProgressBar::start($vn_rows, $vs_msg = "Standorte werden aus Tabelle importiert ...");
	mmsLog("Standorte [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num==1) continue; // skip row with headers

		mmsLog("Standorte [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);
		$o_trans = new Transaction();

		// sicherstellen, dass keine Hierarchie-beziehungen aus dem letzten loop mitgeschleppt werden
		$pn_parent_id = null;
		$vn_col = 2;
		$va_idno_parts = array();

		while($vn_col <= 16) {

			$vs_aktuelle_ebene = $o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num)->getFormattedValue();

			if(strlen($vs_aktuelle_ebene)>0) {
				$vn_type_id = map_column_id_to_sl_type_id($o_sheet,$vn_col);
				$va_idno_parts[] = $vs_aktuelle_ebene;
				$pn_parent_id = insert_storage_loc(join('',$va_idno_parts),$vs_aktuelle_ebene,$vn_type_id,$pn_parent_id,$o_trans);

				if(!$pn_parent_id) { // Error wurde schon in Helferfunktion geloggt -> muessen hier nur Transaktion zurueckrollen
					$o_trans->rollback();
					continue 2;
				}

			}

			if($vn_col < 16) { // NACH dem letzten Label (in Spalten-ID 15) kommt kein Delimiter mehr
				$vs_delimiter = $o_sheet->getCellByColumnAndRow($vn_col+1, $vn_row_num)->getFormattedValue();
				if(strlen($vs_delimiter)>0) {
					$va_idno_parts[] = $vs_delimiter;
				}
			}

			$vn_col += 2;
		}

		// Die Daten aus den nicht ebenen-spezifischen Spalten gelten für die letzten Ebene ($pn_parent_id bei Exit der Schleife)
		$t_loc = new ca_storage_locations($pn_parent_id);
		$t_loc->setTransaction($o_trans);

		$vs_user_idno = trim($o_sheet->getCellByColumnAndRow(1, $vn_row_num)->getCalculatedValue());
		$t_loc->set('idno',$vs_user_idno);

		// Bemerkung
		$vs_bemerkung = trim((string)$o_sheet->getCellByColumnAndRow(17, $vn_row_num));
		if(strlen($vs_bemerkung)>0){
			$t_loc->addAttribute(array(
				'remark' => $vs_bemerkung,
			),'remark');
		}

		// Beschreibung
		$vs_beschreibung = trim((string)$o_sheet->getCellByColumnAndRow(18, $vn_row_num));
		if(strlen($vs_beschreibung)>0){
			$t_loc->addAttribute(array(
				'description' => $vs_beschreibung,
			),'description');
		}

		$t_loc->update();

		print CLIProgressBar::next(1, $vs_msg);
		$o_trans->commit();

		unset($o_trans);
		unset($t_loc);
		mmsGC();
	}

	return true;
}
# ---------------------------------------------------------------------
/**
 * Einfache Helferfunktion für SL Import
 */
function insert_storage_loc($vs_idno,$vs_label,$vs_type,$pn_parent_id=null,$po_transaction=null){
	global $vn_locale_id;

	mmsLog(
		"Standorte: Lege Standort an. Parameter sind: idno:{$vs_idno} | label:{$vs_label} ".
		"| type:{$vs_type} | parent_id(optional):{$pn_parent_id}", Zend_Log::DEBUG
	);

	$t_loc = new ca_storage_locations();
	$t_loc->setTransaction($po_transaction);
	$t_loc->setMode(ACCESS_WRITE);

	if(!($t_loc->load(array("idno" => $vs_idno)))){
		$t_loc->set('idno',$vs_idno);
		$t_loc->set('type_id',$vs_type);
		$t_loc->set('access',0);
		$t_loc->set('status',1);

		if(!$pn_parent_id){
			$pn_parent_id = $t_loc->getHierarchyRootID();
		}

		$t_loc->set('parent_id',$pn_parent_id);
		$t_loc->insert();

		// add label if insert was successful
		if($t_loc->getPrimaryKey()){
			$t_loc->addLabel(array(
				'name' => $vs_label,
			),$vn_locale_id,null,true);

			if($t_loc->numErrors()>0){
				mmsLog("Standorte: Erstellen von Standort mit ID '$vs_idno' und Typ '$vs_type' fehlgeschlagen. API Nachricht: ".join(" ",$t_loc->getErrors()), Zend_Log::WARN);
				return false;
			}

			return $t_loc->getPrimaryKey();
		} else {
			mmsLog("Standorte: Erstellen von Standort mit ID '$vs_idno' und Typ '$vs_type' fehlgeschlagen. API Nachricht: ".join(" ",$t_loc->getErrors()), Zend_Log::WARN);
			return false;
		}
	} else {
		return $t_loc->getPrimaryKey();
	}
}
# ---------------------------------------------------------------------
function map_column_id_to_sl_type_id($po_sheet,$pn_col){
	global $s_storage_location_type_cache;

	if(isset($s_storage_location_type_cache[$pn_col])) {
		return $s_storage_location_type_cache[$pn_col];
	}

	$vs_column_label = trim((string)$po_sheet->getCellByColumnAndRow($pn_col, 1));
	$t_list = new ca_lists();

	$va_item = $t_list->getItemFromListByLabel('storage_location_types', $vs_column_label);

	$vm_return = (isset($va_item['item_id']) ? $va_item['item_id'] : false);

	return ($s_storage_location_type_cache[$pn_col] = $vm_return);
}
# ---------------------------------------------------------------------
