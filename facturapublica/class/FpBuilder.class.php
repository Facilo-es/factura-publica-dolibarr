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
 * \file    facturapublica/class/FpBuilder.class.php
 * \ingroup facturapublica
 * \brief   Turns a validated Dolibarr invoice into a Facturae 3.2.2 document.
 *
 * Reads only. Amounts come from Dolibarr's stored figures; nothing is written to llx_facture or llx_facturedet.
 */
require_once __DIR__.'/../lib/facturapublica.lib.php';

use josemmo\Facturae\Facturae;
use josemmo\Facturae\FacturaeItem;
use josemmo\Facturae\FacturaeParty;
use josemmo\Facturae\FacturaeCentre;
use josemmo\Facturae\FacturaeFile;
use josemmo\Facturae\FacturaePayment;
use josemmo\Facturae\CorrectiveDetails;

/**
 * Builds the Facturae document for one invoice
 */
class FpBuilder
{
	/** @var DoliDB */
	public $db;

	/** @var array Line problems found while building: list of array(code, n, detail) */
	public $lineIssues = array();

	/** @var bool True when at least one line had no Dolibarr unit, so "Unidades" was used */
	public $unitGuessed = false;

	/** Dolibarr unit code => Facturae unit code */
	const UNITS = array(
		'P' => '01', 'SET' => '01', 'H' => '02', 'KG' => '03', 'L' => '04', 'G' => '21',
		'M' => '25', 'MM' => '26', 'CM' => '16', 'M3' => '33', 'S' => '34',
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Seller data from Dolibarr's company settings
	 *
	 * @return array name, nif, address, zip, town, province, country
	 */
	public static function companyData()
	{
		global $conf, $mysoc, $db;

		$province = '';
		if (!empty($mysoc->state_id) && function_exists('getState')) {
			$province = getState($mysoc->state_id, 0, $db);
		} elseif (!empty($mysoc->state)) {
			$province = $mysoc->state;
		}
		return array(
			'name' => (string) $mysoc->name,
			'nif' => facturapublica_nif_clean(!empty($mysoc->idprof1) ? $mysoc->idprof1 : $mysoc->tva_intra),
			'address' => trim(preg_replace('/\s+/', ' ', (string) $mysoc->address)),
			'zip' => (string) $mysoc->zip,
			'town' => (string) $mysoc->town,
			'province' => (string) $province,
			'country' => (string) $mysoc->country_code,
		);
	}

	/**
	 * Labels (translation keys) of the company fields that are missing or wrong
	 *
	 * @return string[] Translation keys
	 */
	public static function companyMissing()
	{
		$c = self::companyData();
		$missing = array();
		if ($c['name'] === '') {
			$missing[] = 'FpFieldName';
		}
		if (!facturapublica_nif_valid($c['nif'])) {
			$missing[] = 'FpFieldNif';
		}
		if ($c['address'] === '') {
			$missing[] = 'FpFieldAddress';
		}
		if (!preg_match('/^\d{5}$/', $c['zip'])) {
			$missing[] = 'FpFieldZip';
		}
		if ($c['town'] === '') {
			$missing[] = 'FpFieldTown';
		}
		if ($c['province'] === '') {
			$missing[] = 'FpFieldProvince';
		}
		if ($c['country'] !== 'ES') {
			$missing[] = 'FpFieldCountry';
		}
		return $missing;
	}

	/**
	 * Plain text, no HTML, cut to a maximum length with an ellipsis
	 *
	 * @param string $text Text
	 * @param int    $max  Maximum length
	 * @return string
	 */
	public static function plain($text, $max)
	{
		$text = html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", (string) $text)), ENT_QUOTES, 'UTF-8');
		$text = trim(preg_replace('/[ \t]+/', ' ', $text));
		if (mb_strlen($text) > $max) {
			$text = rtrim(mb_substr($text, 0, $max - 1)).'…';
		}
		return $text;
	}

	/**
	 * Invoice series and number, per the "split series" setting
	 *
	 * @param string $ref Dolibarr ref
	 * @return array array(series, number)
	 */
	public static function splitRef($ref)
	{
		if (getDolGlobalString('FACTURAPUBLICA_SPLIT_SERIES') && preg_match('/^(.*?[^0-9])([0-9]+)$/', $ref, $m)) {
			return array(rtrim($m[1], '-/ '), $m[2]);
		}
		return array('', $ref);
	}

	/**
	 * Tax type of a VAT rate setup: '01' IVA, '03' IGIC, '02' IPSI, or '' when it can't be told.
	 *
	 * @param string $vatCode Dolibarr vat_src_code of the line
	 * @return string
	 */
	public static function taxType($vatCode)
	{
		$code = strtoupper((string) $vatCode);
		if (strpos($code, 'IGIC') !== false) {
			return Facturae::TAX_IGIC;
		}
		if (strpos($code, 'IPSI') !== false) {
			return Facturae::TAX_IPSI;
		}
		return Facturae::TAX_IVA;
	}

	/**
	 * Build the Facturae document. Call FpCheck first: this assumes the blocking checks on data passed.
	 *
	 * @param Facture $invoice   Validated invoice with lines and thirdparty loaded
	 * @param array   $public    Row from llx_facturapublica_thirdparty
	 * @param bool    $attachPdf Attach Dolibarr's PDF if it exists
	 * @return Facturae
	 */
	public function build($invoice, $public, $attachPdf = true)
	{
		global $conf;

		$this->lineIssues = array();
		$this->unitGuessed = false;

		$fac = new Facturae(Facturae::SCHEMA_3_2_2);
		$fac->setPrecision(Facturae::PRECISION_LINE);

		list($serie, $number) = self::splitRef($invoice->ref);
		$fac->setNumber($serie, $number);
		$fac->setIssueDate(dol_print_date($invoice->date, '%Y-%m-%d', 'tzserver'));
		if (!empty($invoice->date_lim_reglement)) {
			$fac->setDueDate(dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver'));
		}

		// Seller
		$c = self::companyData();
		$seller = new FacturaeParty(array(
			'isLegalEntity' => facturapublica_company_person_type($c['nif']) === 'J',
			'taxNumber' => $c['nif'],
			'name' => self::plain($c['name'], 80),
			'address' => self::plain($c['address'], 80),
			'postCode' => $c['zip'],
			'town' => self::plain($c['town'], 50),
			'province' => self::plain($c['province'], 20),
			'countryCode' => 'ESP',
		));
		if (!$seller->isLegalEntity) {
			// A self-employed seller: Facturae wants name and surnames apart. Dolibarr keeps one field, so the first word is the name.
			$parts = preg_split('/\s+/', $seller->name, 3);
			$seller->name = $parts[0];
			$seller->firstSurname = isset($parts[1]) ? $parts[1] : '-';
			$seller->lastSurname = isset($parts[2]) ? $parts[2] : '';
		}
		$fac->setSeller($seller);

		// Buyer: the public body, with its DIR3 centres
		$soc = $invoice->thirdparty;
		$buyerNif = facturapublica_soc_nif($soc);
		$centreBase = array(
			'address' => self::plain($soc->address, 80) ?: '-',
			'postCode' => $soc->zip ?: '00000',
			'town' => self::plain($soc->town, 50) ?: '-',
			'province' => self::plain($soc->state, 20) ?: (self::plain($soc->town, 20) ?: '-'),
			'countryCode' => 'ESP',
		);
		$centres = array();
		foreach (array('dir3_oc' => FacturaeCentre::ROLE_CONTABLE, 'dir3_og' => FacturaeCentre::ROLE_GESTOR, 'dir3_ut' => FacturaeCentre::ROLE_TRAMITADOR, 'dir3_op' => FacturaeCentre::ROLE_PROPONENTE) as $field => $role) {
			if (!empty($public[$field])) {
				$centres[] = new FacturaeCentre(array_merge($centreBase, array('role' => $role, 'code' => $public[$field], 'name' => self::plain($soc->name, 40))));
			}
		}
		$fac->setBuyer(new FacturaeParty(array_merge($centreBase, array(
			'isLegalEntity' => true,
			'taxNumber' => $buyerNif,
			'name' => self::plain($soc->name, 80),
			'centres' => $centres,
		))));

		// Corrective invoice (Dolibarr credit note)
		if ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE) {
			$this->setCorrective($fac, $invoice);
		}

		// Lines
		$defaultExempt = getDolGlobalString('FACTURAPUBLICA_DEFAULT_EXEMPT');
		$n = 0;
		foreach ($invoice->lines as $line) {
			$n++;
			if ((int) $line->product_type === 9 || ($line->special_code && (int) $line->product_type > 1)) {
				continue; // Titles and subtotals carry no amount
			}
			$fac->addItem($this->buildItem($fac, $line, $n, $defaultExempt));
		}

		// Payment: transfer by default, with the company's IBAN
		$fac->addPayment($this->payment($invoice));

		// References
		$fileRef = isset($invoice->array_options['options_fp_file_ref']) ? trim($invoice->array_options['options_fp_file_ref']) : '';
		$contractRef = isset($invoice->array_options['options_fp_contract_ref']) ? trim($invoice->array_options['options_fp_contract_ref']) : '';
		if ($fileRef !== '' || $contractRef !== '') {
			$fac->setReferences($fileRef !== '' ? self::plain($fileRef, 20) : null, null, $contractRef !== '' ? self::plain($contractRef, 20) : null);
		}

		// Additional data: VERI*FACTU check URL and the PDF
		$extra = array();
		$vfUrl = $this->verifactuUrl($invoice, $c['nif']);
		if ($vfUrl !== '') {
			$extra[] = 'URL de cotejo VERI*FACTU: '.$vfUrl;
		}
		if (!empty($invoice->note_public)) {
			$extra[] = self::plain($invoice->note_public, 2000);
		}
		if ($extra) {
			$fac->setAdditionalInformation(self::plain(implode("\n", $extra), 2500));
		}
		if ($attachPdf) {
			$pdf = $this->pdfPath($invoice);
			if ($pdf) {
				$fac->addAttachment(new FacturaeFile(basename($pdf), file_get_contents($pdf)), 'Factura '.$invoice->ref);
			}
		}

		return $fac;
	}

	/**
	 * One Facturae item from one Dolibarr line, rounded to match Dolibarr's stored line total
	 *
	 * @param Facturae $fac           Document (for precision)
	 * @param object   $line          FactureLigne
	 * @param int      $n             Line number shown to the user
	 * @param string   $defaultExempt Default exemption reason
	 * @return FacturaeItem
	 */
	protected function buildItem($fac, $line, $n, $defaultExempt)
	{
		$name = self::plain(trim(($line->product_label ? $line->product_label : $line->label).' '.(($line->product_label || $line->label) && $line->desc !== ($line->product_label ?: $line->label) ? $line->desc : '')), 2500);
		if ($name === '') {
			$name = self::plain($line->desc, 2500);
		}
		if ($name === '') {
			$name = 'Línea '.$n;
		}

		$unit = Facturae::UNIT_DEFAULT;
		$unitCode = '';
		if (!empty($line->fk_unit)) {
			$unitCode = $this->unitCode((int) $line->fk_unit);
		}
		if ($unitCode !== '' && isset(self::UNITS[$unitCode])) {
			$unit = self::UNITS[$unitCode];
		} else {
			$this->unitGuessed = true;
		}

		$props = array(
			'name' => $name,
			'quantity' => (float) $line->qty,
			'unitPriceWithoutTax' => (float) $line->subprice,
			'unitOfMeasure' => $unit,
			'sequenceNumber' => $n,
		);
		if ((float) $line->remise_percent != 0) {
			$props['discounts'] = array(array('reason' => 'Descuento', 'rate' => (float) $line->remise_percent));
		}

		$vat = (float) $line->tva_tx;
		$type = self::taxType(isset($line->vat_src_code) ? $line->vat_src_code : '');
		$validRates = $type === Facturae::TAX_IVA ? array(0.0, 4.0, 5.0, 10.0, 21.0) : array();
		if ($type === Facturae::TAX_IVA && !in_array($vat, $validRates, true)) {
			$this->lineIssues[] = array('C06', $n, 'IVA '.price2num($vat).' %');
		}
		$taxes = array();
		$surcharge = 0;
		if ((float) $line->localtax1_tx != 0 && (string) $line->localtax1_type !== '' && (int) $line->localtax1_type !== 0) {
			$surcharge = abs((float) $line->localtax1_tx);
		}
		$taxes[$type] = array('rate' => $vat, 'surcharge' => $surcharge, 'isWithheld' => false);
		if ((float) $line->localtax2_tx != 0 && (int) $line->localtax2_type !== 0) {
			$taxes[Facturae::TAX_IRPF] = array('rate' => abs((float) $line->localtax2_tx), 'surcharge' => 0, 'isWithheld' => true);
		}
		$props['taxes'] = $taxes;

		if ($vat == 0) {
			$reason = isset($line->array_options['options_fp_exempt_reason']) ? trim($line->array_options['options_fp_exempt_reason']) : '';
			if ($reason === '') {
				$reason = $defaultExempt;
			}
			if ($reason === '') {
				$this->lineIssues[] = array('C08', $n, '');
			} else {
				$props['specialTaxableEventCode'] = FacturaeItem::SPECIAL_TAXABLE_EVENT_EXEMPT;
				$props['specialTaxableEventReason'] = self::plain($reason, 2500);
			}
		}

		$item = new FacturaeItem($props);

		// Dolibarr rounds each line's total. When qty × price gives more decimals, a "Redondeo" charge or discount
		// makes the line equal Dolibarr's stored total_ht, so we never write a figure Dolibarr doesn't have.
		$data = $item->getData($fac);
		$diff = round((float) $line->total_ht - (float) $data['grossAmount'], 6);
		if (abs($diff) >= 0.000001) {
			$key = $diff > 0 ? 'charges' : 'discounts';
			$props[$key] = isset($props[$key]) ? $props[$key] : array();
			$props[$key][] = array('reason' => 'Redondeo', 'amount' => abs($diff));
			$item = new FacturaeItem($props);
		}
		return $item;
	}

	/**
	 * Dolibarr unit code of a fk_unit
	 *
	 * @param int $id fk_unit
	 * @return string
	 */
	protected function unitCode($id)
	{
		static $cache = array();
		if (!isset($cache[$id])) {
			$cache[$id] = '';
			$res = $this->db->query('SELECT code FROM '.MAIN_DB_PREFIX.'c_units WHERE rowid = '.((int) $id));
			if ($res && ($obj = $this->db->fetch_object($res))) {
				$cache[$id] = strtoupper($obj->code);
			}
		}
		return $cache[$id];
	}

	/**
	 * Corrective details of a credit note
	 *
	 * @param Facturae $fac     Document
	 * @param Facture  $invoice Credit note
	 * @return void
	 */
	protected function setCorrective($fac, $invoice)
	{
		$reasons = facturapublica_corr_reasons();
		$methods = facturapublica_corr_methods();
		$reason = isset($invoice->array_options['options_fp_corr_reason']) ? (string) $invoice->array_options['options_fp_corr_reason'] : '';
		$method = isset($invoice->array_options['options_fp_corr_method']) ? (string) $invoice->array_options['options_fp_corr_method'] : '';
		if ($method === '') {
			$method = self::defaultMethod($invoice);
		}
		$source = self::sourceInvoice($this->db, $invoice);
		list($serie, $number) = $source ? self::splitRef($source->ref) : array('', '');
		$issue = $source ? dol_print_date($source->date, '%Y-%m-%d', 'tzserver') : null;
		$fac->setCorrective(new CorrectiveDetails(array(
			'invoiceNumber' => $number,
			'invoiceSeriesCode' => $serie !== '' ? $serie : null,
			'invoiceIssueDate' => $issue,
			'reason' => $reason,
			'reasonDescription' => isset($reasons[$reason]) ? $reasons[$reason] : null,
			'taxPeriodStart' => $issue,
			'taxPeriodEnd' => $issue,
			'correctionMethod' => $method,
			'correctionMethodDescription' => isset($methods[$method]) ? $methods[$method] : null,
		)));
	}

	/**
	 * Default correction method: 01 when the credit note cancels the whole invoice, 02 otherwise
	 *
	 * @param Facture $invoice Credit note
	 * @return string
	 */
	public static function defaultMethod($invoice)
	{
		global $db;
		$source = self::sourceInvoice($db, $invoice);
		if ($source && round(abs((float) $source->total_ttc) - abs((float) $invoice->total_ttc), 2) == 0) {
			return CorrectiveDetails::METHOD_FULL;
		}
		return CorrectiveDetails::METHOD_DIFFERENCES;
	}

	/**
	 * The invoice a credit note corrects
	 *
	 * @param DoliDB  $db      Database handler
	 * @param Facture $invoice Credit note
	 * @return Facture|null
	 */
	public static function sourceInvoice($db, $invoice)
	{
		if (empty($invoice->fk_facture_source)) {
			return null;
		}
		$src = new Facture($db);
		return $src->fetch((int) $invoice->fk_facture_source) > 0 ? $src : null;
	}

	/**
	 * Payment line: Dolibarr's payment mode mapped to Facturae, transfer with the IBAN by default
	 *
	 * @param Facture $invoice Invoice
	 * @return FacturaePayment
	 */
	protected function payment($invoice)
	{
		$code = '';
		if (!empty($invoice->mode_reglement_code)) {
			$code = $invoice->mode_reglement_code;
		}
		$map = array('LIQ' => '01', 'PRE' => '02', 'CHQ' => '11', 'CB' => '19', 'VIR' => '04');
		$method = isset($map[$code]) ? $map[$code] : FacturaePayment::TYPE_TRANSFER;
		$iban = null;
		if ($method === FacturaePayment::TYPE_TRANSFER) {
			$iban = self::iban($this->db, $invoice);
		}
		$props = array('method' => $method, 'amount' => price2num($invoice->total_ttc, 'MT'));
		if (!empty($invoice->date_lim_reglement)) {
			$props['dueDate'] = dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver');
		} else {
			$props['dueDate'] = dol_print_date($invoice->date, '%Y-%m-%d', 'tzserver');
		}
		if ($iban) {
			$props['iban'] = $iban;
		}
		return new FacturaePayment($props);
	}

	/**
	 * IBAN for the invoice: its own bank account, or the company's default one
	 *
	 * @param DoliDB  $db      Database handler
	 * @param Facture $invoice Invoice
	 * @return string IBAN without spaces, or ''
	 */
	public static function iban($db, $invoice)
	{
		$id = !empty($invoice->fk_account) ? (int) $invoice->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER');
		$sql = 'SELECT iban_prefix FROM '.MAIN_DB_PREFIX.'bank_account WHERE clos = 0';
		$sql .= $id > 0 ? ' AND rowid = '.$id : ' ORDER BY rowid LIMIT 1';
		$res = $db->query($sql);
		if ($res && ($obj = $db->fetch_object($res))) {
			return strtoupper(preg_replace('/\s+/', '', (string) $obj->iban_prefix));
		}
		return '';
	}

	/**
	 * Path of the invoice's main PDF, if Dolibarr generated one
	 *
	 * @param Facture $invoice Invoice
	 * @return string
	 */
	public function pdfPath($invoice)
	{
		global $conf;
		$ref = dol_sanitizeFileName($invoice->ref);
		$dir = !empty($conf->facture->multidir_output[$invoice->entity]) ? $conf->facture->multidir_output[$invoice->entity] : $conf->facture->dir_output;
		$file = $dir.'/'.$ref.'/'.$ref.'.pdf';
		return is_file($file) ? $file : '';
	}

	/**
	 * VERI*FACTU check URL, per the setting: none, a chosen extra field, or auto (any extra field holding an AEAT check URL).
	 * The URL is only ever copied as the billing system wrote it, never built here: building the QR content is the
	 * billing system's job under RD 1007/2023, and doing it would bring the module into that scope.
	 *
	 * @param Facture $invoice Invoice
	 * @param string  $nif     Seller NIF (unused, kept for callers)
	 * @return string
	 */
	public function verifactuUrl($invoice, $nif = '')
	{
		$mode = getDolGlobalString('FACTURAPUBLICA_VF_MODE', 'auto');
		$options = is_array($invoice->array_options) ? $invoice->array_options : array();
		if ($mode === 'field') {
			$field = getDolGlobalString('FACTURAPUBLICA_VF_FIELD');
			$value = ($field !== '' && isset($options['options_'.$field])) ? trim((string) $options['options_'.$field]) : '';
			return preg_match('#^https://\S+$#i', $value) ? $value : '';
		}
		if ($mode === 'none') {
			return '';
		}
		// auto
		foreach ($options as $value) {
			if (is_string($value) && self::isAeatCheckUrl(trim($value))) {
				return trim($value);
			}
		}
		return '';
	}

	/**
	 * Whether a value is an AEAT invoice check URL (the content of a VERI*FACTU or non-VERI*FACTU QR code)
	 *
	 * @param string $value Value
	 * @return bool
	 */
	public static function isAeatCheckUrl($value)
	{
		return (bool) preg_match('#^https://(www2\.agenciatributaria\.gob\.es|prewww2\.aeat\.es)/wlpl/TIKE-CONT/ValidarQR(NoVerifactu)?\?\S+$#i', $value);
	}

	/**
	 * Compare the document's totals with Dolibarr's stored totals (check C07)
	 *
	 * @param Facturae $fac     Document
	 * @param Facture  $invoice Invoice
	 * @return array List of array(field, dolibarr, file), empty when everything matches to the cent
	 */
	public static function compareTotals($fac, $invoice)
	{
		$t = $fac->getTotals();
		$outputsVat = 0;
		$outputsSurcharge = 0;
		foreach ($t['taxesOutputs'] as $type => $byRate) {
			foreach ($byRate as $tax) {
				$outputsVat += (float) $tax['amount'];
				$outputsSurcharge += (float) $tax['surchargeAmount'];
			}
		}
		$withheld = (float) $t['totalTaxesWithheld'];
		$pairs = array(
			'FpTotalBase' => array((float) $invoice->total_ht, (float) $t['grossAmount'] + (float) $t['totalGeneralCharges'] - (float) $t['totalGeneralDiscounts']),
			'FpTotalVat' => array((float) $invoice->total_tva, $outputsVat),
			'FpTotalSurcharge' => array((float) $invoice->total_localtax1, $outputsSurcharge),
			'FpTotalWithheld' => array(abs((float) $invoice->total_localtax2), abs($withheld)),
			'FpTotalInvoice' => array((float) $invoice->total_ttc, (float) $t['invoiceAmount']),
		);
		$diffs = array();
		foreach ($pairs as $field => $p) {
			if (round($p[0], 2) != round($p[1], 2)) {
				$diffs[] = array($field, round($p[0], 2), round($p[1], 2));
			}
		}
		return $diffs;
	}

	/**
	 * Validate a Facturae XML against the bundled 3.2.2 schema
	 *
	 * @param string $xml XML text
	 * @return string[] First schema messages, empty when valid
	 */
	public static function validateXsd($xml)
	{
		$prev = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$dom = new DOMDocument();
		$msgs = array();
		if (!$dom->loadXML($xml, LIBXML_NONET)) {
			$msgs[] = 'XML';
		} elseif (!$dom->schemaValidate(__DIR__.'/../xsd/Facturaev3_2_2.xsd')) {
			foreach (libxml_get_errors() as $e) {
				$msgs[] = 'Línea '.$e->line.': '.trim($e->message);
				if (count($msgs) >= 3) {
					break;
				}
			}
			if (!$msgs) {
				$msgs[] = 'XSD';
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $msgs;
	}
}
