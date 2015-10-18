<?php
/**
 * This class encapsulates all the distributed database functionality for the LigminchaGlobal extension
 */

define( 'LG_SUCCESS', 'ok' );
define( 'LG_ERROR', 'error' );

// If we're in stand-alone mode, make a fake version of the Joomla plugin class to allow the distributed.php and object classes to work
if( LG_STANDALONE ) {
	require_once( __DIR__ . '/FakeJoomla.php' );
}

// Load the Fake MediaWiki environment and the OD Websocket class from the MediaWiki extension
require_once( __DIR__ . '/FakeMediaWiki.php' );
require_once( __DIR__ . '/WebSocket/WebSocket.php' );
WebSocket::$log = '/var/www/extensions/MediaWiki/WebSocket/ws.log'; // tmp to match my current daemon
WebSocket::$rewrite = true;
WebSocket::setup();

class LigminchaGlobalDistributed {

	// Make singleton available if we need it
	public static $instance;

	// The query-string command for routing changes
	private static $cmd = 'sync';

	// The queue of changes to route at the end of the request
	private static $queue = array();

	// Our distributed data table
	public static $table = 'ligmincha_global';

	// Table structure
	public static $tableStruct = array(
		'id'       => 'BINARY(20) NOT NULL',
		'ref1'     => 'BINARY(20)',
		'ref2'     => 'BINARY(20)',
		'type'     => 'INT UNSIGNED NOT NULL',
		'creation' => 'DOUBLE',
		'modified' => 'DOUBLE',
		'expire'   => 'DOUBLE',
		'flags'    => 'INT UNSIGNED',
		'owner'    => 'BINARY(20)',
		'group'    => 'TEXT',
		'tag'      => 'TEXT',
		'data'     => 'TEXT',
	);

	function __construct() {

		// Make singleton available if we need it
		self::$instance = $this;

		// Check that the local distributed database table exists and has a matching structure
		$this->checkTable();

		// Delete any objects that have reached their expiry time
		$this->expire();

		// Instantiate the main global objects
		$server = LigminchaGlobalServer::getCurrent();
		if( !LG_STANDALONE ) {
			LigminchaGlobalUser::getCurrent();
			LigminchaGlobalSession::getCurrent();
		}

		// If this is a changes request commit the data (and re-route if master)
		// - if the changes data is empty, then it's a request for initial table data
		if( array_key_exists( self::$cmd, $_POST ) ) {
			$data = $_POST[self::$cmd];
			if( $data ) print self::recvQueue( $_POST[self::$cmd] );
			elseif( $server->isMaster ) print self::encodeData( $this->initialTableData() );
			exit;
		}
	}

	/**
	 * Return table name formatted for use in a query
	 */
	public static function sqlTable() {
		return '`#__' . self::$table . '`';
	}

	/**
	 * Check if the passed table exists (accounting for current table-prefix)
	 */
	private function tableExists( $table ) {
		$config = JFactory::getConfig();
		$prefix = $config->get( 'dbprefix' );
		$db = JFactory::getDbo();
		$db->setQuery( "SHOW TABLES" );
		$db->query();
		return array_key_exists( $prefix . $table, $db->loadRowList( 0 ) );
	}

	/**
	 * Create the distributed database table and request initial sync data to populate it with
	 */
	private function createTable() {
		$db = JFactory::getDbo();
		$table = self::sqlTable();
		$def = array();
		foreach( self::$tableStruct as $field => $type ) $def[] = "`$field` $type";
		$query = "CREATE TABLE $table (" . implode( ',', $def ) . ",PRIMARY KEY (id))";
		$db->setQuery( $query );
		$db->query();

		// TODO: Create an LG_DATABASE object to represent this new node in the distributed database

		// Collect initial data to populate table from master server
		$master = LigminchaGlobalServer::masterDomain();
		if( $data = self::post( $master, array( self::$cmd => '' ) ) ) self::recvQueue( $data );
		else die( 'Failed to get initial table content from master' );

		new LigminchaGlobalLog( 'ligmincha_global table created', 'Database' );
	}

	/**
	 * Return the list of sync objects that will populate a newly created distributed database table
	 */
	private function initialTableData() {

		// Just populate new tables with the master (should be current server) server for now
		$master = LigminchaGlobalServer::getMaster();

		// Create a normal update sync object from this object, but with no target so it won't be added to the database for sending
		$rev = new LigminchaGlobalSync( 'U', $master->fields(), false );

		// Create a sync queue that will be processed by the client in the normal way
		$queue = array( LigminchaGlobalServer::getCurrent()->id, 0, $rev );

		return $queue;
	}

	/**
	 * Check that the local distributed database table exists and has a matching structure
	 */
	private function checkTable() {

		// If the table doesn't exist,
		if( !$this->tableExists( self::$table ) ) $this->createTable();

		// Otherwise check that it's the right structure
		else {
			$db = JFactory::getDbo();
			$table = self::sqlTable();

			// Get the current structure
			$db->setQuery( "DESCRIBE $table" );
			$db->query();

			$curFields = $db->loadAssocList( null, 'Field' );

			// For now only adding missing fields is supported, not removing, renaming or changing types
			$alter = array();
			foreach( self::$tableStruct as $field => $type ) {
				if( !in_array( $field, $curFields ) ) $alter[$field] = $type;
			}
			if( $alter ) {
				$cols = array();
				foreach( $alter as $field => $type ) $cols[] = "ADD COLUMN `$field` $type";
				$db->setQuery( "ALTER TABLE $table " . implode( ',', $cols ) );
				$db->query();
				new LigminchaGlobalLog( 'ligmincha_global table fields added: (' . implode( ',', array_keys( $alter ) ) . ')', 'Database' );
			}
		}
	}

	/**
	 * Send all queued sync-objects
	 */
	public static function sendQueue() {

		// Get all LG_SYNC items, bail if none
		if( !$revs = LigminchaGlobalObject::find( array( 'type' => LG_SYNC ) ) ) return false;

		// Make data streams for each target from the sync objects
		$streams = array();
		$server = LigminchaGlobalServer::getCurrent()->id;
		$session = LigminchaGlobalSession::getCurrent() ? LigminchaGlobalSession::getCurrent()->id : 0;
		foreach( $revs as $rev ) {
			$target = LigminchaGlobalServer::newFromId( $rev->ref1 )->id;
			if( array_key_exists( $target, $streams ) ) $streams[$target][] = $rev;
			else $streams[$target] = array( $server, $session, $rev );
		}

		//print '<pre>'; print_r($streams); print '</pre>';

		// Encode and send each stream
		foreach( $streams as $target => $stream ) {

			// Get the target domain from it's tag
			$url = LigminchaGlobalServer::newFromId( $target )->tag;

			// Zip up the data in JSON format
			// TODO: encrypt using shared secret or public key
			$data = self::encodeData( $stream );

			// Post the queue data to the server
			$result = self::post( $url, array( self::$cmd => $data ) );

			// If result is success, remove all sync objects destined for this target server
			if( $result == LG_SUCCESS ) LigminchaGlobalObject::del( array( 'type' => LG_SYNC, 'ref1' => $target ), false, true );
			else die( "Failed to post outgoing sync data ($result)" );
		}

		return true;
	}

	/**
	 * Receive sync-object queue from a remote server
	 */
	private static function recvQueue( $data ) {

		// Decode the data
		$queue = $data = self::decodeData( $data );
		$origin = array_shift( $queue );
		$session = array_shift( $queue );

		// Forward this queue to the WebSocket if it's active
		self::sendToWebSocket( $data, $session );

		// Process each of the sync objects (this may lead to further re-routing sync objects being made)
		foreach( $queue as $sync ) {
			LigminchaGlobalSync::process( $sync['tag'], $sync['data'], $origin );
		}

		// Let client know that we've processed their sync data
		return 'ok';
	}

	/**
	 * Send data to the local WebSocket daemon if active
	 */
	private static function sendToWebSocket( $queue, $session ) {
		if( WebSocket::isActive() ) {

			// Set the ID of this WebSocket message to the session ID of the sender so the WS server doesn't bounce it back to them
			WebSocket::$clientID = $session;

			// Send queue to all clients
			WebSocket::send( 'LigminchaGlobal', $queue );
		}
	}

	/**
	 * Remove all expired items (these changes are not routed because all servers handle expiration themselves)
	 */
	private function expire() {
		$db = JFactory::getDbo();
		$table = self::sqlTable();
		$db->setQuery( "DELETE FROM $table WHERE `expire` > 0 AND `expire`<" . LigminchaGlobalObject::timestamp() );
		$db->query();
	}

	/**
	 * Encode data for sending
	 */
	private static function encodeData( $data ) {
		return json_encode( $data );
	}

	/**
	 * Decode incoming data
	 */
	private static function decodeData( $data ) {
		return json_decode( $data, true );
	}

	/**
	 * POST data to the passed URL
	 */
	private static function post( $url, $data ) {
		$options = array(
			CURLOPT_POST => 1,
			CURLOPT_HEADER => 0,
			CURLOPT_URL => $url,
			CURLOPT_FRESH_CONNECT => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FORBID_REUSE => 1,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_POSTFIELDS => http_build_query( $data )
		);
		$ch = curl_init();
		curl_setopt_array( $ch, $options );
		$result = curl_exec( $ch );
		curl_close( $ch );
		return $result;
	}

	/**
	 * Make an SQL condition from the array
	 * ( a => x, b => y ) gives a=x AND b=y
	 * ( (a => x ), ( a => y ) ) gives a=x OR a=y
	 */
	public static function makeCond( $cond ) {
		$op = 'AND';
		$sqlcond = array();
		foreach( $cond as $field => $val ) {
			if( is_array( $val ) ) {
				$field = array_keys( $val )[0];
				$val = $val[$field];
				$op = 'OR';
			}
			$val = self::sqlField( $val, self::$tableStruct[$field] );
			$sqlcond[] = "`$field`=$val";
		}
		$sqlcond = implode( " $op ", $sqlcond );
		return $sqlcond;
	}

	/**
	 * Check if the passed database-condition only access owned objects
	 */
	private static function validateCond( $cond ) {
		// TODO
	}

	/**
	 * Make sure a hash is really a hash and use NULL if not
	 */
	public static function sqlHash( $hash ) {
		if( preg_match( '/[^a-z0-9]/i', $hash ) ) die( "Bad hash \"$hash\"" );
		$hash = $hash ? "0x$hash" : 'NULL';
		return $hash;
	}

	/**
	 * Format a field value ready for an SQL query
	 */
	public static function sqlField( $val, $type ) {
		if( is_null( $val ) ) return 'NULL';
		if( is_array( $val ) ) $val = json_encode( $val );
		$db = JFactory::getDbo();
		switch( substr( $type, 0, 1 ) ) {
			case 'I': $val = is_numeric( $val ) ? intval( $val ) : die( "Bad integer: \"$val\"" );;
					  break;
			case 'D': $val = is_numeric( $val ) ? $val : die( "Bad number: \"$val\"" );
					  break;
			case 'B': $val = self::sqlHash( $val );
					  break;
			default: $val = $db->quote( $val );
		}
		if( empty( (string)$val ) ) return '""';
		return $val;
	}

	/**
	 * Format the returned SQL fields accounting for hex cols
	 */
	public static function sqlFields() {
		static $fields;
		if( $fields ) return $fields;
		$fields = array();
		foreach( self::$tableStruct as $field => $type ) {
			if( substr( $type, 0, 1 ) == 'B' ) $fields[] = "hex(`$field`) as `$field`";
			else $fields[] = "`$field`";
		}
		$fields = implode( ',', $fields );
		return $fields;
	}

	/**
	 * Load an object's row from the DB given its ID
	 */
	public static function getObject( $id ) {
		if( !$id ) die( __METHOD__ . ' called without an ID' );
		$db = JFactory::getDbo();
		$table = self::sqlTable();
		$all = self::sqlFields();
		$db->setQuery( "SELECT $all FROM $table WHERE `id`=0x$id" );
		$db->query();
		if( !$row = $db->loadAssoc() ) return false;
		$data = $row['data'];
		if( substr( $data, 0, 1 ) == '{' || substr( $data, 0, 1 ) == '[' ) $row['data'] = json_decode( $data, true );
		return $row;
	}
}
