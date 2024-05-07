<?php
/* ----------------------------------------------------------------------
 * mmsHelpers.php : Hilfsfunktionen für sich wiederholende Aufgaben in LHM MMS Plugin
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * ----------------------------------------------------------------------
 */

/**
 * Fügt Warnungs-Box zu aktueller HTTP Antwort hinzu
 * @param string $ps_message Nachricht, die in der Warnungs-Box erscheinen soll
 */
function mmsAddWarningBox($ps_message) {
	global $g_request, $g_response;

	if(is_object($g_response)){
		/** @var RequestHTTP $g_request */
		/** @var ResponseHTTP $g_response */
		if($g_request->getController() != 'ScannerImport') { // keine Warnungs-Boxen beim Scanner import
			$g_response->addContent("
				<div class='notification-error-box rounded'>
					<ul class='notification-error-box'>
						<li class='notification-error-box'>{$ps_message}</li>
					</ul>
				</div>
			",'default');
		}
	}
}

/**
 * Holt Setting aus der LHM MMS Plugin Konfiguration.
 * Dies geschieht an einem zentralen Ort, falls sich der Pfad der Config
 * im Projektverlauf ändern sollte.
 * @param string $ps_setting Die Einstellung, die geholt werden soll
 * @return mixed Der Wert für die Einstellung
 */
function mmsGetSettingFromMMSPluginConfig($ps_setting) {
	// Configuration::load cacht die Objekte, es gibt also keinen Grund, das hier nicht zu machen
	$o_conf = Configuration::load(__CA_APP_DIR__.'/plugins/lhmMMS/conf/lhmMMS.conf');
	return $o_conf->get($ps_setting);
}

/**
 * Holt den aktuell eingeloggten Benutzer. Funktioniert NICHT in CLI Scripten!
 * @return ca_users Der Benutzer
 */
function mmsGetCurrentUser() {
	global $g_request;

	if(is_object($g_request)){
		return $g_request->getUser();
	} else {
		return false;
	}
}

function mmsGetCurrentUserDisplayName() {
	if($t_user = mmsGetCurrentUser()) {
		return $t_user->getName();
	} else {
		return 'Importer';
	}
}

function mmsExtractFloatFromCurrencyValue($ps_value) {
	$va_matches = array();
	if(preg_match("!^([\d\.\,]+)([^\d]+)$!u",$ps_value,$va_matches)) {
		$vs_decimal = $va_matches[1];
	} elseif(preg_match("!^([^\d]+)([\d\.\,]+)$!u",$ps_value,$va_matches)) {
		$vs_decimal = $va_matches[2];
	}

	if(Zend_Registry::isRegistered("Zend_Locale")) {
		$o_locale = Zend_Registry::get('Zend_Locale');
	} else {
		$o_locale = new Zend_Locale('de_DE');
	}
	
	try {
		return (float) Zend_Locale_Format::getNumber($vs_decimal, array('locale' => $o_locale, 'precision' => 2));
	} catch (Zend_Locale_Exception $e){
		return 0;
	}
}

function mmsFloatToCurrencyValue($pn_value){
	global $locale;
	
	$o_locale = $locale ? $locale : new Zend_Locale('de_DE');
 	$vs_decimal = Zend_Locale_Format::toNumber($pn_value, array('locale' => $o_locale, 'precision' => 2));

 	return $vs_decimal.' EUR';
}
