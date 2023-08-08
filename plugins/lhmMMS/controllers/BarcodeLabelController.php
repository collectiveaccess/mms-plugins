<?php
/* ----------------------------------------------------------------------
 * plugins/lhmMMS/controllers/BarcodeLabelController.php
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt München
 * @version 0.3
 * ----------------------------------------------------------------------
 */

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Exception;


class BarcodeLabelController extends ActionController {
	#
	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
	}
	# -------------------------------------------------------
	public function Index() {
		$vn_object_id = $this->request->getParameter('object_id',pInteger);

		$t_object = new ca_objects();

		// Objekt laden
		if(!$t_object->load($vn_object_id)){
			$this->postError(750,'Ungültige Object ID','BarcodeLabelController->Index()');
		}

		// Zugriffsrechte checken
		if($t_object->checkACLAccessForUser($this->request->getUser()) < __CA_ACL_READONLY_ACCESS__) {
			$this->postError(2580,'Sie haben keinen Zugriff auf dieses Objekt','BarcodeLabelController->Index()');	
		}

		if(!caCanRead($this->request->getUserID(),'ca_objects',$vn_object_id)) {
			$this->postError(2580,'Sie haben keinen Zugriff auf dieses Objekt','BarcodeLabelController->Index()');		
		}

		// generate Barcode
		$bc = caGenerateBarcode("{$vn_object_id}", ['type' => 'code128', 'height' => '20px']);

		// Eigentümer von dem Objekt ermitteln
		$lots_id = $t_object->get('lot_id'); // laden des lot id von objects
		$lot_object = new ca_object_lots($lots_id); // laden des dazu gehörigen lot objects
		$booking_area = $lot_object->get('ca_object_lots.sap.sap_accounting_area'); // laden der Nummer des Buchungskreises

		// Zuweisung der Bezeichnung des Buchungskreises
		switch($booking_area){
			case "0227":
				$et = 'Eigentum der LH München';
				break;
			case "0810":
				$et = 'Eigentum der Münchener Schausteller-Stiftung';
				break;
			default:
				$et = '';
				$booking_area = '';
		}


		$va_page_dimensions = array(0,0,4.0*28.346,7.0*28.346);

		$this->getView()->setVar('barcode_file', $bc);
		$this->getView()->setVar('t_object',$t_object);
		$this->getView()->setVar('eigentuemer' ,$et);
		$this->getView()->setVar('buchungskreis', $booking_area);

		$vs_content = $this->render('barcode_pdf_html.php');

		// generate pdf from HTML
		$o_dom_pdf = new Dompdf();
		$o_dom_pdf->set_paper($va_page_dimensions,'landscape');
		$o_dom_pdf->load_html($vs_content);
		@$o_dom_pdf->render();
		@$o_dom_pdf->stream("label_{$vn_object_id}.pdf");

		// clean up barcode tmp files after they've been used
		@unlink($vs_tmp);
		@unlink("{$vs_tmp}.png");
	}
	# -------------------------------------------------------
}
