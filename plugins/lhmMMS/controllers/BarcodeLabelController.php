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

#require_once(__CA_LIB_DIR__.'/Plugins/PDFRenderer/dompdf.php');
require_once(__CA_LIB_DIR__.'/Print/Barcode.php');

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
		$o_barcode = new Barcode();
		$vs_tmp = tempnam(caGetTempDirPath(), 'mmsBarCode');
		$o_barcode->draw("{$vn_object_id}", "{$vs_tmp}.png", 'code128', 'png', 12);

		$va_page_dimensions = array(0,0,4.0*28.346,7.0*28.346);

		$this->getView()->setVar('barcode_file', "{$vs_tmp}.png");
		$this->getView()->setVar('t_object',$t_object);

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
