<?php

function add_action() {}
function add_filter() {}
function add_query_arg() {}
function apache_get_modules() {
	return TestWPDBBackup::apache_get_modules();
}
function apply_filters() {}
function get_option() {}
function trailingslashit() {}

include 'wp-db-backup.php';

class TestWPDBBackup extends PHPUnit_Framework_TestCase {

	protected $_b;

	public static function apache_get_modules()
	{
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

	public function test__get_core_table_names()
	{
		$tables = $this->_b->_get_core_table_names();
		$this->assertContains('wp_commentmeta', $tables);
		$this->assertContains('wp_comments', $tables);
		$this->assertContains('wp_posts', $tables);
	}

	public function test__using_evasive_module()
	{
		// default it's off
		$this->assertFalse($this->_b->_using_evasive_module());

		$this->_b->mod_evasive_override = true;
		$this->assertTrue($this->_b->_using_evasive_module());
		
		$this->_b->mod_evasive_override = false;
		$this->assertFalse($this->_b->_using_evasive_module());
	}

}
