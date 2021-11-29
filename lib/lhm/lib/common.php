<?php

require_once(__CA_APP_DIR__."/helpers/initializeLocale.php");
require_once(__CA_APP_DIR__."/helpers/mailHelpers.php");

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_object_lots.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');

require_once(__CA_LIB_DIR__.'/Parsers/TimeExpressionParser.php');
require_once(__CA_LIB_DIR__.'/Utils/DataMigrationUtils.php');
require_once(__CA_LIB_DIR__.'/Db.php');
require_once(__CA_APP_DIR__."/helpers/CLIHelpers.php");
require_once(__CA_LIB_DIR__."/Utils/CLIUtils.php");

require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'defines.php');

# ---------------------------------------------------------------------
/**
 * Log message through Zend_Log facilities set up in lhm_mms_import.php
 * @param string $ps_message the log message
 * @param int $pn_level log level as Zend_Log level integer:
 *        one of Zend_Log::DEBUG, Zend_Log::INFO, Zend_Log::WARN, Zend_Log::ERR
 * @return bool success state
 */
function mmsLog($ps_message, $pn_level) {
	global $g_logger;

	if(!in_array($pn_level, array(Zend_Log::DEBUG, Zend_Log::INFO, Zend_Log::WARN, Zend_Log::ERR))){
		return false;
	}

	if($g_logger instanceof Zend_Log) {
		$g_logger->log($ps_message,$pn_level);
	}

	if($pn_level == Zend_Log::ERR) {
		CLIUtils::addError("\t".$ps_message.PHP_EOL);
	}

	return true;
}
# ---------------------------------------------------------------------
/**
 * Log error to console and log facilities and exit
 * @param string $ps_message the error message
 */
function mmsCritError($ps_message) {
	mmsLog($ps_message, Zend_Log::ERR);
	exit(255);
}
# ---------------------------------------------------------------------
/**
 * Beende mit kritischem Fehler, aber sende vorher Email-Report
 *
 * @param string $ps_message Fehlernachricht / Subject der Email
 * @param string $ps_logfile Pfad zur Logdatei (Body der Email)
 * @param Configuration $po_hf_conf HF Konfiguration
 */
function mmsHFCritErrorWithMailReport($ps_message,$ps_logfile,$po_hf_conf) {
	CLIUtils::addError("\t".$ps_message.PHP_EOL);
	mmsLog($ps_message, Zend_Log::ERR);

	mmsHFMailReport($ps_message, $ps_logfile, $po_hf_conf);

	exit(255);
}
# ---------------------------------------------------------------------
/**
 * Sende Hot-Folder Email Report
 *
 * @param string $ps_subject Subject der Mail

 * @param string $ps_logfile Logfile, aus dem der Body der Mail kommt
 * @param Configuration $po_hf_conf Hot-Folder Konfiguration
 * @return bool Erfolgsstatus
 */
function mmsHFMailReport($ps_subject, $ps_logfile, $po_hf_conf) {

	if(!is_object($po_hf_conf)) { return false; }

	$va_mails = $po_hf_conf->getList('hf_email_addresses');
	$va_to = array();

	foreach($va_mails as $vs_mail) {
		$va_to[$vs_mail] = $vs_mail;
	}

	$vs_log_content = @file_get_contents($ps_logfile);
	if(!$vs_log_content) { $vs_log_content = 'Logdatei nicht lesbar'; }

	$ps_subject = "[MMS HotFolder] ".date('m-d-Y').": ".$ps_subject;

	return caSendmail($va_to, $po_hf_conf->get('hf_mail_from'), $ps_subject, $vs_log_content);
}
# ---------------------------------------------------------------------
/**
 * Fetch maximum primary key value for given table and pk name
 * @param string $ps_table table name
 * @param string $ps_primary_key primary key name
 * @return int maximum value
 */
function mmsGetMaxPkValue($ps_table,$ps_primary_key){
	$o_db = mmsGetReusableDbInstance();

	$qr_max = $o_db->query("SELECT MAX({$ps_primary_key}) AS max FROM {$ps_table}");
	if($qr_max->nextRow()){
		return intval($qr_max->get('max'));
	} else {
		return 0;
	}
}
# ---------------------------------------------------------------------
/**
 * Get reusable Db instance
 * @return Db
 */
function mmsGetReusableDbInstance() {
	if(MemoryCache::contains('db', 'MMSObjects')) {
		return MemoryCache::fetch('db', 'MMSObjects');
	} else {
		$o_db = new Db();
		MemoryCache::save('db', $o_db, 'MMSObjects');
		return $o_db;
	}
}
# ---------------------------------------------------------------------
/**
 * Run PHP Garbage collector
 */
function mmsGC() {
	gc_enable(); // Enable Garbage Collector
	gc_collect_cycles();
	gc_disable(); // Disable Garbage Collector
}
# ---------------------------------------------------------------------
/**
 * Holt Type Code für ein gegebenes Beziehungstyp-Label
 * @param string $ps_rel_table Tabellenname
 * @param string $ps_label Das zu suchende Label
 * @return string der type code
 */
function mmsGetRelTypeCodeByLabel($ps_rel_table,$ps_label) {
	if(MemoryCache::contains($ps_rel_table.':'.$ps_label, 'MMSRelTypeCodesByLabel')) {
		return MemoryCache::fetch($ps_rel_table.':'.$ps_label, 'MMSRelTypeCodesByLabel');
	}
	$o_dm = Datamodel::load();
	$o_db = mmsGetReusableDbInstance();
	$t_instance = $o_dm->getInstanceByTableName($ps_rel_table);
	if($t_instance){
		$vn_table_num = $t_instance->tableNum();

		$qr_r = $o_db->query("SELECT * FROM ca_relationship_types, ca_relationship_type_labels WHERE
            ca_relationship_types.type_id = ca_relationship_type_labels.type_id
            AND
            ca_relationship_types.table_num = ?
            AND
            ((ca_relationship_type_labels.typename LIKE ?)  OR (ca_relationship_type_labels.typename_reverse LIKE ?))
        ",$vn_table_num,$ps_label,$ps_label);

		if($qr_r->nextRow()){
			$vs_code = $qr_r->get('type_code');
			MemoryCache::save($ps_rel_table.':'.$ps_label, $vs_code, 'MMSRelTypeCodesByLabel');
			return $vs_code;
		}

		MemoryCache::save($ps_rel_table.':'.$ps_label, false, 'MMSRelTypeCodesByLabel');
		return false;
	}
	return false;
}
# ---------------------------------------------------------------------
/**
 * Custom path info function that actually deals with special chars
 * @param $ps_path
 * @return string
 */
function mmsGetRealPath($ps_path) {
	$va_path_info = pathinfo($ps_path);
	$vs_local_path = $va_path_info['dirname'].DIRECTORY_SEPARATOR.$va_path_info['basename'];
	return realpath($vs_local_path);
}
# ---------------------------------------------------------------------
/**
 * Sucht gegebenes Item in der gegebenen Liste und extrahiert den Identifikator
 * @param string $ps_list_code Code zur Identifikation der Liste
 * @param string $ps_label Das zu suchende Label
 * @param null|string $ps_default optionaler Default-Wert, auf den zurückgefallen wird, sollte kein Listenelement gefunden werden
 * @return bool|string Identifikator (item_id oder code) des Listenitems zur Weiterverwendung z.B. in set() oder addAttribute()
 */
function mmsGetListItemIDByLabel($ps_list_code, $ps_label, $ps_default=null) {
	$t_list = new ca_lists();

	if(!$ps_label) {
		if($ps_default) {
			return $t_list->getItemIDFromList($ps_list_code,$ps_default);
		} else {
			return false;
		}
	}
	if(MemoryCache::contains($ps_list_code.':'.$ps_label, 'MMSListItemIDsByLabel')) {
		return MemoryCache::fetch($ps_list_code.':'.$ps_label, 'MMSListItemIDsByLabel');
	} else {
		$va_item = $t_list->getItemFromListByLabel($ps_list_code, $ps_label);

		if($va_item && isset($va_item['item_id'])) {
			MemoryCache::save($ps_list_code.':'.$ps_label, $va_item['item_id'], 'MMSListItemIDsByLabel');
			return $va_item['item_id'];
		} else {

			if($ps_default) {
				return $t_list->getItemIDFromList($ps_list_code,$ps_default);
			}

			return false;
		}
	}
}
# ---------------------------------------------------------------------
/**
 * Sucht gegebenes Item in der gegebenen Liste und extrahiert den Wert
 * @param string $ps_list_code Code zur Identifikation der Liste
 * @param string $ps_label Das zu suchende Label
 * @return bool|string item_value des Listenitems zur Weiterverwendung z.B. in set()
 */
function mmsGetListItemValueByLabel($ps_list_code,$ps_label) {
	if(MemoryCache::contains($ps_list_code.':'.$ps_label, 'MMSListItemValuesByLabel')) {
		return MemoryCache::fetch($ps_list_code.':'.$ps_label, 'MMSListItemValuesByLabel');
	}

	$t_list = new ca_lists();

	$va_item = $t_list->getItemFromListByLabel($ps_list_code, $ps_label);

	if($va_item && isset($va_item['item_value'])) {
		MemoryCache::save($ps_list_code.':'.$ps_label, $va_item['item_value'], 'MMSListItemValuesByLabel');
		return $va_item['item_value'];

	} else {
		MemoryCache::save($ps_list_code.':'.$ps_label, false, 'MMSListItemValuesByLabel');
		return false;
	}
}
# ---------------------------------------------------------------------
/**
 * Hole Datum für gegebene Zelle aus Excel/ODS Tabelle. Konvertiere Excel Datum nach ISO für
 * TimeExpressionParser, wenn nötig. Manche Datumsspalten enthalten echte Zeitstempel, manche enthalten Daten als Strings.
 * @param PHPExcel_Worksheet $po_sheet Das Tabellensheet
 * @param int $pn_col Spaltennummer
 * @param int $pn_row Zeilennummer
 * @return string|null Das Datum, falls ein Wert existiert
 */
function mmsGetDateTimeColumnFromSheet($po_sheet,$pn_col,$pn_row) {
	$o_val = $po_sheet->getCellByColumnAndRow($pn_col, $pn_row);
	$vs_val = trim((string)$o_val);
	if(strlen($vs_val)>0){
		// Es kann sich um ein echtes Excel Datum handeln. In dem Fall konvertiere nach ISO für TEP
		if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($o_val)){
			$vs_timestamp = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($vs_val);
			$vs_return = date('d.m.Y',$vs_timestamp);
		} else {
			$vs_return = $vs_val;
		}
	} else {
		$vs_return = null;
	}

	return $vs_return;
}
# ---------------------------------------------------------------------
/**
 * Prueft, ob Medien- und Archivverzeichnisse vom aktuellen Nutzer beschreibbar sind.
 *
 * @return bool
 */
function mmsMediaAndArchiveDirsAreWritable() {
	$va_media_list = caGetSubDirectoryList(__MMS_INSTANCE_MEDIA_ROOT_DIR__, true);

	foreach(array_keys($va_media_list) as $vs_dir) {
		if(!is_writable($vs_dir)) {
			mmsLog("Verzeichnis {$vs_dir} ist für den aktuellen Benutzer nicht beschreibbar.", Zend_Log::ERR);
			return false;
		}
	}

	$va_archive_list = caGetSubDirectoryList(__MMS_INSTANCE_ARCHIVE_ROOT_DIR__, true);

	foreach(array_keys($va_archive_list) as $vs_dir) {
		if(!is_writable($vs_dir)) {
			mmsLog("Verzeichnis {$vs_dir} ist für den aktuellen Benutzer nicht beschreibbar.", Zend_Log::ERR);
			return false;
		}
	}

	return true;
}
# ---------------------------------------------------------------------
/**
 * Loads the given file into a PhpSpreadsheet object using common settings for preserving memory and performance
 * @param string $ps_xlsx file name
 * @return PhpSpreadsheet
 */
function phpexcel_load_file($ps_xlsx){
	if(MemoryCache::contains($ps_xlsx, 'MMSExcel')) {
		return MemoryCache::fetch($ps_xlsx, 'MMSExcel');
	} else {
		/**  Identify the type  **/

		mmsLog("Fange an, Datei {$ps_xlsx} via PHPExcel zu laden.", Zend_Log::DEBUG);
		$t = new Timer();

		$o_excel = \PhpOffice\PhpSpreadsheet\IOFactory::load($ps_xlsx);

		mmsLog('Laden erledigt. Benötigte Zeit: '.$t->getTime().'s. Maximal benötigter Speicher: '.(memory_get_peak_usage(true) / 1024 / 1024).'MB', Zend_Log::DEBUG);

		MemoryCache::save($ps_xlsx, $o_excel, 'MMSExcel');
		return $o_excel;
	}
}
# ---------------------------------------------------------------------
/**
 * Counts non-empty rows in spreadsheet
 *
 * @param string $ps_xlsx absolute path to spreadsheet
 * @param string $ps_sheet optional sheet to use for counting
 * @return int row count
 */
function count_nonempty_rows($ps_xlsx,$ps_sheet="") {
	if(MemoryCache::contains($ps_xlsx, 'MMSRowCounts')) {
		return MemoryCache::fetch($ps_xlsx, 'MMSRowCounts');
	} else {
		$o_excel = phpexcel_load_file($ps_xlsx);
		if(strlen($ps_sheet)>0){
			$o_sheet = $o_excel->getSheetByName($ps_sheet);
		} else {
			$o_sheet = $o_excel->getActiveSheet();
		}

		$vn_highest_row = intval($o_sheet->getHighestRow());
		MemoryCache::save($ps_xlsx, $vn_highest_row, 'MMSRowCounts');
		return $vn_highest_row;
	}
}
# ---------------------------------------------------------------------
/**
 * Sendet Anfrage an GeoNames und cacht das Ergebnis, so dass z.B. ein einem Objekt-Import
 * nicht mehrere Anfragen nach "München" gesendet werden müssen.
 * @param $ps_element_code
 * @param $ps_query
 * @return array|bool
 */
function get_geonames($ps_element_code,$ps_query) {

	if(MemoryCache::contains($ps_query, 'MMSGeoNames')) {
		return MemoryCache::fetch($ps_query, 'MMSGeoNames');
	}

	$t_element = new ca_metadata_elements();
	$t_element->load(array('element_code' => $ps_element_code));
	$vs_gn_elements = $t_element->getSetting('gnElements');
	$vs_gn_delimiter = $t_element->getSetting('gnDelimiter');

	$pa_elements = explode(',',$vs_gn_elements);

	$vo_conf = Configuration::load();
	$vs_user = trim($vo_conf->get("geonames_user"));

	if ($ps_query) {
		$vs_base = $vo_conf->get('geonames_api_base_url') . '/search';
		$va_params = array(
			"q" => $ps_query,
			"lang" => 'de',
			'style' => 'full',
			'username' => $vs_user,
			'maxRows' => 20,
		);

		$vs_query_string = '';
		foreach ($va_params as $vs_key => $vs_value) {
			$vs_query_string .= "$vs_key=" . urlencode($vs_value) . "&";
		}

		try {
			$vs_xml = caQueryExternalWebservice("{$vs_base}?$vs_query_string");
			$vo_xml = new SimpleXMLElement($vs_xml);

			$va_attr = $vo_xml->status ? $vo_xml->status->attributes() : null;
			if ($va_attr && isset($va_attr['value']) && ((int)$va_attr['value'] > 0)) {
				mmsLog("GeoNames: Auflösen von '{$ps_query}' via GeoNames.org ergab folgenden Text: {$vs_xml}", Zend_Log::DEBUG);
				MemoryCache::save($ps_query, false, 'MMSGeoNames');
				return false;
			} else {
				foreach($vo_xml->children() as $vo_child){
					if($vo_child->getName()=="geoname"){
						$va_elements = array();

						foreach($pa_elements as $ps_element){
							$vs_val = $vo_child->{trim($ps_element)};
							if(strlen(trim($vs_val))>0){
								$va_elements[] = trim($vs_val);
							}
						}

						$va_item = array(
							'displayname' => $vo_child->name,
							'label' => join($vs_gn_delimiter,$va_elements).
								($vo_child->lat ? " [".$vo_child->lat."," : '').
								($vo_child->lng ? $vo_child->lng."]" : ''),
							'lat' => $vo_child->lat ? $vo_child->lat : null,
							'lng' => $vo_child->lng ? $vo_child->lng : null,
							'id' => (string)$vo_child->geonameId
						);

						MemoryCache::save($ps_query, $va_item, 'MMSGeoNames');
						return $va_item;
					}
				}
			}
		} catch (Exception $e) {
			mmsLog("Exception in GeoNames Processor beim Verarbeiten von Query '{$ps_query}': ".$e->getMessage(), Zend_Log::ERR);
			MemoryCache::save($ps_query, false, 'MMSGeoNames');
			return false;
		}
	}

	MemoryCache::save($ps_query, false, 'MMSGeoNames');
	return false;
}
# -------------------------------------------------------
