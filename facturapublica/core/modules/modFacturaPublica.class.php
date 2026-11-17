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
 * \defgroup facturapublica Module Factura Pública
 * \file     facturapublica/core/modules/modFacturaPublica.class.php
 * \ingroup  facturapublica
 * \brief    Module descriptor for Factura Pública
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descriptor of module Factura Pública
 */
class modFacturaPublica extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Placeholder until the ID range is reserved on the Dolibarr wiki (authors' range 95000-99999)
		$this->numero = 210217;
		$this->rights_class = 'facturapublica';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'FacturaPublicaDescription';
		$this->descriptionlong = 'FacturaPublicaDescription';
		$this->editor_name = 'Facilo';
		$this->editor_url = 'https://facilo.es';
		$this->version = '1.0.0';
		$this->url_last_version = 'https://facturapublica.facilo.es/version.txt';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'facturapublica@facturapublica';

		$this->module_parts = array(
			'triggers' => 0,
			'css' => array('/facturapublica/css/facturapublica.css'),
			'js' => array('/facturapublica/js/facturapublica.js'),
			'hooks' => array(
				'data' => array('invoicecard', 'invoicelist'),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/facturapublica/temp');
		$this->config_page_url = array('setup.php@facturapublica');
		$this->hidden = false;
		$this->depends = array('modFacture', 'modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('facturapublica@facturapublica');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			1 => array('FACTURAPUBLICA_ENV', 'chaine', 'prod', 'FACe environment: prod or staging', 0, 'current', 0),
			2 => array('FACTURAPUBLICA_ATTACH_PDF', 'chaine', '1', 'Attach the invoice PDF inside the Facturae file', 0, 'current', 0),
			3 => array('FACTURAPUBLICA_VF_MODE', 'chaine', 'auto', 'VERI*FACTU check URL: auto, field or none', 0, 'current', 0),
			4 => array('FACTURAPUBLICA_VF_PLACE', 'chaine', 'additional', 'Where the VERI*FACTU URL goes in the file', 0, 'current', 0),
		);

		if (!isModEnabled('facturapublica')) {
			$conf->facturapublica = new stdClass();
			$conf->facturapublica->enabled = 0;
		}

		$this->tabs = array(
			array('data' => 'thirdparty:+facturapublica:FpTabName:facturapublica@facturapublica:$user->hasRight("facturapublica", "read"):/facturapublica/thirdparty_tab.php?socid=__ID__'),
		);

		$this->dictionaries = array();

		// The dashboard box shows sent invoices, so Lite (no sending) has none
		$this->boxes = array();
		if (is_file(__DIR__.'/../boxes/box_facturapublica.php')) {
			$this->boxes[0] = array('file' => 'box_facturapublica.php@facturapublica', 'note' => 'FpWidgetTitle', 'enabledbydefaulton' => 'Home');
		}

		$this->cronjobs = array();
		if (is_file(__DIR__.'/../../class/FpCron.class.php')) {
			$this->cronjobs = array(
				0 => array(
					'label' => 'FpCronDaily', 'jobtype' => 'method', 'class' => '/facturapublica/class/FpCron.class.php',
					'objectname' => 'FpCron', 'method' => 'runDaily', 'parameters' => '', 'comment' => 'FpCronDailyHelp',
					'frequency' => 1, 'unitfrequency' => 86400, 'status' => 1, 'test' => 'isModEnabled("facturapublica")', 'priority' => 50,
				),
				1 => array(
					'label' => 'FpCronWeekly', 'jobtype' => 'method', 'class' => '/facturapublica/class/FpCron.class.php',
					'objectname' => 'FpCron', 'method' => 'runWeekly', 'parameters' => '', 'comment' => 'FpCronWeeklyHelp',
					'frequency' => 7, 'unitfrequency' => 86400, 'status' => 1, 'test' => 'isModEnabled("facturapublica")', 'priority' => 50,
				),
			);
		}

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'FpRightRead';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'FpRightSend';
		$this->rights[$r][4] = 'send';
		$r++;
		$this->rights[$r][0] = $this->numero.'03';
		$this->rights[$r][1] = 'FpRightSetup';
		$this->rights[$r][4] = 'setup';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'FpMenuSummary',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'facturapublica',
			'url' => '/facturapublica/summary.php',
			'langs' => 'facturapublica@facturapublica',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("facturapublica") && is_file(dol_buildpath("/facturapublica/summary.php", 0))',
			'perms' => '$user->hasRight("facturapublica", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Called when the module is enabled: tables, constants, rights, menus, extra fields
	 *
	 * @param string $options Options when enabling the module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		$result = $this->_load_tables('/facturapublica/sql/');
		if ($result < 0) {
			return -1;
		}

		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$langs->load('facturapublica@facturapublica');
		$reasons = array();
		dol_include_once('/facturapublica/lib/facturapublica.lib.php');
		foreach (facturapublica_corr_reasons() as $code => $label) {
			$reasons[$code] = $code.' '.$label;
		}
		$methods = array();
		foreach (facturapublica_corr_methods() as $code => $label) {
			$methods[$code] = $code.' '.$label;
		}
		// Extra fields live in Dolibarr's own *_extrafields tables, never in llx_facture itself
		$extrafields->addExtraField('fp_file_ref', 'FpFileRef', 'varchar', 1010, '64', 'facture', 0, 0, '', '', 1, '', '1', '', '', '', 'facturapublica@facturapublica', 'isModEnabled("facturapublica")');
		$extrafields->addExtraField('fp_contract_ref', 'FpContractRef', 'varchar', 1011, '64', 'facture', 0, 0, '', '', 1, '', '1', '', '', '', 'facturapublica@facturapublica', 'isModEnabled("facturapublica")');
		$extrafields->addExtraField('fp_corr_reason', 'FpCorrReason', 'select', 1012, '', 'facture', 0, 0, '', array('options' => $reasons), 1, '', '1', '', '', '', 'facturapublica@facturapublica', 'isModEnabled("facturapublica")');
		$extrafields->addExtraField('fp_corr_method', 'FpCorrMethod', 'select', 1013, '', 'facture', 0, 0, '', array('options' => $methods), 1, '', '1', '', '', '', 'facturapublica@facturapublica', 'isModEnabled("facturapublica")');
		$extrafields->addExtraField('fp_exempt_reason', 'FpExemptReason', 'varchar', 1014, '255', 'facturedet', 0, 0, '', '', 1, '', '1', '', '', '', 'facturapublica@facturapublica', 'isModEnabled("facturapublica")');

		$this->remove($options);

		dol_include_once('/facturapublica/lib/facturapublica.lib.php');
		facturapublica_migrate_private_dir();

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Called when the module is disabled. Tables and data are kept, as Dolibarr does.
	 *
	 * @param string $options Options when disabling the module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		// Deleting the certificate on disable is the user's choice in Options; init() calls remove() too, so only on a real disable
		if ($options === '' && getDolGlobalString('FACTURAPUBLICA_UNINSTALL_CERT') && GETPOST('action', 'aZ09') !== 'reset_init') {
			dol_include_once('/facturapublica/class/FpSigner.class.php');
			if (class_exists('FpSigner')) {
				FpSigner::forget($this->db);
			}
		}
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
