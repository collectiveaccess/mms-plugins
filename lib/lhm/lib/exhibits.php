<?php
require_once(__CA_MODELS_DIR__.'/ca_occurrences.php');

# ---------------------------------------------------------------------
/**
 * Do exhibit import from spreadsheet
 * @param  string $ps_xlsx   absolute path to spreadsheet
 * @return boolean success state
 */
function exhibit_import($ps_xlsx) {
	$t_locale = new ca_locales();
	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		CLIUtils::addError("Invalid locale code ".__LHM_MMS_DEFAULT_LOCALE__);
		return false;
	}

	$o_excel = phpexcel_load_file($ps_xlsx);
	$o_sheet = $o_excel->getActiveSheet();
	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;

	$o_tep = new TimeExpressionParser(null,'de_DE');

	mmsLog("Ausstellungen [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, $vs_msg = "Ausstellungen werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num==1) continue; // skip row with headers

		print CLIProgressBar::next(1, $vs_msg);

		$o_trans = new Transaction();
		$t_occ = new ca_occurrences();
		$t_occ->setTransaction($o_trans);
		$t_occ->setMode(ACCESS_WRITE);

		// Ausstellungs ID
		$vs_exhibit_idno = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row_num));

		if(strlen($vs_exhibit_idno)<1) {
			mmsLog("Ausstellungen [{$ps_xlsx}]: Die Ausstellungs_ID ist leer für Zeile {$vn_row_num}. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		}

		if($t_occ->load(array('idno' => $vs_exhibit_idno))){
			mmsLog("Ausstellungen [{$ps_xlsx}]: Die Ausstellungs_ID für Zeile {$vn_row_num} existiert bereits. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		} else {
			$t_occ->set('idno',$vs_exhibit_idno);
		}

		$t_occ->set('access',0);
		$t_occ->set('status',1);
		$t_occ->set('type_id','exhibition');

		// Ausstellungstyp
		$vs_type = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));
		$vs_type = mmsGetListItemIDByLabel('exhibition_type_list',$vs_type,'intern');

		$t_occ->addAttribute(array(
			'exhibition_type' => $vs_type,
		),'exhibition_type');

		// Ausstellungsdauer
		$vs_duration = mmsGetDateTimeColumnFromSheet($o_sheet, 3, $vn_row_num);

		if(strlen($vs_duration) > 0) {
			if(!$o_tep->parse($vs_duration)) {
				mmsLog("Ausstellungen [{$ps_xlsx}]: Die Ausstellungsdauer ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_duration' und wurde ignoriert", Zend_Log::WARN);
				$vs_duration = null;
			}

			$t_occ->addAttribute(array(
				'exhibition_duration' => $vs_duration,
			),'exhibition_duration');
		}

		// Viele Textfelder

		$vs_place = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));
		if(strlen($vs_place)>0){
			$t_occ->addAttribute(array(
				'exhibition_place' => $vs_place,
			),'exhibition_place');
		}

		$vs_institution = trim((string)$o_sheet->getCellByColumnAndRow(5, $vn_row_num));
		if(strlen($vs_institution)>0){
			$t_occ->addAttribute(array(
				'exhibition_institution' => $vs_institution,
			),'exhibition_institution');
		}

		$vs_add_places = trim((string)$o_sheet->getCellByColumnAndRow(6, $vn_row_num));
		if(strlen($vs_add_places)>0){
			$t_occ->addAttribute(array(
				'exhibition_additonal_places' => $vs_add_places,
			),'exhibition_additonal_places');
		}

		$vs_curator = trim((string)$o_sheet->getCellByColumnAndRow(7, $vn_row_num));
		if(strlen($vs_curator)>0){
			$t_occ->addAttribute(array(
				'exhibition_curator' => $vs_curator,
			),'exhibition_curator');
		}

		$vs_catalogue = trim((string)$o_sheet->getCellByColumnAndRow(8, $vn_row_num));
		if(strlen($vs_catalogue)>0){
			$t_occ->addAttribute(array(
				'exhibition_catalogue' => $vs_catalogue,
			),'exhibition_catalogue');
		}

		$vs_remark = trim((string)$o_sheet->getCellByColumnAndRow(9, $vn_row_num));
		if(strlen($vs_remark)>0){
			$t_occ->addAttribute(array(
				'remark' => $vs_remark,
			),'remark');
		}

		$t_occ->insert();

		if($t_occ->numErrors() > 0) {
			foreach ($t_occ->getErrors() as $vs_error) {
				mmsLog("Ausstellungen [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		// labels
		$vs_name = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));

		if(strlen($vs_name)<1){
			$vs_name = "[LEER]";
		}

		$t_occ->addLabel(array(
			'name' => $vs_name,
		),$vn_locale_id,null,true);

		$o_trans->commit();

		unset($t_occ);
		unset($o_trans);
		mmsGC();
	}

	print CLIProgressBar::finish();
}
# ---------------------------------------------------------------------
