<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    facturapublica/lib/facturapublica.lib.php
 * \ingroup facturapublica
 * \brief   Shared helpers for Factura Pública
 */

// Bundled facturae-php (MIT), loaded without Composer
if (!function_exists('facturapublica_autoload')) {
	/**
	 * Autoloader for the bundled facturae-php library
	 *
	 * @param string $class Class name
	 * @return void
	 */
	function facturapublica_autoload($class)
	{
		$prefix = 'josemmo\\Facturae\\';
		if (strpos($class, $prefix) !== 0) {
			return;
		}
		$file = __DIR__.'/vendor/facturae-php/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
	spl_autoload_register('facturapublica_autoload');
}

/** URL of the paid edition on its own page, shown on Lite locks */
define('FACTURAPUBLICA_STORE_URL', 'https://facturapublica.facilo.es/#precio');

/** Largest file FACe accepts, in bytes (FACe supplier guide) */
define('FACTURAPUBLICA_MAX_BYTES', 10 * 1024 * 1024);

/**
 * True when the full (paid) edition is installed. The Lite build leaves out the signer.
 *
 * @return bool
 */
function facturapublica_is_full()
{
	return is_file(__DIR__.'/../class/FpSigner.class.php');
}

/**
 * Environment in use: 'staging' or 'prod'
 *
 * @return string
 */
function facturapublica_env()
{
	return getDolGlobalString('FACTURAPUBLICA_ENV', 'prod') === 'staging' ? 'staging' : 'prod';
}

/**
 * Folder for the certificate, one per entity. It sits outside the module's document folder on purpose:
 * Dolibarr's document.php serves any file under dir_output to users with the module's read right.
 *
 * @return string
 */
function facturapublica_private_dir()
{
	global $conf;
	return DOL_DATA_ROOT.'/facturapublica_private/'.((int) $conf->entity);
}

/**
 * Move a certificate left in the old folder (inside the document folder, before 1.0.0) to the private one
 *
 * @return void
 */
function facturapublica_migrate_private_dir()
{
	global $conf;
	$base = !empty($conf->facturapublica->dir_output) ? $conf->facturapublica->dir_output : DOL_DATA_ROOT.'/facturapublica';
	$old = $base.'/private/cert.p12';
	if (is_file($old)) {
		$dir = facturapublica_ensure_private_dir();
		if (!is_file($dir.'/cert.p12')) {
			@rename($old, $dir.'/cert.p12');
		}
		if (is_file($old)) {
			file_put_contents($old, random_bytes(max(1, (int) filesize($old))));
			unlink($old);
		}
	}
}

/**
 * Create the private folder, closed to the web, if it doesn't exist yet
 *
 * @return string Path of the folder
 */
function facturapublica_ensure_private_dir()
{
	$dir = facturapublica_private_dir();
	if (!is_dir($dir)) {
		dol_mkdir($dir);
	}
	@chmod($dir, 0700);
	if (!is_file($dir.'/.htaccess')) {
		file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
	}
	if (!is_file($dir.'/index.php')) {
		file_put_contents($dir.'/index.php', "<?php\nhttp_response_code(404);\n");
	}
	if (!is_file(dirname($dir).'/index.php')) {
		file_put_contents(dirname($dir).'/index.php', "<?php\nhttp_response_code(404);\n");
	}
	if (!is_file(dirname($dir).'/.htaccess')) {
		file_put_contents(dirname($dir).'/.htaccess', "Require all denied\nDeny from all\n");
	}
	return $dir;
}

/**
 * Check a Spanish tax ID (NIF, NIE or CIF), including its control character
 *
 * @param string $nif Tax ID, with or without the ES prefix
 * @return bool
 */
function facturapublica_nif_valid($nif)
{
	$nif = facturapublica_nif_clean($nif);
	if (!preg_match('/^[0-9A-Z]{9}$/', $nif)) {
		return false;
	}
	$letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
	// DNI and K/L/M personal IDs
	if (preg_match('/^[0-9]{8}[A-Z]$/', $nif)) {
		return $letters[intval(substr($nif, 0, 8)) % 23] === $nif[8];
	}
	if (preg_match('/^[KLM][0-9]{7}[A-Z]$/', $nif)) {
		return $letters[intval(substr($nif, 1, 7)) % 23] === $nif[8];
	}
	// NIE
	if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nif)) {
		$num = strtr($nif[0], array('X' => '0', 'Y' => '1', 'Z' => '2')).substr($nif, 1, 7);
		return $letters[intval($num) % 23] === $nif[8];
	}
	// CIF of an entity
	if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $nif)) {
		$digits = substr($nif, 1, 7);
		$sum = 0;
		for ($i = 0; $i < 7; $i++) {
			$d = intval($digits[$i]);
			if ($i % 2 === 0) {
				$d *= 2;
				$d = intdiv($d, 10) + ($d % 10);
			}
			$sum += $d;
		}
		$control = (10 - ($sum % 10)) % 10;
		$controlLetter = 'JABCDEFGHI'[$control];
		$last = $nif[8];
		if (strpos('KPQRSNW', $nif[0]) !== false) {
			return $last === $controlLetter;
		}
		if (strpos('ABEH', $nif[0]) !== false) {
			return $last === (string) $control;
		}
		return $last === (string) $control || $last === $controlLetter;
	}
	return false;
}

/**
 * Normalise a tax ID: uppercase, no spaces, dashes or ES prefix
 *
 * @param string $nif Tax ID
 * @return string
 */
function facturapublica_nif_clean($nif)
{
	$nif = strtoupper(preg_replace('/[\s\.\-]/', '', (string) $nif));
	if (strlen($nif) === 11 && substr($nif, 0, 2) === 'ES') {
		$nif = substr($nif, 2);
	}
	return $nif;
}

/**
 * Facturae person type for a tax ID: J for an entity, F for a person
 *
 * @param string $nif Tax ID
 * @return string 'J' or 'F'
 */
function facturapublica_person_type($nif)
{
	$nif = facturapublica_nif_clean($nif);
	return preg_match('/^[A-HJNP-SUVW]/', $nif) ? 'J' : 'F';
}

/**
 * Legal entity (J) or individual (F) for the company itself: read from its NIF unless the wizard set it
 *
 * @param string $nif Company NIF
 * @return string J or F
 */
function facturapublica_company_person_type($nif)
{
	$set = getDolGlobalString('FACTURAPUBLICA_PERSON_TYPE');
	return in_array($set, array('J', 'F'), true) ? $set : facturapublica_person_type($nif);
}

/**
 * Tax ID of a third party: Dolibarr keeps it in idprof1 for Spain, or in the VAT number
 *
 * @param Societe $soc Third party
 * @return string
 */
function facturapublica_soc_nif($soc)
{
	$nif = facturapublica_nif_clean($soc->idprof1);
	if ($nif === '' && !empty($soc->tva_intra)) {
		$nif = facturapublica_nif_clean($soc->tva_intra);
	}
	return $nif;
}

/**
 * Clean a DIR3 code: uppercase, no spaces
 *
 * @param string $code Code as typed
 * @return string
 */
function facturapublica_dir3_clean($code)
{
	return strtoupper(preg_replace('/\s+/', '', (string) $code));
}

/**
 * True when a DIR3 code has the right shape: a letter and 8 letters or digits
 *
 * @param string $code Cleaned code
 * @return bool
 */
function facturapublica_dir3_valid($code)
{
	return (bool) preg_match('/^[A-Z][A-Z0-9]{8}$/', $code);
}

/**
 * FACe status codes and their Spanish labels (translation keys)
 *
 * @return array<string,string> code => translation key
 */
function facturapublica_status_keys()
{
	return array(
		'1200' => 'FpStatus1200',
		'1300' => 'FpStatus1300',
		'2400' => 'FpStatus2400',
		'2500' => 'FpStatus2500',
		'2600' => 'FpStatus2600',
		'3100' => 'FpStatus3100',
		'4100' => 'FpStatus4100',
		'4200' => 'FpStatus4200',
		'4300' => 'FpStatus4300',
		'4400' => 'FpStatus4400',
	);
}

/**
 * Label to show for a FACe status code
 *
 * @param string $code FACe code
 * @param string $text FACe's own text, used for unknown codes
 * @return string
 */
function facturapublica_status_label($code, $text = '')
{
	global $langs;
	$keys = facturapublica_status_keys();
	if (isset($keys[(string) $code])) {
		return $langs->trans($keys[(string) $code]);
	}
	return $langs->trans('FpStatusUnknown', facturapublica_plain($code), facturapublica_plain($text));
}

/**
 * Badge style for a status code: ok, wait, bad or off
 *
 * @param string $code FACe code
 * @return string
 */
function facturapublica_status_tone($code)
{
	$code = (string) $code;
	if ($code === '2500') {
		return 'ok';
	}
	if ($code === '2600' || $code === '4400') {
		return 'bad';
	}
	if ($code === '3100' || $code === '4300') {
		return 'off';
	}
	return 'wait';
}

/**
 * True when FACe won't change this status any more (paid, rejected, cancelled)
 *
 * @param string $code   Processing status code
 * @param string $cancel Cancellation status code
 * @return bool
 */
function facturapublica_status_final($code, $cancel = '')
{
	return in_array((string) $code, array('2500', '2600', '3100'), true) || (string) $cancel === '4300';
}

/**
 * Format an amount as Spanish euros, for messages
 *
 * @param float $amount Amount
 * @return string
 */
function facturapublica_eur($amount)
{
	return number_format((float) $amount, 2, ',', '.');
}

/**
 * Facturae correction reason codes (Facturae 3.2.2, ReasonCode)
 *
 * @return array<string,string> code => Spanish description
 */
function facturapublica_corr_reasons()
{
	return array(
		'01' => 'Número de la factura',
		'02' => 'Serie de la factura',
		'03' => 'Fecha expedición',
		'04' => 'Nombre y apellidos/Razón Social-Emisor',
		'05' => 'Nombre y apellidos/Razón Social-Receptor',
		'06' => 'Identificación fiscal Emisor/obligado',
		'07' => 'Identificación fiscal Receptor',
		'08' => 'Domicilio Emisor/Obligado',
		'09' => 'Domicilio Receptor',
		'10' => 'Detalle Operación',
		'11' => 'Porcentaje impositivo a aplicar',
		'12' => 'Cuota tributaria a aplicar',
		'13' => 'Fecha/Periodo a aplicar',
		'14' => 'Clase de factura',
		'15' => 'Literales legales',
		'16' => 'Base imponible',
		'80' => 'Cálculo de cuotas repercutidas',
		'81' => 'Cálculo de cuotas retenidas',
		'82' => 'Base imponible modificada por devolución de envases / embalajes',
		'83' => 'Base imponible modificada por descuentos y bonificaciones',
		'84' => 'Base imponible modificada por resolución firme, judicial o administrativa',
		'85' => 'Base imponible modificada cuotas repercutidas no satisfechas. Auto de declaración de concurso',
	);
}

/**
 * Facturae correction methods
 *
 * @return array<string,string> code => Spanish description
 */
function facturapublica_corr_methods()
{
	return array(
		'01' => 'Rectificación íntegra',
		'02' => 'Rectificación por diferencias',
		'03' => 'Rectificación por descuento por volumen de operaciones durante un periodo',
		'04' => 'Autorizadas por la Agencia Tributaria',
	);
}

/**
 * Tabs of the setup pages
 *
 * @return array
 */
function facturapublica_admin_prepare_head()
{
	global $langs, $conf;

	$langs->load('facturapublica@facturapublica');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/facturapublica/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('FpTabWizard');
	$head[$h][2] = 'setup';
	$h++;
	$head[$h][0] = dol_buildpath('/facturapublica/admin/options.php', 1);
	$head[$h][1] = $langs->trans('FpTabOptions');
	$head[$h][2] = 'options';
	$h++;
	$head[$h][0] = dol_buildpath('/facturapublica/admin/import.php', 1);
	$head[$h][1] = $langs->trans('FpTabImport');
	$head[$h][2] = 'import';
	$h++;
	$head[$h][0] = dol_buildpath('/facturapublica/admin/about.php', 1);
	$head[$h][1] = $langs->trans('FpTabAbout');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'facturapublica@facturapublica');
	return $head;
}

/**
 * Bars shown on top of every module screen: test mode and certificate expiry
 *
 * @return string HTML
 */
function facturapublica_top_bars()
{
	global $langs;
	$out = '';
	if (facturapublica_env() === 'staging') {
		$out .= '<div class="fpc-bar fpc-bar-test">'.dol_escape_htmltag($langs->trans('FpTestModeBar')).'</div>';
	}
	return $out;
}

/**
 * Outside text (FACe answers, refs) made safe for a translation parameter: trans() keeps <a and <span tags
 *
 * @param string $s Text
 * @return string
 */
function facturapublica_plain($s)
{
	return str_replace(array('<', '>'), array('‹', '›'), (string) $s);
}

/**
 * True when the request carries this page's CSRF token. Dolibarr 19 does not check it on every request, so the module does.
 *
 * @return bool
 */
function facturapublica_token_ok()
{
	$t = GETPOST('token', 'alpha');
	return $t !== '' && ($t === currentToken() || (isset($_SESSION['newtoken']) && $t === $_SESSION['newtoken']));
}

/**
 * Lock shown in Lite in place of a paid feature
 *
 * @return string HTML
 */
function facturapublica_lite_lock()
{
	global $langs;
	return '<div class="fpc-lock"><span class="fpc-lock-i" aria-hidden="true">&#128274;</span> '.dol_escape_htmltag($langs->trans('FpLiteLock')).' <a href="'.FACTURAPUBLICA_STORE_URL.'" target="_blank" rel="noopener">'.dol_escape_htmltag($langs->trans('FpSeeOnStore')).'</a></div>';
}

/**
 * DIR3 finder: a side panel that searches FACe's list and saves the three codes
 *
 * @param string $name    Customer name, shown as the title
 * @param string $postUrl Page that saves the codes
 * @param string $action  Action posted with the codes
 * @param array  $public  Current public-body row
 * @return string
 */
function facturapublica_finder_panel($name, $postUrl, $action, $public)
{
	global $langs;
	$at = FpDir3::updatedAt();
	$roles = array($langs->transnoentities('FpOc'), $langs->transnoentities('FpOg'), $langs->transnoentities('FpUt'));
	$out = '<div class="fpc-back" id="fp-back" hidden></div>';
	$out .= '<aside class="fpc-pan" id="fp-finder" hidden role="dialog" aria-label="'.dol_escape_htmltag($langs->trans('FpDir3Find')).'"';
	$out .= ' data-url="'.dol_escape_htmltag(dol_buildpath('/facturapublica/ajax/dir3.php', 1)).'"';
	$out .= ' data-roles="'.dol_escape_htmltag(json_encode($roles)).'"';
	$out .= ' data-none="'.dol_escape_htmltag($langs->transnoentities('FpDir3NoResults')).'"';
	$out .= ' data-searching="'.dol_escape_htmltag($langs->transnoentities('FpDir3Searching')).'">';
	$out .= '<form method="POST" action="'.dol_escape_htmltag($postUrl).'">';
	$out .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.dol_escape_htmltag($action).'">';
	foreach (array('dir3_oc', 'dir3_og', 'dir3_ut') as $k) {
		$out .= '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag((string) $public[$k]).'">';
	}
	$out .= '<header><b>'.dol_escape_htmltag($name).'</b><p>'.dol_escape_htmltag($langs->trans('FpDir3Help')).'</p>';
	$out .= '<button type="button" class="fpc-x" aria-label="'.dol_escape_htmltag($langs->trans('Close')).'">&times;</button></header>';
	$out .= '<div class="fpc-pb"><label class="fpc-search"><input type="search" class="fp-q noborderfocus" autocomplete="off" placeholder="'.dol_escape_htmltag($langs->trans('FpDir3Search')).'" value="'.dol_escape_htmltag($name).'"></label>';
	$out .= '<div class="fpc-res" aria-live="polite"></div></div>';
	$out .= '<footer><span>'.($at ? $langs->trans('FpDir3Updated', dol_print_date($at, 'day')) : $langs->trans('FpDir3Never')).'</span>';
	$out .= '<button type="submit" class="fpc-go" disabled>'.$langs->trans('FpDir3Save').'</button></footer>';
	$out .= '</form></aside>';
	return $out;
}

/**
 * SQL pieces for the latest FACe send of each invoice, in the current environment
 *
 * @return array{base: string, open: string} FROM/WHERE part, and the filter for invoices still waiting for payment
 */
function facturapublica_sent_sql()
{
	global $db, $conf, $user;
	$P = MAIN_DB_PREFIX;
	$base = ' FROM '.$P.'facturapublica_sending as s';
	$base .= ' INNER JOIN '.$P.'facture as f ON f.rowid = s.fk_facture';
	$base .= ' INNER JOIN '.$P.'societe as so ON so.rowid = f.fk_soc';
	$base .= ' WHERE s.entity = '.((int) $conf->entity)." AND s.environment = '".$db->escape(facturapublica_env())."' AND s.face_registry IS NOT NULL";
	// A user limited to their own customers sees only theirs, as in Dolibarr's invoice list
	if (!$user->hasRight('societe', 'client', 'voir')) {
		$base .= ' AND EXISTS (SELECT 1 FROM '.$P.'societe_commerciaux as sc WHERE sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $user->id).')';
	}
	$base .= ' AND s.rowid IN (SELECT MAX(s2.rowid) FROM '.$P.'facturapublica_sending as s2 WHERE s2.face_registry IS NOT NULL GROUP BY s2.fk_facture)';
	$open = " AND (s.status_code IS NULL OR s.status_code NOT IN ('2500', '2600', '3100')) AND (s.cancel_code IS NULL OR s.cancel_code <> '4300')";
	return array('base' => $base, 'open' => $open);
}

/**
 * The four numbers of the summary page and the home widget
 *
 * @return array{total: int, month: int, pending: int, amount: float, rejected: int}
 */
function facturapublica_counts()
{
	global $db;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
	$q = facturapublica_sent_sql();
	$one = function ($sql) use ($db) {
		$resql = $db->query($sql);
		$o = $resql ? $db->fetch_object($resql) : null;
		return $o ? (float) $o->n : 0;
	};
	$monthStart = dol_get_first_day((int) dol_print_date(dol_now(), '%Y'), (int) dol_print_date(dol_now(), '%m'));
	return array(
		'total' => (int) $one('SELECT COUNT(*) as n'.$q['base']),
		'month' => (int) $one('SELECT COUNT(*) as n'.$q['base']." AND s.sent_at >= '".$db->idate($monthStart)."'"),
		'pending' => (int) $one('SELECT COUNT(*) as n'.$q['base'].$q['open']),
		'amount' => $one('SELECT SUM(f.total_ttc) as n'.$q['base'].$q['open']),
		'rejected' => (int) $one('SELECT COUNT(*) as n'.$q['base']." AND s.status_code = '2600'"),
	);
}
