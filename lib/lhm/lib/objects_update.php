<?php

require_once(__CA_LIB_DIR__.'/ApplicationPluginManager.php');

# ---------------------------------------------------------------------
/**
 * Aktualisierung bestehender Objekte
 * @param  string $ps_xlsx Absoluter Pfad zum Spreadsheet
 * @return boolean success state
 */
function objects_update($ps_xlsx) {
	ApplicationPluginManager::initPlugins(); // Sicherstellen, dass unser MMS Plugin geladen wird

	$t_locale = new ca_locales();
	$t_list = new ca_lists();
	$t_loc = new ca_storage_locations();
	$t_entity = new ca_entities();
	$t_lot = new ca_object_lots();
	$t_occ = new ca_occurrences();
	$t_col = new ca_collections();

	if(!($vn_locale_id = $t_locale->localeCodeToID(__LHM_MMS_DEFAULT_LOCALE__))) {
		mmsCritError("Objekt-Update: Konnte Locale nicht laden");
	}

	$o_db = mmsGetReusableDbInstance();
	$o_tep = new TimeExpressionParser(null,__LHM_MMS_DEFAULT_LOCALE__);
	$o_excel = phpexcel_load_file($ps_xlsx);

	// OBJEKTE

	$o_sheet = $o_excel->getActiveSheet();
	$vn_rows = count_nonempty_rows($ps_xlsx) - 1;
	mmsLog("Objekt-Update [{$ps_xlsx}]: Starte Update von {$vn_rows} items ...", Zend_Log::INFO);
	print CLIProgressBar::start($vn_rows, "Objekte werden aktualisiert ...");

	foreach ($o_sheet->getRowIterator() as $o_row) {
		$vn_row_num = $o_row->getRowIndex();
		if($vn_row_num == 1) continue; // headers

		$t_object = new ca_objects();
		$o_trans = new Transaction($o_db);
		$t_object->setTransaction($o_trans);
		$vs_object_id = trim((string)$o_sheet->getCellByColumnAndRow(0, $vn_row_num));

		// increment before 1st possible continuation point
		print CLIProgressBar::next();
		mmsLog("Objekt-Update [{$ps_xlsx}]: Verarbeite Zeile {$vn_row_num}", Zend_Log::DEBUG);

		// Objekt-ID
		if(!$t_object->load($vs_object_id)) {
			mmsLog("Objekt-Update [{$ps_xlsx}]: Konnte die Objekt_ID für Zeile {$vn_row_num} nicht laden. Der Wert muss ein existierendes Objekt referenzieren. Zeile wird übersprungen", Zend_Log::WARN);
			$o_trans->rollback();
			continue;
		}

		// set basic stuff
		$t_object->setMode(ACCESS_WRITE);

		// Inventarnummer
		$vs_idno = trim((string)$o_sheet->getCellByColumnAndRow(1, $vn_row_num));

		if(strlen($vs_idno)>0) {

			// Alte Inventarnummer in 'Inventarnummer Alt' verschieben
			$vs_old_idno = $t_object->get('idno');

			$t_object->addAttribute(array(
				'inventory_nr_old_nr' => $vs_old_idno,
				'inventory_nr_old_type' => 'bei Update Import',
			),'inventory_nr_old');

			$t_object->set('idno',$vs_idno);
		}

		// Ort Text und GeoNames (add)
		$vs_ort_text = trim((string)$o_sheet->getCellByColumnAndRow(12, $vn_row_num));
		$vs_ort_gn = trim((string)$o_sheet->getCellByColumnAndRow(13, $vn_row_num));

		if(strlen($vs_ort_text)>0 || strlen($vs_ort_gn)>0) { // Container wird gesetzt, wenn eins von beidem gesetzt ist!
			// Lookup Ort in GeoNames und baue String
			$vs_geonames_val = null;
			if(strlen($vs_ort_gn)>0) {
				if($va_item = get_geonames('object_places_geonames', $vs_ort_gn)) {
					if($va_item['id']) {
						$vs_geonames_val = $va_item['label'] . ' [id:' . $va_item['id'] . ']';
					}
				}
			}

			$t_object->addAttribute(array(
				'object_places_text' => $vs_ort_text,
				'object_places_geonames' => $vs_geonames_val,
			),'object_places');
		}

		// Datierung rechnerisch und Freitext, mit Typ - add
		$vs_date_text = trim((string)$o_sheet->getCellByColumnAndRow(14, $vn_row_num));
		$vs_date_calc = mmsGetDateTimeColumnFromSheet($o_sheet,15,$vn_row_num);
		$vs_date_type = trim((string)$o_sheet->getCellByColumnAndRow(16, $vn_row_num));
		if(strlen($vs_date_text)>0 || strlen($vs_date_calc)>0) { // Container wird gesetzt, wenn eins von beidem gesetzt ist!

			if(strlen($vs_date_calc)>0) {
				if(!$o_tep->parse($vs_date_calc)) {
					$vs_date_calc = null;
					mmsLog("Objekt-Update [{$ps_xlsx}]: Das rechnerische Datum ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_date_calc' und wurde ignoriert", Zend_Log::WARN);
				}
			}

			$vs_date_type_code = null;
			if(strlen($vs_date_type)>0) {
				$vs_date_type_code = $t_list->getItemIDFromListByLabel('dates_type_list', $vs_date_type);
			}

			$t_object->addAttribute(array(
				'dates_calculated' => $vs_date_calc,
				'dates_display' => $vs_date_text,
				'dates_type' => ($vs_date_type_code ? $vs_date_type_code : 'entstehung'),
			),'dates');
		}

		// Objektart (replace)
		$vs_objektart = trim((string)$o_sheet->getCellByColumnAndRow(17, $vn_row_num));
		if(strlen($vs_objektart)>0) {
			$t_object->replaceAttribute(array(
				'object_type_text' => $vs_objektart,
			),'object_type_text');
		}

		// Material/Technik (replace)
		$vs_material_technique = trim((string)$o_sheet->getCellByColumnAndRow(18, $vn_row_num));
		if(strlen($vs_material_technique)>0) {
			$t_object->replaceAttribute(array(
				'material_technique' => $vs_material_technique,
			),'material_technique');
		}

		// Maße (hinzufügen)
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

			$vn_dimensions_type_id = mmsGetListItemIDByLabel('dimension_types_list', $vs_dimensions_type, 'objektmass');

			switch(strtolower($vs_dimensions_geprueft)) {
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
				'dimensions_type' => $vn_dimensions_type_id,
				'dimensions_text' => $vs_dimensions_text,
				'dimensions_state' => $vs_dimensions_state,
			),'dimensions');

		}

		// Rahmung (ersetzen)
		$vs_rahmung = trim((string)$o_sheet->getCellByColumnAndRow(25, $vn_row_num));
		if(strlen($vs_rahmung)>0) {
			$t_object->replaceAttribute(array(
				'framing' => $vs_rahmung,
			),'framing');
		}

		// Signatur/Beschriftung (ersetzen)
		$vs_inscription = trim((string)$o_sheet->getCellByColumnAndRow(26, $vn_row_num));
		if(strlen($vs_inscription)>0) {
			$t_object->replaceAttribute(array(
				'inscription' => $vs_inscription,
			),'inscription');
		}

		// Beschreibung (hinzufügen)
		$vs_description = trim((string)$o_sheet->getCellByColumnAndRow(27, $vn_row_num));
		if(strlen($vs_description)>0) {
			$t_object->addAttribute(array(
				'add_information_object_text' => $vs_description,
				'add_information_object_type' => 'beschreibung'
			),'add_information_object');
		}

		// Sonstige Angaben (add)
		$vs_sonst_angaben = trim((string)$o_sheet->getCellByColumnAndRow(28, $vn_row_num));
		if(strlen($vs_sonst_angaben)>0) {
			$t_object->addAttribute(array(
				'add_information_object_text' => $vs_sonst_angaben,
				'add_information_object_type' => 'sonst_angaben'
			),'add_information_object');
		}

		// WIO bestimmt (add)
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

		// Provenienz (add)
		$vs_provenance = trim((string)$o_sheet->getCellByColumnAndRow(31, $vn_row_num));
		if(strlen($vs_provenance)>0) {
			$t_object->addAttribute(array(
				'provenance_previous_owner' => $vs_provenance,
			),'provenance');
		}

		// Kulturgutschutzliste
		$vs_cultural_artifact_list = trim((string)$o_sheet->getCellByColumnAndRow(32, $vn_row_num));
		if((strlen($vs_cultural_artifact_list)>0) && (strtolower($vs_cultural_artifact_list)=="x")) {
			$t_object->replaceAttribute(array(
				'cultural_artifact' => 'yes',
			),'cultural_artifact');
		} else {
			$t_object->replaceAttribute(array(
				'cultural_artifact' => 'no',
			),'cultural_artifact');
		}

		// Erwerbungen (replace)
		$vs_erwerbung_id = trim((string)$o_sheet->getCellByColumnAndRow(33, $vn_row_num));
		if(strlen($vs_erwerbung_id)>0) {
			if($t_lot->load($vs_erwerbung_id)) {
				$t_object->set('lot_id',$t_lot->getPrimaryKey());
			} else {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Konnte Erwerbung für Zeile {$vn_row_num} via ID nicht finden. Der Wert ist '{$vs_erwerbung_id}'. Beziehung zu Erwerbung konnte nicht hergestellt werden, die alte Erwerbung blieb bestehen.", Zend_Log::WARN);
			}
		}

		// Leihgeber (replace)
		$vs_leihgeber = trim((string)$o_sheet->getCellByColumnAndRow(34, $vn_row_num));
		if(strlen($vs_leihgeber)>0) {
			$t_object->replaceAttribute(array(
				'lender_owner' => $vs_leihgeber,
			),'lender_owner');
		}

		// Kosten / Wert des Objekts (replace)
		$vs_kosten_org = trim((string)$o_sheet->getCellByColumnAndRow(35, $vn_row_num));
		$vs_kosten_eur = trim((string)$o_sheet->getCellByColumnAndRow(36, $vn_row_num));
		if(strlen($vs_kosten_org)>0 || strlen($vs_kosten_eur)>0) {

			if(strlen($vs_kosten_eur)>0) {
				if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_kosten_eur)) {
					mmsLog("Objekt-Update [{$ps_xlsx}]: Der Wert des Objektes in EUR ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_kosten_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
					$vs_kosten_eur = null;
				}
			} else {
				$vs_kosten_eur = null;
			}

			$t_object->replaceAttribute(array(
				'value_original' => $vs_kosten_org,
				'value_eur' => $vs_kosten_eur,
			),'costs');
		}

		// Nebenkosten (replace)
		$vs_nk_eur = trim((string)$o_sheet->getCellByColumnAndRow(37, $vn_row_num));
		$vs_nk_bemerkung = trim((string)$o_sheet->getCellByColumnAndRow(38, $vn_row_num));
		if(strlen($vs_nk_bemerkung)>0 || strlen($vs_nk_eur)>0) {

			if(strlen($vs_nk_eur)>0) {
				if(!preg_match("/^[\d\.\,]+\s?EUR$/",$vs_nk_eur)) {
					mmsLog("Objekt-Update [{$ps_xlsx}]: Die Nebenkosten des Objektes in EUR sind nicht gültig für Zeile {$vn_row_num}. Der Wert ist '{$vs_nk_eur}', erwartet wird ein Wert im Format 'ZZZZ EUR'. Der Wert wurde ignoriert.", Zend_Log::WARN);
					$vs_nk_eur = null;
				}
			} else {
				$vs_nk_eur = null;
			}

			$t_object->replaceAttribute(array(
				'costs_additional_comment' => $vs_nk_bemerkung,
				'costs_additional_eur' => $vs_nk_eur,
			),'costs_additional');
		}

		// Literatur (mit Trennzeichen hinzufügen)
		$vs_literature = trim((string)$o_sheet->getCellByColumnAndRow(39, $vn_row_num));
		if(strlen($vs_literature)>0) {

			// wenn schon Wert vorhanden, neue Literaturangabe mit Trennzeichen anfügen
			if($vs_old_lit = $t_object->get('ca_objects.literature')) {
				$vs_literature = trim($vs_old_lit)." | ".$vs_literature;
			}

			$t_object->replaceAttribute(array(
				'literature' => $vs_literature,
			),'literature');
		}

		// Inventarnummer Alt (add)
		$vs_inv_nr_alt_nr = trim((string)$o_sheet->getCellByColumnAndRow(40, $vn_row_num));
		$vs_inv_nr_alt_type = trim((string)$o_sheet->getCellByColumnAndRow(41, $vn_row_num));
		if(strlen($vs_inv_nr_alt_nr)>0 || strlen($vs_inv_nr_alt_type)>0) {
			$va_inv_nr_alt_nr = explode(';', $vs_inv_nr_alt_nr);
			$va_inv_nr_alt_type = explode(';', $vs_inv_nr_alt_type);

			foreach($va_inv_nr_alt_nr as $vn_i => $vs_no) {
				if(!isset($va_inv_nr_alt_type[$vn_i])) {
					continue;
				}

				$t_object->addAttribute(array(
					'inventory_nr_old_nr' => trim($vs_no),
					'inventory_nr_old_type' => trim($va_inv_nr_alt_type[$vn_i]),
				),'inventory_nr_old');
			}
		}

		// Inventarisierung Kommentar (add)
		$vs_inv_comment = trim((string)$o_sheet->getCellByColumnAndRow(42, $vn_row_num));
		if(strlen($vs_inv_comment)>0) {

			// wenn schon Wert vorhanden, neuen Kommentar mit Trennzeichen anfügen
			if($vs_old_inv_comment = $t_object->get('ca_objects.inventory_comment')) {
				$vs_inv_comment = trim($vs_old_inv_comment)." | ".$vs_inv_comment;
			}

			$t_object->replaceAttribute(array(
				'inventory_comment' => $vs_inv_comment,
			),'inventory_comment');
		}

		// Copyright Info // Creditline (replace)
		$vs_creditline = trim((string)$o_sheet->getCellByColumnAndRow(43, $vn_row_num));
		if(strlen($vs_creditline)>0) {
			$t_object->replaceAttribute(array(
				'copyright_information' => $vs_creditline,
			),'copyright_information');
		}

		// ToDo Inventarisierung (add)
		$vs_todo_inv = trim((string)$o_sheet->getCellByColumnAndRow(46, $vn_row_num));
		if(strlen($vs_todo_inv)>0) {
			$t_object->addAttribute(array(
				'todo_inventory_description' => $vs_todo_inv,
			),'todo_inventory');
		}

		// Datenquelle (anfügen)
		$va_data_source = array();
		$vs_data_source = trim((string)$o_sheet->getCellByColumnAndRow(47, $vn_row_num));
		$vs_bearbeitet_von = trim((string)$o_sheet->getCellByColumnAndRow(48, $vn_row_num));
		$vs_bearbeitet_am = mmsGetDateTimeColumnFromSheet($o_sheet, 49, $vn_row_num);

		if(strlen($vs_data_source)>0) {
			$va_data_source[] = "Quelle: ".$vs_data_source;
		}

		if(strlen($vs_bearbeitet_von)>0) {
			$va_data_source[] = "Bearbeitet von: ".$vs_bearbeitet_von;
		}

		if(strlen($vs_bearbeitet_am)>0) {
			$va_data_source[] = "Bearbeitet Datum: ".$vs_bearbeitet_am;
		}

		if(sizeof($va_data_source)>0) {
			$vs_new_source = join("; ", $va_data_source);

			if($vs_old_source = $t_object->get('ca_objects.data_source')) {
				$vs_new_source = trim($vs_old_source)." | ".$vs_new_source;
			}

			$t_object->replaceAttribute(array(
				'data_source' => $vs_new_source,
			),'data_source');
		}

		// ToDo Datensatz (add)
		$vs_todo_datensatz = trim((string)$o_sheet->getCellByColumnAndRow(51, $vn_row_num));
		if(strlen($vs_todo_datensatz)>0) {
			$t_object->addAttribute(array(
				'todo_record_description' => $vs_todo_datensatz,
			),'todo_record');
		}

		// SAP Einzelnummer (replace)
		$vs_sap_einzelnr = trim((string)$o_sheet->getCellByColumnAndRow(52, $vn_row_num));
		if(strlen($vs_sap_einzelnr)>0) {
			$t_object->replaceAttribute(array(
				'sap_asset_nr' => $vs_sap_einzelnr,
			),'sap_asset_nr');
		}

		// UUID (replace)
		$vs_uuid = trim((string)$o_sheet->getCellByColumnAndRow(54, $vn_row_num));
		if(strlen($vs_uuid)>0) {
			$t_object->replaceAttribute(array(
				'uuid' => $vs_uuid,
			),'uuid');
		}

		// Inventarstatus des Objekts (replace)
		$vs_inventory_state = trim((string)$o_sheet->getCellByColumnAndRow(55, $vn_row_num));
		if(strlen($vs_inventory_state)>0) {
			$vs_inventory_state = mmsGetListItemIDByLabel('object_inventory_state_list', $vs_inventory_state, 'bestandsobjekt');

			$t_object->replaceAttribute(array(
				'inventory_state' => $vs_inventory_state,
			),'inventory_state');
		}

		// Datensatz geprüft (replace)
		$vs_datensatz_geprueft = trim((string)$o_sheet->getCellByColumnAndRow(56, $vn_row_num));
		if((strlen($vs_datensatz_geprueft)>0) && (strtolower($vs_datensatz_geprueft) == 'x')) {
			$t_object->replaceAttribute(array(
				'record_status' => 'yes',
			),'record_status');
		} else {
			$t_object->replaceAttribute(array(
				'record_status' => 'no',
			),'record_status');
		}

		// Abzug/Auflage (add)
		$vs_abzug_auflage = trim((string)$o_sheet->getCellByColumnAndRow(57, $vn_row_num));
		if(strlen($vs_abzug_auflage)>0) {
			$t_object->addAttribute(array(
				'edition' => $vs_abzug_auflage,
			),'edition');

		}

		// Zustand Beschreibung (add)
		$vs_zustand_beschreibung = trim((string)$o_sheet->getCellByColumnAndRow(58, $vn_row_num));
		if(strlen($vs_zustand_beschreibung)>0) {
			$t_object->addAttribute(array(
				'condition_description' => $vs_zustand_beschreibung,
			),'conditional_notes');
		}

		// Aufbewahrung (add)
		$vs_aufbewahrung = trim((string)$o_sheet->getCellByColumnAndRow(59, $vn_row_num));
		if(strlen($vs_aufbewahrung)>0) {
			$t_object->addAttribute(array(
				'safe_keeping' => $vs_aufbewahrung,
			),'safe_keeping');
		}

		// Negativnummer (add)
		$vs_negativnummer = trim((string)$o_sheet->getCellByColumnAndRow(60, $vn_row_num));
		if(strlen($vs_negativnummer)>0) {
			$t_object->addAttribute(array(
				'negative_number' => $vs_negativnummer,
			),'negative_number');
		}

		$t_object->update();

		if($t_object->numErrors()>0) {
			foreach($t_object->getErrors() as $vs_error) {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Import von Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
			}
			$o_trans->rollback();
			continue;
		}

		// AFTER UPDATE (RELATIONSHIPS; LABELS; ETC)

		// Titel (ersetzen)
		$vs_title = trim((string)$o_sheet->getCellByColumnAndRow(2, $vn_row_num));
		if(strlen($vs_title)>0) {
			$t_object->removeAllLabels(__CA_LABEL_TYPE_PREFERRED__);

			$t_object->addLabel(array(
				'name' => $vs_title,
			),$vn_locale_id,null,true);
		}

		// Aufbewahrungsort (mit Bearbeiter und Datum)
		// Wir fügen nur hinzu, das "Ersetzen" übernimmt das Plugin - hoffentlich.
		$vs_aufbewahrungsort = trim((string)$o_sheet->getCellByColumnAndRow(3, $vn_row_num));
		if(strlen($vs_aufbewahrungsort)>0) {
			if($t_loc->load(array("idno" => $vs_aufbewahrungsort))) {
				$vs_sl_date = mmsGetDateTimeColumnFromSheet($o_sheet,5,$vn_row_num);
				if(strlen($vs_sl_date)>0) {
					if(!$o_tep->parse($vs_sl_date)) {
						$vs_sl_date = "";
						mmsLog("Objekt-Update [{$ps_xlsx}]: Das Datum zum Aufbewahrungsort ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_sl_date' und wurde ignoriert", Zend_Log::WARN);
					}
				}

				$t_rel = $t_object->addRelationship('ca_storage_locations',$t_loc->getPrimaryKey(),'repository',$vs_sl_date);

				if($t_rel instanceof BaseRelationshipModel) {
					$t_rel->setMode(ACCESS_WRITE);

					$vs_sl_user = trim((string)$o_sheet->getCellByColumnAndRow(4, $vn_row_num));
					if(strlen($vs_sl_user)>0) {
						$t_rel->addAttribute(array(
							'storage_location_user' => $vs_sl_user,
						),'storage_location_user');

						$t_rel->update();
					}
				}
			} else {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Konnte ID für Aufbewahrungsort für Zeile {$vn_row_num} nicht finden. Der Wert ist '{$vs_aufbewahrungsort}'. Beziehung konnte nicht hergestellt werden.", Zend_Log::WARN);
			}
		}

		// Standort (mit Bearbeiter und Datum)
		// Wir fügen nur hinzu, die Historisierung übernimmt das Plugin - hoffentlich.
		$vs_standort = trim((string)$o_sheet->getCellByColumnAndRow(6, $vn_row_num));
		if(strlen($vs_standort)>0) {
			if($t_loc->load(array("idno" => $vs_standort))) {

				$vs_sl_date = mmsGetDateTimeColumnFromSheet($o_sheet,8,$vn_row_num);
				if(strlen($vs_sl_date)>0) {
					if(!$o_tep->parse($vs_sl_date)) {
						$vs_sl_date = "";
						mmsLog("Objekt-Update [{$ps_xlsx}]: Das Datum zum aktuellen Standort ist nicht gültig für Zeile {$vn_row_num}. Der Wert ist '$vs_sl_date' und wurde ignoriert", Zend_Log::WARN);
					}
				}

				$t_rel = $t_object->addRelationship('ca_storage_locations',$t_loc->getPrimaryKey(),'current_location',$vs_sl_date);

				if($t_rel instanceof BaseRelationshipModel) {
					$t_rel->setMode(ACCESS_WRITE);

					$vs_sl_user = trim((string)$o_sheet->getCellByColumnAndRow(7, $vn_row_num));
					if(strlen($vs_sl_user)>0) {
						$t_rel->addAttribute(array(
							'storage_location_user' => $vs_sl_user,
						),'storage_location_user');
						$t_rel->update();
					}
				}
			} else {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Konnte ID für aktuellen Standort für Zeile {$vn_row_num} nicht finden. Der Wert ist '{$vs_standort}'. Beziehung konnte nicht hergestellt werden.", Zend_Log::WARN);
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

					if(isset($va_entity_roles[$vn_i]) && strlen($va_entity_roles[$vn_i])>0) {
						$vs_rel_type = mmsGetRelTypeCodeByLabel('ca_objects_x_entities',$va_entity_roles[$vn_i]);
					}

					if(!$vs_rel_type) { $vs_rel_type = 'artist'; }

					$t_rel = $t_object->addRelationship('ca_entities', $t_entity->getPrimaryKey(), $vs_rel_type);

					if($t_rel instanceof BaseRelationshipModel) {

						if(isset($va_entity_attribution[$vn_i]) && strlen($va_entity_attribution[$vn_i])>0) {
							$vs_attribution_val_for_ca = null;

							switch(strtolower(trim($va_entity_attribution[$vn_i]))) {
								case 'x':
									$vs_attribution_val_for_ca = 'yes';
									break;
								case 'n':
									$vs_attribution_val_for_ca = 'no';
									break;
								default:
									break;
							}

							if(!is_null($vs_attribution_val_for_ca)) {
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

		// Objektgruppen, Konvolute (add)
		$vs_objektgruppen = trim((string)$o_sheet->getCellByColumnAndRow(44, $vn_row_num));
		if(strlen($vs_objektgruppen)>0) {
			$va_objektgruppen = explode(';',$vs_objektgruppen);

			foreach($va_objektgruppen as $vs_objektgruppe) {
				$vs_objektgruppe = trim($vs_objektgruppe);

				if($t_occ->load(array('idno' => $vs_objektgruppe))) {
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
						mmsLog("Objekt-Update [{$ps_xlsx}]: Beziehung zu Objektgruppe oder Konvolut mit ID {$vs_objektgruppe} konnte nicht hergestellt werden, da der Typ des Datensatzes nicht stimmt (keine Objektgruppe oder Konvolut).", Zend_Log::WARN);
					}
				} else {
					mmsLog("Objekt-Update [{$ps_xlsx}]: Beziehung zu Objektgruppe oder Konvolut mit ID {$vs_objektgruppe} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
				}
			}
		}

		// Ausstellungen (add)
		$vs_ausstellungen = trim((string)$o_sheet->getCellByColumnAndRow(45, $vn_row_num));
		if(strlen($vs_ausstellungen)>0) {
			$va_ausstellungen = explode(';',$vs_ausstellungen);

			foreach($va_ausstellungen as $vs_ausstellung) {
				$vs_ausstellung = trim($vs_ausstellung);

				if($t_occ->load(array('idno' => $vs_ausstellung))) {
					if($t_occ->getTypeCode() == 'exhibition') {
						$t_object->addRelationship('ca_occurrences',$t_occ->getPrimaryKey(),'exhibit_in');
					} else {
						mmsLog("Objekt-Update [{$ps_xlsx}]: Beziehung zu Ausstellung mit ID {$vs_ausstellung} konnte nicht hergestellt werden, da der Typ des Datensatzes nicht stimmt (keine Ausstellung).", Zend_Log::WARN);
					}
				} else {
					mmsLog("Objekt-Update [{$ps_xlsx}]: Beziehung zu Ausstellung mit ID {$vs_ausstellung} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
				}

			}
		}

		// Schlagworte (add)
		$vs_schlagworte = trim((string)$o_sheet->getCellByColumnAndRow(50, $vn_row_num));
		if(strlen($vs_schlagworte)>0) {
			$va_schlagworte = explode(";",$vs_schlagworte);
			foreach($va_schlagworte as $vs_schlagwort) {
				$vn_item_id = ca_list_items::find(array('preferred_labels' => array('name_singular' => trim($vs_schlagwort))),array('returnAs' => 'firstId'));
				if($vn_item_id) {
					$t_object->addRelationship('ca_list_items',$vn_item_id,'described');
				}
			}
		}

		// Sammlung (replace)
		$vs_sammlung = trim((string)$o_sheet->getCellByColumnAndRow(53, $vn_row_num));
		if(strlen($vs_sammlung)>0) {
			if($t_col->load(array('idno' => $vs_sammlung))) {
				$t_object->removeRelationships('ca_collections');
				$t_object->addRelationship('ca_collections', $t_col->getPrimaryKey(), 'related_to');
			} else {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Beziehung zu Sammlung mit ID {$vs_sammlung} konnte nicht hergestellt werden, da der Datensatz nicht gefunden wurde.", Zend_Log::WARN);
			}
		}

		if($t_object->numErrors()>0) {
			foreach($t_object->getErrors() as $vs_error) {
				mmsLog("Objekt-Update [{$ps_xlsx}]: Hinzufügen von Beziehungen zu Objekt aus Zeile {$vn_row_num} fehlgeschlagen. API Nachricht: {$vs_error}", Zend_Log::WARN);
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
