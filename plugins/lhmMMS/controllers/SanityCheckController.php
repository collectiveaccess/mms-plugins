<?php
/* ----------------------------------------------------------------------
 * plugins/lhmMMS/controllers/SanityCheckController.php
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * ----------------------------------------------------------------------
 */

 	require_once(__CA_BASE_DIR__.'/lhm/lib/SanityCheck.php');


 	class SanityCheckController extends ActionController {
 		# -------------------------------------------------------
 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			parent::__construct($po_request, $po_response, $pa_view_paths);
 		}
 		# -------------------------------------------------------
 		public function Index() {
 			$this->render('sanity_index_html.php');
 		}
 		# -------------------------------------------------------
 		public function checkSanity() {
 			$va_errors = array();
			SanityCheck::init();

 			$vs_sanity_type = $this->request->getParameter('sanity_type',pString);

 			if(!in_array($vs_sanity_type, array_keys(SanityCheck::$s_sanity_check_cfg))){
				$va_errors[] = "Import-Typ nicht gültig.";
			}

 			if(!is_array($_FILES['data'])){
 				$va_errors[] = 'Keine hochgeladene Datei gefunden';
 			}

 			$vs_file = $_FILES['data']['tmp_name'];

 			if(!file_exists($vs_file)){
				$va_errors[] = "Hochgeladene Datei konnte nicht geöffnet werden.";
			}

			// Set up logging
			if(!SanityCheck::doCheck($vs_file,$vs_sanity_type)){
				$va_errors[] = "Plausibilitätscheck der Tabelle fehlgeschlagen. Bitte prüfen Sie das Protokoll für weitere Details.";
			}

 			$this->view->setVar('errors',$va_errors);
 			$this->view->setVar('report',SanityCheck::getErrors());

 			$this->render('sanity_feedback_html.php');
 		}
 		# -------------------------------------------------------
 	}
