<?php
/** ---------------------------------------------------------------------
 * app/plugins/lhmMMS/services/RoleControlService.php
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage WebServices
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

/**
 *
 */

require_once(__CA_LIB_DIR__."/Service/BaseJSONService.php");

class RoleControlService extends BaseJSONService {
	# -------------------------------------------------------
	public function __construct($po_request) {
		parent::__construct($po_request);
	}
	# -------------------------------------------------------
	public function push() {
		$va_post = $this->getRequestBodyArray();

		if(!is_array($va_post) || sizeof($va_post)<1) {
			$this->addError('Keine Rollen definiert!');
			return array('ok' => false);
		}

		$t_role = new ca_user_roles();

		$vn_new_roles = 0;
		$vn_existing_roles = 0;
		$vn_deleted_roles = 0;

		foreach($va_post as $vs_role_code => $va_role_data) {
			if($t_role->load(array('code' => $vs_role_code))) { // existierende Rolle
				$vb_insert = false;
			} else { // neue Rolle erstellen
				$vb_insert = true;
			}

			foreach($va_role_data as $vs_field => $vm_data) {
				$t_role->set($vs_field, $vm_data);
			}

			$t_role->setMode(ACCESS_WRITE);
			if($vb_insert) {
				$vn_new_roles++;
				$t_role->insert();
			} else {
				$vn_existing_roles++;
				$t_role->update();
			}
		}

		// übrig gebliebene Rollen (die nicht im JSON sind) löschen
		$va_roles = $t_role->getRoleList();
		foreach($va_roles as $va_role_data) {
			if(!in_array($va_role_data['code'], array_keys($va_post))) {
				$t_role->load(array('code' => $va_role_data['code']));
				$t_role->setMode(ACCESS_WRITE);
				$t_role->delete(true);
				$vn_deleted_roles++;
			}
		}

		return array(
			'ok' => true,
			'message' => "Es wurden $vn_new_roles neue Rollen erstellt, $vn_existing_roles exitierende Rollen bearbeitet und $vn_deleted_roles Rollen geloescht."
		);
	}
	# -------------------------------------------------------
}
