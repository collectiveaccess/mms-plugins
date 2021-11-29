<?php

require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'storage_locations.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'keywords.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'media.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'objects.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'objects_update.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'lots.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'entities.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'exhibits.php');
require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'object_groups.php');

require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'SanityCheck.php');

# -------------------------------------------------------
class Dispatcher {
	# -------------------------------------------------------
	public static function storage_locationsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function storage_locationsHelp() {
		return "Importiert Standorte aus XLS(X) Tabellen";
	}
	# -------------------------------------------------------
	public static function storage_locations($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Standorte: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file
		if(!file_exists($vs_xlsx)){
			mmsCritError("Standorte: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual import
		if(!do_storage_location_import($vs_xlsx)){
			exit(255);
		}

		return true;
	}
	# -------------------------------------------------------
	public static function keywordsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function keywordsHelp() {
		return "Importiert Schlagworte aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function keywords($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Schlagworte: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Schlagworte: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!keyword_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function mediaParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
			"base|b=s" => "Basispfad, in dem sich alle in der Tabelle referenzierten Dateien befinden."
		);
	}
	# -------------------------------------------------------
	public static function mediaHelp() {
		return "Importiert Mediendateien aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function media($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Medien: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		if(!($vs_base_path = $o_opts->getOption('b'))){
			mmsCritError("Medien: Basispfad ist Pflichtparameter. Versuchen Sie --help für eine Liste von Optionen.");
		}

		if(!file_exists($vs_base_path) || !is_dir($vs_base_path) || !is_readable($vs_base_path) ) {
			mmsCritError("Medien: Basispfad {$vs_base_path} existiert nicht, kann nicht gelesen werden oder ist kein Verzeichnis.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Medien: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		/*print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Dateien importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}*/

		// do actual insert
		if(!media_import($vs_xlsx,$vs_base_path,false)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function media_uuidParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
			"base|b=s" => "Basispfad, in dem sich alle in der Tabelle referenzierten Dateien befinden."
		);
	}
	# -------------------------------------------------------
	public static function media_uuidHelp() {
		return "Importiert Mediendateien aus XLS(X)/Calc Tabellen via UUID Mapping (Stadtmuseum).";
	}
	# -------------------------------------------------------
	public static function media_uuid($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Medien: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		if(!($vs_base_path = $o_opts->getOption('b'))){
			mmsCritError("Medien: Basispfad ist Pflichtparameter. Versuchen Sie --help für eine Liste von Optionen.");
		}

		if(!file_exists($vs_base_path) || !is_dir($vs_base_path) || !is_readable($vs_base_path) ) {
			mmsCritError("Medien: Basispfad {$vs_base_path} existiert nicht, kann nicht gelesen werden oder ist kein Verzeichnis.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Medien: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		/*print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Dateien importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}*/

		// do actual insert
		if(!media_import($vs_xlsx,$vs_base_path,true)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function objects_updateParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function objects_updateHelp() {
		return "Aktualisiert bereits existierende Objekte aus XLS(X)/Calc Tabellen";
	}
	# -------------------------------------------------------
	public static function objects_update($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Objekt-Update: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Objekt-Update: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual update
		if(!objects_update($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function objectsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function objectsHelp() {
		return "Importiert Objekte aus XLS(X)/Calc Tabellen";
	}
	# -------------------------------------------------------
	public static function objects($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Objekte: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Objekte: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!objects_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function lotsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function lotsHelp() {
		return "Importiert Erwerbungen aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function lots($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Erwerbungen: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Erwerbungen: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!lots_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function entitiesParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function entitiesHelp() {
		return "Importiert Personen aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function entities($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Personen: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Personen: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!entities_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function exhibitionsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function exhibitionsHelp() {
		return "Importiert Ausstellungen aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function exhibitions($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Ausstellungen: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Ausstellungen: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!exhibit_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function object_groupsParamList() {
		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Import",
		);
	}
	# -------------------------------------------------------
	public static function object_groupsHelp() {
		return "Importiert Objektgruppen/Konvolute aus XLS(X)/Calc Tabellen.";
	}
	# -------------------------------------------------------
	public static function object_groups($o_opts){
		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Objektgruppen/Konvolute: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Objektgruppen/Konvolute: Datei {$vs_xlsx} existiert nicht.");
		}

		// you sure you want to import?
		print CLIUtils::textWithColor("Sind Sie sicher, dass Sie die Datensätze importieren möchten? Dies kann nicht rückgängig gemacht werden. Es wird empfohlen, vor diesem Schritt ein Backup der Datenbank anzulegen. [y/N]\n","green");
		$vs_confirm = trim(strtolower(fgets(STDIN)));

		if ($vs_confirm !== 'y') {
			CLIUtils::addError("Okay, Abbruch ...\n");
			exit(0);
		}

		// do actual insert
		if(!object_group_import($vs_xlsx)){
			exit(255);
		}
	}
	# -------------------------------------------------------
	public static function sanityParamList() {
		SanityCheck::init();

		return array(
			"file|f=s" => "Angabe des XLS(X)/Calc Dokuments zum Prüfen",
			"type|t=s" => "Angabe der Import-Art. Mögliche Werte sind: ".join(", ",array_keys(SanityCheck::$s_sanity_check_cfg)),
			"base|b=s" => "Basisverzeichnis für Medienimporte, i.e. das Verzeichnis, in dem sich die Mediendateien befinden. Der Parameter hat bei anderen Import-Arten keinen Effekt."
		);
	}
	# -------------------------------------------------------
	public static function sanityHelp() {
		return "Führt ausführlichen Plausibilitätscheck für gegebene Import-Tabelle und Import-Art durch.";
	}
	# -------------------------------------------------------
	public static function sanity($o_opts) {
		global $g_media_import_base_path;
		global $g_logger;

		if(!($vs_xlsx = $o_opts->getOption('f'))){
			mmsCritError("Plausibilitätscheck: Keine Datei angegeben. Der --file (-f) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		// handle input file and get preview
		if(!file_exists($vs_xlsx)){
			mmsCritError("Plausibilitätscheck: Datei {$vs_xlsx} existiert nicht.");
		}

		if(!($vs_type = $o_opts->getOption('t'))){
			mmsCritError("Plausibilitätscheck: Kein Import-Typ angegeben. Der --type (-t) Parameter ist Pflicht. Versuchen Sie --help für eine Liste von Optionen.");
		}

		if(!in_array($vs_type, array_keys(SanityCheck::$s_sanity_check_cfg))){
			mmsCritError("Plausibilitätscheck: Import-Typ nicht gültig. Versuchen Sie --help für eine Liste von Optionen.");	
		}

		$g_media_import_base_path = null;
		if(in_array($vs_type, array('media', 'media-uuid'))) {
			if(!($g_media_import_base_path = $o_opts->getOption('b'))) {
				mmsCritError("Plausibilitätscheck: Kein Basisverzeichnis fuer die Dateien angegeben. Für Medienimporte ist der -b Parameter Pflicht, weil er zusaetzlich zur Tabelle auch die Dateien ueberprueft. Versuchen Sie --help für eine Liste von Optionen.");
			}
		}

		SanityCheck::addZendLogger($g_logger);

		if(!SanityCheck::doCheck($vs_xlsx,$vs_type)){
			mmsCritError("Plausibilitätscheck der Tabelle fehlgeschlagen. Bitte prüfen Sie das Protokoll für weitere Details.");
		} else {
			print CLIUtils::textWithColor("Alles OK!\n","green");
		}
	}
	# -------------------------------------------------------
}
# -------------------------------------------------------
