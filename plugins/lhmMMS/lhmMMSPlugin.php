<?php
/* ----------------------------------------------------------------------
 * lhmMMS.php : Application Plugin that enforces project-specific rules.
 * This plugin class basically serves as a registry for available functions
 * and reroutes hook calls to the appropriate functions in the actual
 * implementations based in the <plugin_dir>/lib/ directory.
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * ----------------------------------------------------------------------
 */
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'mmsHelpers.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MMSStorageLocationManagement.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MMSCollectionManagement.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MMSInsuranceFeatures.php');

class lhmMMSPlugin extends BaseApplicationPlugin
{
	# -------------------------------------------------------
	private $opo_datamodel;
	private $opa_relationships_to_edit = array();

	# -------------------------------------------------------
	public function __construct($ps_plugin_path)
	{
		$this->description = _t('Zusatzfunktionalität für LHM MMS');
		parent::__construct();

		$this->opa_hook_registry = mmsGetSettingFromMMSPluginConfig('lhm_mms_hook_registry');

		$this->opo_datamodel = Datamodel::load();
	}
	# -------------------------------------------------------

	/**
	 * Override checkStatus() to return true - the MMS plugin always initializes ok
	 */
	public function checkStatus()
	{
		return array(
			'description' => $this->getDescription(),
			'errors' => array(),
			'warnings' => array(),
			'available' => ((bool)mmsGetSettingFromMMSPluginConfig('enabled'))
		);
	}
	# -------------------------------------------------------
	/**
	 * Reroute calls to appropriate class as defined in hook registry.
	 * We have to list all hooks explicitly because CollectiveAccess'
	 * application plugin manager uses ReflectionClass to figure out
	 * what hooks are supported by a plugin. So a simple rerouting
	 * through __call is unfortunately not sufficient.
	 */
	# -------------------------------------------------------
	public function hookAddRelationship(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}

	public function hookAfterBundleInsert(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}

	public function hookSaveItem(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}

	public function hookBeforeMoveRelationships(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}

	public function hookBeforeSaveItem(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}

	public function hookEditItem(&$pa_params)
	{
		$this->reroute(__FUNCTION__, $pa_params);
	}


	# -------------------------------------------------------
	private function reroute($ps_hook_name, &$pa_params)
	{
		if (isset($this->opa_hook_registry[$ps_hook_name]) && is_array($this->opa_hook_registry[$ps_hook_name])) {
			foreach ($this->opa_hook_registry[$ps_hook_name] as $vs_call) {
				$va_tmp = explode('::', $vs_call);
				if (sizeof($va_tmp) == 2) {
					if (class_exists($va_tmp[0]) && method_exists($va_tmp[0], $va_tmp[1])) {
						$va_tmp[0]::{$va_tmp[1]}($pa_params);
					}
				}
			}
		}
	}
	# -------------------------------------------------------

	/**
	 * Füge Menü-Item für Scanner Import hinzu
	 */
	public function hookRenderMenuBar($pa_menu_bar)
	{
		if ($o_req = $this->getRequest()) {
			$va_menu_items = array();

			$va_menu_items['lhm_scanner_import'] = array(
				'displayName' => 'LHM Depot Scannerdaten',
				'default' => array(
					'module' => 'lhmMMS',
					'controller' => 'ScannerImport',
					'action' => 'Index'
				),
				'requires' => array(
					'action:can_use_mms_depository_import' => 'AND',
				),
			);

			$va_menu_items['lhm_sanity_check'] = array(
				'displayName' => 'LHM Import Plausibilitätscheck',
				'default' => array(
					'module' => 'lhmMMS',
					'controller' => 'SanityCheck',
					'action' => 'Index'
				),
				'requires' => array(
					'action:can_use_mms_sanity_check' => 'AND',
				),
			);

			$va_menu_items['lhm_admin_tools'] = array(
				'displayName' => 'LHM Admin Tools',
				'default' => array(
					'module' => 'lhmMMS',
					'controller' => 'AdminTools',
					'action' => 'DupeIdnoCheck'
				),
				'requires' => array(
					'action:can_use_mms_admin_tools' => 'AND',
				),
				'submenu' => array(
					'type' => 'static',
					'navigation' => array(
						'dupe_check' => array(
							'is_enabled' => 1,
							'displayName' => 'Duplikatcheck',
							'default' => array(
								'module' => 'lhmMMS',
								'controller' => 'AdminTools',
								'action' => 'DupeIdnoCheck'
							),
						),
						'field_values' => array(
							'is_enabled' => 1,
							'displayName' => 'Feldwerte',
							'default' => array(
								'module' => 'lhmMMS',
								'controller' => 'AdminTools',
								'action' => 'FieldValues'
							),
						),
						'orphaned_media' => array(
							'is_enabled' => 1,
							'displayName' => 'Verwaiste Medien',
							'default' => array(
								'module' => 'lhmMMS',
								'controller' => 'AdminTools',
								'action' => 'OrphanedMedia'
							),
						),
						'data_stats' => array(
							'is_enabled' => 1,
							'displayName' => 'Datenstatistik',
							'default' => array(
								'module' => 'lhmMMS',
								'controller' => 'AdminTools',
								'action' => 'DataStats'
							),
						)
					)
				)
			);

			$pa_menu_bar['LHM']['displayName'] = 'LHM';
			$pa_menu_bar['LHM']['navigation'] = $va_menu_items;
		}

		return $pa_menu_bar;
	}
	# -------------------------------------------------------

	/**
	 * Get plugin user actions
	 */
	static public function getRoleActionList()
	{
		return array(
			'can_use_mms_sanity_check' => array(
				'label' => 'MMS Plausibilitätscheck',
				'description' => 'Darf MMS Plausibilitätscheck benutzen'
			),
			'can_use_mms_depository_import' => array(
				'label' => 'MMS Depot Import',
				'description' => 'Darf MMS Depot Import benutzen'
			),
			'can_use_mms_admin_tools' => array(
				'label' => 'MMS Admin Tools',
				'description' => 'Darf MMS Admin Tools benutzen'
			),
			'can_use_mms_role_sync' => array(
				'label' => 'MMS Rollen Synchronisation via Webservice',
				'description' => 'Darf MMS Rollen Sync via Webservice benutzen'
			)
		);
	}
	# -------------------------------------------------------

}