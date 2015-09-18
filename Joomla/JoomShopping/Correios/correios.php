<?php
/**
 * Modifies Joomshopping shipping types (PAC, SEDEX and Carta registrada) to calculate correios cost during checkout
 * - see components/com_jshopping/controllers/checkout.php for relavent events
 *
 * @copyright	Copyright (C) 2015 Aran Dunkley
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

/**
 * @package		Joomla.Plugin
 * @subpackage	System.correios
 * @since 2.5
 */
class plgSystemCorreios extends JPlugin {

	public static $cartaPrices = array();    // the table of prices per weight for carta registrada
	public static $cartaPricesMod = array(); // the table of prices per weight for carta registrada (módico)
	public static $allbooks;                 // whether the order consists only of book or not (whether carta registrada is allowed or not)
	public static $bookCats = array();       // which categories contain books

	public function onAfterInitialise() {

		// If this is a local request and carta=update get the weight/costs and cartaupdate set the config
		if( $this->isLocal() && array_key_exists( 'cartaupdate', $_REQUEST ) ) $this->updateWeightCosts();

		// Set which cats allow carta registrada from the config form
		self::$bookCats = preg_split( '/\s*,\s*/', $this->params->get( "cartaCats" ) );

		// And the Carta registrada prices
		foreach( array( 100, 150, 200, 250, 300, 350, 400, 450 ) as $d ) {
				self::$cartaPrices[$d] = str_replace( ',', '.', $this->params->get( "carta$d" ) );
				self::$cartaPricesMod[$d] = str_replace( ',', '.', $this->params->get( "cartam$d" ) );
		}

		// Install our extended shipping type if not already there
		// (should be done from onExtensionAfterInstall but can't get it to be called)
		// (or better, should be done from the xml with install/uninstall element, but couldn't get that to work either)
		$db = JFactory::getDbo();
		$tbl = '#__jshopping_shipping_ext_calc';
		$db->setQuery( "SELECT 1 FROM `$tbl` WHERE `name`='Correios'" );
		$row = $db->loadRow();
		if( !$row ) {

			// Add the shipping type extension
			$query = "INSERT INTO `$tbl` "
				. "(`name`, `alias`, `description`, `params`, `shipping_method`, `published`, `ordering`) "
				. "VALUES( 'Correios', 'sm_correios', 'Correios', '', '', 1, 1 )";
			$db->setQuery( $query );
			$db->query();

			// Add our freight cost cache table
			$tbl = '#__correios_cache';
			$query = "CREATE TABLE IF NOT EXISTS `$tbl` (
				id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
				cep    INT UNSIGNED NOT NULL,
				weight INT UNSIGNED NOT NULL,
				time   INT UNSIGNED NOT NULL,
				pac    DECIMAL(5,2) NOT NULL,
				sedex  DECIMAL(5,2) NOT NULL,
				PRIMARY KEY (id)
			)";
			$db->setQuery( $query );
			$db->query();

			// Copy the sm_ligmincha_freight class into the proper place
			// (there's probably a proper way to do this from the xml file)
			$path = JPATH_ROOT . '/components/com_jshopping/shippings/sm_correios';
			$file = 'sm_correios.php';
			if( !is_dir( $path ) ) mkdir( $path );
			copy( __DIR__ . "/$file", "$path/$file" );
		}

	}

	/**
	 * Called on removal of the extension
	 */
	public function onExtensionAfterUnInstall() {

		// Remove our extended shipping type
		$db = JFactory::getDbo();
		$tbl = '#__jshopping_shipping_ext_calc';
		$db->setQuery( "DELETE FROM `$tbl` WHERE `name`='Correios'" );
		$db->query();

		// Remove our freight cost cache table
		$tbl = '#__correios_cache';
		$db->setQuery( "DROP TABLE IF EXISTS `$tbl`" );
		$db->query();

		// Remove the script
		$path = JPATH_ROOT . '/components/com_jshopping/shippings/sm_correios';
		$file = 'sm_correios.php';
		if( file_exists( "$path/$file" ) ) unlink( "$path/$file" );
		if( is_dir( $path ) ) rmdir( $path );
	}

	/**
	 * If the order is not all books, remove the Carta registrada options
	 * (the $allbooks settings is updated in checkout by sm_correios class)
	 */
	public function onBeforeDisplayCheckoutStep4View( &$view ) {
		if( !self::$allbooks ) {
			$tmp = array();
			for( $i = 0; $i < count( $view->shipping_methods ); $i++ ) {
				if( !preg_match( '|carta\s*registrada|i', $view->shipping_methods[$i]->name ) ) {
					$tmp[] = $view->shipping_methods[$i];
				}
			}
			$view->shipping_methods = $tmp;
		}
	}

	/**
	 * Change the CSS of the email to inline as many webmail services like gmail ignore style tags
	 * - this is done with François-Marie de Jouvencel's class from https://github.com/djfm/cssin
	 */
	private function inlineStyles( $mailer ) {
		require_once( JPATH_ROOT . '/plugins/system/correios/cssin/src/CSSIN.php' );
		$cssin = new FM\CSSIN();
		$inline = $cssin->inlineCSS( 'http://' . $_SERVER['HTTP_HOST'], $mailer->Body );
		$inline = preg_replace( '|line-height\s*:\s*100%;|', 'line-height:125%', $inline ); // a hack to increase line spacing a bit
		$mailer->Body = $inline;
	}

	/**
	 * Return the shipping method name given it's id
	 */
	public static function getShippingMethodName( $id ) {
		$type = JSFactory::getTable( 'shippingMethod', 'jshop' );
		$type->load( $id );
		return $type->getProperties()['name_pt-BR'];
	}

	/**
	 * Return weights if all books
	 */
	public static function getOrderWeights( $order ) {
		$weights = array();
		foreach( $order->products as $item ) {
			for( $i = 0; $i < $item['product_quantity']; $i++ ) $weights[] = $item['weight'];
		}
		return $weights;
	}

	/**
	 * Given a list of product weights for products that are all allowed Carta Registrada,
	 * rearrange them into as few packages as possible that are all 500g max
	 */
	public static function optimiseWeights( $weights, $detailed = false ) {
		$wtmp = array();                        // The optimiased packages weights
		$pkg = array();                         // Descriptive list of weights for each package (not used yet)
		rsort( $weights );
		while( count( $weights ) > 0 ) {        // If any products left, start new package
			$pkg[] = array( $weights[0] );
			$wtmp[] = array_shift( $weights );  // New package starts with heaviest remaining product
			$ltmp = count( $wtmp ) - 1;         // Index of new package item
			$cw = count( $weights );            // Number of products remaining
			while( $cw > 0 && $weights[$cw - 1] + $wtmp[$ltmp] <= 500 ) { // Keep adding remaining products until none left or over 500g
				$pkg[count( $pkg ) - 1][] = $weights[$cw - 1];
				$wtmp[$ltmp] += array_pop( $weights );
				$cw = count( $weights );
			}
		}
		return $detailed ? $pkg : $wtmp;
	}

	/**
	 * Add a manifest for carta registrada orders that have more than one package
	 */
	private function addManifest( $order, $mailer ) {

		// If we've already rendered the manifest (or determined that there isn't one) for this order just use that
		static $html = false;
		if( $html !== false ) {
			$html = '';

			// Only have manifest for Carta Registrada shipping types
			$type = self::getShippingMethodName( $order->shipping_method_id );
			if( !preg_match( '/carta\s*registrada/i', $type ) ) return;

			// Optimise the order's weights into packages
			$packages = self::optimiseWeights( getOrderWeights( $order ), true );

			// Only have manifest if more than one package
			if( count( $packages ) < 2 ) return;

			// Create an array of products-to-process that we can tick off
			$products = array()
			foreach( $order->products as $item ) {
				for( $i = 0; $i < $item['product_quantity']; $i++ ) {
					$title = $item['product_name'];
					if( array_key_exists( $title, $products ) ) $products[$title][0]++;
					else $products[$title] = array( 1, $item['weight'] );
				}
			}

			// Loop through all packages
			$manifest = array();
			foreach( $packages as $i => $weights ) {

				// Loop through the weights,
				foreach( $weights as $weight ) {

					// Find a product of the same weight
					foreach( $products as $title => $item ) {
						if( $weight == $item[1] ) {

							// Remove one of these from the products-to-process list
							$products[$title][0]--;

							// Add the product to the manifest
							if( array_key_exists( $title, $manifest[$i] ) ) $manifest[$i][$title][0]++;
							else $manifest[$i][$title] = array( 1, $weight );

							// Found a matching product, so leave loop
							break;
						}
					}
				}
			}

			// Render the manifest
			$html = "<br>This order contains more than one package.<br>";
			foreach( $manifest as $i => $package ) {
				$html .= "<table><tr><th colspan=\"4\">Package $i</th></tr>\n";
				$html .= "<tr><th>Product</th><th>Unit weight</th><th>Qty</th><th>Total</th></tr>\n";
				$grand = 0;
				foreach( $package as $title => $item ) {
					$qty = $item[0];
					$weight = $item[1] * 1000;
					$total = $weight * $qty;
					$grand += $total;
					$html .= "<tr><td>$title</td><td>{$weight}g</td><td>$qty</td><td>{$total}g</td></tr>\n";
				}
				$html .= "<tr><td colspan=\"4\">Total package weight: {$grand}g</td></tr>\n";
				$html .= "</table><br>\n";
			}
		}

		// Add the table to the end of the message
		$mailer->Body .= $html;
	}

	/**
	 * Set the order mailout events to call our inline method
	 */
	public function onBeforeSendOrderEmailClient( $mailer, $order, &$manuallysend, &$pdfsend ) {
		$this->addManifest( $order, $mailer );
		$this->inlineStyles( $mailer );
	}
	public function onBeforeSendOrderEmailAdmin( $mailer, $order, &$manuallysend, &$pdfsend ) {
		$this->addManifest( $order, $mailer );
		$this->inlineStyles( $mailer );
	}
	public function onBeforeSendOrderEmailVendor( $mailer, $order, &$manuallysend, &$pdfsend, &$vendor, &$vendors_send_message, &$vendor_send_order ) {
		$this->addManifest( $order, $mailer );
		$this->inlineStyles( $mailer );
	}

	/**
	 * Return whether request not from a local IP address
	 */
	private function isLocal() {
		if( preg_match_all( "|inet6? addr:\s*([0-9a-f.:]+)|", `/sbin/ifconfig`, $matches ) && !in_array( $_SERVER['REMOTE_ADDR'], $matches[1] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get the weight costs from the Correios site and update the config data
	 */
	private function updateWeightCosts() {
		$info = $info2 = $err = '';
		$correios = 'http://www.correios.com.br/para-voce/consultas-e-solicitacoes/precos-e-prazos';

		// Get the tracking costs for Nacional and Módico
		$tracking = file_get_contents( "$correios/servicos-adicionais-nacionais" );
		if( preg_match( '|<table class="conteudo-tabela">.+?<td>Registro Nacional.+?([1-9][0-9.,]+).+?<td>Registro Módico.+?([1-9][0-9.,]+)|is', $tracking, $m ) ) {
			$tracking = str_replace( ',', '.', $m[2] );

			// Get the weight/costs table (starting at the 100-150 gram entry)
			$weights = file_get_contents( "$correios/servicos-nacionais_pasta/carta" );
			if( preg_match( '|Carta não Comercial.+?Mais de 100 até 150</td>\s*(.+?)<tr class="rodape-tabela">|si', $weights, $m ) ) {
				if( preg_match_all( '|<td>([0-9,]+)</td>\s*<td>([0-9,]+)</td>\s*<td>[0-9,]+</td>\s*<td>[0-9,]+</td>\s*<td>[0-9,]+</td>\s*|is', $m[1], $n ) ) {

					// Update the plugin's parameters with the formatted results
					foreach( $n[1] as $i => $v ) {

						// Get the index into the price config in 50 gram divisions
						$d = 100 + 50 * $i;

						// Set the Módico price checking for changes
						$n[1][$i] = number_format( (float)(str_replace( ',', '.', $n[1][$i] ) + $tracking), 2, ',', '' );
						$k = "cartam$d";
						$v = $n[1][$i];
						$o = $this->params->get( $k );
						if( $v != $o ) {
							$this->params->set( $k, $v );
							$info .= "Registro Módico price for $d-" . ($d + 50) . "g changed from $o to $v\n";
						}

						// Set the Nacional price checking for changes
						$k = "carta$d";
						$v = $n[2][$i];
						$o = $this->params->get( $k );
						if( $v != $o ) {
							$this->params->set( $k, $v );
							$info2 .= "Registro Nacional price for $d-" . ($d + 50) . "g changed from $o to $v\n";
						}
					}

					// If changes, write them to the plugin's parameters field in the extensions table
					if( $info || $info2 ) {
						$params = (string)$this->params;
						$db = JFactory::getDbo();
						$db->setQuery( "UPDATE `#__extensions` SET `params`='$params' WHERE `name`='plg_system_correios'" );
						$db->query();
					}
				} else $err .= "ERROR: Found weight/cost table but couldn't extract the data.\n";
			} else $err .= "ERROR: Couldn't find weight/cost table.\n";
		} else $err .= "ERROR: Couldn't retrieve tracking prices.\n";

		// If any info, email it
		$info .= $info2 . $err;
		$config = JFactory::getConfig();
		$from = $config->get( 'mailfrom' );
		if( !$to = $config->get( 'webmaster' ) ) $to = $from;
		$mailer = JFactory::getMailer();
		$mailer->addRecipient( $to );
		if( !$err && $to != $from ) $mailer->addRecipient( $from );
		$mailer->setSubject( 'Notification from Correios extension' );
		$mailer->setBody( $info );
		$mailer->isHTML( false );
		$send = $mailer->Send();
	}
}
