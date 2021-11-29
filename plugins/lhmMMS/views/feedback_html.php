<?php
	$va_errors = $this->getVar('errors');
	$va_report = $this->getVar('report');

	if(sizeof($va_errors)>0){
		print "<b>Bei der Prüfung des Import-Datei sind folgende Fehler aufgefallen. Der Import wurde daher nicht ausgeführt.</b><br/><br/>";
		foreach($va_errors as $vs_error) {
			print $vs_error."<br />";
		}
	} else {
		print "<h2>Der Import war erfolgreich!</h2><br />";
	}

	if(is_array($va_report) && sizeof($va_report)) {
		print "<b>Import-Report:</b><br/><br/>";
		foreach($va_report as $vs_rep) {
			print $vs_rep."<br />";
		}
	}
?>

<div style="text-align:center; width:100%; margin-top:20px;">
	<?php print caNavLink($this->request, "Weiteren Scanner-Import ausführen", "button", 'lhmMMS', 'ScannerImport', 'Index'); ?>
</div>
