<?php
/* ----------------------------------------------------------------------
 * lhmMMSToolsPlugin.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2016 Whirl-i-Gig
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
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__ . '/BaseToolsPlugin.php');
require_once(__CA_APP_DIR__ . '/plugins/lhmMMSTools/lib/lhmMMSMailTool.php');

class lhmMMSToolsPlugin extends BaseToolsPlugin {
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		/**
		 * Title of tool. Should be formatted for display and unique to the tool.
		 */
		$this->tool_title = "LHM Tools";

		/**
		 * Description of tool for display in tools list
		 */
		$this->description = 'E-Mail Werkzeuge für LHM-MMS';

		/**
		 * Instance of tool
		 */
		$this->tool = new lhmMMSMailTool();

		$this->config = Configuration::load($ps_plugin_path."/conf/lhmMMSTools.conf");

		parent::__construct($ps_plugin_path);
	}
	# -------------------------------------------------------
}
