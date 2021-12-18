<?php
require_once(__CA_MODELS_DIR__.'/ca_occurrences.php');

# ---------------------------------------------------------------------
/**
 * Do object group import from spreadsheet
 * @param  string $ps_xlsx   absolute path to spreadsheet
 * @return boolean success state
 */
function object_group_import($ps_xlsx) {
	$t_locale = new ca_locales();
	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		CLIUtils::addError("Invalid locale code ".__LHM_MMS_DEFAULT_LOCALE__);
		return false;
	}

	$o_excel = phpexcel_load_file($ps_xlsx);
	$o_sheet = $o_excel->getActiveSheet();
	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;

	mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, $vs_msg = "Objektgruppen/Konvolute werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num==1) continue; // skip row with headers

		print CLIProgressBar::next(1, $vs_msg);
		mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);

		$o_trans = new Transaction();
		$t_occ = new ca_occurrences();
		$t_occ->setTransaction($o_trans);

		// Objektgruppe/Konvolut ID ID
		$vs_idno = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));

		if(strlen($vs_idno)<1) {
			mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Die Objektgruppe/Konvolut ID ist leer für Zeile {$vn_row_num}. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		}

		if($t_occ->load(array('idno' => $vs_idno))){
			mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Die Objektgruppe/Konvolut ID für Zeile {$vn_row_num} existiert bereits. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		} else {
			$t_occ->set('idno',$vs_idno);
		}

		$t_occ->set('access',0);
		$t_occ->set('status',1);

		// Type ID
		$vs_type = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));

		if(!in_array(strtolower($vs_type), array('o','k'))){
			mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Der Ausstellungstyp ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_type'. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		}

		switch(strtolower($vs_type)){
			case 'o' :
				$vs_type_id = 'object_group';
				break;
			case 'k':
				$vs_type_id = 'lot';
				break;
			default:
				break;
		}

		$t_occ->set('type_id',$vs_type_id);

		// Beschreibung
		$vs_desc = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));

		if(strlen($vs_desc) > 0) {
			$t_occ->addAttribute(array(
				'description' => $vs_desc,
			),'description');
		}

		$t_occ->insert();

		if($t_occ->numErrors() > 0) {
			foreach ($t_occ->getErrors() as $vs_error) {
				mmsLog("Objektgruppen/Konvolute [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		// labels
		$vs_name = trim((string)$o_sheet->getCellByColumnAndRow(3, $vn_row_num));

		if(strlen($vs_name)<1){
			$vs_name = "[LEER]";
		}

		$t_occ->addLabel(array(
			'name' => $vs_name,
		),$vn_locale_id,null,true);

		$o_trans->commit();

		unset($o_trans);
		unset($t_occ);
		mmsGC();
	}

	print CLIProgressBar::finish();
}
# ---------------------------------------------------------------------
