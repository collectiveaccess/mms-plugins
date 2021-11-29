<?php
/* ----------------------------------------------------------------------
 * app/plugins/lhmMMS/views/orphaned_media_list_html.php:
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2016 Whirl-i-Gig
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

$va_orphaned_media_list = $this->getVar('orphaned_media_list');

?>
<div class="sectionBox">
	<h3>Verwaiste Medien</h3>
	<p>Hier werden Mediendatensätze aufgelistet, die keinem "Hauptdatensatz" (Objekt, Person, etc.) zugeordnet sind.</p>

	<?php
		print caFormControlBox(
			'<div class="list-filter">'._t('Filter').': <input type="text" name="filter" value="" onkeyup="$(\'#caOrphanedMediaList\').caFilterTable(this.value); return false;" size="20"/></div>',
			'',
			''
		);
	?>
<?php
	if(!sizeof($va_orphaned_media_list)) {
		print "<h4>Keine verwaisten Medien gefunden</h4>";
	} else {
?>
	<table id="caOrphanedMediaList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
		<tr>
			<th>Thumbnail</th>
			<th>Dateiname</th>
			<th class="{sorter: false} list-header-nosort" style="width: 40px">Bearbeiten</th>
		</tr>
		</thead>
		<tbody>
<?php
		foreach($va_orphaned_media_list as $vn_rep_id => $va_media) {
?>
		<tr>
			<td>
				<?php print caEditorLink($this->request, $va_media['thumbnail'], '', 'ca_object_representations', $vn_rep_id, array(), array()); ?>
			</td>
			<td>
				<?php print $va_media['original_filename']; ?>
			</td>
			<td>
				<div class="saveDelete">
					<?php print caEditorLink($this->request, caNavIcon( __CA_NAV_ICON_EDIT__, '15px'), '', 'ca_object_representations', $vn_rep_id, array(), array()); ?>
				</div>
			</td>
		</tr>
<?php
		}
	}
?>
		</tbody>
	</table>
</div>
<div class="editorBottomPadding"><!-- empty --></div>
