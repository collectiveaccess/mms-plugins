<?php
	$t_object = $this->getVar('t_object');
	$vs_barcode_file = $this->getVar('barcode_file');
	$eigentuemer = $this->getVar('eigentuemer');
	$buchungskreis = $this->getVar('buchungskreis');

	// mmsd001.stm.kul.muenchen.de/index.php/lhmMMS/BarcodeLabel/Index/object_id/10081591/format/attach
?>

<html>
<style>

<?php
	if(file_exists(__CA_APP_DIR__.'/plugins/lhmMMS/css/barcode.css')) {
		print file_get_contents(__CA_APP_DIR__.'/plugins/lhmMMS/css/barcode.css');
	}
?>

</style>
	<body>
		<div id="wrapper">
			<div id='title'><?php print $t_object->getLabelForDisplay(); ?></div>
			<div id='idno'><?php print $t_object->get('idno'); ?></div>
			<div id='barcode'><?= $vs_barcode_file; ?></div>
			
			<div id='object_id'><?php print $t_object->getPrimaryKey(); ?></div>
			<div id='date'><?php print date("d.m.Y"); ?></div>

						<div id='buchungskreis'><?php print $buchungskreis; ?></div>
		            <div id='eigentuemer'><?php print $eigentuemer; ?></div>

		</div>
	</body>
</html>
