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

			ksort($vals);               //der letzte Wer
			$last = array_pop($vals);
			$sum += mmsExtractFloatFromCurrencyValue($last);
		}

		return $sum;
	}

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

		// Neue Summe der verknüpften Objekte
		$sumNew = self::sumLatestInsuranceValuesForLoan($t_loan);

		// Der aktuell in der DB gespeicherte Wert
		$stored = $t_loan->get('ca_loans.loan_insurance.loan_insurance_value_eur', ['returnAsArray' => false]);
		$sumStored = mmsExtractFloatFromCurrencyValue((string)$stored);

		// Den Differenzwert gerundet vergleichen
		$sumNewRounded = round($sumNew, 2);
		$sumStoredRounded = round($sumStored, 2);

		// Nur wenn ein Unterschied besteht → den neuen Wert im Formular setzen und eine Meldung ausgeben!
		if (abs($sumNewRounded - $sumStoredRounded) > 0.00001) {
			$t_loan->replaceAttribute(['loan_insurance_remark' => mmsGetSettingFromMMSPluginConfig('lhm_mms_loan_insurance_comment'), 'loan_insurance_value_eur' => mmsFloatToCurrencyValue($sumNewRounded),], 'loan_insurance');

			mmsAddWarningBox('Neue Versicherungssumme wurde automatisch generiert. ' . 'Bitte prüfen – Speichern ist erforderlich.');
		}
		// Wenn gleich → nichts tun → keine Meldung.
	}

	public static function calcLoanInsuranceVal(&$pa_params)
	{

		$tbl = $pa_params['instance']->tableName();

		if ($tbl === 'ca_loans') {
			$t_loan = $pa_params['instance'];

			$newSum = self::sumLatestInsuranceValuesForLoan($t_loan);

			$stored = $t_loan->get('ca_loans.loan_insurance.loan_insurance_value_eur', ['returnAsArray' => false]);
			$storedFloat = mmsExtractFloatFromCurrencyValue($stored);

			if (abs($newSum - $storedFloat) > 0.0001) {
				// Im Formular eintragen und speichern
				$t_loan->replaceAttribute(['loan_insurance_remark' => mmsGetSettingFromMMSPluginConfig('lhm_mms_loan_insurance_comment'), 'loan_insurance_value_eur' => mmsFloatToCurrencyValue($newSum),], 'loan_insurance');
				$t_loan->update();

				mmsAddInfoBox('Ein Objekt wurde verknüpft oder geändert. Die Versicherungssumme hat sich geändert. Bitte prüfen und bei Bedarf erneut speichern.');
			}

		}
	}

}
