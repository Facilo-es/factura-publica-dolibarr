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
 * \file    facturapublica/class/FpStore.class.php
 * \ingroup facturapublica
 * \brief   Reads and writes the module's own tables (never Dolibarr's invoice tables)
 */

/**
 * Access to llx_facturapublica_* tables
 */
class FpStore
{
	/** Local status of a send that timed out: FACe may or may not have it */
	const UNKNOWN = 'UNK';

	/**
	 * Public-body settings of a third party
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $socid Third party id
	 * @return array Row with defaults when none is saved
	 */
	public static function getPublic($db, $socid)
	{
		global $conf;
		$row = array('fk_soc' => (int) $socid, 'is_public' => 0, 'dir3_oc' => '', 'dir3_og' => '', 'dir3_ut' => '', 'dir3_op' => '', 'needs_file_ref' => 0, 'notify_email' => '', 'saved' => false);
		$sql = 'SELECT is_public, dir3_oc, dir3_og, dir3_ut, dir3_op, needs_file_ref, notify_email FROM '.MAIN_DB_PREFIX.'facturapublica_thirdparty';
		$sql .= ' WHERE fk_soc = '.((int) $socid).' AND entity = '.((int) $conf->entity);
		$res = $db->query($sql);
		if ($res && ($obj = $db->fetch_array($res))) {
			foreach ($obj as $k => $v) {
				if (!is_int($k)) {
					$row[$k] = $v === null ? '' : $v;
				}
			}
			$row['saved'] = true;
		}
		return $row;
	}

	/**
	 * Save public-body settings. Codes are uppercased and stripped of spaces by the caller.
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $socid Third party id
	 * @param array  $data  is_public, dir3_oc, dir3_og, dir3_ut, dir3_op, needs_file_ref, notify_email
	 * @return bool
	 */
	public static function savePublic($db, $socid, $data)
	{
		global $conf;
		$fields = array(
			'is_public' => (int) !empty($data['is_public']),
			'dir3_oc' => (string) $data['dir3_oc'], 'dir3_og' => (string) $data['dir3_og'], 'dir3_ut' => (string) $data['dir3_ut'],
			'dir3_op' => (string) $data['dir3_op'],
			'needs_file_ref' => (int) !empty($data['needs_file_ref']),
			'notify_email' => (string) $data['notify_email'],
		);
		$current = self::getPublic($db, $socid);
		if ($current['saved']) {
			$set = array();
			foreach ($fields as $k => $v) {
				$set[] = $k.' = '.(is_int($v) ? $v : ($v === '' ? 'NULL' : "'".$db->escape($v)."'"));
			}
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'facturapublica_thirdparty SET '.implode(', ', $set);
			$sql .= ' WHERE fk_soc = '.((int) $socid).' AND entity = '.((int) $conf->entity);
		} else {
			$vals = array();
			foreach ($fields as $v) {
				$vals[] = is_int($v) ? $v : ($v === '' ? 'NULL' : "'".$db->escape($v)."'");
			}
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facturapublica_thirdparty (entity, fk_soc, '.implode(', ', array_keys($fields)).')';
			$sql .= ' VALUES ('.((int) $conf->entity).', '.((int) $socid).', '.implode(', ', $vals).')';
		}
		return (bool) $db->query($sql);
	}

	/**
	 * Latest send record of an invoice: the invoice's FACe state
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $invoiceId Invoice id
	 * @return array|null
	 */
	public static function latestSending($db, $invoiceId)
	{
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'facturapublica_sending WHERE fk_facture = '.((int) $invoiceId).' ORDER BY rowid DESC LIMIT 1';
		$res = $db->query($sql);
		if ($res && ($obj = $db->fetch_array($res))) {
			return array_filter($obj, 'is_string', ARRAY_FILTER_USE_KEY);
		}
		return null;
	}

	/**
	 * A send record by id
	 *
	 * @param DoliDB $db Database handler
	 * @param int    $id rowid
	 * @return array|null
	 */
	public static function getSending($db, $id)
	{
		$res = $db->query('SELECT * FROM '.MAIN_DB_PREFIX.'facturapublica_sending WHERE rowid = '.((int) $id));
		if ($res && ($obj = $db->fetch_array($res))) {
			return array_filter($obj, 'is_string', ARRAY_FILTER_USE_KEY);
		}
		return null;
	}

	/**
	 * True when the latest record blocks a new send: it is registered at FACe and not rejected or cancelled (C12)
	 *
	 * @param array|null $s Latest send record
	 * @return bool
	 */
	public static function blocksResend($s)
	{
		if ($s && (string) $s['status_code'] === self::UNKNOWN && ($s['face_registry'] === null || $s['face_registry'] === '')) {
			return true;
		}
		if (!$s || $s['face_registry'] === null || $s['face_registry'] === '') {
			return false;
		}
		if (in_array((string) $s['status_code'], array('2600', '3100'), true) || (string) $s['cancel_code'] === '4300') {
			return false;
		}
		return true;
	}

	/**
	 * Create a send attempt
	 *
	 * @param DoliDB $db     Database handler
	 * @param array  $fields Column => value
	 * @return int New rowid, or 0
	 */
	public static function addSending($db, $fields)
	{
		global $conf, $user;
		$fields = array_merge(array('entity' => (int) $conf->entity, 'fk_user' => (int) $user->id, 'datec' => dol_now()), $fields);
		$cols = array();
		$vals = array();
		foreach ($fields as $k => $v) {
			$cols[] = $k;
			$vals[] = self::sqlValue($db, $k, $v);
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facturapublica_sending ('.implode(', ', $cols).') VALUES ('.implode(', ', $vals).')';
		if (!$db->query($sql)) {
			return 0;
		}
		return (int) $db->last_insert_id(MAIN_DB_PREFIX.'facturapublica_sending');
	}

	/**
	 * Update a send attempt
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $id     rowid
	 * @param array  $fields Column => value
	 * @return bool
	 */
	public static function updateSending($db, $id, $fields)
	{
		$set = array();
		foreach ($fields as $k => $v) {
			$set[] = $k.' = '.self::sqlValue($db, $k, $v);
		}
		return (bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'facturapublica_sending SET '.implode(', ', $set).' WHERE rowid = '.((int) $id));
	}

	/**
	 * Record a FACe status in the history. Repeats of the latest code are not logged twice.
	 *
	 * @param DoliDB $db   Database handler
	 * @param int    $id   Send record id
	 * @param string $code FACe code
	 * @param string $text FACe text
	 * @param int    $at   When
	 * @return bool True when it is a new status
	 */
	public static function logStatus($db, $id, $code, $text, $at = null)
	{
		// Processing (1xxx-3xxx) and cancellation (4xxx) states are two lines of history: compare with the last of the same kind
		$kind = strpos((string) $code, '4') === 0 ? "LIKE '4%'" : "NOT LIKE '4%'";
		$res = $db->query('SELECT code FROM '.MAIN_DB_PREFIX.'facturapublica_status_log WHERE fk_sending = '.((int) $id).' AND code '.$kind.' ORDER BY rowid DESC LIMIT 1');
		if ($res && ($obj = $db->fetch_object($res)) && (string) $obj->code === (string) $code) {
			return false;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facturapublica_status_log (fk_sending, code, text, at) VALUES (';
		$sql .= ((int) $id).", '".$db->escape((string) $code)."', '".$db->escape(dol_trunc((string) $text, 250, 'right', 'UTF-8', 1))."', '".$db->idate($at ? $at : dol_now())."')";
		return (bool) $db->query($sql);
	}

	/**
	 * Status history of a send record, oldest first
	 *
	 * @param DoliDB $db Database handler
	 * @param int    $id Send record id
	 * @return array
	 */
	public static function statusLog($db, $id)
	{
		$out = array();
		$res = $db->query('SELECT code, text, at FROM '.MAIN_DB_PREFIX.'facturapublica_status_log WHERE fk_sending = '.((int) $id).' ORDER BY rowid');
		while ($res && ($obj = $db->fetch_object($res))) {
			$out[] = array('code' => $obj->code, 'text' => $obj->text, 'at' => $db->jdate($obj->at));
		}
		return $out;
	}

	/**
	 * SQL literal for a column value. Dates are passed as timestamps for *_at and datec columns.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $k  Column
	 * @param mixed  $v  Value
	 * @return string
	 */
	protected static function sqlValue($db, $k, $v)
	{
		if ($v === null) {
			return 'NULL';
		}
		if (preg_match('/(_at|^datec)$/', $k)) {
			return "'".$db->idate((int) $v)."'";
		}
		if (is_int($v) || is_bool($v)) {
			return (string) (int) $v;
		}
		return "'".$db->escape((string) $v)."'";
	}

	/**
	 * Save a generated file in the invoice's own documents
	 *
	 * @param Facture $invoice Invoice
	 * @param string  $content File content
	 * @param bool    $signed  Signed (.xsig) or not (.xml)
	 * @return string Path, or '' on failure
	 */
	public static function saveFile($invoice, $content, $signed)
	{
		global $conf;
		$ref = dol_sanitizeFileName($invoice->ref);
		$dir = (!empty($conf->facture->multidir_output[$invoice->entity]) ? $conf->facture->multidir_output[$invoice->entity] : $conf->facture->dir_output).'/'.$ref;
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return '';
		}
		$path = $dir.'/'.$ref.'-facturae.'.($signed ? 'xsig' : 'xml');
		if (file_put_contents($path, $content) === false) {
			return '';
		}
		dolChmod($path);
		return $path;
	}
}
