<style>
	pre {
		white-space: pre-wrap;       /* CSS 3 */
		white-space: -moz-pre-wrap;  /* Mozilla, since 1999 */
		white-space: -o-pre-wrap;    /* Opera 7 */
		word-wrap: break-word;       /* Internet Explorer 5.5+ */
	}
</style>

<?php
	$va_errors = $this->getVar('errors');
	$va_report = $this->getVar('report');

	if(sizeof($va_errors)>0){
		print "<b>Es gab Fehler bei der Ausführung des Plausibilitätschecks:</b><br/><br/>";
		foreach($va_errors as $vs_error) {
			print $vs_error."<br />";
		}
	} else {
		print "<div style='color: green'><b>Plausibilitätscheck ohne Fehler abgeschlossen!:</b></div>";
	}

	if(sizeof($va_report)>0) {
		print "<h2>Log-Nachrichten des Plausibilitätschecks:</h2>";
		print "<pre>";
		foreach($va_report as $vs_rep) {
			print $vs_rep."\n";
		}
		print "</pre>";
	}
?>

<div style="text-align:center; width:100%; margin-top:20px;">
	<?php print caNavLink($this->request, "Weiteren Check ausführen", "button", 'lhmMMS', 'SanityCheck', 'Index'); ?>
</div>
