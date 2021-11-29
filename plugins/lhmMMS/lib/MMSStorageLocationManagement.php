<?php
/* ----------------------------------------------------------------------
 * MMSStorageLocationManagement.php : Enthält statische Funktionen zur
 * Standortverwaltung mittels Plugin hooks. Die Funktionen werden 
 * ausschließlich von der LHM MMS Plugin Basisklasse gerufen.
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * @version 0.1
 * ----------------------------------------------------------------------
 */


class MMSStorageLocationManagement {
	# -----------------------------------------------------------------------------
	static private $s_dm = null;
	static private $s_rel_type_map = array();
	# -----------------------------------------------------------------------------
	/**
	 * Verwaltet Standorthistorie. Beim Speichern eines Objektes werden schon
	 * existierende Standorte mit dem Typ 'current_location' in historische
	 * Standorte mit dem Typ 'former_location' umgewandelt. 
	 * 
	 * @param array $pa_params Parameter-Array, das vom Plugin Hook übergeben wird
	 */
	public static function manageHistory(&$pa_params) {
		switch($pa_params['instance']->tableName()) {
			case 'ca_objects':
				// falls related table als table_num angegeben ist, übersetze sie hier
				if(is_numeric($pa_params['related_table'])) {
					//$o_dm = Datamodel::load();
					$pa_params['related_table'] = Datamodel::getTableName($pa_params['related_table']);
				}

				// wir verwalten die Standort-Historie, sonst nichts
				// wir machen also nur was, wenn eine neue storage locations
				// Beziehung für ca_objects angelegt werden soll
				if($pa_params['related_table'] == 'ca_storage_locations') {

					$pn_aktueller_standort = MMSStorageLocationManagement::getStorageLocationRelTypeID('current_location');
					$pn_hatte_standort = MMSStorageLocationManagement::getStorageLocationRelTypeID('former_location');
					$pn_aufenthaltsort = MMSStorageLocationManagement::getStorageLocationRelTypeID('repository');
					$vn_new_rel_type_id = MMSStorageLocationManagement::getStorageLocationRelTypeID($pa_params['type_id']);

					// wenn sich der aktuelle standort ändert ...
					if($vn_new_rel_type_id == $pn_aktueller_standort) {
						// verzeichne effective date und den aktuellen Benutzer in der Beziehung, die gerade erstellt wird
						$pa_params['edate'] = 'jetzt';
						$pa_params['options']['interstitialValues']['storage_location_user'] = mmsGetCurrentUserDisplayName();

						// hole alte standorte vom aktuellen typ
						$va_items = $pa_params['instance']->getRelatedItems('ca_storage_locations',array('restrictToRelationshipTypes' => $vn_new_rel_type_id));

						// editiere alte standorte -> neuer relationship type 'former_location'
						// um doppelungen und konflikte mit dem unique index u_all zu vermeiden,
						// fügen wir noch den zeitstempel der Änderungen als effective_date hinzu.
						// weiterhin wird der user dokumentiert.
						if(sizeof($va_items) > 0) {
							$t_rel = new ca_objects_x_storage_locations();
							$t_rel->setMode(ACCESS_WRITE);
							if($o_trans = $pa_params['instance']->getTransaction()) {
								$t_rel->setTransaction($o_trans);
							}
							foreach($va_items as $vn_rel_id => $va_rel) {
								$t_rel->load($vn_rel_id);
								if(!$t_rel->get('effective_date')) {
									$t_rel->set('effective_date','jetzt');
								}
								$t_rel->set('type_id', $pn_hatte_standort);
								$t_rel->update();
							}
						}
					}

					// wenn sich der aufenthaltsort ändert ...
					if ($vn_new_rel_type_id == $pn_aufenthaltsort) {
						// füge warnungs-box zur globalen HTTPResponse hinzu
						mmsAddWarningBox(mmsGetSettingFromMMSPluginConfig('lhm_mms_repository_change_msg'));

						// lösche alle existierenden Aufenthaltsorte
						$va_items = $pa_params['instance']->getRelatedItems('ca_storage_locations',array('restrictToRelationshipTypes' => $pn_aufenthaltsort));
						if(sizeof($va_items) > 0) {

							$t_rel = new ca_objects_x_storage_locations($vn_rel_id);
							$t_rel->setMode(ACCESS_WRITE);
							if($o_trans = $pa_params['instance']->getTransaction()) {
								$t_rel->setTransaction($o_trans);
							}
							foreach($va_items as $vn_rel_id => $va_rel) {
								$t_rel->load($vn_rel_id);
								$t_rel->delete();
							}
						}

						// verzeichne effective date und den aktuellen Benutzer in der Beziehung, die gerade erstellt wird
						$pa_params['edate'] = 'jetzt';
						$pa_params['options']['interstitialValues']['storage_location_user'] = mmsGetCurrentUserDisplayName();
					}
				}
				break;
			default: 
				break;
		}
	}
	# -----------------------------------------------------------------------------
	private static function getStorageLocationRelTypeID($pm_type_id) {
		// wenn eine ID reinkommt, können wir sie so zurückgeben, wie sie kommt
		if(is_numeric($pm_type_id)) { return $pm_type_id; }
		// prüfen, ob sich wert bereits im Cache befindet
		if(isset(self::$s_rel_type_map[$pm_type_id])) { return self::$s_rel_type_map[$pm_type_id]; }

		// both calls are cached in Datamodel
		//$o_dm = Datamodel::load();
		$t_rel_types = Datamodel::getInstanceByTableName('ca_relationship_types', true);

		// wert in den cache packen und zurückgeben
		return self::$s_rel_type_map[$pm_type_id] = $t_rel_types->getRelationshipTypeID('ca_objects_x_storage_locations', $pm_type_id);
	}
	# -----------------------------------------------------------------------------
}
