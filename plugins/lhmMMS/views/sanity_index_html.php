<h1>LHM MMS Import Plausibilitätscheck v0.7.2</h1>
<h2 style="color:red">Dieses Feature befindet sich noch in Entwicklung, ist aber bereits zum Testen geeignet</h2>

<div>
<?php

	print caFormTag($this->request, 'checkSanity', 'lhm_sanity_check', 'lhmMMS/SanityCheck');
?>
	Import-Typ:
<?php

 	print caHTMLSelect('sanity_type', array(
 		'Erwerbungen' => 'lots',
 		'Personen/Institutionen' => 'entities',
 		'Ausstellungen' => 'exhibitions',
 		'Objektgruppen/Konvolute' => 'object-groups',
 		'Schlagworte' => 'keywords',
 		'Standorte' => 'storage-locations',
 		'Objekt-Insert' => 'objects',
 		'Objekt-Update' => 'objects-update',
 	));

?>
	<hr />
	Zu prüfende Datei: <input type="file" name="data" size="40"> 
	<hr />
</div>
<?php
 	print caFormSubmitButton($this->request, __CA_NAV_ICON_GO__, "Absenden", 'lhm_sanity_check');

?>
