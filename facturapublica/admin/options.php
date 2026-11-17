<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/options.php
 * \ingroup facturapublica
 * \brief   Options: PDF inside the file, time stamp, VERI*FACTU URL, series, notices, default exemption
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once __DIR__.'/../lib/facturapublica.lib.php';

$langs->loadLangs(array('admin', 'facturapublica@facturapublica'));
if (!isModEnabled('facturapublica') || !$user->hasRight('facturapublica', 'setup')) {
	accessforbidden($langs->trans('FpSetupNoRight'));
}

$action = GETPOST('action', 'aZ09');
// Every action changes something, so each needs the page's token
if ($action !== '' && !facturapublica_token_ok()) {
	setEventMessages($langs->trans('ErrorBadToken'), null, 'errors');
	$action = '';
}
$self = dol_buildpath('/facturapublica/admin/options.php', 1);

$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label('facture');
$invoiceFields = array();
if (!empty($extrafields->attributes['facture']['label'])) {
	foreach ($extrafields->attributes['facture']['label'] as $key => $label) {
		if (strpos($key, 'fp_') !== 0) {
			$invoiceFields[$key] = $langs->trans($label).' ('.$key.')';
		}
	}
}
$exempts = array();
foreach (array('E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'Isp') as $k) {
	$exempts[$k] = $langs->transnoentitiesnoconv('FpExempt'.$k);
}
$errors = array();

/*
 * Actions
 */
if ($action === 'save') {
	$mode = GETPOST('vf_mode', 'aZ09');
	$mode = in_array($mode, array('none', 'auto', 'field'), true) ? $mode : 'auto';
	$field = GETPOST('vf_field', 'aZ09');
	if ($mode === 'field' && !isset($invoiceFields[$field])) {
		$errors['vf_field'] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('FpOptVfFieldPick'));
	}
	$tsa = trim(GETPOST('tsa_url', 'alphanohtml'));
	if ($tsa !== '' && !preg_match('#^https?://[^\s]+$#i', $tsa)) {
		$errors['tsa_url'] = $langs->trans('ErrorBadUrl', $tsa);
	}
	$notify = trim(GETPOST('notify_email', 'alphanohtml'));
	if ($notify !== '' && !isValidEmail($notify)) {
		$errors['notify_email'] = $langs->trans('FpEmailFormat');
	}
	$exempt = GETPOST('exempt', 'aZ09');
	if (!$errors) {
		$set = array(
			'FACTURAPUBLICA_ATTACH_PDF' => GETPOST('attach_pdf', 'int') ? '1' : '0',
			'FACTURAPUBLICA_TSA_URL' => $tsa,
			'FACTURAPUBLICA_VF_MODE' => $mode,
			'FACTURAPUBLICA_VF_FIELD' => $mode === 'field' ? $field : '',
			'FACTURAPUBLICA_VF_PLACE' => 'additional',
			'FACTURAPUBLICA_SPLIT_SERIES' => GETPOST('split', 'int') ? '1' : '',
			'FACTURAPUBLICA_NOTIFY_EMAIL' => $notify,
			'FACTURAPUBLICA_UNINSTALL_CERT' => GETPOST('uninstall_cert', 'int') ? '1' : '',
			// The builder writes the reason as text, so store the label itself
			'FACTURAPUBLICA_DEFAULT_EXEMPT' => isset($exempts[$exempt]) ? $exempts[$exempt] : '',
		);
		foreach ($set as $k => $v) {
			if ($v === '') {
				dolibarr_del_const($db, $k, $conf->entity);
			} else {
				dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $conf->entity);
			}
		}
		setEventMessages($langs->trans('FpOptSaved'), null);
		header('Location: '.$self);
		exit;
	}
	setEventMessages(implode('<br>', $errors), null, 'errors');
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('FpOptionsTitle'), '', '', 0, 0, '', '', '', 'mod-facturapublica page-admin-options');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('FpTabName'), $linkback, 'title_setup');
print dol_get_fiche_head(facturapublica_admin_prepare_head(), 'options', '', -1, '');

$val = function ($post, $const, $default = '') use ($action) {
	return $action === 'save' ? GETPOST($post, 'alphanohtml') : getDolGlobalString($const, $default);
};
$curExempt = '';
foreach ($exempts as $k => $label) {
	if ($label === getDolGlobalString('FACTURAPUBLICA_DEFAULT_EXEMPT')) {
		$curExempt = $k;
	}
}
$mode = $val('vf_mode', 'FACTURAPUBLICA_VF_MODE', 'auto');

print '<div class="fpw">';
print facturapublica_top_bars();
print '<div class="fpw-title">'.$langs->trans('FpOptionsTitle').'</div>';
print '<form method="POST" action="'.$self.'" class="fpw-card fpo"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';

$row = function ($label, $control, $help = '', $error = '') {
	$out = '<div class="fpo-row"><div class="fpo-l">'.$label.'</div><div class="fpo-c">'.$control;
	$out .= $help ? '<div class="fpo-help">'.$help.'</div>' : '';
	$out .= $error ? '<div class="fpc-err">'.$error.'</div>' : '';
	return $out.'</div></div>';
};

$attach = $action === 'save' ? GETPOST('attach_pdf', 'int') : getDolGlobalInt('FACTURAPUBLICA_ATTACH_PDF', 1);
print $row('<label for="attach_pdf">'.$langs->trans('FpOptAttachPdf').'</label>', '<input type="checkbox" id="attach_pdf" name="attach_pdf" value="1"'.($attach ? ' checked' : '').'>', $langs->trans('FpOptAttachPdfHelp'));

$split = $action === 'save' ? GETPOST('split', 'int') : getDolGlobalString('FACTURAPUBLICA_SPLIT_SERIES');
print $row('<label for="split">'.$langs->trans('FpOptSplit').'</label>', '<input type="checkbox" id="split" name="split" value="1"'.($split ? ' checked' : '').'>', $langs->trans('FpOptSplitHelp'));

$unc = $action === 'save' ? GETPOST('uninstall_cert', 'int') : getDolGlobalString('FACTURAPUBLICA_UNINSTALL_CERT');
print $row('<label for="uninstall_cert">'.$langs->trans('FpUninstallCert').'</label>', '<input type="checkbox" id="uninstall_cert" name="uninstall_cert" value="1"'.($unc ? ' checked' : '').'>', $langs->trans('FpUninstallCertHelp'));

print $row('<label for="notify_email">'.$langs->trans('FpOptNotify').'</label>', '<input type="email" id="notify_email" name="notify_email" class="flat minwidth300" value="'.dol_escape_htmltag($val('notify_email', 'FACTURAPUBLICA_NOTIFY_EMAIL')).'">', $langs->trans('FpOptNotifyHelp'), isset($errors['notify_email']) ? $errors['notify_email'] : '');

print $row('<label for="exempt">'.$langs->trans('FpOptExempt').'</label>', $form->selectarray('exempt', $exempts, $action === 'save' ? GETPOST('exempt', 'aZ09') : $curExempt, $langs->trans('FpOptExemptOwn'), 0, 0, '', 0, 0, 0, '', 'minwidth300'), $langs->trans('FpOptExemptHelp'));

print $row('<label for="tsa_url">'.$langs->trans('FpOptTsa').'</label>', '<input type="url" id="tsa_url" name="tsa_url" class="flat minwidth300" placeholder="https://" value="'.dol_escape_htmltag($val('tsa_url', 'FACTURAPUBLICA_TSA_URL')).'">', $langs->trans('FpOptTsaHelp'), isset($errors['tsa_url']) ? $errors['tsa_url'] : '');

// VERI*FACTU check URL (F13)
$radios = '<div class="fpw-env">';
foreach (array('auto' => 'FpOptVfAuto', 'field' => 'FpOptVfField', 'none' => 'FpOptVfNone') as $k => $label) {
	$radios .= '<label><input type="radio" name="vf_mode" value="'.$k.'"'.($mode === $k ? ' checked' : '').'> '.$langs->trans($label).'</label>';
}
$radios .= '</div>';
print $row($langs->trans('FpOptVfMode'), $radios, $langs->trans('FpOptVfModeHelp'));
if ($invoiceFields) {
	$fieldCtl = $form->selectarray('vf_field', $invoiceFields, $val('vf_field', 'FACTURAPUBLICA_VF_FIELD'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
	$fieldCtl = '<span class="opacitymedium">'.$langs->trans('FpOptVfNoFields').'</span>';
}
print $row('<label for="vf_field">'.$langs->trans('FpOptVfFieldPick').'</label>', $fieldCtl, '', isset($errors['vf_field']) ? $errors['vf_field'] : '');
print $row('<label for="vf_place">'.$langs->trans('FpOptVfPlace').'</label>', $form->selectarray('vf_place', array('additional' => $langs->trans('FpOptVfPlaceAdditional')), 'additional', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200'));

print '<div class="fpw-acts"><button type="submit" class="fpc-go">'.$langs->trans('Save').'</button></div>';
print '</form></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
