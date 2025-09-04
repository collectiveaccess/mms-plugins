<?php
/* ----------------------------------------------------------------------
 * MMSInsuranceFeatures.php : Enthält statische Funktionen zur
 * automatischen Generierung von Versicherungswerten für Leihgaben
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * @version 0.1
 * ----------------------------------------------------------------------
 */

class MMSInsuranceFeatures
{

	private static $oldInsuranceVals = [];

	/**
	 * Speichert den aktuellen Versicherungswert vor dem Speichern
	 *
	 * @param $pa_params
	 * @return void
	 */
	public static function rememberOldInsuranceValue(&$pa_params)
	{
		$po_object = $pa_params['instance'];

		if ($po_object->tableName() !== 'ca_objects') {
			return;
		}

		$id = $po_object->getPrimaryKey();

		// Vorherigen Wert als Array speichern
		self::$oldInsuranceVals[$id] = $po_object->get('insurance_value_current', ['returnAsArray' => true]);
	}

	/**
	 * Wird nach dem Speichern aufgerufen, prüft Änderungen und speichert alten Wert historisch
	 *
	 * @param $pa_params
	 * @return void
	 */
	public static function handleInsuranceValueHistoryOnUpdate(&$pa_params)
	{
		$po_object = $pa_params['instance'];

		if ($po_object->tableName() !== 'ca_objects') {
			return;
		}

		$id = $po_object->getPrimaryKey();
		$oldVals = self::$oldInsuranceVals[$id] ?? null;
		$newVals = $po_object->get('insurance_value_current', ['returnAsArray' => true]);


		// Keine Änderung oder keine alten/neuen Werte → kein Handlungsbedarf
		if (!$oldVals || !$newVals || json_encode($oldVals) === json_encode($newVals)) {
			unset(self::$oldInsuranceVals[$id]);
			return;
		}

		$historicValues = $po_object->get('insurance_value_historic', ['returnAsArray' => true]);
		// Prüft, ob 'insurance_value_historic' nur leere Platzhalter enthält (z. B. ';;') und entfernt es in diesem Fall
		$filtered = array_filter($historicValues, function ($val) {
			return trim($val) !== '' && trim($val) !== ';;';
		});
		if (empty($filtered)) {
			$po_object->removeAttributes('insurance_value_historic');
		}

		foreach ($oldVals as $oldVal) {
			$parts = array_map('trim', explode(';', $oldVal));

			$date_raw = $parts[0] ?? date('Y-m-d');
			$date_str = date('Y-m-d', strtotime($date_raw));  // gültiges Datum erzeugen

			$value_raw = $parts[1] ?? '';
			$value_float = mmsExtractFloatFromCurrencyValue($value_raw);
			$value_currency = mmsFloatToCurrencyValue($value_float);

			$remark = $parts[2] ?? '';

			$historicData = ['historic_date' => $date_str, 'historic_value_eur' => $value_currency, 'historic_remark' => $remark,];

			$po_object->addAttribute($historicData, 'insurance_value_historic');
		}

		// Änderungen speichern
		$po_object->update();
		unset(self::$oldInsuranceVals[$id]);
	}


	/**
	 * @param $pa_params
	 * @return void
	 */
	public static function recalcLoanInsuranceOnOpen(&$pa_params)
	{
		if (($pa_params['table_name'] ?? null) !== 'ca_loans') {
			return;
		}

		/** @var ca_loans $t_loan */
		$t_loan = $pa_params['instance'];
		if (!$t_loan || ($t_loan->tableName() !== 'ca_loans')) {
			return;
		}
		list($sumNewRounded, $sumStoredRounded, $storedIsEmpty, $hasRelatedObjects) = self::neueSummeAusVerknüpftenObjekten($t_loan);


		$t_loan->replaceAttribute(['loan_insurance_remark' => mmsGetSettingFromMMSPluginConfig('lhm_mms_loan_insurance_comment'), 'loan_insurance_value_eur' => mmsFloatToCurrencyValue($sumNewRounded),], 'loan_insurance');

		// Fall 1: Wenn das Feld leer ist und verknüpfte Objekte vorhanden sind → Wert setzen + Meldung
		if ($storedIsEmpty && $hasRelatedObjects) {
			mmsAddInfoBox('Die Versicherungssumme war leer, obwohl verknüpfte Objekte vorhanden sind. ' . 'Die Summe wurde neu berechnet.');
			// Save
			$t_loan->update();
			return;
		}


		// Fall 2: Wenn gespeicherter und berechneter Wert unterschiedlich sind → Wert setzen + Meldung
		if (abs($sumNewRounded - $sumStoredRounded) > 0.00001) {
			mmsAddWarningBox('Neue Versicherungssumme wurde neu generiert. ' . 'Zum Anzeigen und Übernehmen des neuen Werts ist ein Speichern erforderlich.');
			return;
		}

		// Werte sind gleich → nichts tun (keine Meldung und keine Änderung)
	}

	/**
	 * @param $pa_params
	 * @return void
	 */
	public static function calcLoanInsuranceVal(&$pa_params)
	{
		$tbl = $pa_params['instance']->tableName();
		if ($tbl !== 'ca_loans') {
			return;
		}

		/** @var ca_loans $t_loan */
		$t_loan = $pa_params['instance'];

		list($sumNewRounded, $sumStoredRounded, $storedIsEmpty, $hasRelatedObjects) = self::neueSummeAusVerknüpftenObjekten($t_loan);


		$t_loan->replaceAttribute(['loan_insurance_remark' => mmsGetSettingFromMMSPluginConfig('lhm_mms_loan_insurance_comment'), 'loan_insurance_value_eur' => mmsFloatToCurrencyValue($sumNewRounded),], 'loan_insurance');

		// --- Fall 1: leer + verknüpfte Objekte → setzen + Info
		if ($storedIsEmpty && $hasRelatedObjects) {
			mmsAddInfoBox('Die Versicherungssumme war leer, obwohl verknüpfte Objekte vorhanden waren. Die Summe wurde neu berechnet und gespeichert.');
			$t_loan->update();
			return;
		}
		// --- Fall 2: Unterschied → setzen + Warnung
		if (abs($sumNewRounded - $sumStoredRounded) > 0.00001) {
			mmsAddInfoBox('Ein Objekt wurde verknüpft oder geändert. Die Versicherungssumme hat sich geändert.');
			$t_loan->update();
			return;
		}

		// Gleich → nichts tun (kein update, keine Meldung)
	}


	/**
	 * @param ca_loans $t_loan
	 * @return float
	 */
	private static function sumLatestInsuranceValuesForLoan(ca_loans $t_loan): float
	{
		$va_objects = $t_loan->getRelatedItems('ca_objects');
		if (!is_array($va_objects) || !sizeof($va_objects)) {
			return 0.0;
		}

		$t_object = new ca_objects();
		$sum = 0.0;

		foreach ($va_objects as $va) {
			if (!$t_object->load($va['object_id'])) {
				continue;
			}

			$vals = $t_object->get('ca_objects.insurance_value_current.current_value_eur', ['returnAsArray' => true]);
			if (!is_array($vals) || !sizeof($vals)) {
				continue;
			}

			ksort($vals);               //der letzte Wert
			$last = array_pop($vals);
			$sum += mmsExtractFloatFromCurrencyValue($last);
		}

		return $sum;
	}


	/**
	 * @param ca_loans $t_loan
	 * @return array
	 */
	public static function neueSummeAusVerknüpftenObjekten(ca_loans $t_loan): array
	{
// Neue Summe aus verknüpften Objekten
		$sumNew = self::sumLatestInsuranceValuesForLoan($t_loan);
		$sumNewRounded = round($sumNew, 2);

		// Aktuell gespeicherter Wert in der DB
		$stored = $t_loan->get('ca_loans.loan_insurance.loan_insurance_value_eur', ['returnAsArray' => false]);
		$sumStored = mmsExtractFloatFromCurrencyValue((string)$stored);
		$sumStoredRounded = round($sumStored, 2);

		// Ist das Feld tatsächlich leer?
		$storedIsEmpty = ($stored === null) || (trim((string)$stored) === '');

		// Gibt es verknüpfte Objekte?
		$relatedObjects = $t_loan->getRelatedItems('ca_objects');
		$hasRelatedObjects = is_array($relatedObjects) && !empty($relatedObjects);
		return array($sumNewRounded, $sumStoredRounded, $storedIsEmpty, $hasRelatedObjects);
	}


}
