<?php

/** WordPress-specific functions **/
function __($a) {
	return $a;
}
function add_action() {}
function add_filter() {}
function add_query_arg() {}
function apply_filters() {}
function current_user_can() {
	$args = func_get_args();
	return call_user_func_array(array('TestWPDBBackup', 'current_user_can'), $args);
}
function get_option() {}
function trailingslashit() {}

/** Other functions **/
function apache_get_modules() {
	return TestWPDBBackup::apache_get_modules();
}

include 'wp-db-backup.php';

class TestWPDBBackup extends PHPUnit_Framework_TestCase {

	protected $_b;
	static $_apache_modules;
	static $_current_user_can;

	public static function apache_get_modules()
	{
		return self::$_apache_modules;
	}

	public static function current_user_can()
	{
		return self::$_current_user_can;
	}

	protected function _setup_wpdb() 
	{
		global $wpdb;
		$wpdb->tables = array(
			'users',
			'usermeta',
			'posts',
			'categories',
			'post2cat',
			'comments',
			'links',
			'link2cat',
			'options',
			'postmeta',
			'terms',
			'term_taxonomy',
			'term_relationships',
			'commentmeta',
		);
		$wpdb->prefix = 'wp_';

		foreach( $wpdb->tables as $table ) {
			$wpdb->$table = $wpdb->prefix . $table;
		}

	}

	protected function setUp()
	{
		$this->_setup_wpdb();
		$this->_b = new WP_DB_Backup;
	}

	protected function tearDown()
	{
	}

	public function test___get_core_table_names()
	{
		$tables = $this->_b->_get_core_table_names();
		$this->assertContains('wp_commentmeta', $tables);
		$this->assertContains('wp_comments', $tables);
		$this->assertContains('wp_posts', $tables);
	}

	public function test___using_evasive_module()
	{
		// default it's off
		$this->assertFalse($this->_b->_using_evasive_module());

		$this->_b->mod_evasive_override = true;
		$this->assertTrue($this->_b->_using_evasive_module());
		
		$this->_b->mod_evasive_override = false;
		$this->assertFalse($this->_b->_using_evasive_module());

		self::$_apache_modules = array('whatever');
		$this->assertFalse($this->_b->_using_evasive_module());
		
		self::$_apache_modules = array('whatever', 'mod_evasive');
		$this->assertTrue($this->_b->_using_evasive_module());
		
		self::$_apache_modules = array('whatever', 'mod_dosevasive');
		$this->assertTrue($this->_b->_using_evasive_module());
	}

	public function test__current_user_can_backup()
	{
		$this->assertFalse($this->_b->current_user_can_backup());

		$stub = $this->getMock('WP_DB_Backup', array('is_wp_secure_enough'));
		$stub->expects($this->any())
			->method('is_wp_secure_enough')
			->will($this->returnValue(true));

		self::$_current_user_can = true;		
		$this->assertTrue($stub->current_user_can_backup());
		
		self::$_current_user_can = false;		
		$this->assertFalse($stub->current_user_can_backup());
	}

	public function test__is_wp_secure_enough()
	{
		$this->assertEquals(function_exists('wp_verify_nonce'), $this->_b->is_wp_secure_enough());

		$this->assertFalse($this->_b->is_wp_secure_enough());

		function wp_verify_nonce() {};

		$this->assertTrue($this->_b->is_wp_secure_enough());
	}
}
