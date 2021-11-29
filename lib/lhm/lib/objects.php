<?php
# ---------------------------------------------------------------------
/**
 * Vollständiger Objektimport
 * @param  string $ps_xlsx Absoluter Pfad zum Spreadsheet
 * @return boolean success state
 */
function objects_import($ps_xlsx){
	$t_locale = new ca_locales();
	$t_list = new ca_lists();
	$t_loc = new ca_storage_locations();
	$t_entity = new ca_entities();
	$t_lot = new ca_object_lots();
	$t_occ = new ca_occurrences();
	$t_col = new ca_collections();
	$t_user_group = new ca_user_groups();

	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))){
		mmsCritError("Objekte: Konnte Locale nicht laden");
	}

	$o_db = mmsGetReusableDbInstance();
	$o_tep = new TimeExpressionParser(null,__LHM_MMS_DEFAULT_LOCALE__);
	$o_excel = phpexcel_load_file($ps_xlsx);

	// OBJEKTE

	$o_sheet = $o_excel->getActiveSheet();
	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;
	$va_user_group_id_map = array();
	mmsLog("Objekte [{$ps_xlsx}]: Starte Import von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, $vs_msg = "Objekte werden aus Tabelle importiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num == 1) continue; // headers

		$vs_object_id = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row_num));

		// increment before 1st possible continuation point
		print CLIProgressBar::next();
		mmsLog("Objekte [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);

		$t_object = new ca_objects();
		$o_trans = new Transaction($o_db);
		$t_object->setTransaction($o_trans);

		// Objekt-ID
		if(strlen($vs_object_id)>0) { // Wenn Objekt-ID NICHT gesetzt ist, lasse das normale Autoincrement arbeiten

			$vn_object_id = intval($vs_object_id);

			if($t_object->load($vn_object_id)) {
				mmsLog("Objekte [{$ps_xlsx}]: Die Objekt_ID für Zeile {$vn_row_num} existiert bereits. Der Wert ist '{$vs_object_id}'. Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
				$o_trans->rollback();
				continue;
			} else {
				$vn_max_id = mmsGetMaxPkValue('ca_objects', 'object_id');
				if($vn_object_id > $vn_max_id) {
					$o_db->query("ALTER TABLE ca_objects AUTO_INCREMENT = ?",$vn_object_id);
				} else {
					mmsLog("Objekte [{$ps_xlsx}]: Die Objekt_ID für Zeile {$vn_row_num} ist ungültig. Die IDs müssen streng monoton steigend sortiert sein und dürfen nicht mit existierenden Werten in der Datenbank kollidieren! Die gesamte Zeile wurde ignoriert.", Zend_Log::WARN);
					$o_trans->rollback();
					continue;
				}
			}
		}

		$t_object = new ca_objects();
		$t_object->setTransaction($o_trans);
		$t_object->setMode(ACCESS_WRITE);
		$t_object->set('access',0);
		$t_object->set('status',1);
		$t_object->set('type_id','object');
		$t_object->set('acl_inherit_from_ca_collections', 0);
		$t_object->set('acl_inherit_from_parent', 0);

		// Inventarnummer
		$vs_idno = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));
		if(strlen($vs_idno)>0){
			$t_object->set('idno',$vs_idno);
		}

		// Ort Text und GeoNames
		$vs_ort_text = trim((string)$o_sheet->getCellByColumnAndRow(12, $vn_row_num));
		$vs_ort_gn = trim((string)$o_sheet->getCellByColumnAndRow(13, $vn_row_num));

		if(strlen($vs_ort_text)>0 || strlen($vs_ort_gn)>0) { // Container wird gesetzt, wenn eins von beidem gesetzt ist!
			// Lookup Ort in GeoNames und baue String
			$vs_geonames_val = null;
			if(strlen($vs_ort_gn)>0){
				if($va_item = get_geonames('object_places_geonames',$vs_ort_gn)){
					if($va_item['id']){
						$vs_geonames_val = $va_item['label'] . ' [id:' . $va_item['id'] . ']';
					}
				}
			}

			$t_object->addAttribute(array(
				'object_places_text' => $vs_ort_text,
				'object_places_geonames' => $vs_geonames_val,
			),'object_places');
		}

		// Datierung rechnerisch und Freitext, mit Typ
		$vs_date_text = trim((string)$o_sheet->getCellByColumnAndRow(14, $vn_row_num));
		$vs_date_calc = mmsGetDateTimeColumnFromSheet($o_sheet,15,$vn_row_num);
		$vs_date_type = trim((string)$o_sheet->getCellByColumnAndRow(16, $vn_row_num));
		if(strlen($vs_date_text)>0 || strlen($vs_date_calc)>0) { // Container wird gesetzt, wenn eins von beidem gesetzt ist!

			if(strlen($vs_date_calc)>0){
				if(!$o_tep->parse($vs_date_calc)){
					$vs_date_calc = null;
					mmsLog("Objekte [{$ps_xlsx}]: Das rechnerische Datum ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_date_calc' und wurde ignoriert", Zend_Log::WARN);
				}
			}

			$vs_date_type_code = null;
			if(strlen($vs_date_type)>0){
				$vs_date_type_code = $t_list->getItemIDFromListByLabel('dates_type_list', $vs_date_type);
			}

			$t_object->addAttribute(array(
				'dates_calculated' => $vs_date_calc,
				'dates_display' => $vs_date_text,
				'dates_type' => ($vs_date_type_code ? $vs_date_type_code : 'entstehung'),
			),'dates');
		}

		// Objektart
		$vs_objektart = trim((string)$o_sheet->getCellByColumnAndRow(17, $vn_row_num));
		if(strlen($vs_objektart)>0) {
			$t_object->addAttribute(array(
				'object_type_text' => $vs_objektart,
			),'object_type_text');
		}

		// Material/Technik
		$vs_material_technique = trim((string)$o_sheet->getCellByColumnAndRow(18, $vn_row_num));
		if(strlen($vs_material_technique)>0) {
			$t_object->addAttribute(array(
				'material_technique' => $vs_material_technique,
			),'material_technique');
		}

		// Maße
		$vs_dimensions_text = trim((string)$o_sheet->getCellByColumnAndRow(19, $vn_row_num));
		$vs_dimensions_h = trim((string)$o_sheet->getCellByColumnAndRow(20, $vn_row_num));
		$vs_dimensions_b = trim((string)$o_sheet->getCellByColumnAndRow(21, $vn_row_num));
		$vs_dimensions_t = trim((string)$o_sheet->getCellByColumnAndRow(22, $vn_row_num));
		$vs_dimensions_type = trim((string)$o_sheet->getCellByColumnAndRow(23, $vn_row_num));
		$vs_dimensions_geprueft = trim((string)$o_sheet->getCellByColumnAndRow(24, $vn_row_num));

		if(
			strlen($vs_dimensions_text)>0 ||
			strlen($vs_dimensions_h)>0 ||
			strlen($vs_dimensions_b)>0 ||
			strlen($vs_dimensions_t)>0
		) {

			$vs_dimensions_type_id = mmsGetListItemIDByLabel('dimension_types_list', $vs_dimensions_type, 'objektmass');

			switch(strtolower($vs_dimensions_geprueft)){
				case 'x':
					$vs_dimensions_state = 'yes';
					break;
				default:
					$vs_dimensions_state = 'no';
					break;
			}

			$t_object->addAttribute(array(
				'dimensions_height' => $vs_dimensions_h,
				'dimensions_width' => $vs_dimensions_b,
				'dimensions_depth' => $vs_dimensions_t,
				'dimensions_type' => $vs_dimensions_type_id,
				'dimensions_text' => $vs_dimensions_text,
				'dimensions_state' => $vs_dimensions_state,
			),'dimensions');

		}

		// Rahmung
		$vs_rahmung = trim((string)$o_sheet->getCellByColumnAndRow(25, $vn_row_num));
		if(strlen($vs_rahmung)>0) {
			$t_object->addAttribute(array(
				'framing' => $vs_rahmung,
			),'framing');
		}

		// Signatur/Beschriftung
		$vs_inscription = trim((string)$o_sheet->getCellByColumnAndRow(26, $vn_row_num));
		if(strlen($vs_inscription)>0) {
			$t_object->addAttribute(array(
				'inscription' => $vs_inscription,
			),'inscription');
		}

		// Beschreibung
		$vs_description = trim((string)$o_sheet->getCellByColumnAndRow(27, $vn_row_num));
		if(strlen($vs_description)>0) {
			$t_object->addAttribute(array(
				'add_information_object_text' => $vs_description,
				'add_information_object_type' => 'beschreibung'
			),'add_information_object');
		}

		// Sonstige Angaben
		$vs_sonst_angaben = trim((string)$o_sheet->getCellByColumnAndRow(28, $vn_row_num));
		if(strlen($vs_sonst_angaben)>0) {
			$t_object->addAttribute(array(
				'add_information_object_text' => $vs_sonst_angaben,
				'add_information_object_type' => 'sonst_angaben'
			),'add_information_object');
		}

		// WIO bestimmt
		$vs_wio_text = trim((string)$o_sheet->getCellByColumnAndRow(29, $vn_row_num));
		$vs_wio_type = trim((string)$o_sheet->getCellByColumnAndRow(30, $vn_row_num));
		if(strlen($vs_wio_text)>0 && strlen($vs_wio_type)>0) {

			if($vs_wio_type_code = $t_list->getItemIDFromListByLabel('add_information_object_list', $vs_wio_type)) {

				$t_object->addAttribute(array(
					'add_information_object_text' => $vs_wio_text,
					'add_information_object_type' => $vs_wio_type_code
				),'add_information_object');

			} else {
				mmsLog("Objekte [{$ps_xlsx}]: Konnte WIO für Zeile {$vn_row_num} nicht hinzufügen. Der WIO Typ für Wert '{$vs_wio_text}' ist nicht gültig ('$vs_wio_type').", Zend_Log::WARN);
			}
		}

		// Provenienz
		$vs_provenance = trim((string)$o_sheet->getCellByColumnAndRow(31, $vn_row_num));
		if(strlen($vs_provenance)>0) {
			$t_object->addAttribute(array(
				'provenance_previous_owner' => $vs_provenance,
			),'provenance');
		}

		// Kulturgutschutzliste
		$vs_cultural_artifact_list = trim((string)$o_sheet->getCellByColumnAndRow(32, $vn_row_num));
		if((strlen($vs_cultural_artifact_list)>0) && (strtolower($vs_cultural_artifact_list)=="x")) {
			$t_object->addAttribute(array(
				'cultural_artifact' => 'yes',
			),'cultural_artifact');
		} else {
			$t_object->addAttribute(array(
				'cultural_artifact' => 'no',
			),'cultural_artifact');
		}

		// Erwerbungen (nur noch via lot_id! Yay!)
		$vs_erwerbung_id = trim((string)$o_sheet->getCellByColumnAndRow(33, $vn_row_num));
		if(strlen($vs_erwerbung_id)>0) {
			if($t_lot->load($vs_erwerbung_id)){
				$t_object->set('lot_id',$t_lot->getPrimaryKey());
			} else {
				mmsLog("Objekte [{$ps_xlsx}]: Konnte Erwerbung für Zeile {$vn_row_num} via ID nicht finden. Der Wert ist '{$vs_erwerbung_id}'. Beziehung zu Erwerbung konnte nicht hergestellt werden.", Zend_Log::WARN);
			}
		}

		// Leihgeber
		$vs_leihgeber = trim((string)$o_sheet->getCellByColumnAndRow(34, $vn_row_num));
		if(strlen($vs_leihgeber)>0) {
			$t_object->addAttribute(array(
				'lender_owner' => $vs_leihgeber,
			),'lender_owner');
		}

		// Kosten / Wert des Objekts
		$vs_kosten_org = trim((string)$o_sheet->getCellByColumnAndRow(35, $vn_row_num));
		$vs_kosten_eur = trim((string)$o_sheet->getCellByColumnAndRow(36, $vn_row_num));
		if(strlen($vs_kosten_org)>0 || strlen($vs_kosten_eur)>0) {

			if(strlen($vs_kosten_eur)>0) {
				if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_kosten_eur)){
					mmsLog("Objekte [{$ps_xlsx}]: Der Wert des Objektes in EUR ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_kosten_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
					$vs_kosten_eur = null;
				}
			} else {
				$vs_kosten_eur = null;
			}

			$t_object->addAttribute(array(
				'value_original' => $vs_kosten_org,
				'value_eur' => $vs_kosten_eur,
			),'costs');
		}

		// Nebenkosten
		$vs_nk_eur = trim((string)$o_sheet->getCellByColumnAndRow(37, $vn_row_num));
		$vs_nk_bemerkung = trim((string)$o_sheet->getCellByColumnAndRow(38, $vn_row_num));
		if(strlen($vs_nk_bemerkung)>0 || strlen($vs_nk_eur)>0) {

			if(strlen($vs_nk_eur)>0) {
				if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_nk_eur)){
					mmsLog("Objekte [{$ps_xlsx}]: Die Nebenkosten des Objektes in EUR sind nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_nk_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
					$vs_nk_eur = null;
				}
			} else {
				$vs_nk_eur = null;
			}

			$t_object->addAttribute(array(
				'costs_additional_comment' => $vs_nk_bemerkung,
				'costs_additional_eur' => $vs_nk_eur,
			),'costs_additional');
		}

		// Versicherungswert historisch
		$vs_vw_eur = trim((string)$o_sheet->getCellByColumnAndRow(39, $vn_row_num));
		$vs_vw_date = trim((string)$o_sheet->getCellByColumnAndRow(40, $vn_row_num));
		if(strlen($vs_vw_eur)>0 || strlen($vs_vw_date)>0) {
			$va_tmp_eur = explode(';',$vs_vw_eur);
			$va_tmp_date = explode(';', $vs_vw_date);

			if(is_array($va_tmp_eur)) {
				foreach($va_tmp_eur as $vn_i => $vs_tmp_eur) {
					$vs_tmp_eur = trim($vs_tmp_eur);

					if(strlen($vs_tmp_eur)>0) {

						if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_tmp_eur)) {
							mmsLog("Objekte [{$ps_xlsx}]: Der historische Versicherungswert des Objektes in EUR sind nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_tmp_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
							continue;
						}

						if(isset($va_tmp_date[$vn_i])) {
							$vs_tmp_date = trim($va_tmp_date[$vn_i]);
							if(strlen($vs_tmp_date) > 0) {
								if(!$o_tep->parse($vs_tmp_date)){
									mmsLog("Objekte [{$ps_xlsx}]: Das Datum zum historischen Versicherungswert ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_tmp_date' und wurde ignoriert", Zend_Log::WARN);
									$vs_tmp_date = null;
								}
							} else {
								$vs_tmp_date = null;
							}
						} else {
							$vs_tmp_date = null;
							mmsLog("Objekte [{$ps_xlsx}]: Kein Datum zum historischen Versicherungswert '$vs_tmp_eur' gefunden in Zeile {$vn_row_num}. Der Wert wird ohne Datum eingefügt.", Zend_Log::WARN);
						}

						$t_object->addAttribute(array(
							'historic_date' => $vs_tmp_date,
							'historic_value_eur' => $vs_tmp_eur,
						),'insurance_value_historic');

					}
				}
			}
		}

		// Literatur
		$vs_literature = trim((string)$o_sheet->getCellByColumnAndRow(41, $vn_row_num));
		if(strlen($vs_literature)>0) {
			$t_object->addAttribute(array(
				'literature' => $vs_literature,
			),'literature');
		}

		// Inventarnummer Alt
		$vs_inv_nr_alt_nr = trim((string)$o_sheet->getCellByColumnAndRow(42, $vn_row_num));
		$vs_inv_nr_alt_type = trim((string)$o_sheet->getCellByColumnAndRow(43, $vn_row_num));
		if(strlen($vs_inv_nr_alt_nr)>0 || strlen($vs_inv_nr_alt_type)>0) {
			$va_tmp_nr = explode(';',$vs_inv_nr_alt_nr);
			$va_tmp_type = explode(';', $vs_inv_nr_alt_type);

			foreach($va_tmp_nr as $vn_i => $vs_tmp_nr) {

				$vs_tmp_type = (isset($va_tmp_type[$vn_i]) ? trim($va_tmp_type[$vn_i]): "");

				$t_object->addAttribute(array(
					'inventory_nr_old_nr' => trim($vs_tmp_nr),
					'inventory_nr_old_type' => $vs_tmp_type,
				),'inventory_nr_old');

			}
		}

		// Inventarisierung Kommentar
		$vs_inv_comment = trim((string)$o_sheet->getCellByColumnAndRow(44, $vn_row_num));
		if(strlen($vs_inv_comment)>0) {
			$t_object->addAttribute(array(
				'inventory_comment' => $vs_inv_comment,
			),'inventory_comment');
		}

		// Copyright Info
		$vs_creditline = trim((string)$o_sheet->getCellByColumnAndRow(45, $vn_row_num));
		if(strlen($vs_creditline)>0) {
			$t_object->addAttribute(array(
				'copyright_information' => $vs_creditline,
			),'copyright_information');
		}

		// ToDo Inventarisierung
		$vs_todo_inv = trim((string)$o_sheet->getCellByColumnAndRow(48, $vn_row_num));
		if(strlen($vs_todo_inv)>0){
			$t_object->addAttribute(array(
				'todo_inventory_description' => $vs_todo_inv,
			),'todo_inventory');
		}

		// Datenquelle
		$va_data_source = array();
		$vs_data_source = trim((string)$o_sheet->getCellByColumnAndRow(49, $vn_row_num));
		$vs_bearbeitet_von = trim((string)$o_sheet->getCellByColumnAndRow(50, $vn_row_num));
		$vs_bearbeitet_am = mmsGetDateTimeColumnFromSheet($o_sheet,51,$vn_row_num);

		if(strlen($vs_data_source)>0){
			$va_data_source[] = "Quelle: ".$vs_data_source;
		}

		if(strlen($vs_bearbeitet_von)>0){
			$va_data_source[] = "Bearbeitet von: ".$vs_bearbeitet_von;
		}

		if(strlen($vs_bearbeitet_am)>0){
			$va_data_source[] = "Bearbeitet Datum: ".$vs_bearbeitet_am;
		}

		if(sizeof($va_data_source)>0) {
			$t_object->addAttribute(array(
				'data_source' => join("; ", $va_data_source),
			),'data_source');
		}

		// ToDo Datensatz
		$vs_todo_datensatz = trim((string)$o_sheet->getCellByColumnAndRow(53, $vn_row_num));
		if(strlen($vs_todo_datensatz)>0){
			$t_object->addAttribute(array(
				'todo_record_description' => $vs_todo_datensatz,
			),'todo_record');
		}

		// SAP Einzelnummer
		$vs_sap_einzelnr = trim((string)$o_sheet->getCellByColumnAndRow(54, $vn_row_num));
		if(strlen($vs_sap_einzelnr)>0){
			$t_object->addAttribute(array(
				'sap_asset_nr' => $vs_sap_einzelnr,
			),'sap_asset_nr');
		}

		// UUID
		$vs_uuid = trim((string)$o_sheet->getCellByColumnAndRow(56, $vn_row_num));
		if(strlen($vs_uuid)>0){
			$t_object->addAttribute(array(
				'uuid' => $vs_uuid,
			),'uuid');
		}

		// Inventarstatus des Objekts
		$vs_inventory_state = trim((string)$o_sheet->getCellByColumnAndRow(57, $vn_row_num));
		if(strlen($vs_inventory_state)>0){
			$vs_inventory_state = mmsGetListItemIDByLabel('object_inventory_state_list', $vs_inventory_state, 'bestandsobjekt');

			$t_object->replaceAttribute(array(
				'inventory_state' => $vs_inventory_state,
			),'inventory_state');
		}

		// Datensatz geprüft
		$vs_datensatz_geprueft = trim((string)$o_sheet->getCellByColumnAndRow(58, $vn_row_num));
		if((strlen($vs_datensatz_geprueft)>0) && (strtolower($vs_datensatz_geprueft) == 'x')){
			$t_object->replaceAttribute(array(
				'record_status' => 'yes',
			),'record_status');
		} else {
			$t_object->replaceAttribute(array(
				'record_status' => 'no',
			),'record_status');
		}

		// Versicherungswert aktuell
		$vs_vw_curr_eur = trim((string)$o_sheet->getCellByColumnAndRow(59, $vn_row_num));
		$vs_vw_curr_date = trim((string)$o_sheet->getCellByColumnAndRow(60, $vn_row_num));
		if(strlen($vs_vw_curr_eur)>0) {

			if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_vw_curr_eur)) {
				mmsLog("Objekte [{$ps_xlsx}]: Der aktuelle Versicherungswert des Objektes in EUR sind nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_vw_curr_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
				continue;
			}

			if(strlen($vs_vw_curr_date)>0) {
				if(!$o_tep->parse($vs_vw_curr_date)) {
					mmsLog("Objekte [{$ps_xlsx}]: Das Datum zum aktuellen Versicherungswert ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_vw_curr_date' und wurde ignoriert", Zend_Log::WARN);
					$vs_vw_curr_date = null;
				}
			} else {
				$vs_vw_curr_date = null;
				mmsLog("Objekte [{$ps_xlsx}]: Kein Datum zum aktuellen Versicherungswert '$vs_tmp_eur' gefunden in Zeile {$vn_row_num}. Der Wert wird ohne Datum eingefügt.", Zend_Log::WARN);
			}

			$t_object->addAttribute(array(
				'current_date' => $vs_vw_curr_date,
				'current_value_eur' => $vs_vw_curr_eur,
			),'insurance_value_current');

		}

		// Abzug/Auflage
		$vs_abzug_auflage = trim((string)$o_sheet->getCellByColumnAndRow(61, $vn_row_num));
		if(strlen($vs_abzug_auflage)>0) {
			$t_object->addAttribute(array(
				'edition' => $vs_abzug_auflage,
			),'edition');

		}

		// Zustand Beschreibung
		$vs_zustand_beschreibung = trim((string)$o_sheet->getCellByColumnAndRow(62, $vn_row_num));
		if(strlen($vs_zustand_beschreibung)>0) {
			$t_object->addAttribute(array(
				'condition_description' => $vs_zustand_beschreibung,
			),'conditional_notes');
		}

		// Aufbewahrung
		$vs_aufbewahrung = trim((string)$o_sheet->getCellByColumnAndRow(63, $vn_row_num));
		if(strlen($vs_aufbewahrung)>0) {
			$t_object->addAttribute(array(
				'safe_keeping' => $vs_aufbewahrung,
			),'safe_keeping');
		}

		// Negativnummer
		$vs_negativnummer = trim((string)$o_sheet->getCellByColumnAndRow(64, $vn_row_num));
		if(strlen($vs_negativnummer)>0) {
			$t_object->addAttribute(array(
				'negative_number' => $vs_negativnummer,
			),'negative_number');
		}

		$t_object->insert();

		if($t_object->numErrors()>0){
			foreach($t_object->getErrors() as $vs_error){
				mmsLog("Objekte [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::ERR);
			}
			$o_trans->rollback();
			continue;
		}

		// NACH INSERT (RELATIONSHIPS, LABELS, ETC.)

		// Titel
		$vs_title = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));
		if(strlen($vs_title)<1){
			$vs_title = "[LEER]";
		}

		$t_object->addLabel(array(
			'name' => $vs_title,
		),$vn_locale_id,null,true);

		// Aufbewahrungsort (mit Bearbeiter und Datum)
		$vs_aufbewahrungsort = trim((string)$o_sheet->getCellByColumnAndRow(3, $vn_row_num));
		if(strlen($vs_aufbewahrungsort)>0) {
			if($t_loc->load(array("idno" => $vs_aufbewahrungsort))){

				$vs_sl_date = mmsGetDateTimeColumnFromSheet($o_sheet,5,$vn_row_num);
				if(strlen($vs_sl_date)>0){
					if(!$o_tep->parse($vs_sl_date)){
						$vs_sl_date = "";
						mmsLog("Objekte [{$ps_xlsx}]: Das Datum zum Aufbewahrungsort ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_sl_date' und wurde ignoriert", Zend_Log::WARN);
					}
				}

				$t_rel = $t_object->addRelationship('ca_storage_locations',$t_loc->getPrimaryKey(),'repository',$vs_sl_date);

				if($t_rel instanceof BaseRelationshipModel) {
					$t_rel->setMode(ACCESS_WRITE);

					$vs_sl_user = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));
					if(strlen($vs_sl_user)>0){
						$t_rel->addAttribute(array(
							'storage_location_user' => $vs_sl_user,
						),'storage_location_user');

						$t_rel->update();
					}
				}
			} else {
				mmsLog("Objekte [{$ps_xlsx}]: Konnte ID für Aufbewahrungsort für Zeile {$vn_row_num} nicht finden. Der Wert ist '{$vs_aufbewahrungsort}'. Beziehung konnte nicht hergestellt werden.", Zend_Log::WARN);
			}
		}

		// Standort (mit Bearbeiter und Datum)
		$vs_standort = trim((string)$o_sheet->getCellByColumnAndRow(6, $vn_row_num));
		if(strlen($vs_standort)>0){
			if($t_loc->load(array("idno" => $vs_standort))){

				$vs_sl_date = mmsGetDateTimeColumnFromSheet($o_sheet,8,$vn_row_num);
				if(strlen($vs_sl_date)>0){
					if(!$o_tep->parse($vs_sl_date)){
						$vs_sl_date = "";
						mmsLog("Objekte [{$ps_xlsx}]: Das Datum zum aktuellen Standort ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_sl_date' und wurde ignoriert", Zend_Log::WARN);
					}
				}

				$t_rel = $t_object->addRelationship('ca_storage_locations',$t_loc->getPrimaryKey(),'current_location',$vs_sl_date);

				if($t_rel instanceof BaseRelationshipModel) {
					$t_rel->setMode(ACCESS_WRITE);

					$vs_sl_user = trim((string)$o_sheet->getCellByColumnAndRow(7, $vn_row_num));
					if(strlen($vs_sl_user)>0){
						$t_rel->addAttribute(array(
							'storage_location_user' => $vs_sl_user,
						),'storage_location_user');
						$t_rel->update();
					}
				}
			} else {
				mmsLog("Objekte [{$ps_xlsx}]: Konnte ID für aktuellen Standort für Zeile {$vn_row_num} nicht finden. Der Wert ist '{$vs_standort}'. Beziehung konnte nicht hergestellt werden.", Zend_Log::WARN);
			}
		}

		// Person relationships
		$vs_entity_ids = trim((string)$o_sheet->getCellByColumnAndRow(9, $vn_row_num));
		$vs_entity_roles = trim((string)$o_sheet->getCellByColumnAndRow(10, $vn_row_num));
		$vs_entity_attribution = trim((string)$o_sheet->getCellByColumnAndRow(11, $vn_row_num));

		if(strlen($vs_entity_ids)>0) {
			$va_entity_ids = explode(';',$vs_entity_ids);
			$va_entity_roles = explode(';',$vs_entity_roles);
			$va_entity_attribution = explode(';',$vs_entity_attribution);

			foreach($va_entity_ids as $vn_i => $vs_entity_id) {
				$vs_entity_id = trim($vs_entity_id);

				if($t_entity->load($vs_entity_id)) {
					$vs_rel_type = false;

					if(isset($va_entity_roles[$vn_i]) && strlen($va_entity_roles[$vn_i])>0){
						$vs_rel_type = mmsGetRelTypeCodeByLabel('ca_objects_x_entities',$va_entity_roles[$vn_i]);
					}

					if(!$vs_rel_type) { $vs_rel_type = 'artist'; }

					$t_rel = $t_object->addRelationship('ca_entities',$t_entity->getPrimaryKey(),$vs_rel_type);

					if($t_rel instanceof BaseRelationshipModel) {

						if(isset($va_entity_attribution[$vn_i]) && strlen($va_entity_attribution[$vn_i])>0) {
							$vs_attr = trim($va_entity_attribution[$vn_i]);
							$vs_attribution_val_for_ca = null;

							switch(strtolower($vs_attr)) {
								case 'x':
									$vs_attribution_val_for_ca = 'yes';
									break;
								case 'n':
									$vs_attribution_val_for_ca = 'no';
									break;
								default:
									break;
							}

							if(!is_null($vs_attribution_val_for_ca)){
								$t_rel->setMode(ACCESS_WRITE);
								$t_rel->addAttribute(array(
									'objects_entities_attribution' => $vs_attribution_val_for_ca,
								),'objects_entities_attribution');
								$t_rel->update();
							}

						}
					}
				} else {
					mmsLog("Objekte [{$ps_xlsx}]: Konnte ID für Person für Zeile {$vn_row_num} nicht finden. Der Wert ist '{$vs_entity_id}'. Beziehung konnte nicht hergestellt werden.", Zend_Log::WARN);
				}
			}
		}

		// Objektgruppen, Konvolute
		$vs_objektgruppen = trim((string)$o_sheet->getCellByColumnAndRow(46, $vn_row_num));
		if(strlen($vs_objektgruppen)>0){
			$va_objektgruppen = explode(';',$vs_objektgruppen);

			foreach($va_objektgruppen as $vs_objektgruppe) {
				$vs_objektgruppe = trim($vs_objektgruppe);

				if($t_occ->load(array('idno' => $vs_objektgruppe))){
					switch($t_occ->getTypeCode()) {
						case 'lot':
							$vs_rel_type = 'related_to_lot';
							break;
						case 'object_group':
							$vs_rel_type = 'related_to_object_group';
							break;
						default:
							$vs_rel_type = null;
							break;

					}

					if($vs_rel_type) {
						$t_object->addRelationship('ca_occurrences',$t_occ->getPrimaryKey(),$vs_rel_type);
					} else {
						mmsLog("Objekte [{$ps_xlsx}]: Beziehung zu Objektgruppe oder Konvolut mit ID {$vs_objektgruppe} konnte nicht hergestellt werden, da der Typ des Datensatzes nicht stimmt (keine Objektgruppe oder Konvolut).", Zend_Log::WARN);
					}
				} else {
					mmsLog("Objekte [{$ps_xlsx}]: Beziehung zu Objektgruppe oder Konvolut mit ID {$vs_objektgruppe} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
				}
			}
		}

		// Ausstellungen
		$vs_ausstellungen = trim((string)$o_sheet->getCellByColumnAndRow(47, $vn_row_num));
		if(strlen($vs_ausstellungen)>0){
			$va_ausstellungen = explode(';',$vs_ausstellungen);

			foreach($va_ausstellungen as $vs_ausstellung){
				$vs_ausstellung = trim($vs_ausstellung);

				if($t_occ->load(array('idno' => $vs_ausstellung))){
					if($t_occ->getTypeCode() == 'exhibition'){
						$t_object->addRelationship('ca_occurrences',$t_occ->getPrimaryKey(),'exhibit_in');
					} else {
						mmsLog("Objekte [{$ps_xlsx}]: Beziehung zu Ausstellung mit ID {$vs_ausstellung} konnte nicht hergestellt werden, da der Typ des Datensatzes nicht stimmt (keine Ausstellung).", Zend_Log::WARN);
					}
				} else {
					mmsLog("Objekte [{$ps_xlsx}]: Beziehung zu Ausstellung mit ID {$vs_ausstellung} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
				}

			}
		}

		// Schlagworte
		$vs_schlagworte = trim((string)$o_sheet->getCellByColumnAndRow(52, $vn_row_num));
		if(strlen($vs_schlagworte)>0){
			$va_schlagworte = explode(";",$vs_schlagworte);
			foreach($va_schlagworte as $vs_schlagwort){
				$vn_item_id = ca_list_items::find(array('preferred_labels' => array('name_singular' => trim($vs_schlagwort))),array('returnAs' => 'firstId'));
				if($vn_item_id){
					$t_object->addRelationship('ca_list_items',$vn_item_id,'described');
				}
			}
		}

		// Sammlung
		$vs_sammlung = trim((string)$o_sheet->getCellByColumnAndRow(55, $vn_row_num));
		if(strlen($vs_sammlung)>0){
			if($t_col->load(array('idno' => $vs_sammlung))) {
				$t_object->addRelationship('ca_collections',$t_col->getPrimaryKey(),'related_to');
				// alter code zur Rechteverwaltung mit Sammlungsbereichen
				/*if($vb_add_rel) {
					$o_conf = Configuration::load(__CA_APP_DIR__.'/plugins/lhmMMS/conf/lhmMMS.conf'); // ist in memory gecacht, jedes mal laden schadet also nicht
					$va_group_collection_map = $o_conf->get('lhm_mms_group_collection_map');
					if(is_array($va_group_collection_map) && sizeof($va_group_collection_map)>0) {
						// config ist group=>collection, wir brauchen collection=>group, um die gruppe ermitteln zu koennen
						$va_collection_group_map = array_flip($o_conf->get('lhm_mms_group_collection_map'));
						if(sizeof($va_collection_group_map) > 0 ){
							if(isset($va_collection_group_map[$vs_sammlung])) { // wenn es fuer die Sammlg eine Gruppe gibt
								$vs_group_code = $va_collection_group_map[$vs_sammlung];

								// Mapping von group_code zu group_id im Speicher vorhalten.
								if(!isset($va_user_group_id_map[$vs_group_code])) {
									$t_user_group->load(array('code' => $vs_group_code));
									$va_user_group_id_map[$vs_group_code] = $t_user_group->getPrimaryKey();
								}

								$va_ro_groups = $t_user_group->getGroupList(); // Liste aller Gruppen
								unset($va_ro_groups[$va_user_group_id_map[$vs_group_code]]); // unsere Gruppe soll nicht gesetzt werden, hier gilt dann die default Einstellung aus app.conf

								// für alle übrig gebliebenen group_ids legen wir explizit read-only access fest
								$va_acl_user_groups = array();
								foreach(array_keys($va_ro_groups) as $vn_ro_group_id) {
									$va_acl_user_groups[$vn_ro_group_id] = __CA_ACL_READONLY_ACCESS__;
								}

								$t_object->addACLUserGroups($va_acl_user_groups);
							}
						}
					}
				}*/
			} else {
				mmsLog("Objekte [{$ps_xlsx}]: Beziehung zu Sammlung mit ID {$vs_sammlung} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
			}
		}

		if($t_object->numErrors()>0){
			foreach($t_object->getErrors() as $vs_error){
				mmsLog("Objekte [{$ps_xlsx}]: Hinzufügen von Beziehungen zu Objekt aus Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		$o_trans->commit();

		unset($o_trans);
		unset($t_object);
		mmsGC();
	}

	print CLIProgressBar::finish();

}
# ---------------------------------------------------------------------
