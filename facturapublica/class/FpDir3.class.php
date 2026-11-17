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
 * \file    facturapublica/class/FpDir3.class.php
 * \ingroup facturapublica
 * \brief   Local copy of FACe's DIR3 directory: administrations, and their units fetched on demand
 */

require_once __DIR__.'/../lib/facturapublica.lib.php';

/**
 * DIR3 finder. An administration is kept as one row without units until its units are fetched.
 */
class FpDir3
{
	/** Most administrations a search returns */
	const MAX_ADMINS = 12;
	/** Most administrations a search fetches units for, when they have none yet */
	const MAX_FETCH = 3;

	/**
	 * Download FACe's administrations and keep the units already fetched
	 *
	 * @param DoliDB $db       Database handler
	 * @param bool   $withUnits Fetch again the units of administrations already loaded (weekly job)
	 * @return array ok, count, message
	 */
	public static function refresh($db, $withUnits = false)
	{
		global $langs;
		$langs->load('facturapublica@facturapublica');
		if (!class_exists('FpFace')) {
			return array('ok' => false, 'count' => 0, 'message' => $langs->trans('FpLiteLocked'));
		}
	}

	/**
	 * Administrations whose units are already stored
	 *
	 * @param DoliDB $db Database handler
	 * @return array code => name
	 */
	protected static function loadedAdmins($db)
	{
		$out = array();
		$res = $db->query('SELECT DISTINCT admin_code, admin_name FROM '.MAIN_DB_PREFIX.'facturapublica_dir3_unit WHERE oc_code IS NOT NULL');
		while ($res && ($o = $db->fetch_object($res))) {
			$out[$o->admin_code] = $o->admin_name;
		}
		return $out;
	}

	/**
	 * Fetch and store the units of one administration, replacing what was stored
	 *
	 * @param DoliDB $db   Database handler
	 * @param string $code Administration code
	 * @param string $name Administration name, '' to take the first managing body's
	 * @return array Result of the FACe units call: ok, rows, code, text, network
	 */
	public static function fetchUnits($db, $code, $name = '')
	{
		$code = facturapublica_dir3_clean($code);
		if (!facturapublica_dir3_valid($code)) {
			return array('ok' => false, 'rows' => array(), 'code' => 'format', 'text' => '', 'network' => false);
		}
		if (!class_exists('FpFace')) {
			return array('ok' => false, 'rows' => array(), 'code' => 'lite', 'text' => '', 'network' => false);
		}
	}

	/**
	 * Search by name or code. Administrations found without units get them from FACe, a few per search.
	 * A query shaped like a DIR3 code that isn't stored is asked to FACe directly.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $query Text typed
	 * @param bool   $fetch Ask FACe for missing units (needs a certificate)
	 * @return array List of array(code, name, units => list of rows)
	 */
	public static function search($db, $query, $fetch = true)
	{
		$query = trim((string) $query);
		if (dol_strlen($query) < 2) {
			return array();
		}
		$admins = self::matchAdmins($db, $query);
		$code = facturapublica_dir3_clean($query);
		if ($fetch && !$admins && facturapublica_dir3_valid($code)) {
			self::fetchUnits($db, $code);
			$admins = self::matchAdmins($db, $query);
		}
		$out = array();
		$fetched = 0;
		foreach ($admins as $acode => $aname) {
			$units = self::units($db, $acode);
			if (!$units && $fetch && $fetched < self::MAX_FETCH) {
				$fetched++;
				self::fetchUnits($db, $acode, $aname);
				$units = self::units($db, $acode);
			}
			$out[] = array('code' => $acode, 'name' => $aname, 'units' => self::rank($units, $query));
		}
		return $out;
	}

	/**
	 * Administrations whose name or code, or one of whose units, matches every word typed
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $query Text typed
	 * @return array code => name
	 */
	protected static function matchAdmins($db, $query)
	{
		$words = array_slice(array_filter(preg_split('/\s+/', $query), function ($w) {
			return dol_strlen($w) >= 2;
		}), 0, 5);
		if (!$words) {
			return array();
		}
		$where = array();
		foreach ($words as $w) {
			$like = "'%".(method_exists($db, 'escapeforlike') ? $db->escape($db->escapeforlike($w)) : $db->escape($w))."%'";
			$or = array();
			foreach (array('admin_code', 'admin_name', 'oc_code', 'oc_name', 'og_code', 'og_name', 'ut_code', 'ut_name') as $col) {
				$or[] = $col.' LIKE '.$like;
			}
			$where[] = '('.implode(' OR ', $or).')';
		}
		$sql = 'SELECT admin_code, MIN(admin_name) as admin_name FROM '.MAIN_DB_PREFIX.'facturapublica_dir3_unit';
		$sql .= ' WHERE '.implode(' AND ', $where).' GROUP BY admin_code ORDER BY MIN(LENGTH(admin_name)), admin_code';
		$sql .= $db->plimit(self::MAX_ADMINS);
		$out = array();
		$res = $db->query($sql);
		while ($res && ($o = $db->fetch_object($res))) {
			$out[$o->admin_code] = (string) $o->admin_name;
		}
		return $out;
	}

	/**
	 * Stored units of one administration
	 *
	 * @param DoliDB $db   Database handler
	 * @param string $code Administration code
	 * @return array
	 */
	public static function units($db, $code)
	{
		$out = array();
		$sql = 'SELECT oc_code, oc_name, og_code, og_name, ut_code, ut_name FROM '.MAIN_DB_PREFIX.'facturapublica_dir3_unit';
		$sql .= " WHERE admin_code = '".$db->escape($code)."' AND oc_code IS NOT NULL ORDER BY og_name, ut_name";
		$res = $db->query($sql);
		while ($res && ($o = $db->fetch_array($res))) {
			$out[] = array('oc_code' => $o['oc_code'], 'oc_name' => $o['oc_name'], 'og_code' => $o['og_code'], 'og_name' => $o['og_name'], 'ut_code' => $o['ut_code'], 'ut_name' => $o['ut_name']);
		}
		return $out;
	}

	/**
	 * Units that match the words typed first, then the rest; at most 30
	 *
	 * @param array  $units Units
	 * @param string $query Text typed
	 * @return array
	 */
	protected static function rank($units, $query)
	{
		$words = array_filter(preg_split('/\s+/', dol_strtolower(dol_string_unaccent($query))));
		$score = array();
		foreach ($units as $i => $u) {
			$hay = dol_strtolower(dol_string_unaccent(implode(' ', $u)));
			$score[$i] = 0;
			foreach ($words as $w) {
				$score[$i] += strpos($hay, $w) !== false ? 1 : 0;
			}
		}
		uksort($units, function ($a, $b) use ($score) {
			return $score[$b] - $score[$a] ?: $a - $b;
		});
		return array_slice(array_values($units), 0, 30);
	}

	/**
	 * When the list was last downloaded
	 *
	 * @return int Timestamp, 0 if never
	 */
	public static function updatedAt()
	{
		global $db;
		$at = getDolGlobalInt('FACTURAPUBLICA_DIR3_AT');
		// Units loaded on demand for one administration count as an update too
		$resql = $db->query('SELECT MAX(fetched_at) AS t FROM '.MAIN_DB_PREFIX.'facturapublica_dir3_unit');
		if ($resql && ($o = $db->fetch_object($resql)) && $o->t) {
			$at = max($at, (int) $db->jdate($o->t));
		}
		return $at;
	}
}
