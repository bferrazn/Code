<!DOCTYPE html>
<html lang="pt-BR" dir="ltr" class="client-nojs">
<head>
<meta charset="UTF-8" />
<title>Doação</title>
</head>
<body>
<?php
/**
 * This script allows donations to be made to PagSeguro with only the amount needing to be specified
 */

// Get the donation amount and format or die if not supplied
$amount = array_key_exists( 'amount', $_REQUEST ) && is_numeric( $_REQUEST['amount'] )
	? number_format( $_REQUEST['amount'], 2, '.', '' )
	: die( 'Por favor fornecer o valor de doação' );

// Set URI to the loja so that the jshopping component is loaded
$_SERVER['REQUEST_URI'] = '/carrinho-menu';

// Load the Joomla framework
define('_JEXEC', 1);
define('DS', DIRECTORY_SEPARATOR);
define('JPATH_BASE', preg_replace( '|/components/.+$|', '', __DIR__ ));
require_once JPATH_BASE.'/includes/defines.php';
require_once JPATH_BASE.'/includes/framework.php';
$app = JFactory::getApplication('site');
$config = JFactory::getConfig();
$app->initialise();
$app->route();
$app->dispatch();

// Get the PagSeguro payment type ID
$db = JFactory::getDbo();
$db->setQuery( "SELECT `payment_id` FROM `#__jshopping_payment_method` WHERE `scriptname`='pm_pagseguro'" );
if( $row = $db->loadRow() ) {

	// Get the PagSeguro account email and API token
	$pm_method = JSFactory::getTable( 'paymentMethod', 'jshop' );
	$pm_method->load( $row[0] );
	$pmconfigs = $pm_method->getConfigs();
	$sandbox = $pmconfigs['testmode'] ? 'sandbox.' : '';
	$email = $pmconfigs['email_received'];
	$token = $pmconfigs[$sandbox ? 'test_token' : 'token'];

	// Build data to post to the PagSeguro server
	$data = array(
			'email' => $email,
			'token' => $token,
			'senderName' => $config->get( 'sitename' ),
			'currency' => 'BRL',
			'redirectURL' => array_key_exists( 'return', $_REQUEST ) ? $_REQUEST['return'] : $_SERVER['HTTP_REFERER'],
			'reference' => 0,
			'itemId1' => 0,
			'itemDescription1' => 'Doacao', // Can't get pt chrs to work here :-(
			'itemAmount1' => $amount,
			'itemQuantity1' => 1
	);

	// Post the order data to PagSeguro
	$options = array(
		CURLOPT_POST => 1,
		CURLOPT_HEADER => 0,
		CURLOPT_URL => "https://ws.{$sandbox}pagseguro.uol.com.br/v2/checkout/",
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_TIMEOUT => 4,
		CURLOPT_POSTFIELDS => http_build_query( $data )
	);
	$ch = curl_init();
	curl_setopt_array( $ch, $options );
	if( !$result = curl_exec( $ch ) ) die( 'Error: ' . curl_error( $ch ) );
	curl_close( $ch );

	// If we received a code, redirect the client to PagSeguro to complete the order
	$code = preg_match( '|<code>(.+?)</code>|', $result, $m ) ? $m[1] : false;
	if( $code && !is_numeric( $code ) ) {
		header( "Location: https://{$sandbox}pagseguro.uol.com.br/v2/checkout/payment.html?code=$code" );
	} else die( "Error: $result" );
}
?>
</body>
</html>
