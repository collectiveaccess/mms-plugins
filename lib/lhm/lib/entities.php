<?php
require_once(__CA_MODELS_DIR__.'/ca_entities.php');

# ---------------------------------------------------------------------
/**
 * Do entity import from spreadsheet
 * @param  string $ps_xlsx   absolute path to spreadsheet
 * @return boolean success state
 */
function entities_import($ps_xlsx) {
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

	mmsLog("Personen [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, $vs_msg = "Personen werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num==1) continue; // skip row with headers

		print CLIProgressBar::next(1, $vs_msg);
		mmsLog("Personen [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);

		$o_trans = new Transaction();
		$t_entity = new ca_entities();

		// entity ID
		$vs_entity_id = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row_num));

		if(strlen($vs_entity_id)<1) {
			mmsLog("Personen [{$ps_xlsx}]: Die Personen_ID ist leer für Zeile {$vn_row_num}. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			continue;
		}

		$vn_entity_id = intval($vs_entity_id);

		if($t_entity->load($vs_entity_id)){
			mmsLog("Personen [{$ps_xlsx}]: Die Personen_ID für Zeile {$vn_row_num} existiert bereits. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
			continue;
		} else {
			$vn_max_id = mmsGetMaxPkValue('ca_entities', 'entity_id');
			if($vn_entity_id > $vn_max_id){
				$o_db->query("ALTER TABLE ca_entities AUTO_INCREMENT = ?",$vn_entity_id);
			} else {
				mmsLog("Personen [{$ps_xlsx}]: Die Personen_ID für Zeile {$vn_row_num} ist ungültig. Die IDs müssen streng monoton steigend sortiert sein! Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
				continue;
			}
		}

		$t_entity->setTransaction($o_trans);
		$t_entity->setMode(ACCESS_WRITE);
		$t_entity->set('access',0);
		$t_entity->set('status',1);

		// Type ID
		$vs_type = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));

		switch(strtolower($vs_type)){
			case 'i' :
				$vs_type_id = 'institution';
				break;
			case 'e':
				$vs_type_id = 'ethnie';
				break;
			case 'p':
			default:
				$vs_type_id = 'person';
				break;
		}

		$t_entity->set('type_id',$vs_type_id);

		// Lebensdaten
		$vs_displaydate = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));
		$vs_lifespan = mmsGetDateTimeColumnFromSheet($o_sheet, 5, $vn_row_num);

		if(strlen($vs_displaydate) > 0 || strlen($vs_lifespan) > 0) {
			if(!$o_tep->parse($vs_lifespan)) {
				mmsLog("Personen [{$ps_xlsx}]: Die Lebensdaten (rechnerisch) sind nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_lifespan' und wurde ignoriert", Zend_Log::WARN);
				$vs_lifespan = null;
			}

			$t_entity->addAttribute(array(
				'life_span_calculated' => $vs_lifespan,
				'life_span_displaydate' => $vs_displaydate,
			),'life_span');
		}

		// Geburtsort (original + GeoNames)
		$vs_geburtsort_org = trim((string)$o_sheet->getCellByColumnAndRow(6, $vn_row_num));
		$vs_geburtsort_geonames = trim((string)$o_sheet->getCellByColumnAndRow(7, $vn_row_num));

		add_person_places_to_entity($t_entity,$vs_geburtsort_org,$vs_geburtsort_geonames,'geburtsort');

		// Sterbeort (original + GeoNames)
		$vs_sterbeort_org = trim((string)$o_sheet->getCellByColumnAndRow(8, $vn_row_num));
		$vs_sterbeort_geonames = trim((string)$o_sheet->getCellByColumnAndRow(9, $vn_row_num));

		add_person_places_to_entity($t_entity,$vs_sterbeort_org,$vs_sterbeort_geonames,'sterbeort');

		// Ort mit bestimmter Art
		$vs_ort_best_org = trim((string)$o_sheet->getCellByColumnAndRow(10, $vn_row_num));
		$vs_ort_best_geonames = trim((string)$o_sheet->getCellByColumnAndRow(11, $vn_row_num));
		$vs_ort_best_art = trim((string)$o_sheet->getCellByColumnAndRow(12, $vn_row_num));

		$vs_ort_best_art = mmsGetListItemIDByLabel('person_places_type_list',$vs_ort_best_art,'wirkungsort');

		add_person_places_to_entity($t_entity,$vs_ort_best_org,$vs_ort_best_geonames,$vs_ort_best_art);

		// Beruf
		$vs_beruf = trim((string)$o_sheet->getCellByColumnAndRow(16, $vn_row_num));
		if(strlen($vs_beruf)>0){
			$t_entity->addAttribute(array(
				'occupation' => $vs_beruf,
			),'occupation');
		}

		// GND
		$vs_gnd = trim((string)$o_sheet->getCellByColumnAndRow(17, $vn_row_num));
		if(strlen($vs_gnd)>0){
			$t_entity->addAttribute(array(
				'gnd_nr' => $vs_gnd,
			),'gnd_nr');
		}

		$vs_wip_text = trim((string)$o_sheet->getCellByColumnAndRow(18, $vn_row_num));
		$vs_wip_art = trim((string)$o_sheet->getCellByColumnAndRow(19, $vn_row_num));

		$vs_wip_art = mmsGetListItemIDByLabel('add_information_person_list',$vs_wip_art,'null');

		if(strlen($vs_wip_text)>0) {
			$t_entity->addAttribute(array(
				'add_information_person_text' => $vs_wip_text,
				'add_information_person_type' => $vs_wip_art
			),'add_information_person');
		}

		$vs_geprueft = trim((string)$o_sheet->getCellByColumnAndRow(20, $vn_row_num));
		switch(strtolower($vs_geprueft)) {
			case 'x':
				$vs_geprueft_val = 'yes';
				break;
			default:
				$vs_geprueft_val = 'no';
				break;
		}

		$t_entity->addAttribute(array(
			'record_status' => $vs_geprueft_val
		),'record_status');

		$t_entity->insert();

		if($t_entity->numErrors() > 0) {
			foreach ($t_entity->getErrors() as $vs_error) {
				mmsLog("Personen [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		// labels
		$vs_surname = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));
		$vs_forename = trim((string)$o_sheet->getCellByColumnAndRow(3, $vn_row_num));

		if((strlen($vs_surname)>0) || (strlen($vs_forename)>0)){
			$t_entity->addLabel(array(
				'surname' => $vs_surname,
				'forename' => $vs_forename,
			),$vn_locale_id,null,true);
		} else {
			$t_entity->addLabel(array(
				'surname' => "[LEER]",
			),$vn_locale_id,null,true);
		}

		$vs_alt_surnames = trim((string)$o_sheet->getCellByColumnAndRow(13, $vn_row_num));
		$vs_alt_forenames = trim((string)$o_sheet->getCellByColumnAndRow(14, $vn_row_num));
		$vs_alt_name_types = trim((string)$o_sheet->getCellByColumnAndRow(15, $vn_row_num));

		// Plausibilitätscheck stellt bereits sicher, dass alle gleich viele Werte haben!
		$va_alt_surnames = explode(';',$vs_alt_surnames);
		$va_alt_forenames = explode(';',$vs_alt_forenames);
		$va_alt_name_types = explode(';',$vs_alt_name_types);

		if(sizeof($va_alt_surnames)>0) {
			foreach($va_alt_surnames as $vn_i => $vs_alt_surname) {
				$vs_alt_forename = isset($va_alt_forenames[$vn_i]) ? trim($va_alt_forenames[$vn_i]) : '';
				$vs_alt_name_type = isset($va_alt_name_types[$vn_i]) ? trim($va_alt_name_types[$vn_i]) : '';

				$vs_alt_name_type = mmsGetListItemIDByLabel('entity_label_types',$vs_alt_name_type,'null');

				$t_entity->addLabel(array(
					'surname' => $vs_alt_surname,
					'forename' => $vs_alt_forename,
				),$vn_locale_id,$vs_alt_name_type,false);
			}
		}

		$o_trans->commit();

		unset($t_entity);
		unset($o_trans);
		mmsGC();
	}
	print CLIProgressBar::finish();
}
# ---------------------------------------------------------------------
function add_person_places_to_entity(&$t_entity, $vs_text, $vs_geoname, $vs_type) {
	$vs_geoname_val = null;

	if(strlen($vs_text) > 0 || strlen($vs_geoname) > 0) {
		if(strlen($vs_geoname)>0) {
			if($va_item = get_geonames('person_places_geonames',$vs_geoname)) {
				if($va_item['id']){
					$vs_geoname_val = $va_item['label'] . ' [id:' . $va_item['id'] . ']';
				}
			} else {
				mmsLog("Personen: Auflösen von '{$vs_geoname}' via GeoNames.org fehlgeschlagen. Evtl. wurde ein Limit erreicht?", Zend_Log::WARN);
				return false;
			}
		}

		$t_entity->addAttribute(array(
			'person_places_text' => $vs_text,
			'person_places_geonames' => $vs_geoname_val,
			'person_places_type' => $vs_type,
		),'person_places');
	}
}
# ---------------------------------------------------------------------
