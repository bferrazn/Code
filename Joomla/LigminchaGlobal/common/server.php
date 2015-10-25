<?php
/**
 * Global Server
 */
class LigminchaGlobalServer extends LigminchaGlobalObject {

	// Current instance
	private static $current = null;

	// Master server
	private static $master = null;

	public $isMaster = false;

	function __construct() {
		$this->checkMaster();
		$this->type = LG_SERVER;
		parent::__construct();
	}

	/**
	 * Determine whether or not this is the master site
	 */
	private function checkMaster() {
		$this->isMaster = ( $_SERVER['HTTP_HOST'] == self::masterDomain() );
	}

	/**
	 * What is the master domain?
	 */
	public static function masterDomain() {
		static $master;
		if( !$master ) {
			$config = JFactory::getConfig();
			if( !$master = $config->get( 'lgMaster' ) ) $master = 'ligmincha.org';
		}
		return $master;
	}

	/**
	 * Get the master server object
	 * - we have to allow master server to be optional so that everything keeps working prior to its object having been loaded
	 */
	public static function getMaster() {
		if( !self::$master ) {
			$domain = self::masterDomain();
			self::$master = self::getCurrent()->isMaster ? self::getCurrent() : self::selectOne( array( 'tag' => $domain ) );

			// Put our server on the update queue after we've established the master
			if( self::$master ) self::getCurrent()->update();
		}
		return self::$master;
	}

	/**
	 * Get/create current object instance
	 */
	public static function getCurrent() {
		if( is_null( self::$current ) ) {

			// Make a new uuid from the server's secret
			$config = JFactory::getConfig();
			$id = self::hash( $config->get( 'secret' ) );
			self::$current = self::newFromId( $id );

			// If the object was newly created, populate with default initial data and save
			if( !self::$current->tag ) {

				// Make it easy to find this server by domain
				self::$current->tag = $_SERVER['HTTP_HOST'];

				// Server information
				self::$current->data = array(
					'name' => $config->get( 'sitename' )
				);

				// Save our new instance to the DB (if we have a master yet)
				if( self::getMaster() ) self::$current->update();
			}
		}
		return self::$current;
	}

	/**
	 * Make a new object given an id
	 */
	public static function newFromId( $id, $type = false ) {
		$obj = parent::newFromId( $id, LG_SERVER );
		$obj->checkMaster();
		return $obj;
	}

	/**
	 * Add this type to $cond
	 */
	public static function select( $cond = array() ) {
		$cond['type'] = LG_SERVER;
		return parent::select( $cond );
	}

	public static function selectOne( $cond = array() ) {
		$cond['type'] = LG_SERVER;
		return parent::selectOne( $cond );
	}
}
