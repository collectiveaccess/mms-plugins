<?php
/* ----------------------------------------------------------------------
 * lhm/lib/SanityCheck.php
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/Parsers/TimeExpressionParser.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_collections.php');
require_once(__CA_LIB_DIR__.'/Search/ObjectSearch.php');
require_once(__CA_LIB_DIR__.'/Media.php');

require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'common.php');

/**
 * Spezielle Konstante für eine Helperfunktion unten, bei der wir einen extra Zustand definieren müssen.
 * Signalisiert, dass das aktuelle Level in der Schlagwort-Tabelle keine Daten enthält.
 */
define("__KEYWORD_TABLE_NO_DATA_IN_CURRENT_LEVEL__", -1);


class SanityCheck {

	public static $s_sanity_check_cfg;
	/**
	 * @var Zend_Log
	 */
	public static $s_zend_logger;
	private static $s_errors = array();
	private static $s_warnings = array();

	static public function getErrors() {
		return self::$s_errors;
	}

	static public function addError($ps_error) {
		self::$s_errors[] = $ps_error;

		if(self::hasZendLogger()) {
			self::$s_zend_logger->log($ps_error,Zend_Log::ERR);
		}
	}

	static public function addWarning($ps_warn) {
		self::$s_warnings[] = $ps_warn;

		if(self::hasZendLogger()) {
			self::$s_zend_logger->log($ps_warn,Zend_Log::WARN);
		}
	}

	static public function addZendLogger($po_logger) {
		if($po_logger instanceof Zend_Log) {
			self::$s_zend_logger = $po_logger;
		}
	}

	static public function hasZendLogger() {
		return (self::$s_zend_logger instanceof Zend_Log);
	}

	/**
	 * Überprüft Plausibilität der gegebenen Tabelle für den ausgewählten Import-Typ.
	 * Hier werden keine semantischen Tests durchgeführt, sondern es wird lediglich die grobe
	 * Form der Tabelle validiert (Dateityp, Anzahl Spalten, etc.).
	 * Fehlermeldungen werden gesammelt und wenn moeglich in Logfile geschrieben.
	 * @param string $ps_xlsx Pfad des Spreadsheets
	 * @param string $ps_type Importer-Typ, wie im Kommandozeilen-Parameter (storage-locations, keywords, etc.)
	 * @return bool Ergebnis des Tests
	 */
	static public function doCheck($ps_xlsx, $ps_type) {
		self::init();
		$va_sanity_check_cfg = self::$s_sanity_check_cfg;
		$o_tep = new TimeExpressionParser(null,__LHM_MMS_DEFAULT_LOCALE__);
		$o_locale = new Zend_Locale(__LHM_MMS_DEFAULT_LOCALE__);

		if(!isset($va_sanity_check_cfg[$ps_type])){
			self::addError("Keine Config für Plausibilitätscheck-Typ '$ps_type' gefunden. Check nicht möglich, Import wird abgebrochen.");
			return false;
		}

		$va_cfg = $va_sanity_check_cfg[$ps_type];

		$t_list = new ca_lists();

		// MIME-Typ überprüfen
		if(function_exists('mime_content_type')) { // Funktion ist deprecated
			$vs_mimetype = mime_content_type($ps_xlsx);
			if(!in_array($vs_mimetype, array(
				'application/vnd.ms-office',
				'application/octet-stream',
				'application/vnd.oasis.opendocument.spreadsheet',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
			))){
				self::addError("Plausibilitätscheck [{$ps_xlsx}]: Datei scheint kein Excel oder OpenOffice Spreadsheet zu sein (erkannter MIME Typ ist '$vs_mimetype').");
				return false;
			}
		}

		// Checken, ob PHPExcel das File lädt
		$o_excel = phpexcel_load_file($ps_xlsx);

		if(!$o_excel || !($o_excel instanceof PhpOffice\PhpSpreadsheet\Spreadsheet)){
			self::addError("Plausibilitätscheck [{$ps_xlsx}]: PHPExcel konnte Datei nicht laden");
			return false;
		}

		// Checken, ob PHPExcel das Sheet findet
		$o_sheet = $o_excel->getActiveSheet();

		if(!$o_sheet || !($o_sheet instanceof PhpOffice\PhpSpreadsheet\Worksheet\Worksheet)){
			self::addError("Plausibilitätscheck [{$ps_xlsx}]: PHPExcel konnte aktives Sheet nicht laden");
			return false;
		}

		// enthält die Tabelle überhaupt Daten?
		$vn_rows = count_nonempty_rows($ps_xlsx) - $va_cfg['header_rows'];

		if($vn_rows < 1){
			self::addError("Plausibilitätscheck [{$ps_xlsx}]: Datei hat keine zu verarbeitenden Zeilen");
			return false;
		}

		// Stimmt die Anzahl der Spalten?
		$vs_highest_column = $o_sheet->getHighestColumn();
		$vn_highest_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString((string)$vs_highest_column);

		if($vn_highest_column != $va_cfg['columns']) {
			self::addError("Plausibilitätscheck [{$ps_xlsx}]: Die Tabelle hat {$vn_highest_column} Spalten. Erwartet wurden ".$va_cfg['columns'].".");
			return false;
		}

		// Checks, für die wir durch die Daten loopen müssen
		$va_unique_col_data = array();
		$va_ascending_column_vals = array();
		$va_table_instances = array();
		$t_primary_key_check_instance = null;
		$vb_return = true;
		foreach ($o_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();

			if($vn_row_num <= $va_cfg['header_rows']){ continue; } // ignore headers

			// ignore rows that have no values in columns defined under skip_row_if_empty
			if(isset($va_cfg['skip_row_if_empty']) && is_array($va_cfg['skip_row_if_empty']) && sizeof($va_cfg['skip_row_if_empty'])>0) {
				$vb_skip = true;
				foreach($va_cfg['skip_row_if_empty'] as $vn_col) {
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0){
						$vb_skip = false;
						break;
					}
				}
				if($vb_skip) { continue; }
			}

			// Pflichtspalten überprüfen
			if(isset($va_cfg['mandatory_columns']) && is_array($va_cfg['mandatory_columns'])) {
				foreach($va_cfg['mandatory_columns'] as $vn_col) {
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)<1){
						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString((string)$vn_col);
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vn_col} ist Pflicht, aber hat keinen Wert für Zeile {$vn_row_num}.");
						$vb_return = false;
					}
				}
			}

			// Eindeutige Spalten überprüfen
			if(isset($va_cfg['unique_columns']) && is_array($va_cfg['unique_columns'])) {
				foreach($va_cfg['unique_columns'] as $vn_col){
					if(!is_array($va_unique_col_data[$vn_col])) { $va_unique_col_data[$vn_col] = array(); }

					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0){
						if(in_array($vs_val, $va_unique_col_data[$vn_col])){
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex((string)$vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll eindeutige Werte enthalten, aber der Wert in Zeile {$vn_row_num} ('$vs_val') existiert bereits in einer vorigen Zeile.");
							$vb_return = false;
						} else {
							$va_unique_col_data[$vn_col][] = $vs_val;
						}
					}
				}
			}

			// Primärschlüssel Spalte prüfen
			if(isset($va_cfg['primary_key_column']) && is_array($va_cfg['primary_key_column'])) {
				$vn_col = $va_cfg['primary_key_column']['column'];
				$vs_table = $va_cfg['primary_key_column']['table'];
				$vm_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
				if(strlen($vm_val)>0) {
					if(!is_numeric($vm_val)) {
						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll als Primärschlüssel für {$vs_table} benutzt werden, aber der Wert in Zeile {$vn_row_num} ('$vm_val') ist keine Zahl.");
						return false;
					}

					$vm_val = intval($vm_val);

					if(!$t_primary_key_check_instance) {
						$t_primary_key_check_instance = Datamodel::getInstance($vs_table);
					}

					if($t_primary_key_check_instance->load($vm_val)) {
						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll als Primärschlüssel für {$vs_table} benutzt werden, aber es gibt bereits einen Datensatz mit dem Wert in Zeile {$vn_row_num} ('$vm_val').");
						return false;
					}

					$vn_max_val = mmsGetMaxPkValue($t_primary_key_check_instance->tableName(), $t_primary_key_check_instance->primaryKey());

					if($vm_val <= $vn_max_val) {
						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll als Primärschlüssel für {$vs_table} benutzt werden, aber der Wert in Zeile {$vn_row_num} ('$vm_val') ist nicht größer als der bereits existierende größte Wert ('$vn_max_val'').");
						return false;
					}

					// Monotonie sollte extra geprüft werden mit 'ascending_columns' Check!

				}
			}

			// Streng monotone Spalten prüfen
			if(isset($va_cfg['ascending_columns']) && is_array($va_cfg['ascending_columns'])) {
				foreach($va_cfg['ascending_columns'] as $vn_col) {
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0) {
						if(!is_numeric($vs_val)) {
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll aufsteigende Werte enthalten, aber der Wert in Zeile {$vn_row_num} ('$vs_val') ist keine Zahl.");
							return false;
						}

						if(!isset($va_ascending_column_vals[$vn_col])) { // Erster Wert
							$va_ascending_column_vals[$vn_col] = intval($vs_val);
							continue;
						}

						if(intval($vs_val) <= $va_ascending_column_vals[$vn_col]) {
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll aufsteigende Werte enthalten, aber der Wert in Zeile {$vn_row_num} ('$vs_val') ist kleiner oder gleich wie der bisherige größte Wert.");
							return false;
						} else {
							$va_ascending_column_vals[$vn_col] = intval($vs_val);
						}
					}
				}
			}

			// Whitelisted Spalten prüfen
			if(isset($va_cfg['whitlelisted_columns']) && is_array($va_cfg['whitlelisted_columns'])) {
				foreach($va_cfg['whitlelisted_columns'] as $vn_col => $va_col_vals){
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(!in_array($vs_val, $va_col_vals)){
						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} hat eine Whitelist und der Wert in Zeile {$vn_row_num} ('$vs_val') gehört nicht zu den erlaubten Werten.");
						$vb_return = false;
					}
				}
			}

			// Listenspalten prüfen
			if(isset($va_cfg['list_columns']) && is_array($va_cfg['list_columns'])) {
				foreach($va_cfg['list_columns'] as $vn_col => $vs_list_code){
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0){
						if(!$t_list->getItemIDFromListByLabel($vs_list_code, $vs_val)){
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Werte in Spalte {$vs_col} sollen via Bezeichnung auf Liste {$vs_list_code} verweisen. Wert '$vs_val' konnte in dieser Liste nicht gefunden werden.");
							$vb_return = false;
						}
					}
				}
			}

			// Datumsspalten überprüfen
			if(isset($va_cfg['date_columns']) && is_array($va_cfg['date_columns'])) {
				foreach($va_cfg['date_columns'] as $vn_col){
					$vs_val = mmsGetDateTimeColumnFromSheet($o_sheet,$vn_col,$vn_row_num);
					// wenn ein Wert existiert, muss er parse-bar sein!
					if(strlen($vs_val)>0) {
						if(!$o_tep->parse($vs_val)) {
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} sollte nur CA-konforme Datumswerte enthalten. Der Wert in Zeile {$vn_row_num} ('$vs_val') ist kein solches Datum.");
							$vb_return = false;
						}
					}
				}
			}

			// Beziehungs-Spalten überprüfen
			if(isset($va_cfg['relationship_columns']) && is_array($va_cfg['relationship_columns'])) {
				foreach($va_cfg['relationship_columns'] as $vn_col => $va_info){
					if(!isset($va_table_instances[$vn_col])) { $va_table_instances[$vn_col] = Datamodel::getInstance($va_info['table']); }
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));

					if(strlen($vs_val)>0){ // Beziehungsspalten sind in der Regel optional, also prüfe nur, wenn auch Wert eingesetzt wurde
						if(isset($va_info['delimiter']) && $va_info['delimiter']){
							$va_val = explode(trim($va_info['delimiter']),$vs_val);
							foreach($va_val as $vs_v){
								$vs_v = trim($vs_v);

								if($va_info['field'] == 'label'){
									if(!$va_table_instances[$vn_col]->loadByLabel(array($va_table_instances[$vn_col]->getLabelDisplayField() => $vs_v))){
										self::addError("Plausibilitätscheck [{$ps_xlsx}]: Für Spalte {$vs_col} sollen existierende ".$va_info['display']." geladen werden. Für einen Wert in Zeile {$vn_row_num} ('$vs_v') lässt sich kein Datensatz finden.");
										$vb_return = false;
									}
								} elseif(!$va_table_instances[$vn_col]->load(array($va_info['field'] => $vs_v))) {
									$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
									self::addError("Plausibilitätscheck [{$ps_xlsx}]: Für Spalte {$vs_col} sollen existierende ".$va_info['display']." geladen werden. Für einen Wert in Zeile {$vn_row_num} ('$vs_v') lässt sich kein Datensatz finden.");
									$vb_return = false;
								}
							}
						} else {
							if(!$va_table_instances[$vn_col]->load(array($va_info['field'] => $vs_val))) {
								$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
								self::addError("Plausibilitätscheck [{$ps_xlsx}]: Für Spalte {$vs_col} sollen existierende ".$va_info['display']." geladen werden. Für den Wert in Zeile {$vn_row_num} ('$vs_val') lässt sich kein Datensatz finden.");
								$vb_return = false;
							}
						}
					}
				}
			}

			// Reguläre Ausdrücke checken
			if(isset($va_cfg['regex_columns']) && is_array($va_cfg['regex_columns'])) {
				foreach($va_cfg['regex_columns'] as $vn_col => $vs_regex){
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0){ // Spalten sind in der Regel optional, also prüfe nur, wenn auch Wert eingesetzt wurde
						if(!preg_match($vs_regex,$vs_val)) {
							$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Der Wert in Spalte {$vs_col} soll dem regulären Ausdruck '{$vs_regex}' entsprechen. Auf den Wert in Zeile {$vn_row_num} ('$vs_val') trifft das nicht zu.");
							$vb_return = false;
						}
					}
				}
			}

			// Multivalue Bundles prüfen (i.e. existieren nach Trennung in jedem Wert der aufgelisteten Felder gleich viele Werte)
			if(isset($va_cfg['multivalue_bundles']) && is_array($va_cfg['multivalue_bundles'])) {
				foreach($va_cfg['multivalue_bundles'] as $va_value_bundle) {
					$vn_value_count = null;
					foreach($va_value_bundle as $vn_col) {
						$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
						if(strlen($vs_val)>0){
							$vn_actual = sizeof(explode(';',$vs_val));
							if(is_null($vn_value_count)) { // Erster Wert => Setze "expected"
								$vn_value_count = $vn_actual;
							} else {
								if($vn_value_count != $vn_actual){
									$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);
									self::addError("Plausibilitätscheck [{$ps_xlsx}]: Für Spalte {$vs_col} in Zeile {$vn_row_num} wurden {$vn_value_count} Semikolon-getrennte Werte erwartet, ermittelt auf Basis des Werts einer früheren Spalte. Gefunden wurden stattdessen {$vn_actual}.");
									$vb_return = false;
								}
							}
						}
					}
				}
			}

			// Währungsspalten pruefen
			if(isset($va_cfg['currency_columns']) && is_array($va_cfg['currency_columns'])) {
				foreach($va_cfg['currency_columns'] as $vn_col ) {
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));
					if(strlen($vs_val)>0){ // Spalten sind in der Regel optional, also prüfe nur, wenn auch Wert eingesetzt wurde

						$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);

						if (preg_match("!^([\d\.\,]+)([^\d]+)$!", $vs_val, $va_matches)) {
							$vs_decimal_value = $va_matches[1];
							$vs_currency_specifier = trim($va_matches[2]);

							if($vs_currency_specifier != "EUR") {
								self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Währungs-Werte in EUR enthalten. Der Wert in Zeile {$vn_row_num} hat keine ('$vs_val') oder die falsche Währung.");
								$vb_return = false;
							}

							try {
								$vn_value = Zend_Locale_Format::getNumber($vs_decimal_value, array('locale' => $o_locale, 'precision' => 2));

								if(floatval($vn_value) > 99999999999999) {
									self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Währungs-Werte in EUR enthalten. Der Wert in Zeile {$vn_row_num} ist zu groß.");
									$vb_return = false;
								}

							} catch (Zend_Locale_Exception $e){
								self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Währungs-Werte in EUR enthalten. Der Wert in Zeile {$vn_row_num} ('$vs_val') ist kein solcher Wert. Das Dezimalformat stimmt nicht: ".$e->getMessage());
								$vb_return = false;
							}

						} else {
							self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Währungs-Werte in EUR enthalten. Der Wert in Zeile {$vn_row_num} ('$vs_val') ist kein solcher Wert. Der reguläre Ausdruck schlug fehl.");
							$vb_return = false;
						}
					}
				}
			}

			// Text columns with boundaries
			if(isset($va_cfg['text_columns']) && is_array($va_cfg['text_columns'])) {

				foreach($va_cfg['text_columns'] as $vn_col => $va_col_info) {
					$vs_val = trim((string)$o_sheet->getCellByColumnAndRow($vn_col, $vn_row_num));

					if(($va_col_info['min'] == 0) && (strlen($vs_val)<1)) { // Spalten mit min=0 sind optional
						continue;
					}

					$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_col);

					if (strlen($vs_val) < $va_col_info['min']) {
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Textwerte einer gewissen Länge enthalten. Der Wert in Zeile {$vn_row_num} ist zu kurz.");
						$vb_return = false;
					}

					if (strlen($vs_val) > $va_col_info['max']) {
						self::addError("Plausibilitätscheck [{$ps_xlsx}]: Spalte {$vs_col} soll Textwerte einer gewissen Länge enthalten. Der Wert in Zeile {$vn_row_num} ist zu lang.");
						$vb_return = false;
					}
				}
			}

		}

		// Custom Checks rufen
		if(isset($va_cfg['custom_check_functions']) && is_array($va_cfg['custom_check_functions'])) {
			foreach($va_cfg['custom_check_functions'] as $vs_function) {
				if(!call_user_func("SanityCheck::{$vs_function}", $o_sheet)){
					self::addError("Plausibilitätscheck [{$ps_xlsx}]: Die spezielle Check-Funktion '{$vs_function}' fand einen Fehler im aktuellen Sheet.");
					$vb_return = false;
				}
			}
		}

		return $vb_return;
	}

	######################################################################################
	## Funktionen für spezielle Checks, die entweder schwer zu verallgemeinern sind
	## oder wo es sich nicht lohnt, eine Verallgemeinerung zu implementieren
	######################################################################################

	/**
	 * Prüfe, ob Erwerbungen Label (SGL) ODER idno (SGL) haben. Eins von beidem muss für jede Zeile existieren.
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkLotsForLabelOrIdno($po_sheet) {
		return self::checkSomethingForEitherOr($po_sheet,1,2);
	}

	/**
	 * Prüfe, ob Entity Lebensdaten korrekt ausgefüllt sind (i.e. genau dann, wenn Text existiert, existiert auch rechnerisch)
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkEntitiesForLifeDates($po_sheet) {
		return self::checkSomethingForIfOneThenBoth($po_sheet,4,5);
	}

	/**
	 * Prüfe, ob Maße beim Objekt-Update korrekt ausgefüllt sind. Bedeutet, wenn Freitext, Höhe, Breite oder Tiefe gesetzt sind, muss auch Maß Art gesetzt sein.
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkObjectUpdateForDimensions($po_sheet) {
		return (
			self::checkSomethingForIfOneThenBoth($po_sheet,19,23)
			&&
			self::checkSomethingForIfOneThenBoth($po_sheet,20,23)
			&&
			self::checkSomethingForIfOneThenBoth($po_sheet,21,23)
			&&
			self::checkSomethingForIfOneThenBoth($po_sheet,22,23)
		);
	}

	/**
	 * Prüfe, ob Objekt Datumsangaben korrekt ausgefüllt sind (i.e. genau dann, wenn Text existiert, existiert auch rechnerisch)
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkObjectsForDisplayDates($po_sheet) {
		return self::checkSomethingForIfOneThenBoth($po_sheet,14,15);
	}

	/**
	 * Prüfe, ob ein Sheet für jede Zeile außer dem Header einen Wert in mindestens einer von zwei gegebenen Spalten hat.
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @param int $pn_either Erste Spalte
	 * @param int $pn_or Zweite Spalte
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkSomethingForEitherOr($po_sheet,$pn_either,$pn_or) {

		$vs_either = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_either);
		$vs_or = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_or);
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip row with headers

			$vs_idno = trim((string)$po_sheet->getCellByColumnAndRow($pn_either, $vn_row_num));
			$vs_label = trim((string)$po_sheet->getCellByColumnAndRow($pn_or, $vn_row_num));

			if( (strlen($vs_idno)<1) && (strlen($vs_label)<1) ){
				self::addError("Plausibilitätscheck [Entweder-Oder]: Beim aktuellen Format müssen für jede Zeile entweder Spalte {$vs_either} oder Spalte {$vs_or} gesetzt sein. Für Zeile {$vn_row_num} ist das nicht der Fall.");
				$vb_return = false;
			}
		}
		return $vb_return;
	}

	/**
	 * Prüfe, ob bei einem Sheet für jede Zeile gilt: $pn_one hat genau dann einen Wert, wenn $pn_two einen hat.
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @param int $pn_one Erste Spalte
	 * @param int $pn_two Zweite Spalte
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkSomethingForIfOneThenBoth($po_sheet,$pn_one,$pn_two) {

		$vs_one_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_one);
		$vs_two_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_two);
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip row with headers

			$vs_one_val = trim((string)$po_sheet->getCellByColumnAndRow($pn_one, $vn_row_num));
			$vs_two_val = trim((string)$po_sheet->getCellByColumnAndRow($pn_two, $vn_row_num));

			if((strlen($vs_one_val)>0) || (strlen($vs_two_val)>0)){
				if(!((strlen($vs_one_val)>0) && (strlen($vs_two_val)>0))) {
					self::addError("Plausibilitätscheck [Wenn-Eins-Dann-Beide]: Beim aktuellen Format muss für jede Zeile gelten: {$vs_one_col} hat genau dann einen Wert, wenn {$vs_two_col} einen hat. Für Zeile {$vn_row_num} ist das nicht der Fall.");
					$vb_return = false;
				}
			}
		}

		return $vb_return;
	}

	/**
	 * Prüfe, ob die Spaltenüberschriften bei Standort-Importen valide sind. Sie sollten auf existierende Standort-Typen verweisen
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkStorageLocationHeaders($po_sheet) {
		$t_list = new ca_lists();

		$vn_i = 1; // Startindex [0 = nutzerdefinierte idno]

		do {
			$vs_head = trim((string)$po_sheet->getCellByColumnAndRow($vn_i, 1));
			$va_item = $t_list->getItemFromListByLabel('storage_location_types', $vs_head);

			if(!isset($va_item['item_id'])){
				$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($vn_i);
				self::addError("Plausibilitätscheck [Standorte speziell]: Bei Standort-Importen müssen die Einträge in der Kopfzeile (Zeile 1) valide Standort-Typen-Bezeichnungen enthalten, ausgenommen Spalten A,Q,R. Dies ist für Spalte {$vs_col} ('{$vs_head}') nicht der Fall.");
				return false;
			}

			$vn_i += 2; // um 2 erhöht, weil immer eine Spalte für Trennzeichen dazwischen ist
		} while($vn_i < 16); // 16+17 [Q+R] sind Attribut-Spalten

		return true;
	}

	/**
	 * Prüft, ob die aus den einzelnen Bestandteilen zusammengesetzte Standort ID mit der vom Nutzer gesetzten in der ersten Spalte übereinstimmt.
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkStorageLocationIDNOConcatenation($po_sheet) {
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip row with headers

			$vs_user_idno = trim($po_sheet->getCellByColumnAndRow(0, $vn_row_num)->getCalculatedValue());
			$vn_label_column = 1;
			$vs_concat_idno = "";

			do {
				$vs_label_part = $po_sheet->getCellByColumnAndRow($vn_label_column, $vn_row_num)->getFormattedValue();
				$vs_delimiter = $po_sheet->getCellByColumnAndRow($vn_label_column+1, $vn_row_num)->getFormattedValue();

				if(strlen($vs_label_part)>0 || strlen($vs_delimiter)>0) {
					$vs_concat_idno .= $vs_label_part.$vs_delimiter;
				}

				$vn_label_column += 2;
			} while($vn_label_column < 16);

			$vs_concat_idno = trim($vs_concat_idno);

			if($vs_concat_idno != $vs_user_idno) {
				self::addError("Plausibilitätscheck [Standorte speziell]: Bei Standort-Importen muss die automatisch zusammengesetzte Standort ID mit der in Spalte A übereinstimmtn. In Zeile {$vn_row_num} ist dies nicht der Fall ({$vs_user_idno} != {$vs_concat_idno}).");
				$vb_return = false;
			}
		}

		return $vb_return;
	}

	/**
	 * Prüft für UUID-Import-Modus des Medienimporters, für alle UUID Werte Objekte vorhanden sind
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkUUIDForMediaImport($po_sheet) {
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip rows with headers

			$vs_uuid = trim((string)$po_sheet->getCellByColumnAndRow(0, $vn_row_num));

			$o_search = new ObjectSearch();
			$o_result = $o_search->search('ca_objects.uuid:"'.$vs_uuid.'"', array('dontFilterByACL' => true));
			$vn_num_hits = $o_result->numHits();
			if($vn_num_hits<1) {
				self::addError("Plausibilitätscheck [Medien speziell]: Konnte UUID '$vs_uuid' für Zeile $vn_row_num nicht mindestens einem Objekt zuordnen.");
				$vb_return = false;
			}
		}
		return $vb_return;
	}

	/**
	 * Prüft für Medienimporte, ob alle Dateien existieren, sie bereits in der DB vorhanden sind (führt nicht zum Abbruch)
	 * und sich vom Medienprozessor verarbeiten lassen (i.e. keine kaputten oder nicht unterstützten Files)
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkFileExistsForMediaImport($po_sheet) {
		global $g_media_import_base_path;

		$o_media = new Media();
		$vb_return = true;
		$o_db = new Db();

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip rows with headers

			// Dateipfad + Dateiname
			$vs_file = trim((string)$po_sheet->getCellByColumnAndRow(1, $vn_row_num));

			// this returns false if the file doesn't exist
			$vs_local_path = mmsGetRealPath($g_media_import_base_path.DIRECTORY_SEPARATOR.$vs_file);
			if(!$vs_local_path) {
				self::addError("Plausibilitätscheck [Medien speziell]: Konnte Datei '$vs_file' für Zeile $vn_row_num nicht lesen oder finden.");
				$vb_return = false;
				continue;
			}

			// Bei potentiellen Duplikaten wird nur noch eine Warnung ausgegeben, führt nicht mehr zum Fehler
			$vs_md5 = md5_file($vs_local_path);
			$qr_md5 = $o_db->query("SELECT representation_id FROM ca_object_representations WHERE md5=? AND deleted=0", $vs_md5);
			if($qr_md5->numRows() > 0) {
				$qr_md5->nextRow();
				self::addWarning("Plausibilitätscheck [Medien speziell]: Datei '$vs_local_path' in Zeile $vn_row_num scheint bereits in der Datenbank vorhanden zu sein. MD5 Pruefsumme war {$vs_md5}. Medium ID ist ".$qr_md5->get('representation_id').".");
				if($qr_md5->numRows() > 1) {
					self::addError("Plausibilitätscheck [Medien speziell]: Datei '$vs_local_path' in Zeile $vn_row_num scheint bereits mehrfach in der Datenbank vorhanden zu sein! MD5 Pruefsumme war {$vs_md5}.");
					$vb_return = false;
				}
			}

			if(!($o_media->divineFileFormat($vs_local_path))) {
				self::addError("Plausibilitätscheck [Medien speziell]: Media Processor konnte Format für Datei '$vs_local_path' in Zeile $vn_row_num nicht bestimmen oder Dateiformat wird nicht unterstützt.");
				$vb_return = false;
			}
		}

		return $vb_return;
	}

	/**
	 * Prüft für Objektimporte, ob die Beziehungs-Definitionen für Entities okay sind.
	 * Für weitere Details zum erwarteten Format, siehe Import-Spezifikation (Matchingtabelle)
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkObjectsForProperEntityDefs($po_sheet){
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip rows with headers

			$vs_base_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(9);

			$vs_person = trim((string)$po_sheet->getCellByColumnAndRow(9, $vn_row_num));
			$vs_role = trim((string)$po_sheet->getCellByColumnAndRow(10, $vn_row_num));
			$vs_attribution = trim((string)$po_sheet->getCellByColumnAndRow(11, $vn_row_num));

			if(strlen($vs_person)>0){
				$vn_expected = sizeof(explode(';',$vs_person));

				if(strlen($vs_role)>0) {
					$va_roles = explode(';', $vs_role);
					if($vn_expected != sizeof($va_roles)) {
						$vs_broken_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(10);
						self::addError("Plausibilitätscheck [Objekte speziell]: Es wird erwartet, dass in Spalte {$vs_broken_col} gleich viele Werte vorhanden sind wie in Spalte {$vs_base_col}. In Zeile $vn_row_num ist das nicht der Fall.", Zend_Log::WARN);
						$vb_return = false;
					}

					foreach($va_roles as $vs_r){
						if(!mmsGetRelTypeCodeByLabel('ca_objects_x_entities',trim($vs_r))) {
							$vs_broken_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(10);
							self::addError("Plausibilitätscheck [Objekte speziell]: Es wird erwartet, dass in Spalte {$vs_broken_col} nur Referenzen auf existierende Rollenbezeichnungen vorhanden sind. In Zeile $vn_row_num ist das nicht der Fall, der Wert {$vs_r} konnte nicht zugeordnet werden.", Zend_Log::WARN);
							$vb_return = false;
						}
					}
				}

				if(strlen($vs_attribution)>0) {
					if($vn_expected != sizeof(explode(';', $vs_attribution))) {
						$vs_broken_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(11);
						self::addError("Plausibilitätscheck [Objekte speziell]: Es wird erwartet, dass in Spalte {$vs_broken_col} gleich viele Werte vorhanden sind wie in Spalte {$vs_base_col}. In Zeile $vn_row_num ist das nicht der Fall.", Zend_Log::WARN);
						$vb_return = false;
					}
				}
			}
		}

		return $vb_return;
	}

	/**
	 * Prüft, ob die Werte in der Spalte für Versicherungsdatum alle korrekte CA-Daten sind.
	 * Da Versicherungsdaten ein Wiederholfeld sind, brauchen wir hier einen extra Check.
	 * Es handelt sich um das einzige Vorkommen, lohnt also nicht, das zu verallgemeinern
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkObjectsForInsuranceDates($po_sheet) {
		$o_tep = new TimeExpressionParser(null,__LHM_MMS_DEFAULT_LOCALE__);
		$vb_return = true;

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip rows with headers

			$vs_insurance_date_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(40);
			$vs_insurance_dates = trim((string)$po_sheet->getCellByColumnAndRow(40, $vn_row_num));

			if(strlen($vs_insurance_dates)>0) {
				$va_insurance_dates = explode(';',$vs_insurance_dates);

				foreach($va_insurance_dates as $vs_ins) {
					if(!$o_tep->parse(trim($vs_ins))) {
						self::addError("Plausibilitätscheck [Objekt-Insert speziell]: Spalte {$vs_insurance_date_col} sollte nur semikolon-getrennte, CA-konforme Datumswerte enthalten. Ein Wert in Zeile {$vn_row_num} ('$vs_ins') ist kein solches Datum.");
						$vb_return = false;
					}
				}
			}
		}

		return $vb_return;
	}

	/**
	 * Prüft, ob sich für Objekte die zugeordnete Sammlung ändert und setzt einen Log-Eintrag falls das der Fall ist.
	 * Da es sich nicht um eine Abbruchbedingung handelt, wird hier immer 'true' zurückgegeben!
	 * @param PHPExcel_Worksheet $po_sheet Das Tabellenblatt
	 * @return boolean Immer true!
	 */
	static public function checkObjectsForChangedCollection($po_sheet) {
		$t_object = new ca_objects();

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1) continue; // skip rows with headers

			$vs_object_id = trim((string)$po_sheet->getCellByColumnAndRow(0, $vn_row_num));
			$vs_collection_idno = trim((string)$po_sheet->getCellByColumnAndRow(55, $vn_row_num));

			if(strlen($vs_collection_idno)>0){

				$t_object->load($vs_object_id); // Ist bereits durch vorige Plausibilitätschecks sichergestellt

				if($vn_col_id = intval(ca_collections::find(array('idno' => $vs_collection_idno), array('returnAs' => 'firstId')))) {

					$vn_current_col_id = intval($t_object->get('ca_collections.collection_id'));
					$vs_old_collection_idno = $t_object->get('ca_collections.idno');

					if($vn_current_col_id != $vn_col_id) {
						self::addError("Plausibilitätscheck [Objekt-Update speziell]: Objekt in Zeile {$vn_row_num} würde eine neue Sammlung zugeordnet bekommen! Alt: {$vs_old_collection_idno}, Neu: {$vs_collection_idno}");
					}
				}
			}
		}

		return true;
	}

	/**
	 * Spezielle Überprüfung für Schlagworte. Hier ist es so, dass einzelne Spalten zu
	 * Pflichtspalten werden, wenn in einer Ebene Daten existieren. Für weitere Infos
	 * siehe Matchingtabelle/Importdefinition.
	 * @param PHPExcel_Worksheet $po_sheet
	 * @return boolean Bedingung erfüllt oder nicht?
	 */
	static public function checkKeywordTableForMandatoryFields($po_sheet) {

		foreach ($po_sheet->getRowIterator() as $o_row) {
			$vn_row_num = $o_row->getRowIndex();
			if($vn_row_num==1 || $vn_row_num==2) continue; // skip rows with headers

			$vn_current_singular_ptr = 6;
			while(($vm_ret = self::checkKeyWordTableLevel($po_sheet, $vn_current_singular_ptr, $vn_row_num)) !== __KEYWORD_TABLE_NO_DATA_IN_CURRENT_LEVEL__) {
				if($vm_ret == false) { return false; }
				$vn_current_singular_ptr += 5; // jedes Level hat 5 Spalten (sing,plu,idno,aktiv,default)
			}
		}

		return true;
	}

	/**
	 * Helper für aktuelles Level in der Keyword-Tabelle
	 */
	static public function checkKeyWordTableLevel($po_sheet, $pn_singular_col, $pn_row_id) {
		$vs_name_singular = trim((string)$po_sheet->getCellByColumnAndRow($pn_singular_col, $pn_row_id));

		if(strlen($vs_name_singular)>0) { // es gibt Daten in dieser Ebene -> Singular, Plural, idno werden Pflicht
			$vs_name_plural = trim((string)$po_sheet->getCellByColumnAndRow($pn_singular_col+1, $pn_row_id));

			if(strlen($vs_name_plural)<1) {
				$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_singular_col+1);
				self::addError("Plausibilitätscheck [Schlagworte speziell]: Bei Schlagwort-Importen müssen für alle Einträge, die Daten in bestimmten Ebenen enthalten, Singular, Plural und IDNO ausgefüllt sein. In Zeile {$pn_row_id} ist dies nicht der Fall (Spalte {$vs_col}).");
				return false;
			}

			$vs_idno = trim((string)$po_sheet->getCellByColumnAndRow($pn_singular_col+2, $pn_row_id));

			if(strlen($vs_idno)<1) {
				$vs_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pn_singular_col+2);
				self::addError("Plausibilitätscheck [Schlagworte speziell]: Bei Schlagwort-Importen müssen für alle Einträge, die Daten in bestimmten Ebenen enthalten, Singular, Plural und IDNO ausgefüllt sein. In Zeile {$pn_row_id} ist dies nicht der Fall (Spalte {$vs_col}).");
				return false;
			}

			return true;
		} else {
			return __KEYWORD_TABLE_NO_DATA_IN_CURRENT_LEVEL__;
		}
	}

	static public function init() {
		if(!is_array(self::$s_sanity_check_cfg)) {
			self::$s_sanity_check_cfg = array(
				'lots' => array(
					'columns' => 15,
					'header_rows' => 1,
					'primary_key_column' => array('column' => 0, 'table' => 'ca_object_lots'),
					'unique_columns' => array(0),
					'ascending_columns' => array(0),
					'date_columns' => array(5),
					'list_columns' => array(
						3 => 'object_lot_types',
						4 => 'object_lot_statuses'
					),
					'regex_columns' => array(
						12 => "/^[\d]{4,4}$/",
						13 => "/^[\d]{8,8}$/",
					),
					'whitlelisted_columns' => array(
						14 => array('X','x',''),
					),
					'currency_columns' => array(9,10),
					'text_columns' => array(
						1 => array('min' => 0, 'max' => 255),
						2 => array('min' => 0, 'max' => 1024),
						6 => array('min' => 0, 'max' => 65535),
						7 => array('min' => 0, 'max' => 65535),
						8 => array('min' => 0, 'max' => 255),
						11 => array('min' => 0, 'max' => 65535),
					),
					'custom_check_functions' => array('checkLotsForLabelOrIdno'),
				),
				'entities' => array(
					'columns' => 21,
					'header_rows' => 1,
					'primary_key_column' => array('column' => 0, 'table' => 'ca_entities'),
					'unique_columns' => array(0),
					'ascending_columns' => array(0),
					'date_columns' => array(5),
					'text_columns' => array(
						2 => array('min' => 0, 'max' => 100), // nachname
						3 => array('min' => 0, 'max' => 100), // vorname
						4 => array('min' => 0, 'max' => 255), // lebensdaten anzeige
						5 => array('min' => 0, 'max' => 255), // Geburtsort Text
						7 => array('min' => 0, 'max' => 255), // Sterbeort Text
						9 => array('min' => 0, 'max' => 255), // Ort mit best Art Text
						13 => array('min' => 0, 'max' => 100), // alt nachname
						14 => array('min' => 0, 'max' => 100), // alt vorname
						16 => array('min' => 0, 'max' => 255), // beruf
						17 => array('min' => 0, 'max' => 255), // gnd
						18 => array('min' => 0, 'max' => 65535), // WIP Text
					),
					'whitlelisted_columns' => array(
						1 => array('', 'p', 'i', 'e'),
						20 => array('','x','X'),
					),
					'multivalue_bundles' => array(
						array(13,14,15)
					),
					'list_columns' => array(
						12 => 'person_places_type_list',
						19 => 'add_information_person_list'
					),
					'custom_check_functions' => array('checkEntitiesForLifeDates'),
				),
				'exhibitions' => array(
					'columns' => 10,
					'header_rows' => 1,
					'mandatory_columns' => array(0),
					'unique_columns' => array(0),
					'date_columns' => array(3),
					'list_columns' => array(
						1 => 'exhibition_type_list'
					),
					'text_columns' => array(
						0 => array('min' => 0, 'max' => 255), // idno
						2 => array('min' => 0, 'max' => 1024), // title
						4 => array('min' => 0, 'max' => 255), // ort der ausstellung
						5 => array('min' => 0, 'max' => 255), // institution
						6 => array('min' => 0, 'max' => 255), // weitere orte
						7 => array('min' => 0, 'max' => 255), // kurator
						8 => array('min' => 0, 'max' => 65535), // katalog
						9 => array('min' => 0, 'max' => 65535), // remark
					),
				),
				'object-groups' => array(
					'columns' => 4,
					'header_rows' => 1,
					'mandatory_columns' => array(0,1),
					'unique_columns' => array(0),
					'whitlelisted_columns' => array(
						1 => array('o','k','O','K'),
					),
					'text_columns' => array(
						0 => array('min' => 0, 'max' => 255), // idno
						2 => array('min' => 0, 'max' => 1024), // title
						3 => array('min' => 0, 'max' => 65535), // beschreibung
					),
				),
				'media' => array(
					'columns' => 4,
					'header_rows' => 1,
					'mandatory_columns' => array(0,1,2,3),
					'list_columns' => array(
						2 => 'object_representation_types',
						3 => 'workflow_statuses'
					),
					'relationship_columns' => array(
						0 => array('table' => 'ca_objects', 'field' => 'object_id', 'display' => 'Objekte'),
					),
					'text_columns' => array(
						1 => array('min' => 0, 'max' => 1024), // original filename
					),
					'custom_check_functions' => array('checkFileExistsForMediaImport')
				),
				'media-uuid' => array(
					'columns' => 4,
					'header_rows' => 1,
					'mandatory_columns' => array(0,1,2,3),
					'list_columns' => array(
						2 => 'object_representation_types',
						3 => 'workflow_statuses'
					),
					'text_columns' => array(
						1 => array('min' => 0, 'max' => 1024), // original filename
					),
					'custom_check_functions' => array(
						'checkUUIDForMediaImport',
						'checkFileExistsForMediaImport'
					)
				),
				'keywords' => array(
					'columns' => 36,
					'header_rows' => 2,
					'mandatory_columns' => array(0,1,2,3),
					'whitelisted_columns' => array(
						0 => array('category_list', 'subject_group_list', 'style_list'),
						// "aktiv"-Spalten
						4 => array('nein', 'ja', ''),
						9 => array('nein', 'ja', ''),
						14 => array('nein', 'ja', ''),
						19 => array('nein', 'ja', ''),
						24 => array('nein', 'ja', ''),
						29 => array('nein', 'ja', ''),
						34 => array('nein', 'ja', ''),
						// "default"-Spalten
						5 => array('x', 'X', ''),
						10 => array('x', 'X', ''),
						15 => array('x', 'X', ''),
						20 => array('x', 'X', ''),
						25 => array('x', 'X', ''),
						30 => array('x', 'X', ''),
						35 => array('x', 'X', ''),
					),
					'custom_check_functions' => array('checkKeywordTableForMandatoryFields'),
					'text_columns' => array(
						0 => array('min' => 0, 'max' => 100), // list code
						// ebene 1
						1 => array('min' => 0, 'max' => 255), // singular
						2 => array('min' => 0, 'max' => 255), // plural
						3 => array('min' => 0, 'max' => 255), // idno
						// ebene 2
						6 => array('min' => 0, 'max' => 255), // singular
						7 => array('min' => 0, 'max' => 255), // plural
						8 => array('min' => 0, 'max' => 255), // idno
						// ebene 3
						11 => array('min' => 0, 'max' => 255), // singular
						12 => array('min' => 0, 'max' => 255), // plural
						13 => array('min' => 0, 'max' => 255), // idno
						// ebene 4
						16 => array('min' => 0, 'max' => 255), // singular
						17 => array('min' => 0, 'max' => 255), // plural
						18 => array('min' => 0, 'max' => 255), // idno
						// ebene 5
						21 => array('min' => 0, 'max' => 255), // singular
						22 => array('min' => 0, 'max' => 255), // plural
						23 => array('min' => 0, 'max' => 255), // idno
						// ebene 6
						26 => array('min' => 0, 'max' => 255), // singular
						27 => array('min' => 0, 'max' => 255), // plural
						28 => array('min' => 0, 'max' => 255), // idno
						// ebene 7
						31 => array('min' => 0, 'max' => 255), // singular
						32 => array('min' => 0, 'max' => 255), // plural
						33 => array('min' => 0, 'max' => 255), // idno
					),
				),
				'storage-locations' => array(
					'columns' => 18,
					'header_rows' => 1,
					'mandatory_columns' => array(0,1),
					'unique_columns' => array(0),
					'skip_row_if_empty' => array(0,1),
					'text_columns' => array(
						0 => array('min' => 0, 'max' => 255), // idno
						16 => array('min' => 0, 'max' => 65535), // remark
					),
					'custom_check_functions' => array(
						'checkStorageLocationHeaders',
						'checkStorageLocationIDNOConcatenation'
					),
				),
				'objects' => array(
					'columns' => 65,
					'header_rows' => 1,
					'mandatory_columns' => array(),
					'unique_columns' => array(54),
					'ascending_columns' => array(0),
					'primary_key_column' => array('column' => 0, 'table' => 'ca_objects'),
					'date_columns' => array(5,8,15),
					'whitlelisted_columns' => array(
						24 => array('x','X',''),
						32 => array('x','X',''),
						58 => array('x','X',''),
					),
					'list_columns' => array(
						16 => 'dates_type_list',
						23 => 'dimension_types_list',
						30 => 'add_information_object_list',
						57 => 'object_inventory_state_list',
					),
					'relationship_columns' => array(
						3 => array('table' => 'ca_storage_locations', 'field' => 'idno', 'display' => 'Aufbewahrungsorte'),
						6 => array('table' => 'ca_storage_locations', 'field' => 'idno', 'display' => 'aktuelle Standorte'),
						9 => array('table' => 'ca_entities', 'field' => 'entity_id', 'delimiter' => ';', 'display' => 'Personen'),
						33 => array('table' => 'ca_object_lots', 'field' => 'lot_id', 'display' => 'Erwerbungen'),
						46 => array('table' => 'ca_occurrences', 'field' => 'idno', 'delimiter' => ';', 'display' => 'Objektgruppen/Konvolute'),
						47 => array('table' => 'ca_occurrences', 'field' => 'idno', 'delimiter' => ';', 'display' => 'Ausstellungen'),
						52 => array('table' => 'ca_list_items', 'field' => 'label', 'delimiter' => ';', 'display' => 'Schlagworte/Sachgruppen'),
						55 => array('table' => 'ca_collections', 'field' => 'idno', 'display' => 'Sammlungen'),
					),
					'regex_columns' => array(
						// wiederholbares Ja/Nein-Feld für Zuschreibung
						11 => "/^([xXnN]{0,1}[\s\;]*)+$/",
						// Maße
						20 => "/^[\d\.\,]+\s?cm$/",
						21 => "/^[\d\.\,]+\s?cm$/",
						22 => "/^[\d\.\,]+\s?cm$/",
						// wiederholbare Währung
						39 => "/^([\d\.\,]+\s?EUR[\s\;]*)+$/",
						// Zahl
						54 => "/^[\d]{8,8}$/"
					),
					'currency_columns' => array(36,37),
					'multivalue_bundles' => array(
						array(39,40),
						array(42,43)
					),
					'text_columns' => array(
						1 => array('min' => 0, 'max' => 255), // idno
						2 => array('min' => 0, 'max' => 1024), // titel
						4 => array('min' => 0, 'max' => 255), // storage location user
						7 => array('min' => 0, 'max' => 255), // storage location user
						12 => array('min' => 0, 'max' => 255), // ort text
						14 => array('min' => 0, 'max' => 255), // datierung anzeige
						17 => array('min' => 0, 'max' => 255), // Objektart
						18 => array('min' => 0, 'max' => 65535), // Material/Technik
						19 => array('min' => 0, 'max' => 65535), // Maßfreitext
						25 => array('min' => 0, 'max' => 255), // Rahmung
						26 => array('min' => 0, 'max' => 65535), // Signatur/Beschriftung
						27 => array('min' => 0, 'max' => 65535), // WIO
						28 => array('min' => 0, 'max' => 65535), // WIO
						29 => array('min' => 0, 'max' => 65535), // WIO
						31 => array('min' => 0, 'max' => 1024), // Provenance
						34 => array('min' => 0, 'max' => 255), // Leihgeber/Miteigentümer
						35 => array('min' => 0, 'max' => 255), // Kosten original
						38 => array('min' => 0, 'max' => 65535), // Nebenkosten Bemerkung
						41 => array('min' => 0, 'max' => 65535), // Literatur
						42 => array('min' => 0, 'max' => 255), // Inv Nr Alt Nummer
						43 => array('min' => 0, 'max' => 255), // Inv Nr Alt Typ
						44 => array('min' => 0, 'max' => 65535), // Inventarisierung Kommentar
						45 => array('min' => 0, 'max' => 1024), // Creditline
						48 => array('min' => 0, 'max' => 255), // TODO Inventarisierung Text
						49 => array('min' => 0, 'max' => 65535), // Datenquelle
						50 => array('min' => 0, 'max' => 65535), // Datenquelle
						53 => array('min' => 0, 'max' => 255), // TODO Datensatz Text
						56 => array('min' => 0, 'max' => 255), // UUID
					),
					'custom_check_functions' => array(
						'checkObjectsForDisplayDates',
						'checkObjectsForInsuranceDates',
						'checkObjectsForProperEntityDefs',
					),
				),
				'objects-update' => array(
					'columns' => 61,
					'header_rows' => 1,
					'mandatory_columns' => array(0), // object_id is mandatory!
					'unique_columns' => array(54), // uuid
					'ascending_columns' => array(),
					'date_columns' => array(5,8,15),
					'whitlelisted_columns' => array(
						24 => array('x','X',''),
						32 => array('x','X',''),
						56 => array('x','X',''),
					),
					'list_columns' => array(
						16 => 'dates_type_list',
						23 => 'dimension_types_list',
						30 => 'add_information_object_list',
						55 => 'object_inventory_state_list',
					),
					'relationship_columns' => array(
						0 => array('table' => 'ca_objects', 'field' => 'object_id', 'display' => 'Objekte'),
						3 => array('table' => 'ca_storage_locations', 'field' => 'idno', 'display' => 'Aufbewahrungsorte'),
						6 => array('table' => 'ca_storage_locations', 'field' => 'idno', 'display' => 'aktuelle Standorte'),
						9 => array('table' => 'ca_entities', 'field' => 'entity_id', 'delimiter' => ';', 'display' => 'Personen'),
						33 => array('table' => 'ca_object_lots', 'field' => 'lot_id', 'display' => 'Erwerbungen'),
						44 => array('table' => 'ca_occurrences', 'field' => 'idno', 'delimiter' => ';', 'display' => 'Objektgruppen/Konvolute'),
						45 => array('table' => 'ca_occurrences', 'field' => 'idno', 'delimiter' => ';', 'display' => 'Ausstellungen'),
						50 => array('table' => 'ca_list_items', 'field' => 'label', 'delimiter' => ';', 'display' => 'Schlagworte/Sachgruppen'),
						53 => array('table' => 'ca_collections', 'field' => 'idno', 'display' => 'Sammlungen'),
					),
					'regex_columns' => array(
						// wiederholbares Ja/Nein-Feld für Zuschreibung
						11 => "/^([xXnN]{0,1}[\s\;]*)+$/",
						// Maße
						20 => "/^[\d\.\,]+\s?cm$/",
						21 => "/^[\d\.\,]+\s?cm$/",
						22 => "/^[\d\.\,]+\s?cm$/",
						// Zahl
						54 => "/^[\d]{8,8}$/"
					),
					'currency_columns' => array(36,37),
					'multivalue_bundles' => array(
						array(40,41), // inventarnr alt
					),
					'text_columns' => array(
						1 => array('min' => 0, 'max' => 255), // idno
						2 => array('min' => 0, 'max' => 1024), // titel
						4 => array('min' => 0, 'max' => 255), // storage location user
						7 => array('min' => 0, 'max' => 255), // storage location user
						12 => array('min' => 0, 'max' => 255), // ort text
						14 => array('min' => 0, 'max' => 255), // datierung anzeige
						17 => array('min' => 0, 'max' => 255), // Objektart
						18 => array('min' => 0, 'max' => 65535), // Material/Technik
						19 => array('min' => 0, 'max' => 65535), // Maßfreitext
						25 => array('min' => 0, 'max' => 255), // Rahmung
						26 => array('min' => 0, 'max' => 65535), // Signatur/Beschriftung
						27 => array('min' => 0, 'max' => 65535), // WIO
						28 => array('min' => 0, 'max' => 65535), // WIO
						29 => array('min' => 0, 'max' => 65535), // WIO
						31 => array('min' => 0, 'max' => 1024), // Provenance
						34 => array('min' => 0, 'max' => 255), // Leihgeber/Miteigentümer
						35 => array('min' => 0, 'max' => 255), // Kosten original
						38 => array('min' => 0, 'max' => 65535), // Nebenkosten Bemerkung
						39 => array('min' => 0, 'max' => 65535), // Literatur
						40 => array('min' => 0, 'max' => 255), // Inv Nr Alt Nummer
						41 => array('min' => 0, 'max' => 255), // Inv Nr Alt Typ
						42 => array('min' => 0, 'max' => 65535), // Inventarisierung Kommentar
						43 => array('min' => 0, 'max' => 1024), // Creditline
						46 => array('min' => 0, 'max' => 255), // TODO Inventarisierung Text
						47 => array('min' => 0, 'max' => 65535), // Datenquelle
						48 => array('min' => 0, 'max' => 65535), // Datenquelle
						51 => array('min' => 0, 'max' => 255), // TODO Datensatz Text
						54 => array('min' => 0, 'max' => 255), // UUID
					),
					'custom_check_functions' => array(
						'checkObjectsForDisplayDates',
						'checkObjectsForProperEntityDefs',
						'checkObjectsForChangedCollection',
						//'checkObjectUpdateForDimensions'
					)
				)
			);
		}

		return true;
	}
}
