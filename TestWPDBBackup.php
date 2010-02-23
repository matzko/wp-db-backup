<?php

@require_once 'vfsStream/vfsStream.php';

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
	return call_user_func_array(array('TestWPDBBackup', 'mock__current_user_can'), $args);
}
function get_bloginfo() {}
function get_option()
{
	$args = func_get_args();
	return call_user_func_array(array('TestWPDBBackup', 'mock__get_option'), $args);
}
function trailingslashit() {}

/** Other functions **/
function apache_get_modules() {
	return TestWPDBBackup::apache_get_modules();
}

include 'wp-db-backup.php';

class TestWPDB {

	static $_get_col;

	function get_col()
	{
		return self::$_get_col;
	}
}

class TestWPDBBackup extends PHPUnit_Framework_TestCase {

	protected $_b;
	static $_apache_modules;
	static $_current_user_can;
	static $_is_site_admin = false;
	static $_options;

	public static function apache_get_modules()
	{
		return self::$_apache_modules;
	}

	public static function mock__current_user_can()
	{
		return self::$_current_user_can;
	}

	public static function mock__get_option($name = '')
	{
		if ( isset( self::$_options[$name] ) ) {
			return self::$_options[$name];
		} else {
			return false;
		}
	}

	protected function _setup_wpdb() 
	{
		global $wpdb;
		$wpdb = new TestWPDB;
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

	public function test__add_cron_sched_options()
	{
		$cron = $this->_b->add_cron_sched_options(array());
		$this->assertArrayHasKey('weekly', $cron);

		$cron = $this->_b->add_cron_sched_options(null);
		$this->assertArrayHasKey('weekly', $cron);
		
		$cron = $this->_b->add_cron_sched_options();
		$this->assertArrayHasKey('weekly', $cron);

		$cron_item = $cron['weekly'];
		$this->assertArrayHasKey('interval', $cron_item);
		$this->assertArrayHasKey('display', $cron_item);
		$this->assertContains(604800, $cron_item);
	}

	public function test__current_user_can_backup()
	{
		$this->assertFalse($this->_b->current_user_can_backup());

		$stub = $this->getMock('WP_DB_Backup', array('is_wp_secure_enough'));
		$stub->expects($this->any())
			->method('is_wp_secure_enough')
			->will($this->returnValue(true));


		// not multi-site, but user can import
		self::$_current_user_can = true;		
		$this->assertTrue($stub->current_user_can_backup());
		
		// not multi-site, and user cannot import
		self::$_current_user_can = false;		
		$this->assertFalse($stub->current_user_can_backup());


		function is_site_admin() {
			return TestWPDBBackup::$_is_site_admin;
		}
		
		// is multi-site, not ms-admin, but user can import
		self::$_current_user_can = true;		
		self::$_is_site_admin = false;
		$this->assertFalse($stub->current_user_can_backup());

		// is multi-site, is ms-admin, and user can import
		self::$_current_user_can = true;		
		self::$_is_site_admin = true;
		$this->assertTrue($stub->current_user_can_backup());
	}

	public function test__do_cron_backup()
	{
		$stub = $this->getMock('WP_DB_Backup', 
			array(
				'_get_core_table_names',
				'do_db_backup',
				'deliver_backup',
			)
		);

		self::$_options['wp_cron_backup_tables'] = array('my_selected_cron');	

		TestWPDB::$_get_col = array('my_selected_cron', 'wp_posts', 'wp_comments', 'wp_users', 'whatever'); 

		$stub->expects($this->any())
			->method('_get_core_table_names')
			->will($this->returnValue(array('wp_posts', 'wp_comments')));

		$stub->expects($this->any())
			->method('do_db_backup')
			->will($this->returnCallback(array(&$this, 'mock__do_db_backup')));

		$stub->do_cron_backup();	

		$this->assertContains('my_selected_cron', $this->_mock__do_db_backup_values);
		$this->assertContains('wp_comments', $this->_mock__do_db_backup_values);
		$this->assertContains('wp_posts', $this->_mock__do_db_backup_values);
		$this->assertNotContains('whatever', $this->_mock__do_db_backup_values);
		$this->assertNotContains('wp_users', $this->_mock__do_db_backup_values);
		
		
		TestWPDB::$_get_col = array(); 
		
		$stub->do_cron_backup();	

		$this->assertNotContains('my_selected_cron', $this->_mock__do_db_backup_values);
		$this->assertNotContains('wp_posts', $this->_mock__do_db_backup_values);
	}
		public function mock__do_db_backup()
		{
			$args = func_get_args();
			$this->_mock__do_db_backup_values = $args[0];
		}

	public function test__is_wp_secure_enough()
	{
		$this->assertEquals(function_exists('wp_verify_nonce'), $this->_b->is_wp_secure_enough());

		$this->assertFalse($this->_b->is_wp_secure_enough());

		function wp_verify_nonce() {};

		$this->assertTrue($this->_b->is_wp_secure_enough());
	}

	/**
	 * @dataProvider provider__perhaps_compress_file
	 */
	public function test__perhaps_compress_file($filename, $gzipped_filename, $file_exists, $gzipped_file_exists)
	{
		if (class_exists('vfsStream') === false) {
			$this->markTestSkipped('vfsStream not installed.');
		}
		
		vfsStreamWrapper::register();
		$root = new vfsStreamDirectory('home');

		$dir = dirname($filename);
		if ( ! empty( $dir ) ) {
			$root->addChild(new vfsStreamDirectory($dir));
			$root->getChild($dir)->addChild(vfsStream::newFile(basename($filename))->withContent('Backup SQL text'));
		}
		vfsStreamWrapper::setRoot($root);


		$new_filename = $this->_b->perhaps_compress_file(vfsStream::url($filename));

		if ( function_exists('gzencode') ) {
			
			$this->assertEquals($gzipped_file_exists, vfsStreamWrapper::getRoot()->hasChild($gzipped_filename));
			$this->assertEquals($file_exists, vfsStreamWrapper::getRoot()->hasChild($filename));
		} else {
			$this->assertFalse(vfsStreamWrapper::getRoot()->hasChild($gzipped_filename));
			$this->assertTrue(vfsStreamWrapper::getRoot()->hasChild($filename));
		}


	}
		public function provider__perhaps_compress_file()
		{
			return array(
				array('path/file.sql', 'path/file.sql.gz', false, true),
			);
		}

	/**
	 * @dataProvider provider__send_mail
	 */
	public function test__send_mail($to, $subject, $message, $file, $send_count)
	{
		$mail_stub = $this->getMock('PHPMailer',
			array(
				'AddAddress',
				'AddAttachment',
				'ClearAddresses',
				'ClearAllRecipients',
				'ClearAttachments',
				'ClearBCCs',
				'ClearCCs',
				'ClearCustomHeaders',
				'ClearReplyTos',
				'IsMail',
				'Send',
			)
		);

		$mail_stub->expects($this->exactly($send_count))
			->method('Send')
			->will($this->returnValue(true));

		$this->_b->send_mail($mail_stub, $to, $subject, $message, $file);
	}
		public function provider__send_mail()
		{
			return array(
				array(
					'bob@example.com', 'backup email', 'some message', '/path/to/file.sql.gz', 1,
				),
				array(
					'', 'backup email', 'some message', '/path/to/file.sql.gz', 0,
				),
				array(
					'bob@example.com', 'backup email', 'some message', '', 0,
				),
			);
		}
}
