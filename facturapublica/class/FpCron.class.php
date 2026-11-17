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
 * \file    facturapublica/class/FpCron.class.php
 * \ingroup facturapublica
 * \brief   Scheduled jobs: daily FACe statuses and certificate watch, weekly DIR3 list
 */

require_once __DIR__.'/../lib/facturapublica.lib.php';
require_once __DIR__.'/FpCheck.class.php';
require_once __DIR__.'/FpStore.class.php';
require_once __DIR__.'/FpDir3.class.php';

/**
 * Jobs called by Dolibarr's cron module. Each returns 0 on success, as cron expects.
 */
class FpCron
{
	/** @var DoliDB */
	public $db;
	/** @var string Shown in the job's last result */
	public $output = '';
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Ask FACe for every sent invoice that isn't final, then check the certificate's expiry
	 *
	 * @return int 0 when done
	 */
	public function runDaily()
	{
		global $conf;
		$checked = 0;
		$failed = 0;
		$warned = $this->watchCertificate();
		$this->output = 'checked '.$checked.', failed '.$failed.($warned ? ', certificate warning '.$warned : '');
		return 0;
	}

	/**
	 * Warn once 30 days and once 7 days before the certificate expires
	 *
	 * @return string '30', '7' or '' when nothing was warned
	 */
	public function watchCertificate()
	{
		global $conf, $langs, $user;
		$until = getDolGlobalInt('FACTURAPUBLICA_CERT_NOTAFTER');
		if ($until <= 0 || $until < dol_now()) {
			return '';
		}
		$days = (int) floor(($until - dol_now()) / 86400);
		$done = getDolGlobalString('FACTURAPUBLICA_CERT_WARNED');
		$step = '';
		if ($days <= 7 && $done !== '7') {
			$step = '7';
		} elseif ($days <= 30 && $days > 7 && $done === '') {
			$step = '30';
		}
		if ($step === '') {
			return '';
		}
		dolibarr_set_const($this->db, 'FACTURAPUBLICA_CERT_WARNED', $step, 'chaine', 0, '', $conf->entity);
		if (isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$langs->load('facturapublica@facturapublica');
			$ev = new ActionComm($this->db);
			$ev->type_code = 'AC_OTH_AUTO';
			$ev->code = 'AC_FP_CERT';
			$ev->label = $langs->transnoentities('FpCertExpiresSoon', dol_print_date($until, 'day'));
			$ev->note_private = $langs->transnoentities('W03', dol_print_date($until, 'day'));
			$ev->datep = dol_now();
			$ev->percentage = -1;
			$ev->userownerid = (int) $user->id;
			$ev->create($user);
		}
		return $step;
	}

	/**
	 * Download FACe's administrations again, and the units already in use
	 *
	 * @return int 0 when done or skipped, -1 when FACe failed
	 */
	public function runWeekly()
	{
		if (!facturapublica_is_full() || FpCheck::certState() !== '') {
			$this->output = 'skipped: no valid certificate';
			return 0;
		}
	}
}
