<?php
# ---------------------------------------------------------------------
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_list_items.php');
# ---------------------------------------------------------------------
function keyword_import($ps_xlsx) {
	$t_locale = new ca_locales();

	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		return false;
	}

	$t_list = new ca_lists();

	$vn_rows = count_nonempty_rows($ps_xlsx) - 2;

	$o_excel = phpexcel_load_file($ps_xlsx);
	$o_sheet = $o_sheet = $o_excel->getActiveSheet();

	mmsLog("Schlagworte [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, $vs_msg = "Schlagworte werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num == 1 || $vn_row_num == 2) continue; // headers

		mmsLog("Schlagworte [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);
		$o_trans = new Transaction();

		$vs_list_code = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));

		if(!$t_list->load(array('list_code' => $vs_list_code))){
			mmsLog("Schlagworte [{$ps_xlsx}]: Listen Code '$vs_list_code' für Zeile $vn_row_num nicht gefunden. Die Zeile wird übersprungen.", Zend_Log::WARN);
			continue;
		}

		$vn_current_singular_ptr = 2;
		$vn_last_level_id = null;

		do {
			$vs_lvl_name_singular = trim((string)$o_sheet->getCellByColumnAndRow($vn_current_singular_ptr, $vn_row_num));

			if(strlen($vs_lvl_name_singular)<1) { break; }

			$vs_lvl_name_plural = trim((string)$o_sheet->getCellByColumnAndRow($vn_current_singular_ptr+1, $vn_row_num));
			$vs_lvl_code = trim((string)$o_sheet->getCellByColumnAndRow($vn_current_singular_ptr+2, $vn_row_num));
			$vs_lvl_enabled = trim((string)$o_sheet->getCellByColumnAndRow($vn_current_singular_ptr+3, $vn_row_num));
			$vs_lvl_default = trim((string)$o_sheet->getCellByColumnAndRow($vn_current_singular_ptr+4, $vn_row_num));

			$vn_last_level_id = import_keyword_level($vn_locale_id,$vs_list_code,$vs_lvl_name_singular,$vs_lvl_name_plural,$vs_lvl_code,$vs_lvl_enabled,$vs_lvl_default,$vn_last_level_id,$o_trans);

			$vn_current_singular_ptr += 5;
		} while(strlen($vs_lvl_name_singular)>0);

		$o_trans->commit();
		unset($o_trans);
		mmsGC();

		print CLIProgressBar::next(1, $vs_msg);
	}
	mmsLog("Schlagworte: Import beendet", Zend_Log::INFO);
	print CLIProgressBar::finish();
}
# ---------------------------------------------------------------------
function import_keyword_level($pn_locale_id,$ps_list_code,$ps_singular,$ps_plural,$ps_idno,$ps_enabled,$ps_default,$pn_parent_id=null,$po_transaction=null){
	mmsLog(
		"Schlagworte: importiere Hierarchie-Level. Parameter sind: locale:{$pn_locale_id} | list_code:{$ps_list_code} ".
		"| singular:{$ps_singular} | plural:{$ps_plural} | idno:{$ps_idno} | enabled:{$ps_enabled} ".
		"| default:{$ps_default} | parent_id:{$pn_parent_id}", Zend_Log::DEBUG
	);

	$va_values = array();
	if(strlen($ps_singular) < 1) return false; // no name -> no valid row

	$va_values['name_singular'] = $ps_singular;
	$va_values['name_plural'] = $ps_plural;

	if(strlen($ps_idno)>0){
		$vs_item_code = $ps_idno;
	} else {
		$vs_item_code = $ps_singular;
	}

	if(strtolower($ps_enabled)=='nein') {
		$vn_enabled = 0;
	} else {
		$vn_enabled = 1;
	}

	if(strtolower($ps_default)=='x') {
		$vn_default = 1;
	} else {
		$vn_default = 0;
	}

	$va_values['is_enabled'] = $vn_enabled;
	$va_values['default'] = $vn_default;

	if($pn_parent_id){
		$va_values['parent_id'] = $pn_parent_id;
	}

	return DataMigrationUtils::getListItemID($ps_list_code, $vs_item_code, 'concept', $pn_locale_id, $va_values, array('transaction' => $po_transaction));
}
# ---------------------------------------------------------------------
