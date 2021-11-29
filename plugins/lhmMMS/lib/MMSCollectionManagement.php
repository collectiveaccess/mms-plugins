<?php
/* ----------------------------------------------------------------------
 * MMSCollectionManagement.php : Enthält statische Funktionen zur
 * Verwaltung von Sammlungen mittels Plugin hooks. Die Funktionen werden 
 * ausschließlich von der LHM MMS Plugin Basisklasse gerufen.
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * @version 0.1
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_acl.php');
require_once(__CA_MODELS_DIR__.'/ca_collections.php');
require_once(__CA_MODELS_DIR__.'/ca_user_groups.php');

class MMSCollectionManagement {

	/**
	 * Stellt sicher, dass jedes Objekt nur einem Sammlungsbereich zugeordnet ist,
	 * indem beim Versuch einen neuen Bereich zuzuordnen alle Referenzen auf andere
	 * Bereiche entfernt werden und eine entsprechende Warnung ausgegeben wird
	 * 
	 * @param array $pa_params Parameter-Array, das vom Plugin Hook übergeben wird
	 */
	public static function enforceSingleCollection(&$pa_params) {
		switch($pa_params['instance']->tableName()) {
			case 'ca_objects':
				// falls related table als table_num angegeben ist, übersetze sie hier
				if(is_numeric($pa_params['related_table'])) {
                    //$o_dm = Datamodel::load();
                    $pa_params['related_table'] = Datamodel::getTableName($pa_params['related_table']);
				}

				// neue Sammlung soll zu Objekt hinzugefügt werden
				// -> Lösche alle existierenden Beziehungen, da nur eine erlaubt ist und gibt Warnung aus
				if($pa_params['related_table'] == 'ca_collections') {
					$va_items = $pa_params['instance']->getRelatedItems('ca_collections');

					if(sizeof($va_items)>0) {
						$vs_old_collection_name = "";
						foreach($va_items as $vn_rel_id => $va_rel_info){
							$vs_old_collection_name .= $va_rel_info['name']." ";
							$pa_params['instance']->removeRelationship('ca_collections', $vn_rel_id);
						}

						mmsAddWarningBox("Jedes Objekt darf nur einem Sammlungsbereich zugeordnet sein. Der vorige Zuordnung zu '".trim($vs_old_collection_name)."' wurde daher entfernt.");
					}
				}
				break;
			default:
				break;
		}
	}

	/**
	 * Fügt neu erstellte Objekte abhängig von Einstellungen in LHM MMS Plugin Config zu Sammlungsbereich
	 * hinzu und, falls das erfolgreich war, setzt die Berechtigungen für alle anderen Sammlungsbereiche
	 * bzw. Nutzergruppen auf Read-Only. Nutzergruppen und Sammlungsbereiche sind hier synonym zu verwenden,
	 * sie werden einander in der LHM MMS Plugin Config eindeutig zugeordnet.
	 *
	 * Einige Museen benötigen dieses Feature nicht, daher macht diese Funktion nichts, wenn kein 
	 * Nutzergruppen <-> Sammlungsbereich Mapping für die aktuelle Instanz (identifiziert via app_name)
	 * vorhanden ist.
     *
	 * @param [type] $pa_params [description]

	public static function setCollectionAndACLsForNewObject(&$pa_params) {
		switch($pa_params['instance']->tableName()) {
			case 'ca_objects':
				$va_group_collection_map = mmsGetSettingFromMMSPluginConfig('lhm_mms_group_collection_map');

				// einige Museen benutzen dieses Feature nicht, es muss also sichergestellt sein, dass keine Zugriffsfehler auftreten
				if($va_group_collection_map && is_array($va_group_collection_map) && (sizeof($va_group_collection_map)>0)){
					$t_user = mmsGetCurrentUser();
					if(!$t_user) { return; } // no user set, possibly script
					$va_groups = $t_user->getUserGroups();

					foreach($va_groups as $vn_group_id => $va_group_info) {
						
						// für jede Nutzergruppe des aktuellen Nutzers, prüfe ob der Code in der Config Map existiert, i.e. ob es einen Sammlungsbreich dazu gibt
						if(
							isset($va_group_collection_map[__CA_APP_NAME__][$va_group_info['code']])
							&&
							($vs_collection_idno = $va_group_collection_map[__CA_APP_NAME__][$va_group_info['code']])
						) {
							// wenn wir ein Mapping gefunden haben, prüfe, ob das Objekt bereits mit Sammlungsbereichen
							// verbunden ist. Wenn nicht, füge Sammlungsbereich, der der Gruppe zugeordnet ist, hinzu.
							$va_related_collections = $pa_params['instance']->getRelatedItems('ca_collections');
							if(!(sizeof($va_related_collections)>0)){
								$vb_add_rel = $pa_params['instance']->addRelationship(
									'ca_collections',
									$vs_collection_idno,
									mmsGetSettingFromMMSPluginConfig('lhm_mms_collection_rel_type')
								);

								// wurde eine Beziehung erfolgreich hinzugefügt, werden die restlichen
								// Sammlungen für dieses Objekt auf read-only gesetzt und ignoriert
								if($vb_add_rel) {
									$t_groups = new ca_user_groups();
									$va_ro_groups = $t_groups->getGroupList(); // Liste aller Gruppen
									unset($va_ro_groups[$vn_group_id]); // unsere Gruppe soll nicht gesetzt werden, hier gilt dann die default Einstellung aus app.conf

									// für alle übrig gebliebenen group_ids legen wir read-only access fest
									$va_acl_user_groups = array();
									foreach(array_keys($va_ro_groups) as $vn_ro_group_id) {
										$va_acl_user_groups[$vn_ro_group_id] = __CA_ACL_READONLY_ACCESS__;
									}
									
									$pa_params['instance']->addACLUserGroups($va_acl_user_groups);
									return;
								}
							}
						}
					}
				}
				
				break;
			default:
				break;
		}
	} */
}
