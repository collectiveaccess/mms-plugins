<?php
/** ---------------------------------------------------------------------
 * app/lib/ca/BaseApplicationTool.php : 
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
 * @subpackage AppPlugin
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
 
 /**
  *
  */
 
require_once(__CA_LIB_DIR__.'/Utils/BaseApplicationTool.php');
require_once(__CA_LIB_DIR__.'/ModelSettings.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_APP_DIR__ . '/plugins/lhmMMS/controllers/AdminToolsController.php');
require_once(__CA_APP_DIR__ . '/plugins/lhmMMSTools/lib/ArrayToTextTable.php');
 
	class lhmMMSMailTool extends BaseApplicationTool {
		# -------------------------------------------------------
		
		/**
		 * Settings delegate - implements methods for setting, getting and using settings
		 */
		public $SETTINGS;
		
		/**
		 * Name for tool. Must be unique to the tool.
		 */
		protected $ops_tool_name = 'LHM MMS Tools';
		
		/**
		 * Identifier for tool. Usually the same as the class name. Must be unique to the tool.
		 */
		protected $ops_tool_id = 'lhmMMSMailTool';
		
		/**
		 * Description of tool for display
		 */
		protected $ops_description = 'E-Mail Versand für LHM MMS Statistiken';
		# -------------------------------------------------------
		/**
		 * Set up tool and settings specifications
		 */
		public function __construct($pa_settings=null, $ps_mode='CLI') {
			$this->opa_available_settings = [];
			
			parent::__construct($pa_settings, $ps_mode, __CA_APP_DIR__.'/plugins/lhmMMSTools/conf/lhmMMSTools.conf');
		}
		# -------------------------------------------------------
		# Commands
		# -------------------------------------------------------
		/**
		 * Import SIPs from specified directory into CollectiveAccess Database
		 */
		public function commandSendMail() {
			$o_conf = $this->getToolConfig();

			$va_actual_emails = [];

			foreach($o_conf->get('users') as $vs_user) {
				$va_actual_emails[] = "{$vs_user}@muenchen.de";
			}


			$vs_from_date = date("Y-m-d H:i", strtotime($o_conf->get('start'))); // start
			$vs_to_date = date('Y-m-d H:i'); // now

			$o_tep = new TimeExpressionParser("{$vs_from_date} - {$vs_to_date}", 'de_DE');
			$va_t = $o_tep->getUnixTimestamps();

			$vs_body_text = $o_conf->get('template');
			$vs_body_text = str_replace('{date_range}', date('d.m.Y H:i', $va_t['start']) . ' - ' . date('d.m.Y H:i', $va_t['end']), $vs_body_text);

			$va_current_stats = [];
			foreach(AdminToolsController::getRelativeStats($va_t) as $vs_cat => $vs_val) {
				$va_current_stats[] = ['kategorie' => $vs_cat, 'wert' => $vs_val];
			}

			$o_array_to_table = new ArrayToTextTable($va_current_stats);
			$o_array_to_table->showHeaders(true);
			$vs_current_stats = @$o_array_to_table->render(true);

			$va_absolute_stats = [];
			foreach(AdminToolsController::getAbsoluteStats() as $vs_cat => $vs_val) {
				$va_absolute_stats[] = ['kategorie' => $vs_cat, 'wert' => $vs_val];
			}

			$o_array_to_table = new ArrayToTextTable($va_absolute_stats);
			$o_array_to_table->showHeaders(true);
			$vs_absolute_stats = @$o_array_to_table->render(true);

			$vs_body_text = str_replace('{relative_stats}', $vs_current_stats, $vs_body_text);
			$vs_body_text = str_replace('{absolute_stats}', $vs_absolute_stats, $vs_body_text);
			caSendmail($va_actual_emails, $o_conf->get('from'), $o_conf->get('subject'), $vs_body_text);
		}
		# -------------------------------------------------------
		# Help
		# -------------------------------------------------------
		/**
		 * Return short help text about a tool command
		 *
		 * @return string 
		 */
		public function getShortHelpText($ps_command) {
			switch($ps_command) {
				case 'SendMail':
				default:
					return 'Versende Statistiken via Email';
			}
		}
		# -------------------------------------------------------
		/**
		 * Return full help text about a tool command
		 *
		 * @return string 
		 */
		public function getHelpText($ps_command) {
			switch($ps_command) {
				case 'SendMail':
				default:
					return 'Versende Statistiken via Email';
			}
		}
		# -------------------------------------------------------
	}
