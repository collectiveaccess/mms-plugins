<?php
/** ---------------------------------------------------------------------
 *
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

require_once(__CA_LIB_DIR__ . '/Utils/BaseApplicationTool.php');
require_once(__CA_LIB_DIR__ . '/ModelSettings.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_APP_DIR__ . '/plugins/lhmMMS/controllers/AdminToolsController.php');
require_once(__CA_APP_DIR__ . '/plugins/lhmMMSTools/lib/ArrayToHTMLTable.php');

class lhmMMSMailTool extends BaseApplicationTool
{
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
	public function __construct($pa_settings = null, $ps_mode = 'CLI')
	{
		$this->opa_available_settings = [];

		parent::__construct($pa_settings, $ps_mode, __CA_APP_DIR__ . '/plugins/lhmMMSTools/conf/lhmMMSTools.conf');
	}
	# -------------------------------------------------------
	# Commands
	# -------------------------------------------------------
	/**
	 * Import SIPs from specified directory into CollectiveAccess Database
	 */
	public function commandSendMail()
	{
		$o_conf = $this->getToolConfig();

		$va_actual_emails = [];

		foreach ($o_conf->get('users') as $vs_user) {
			$va_actual_emails[] = "{$vs_user}@muenchen.de";
		}


		$vs_from_date = date("Y-m-d H:i", strtotime($o_conf->get('start'))); // start
		$vs_to_date = date('Y-m-d H:i'); // now

		$o_tep = new TimeExpressionParser("{$vs_from_date} - {$vs_to_date}", 'de_DE');
		$va_t = $o_tep->getUnixTimestamps();

		//Headline with daterange
		$vs_body_text = $o_conf->get('template');
		$vs_body_text = str_replace('{date_range}', date('d.m.Y H:i', $va_t['start']) . ' - ' . date('d.m.Y H:i', $va_t['end']), $vs_body_text);

		/*
		* Relative Stats
		* */
		$va_current_stats = [];
		foreach (AdminToolsController::getRelativeStats($va_t) as $vs_cat => $vs_val) {
			$va_current_stats[] = ['Kategorie' => $vs_cat, 'Wert' => $vs_val];
		}
		//Convert va_current_stats to HTML Table
		$tableGenerator = new ArrayToHTMLTable();
		$va_current_stats_table = $tableGenerator->convert($va_current_stats);

		//Replace template placeholders with the matching HTML table for relative stats
		$vs_body_text = str_replace('{relative_stats}', $va_current_stats_table, $vs_body_text);

		/*
		 * Absolute Stats
		 * */
		foreach (AdminToolsController::getAbsoluteStats() as $vs_cat => $vs_val) {
			$va_absolute_stats[] = ['Kategorie' => $vs_cat, 'Wert' => $vs_val];
		}
		//Convert absolute stats to HTML Table
		$tableGenerator = new ArrayToHTMLTable();
		$va_absolute_stats_table = $tableGenerator->convert($va_absolute_stats);

		//Replace template placeholders with the matching HTML table for relative stats
		$vs_body_text = str_replace('{absolute_stats}', $va_absolute_stats_table, $vs_body_text);

		// new line von Header
		// Header for E-Mail
		$headers = "MIME-Version: 1.0" . "\r\n";
		$headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
		$headers .= "From: " . $o_conf->get('from') . "\r\n";
		$headers .= "Reply-To: " . $o_conf->get('from') . "\r\n";
		// Normalize whitespace in the email body
		$vs_body_text = preg_replace('/\s+/', ' ', $vs_body_text);
		$vs_body_text = trim($vs_body_text);

        // Generate or retrieve the current job ID to track this email process
		$ps_job_id = $this->getJobID();
		if (!$ps_job_id) {
			$ps_job_id = uniqid('job_', true); // Create unique job ID if none exists
			$this->setJobID($ps_job_id);  // Save job ID for this session
		}
		// Initialize a new ProgressBar for WebUI mode to track email sending
		$o_progress = new ProgressBar('WebUI', 0, $ps_job_id);
		$o_progress->setMode('WebUI');
		$o_progress->setTotal(count($va_actual_emails) > 0 ? count($va_actual_emails) : 1);
		$o_progress->start('E-Mail Versand gestartet...', ['created' => [], 'updated' => []]);

		// Retrieve current job info and ensure arrays exist for tracking created/updated items
		$va_job_info = $o_progress->getDataForJobID($ps_job_id);
		if (!is_array($va_job_info['data']['created'])) {
			$va_job_info['data']['created'] = [];
		}
		if (!is_array($va_job_info['data']['updated'])) {
			$va_job_info['data']['updated'] = [];
		}

		// Loop through all emails and send them while updating the progress bar
		foreach ($va_actual_emails as $email) {
			if (!mail(implode(',', $va_actual_emails), $o_conf->get('subject'), $vs_body_text, $headers)) {
				$o_progress->setError("Fehler beim Senden an {$email}");
			} else {
				$o_progress->next("E-Mail an {$email} gesendet");
			}
		}

		// Finish the progress bar job
		$o_progress->finish('E-Mail Versand abgeschlossen');

		return true;

	}
	# -------------------------------------------------------
	# Help
	# -------------------------------------------------------
	/**
	 * Return short help text about a tool command
	 *
	 * @return string
	 */
	public function getShortHelpText($ps_command)
	{
		switch ($ps_command) {
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
	public function getHelpText($ps_command)
	{
		switch ($ps_command) {
			case 'SendMail':
			default:
				return 'Versende Statistiken via Email';
		}
	}
	# -------------------------------------------------------
}
