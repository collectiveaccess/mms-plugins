<?php
/* ----------------------------------------------------------------------
 * app/views/admin/access/elements_list_html.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2009-2015 Whirl-i-Gig
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
 * ----------------------------------------------------------------------
 */

$va_element_list = $this->getVar('element_list');
$va_attribute_types = $this->getVar('attribute_types');
?>

<h3>Analyse existierender Feldwerte</h3>
<p>Wählen Sie das Symbol neben einem der Elemente aus, um alle für dieses Feld existierenden Feldwerte anzuzeigen.</p>

<script language="JavaScript" type="text/javascript">
/* <![CDATA[ */
	$(document).ready(function(){
		$('#caElementList').caFormatListTable();
	});
/* ]]> */
</script>
<div class="sectionBox">
	<?php
		print caFormControlBox(
			'<div class="list-filter">'._t('Filter').': <input type="text" name="filter" value="" onkeyup="$(\'#caElementList\').caFilterTable(this.value); return false;" size="20"/></div>',
			'',
			''
		);
	?>

	<table id="caElementList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
		<tr>
			<th>Bezeichnung</th>
			<th>Elementkode</th>
			<th>Typ</th>
			<th>Verwendung</th>
			<th class="{sorter: false} list-header-nosort" style="width: 40px">&nbsp;</th>
		</tr>
		</thead>
		<tbody>
<?php
	foreach($va_element_list as $va_element) {
?>
		<tr>
			<td>
				<?php print $va_element['display_label']; ?>
			</td>
			<td>
				<?php print $va_element['element_code']; ?>
			</td>
			<td>
				<?php print $va_attribute_types[$va_element['datatype']]; ?>
			</td>
			<td>
				<?php print caGetTableDisplayName($va_element['table_num']); ?>
			</td>
			<td>
				<div class="saveDelete">
					<?php print caNavLink($this->request, caNavIcon( __CA_NAV_ICON_GO__, '15px' ), '', 'lhmMMS', 'AdminTools', 'FieldValsForElement', ['element_id' => $va_element['element_id'], 'table_num' => $va_element['table_num']]); ?>
				</div>
			</td>
		</tr>
<?php
	}
?>
		</tbody>
	</table>
</div>
<div class="editorBottomPadding"><!-- empty --></div>
