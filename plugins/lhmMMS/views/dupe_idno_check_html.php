<?php
	$va_dupe_map = $this->getVar('dupe_map');
	$va_pages = $this->getVar('pages');
	$vs_page = $this->getVar('page');
?>

<h2>LHM MMS Duplikatcheck</h2>

<script language="JavaScript" type="text/javascript">
	/* <![CDATA[ */
	jQuery(document).ready(function(){
		jQuery('#mmsDupeList').caFormatListTable();
	});
	/* ]]> */
</script>

<div class="sectionBox">
	<?php
	print caFormControlBox(
		'<div class="list-filter">'._t('Filter').': <input type="text" id="filter" name="filter" value="" onkeyup="$(\'#mmsDupeList\').caFilterTable(this.value); return false;" size="20"/></div>',
		'',
		$vs_set_type_menu
	);
	?>
<?php
	print _t('Objektidentifikator beginnt mit').' ';
	foreach($va_pages as $vs_p) {
		if ($vs_p === $vs_page) {
			print $vs_p ? "<strong>{$vs_p}</strong> " : "<strong>LEER</strong> ";
		} else {
			print caNavLink($this->request, $vs_p ? $vs_p : "LEER", '', '*', '*', '*', ['p' => $vs_p]). ' ';
		}
	}
?>

	<table id="mmsDupeList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
		<tr>
			<th class="list-header-unsorted">
				<?php print 'Objektidentifikator'; ?>
			</th>
			<th class="list-header-nosort">
				<?php print 'potentielle Duplikate'; ?>
			</th>
		</tr>
		</thead>
		<tbody>
		<?php
		if (sizeof($va_dupe_map)) {
			foreach($va_dupe_map as $vs_idno => $va_templates) {
				?>
				<tr>
					<td>
						<?php print $vs_idno; ?>
					</td>
					<td>
						<?php
							print join("<hr/>\n", $va_templates);
//							print caProcessTemplateForIDs("
//<l>^ca_objects.preferred_labels (^ca_objects.object_id)</l>
//<unit relativeTo='ca_entities' restrictToRelationshipTypes='artist'>, Künstler: ^ca_entities.preferred_labels.displayname</unit>
//", 'ca_objects', $va_ids, ['delimiter' => '<hr />']);
						?>
					</td>
				</tr>
				<?php
				TooltipManager::add('.deleteIcon', _t("Delete"));
				TooltipManager::add('.editIcon', _t("Edit"));

			}
		} else {
			?>
			<tr>
				<td colspan='2'>
					<div align="center">
						<?php print 'Keine potentiellen Duplikate gefunden'; ?>
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
