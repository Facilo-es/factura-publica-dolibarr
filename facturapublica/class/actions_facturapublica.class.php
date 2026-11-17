<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_facturapublica.class.php
 * \ingroup facturapublica
 * \brief   Hooks: the "Factura Pública" box on the invoice card, and the bulk send in the invoice list
 */

require_once dirname(__DIR__).'/lib/facturapublica.lib.php';
require_once dirname(__DIR__).'/class/FpCheck.class.php';
require_once dirname(__DIR__).'/class/FpStore.class.php';
require_once dirname(__DIR__).'/class/FpDir3.class.php';

/**
 * Hook class
 */
class ActionsFacturapublica
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * True when the request carries this page's CSRF token
	 *
	 * @return bool
	 */
	protected static function tokenOk()
	{
		return facturapublica_token_ok();
	}

	/**
	 * Invoice card URL
	 *
	 * @param Facture $object Invoice
	 * @param string  $more   Query string to add
	 * @return string
	 */
	protected static function cardUrl($object, $more = '')
	{
		return DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $object->id).$more;
	}

	/**
	 * Redirect back to the invoice card and stop
	 *
	 * @param Facture $object Invoice
	 * @return void
	 */
	protected static function back($object)
	{
		if (defined('FACTURAPUBLICA_TEST')) {
			return;
		}
		header('Location: '.self::cardUrl($object));
		exit;
	}

	/**
	 * Actions on the invoice card and the invoice list
	 *
	 * @param array        $parameters Hook parameters
	 * @param CommonObject $object     Invoice
	 * @param string       $action     Action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int 0 to let Dolibarr go on, <0 on error
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) || strpos((string) $action, 'fp_') !== 0) {
			return 0;
		}
		$langs->load('facturapublica@facturapublica');
		if (empty($object->id) || !$user->hasRight('facturapublica', 'read')) {
			return 0;
		}
		// Showing a confirmation needs no token. Everything else does.
		if (in_array($action, array('fp_send', 'fp_cancel', 'fp_resend'), true)) {
			return 0;
		}
		if (!self::tokenOk()) {
			setEventMessages($langs->trans('ErrorBadToken'), null, 'errors');
			$action = '';
			return 0;
		}
		if (empty($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		$done = $action;
		$action = '';

		if ($done === 'fp_xml') {
			$check = new FpCheck($this->db);
			$check->run($object, false);
			if ($check->xml === '' || $check->schemaErrors) {
				setEventMessages($langs->trans('FpSchemaError'), null, 'errors');
				return 0;
			}
			FpStore::saveFile($object, $check->xml, false);
			if (defined('FACTURAPUBLICA_TEST')) {
				$this->results['xml'] = $check->xml;
				return 0;
			}
			header('Content-Type: application/xml; charset=UTF-8');
			header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName($object->ref).'-facturae.xml"');
			print $check->xml;
			exit;
		}

		if ($done === 'fp_dir3save') {
			if (!$user->hasRight('facturapublica', 'send') && !$user->hasRight('societe', 'creer')) {
				setEventMessages($langs->trans('FpNoRightSend'), null, 'errors');
				return 0;
			}
			$row = FpStore::getPublic($this->db, $object->thirdparty->id);
			$bad = array();
			foreach (array('dir3_oc' => 'FpOc', 'dir3_og' => 'FpOg', 'dir3_ut' => 'FpUt') as $k => $label) {
				$row[$k] = facturapublica_dir3_clean(GETPOST($k, 'alphanohtml'));
				if (!facturapublica_dir3_valid($row[$k])) {
					$bad[] = $langs->trans($label);
				}
			}
			if ($bad) {
				setEventMessages($langs->trans('FpDir3Format').' ('.implode(', ', $bad).')', null, 'errors');
				return 0;
			}
			$row['is_public'] = 1;
			$row['dir3_op'] = (string) $row['dir3_op'];
			$row['notify_email'] = (string) $row['notify_email'];
			FpStore::savePublic($this->db, $object->thirdparty->id, $row);
			setEventMessages($langs->trans('FpSaved'), null);
			self::back($object);
			return 0;
		}

		return 0;
	}


	/**
	 * The "Factura Pública" box. Printed with the action buttons, then moved above them.
	 *
	 * @param array        $parameters Hook parameters
	 * @param CommonObject $object     Invoice
	 * @param string       $action     Action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) || empty($object->id) || !$user->hasRight('facturapublica', 'read')) {
			return 0;
		}
		if ($action !== '' && strpos((string) $action, 'fp_') !== 0) {
			return 0;
		}
		$langs->load('facturapublica@facturapublica');
		print $this->box($object);
		return 0;
	}

	/**
	 * Step strip
	 *
	 * @param int  $now  Index of the current step (5 = all done)
	 * @param bool $bad  The current step failed
	 * @return string
	 */
	protected static function steps($now, $bad = false)
	{
		global $langs;
		$out = '<div class="fpc-steps">';
		foreach (array('FpStepReview', 'FpStepFacturae', 'FpStepSign', 'FpStepFace', 'FpStepPaid') as $i => $k) {
			$cls = $i < $now ? 'done' : ($i === $now ? ($bad ? 'bad' : 'now') : '');
			$out .= '<span'.($cls ? ' class="'.$cls.'"' : '').'><i>'.($i < $now ? '✓' : ($i + 1)).'</i>'.$langs->trans($k).'</span>';
		}
		return $out.'</div>';
	}

	/**
	 * Box HTML for one invoice
	 *
	 * @param Facture $object Invoice
	 * @return string
	 */
	public function box($object)
	{
		global $user, $langs;
		if (empty($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		$full = facturapublica_is_full();
		$canSend = $full && $user->hasRight('facturapublica', 'send');
		$public = FpStore::getPublic($this->db, $object->thirdparty->id);
		$s = $full ? FpStore::latestSending($this->db, $object->id) : null;
		$token = newToken();
		$signedUrl = '';
		if ($s && !empty($s['file_path'])) {
			$signedUrl = DOL_URL_ROOT.'/document.php?modulepart=facture&file='.urlencode(dol_sanitizeFileName($object->ref).'/'.$s['file_path']);
		}
		$btnSigned = $signedUrl ? '<a class="fpc-sec" href="'.dol_escape_htmltag($signedUrl).'">'.$langs->trans('FpDownloadSigned').'</a>' : '';
		$btnXml = '<a class="fpc-sec" href="'.self::cardUrl($object, '&action=fp_xml&token='.$token).'">'.$langs->trans('FpDownloadXml').'</a>';
		$finder = '';

		if (empty($public['is_public']) && !$s) {
			$now = 0;
			$body = '<div class="fpc-item w"><span>'.$langs->trans('FpNotPublic').'</span><a class="fpc-fix" href="'.dol_buildpath('/facturapublica/thirdparty_tab.php', 1).'?socid='.((int) $object->thirdparty->id).'">'.$langs->trans('FpConfigureIt').'</a></div>';
			$bad = false;
		} elseif ((int) $object->status === Facture::STATUS_DRAFT) {
			$now = 0;
			$bad = false;
			$body = '<p class="fpc-note">'.$langs->trans('FpNotValidatedYet').'</p>';
		} else {
			list($now, $bad, $body, $finder) = $this->boxCheck($object, $s, $public, $full, $canSend, $btnXml);
		}

		$out = '<section class="fpc" id="fpc-box"><div class="fpc-h">';
		$out .= '<img src="'.dol_buildpath('/facturapublica/img/object_facturapublica.png', 1).'" alt="" width="22" height="22">';
		$out .= '<b>'.$langs->trans('FpBoxTitle').'</b>'.self::steps($now, $bad).'</div>';
		$out .= '<div class="fpc-b">'.facturapublica_top_bars().$body.'</div></section>';
		$out .= $finder;
		// Above Dolibarr's buttons, where the design puts it. Without JavaScript it simply stays here.
		$out .= '<script>jQuery(function($){var b=$("#fpc-box"),t=b.closest(".tabsAction");if(t.length){b.insertBefore(t);}});</script>';
		return $out;
	}

	/**
	 * Not sent yet: problems, or ready to send
	 *
	 * @param Facture    $object  Invoice
	 * @param array|null $s       Last send attempt (failed), if any
	 * @param array      $public  Public-body row
	 * @param bool       $full    Paid edition
	 * @param bool       $canSend User may send
	 * @param string     $btnXml  Download button
	 * @return array now, bad, html, finder html
	 */
	protected function boxCheck($object, $s, $public, $full, $canSend, $btnXml)
	{
		global $langs;
		$check = new FpCheck($this->db);
		$check->run($object, $full);
		$blocks = $warns = 0;
		$items = '';
		$finder = '';
		foreach ($check->problems as $p) {
			$p['blocking'] ? $blocks++ : $warns++;
			$fix = '';
			if ($p['url'] !== '' || $p['code'] === 'C02') {
				$attr = '';
				if ($p['code'] === 'C02' && !empty($public['is_public'])) {
					$attr = ' data-fp-finder="1"';
					$finder = facturapublica_finder_panel($object->thirdparty->name, self::cardUrl($object), 'fp_dir3save', $public);
				}
				$fix = '<a class="fpc-fix" href="'.dol_escape_htmltag($p['url']).'"'.$attr.'>'.$langs->trans($p['linklabel'] ?: 'FpFix').'</a>';
			}
			$items .= '<div class="fpc-item '.($p['blocking'] ? 'x' : 'w').'"><span>'.$p['text'].'</span>'.$fix.'</div>';
			if ($p['code'] === 'C07' && $check->schemaErrors) {
				$details = implode("\n", array_slice($check->schemaErrors, 0, 3));
				$items .= '<pre class="fpc-note" id="fp-schema">'.dol_escape_htmltag($details, 0, 1).'</pre><a class="fpc-sec" href="#" data-fp-copy="#fp-schema" data-fp-done="'.dol_escape_htmltag($langs->trans('FpCopied')).'">'.$langs->trans('FpCopySupport').'</a>';
			}
		}
		$last = '';
		if ($s && !empty($s['last_error'])) {
			$last = '<p class="fpc-why">'.$langs->transnoentities('FpLastAttempt', dol_escape_htmltag($s['last_error'])).'</p>';
		}

		if ($blocks) {
			$html = '<div class="fpc-top"><h3>'.$langs->trans('FpBeforeSend').'</h3><div class="fpc-count"><span class="x">'.$langs->trans('FpBlockers', $blocks).'</span>';
			$html .= $warns ? '<span class="w">'.$langs->trans('FpWarnings', $warns).'</span>' : '';
			$html .= '</div></div>'.$last.$items;
			$html .= '<div class="fpc-acts">'.($full ? '<span class="fpc-go off" title="'.dol_escape_htmltag($langs->trans('FpSendOff')).'">'.$langs->trans('FpSend').'</span>' : '');
			$html .= ($check->xml !== '' ? $btnXml : '').'</div>';
			if ($full) {
				$html .= '<p class="fpc-note">'.$langs->trans('FpSendOff').'</p>';
			}
			return array(0, true, $html.($full ? '' : facturapublica_lite_lock()), $finder);
		}

		$html = '<p class="fpc-msg">'.$langs->transnoentities('FpReady', '<b>'.dol_escape_htmltag($object->thirdparty->name).'</b>').'</p>'.$last.$items;
		$html .= '<div class="fpc-acts">';
		if ($canSend) {
			$html .= '<a class="fpc-go" href="'.self::cardUrl($object, '&action=fp_send&token='.newToken()).'">'.$langs->trans('FpSend').'</a>';
		} elseif ($full) {
			$html .= '<span class="fpc-go off" title="'.dol_escape_htmltag($langs->trans('FpNoRightSend')).'">'.$langs->trans('FpSend').'</span>';
		}
		$html .= $btnXml.'</div>';
		if ($full && !$canSend) {
			$html .= '<p class="fpc-note">'.$langs->trans('FpNoRightSend').'</p>';
		}
		if (!$full) {
			$html .= facturapublica_lite_lock();
		}
		return array($full ? 3 : 2, false, $html, $finder);
	}

}
