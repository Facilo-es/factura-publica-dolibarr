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
 * \file    facturapublica/class/FpCheck.class.php
 * \ingroup facturapublica
 * \brief   Pre-send check: lists every problem of an invoice at once (C01-C12, W01-W05)
 */
require_once __DIR__.'/../lib/facturapublica.lib.php';
require_once __DIR__.'/FpStore.class.php';
require_once __DIR__.'/FpBuilder.class.php';

/**
 * Pre-send check of one invoice
 */
class FpCheck
{
	/** @var DoliDB */
	public $db;

	/** @var array Problems: list of array(code, blocking, text, url, linklabel) */
	public $problems = array();

	/** @var array Public-body row of the customer */
	public $public = array();

	/** @var array|null Latest send record */
	public $sending = null;

	/** @var \josemmo\Facturae\Facturae|null Built document, when the data checks passed */
	public $fac = null;

	/** @var string Unsigned XML of the built document */
	public $xml = '';

	/** @var string[] Schema messages, if the XSD check failed */
	public $schemaErrors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add a problem
	 *
	 * @param string $code      Code (C01, W03...)
	 * @param string $text      Message, already translated
	 * @param string $url       Fix link, or ''
	 * @param string $linklabel Fix link label key
	 * @return void
	 */
	protected function add($code, $text, $url = '', $linklabel = 'FpFix')
	{
		$this->problems[] = array('code' => $code, 'blocking' => $code[0] === 'C', 'text' => $text, 'url' => $url, 'linklabel' => $linklabel);
	}

	/**
	 * True when at least one blocking problem was found
	 *
	 * @return bool
	 */
	public function hasBlocking()
	{
		foreach ($this->problems as $p) {
			if ($p['blocking']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Codes found
	 *
	 * @return string[]
	 */
	public function codes()
	{
		return array_column($this->problems, 'code');
	}

	/**
	 * State of the stored certificate: '' when usable, else 'missing', 'expired' or 'notyet'
	 *
	 * @return string
	 */
	public static function certState()
	{
		if (!is_file(facturapublica_private_dir().'/cert.p12') || !getDolGlobalString('FACTURAPUBLICA_CERT_PASS')) {
			return 'missing';
		}
		$until = getDolGlobalInt('FACTURAPUBLICA_CERT_NOTAFTER');
		if ($until > 0 && $until < dol_now()) {
			return 'expired';
		}
		$from = getDolGlobalInt('FACTURAPUBLICA_CERT_NOTBEFORE');
		if ($from > 0 && $from > dol_now()) {
			return 'notyet';
		}
		return '';
	}

	/**
	 * Run every check. Builds the document when the data allow it, so totals, schema and size are checked too.
	 *
	 * @param Facture $invoice     Invoice with lines and thirdparty loaded
	 * @param bool    $withSigning Include the certificate check (paid edition)
	 * @return bool True when nothing blocks
	 */
	public function run($invoice, $withSigning = true)
	{
		global $langs, $conf;

		$langs->load('facturapublica@facturapublica');
		$this->problems = array();
		$this->fac = null;
		$this->xml = '';
		$this->schemaErrors = array();

		$cardUrl = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $invoice->id);
		$soc = $invoice->thirdparty;
		$this->public = FpStore::getPublic($this->db, $soc->id);
		$this->sending = FpStore::latestSending($this->db, $invoice->id);
		$tabUrl = dol_buildpath('/facturapublica/thirdparty_tab.php', 1).'?socid='.((int) $soc->id);

		// C01 draft
		if ((int) $invoice->statut === Facture::STATUS_DRAFT || (int) $invoice->status === Facture::STATUS_DRAFT) {
			$this->add('C01', $langs->trans('C01'), $cardUrl);
		}

		// C02 public body and DIR3 codes
		if (empty($this->public['is_public'])) {
			$this->add('C02', $langs->trans('C02NotPublic'), $tabUrl, 'FpConfigureIt');
		} else {
			$missing = false;
			foreach (array('dir3_oc' => 'FpOc', 'dir3_og' => 'FpOg', 'dir3_ut' => 'FpUt') as $f => $label) {
				if ($this->public[$f] === '') {
					$missing = true;
				}
			}
			if ($missing) {
				$this->add('C02', $langs->trans('C02'), $tabUrl);
			}
			foreach (array('dir3_oc' => 'FpOc', 'dir3_og' => 'FpOg', 'dir3_ut' => 'FpUt', 'dir3_op' => 'FpOp') as $f => $label) {
				if ($this->public[$f] !== '' && !facturapublica_dir3_valid($this->public[$f])) {
					$this->add('C02', $langs->trans('C02Format', $langs->transnoentities($label)), $tabUrl);
				}
			}
		}

		// C03 customer NIF
		if (!facturapublica_nif_valid(facturapublica_soc_nif($soc))) {
			$this->add('C03', $langs->trans('C03'), DOL_URL_ROOT.'/societe/card.php?socid='.((int) $soc->id).'&action=edit');
		}

		// C04 company data
		$missing = FpBuilder::companyMissing();
		if ($missing) {
			$names = array();
			foreach ($missing as $k) {
				$names[] = $langs->transnoentities($k);
			}
			$this->add('C04', $langs->trans('C04', implode(', ', $names)), DOL_URL_ROOT.'/admin/company.php');
		}

		// C05 currency
		$currency = !empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : $conf->currency;
		if ($currency !== 'EUR' || $conf->currency !== 'EUR') {
			$this->add('C05', $langs->trans('C05', $currency !== 'EUR' ? $currency : $conf->currency), $cardUrl);
		}

		// C09 credit note details
		if ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE) {
			$lack = array();
			if (empty($invoice->fk_facture_source)) {
				$lack[] = $langs->transnoentities('FpCorrInvoice');
			}
			if (empty($invoice->array_options['options_fp_corr_reason'])) {
				$lack[] = $langs->transnoentities('FpCorrReason');
			}
			if ($lack) {
				$this->add('C09', $langs->trans('C09', implode(', ', $lack)), $cardUrl.'&action=edit_extras&attribute=fp_corr_reason&token='.newToken());
			}
		}

		// C11 certificate, only for signing and sending
		$certState = $withSigning ? self::certState() : '';
		if ($certState !== '') {
			$this->add('C11', $langs->trans('C11'), dol_buildpath('/facturapublica/admin/setup.php', 1).'?step=2', 'FpGoToCert');
		}

		// C12 already at FACe
		if (FpStore::blocksResend($this->sending)) {
			if ((string) $this->sending['status_code'] === FpStore::UNKNOWN) {
				$this->add('C12', $langs->trans('C12Unknown', dol_print_date($this->db->jdate($this->sending['datec']), 'dayhour')));
				$this->problems[count($this->problems) - 1]['unknown'] = true;
			} else {
				$this->add('C12', $langs->trans('C12', facturapublica_plain($this->sending['face_registry'])));
			}
		}

		// Build the file when the data allow it: C06, C07, C08, C10 and the schema
		$dataOk = !array_intersect($this->codes(), array('C02', 'C03', 'C04', 'C05', 'C09'));
		if ($dataOk && is_array($invoice->lines)) {
			$builder = new FpBuilder($this->db);
			try {
				$fac = $builder->build($invoice, $this->public, (bool) getDolGlobalInt('FACTURAPUBLICA_ATTACH_PDF', 1));
				foreach ($builder->lineIssues as $issue) {
					if ($issue[0] === 'C06') {
						$this->add('C06', $langs->trans('C06', $issue[1], $issue[2]), $cardUrl.'&token='.newToken().'&action=editline&lineid='.((int) $invoice->lines[$issue[1] - 1]->id).'#line_'.((int) $invoice->lines[$issue[1] - 1]->id));
					} else {
						$this->add('C08', $langs->trans('C08', $issue[1]), dol_buildpath('/facturapublica/admin/options.php', 1).'#exempt');
					}
				}
				foreach (FpBuilder::compareTotals($fac, $invoice) as $d) {
					$this->add('C07', $langs->trans('C07', $langs->transnoentities($d[0]), facturapublica_eur($d[1]), facturapublica_eur($d[2])), 'mailto:support@facilo.es', 'FpWriteUs');
				}
				$xml = $fac->export();
				$schema = FpBuilder::validateXsd($xml);
				if ($schema) {
					$this->schemaErrors = $schema;
					$this->add('C07', $langs->trans('FpSchemaError'), '', '');
				}
				// The signature adds about 8 KB; keep a margin
				$bytes = strlen($xml) + 16384;
				if ($bytes > FACTURAPUBLICA_MAX_BYTES) {
					$this->add('C10', $langs->trans('C10', number_format($bytes / 1048576, 1, ',', '.'), FACTURAPUBLICA_MAX_BYTES / 1048576), dol_buildpath('/facturapublica/admin/options.php', 1));
				}
				$this->fac = $fac;
				$this->xml = $xml;

				if ($builder->unitGuessed) {
					$this->add('W05', $langs->trans('W05'));
				}
			} catch (Throwable $e) {
				dol_syslog('facturapublica build error: '.$e->getMessage(), LOG_ERR);
				$this->schemaErrors = array($e->getMessage());
				$this->add('C07', $langs->trans('FpSchemaError'), '', '');
			}
		}

		// Warnings
		if (abs((float) $invoice->total_ttc) <= 5000) {
			$this->add('W01', $langs->trans('W01'));
		}
		if (!empty($this->public['needs_file_ref']) && empty($invoice->array_options['options_fp_file_ref']) && empty($invoice->array_options['options_fp_contract_ref'])) {
			$this->add('W02', $langs->trans('W02'), $cardUrl.'&action=edit_extras&attribute=fp_file_ref&token='.newToken());
		}
		if ($withSigning && $certState === '') {
			$until = getDolGlobalInt('FACTURAPUBLICA_CERT_NOTAFTER');
			if ($until > 0 && $until < dol_now() + 30 * 86400) {
				$this->add('W03', $langs->trans('W03', dol_print_date($until, 'day')), dol_buildpath('/facturapublica/admin/setup.php', 1).'?step=2', 'FpGoToCert');
			}
		}
		$payCode = (string) $invoice->mode_reglement_code;
		if (($payCode === '' || $payCode === 'VIR') && FpBuilder::iban($this->db, $invoice) === '') {
			$this->add('W04', $langs->trans('W04'), DOL_URL_ROOT.'/compta/bank/list.php');
			// FACe refuses a transfer without an IBAN (error 426), so it blocks sending; the unsigned file can still be downloaded
			if ($withSigning) {
				$this->problems[count($this->problems) - 1]['blocking'] = true;
			}
		}

		// Blocking problems first, in code order
		usort($this->problems, function ($a, $b) {
			if ($a['blocking'] !== $b['blocking']) {
				return $a['blocking'] ? -1 : 1;
			}
			return strcmp($a['code'], $b['code']);
		});

		return !$this->hasBlocking();
	}
}
