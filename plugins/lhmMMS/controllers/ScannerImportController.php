<?php
/* ----------------------------------------------------------------------
 * plugins/lhmMMS/controllers/ScannerImportController.php
 * ----------------------------------------------------------------------
 * Copyright 2014 Landeshauptstadt MÃ¼nchen
 * @version 0.1
 * ----------------------------------------------------------------------
 */

    require_once(__CA_LIB_DIR__.'/Configuration.php');
    require_once(__CA_LIB_DIR__.'/Parsers/DelimitedDataParser.php');

    class ScannerImportController extends ActionController {
        # -------------------------------------------------------
        protected $opo_config;      // plugin configuration file
        # -------------------------------------------------------
        #
        # -------------------------------------------------------
        public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
            parent::__construct($po_request, $po_response, $pa_view_paths);
            $this->opo_config = Configuration::load(__CA_APP_DIR__.'/plugins/lhmMMS/conf/lhmMMS.conf');
        }
        # -------------------------------------------------------
        public function Index() {
            $this->render('import_html.php');
        }
        # -------------------------------------------------------
        public function import() {
            $va_errors = array();
            $o_tep = new TimeExpressionParser(null, 'de_DE');

            $vs_rel_type_code = $this->request->getParameter('rel_type_code',pString);

            if(!in_array($vs_rel_type_code, array('repository', 'current_location'))) {
                $va_errors[] = 'UngÃ¼ltiger Beziehungstyp';
            }

            if(!is_array($_FILES['data'])){
                $va_errors[] = 'Keine hochgeladene Datei gefunden';
            }

            $va_file = $_FILES['data'];

            if($va_file['type'] != 'text/plain'){
                $va_errors[] = 'Es handelt sich nicht um eine Textdatei';
            }

            $o_parser = new DelimitedDataParser(';');
            if(!$o_parser->parse($va_file['tmp_name'], ['format' => 'txt'])){
                $va_errors[] = 'Konnte Datei nicht verarbeiten. Nicht im korrekten Format?';
            }

            while($o_parser->nextRow()) {
                $vs_location_idno = trim($o_parser->getRowValue(1));
                if(!$vs_location_idno) { continue; }
                if(substr($vs_location_idno, 0, 1)=="$"){
                    $vs_location_idno = substr($vs_location_idno, 1);
                }

                $vs_object_id = intval($o_parser->getRowValue(2));

                $vs_datetime = $o_parser->getRowValue(3);
                if(!$o_tep->parse($vs_datetime)) {
                    $va_errors[] = "Konnte Datum {$vs_datetime} nicht verarbeiten";
                }

                if(!ca_storage_locations::findAsInstance(['idno' => $vs_location_idno])) {
                    $va_errors[] = "Konnte Standort {$vs_location_idno} nicht finden";
                }

                if(!($t_object = ca_objects::findAsInstance(['object_id' => $vs_object_id]))){
                    $va_errors[] = "Konnte Objekt {$vs_object_id} nicht finden";
                }
            }

            $va_report = array();
            // Starte den Import nur dann, wenn alles vorher gut ging
            if(sizeof($va_errors)==0) {
                $o_tx = new Transaction($t_object->getDb());
                $t_object->setTransaction($o_tx);

                // we need to unset the form timestamp to disable the 'Changes have been made since you loaded this data' warning when we update()
                // the warning makes sense because an update()/insert() is called before we arrive here but after the form_timestamp ... but we chose to ignore it
                $vn_timestamp = $_REQUEST['form_timestamp'];
                unset($_REQUEST['form_timestamp']);

                
                if(!$o_parser->parse($va_file['tmp_name'], ['format' => 'txt'])){
                                $va_errors[] = 'Konnte Datei nicht verarbeiten. Nicht im korrekten Format?';
                 }

                while($o_parser->nextRow()) {
                    $vs_location_idno = $o_parser->getRowValue(1);
                    if(substr($vs_location_idno, 0, 1)=="$"){
                        $vs_location_idno = substr($vs_location_idno, 1);
                    }

                    $vs_object_id = intval($o_parser->getRowValue(2));

                    $vs_datetime = $o_parser->getRowValue(3);

                    if(!($t_loc = ca_storage_locations::findAsInstance(['idno' => $vs_location_idno]))) { continue; }
                    if(!($t_object = ca_objects::findAsInstance(['object_id' => $vs_object_id]))){ continue; }

                    $t_rel = $t_object->addRelationship('ca_storage_locations', $t_loc->getPrimaryKey(), $vs_rel_type_code, $vs_datetime);
                    if($t_rel instanceof BaseRelationshipModel) {
                        $t_rel->setTransaction($o_tx);
                        $vs_sl_user = mmsGetCurrentUserDisplayName();
                        if(strlen($vs_sl_user)>0){
                            $t_rel->addAttribute(array(
                                'storage_location_user' => $vs_sl_user,
                            ),'storage_location_user');
                            $t_rel->setMode(ACCESS_WRITE);
                            $t_rel->update();

                            if($t_rel->numErrors()>0){
                                $va_errors = array_merge($va_errors,$t_rel->getErrors());
                                $o_tx->rollback(); $va_report = array();
                                break;
                            }
                        }
                    }

                    if($t_object->numErrors()>0){
                        $va_errors = array_merge($va_errors,$t_object->getErrors());
                        $o_tx->rollback(); $va_report = array();
                        break;
                    } else {
                        $va_report[] = "Standort <b>{$vs_location_idno}</b> erfolgreich mit Objekt <b>{$vs_object_id}</b> verbunden";
                    }
                }

                if(!sizeof($va_errors)) {
                    $o_tx->commit();
                }

                // set it back, so that request processing after this is not impaired
                $_REQUEST['form_timestamp'] = $vn_timestamp;
            }

            $this->view->setVar('errors',$va_errors);
            $this->view->setVar('report',$va_report);

            $this->render('feedback_html.php');
        }
        # -------------------------------------------------------
    }
 
