<?php

require_once(__CA_MODELS_DIR__.'/ca_object_lots.php');
# ---------------------------------------------------------------------
/**
 * Erwerbungen aus Spreadsheet importieren
 * @param string $ps_xlsx Absoluter Pfad zum Spreadsheet
 * @return boolean success state
 */
function lots_import($ps_xlsx) {

	$t_locale = new ca_locales();
	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		CLIUtils::addError("Invalid locale code ".__LHM_MMS_DEFAULT_LOCALE__);
		return false;
	}

	$o_excel = phpexcel_load_file($ps_xlsx);
	$o_sheet = $o_excel->getActiveSheet();
	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;

	$o_db = mmsGetReusableDbInstance();
	$o_tep = new TimeExpressionParser();

	print CLIProgressBar::start($vn_rows, $vs_msg = "Erwerbungen werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num==1) continue; // skip row with headers

		print CLIProgressBar::next(1, $vs_msg);
		$t_lot = new ca_object_lots();
		$o_trans = new Transaction($o_db);
		$t_lot->setTransaction($o_trans);
		$t_lot->setMode(ACCESS_WRITE);

		// Erwerbung ID
		$vs_lot_id = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row_num));

		if(strlen($vs_lot_id)>0){
			$vn_lot_id = intval($vs_lot_id);

			if($t_lot->load($vs_lot_id)){
				mmsLog("Erwerbungen [{$ps_xlsx}]: Die Erwerbung ID für Zeile {$vn_row_num} existiert bereits. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
				$o_trans->rollback();
				continue;
			} else {
				$vn_max_id = mmsGetMaxPkValue('ca_object_lots', 'lot_id');
				if($vn_lot_id > $vn_max_id){
					$o_db->query("ALTER TABLE ca_object_lots AUTO_INCREMENT = ?",$vn_lot_id);
				} else {
					mmsLog("Erwerbungen [{$ps_xlsx}]: Die Erwerbung ID für Zeile {$vn_row_num} ist ungültig. Die IDs müssen streng monoton steigend sortiert und größer als alle vorhanden Werte in der DB sein! Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
					$o_trans->rollback();
					continue;
				}
			}
		}

		// Inventarnummernstamm
		$vs_lot_idno = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));
		if(strlen($vs_lot_idno)>0){
			$t_lot->set('idno_stub',$vs_lot_idno);
		}

		// Erwerbungsart
		$vs_lot_type = trim((string)$o_sheet->getCellByColumnAndRow(3, $vn_row_num));
		$t_lot->set('type_id', mmsGetListItemIDByLabel('object_lot_types',$vs_lot_type,'undefined'));

		// Erwerbungsstatus
		$vs_lot_status = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));
		$t_lot->set('lot_status_id', mmsGetListItemIDByLabel('object_lot_statuses',$vs_lot_status,'laufende_erwerbung'));

		// Erwerbungsdatum
		$vs_acq_date = mmsGetDateTimeColumnFromSheet($o_sheet, 5, $vn_row_num);
		if(strlen($vs_acq_date)>0){
			if($o_tep->parse($vs_acq_date)) {
				$t_lot->addAttribute(array(
					'acquisition_date' => $vs_acq_date,
				),'acquisition_date');
			} else {
				mmsLog("Erwerbungen [{$ps_xlsx}]: Das Erwerbungsdatum ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_acq_date' und wurde ignoriert", Zend_Log::WARN);
			}
		}

		// Erworben von
		$vs_acq_prev_owner = trim((string)$o_sheet->getCellByColumnAndRow(6, $vn_row_num));
		if(strlen($vs_acq_prev_owner)>0){
			$t_lot->addAttribute(array(
				'acquisition_previous_owner' => $vs_acq_prev_owner,
			),'acquisition_previous_owner');
		}

		// Erwerbung Bemerkung
		$vs_remark = trim((string)$o_sheet->getCellByColumnAndRow(7, $vn_row_num));
		if(strlen($vs_remark)>0){
			$t_lot->addAttribute(array(
				'remark' => $vs_remark,
			),'remark');
		}

		// Ankaufspreis
		$vs_value_org = trim((string)$o_sheet->getCellByColumnAndRow(8, $vn_row_num));
		$vs_value_eur = trim((string)$o_sheet->getCellByColumnAndRow(9, $vn_row_num));
		if(strlen($vs_value_org)> 0 || strlen($vs_value_eur)>0) {
			$t_lot->addAttribute(array(
				'value_original' => $vs_value_org,
				'value_eur' => $vs_value_eur
			),'costs');
		}

		// Nebenkosten
		$vs_costs_additional_eur = trim((string)$o_sheet->getCellByColumnAndRow(10, $vn_row_num));
		$vs_costs_additional_comment = trim((string)$o_sheet->getCellByColumnAndRow(11, $vn_row_num));
		if(strlen($vs_costs_additional_eur)> 0 || strlen($vs_costs_additional_comment)>0) {
			$t_lot->addAttribute(array(
				'costs_additional_comment' => $vs_costs_additional_comment,
				'costs_additional_eur' => $vs_costs_additional_eur
			),'costs_additional');
		}

		// SAP
		$vs_sap_accounting_area = trim((string)$o_sheet->getCellByColumnAndRow(12, $vn_row_num));
		$vs_sap_asset_aggr_nr = trim((string)$o_sheet->getCellByColumnAndRow(13, $vn_row_num));
		if(strlen($vs_sap_accounting_area)> 0 || strlen($vs_sap_asset_aggr_nr)>0) {
			$t_lot->addAttribute(array(
				'sap_asset_aggr_nr' => $vs_sap_asset_aggr_nr,
				'sap_accounting_area' => $vs_sap_accounting_area
			),'sap');
		}

		// Haushaltsstatus
		$vs_accounting_state = trim((string)$o_sheet->getCellByColumnAndRow(14, $vn_row_num));
		if((strlen($vs_accounting_state)>0) && (strtolower($vs_accounting_state) == "x")) {
			$t_lot->addAttribute(array(
				'accounting_state' => 'yes'
			),'accounting_state');
		} else {
			$t_lot->addAttribute(array(
				'accounting_state' => 'no'
			),'accounting_state');
		}

		$t_lot->insert();

		if($t_lot->numErrors() > 0) {
			foreach ($t_lot->getErrors() as $vs_error) {
				mmsLog("Erwerbungen [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		// after insert
		$vs_lot_label = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));

		if(strlen($vs_lot_label)<1){
			$vs_lot_label = "[LEER]";
		}

		$t_lot->addLabel(array(
			'name' => $vs_lot_label,
		),$vn_locale_id,null,true);

		$o_trans->commit();

		unset($o_trans);
		unset($t_lot);
		mmsGC();
	}

	print CLIProgressBar::finish();
}
# ---------------------------------------------------------------------
