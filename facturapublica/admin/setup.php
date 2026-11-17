<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup facturapublica
 * \brief   Setup wizard: company data, certificate, registration on FACe, connection test
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
require_once __DIR__.'/../lib/facturapublica.lib.php';
require_once __DIR__.'/../class/FpBuilder.class.php';

$langs->loadLangs(array('admin', 'companies', 'facturapublica@facturapublica'));
if (!isModEnabled('facturapublica') || !$user->hasRight('facturapublica', 'setup')) {
	accessforbidden($langs->trans('FpSetupNoRight'));
}

// FACe's supplier portal, where the certificate is registered for web services (checked November 2026)
$faceUrl = 'https://face.gob.es/es/proveedores';

$action = GETPOST('action', 'aZ09');
// Every action changes something, so each needs the page's token
if ($action !== '' && !facturapublica_token_ok()) {
	setEventMessages($langs->trans('ErrorBadToken'), null, 'errors');
	$action = '';
}
$self = dol_buildpath('/facturapublica/admin/setup.php', 1);
$missing = FpBuilder::companyMissing();
// Lite has no certificate, signing or sending: only step 1, then the lock
$full = facturapublica_is_full();
$hasCert = false;
$registered = getDolGlobalInt('FACTURAPUBLICA_REGISTERED') > 0;
$done = array(1 => !$missing, 2 => $hasCert, 3 => $hasCert && $registered, 4 => $hasCert && getDolGlobalInt('FACTURAPUBLICA_CONN_OK') > 0);
$step = (int) GETPOST('step', 'int');
if ($step < 1 || $step > 4) {
	$step = 4;
	foreach (array(1, 2, 3) as $s) {
		if (!$done[$s]) {
			$step = $s;
			break;
		}
	}
}
if (!$full) {
	$step = 1;
	if (in_array($action, array('pem', 'cert', 'registered', 'test', 'forget'), true)) {
		$action = '';
	}
}
$check = null;
$connError = null;
$connCount = 0;

/*
 * Actions
 */
if ($action === 'persontype') {
	$v = GETPOST('persontype', 'aZ09');
	if (in_array($v, array('J', 'F'), true)) {
		dolibarr_set_const($db, 'FACTURAPUBLICA_PERSON_TYPE', $v, 'chaine', 0, '', $conf->entity);
	} else {
		dolibarr_del_const($db, 'FACTURAPUBLICA_PERSON_TYPE', $conf->entity);
	}
	setEventMessages($langs->trans('FpSaved'), null);
	header('Location: '.$self.'?step=1');
	exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('FpTabWizard'), '', '', 0, 0, '', '', '', 'mod-facturapublica page-admin-setup');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('FpTabName'), $linkback, 'title_setup');
print dol_get_fiche_head(facturapublica_admin_prepare_head(), 'setup', '', -1, '');

print '<div class="fpw">';
print facturapublica_top_bars();
print '<div class="fpw-title">'.$langs->trans('FpWizardTitle').'</div>';
print '<nav class="fpw-steps" aria-label="'.dol_escape_htmltag($langs->trans('FpWizardTitle')).'">';
foreach (array(1, 2, 3, 4) as $s) {
	$cls = $s === $step ? ' n' : ($done[$s] ? ' d' : '');
	print '<a class="fpw-step'.$cls.'" href="'.$self.'?step='.$s.'"'.($s === $step ? ' aria-current="step"' : '').'><i>'.($done[$s] && $s !== $step ? '&#10003;' : $s).'</i>'.$langs->trans('FpStep'.$s).'</a>';
}
print '</nav>';
print '<div class="fpw-card">';

$next = function ($to, $enabled = true) use ($self, $langs) {
	return '<a class="fpc-go'.($enabled ? '' : ' off').'" href="'.$self.'?step='.$to.'"'.($enabled ? '' : ' aria-disabled="true"').'>'.$langs->trans('FpNext').' &rarr;</a>';
};
$back = function ($to) use ($self, $langs) {
	return '<a class="fpc-sec" href="'.$self.'?step='.$to.'">&larr; '.$langs->trans('FpBack').'</a>';
};

if ($step === 1) {
	print '<p>'.$langs->trans('FpStep1Intro').'</p>';
	$c = FpBuilder::companyData();
	$rows = array(
		'FpFieldName' => $c['name'], 'FpFieldNif' => $c['nif'], 'FpFieldAddress' => $c['address'], 'FpFieldZip' => $c['zip'],
		'FpFieldTown' => $c['town'], 'FpFieldProvince' => $c['province'], 'FpFieldCountry' => $c['country'],
	);
	print '<ul class="fpw-list">';
	foreach ($rows as $key => $value) {
		$label = dol_ucfirst(preg_replace('/^(el|la) /', '', $langs->transnoentitiesnoconv($key)));
		if (in_array($key, $missing, true)) {
			print '<li><span class="fpw-ic bad" aria-hidden="true">!</span><span><b>'.dol_escape_htmltag($label).'</b><br>'.dol_escape_htmltag($langs->trans('FpStep1Missing', $langs->transnoentitiesnoconv($key))).'</span>';
			print '<a class="fpc-sec" href="'.DOL_URL_ROOT.'/admin/company.php">'.$langs->trans('FpComplete').'</a></li>';
		} else {
			print '<li><span class="fpw-ic ok" aria-hidden="true">&#10003;</span><span><b>'.dol_escape_htmltag($label).'</b><br>'.dol_escape_htmltag($value).'</span></li>';
		}
	}
	print '</ul>';
	// Legal entity or individual, read from the NIF and changeable
	$auto = facturapublica_person_type($c['nif']);
	$set = getDolGlobalString('FACTURAPUBLICA_PERSON_TYPE');
	print '<form method="POST" action="'.$self.'" class="fpw-grid fpw-person"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="persontype">';
	print '<label for="persontype">'.$langs->trans('FpPersonType').'</label><div>';
	print '<select id="persontype" name="persontype" class="flat minwidth200">';
	print '<option value="">'.dol_escape_htmltag($langs->trans('FpPersonAuto', $langs->transnoentitiesnoconv('FpPerson'.$auto))).'</option>';
	foreach (array('J', 'F') as $t) {
		print '<option value="'.$t.'"'.($set === $t ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('FpPerson'.$t)).'</option>';
	}
	print '</select> <button type="submit" class="fpc-sec">'.$langs->trans('Save').'</button></div></form>';
	if (!$missing) {
		print '<div class="fpw-ok">'.$langs->trans('FpAllGood').'</div>';
	}
	print $full ? '<div class="fpw-acts">'.$next(2, !$missing).'</div>' : facturapublica_lite_lock();
}


print '</div></div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
