<?php
/* ----------------------------------------------------------------------
 * app/plugins/lhmMMS/views/data_stats_html.php:
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

$va_absolute_stats = $this->getVar('absolute_stats');
$va_relative_stats = $this->getVar('relative_stats');

?>
<div class="sectionBox">
	<h3>Datenstatistik</h3>

	<?php
	print caFormTag($this->request, 'DataStats', 'mmsDataStatsSearch');
	print caFormControlBox(
		'',
		'',
		'Zeitraum: '.caHTMLTextInput('data_stats_search', array('size' => 15, 'value' => $this->getVar('data_stats_search') ?: 'heute'))." ".caFormSubmitButton($this->request, __CA_NAV_ICON_SEARCH__, "", 'mmsDataStatsSearch')
	);
	print "</form>";
	?>

	<h3>Relative Statistik für Zeitraum <?php print $this->getVar('date_range_for_display'); ?></h3>
	<table id="mmsRelativeStatsList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
		<tr>
			<th>Kategorie</th>
			<th>Wert</th>
		</tr>
		</thead>
		<tbody>
		<?php
		foreach($va_relative_stats as $vs_name => $vm_val) {
			?>
			<tr>
				<td>
					<?php print $vs_name; ?>
				</td>
				<td>
					<?php print $vm_val; ?>
				</td>
			</tr>
			<?php
		}
		?>
		</tbody>
	</table>
	<p style="font-size: 10px">* Schätzung. Bezieht nicht alle automatisch erstellten Derivate mit ein.</p>

	<h3>Absolute Statistik</h3>
	<table id="mmsAbsoluteStatsList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
		<tr>
			<th>Kategorie</th>
			<th>Wert</th>
		</tr>
		</thead>
		<tbody>
<?php
		foreach($va_absolute_stats as $vs_name => $vm_val) {
?>
		<tr>
			<td>
				<?php print $vs_name; ?>
			</td>
			<td>
				<?php print $vm_val; ?>
			</td>
		</tr>
<?php
		}
?>
		</tbody>
	</table>
</div>
<div class="editorBottomPadding"><!-- empty --></div>
