<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    thirdparty_tab.php
 * \ingroup facturapublica
 * \brief   "Factura Pública" tab on a third party: public body, DIR3 codes, invoices sent to FACe
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once __DIR__.'/lib/facturapublica.lib.php';
require_once __DIR__.'/class/FpStore.class.php';
require_once __DIR__.'/class/FpDir3.class.php';

$langs->loadLangs(array('companies', 'bills', 'facturapublica@facturapublica'));

$socid = GETPOST('socid', 'int');
$action = GETPOST('action', 'aZ09');
// Every action changes something, so each needs the page's token
if ($action !== '' && !facturapublica_token_ok()) {
	setEventMessages($langs->trans('ErrorBadToken'), null, 'errors');
	$action = '';
}

if (!isModEnabled('facturapublica') || !$user->hasRight('facturapublica', 'read')) {
	accessforbidden();
}
$object = new Societe($db);
if ($socid <= 0 || $object->fetch($socid) <= 0) {
	accessforbidden();
}
$result = restrictedArea($user, 'societe', $socid, '&societe');
$canEdit = $user->hasRight('societe', 'creer');
$self = dol_buildpath('/facturapublica/thirdparty_tab.php', 1).'?socid='.((int) $socid);

$row = FpStore::getPublic($db, $socid);
$errors = array();

/*
 * Actions
 */
if (in_array($action, array('fp_save', 'fp_dir3save'), true) && $canEdit) {
	if ($action === 'fp_save') {
		$row['is_public'] = GETPOST('is_public', 'int') ? 1 : 0;
		$row['needs_file_ref'] = GETPOST('needs_file_ref', 'int') ? 1 : 0;
		$row['notify_email'] = trim(GETPOST('notify_email', 'alphanohtml'));
		$row['dir3_op'] = facturapublica_dir3_clean(GETPOST('dir3_op', 'alphanohtml'));
		if ($row['dir3_op'] !== '' && !facturapublica_dir3_valid($row['dir3_op'])) {
			$errors['dir3_op'] = $langs->trans('FpDir3Format');
		}
		if ($row['notify_email'] !== '' && !isValidEmail($row['notify_email'])) {
			$errors['notify_email'] = $langs->trans('FpEmailFormat');
		}
	} else {
		$row['is_public'] = 1;
	}
	foreach (array('dir3_oc', 'dir3_og', 'dir3_ut') as $k) {
		$row[$k] = facturapublica_dir3_clean(GETPOST($k, 'alphanohtml'));
		if ($row[$k] !== '' && !facturapublica_dir3_valid($row[$k])) {
			$errors[$k] = $langs->trans('FpDir3Format');
		}
	}
	if (!$errors) {
		FpStore::savePublic($db, $socid, $row);
		setEventMessages($langs->trans('FpSaved'), null);
		header('Location: '.$self);
		exit;
	}
	setEventMessages($langs->trans('ErrorFieldFormat', $langs->transnoentities('FpTabName')), null, 'errors');
}
if ($action === 'fp_refresh' && $user->hasRight('facturapublica', 'setup')) {
	$r = FpDir3::refresh($db, false);
	setEventMessages($r['message'], null, $r['ok'] ? 'mesgs' : 'errors');
	header('Location: '.$self);
	exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('FpTabName').' - '.$object->name, '', '', 0, 0, '', '', '', 'mod-facturapublica page-thirdparty');

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'facturapublica', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($object, 'socid', '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="underbanner clearboth"></div>';

print '<div class="fpc-tab">';
print facturapublica_top_bars();

$field = function ($name, $label, $value, $help = '', $required = false) use ($errors, $canEdit) {
	$out = '<div class="fpc-field"><label for="'.$name.'">'.$label.($required ? ' <span class="fieldrequired"></span>' : '').'</label>';
	$out .= '<div><input type="text" class="fpc-code flat" id="'.$name.'" name="'.$name.'" maxlength="9" value="'.dol_escape_htmltag($value).'"'.($canEdit ? '' : ' disabled').'>';
	$out .= $help ? ' <span class="opacitymedium">'.$help.'</span>' : '';
	$out .= '</div>';
	if (!empty($errors[$name])) {
		$out .= '<span class="fpc-err">'.$errors[$name].'</span>';
	}
	return $out.'</div>';
};

print '<form method="POST" action="'.$self.'" id="fp-tabform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="fp_save">';
print '<div class="fpc-field"><label for="is_public">'.$langs->trans('FpIsPublic').'</label><div><input type="checkbox" id="is_public" name="is_public" value="1"'.($row['is_public'] ? ' checked' : '').($canEdit ? '' : ' disabled').'></div></div>';

print '<div class="fpc-finder">';
print '<p class="fpc-note">'.$langs->trans('FpDir3Help').'</p>';
print $field('dir3_oc', $langs->trans('FpOc'), $row['dir3_oc'], '', true);
print $field('dir3_og', $langs->trans('FpOg'), $row['dir3_og'], '', true);
print $field('dir3_ut', $langs->trans('FpUt'), $row['dir3_ut'], '', true);
print $field('dir3_op', $langs->trans('FpOp'), $row['dir3_op']);
if ($canEdit) {
	print '<div class="fpc-acts"><a class="fpc-sec" href="#" data-fp-finder="1">'.$langs->trans('FpDir3Find').'</a>';
	if ($user->hasRight('facturapublica', 'setup')) {
		print '<a class="fpc-sec" href="'.$self.'&action=fp_refresh&token='.newToken().'">'.$langs->trans('FpDir3Refresh').'</a>';
	}
	print '</div>';
}
print '</div>';

print '<div class="fpc-field"><label for="needs_file_ref">'.$langs->trans('FpNeedsFileRef').'</label><div><input type="checkbox" id="needs_file_ref" name="needs_file_ref" value="1"'.($row['needs_file_ref'] ? ' checked' : '').($canEdit ? '' : ' disabled').'></div></div>';
print '<div class="fpc-field"><label for="notify_email">'.$langs->trans('FpNotifyEmail').'</label><div><input type="email" class="flat minwidth300" id="notify_email" name="notify_email" value="'.dol_escape_htmltag($row['notify_email']).'"'.($canEdit ? '' : ' disabled').'></div>';
if (!empty($errors['notify_email'])) {
	print '<span class="fpc-err">'.$errors['notify_email'].'</span>';
}
print '</div>';
if ($canEdit) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
}
print '</form>';

if ($canEdit) {
	print facturapublica_finder_panel($object->name, $self, 'fp_dir3save', $row);
}

// Invoices of this customer sent to FACe, latest attempt of each
print '<h3 class="fps-h">'.$langs->trans('FpTabHistory').'</h3>';
$sql = 'SELECT s.rowid, s.face_registry, s.sent_at, s.status_code, s.status_text, f.rowid as fid, f.ref, f.total_ttc';
$sql .= ' FROM '.MAIN_DB_PREFIX.'facturapublica_sending as s INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = s.fk_facture';
$sql .= ' WHERE f.fk_soc = '.((int) $socid).' AND s.entity = '.((int) $conf->entity).' AND s.face_registry IS NOT NULL';
$sql .= ' AND s.rowid IN (SELECT MAX(s2.rowid) FROM '.MAIN_DB_PREFIX.'facturapublica_sending as s2 GROUP BY s2.fk_facture)';
$sql .= ' ORDER BY s.sent_at DESC';
$sql .= $db->plimit(50);
$resql = $db->query($sql);
if ($resql && $db->num_rows($resql)) {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
	print '<th>'.$langs->trans('FpColInvoice').'</th><th>'.$langs->trans('FpRegistryLabel').'</th><th>'.$langs->trans('FpColRegistered').'</th><th class="right">'.$langs->trans('FpColAmount').'</th><th>'.$langs->trans('FpColStatus').'</th></tr>';
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $o->fid).'">'.dol_escape_htmltag($o->ref).'</a></td>';
		print '<td><code>'.dol_escape_htmltag($o->face_registry).'</code></td>';
		print '<td>'.dol_print_date($db->jdate($o->sent_at), 'day').'</td>';
		print '<td class="right nowraponall">'.facturapublica_eur($o->total_ttc).' €</td>';
		print '<td><span class="fpc-badge '.facturapublica_status_tone($o->status_code).'">'.dol_escape_htmltag(facturapublica_status_label($o->status_code, $o->status_text)).'</span></td></tr>';
	}
	print '</table></div>';
} else {
	print '<p class="opacitymedium">'.$langs->trans('FpTabNoHistory').'</p>';
}
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
