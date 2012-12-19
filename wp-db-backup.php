<?php
/*
Plugin Name: WordPress Database Backup
Plugin URI: http://ilfilosofo.com/blog/wp-db-backup
Description: On-demand backup of your WordPress database. Navigate to <a href="edit.php?page=wp-db-backup">Tools &rarr; Backup</a> to get started.
Author: Austin Matzko 
Author URI: http://ilfilosofo.com/
Version: 2.3-alpha

Development continued from that done by Skippy (http://www.skippy.net/)

Originally modified from Mark Ghosh's One Click Backup, which 
in turn was derived from phpMyAdmin.

Many thanks to Owen (http://asymptomatic.net/wp/) for his patch
   http://dev.wp-plugins.org/ticket/219

Copyright 2010  Austin Matzko  ( email : austin@pressedcode.com )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA
*/

class WP_DB_Backup {

	var $backup_complete = false;
	var $backup_file = '';
	var $backup_filename;
	var $basename;
	var $errors = array();
	var $mod_evasive_override = false;
	var $page_url;
	var $referer_check_key;
	var $_rows_per_segment = 100;
	var $version = '2.1.5-alpha';

	function WP_DB_Backup() 
	{
		return $this->__construct();
	}

	function __construct()
	{
		global $table_prefix, $wpdb;
		
		/**
		 * Change WP_BACKUP_DIR if you want to
		 * use a different backup location
		 */

		$rand = substr( md5( md5( DB_PASSWORD ) ), -5 );
		$wpdbb_content_dir = ( defined('WP_CONTENT_DIR') ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$wpdbb_content_url = ( defined('WP_CONTENT_URL') ) ? WP_CONTENT_URL : get_option('siteurl') . '/wp-content';
		$wpdbb_plugin_dir = ( defined('WP_PLUGIN_DIR') ) ? WP_PLUGIN_DIR : $wpdbb_content_dir . '/plugins';

		if ( ! defined('WP_BACKUP_DIR') ) {
			define('WP_BACKUP_DIR', $wpdbb_content_dir . '/backup-' . $rand . '/');
		}

		if ( ! defined('WP_BACKUP_URL') ) {
			define('WP_BACKUP_URL', $wpdbb_content_url . '/backup-' . $rand . '/');
		}

		if ( defined('ROWS_PER_SEGMENT') ) {
			$this->_rows_per_segment = (int) ROWS_PER_SEGMENT;	
		}

		/** 
		 * Someone can set MOD_EVASIVE_OVERRIDE to true 
		 * and increase MOD_EVASIVE_DELAY 
		 * if the backup stops prematurely.
		 */
		if ( defined('MOD_EVASIVE_OVERRIDE') ) {
			$this->mod_evasive_override = (bool) MOD_EVASIVE_OVERRIDE;
		}

		if ( ! defined('MOD_EVASIVE_DELAY') ) {
			define('MOD_EVASIVE_DELAY', '500');
		}

		$table_prefix = ( isset( $table_prefix ) ) ? $table_prefix : $wpdb->prefix;
		$datum = gmdate("Ymd_B");
		$this->backup_filename = DB_NAME . "_$table_prefix$datum.sql".($this->has_gz() ? '.gz' : '');

		$this->backup_dir = trailingslashit(apply_filters('wp_db_b_backup_dir', WP_BACKUP_DIR));
		$this->basename = 'wp-db-backup';
	
		$this->referer_check_key = $this->basename . '-download_' . DB_NAME;
		$query_args = array( 'page' => $this->basename );
		if ( function_exists('wp_create_nonce') )
			$query_args = array_merge( $query_args, array('_wpnonce' => wp_create_nonce($this->referer_check_key)) );
		$this->page_url = add_query_arg( $query_args );

		$this->attach_event_listeners();
	}

	function attach_event_listeners()
	{
		add_action('init', array(&$this, 'init_textdomain'));
		add_action('load-update-core.php', array(&$this, 'update_notice_action'));
		add_action('wp_ajax_save_backup_time', array(&$this, 'save_backup_time'));
		add_action('wp_cron_daily', array(&$this, 'wp_cron_daily'));
		add_action('wp_db_backup_cron', array(&$this, 'do_cron_backup'));

		add_filter('plugin_action_links', array(&$this, 'filter_plugin_action_links'), 10, 2 );
		add_filter('cron_schedules', array(&$this, 'add_cron_sched_options'));
		add_filter('wp_db_b_schedule_choices', array(&$this, 'schedule_choices'));
		
		if (isset($_POST['do_backup'])) {
			if ( ! $this->is_wp_secure_enough() )
				return false;
			check_admin_referer($this->referer_check_key);
			if ( ! $this->current_user_can_backup() ) {
				$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup')));
				return false;
			}

			// save exclude prefs

			$exc_revisions = ( isset( $_POST['exclude-revisions'] ) ) ? (array) $_POST['exclude-revisions'] : array();
			$exc_spam = ( isset( $_POST['exclude-spam'] ) ) ? (array) $_POST['exclude-spam'] : array();
			update_option('wp_db_backup_excs', array('revisions' => $exc_revisions, 'spam' => $exc_spam));
			switch($_POST['do_backup']) {
			case 'backup':
				add_action('init', array(&$this, 'perform_backup'));
				break;
			case 'fragments':
				add_action('admin_menu', array(&$this, 'fragment_menu'));
				break;				
			}
		} elseif (isset($_GET['fragment'] )) {
			check_admin_referer($this->referer_check_key);
			if ( ! $this->current_user_can_backup() ) {
				$this->log_error(array('loc' => 'frame', 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup')));
				return false;
			}
			add_action('init', array(&$this, 'init'));
		} elseif (isset($_GET['backup'] )) {
			check_admin_referer($this->referer_check_key);
			if ( ! $this->current_user_can_backup() ) {
				$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup')));
				return false;
			}
			add_action('init', array(&$this, 'init'));
		} else {
			add_action('admin_menu', array(&$this, 'admin_menu'));
		}
	}

	/**
	 * Get the core WordPress table names.
	 *
	 * @access private
	 *
	 * @return array The array of core tables names.
	 */
	function _get_core_table_names()
	{
		global $wpdb;

		$core_table_names = array();

		$possible_names = array(
			'categories',
			'commentmeta',
			'comments',
			'link2cat',
			'linkcategories',
			'links',
			'options',
			'post2cat',
			'postmeta',
			'posts',
			'terms',
			'term_taxonomy',
			'term_relationships',
			'users',
			'usermeta',
		);
		
		foreach( $possible_names as $name ) {
			if ( isset( $wpdb->{$name} ) ) {
				$core_table_names[] = $wpdb->{$name};
			}
		}

		return $core_table_names;
	}
	
	function init() {
		if ( ! $this->current_user_can_backup() ) {
			$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup')));
			return false;
		}
		if ( isset($_GET['backup']) ) {
			if ( ! wp_verify_nonce($_GET['_wpnonce'], $this->referer_check_key) ) {
				$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => sprintf(__('There appears to be an unauthorized attempt from this site to access your database located at %1s.  The attempt has been halted.','wp-db-backup'),get_option('home'))));
			}
			$via = isset($_GET['via']) ? $_GET['via'] : 'http';
			
			$this->backup_file = $_GET['backup'];
			if ( 0 < validate_file($this->backup_file) ) {
				$this->log_error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __('Invalid file request.','wp-db-backup')));
				return false;
			}

			switch($via) {
			case 'smtp':
			case 'email':
				$success = $this->deliver_backup($this->backup_file, 'smtp', $_GET['recipient'], 'frame');
				$this->print_error_messages( 'frame' );
				if ( $success ) {
					echo '
						<!-- ' . $via . ' -->
						<script type="text/javascript"><!--\\
					';
					echo '
						alert("' . __('Backup Complete!','wp-db-backup') . '");
						window.onbeforeunload = null; 
						</script>
					';
				}
				break;
			default:
				$this->deliver_backup($this->backup_file, $via);
				$this->print_error_messages( 'frame' );
			}
			die();
		}
		if ( isset($_GET['fragment'] ) ) {
			if ( ! wp_verify_nonce($_GET['_wpnonce'], $this->referer_check_key) ) {
				$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => sprintf(__('There appears to be an unauthorized attempt from this site to access your database located at %1s.  The attempt has been halted.','wp-db-backup'),get_option('home'))));
			}
			list($table, $segment, $filename) = explode(':', $_GET['fragment']);
			if ( 0 < validate_file($filename) ) {
				$this->log_error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __('Invalid file request.','wp-db-backup')));
				exit;
			}
			$this->backup_fragment($table, $segment, $filename);
		}

		die();
	}

	function init_textdomain() {
		load_plugin_textdomain('wp-db-backup', str_replace(ABSPATH, '', dirname(__FILE__)), dirname(plugin_basename(__FILE__)));
	}

	/*
	 * Add a link to back up your database when doing a core upgrade 
	 */
	function update_notice_action() {
		if ( empty( $_GET['action'] ) || 'upgrade-core' == $_GET['action'] ) :
			ob_start(array(&$this, 'update_notice'));
			add_action('admin_footer', create_function('', 'ob_end_flush();'));
		endif;
	}
		function update_notice($text = '') {
			$pattern = '#(<a href\="' . __('http://codex.wordpress.org/WordPress_Backups') . '">.*?</p>)#';
			$replace = '$1' . "\n<p>" . sprintf(__('Click <a href="%s" target="_blank">here</a> to back up your database using the WordPress Database Backup plugin. <strong>Note:</strong> WordPress Database Backup does <em>not</em> back up your files, just your database.', 'wp-db-backup'), 'tools.php?page=wp-db-backup') . "</p>\n"; 
			$text = preg_replace($pattern, $replace, $text);
			return $text;
		}

	/**
	 * Determine whether the server is using mod_evasive or the like.  Will also return true if MOD_EVASIVE_OVERRIDE is defined and true.
	 *
	 * @access private 
	 *
	 * @return bool Whether the server is using mod_evasive
	 */
	function _using_evasive_module() {
		if ( true === $this->mod_evasive_override ) 
			return true;

		if ( function_exists('apache_get_modules') ) {
			foreach( (array) apache_get_modules() as $mod ) {
				if ( false !== strpos($mod,'mod_evasive') || false !== strpos($mod,'mod_dosevasive') ) {
					return true;
				}
			}
		}
		return false;
	}

	function print_backup_script() {
		global $table_prefix, $wpdb;
	
		echo "<div class='wrap'>";
		echo 	'<fieldset class="options"><legend>' . __('Progress','wp-db-backup') . '</legend>
			<p><strong>' .
				__('DO NOT DO THE FOLLOWING AS IT WILL CAUSE YOUR BACKUP TO FAIL:','wp-db-backup').
			'</strong></p>
			<ol>
				<li>'.__('Close this browser','wp-db-backup').'</li>
				<li>'.__('Reload this page','wp-db-backup').'</li>
				<li>'.__('Click the Stop or Back buttons in your browser','wp-db-backup').'</li>
			</ol>
			<p><strong>' . __('Progress:','wp-db-backup') . '</strong></p>
			<div id="meterbox" style="height:11px;width:80%;padding:3px;border:1px solid #659fff;"><div id="meter" style="height:11px;line-height:11px;background-color:#659fff;width:0%;text-align:center;font-size:6pt;">&nbsp;</div></div>
			<div id="progress_message"></div>
			<div id="errors"></div>
			</fieldset>
			<iframe id="backuploader" src="about:blank" style="visibility:hidden;border:none;height:1em;width:1px;"></iframe>
			<script type="text/javascript">
			//<![CDATA[
			window.onbeforeunload = function() {
				return "' . __('Navigating away from this page will cause your backup to fail.', 'wp-db-backup') . '";
			}
			function setMeter(pct) {
				var meter = document.getElementById("meter");
				meter.style.width = pct + "%";
				meter.innerHTML = Math.floor(pct) + "%";
			}
			function setProgress(str) {
				var progress = document.getElementById("progress_message");
				progress.innerHTML = str;
			}
			function addError(str) {
				var errors = document.getElementById("errors");
				errors.innerHTML = errors.innerHTML + str + "<br />";
			}

			function backup(table, segment) {
				var fram = document.getElementById("backuploader");
				fram.src = "' . $this->page_url . '&fragment=" + table + ":" + segment + ":' . $this->backup_filename . ':";
			}
			
			var curStep = 0;
			
			function nextStep() {
				backupStep(curStep);
				curStep++;
			}
			
			function finishBackup() {
				var fram = document.getElementById("backuploader");				
				setMeter(100);
		';

		$download_uri = add_query_arg('backup', $this->backup_filename, $this->page_url);
		switch($_POST['deliver']) {
		case 'http':
			echo '
				setProgress("' . sprintf(__("Backup complete, preparing <a href=\\\"%s\\\">backup</a> for download...",'wp-db-backup'), $download_uri) . '");
				window.onbeforeunload = null; 
				fram.src = "' . $download_uri . '";
			';
			break;
		case 'smtp':
			echo '
				setProgress("' . sprintf(__("Backup complete, sending <a href=\\\"%s\\\">backup</a> via email...",'wp-db-backup'), $download_uri) . '");
				window.onbeforeunload = null; 
				fram.src = "' . $download_uri . '&via=email&recipient=' . $_POST['backup_recipient'] . '";
			';
			break;
		default:
			echo '
				setProgress("' . sprintf(__("Backup complete, download <a href=\\\"%s\\\">here</a>.",'wp-db-backup'), $download_uri) . '");
				window.onbeforeunload = null; 
			';
		}
		
		echo '
			}
			
			function backupStep(step) {
				switch(step) {
				case 0: backup("", 0); break;
		';
		
		$also_backup = array();
		if (isset($_POST['other_tables'])) {
			$also_backup = $_POST['other_tables'];
		} else {
			$also_backup = array();
		}
		$core_tables = $_POST['core_tables'];
		$tables = array_merge($core_tables, $also_backup);
		$step_count = 1;
		foreach ($tables as $table) {
			$rec_count = $wpdb->get_var("SELECT count(*) FROM {$table}");
			$rec_segments = ceil($rec_count / $this->_rows_per_segment);
			$table_count = 0;
			if ( $this->_using_evasive_module() ) {
				$delay = "setTimeout('";
				$delay_time = "', " . (int) MOD_EVASIVE_DELAY . ")";
			}
			else { $delay = $delay_time = ''; }
			do {
				echo "case {$step_count}: {$delay}backup(\"{$table}\", {$table_count}){$delay_time}; break;\n";
				$step_count++;
				$table_count++;
			} while($table_count < $rec_segments);
			echo "case {$step_count}: {$delay}backup(\"{$table}\", -1){$delay_time}; break;\n";
			$step_count++;
		}
		echo "case {$step_count}: finishBackup(); break;";
		
		echo '
				}
				if(step != 0) setMeter(100 * step / ' . $step_count . ');
			}

			nextStep();
			// ]]>
			</script>
	</div>
		';
		$this->print_backup_menu();
	}

	function backup_fragment($table, $segment, $filename) {
		global $table_prefix, $wpdb;
			
		echo "$table:$segment:$filename";
		
		if($table == '') {
			$msg = __('Creating backup file...','wp-db-backup');
		} else {
			if($segment == -1) {
				$msg = sprintf(__('Finished backing up table \\"%s\\".','wp-db-backup'), $table);
			} else {
				$msg = sprintf(__('Backing up table \\"%s\\"...','wp-db-backup'), $table);
			}
		}
		
		if (is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $filename, 'a');
			if(!$this->fp) {
				$this->log_error(__('Could not open the backup file for writing!','wp-db-backup'));
				$this->log_error(array('loc' => 'frame', 'kind' => 'fatal', 'msg' =>  __('The backup file could not be saved.  Please check the permissions for writing to your backup directory and try again.','wp-db-backup')));
			}
			else {
				if($table == '') {		
					//Begin new backup of MySql
					$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
					$this->stow("#\n");
					$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),gmdate("l j. F Y H:i T")) . "\n");
					$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
					$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
					$this->stow("# --------------------------------------------------------\n");
				} else {
					if($segment == 0) {
						// Increase script execution time-limit to 15 min for every table.
						if ( !ini_get('safe_mode')) @set_time_limit(15*60);
						// Create the SQL statements
						$this->stow("# --------------------------------------------------------\n");
						$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
						$this->stow("# --------------------------------------------------------\n");
					}			
					$this->backup_table($table, $segment);
				}
			}
		} else {
			$this->log_error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __('The backup directory is not writeable!  Please check the permissions for writing to your backup directory and try again.','wp-db-backup')));
		}

		if($this->fp) $this->close($this->fp);
		
		$this->print_error_messages('frame');

		echo '<script type="text/javascript"><!--//
		var msg = "' . $msg . '";
		window.parent.setProgress(msg);
		window.parent.nextStep();
		//--></script>
		';
		die();
	}

	function perform_backup() {
		// are we backing up any other tables?
		$also_backup = array();
		if (isset($_POST['other_tables']))
			$also_backup = $_POST['other_tables'];
		$core_tables = $_POST['core_tables'];
		$this->backup_file = $this->do_db_backup($core_tables, $also_backup);
		if (false !== $this->backup_file) {
			if ('smtp' == $_POST['deliver']) {
				$this->deliver_backup($this->backup_file, 'smtp', $_POST['backup_recipient'], 'main');
				wp_redirect($this->page_url);
			} elseif ('http' == $_POST['deliver']) {
				$download_uri = add_query_arg('backup',$this->backup_file,$this->page_url);
				wp_redirect($download_uri); 
				exit;
			}
			// we do this to say we're done.
			$this->backup_complete = true;
		}
	}

	/**
	 * Filter the plugin action links, which appear on the main admin plugin page.
	 * @param array $links The links.
	 * @param string $file The current plugin file.
	 * @return array The array of links to show in the settings area
	 */
	function filter_plugin_action_links( $links = array(), $file = '')
	{
		if ( plugin_basename(__FILE__) == $file ) {
			$link = '<a href="tools.php?page=' . $this->basename . '">' . __('Configure &amp; Backup', 'wp-db-backup') . '</a>';
			array_unshift( $links, $link );
		}
		return $links;
	}

	function print_admin_header() {
		?>
		<script type="text/javascript">
		//<![CDATA[
		if ( 'undefined' != typeof addLoadEvent ) {
			addLoadEvent(function() {
				var t = {'extra-tables-list':{name: 'other_tables[]'}, 'include-tables-list':{name: 'wp_cron_backup_tables[]'}};

				for ( var k in t ) {
					t[k].s = null;
					var d = document.getElementById(k);
					if ( ! d )
						continue;
					var ul = d.getElementsByTagName('ul').item(0);
					if ( ul ) {
						var lis = ul.getElementsByTagName('li');
						if ( 2 < lis.length ) {
							var text = document.createElement('p');
							text.className = 'instructions';
							text.innerHTML = '<?php _e('Click and hold down <code>[SHIFT]</code> to toggle multiple checkboxes', 'wp-db-backup'); ?>';
							ul.parentNode.insertBefore(text, ul);
						}
					}
					t[k].p = d.getElementsByTagName("input");
					for(var i=0; i < t[k].p.length; i++)
						if(t[k].name == t[k].p[i].getAttribute('name')) {
							t[k].p[i].id = k + '-table-' + i;
							t[k].p[i].onkeyup = t[k].p[i].onclick = function(e) {
								e = e ? e : event;
								if ( 16  == e.keyCode ) 
									return;
								var match = /([\w-]*)-table-(\d*)/.exec(this.id);
								var listname = match[1];
								var that = match[2];
								if ( null === t[listname].s )
									t[listname].s = that;
								else if ( e.shiftKey ) {
									var start = Math.min(that, t[listname].s) + 1;
									var end = Math.max(that, t[listname].s);
									for( var j=start; j < end; j++)
										t[listname].p[j].checked = t[listname].p[j].checked ? false : true;
									t[listname].s = null;
								}
							}
						}
				}

				<?php if ( function_exists('wp_schedule_event') ) : // needs to be at least WP 2.1 for ajax ?>
				if ( 'undefined' == typeof XMLHttpRequest ) 
					var xml = new ActiveXObject( navigator.userAgent.indexOf('MSIE 5') >= 0 ? 'Microsoft.XMLHTTP' : 'Msxml2.XMLHTTP' );
				else
					var xml = new XMLHttpRequest();

				var initTimeChange = function() {
					var timeWrap = document.getElementById('backup-time-wrap');
					var backupTime = document.getElementById('next-backup-time');
					if ( !! timeWrap && !! backupTime ) {
						var span = document.createElement('span');
						span.className = 'submit';
						span.id = 'change-wrap';
						span.innerHTML = '<input type="submit" id="change-backup-time" name="change-backup-time" value="<?php _e('Change','wp-db-backup'); ?>" />';
						timeWrap.appendChild(span);
						backupTime.ondblclick = function(e) { span.parentNode.removeChild(span); clickTime(e, backupTime); };
						span.onclick = function(e) { span.parentNode.removeChild(span); clickTime(e, backupTime); };
					}
				}

				var clickTime = function(e, backupTime) {
					var tText = backupTime.innerHTML;
					backupTime.innerHTML = '<input type="text" value="' + tText + '" name="backup-time-text" id="backup-time-text" /> <span class="submit"><input type="submit" name="save-backup-time" id="save-backup-time" value="<?php _e('Save', 'wp-db-backup'); ?>" /></span>';
					backupTime.ondblclick = null;
					var mainText = document.getElementById('backup-time-text');
					mainText.focus();
					var saveTButton = document.getElementById('save-backup-time');
					if ( !! saveTButton )
						saveTButton.onclick = function(e) { saveTime(backupTime, mainText); return false; };
					if ( !! mainText )
						mainText.onkeydown = function(e) { 
							e = e || window.event;
							if ( 13 == e.keyCode ) {
								saveTime(backupTime, mainText);
								return false;
							}
						}
				}

				var saveTime = function(backupTime, mainText) {
					var tVal = mainText.value;

					xml.open('POST', 'admin-ajax.php', true);
					xml.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					if ( xml.overrideMimeType )
						xml.setRequestHeader('Connection', 'close');
					xml.send('action=save_backup_time&_wpnonce=<?php echo wp_create_nonce($this->referer_check_key); ?>&backup-time='+tVal);
					xml.onreadystatechange = function() {
						if ( 4 == xml.readyState && '0' != xml.responseText ) {
							backupTime.innerHTML = xml.responseText;
							initTimeChange();
						}
					}
				}

				initTimeChange();
				<?php endif; // wp_schedule_event exists ?>
			});
		}
		//]]>
		</script>
		<style type="text/css">
			.wp-db-backup-updated {
				margin-top: 1em;
			}

			fieldset.options {
				border: 1px solid;
				margin-top: 1em;
				padding: 1em;
			}
				fieldset.options div.tables-list {
					float: left;
					padding: 1em;
				}

				fieldset.options input {
				}

				fieldset.options legend {
					font-size: larger;
					font-weight: bold;
					margin-bottom: .5em;
					padding: 1em;
				}
		
				fieldset.options .instructions {
					font-size: smaller;
				}

				fieldset.options ul {
					list-style-type: none;
				}
					fieldset.options li {
						text-align: left;
					}

				fieldset.options .submit {
					border-top: none;
				}
		</style>
		<?php 
	}

	function admin_load() {
		add_action('admin_head', array(&$this, 'print_admin_header'));
	}

	function admin_menu() {
		$_page_hook = add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', $this->basename, array(&$this, 'print_backup_menu'));
		add_action('load-' . $_page_hook, array(&$this, 'admin_load'));
		if ( function_exists('add_contextual_help') ) {
			$text = $this->help_menu();
			add_contextual_help($_page_hook, $text);
		}
	}

	function fragment_menu() {
		$_page_hook = add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', $this->basename, array(&$this, 'print_backup_script'));
		add_action('load-' . $_page_hook, array(&$this, 'admin_load'));
		if ( function_exists('add_contextual_help') ) {
			$text = $this->help_menu();
			add_contextual_help($_page_hook, $text);
		}
	}

	/** 
	 * Add WP-DB-Backup-specific help options to the 2.7 =< WP contextual help menu
	 * return string The text of the help menu.
	 */
	function help_menu() {
		$text = "\n<a href=\"http://wordpress.org/extend/plugins/wp-db-backup/faq/\" target=\"_blank\">" . __('FAQ', 'wp-db-backup') . '</a>';
		$text .= "\n<br />\n<a href=\"http://www.ilfilosofo.com/forum/forum/2\" target=\"_blank\">" . __('WP-DB-Backup Support Forum', 'wp-db-backup') . '</a>';
		return $text;
	}

	function save_backup_time() {
		if ( $this->current_user_can_backup() ) {
			if ( ! wp_verify_nonce($_POST['_wpnonce'], $this->referer_check_key) ) {
				$this->log_error(array('loc' => 'main', 'kind' => 'fatal', 'msg' => sprintf(__('There appears to be an unauthorized attempt from this site to access your database located at %1s.  The attempt has been halted.','wp-db-backup'),get_option('home'))));
			}
			// try to get a time from the input string
			$time = strtotime(strval($_POST['backup-time']));
			if ( ! empty( $time ) && time() < $time ) {
				wp_clear_scheduled_hook( 'wp_db_backup_cron' ); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = get_option('wp_cron_backup_schedule');
				if ( 0 != $time ) {
					wp_schedule_event($time, $name, 'wp_db_backup_cron');
					echo gmdate(get_option('date_format') . ' ' . get_option('time_format'), $time + (get_option('gmt_offset') * 3600));
					exit;
				}
			}
		} else {
			die(0);
		}
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 */
	function sql_addslashes($a_string = '', $is_like = false) {
		if ( $is_like ) 
			$a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else 
			$a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	} 

	function open($filename = '', $mode = 'w') {
		if ('' == $filename) return false;
		if ($this->has_gz()) {
			$fp = @gzopen($filename, $mode);
		} else {
			$fp = @fopen($filename, $mode);
		}
		return $fp;
	}

	function close($fp) {
		if ($this->has_gz()) {
			gzclose($fp);
		} else {
			fclose($fp);
		}
	}

	function has_gz() {
		return function_exists('gzencode');
	}

	/**
	 * Write to the backup file
	 * @param string $query_line the line to write
	 * @return null
	 */
	function stow($query_line) {
		if(false === @fwrite($this->fp, $query_line))
			$this->log_error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
	}
	
	/**
	 * Logs any error messages
	 * @param array $args
	 * @return bool
	 */
	function log_error($args = array()) {
		if ( is_string( $args ) ) 
			$args = array('msg' => $args);
		$args = array_merge( array('loc' => 'main', 'kind' => 'warn', 'msg' => ''), $args);
		$this->errors[$args['kind']][] = $args['msg'];
		if ( 'fatal' == $args['kind'] || 'frame' == $args['loc'])
			$this->print_error_messages($args['loc']);
		return true;
	}

	/**
	 * Displays error messages 
	 * @param array $errs
	 * @param string $loc
	 * @return string
	 */
	function print_error_messages($loc = 'main', $echo = true) {
		$errs = $this->errors;
		$err_list = array();
		unset( $this->errors );
		if ( ! count($errs) ) return;
		$msg = '';
		if ( isset($errs) && isset($errs['fatal']) ) {
			$err_list = array_merge($err_list, (array) $errs['fatal']);
		}
		if ( isset($errs) && isset($errs['warn']) ) {
			$err_list = array_merge($err_list, (array) $errs['warn']);
		}
		$err_list = array_slice($err_list, 0, 10);
		if ( 10 == count( $err_list ) )
			$err_list[9] = __('Subsequent errors have been omitted from this log.','wp-db-backup');
		$wrap = ( 'frame' == $loc ) ? "<script type=\"text/javascript\">\n var msgList = ''; \n %1\$s \n if ( msgList ) alert(msgList); \n </script>" : '%1$s';
		$line = ( 'frame' == $loc ) ? 
			"try{ window.parent.addError('%1\$s'); } catch(e) { msgList += ' %1\$s';}\n" :
			"%1\$s<br />\n";
		foreach( (array) $err_list as $err )
			$msg .= sprintf($line,str_replace(array("\n","\r"), '', addslashes($err)));
		$msg = sprintf($wrap,$msg);
		if ( count($errs['fatal'] ) ) {
			if ( function_exists('wp_die') && 'frame' != $loc ) wp_die(stripslashes($msg));
			else die($msg);
		}
		else {
			if ( $echo ) echo $msg;
			else return $msg;
		}
	}

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			$this->log_error(__('Error getting table details','wp-db-backup') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				$this->log_error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
				$this->log_error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# ' . sprintf(__('Data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			
			// Batch by $row_inc
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = $this->_rows_per_segment;
			} else {
				$row_start = $segment * $this->_rows_per_segment;
				$row_inc = $this->_rows_per_segment;
			}
			
			do {	
				// don't include extra stuff, if so requested
				$excs = (array) get_option('wp_db_backup_excs');
				$where = '';
				if ( is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) {
					$where = ' WHERE comment_approved != "spam"';
				} elseif ( is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) {
					$where = ' WHERE post_type != "revision"';
				}
				
				if ( !ini_get('safe_mode')) @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);

				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$values = array();
						foreach ($row as $key => $value) {
							if ( isset($ints[strtolower($key)]) && $ints[strtolower($key)]) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ');');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
	} // end backup_table()
	
	/**
	 * Create the backup file.
	 *
	 * @param array $tables The database tables to backup.
	 * @return mixed. String of database file path or false if failure.
	 */
	function do_db_backup($tables = array())
	{
		global $table_prefix, $wpdb;
		
		if (is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $this->backup_filename);
			if(!$this->fp) {
				$this->log_error(__('Could not open the backup file for writing!','wp-db-backup'));
				return false;
			}
		} else {
			$this->log_error(__('The backup directory is not writeable!','wp-db-backup'));
			return false;
		}
		
		//Begin new backup of MySql
		$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),gmdate("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");
		
		foreach ($tables as $table) {
			// Increase script execution time-limit to 15 min for every table.
			if ( !ini_get('safe_mode')) @set_time_limit(15*60);
			// Create the SQL statements
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->backup_table($table);
		}
				
		$this->close($this->fp);
		
		if (count($this->errors)) {
			return false;
		} else {
			return $this->backup_filename;
		}
		
	} // do_db_backup

	/**
	 * Prepares and sends the backed-up file via email
	 *
	 * @param string $to The recipient of the email.
	 * @param string $subject The subject of the email.
	 * @param string $message The text message for the backup email.
	 * @param string $diskfile The path to the backup file.
	 * @return bool
	 */
	function prepare_mail( $to, $subject, $message, $diskfile)
	{
		global $phpmailer;

		extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message' ) ) );

		// try to use phpmailer directly (WP 2.2+)
		if ( 
			! class_exists('PHPMailer') ||
			! is_object( $phpmailer ) || 
			! is_a( $phpmailer, 'PHPMailer' ) 
		) {
			if ( file_exists( ABSPATH . WPINC . '/class-phpmailer.php' ) )
				require_once ABSPATH . WPINC . '/class-phpmailer.php';
			if ( file_exists( ABSPATH . WPINC . '/class-smtp.php' ) )
				require_once ABSPATH . WPINC . '/class-smtp.php';
			if ( class_exists( 'PHPMailer') ) {
				$phpmailer = new PHPMailer;

			}
		}

		if ( is_a($phpmailer, 'PHPMailer') ) {
			return $this->send_mail($phpmailer, $to, $subject, $message, $diskfile);
		}

		return false;
	}

	/**
	 * Sends the backed-up file via email
	 *
	 * @param object $phpmailer The PHPMailer object.
	 * @param string $to The recipient of the email.
	 * @param string $subject The subject of the email.
	 * @param string $message The text message for the backup email.
	 * @param string $diskfile The path to the backup file.
	 * @return bool
	 */
	function send_mail($phpmailer = null, $to = '', $subject = '', $message = '', $diskfile)
	{
		$result = false;

		if ( 
			empty( $diskfile ) ||
			empty( $to )
		) {
			return false;
		}
	
		if ( is_a($phpmailer, 'PHPMailer') ) {
			
			// Get the site domain and get rid of www.
			$sitename = strtolower( $_SERVER['SERVER_NAME'] );
			if ( substr( $sitename, 0, 4 ) == 'www.' ) {
				$sitename = substr( $sitename, 4 );
			}
			$from_email = 'wordpress@' . $sitename;
			$from_name = 'WordPress';

			// Empty out the values that may be set
			$phpmailer->ClearAddresses();
			$phpmailer->ClearAllRecipients();
			$phpmailer->ClearAttachments();
			$phpmailer->ClearBCCs();
			$phpmailer->ClearCCs();
			$phpmailer->ClearCustomHeaders();
			$phpmailer->ClearReplyTos();

			$phpmailer->AddAddress( $to );
			$phpmailer->AddAttachment($diskfile, basename($diskfile));
			$phpmailer->Body = $message;
			$phpmailer->CharSet = apply_filters( 'wp_mail_charset', get_bloginfo('charset') );
			$phpmailer->From = apply_filters( 'wp_mail_from', $from_email );
			$phpmailer->FromName = apply_filters( 'wp_mail_from_name', $from_name );
			$phpmailer->IsMail();
			$phpmailer->Subject = $subject;

			$result = @$phpmailer->Send();

		}
		return $result;

	}

	function deliver_backup($filename = '', $delivery = 'http', $recipient = '', $location = 'main') {
		if ( empty( $filename ) ) { 
			return false; 
		}

		$diskfile = $this->backup_dir . $filename;

		if ('http' == $delivery) {
			if (! file_exists($diskfile)) 
				$this->log_error(array('kind' => 'fatal', 'msg' => sprintf(__('File not found:%s','wp-db-backup'), "&nbsp;<strong>$filename</strong><br />") . '<br /><a href="' . $this->page_url . '">' . __('Return to Backup','wp-db-backup') . '</a>'));
			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Length: ' . filesize($diskfile));
			header("Content-Disposition: attachment; filename=$filename");
			$success = readfile($diskfile);
			unlink($diskfile);
		} elseif ('smtp' == $delivery) {
			if (! file_exists($diskfile)) {
				$msg = sprintf(__('File %s does not exist!','wp-db-backup'), $diskfile);
				$this->log_error($msg);
				return false;
			}
			if (! is_email($recipient)) {
				$recipient = get_option('admin_email');
			}
			$message = sprintf(__("Attached to this email is\n   %1s\n   Size:%2s kilobytes\n",'wp-db-backup'), $filename, round(filesize($diskfile)/1024));
			$success = $this->prepare_mail($recipient, get_bloginfo('name') . ' ' . __('Database Backup','wp-db-backup'), $message, $diskfile);

			if ( false === $success ) {
				$msg = __('The following errors were reported:','wp-db-backup') . "\n ";
				if ( function_exists('error_get_last') ) {
					$err = error_get_last();
					$msg .= $err['message'];
				} else {
					$msg .= __('ERROR: The mail application has failed to deliver the backup.','wp-db-backup'); 
				}
				$this->log_error(array('kind' => 'fatal', 'loc' => $location, 'msg' => $msg));
			} else {
				unlink($diskfile);
			}
		}
		return $success;
	}
	
	function print_backup_menu() {
		global $table_prefix, $wpdb, $wp_filesystem;
		$feedback = '';
		$whoops = false;
		
		// did we just do a backup?  If so, let's report the status
		if ( $this->backup_complete ) {
			$feedback = '<div class="updated wp-db-backup-updated"><p>' . __('Backup Successful','wp-db-backup') . '!';
			$file = $this->backup_file;
			switch($_POST['deliver']) {
			case 'http':
				$feedback .= '<br />' . sprintf(__('Your backup file: <a href="%1s">%2s</a> should begin downloading shortly.','wp-db-backup'), WP_BACKUP_URL . "{$this->backup_file}", $this->backup_file);
				break;
			case 'smtp':
				if (! is_email($_POST['backup_recipient'])) {
					$feedback .= get_option('admin_email');
				} else {
					$feedback .= $_POST['backup_recipient'];
				}
				$feedback = '<br />' . sprintf(__('Your backup has been emailed to %s','wp-db-backup'), $feedback);
				break;
			case 'none':
				$feedback .= '<br />' . __('Your backup file has been saved on the server. If you would like to download it now, right click and select "Save As"','wp-db-backup');
				$feedback .= ':<br /> <a href="' . WP_BACKUP_URL . "$file\">$file</a> : " . sprintf(__('%s bytes','wp-db-backup'), filesize($this->backup_dir . $file));
			}
			$feedback .= '</p></div>';
		}
	
		// security check
		if ( ! $this->is_wp_secure_enough() ) {
			$this->log_error(array('kind' => $kind, 'loc' => $loc, 'msg' => sprintf(__('Your WordPress version, %1s, lacks important security features without which it is unsafe to use the WP-DB-Backup plugin.  Hence, this plugin is automatically disabled.  Please consider <a href="%2s">upgrading WordPress</a> to a more recent version.','wp-db-backup'),$GLOBALS['wp_version'],'http://wordpress.org/download/')));
		}

		if (count($this->errors)) {
			$feedback .= '<div class="updated wp-db-backup-updated error"><p><strong>' . __('The following errors were reported:','wp-db-backup') . '</strong></p>';
			$feedback .= '<p>' . $this->print_error_messages( 'main', false ) . '</p>';
			$feedback .= "</p></div>";
		}

		// did we just save options for wp-cron?
		if ( (function_exists('wp_schedule_event') || function_exists('wp_cron_init')) 
			&& isset($_POST['wp_cron_backup_options']) ) :
			do_action('wp_db_b_update_cron_options');
			if ( function_exists('wp_schedule_event') ) {
				wp_clear_scheduled_hook( 'wp_db_backup_cron' ); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = strval($_POST['wp_cron_schedule']);
				$interval = ( isset($scheds[$name]['interval']) ) ? 
					(int) $scheds[$name]['interval'] : 0;
				update_option('wp_cron_backup_schedule', $name, false);
				if ( 0 !== $interval ) {
					wp_schedule_event(time() + $interval, $name, 'wp_db_backup_cron');
				}
			}
			else {
				update_option('wp_cron_backup_schedule', intval($_POST['cron_schedule']), false);
			}
			update_option('wp_cron_backup_tables', $_POST['wp_cron_backup_tables']);
			if (is_email($_POST['cron_backup_recipient'])) {
				update_option('wp_cron_backup_recipient', $_POST['cron_backup_recipient'], false);
			}
			$feedback .= '<div class="updated wp-db-backup-updated"><p>' . __('Scheduled Backup Options Saved!','wp-db-backup') . '</p></div>';
		endif;
		
		$other_tables = array();
		$also_backup = array();
	
		// Get complete db table list	
		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		// Get list of WP tables that actually exist in this DB (for 1.6 compat!)
		$wp_backup_default_tables = array_intersect($all_tables, $this->_get_core_table_names());
		// Get list of non-WP tables
		$other_tables = array_diff($all_tables, $wp_backup_default_tables);
		
		if ('' != $feedback)
			echo $feedback;

		if ( ! $this->is_wp_secure_enough() ) 	
			return false;

		// Give the new dirs the same perms as wp-content.
//		$stat = stat( ABSPATH . 'wp-content' );
//		$dir_perms = $stat['mode'] & 0000777; // Get the permission bits.
		$dir_perms = '0777';

		// if filesystem ftp available and trying to do that
		if ( function_exists( 'request_filesystem_credentials' ) && ! empty( $_POST['ftp-nonce'] ) ) :
			if ( $this->current_user_can_backup() && wp_verify_nonce( $_POST['ftp-nonce'], 'ftp-change' ) ) :
				ob_start();
				WP_Filesystem(@request_filesystem_credentials(), false);
				$ftp_backup_dir = trailingslashit($wp_filesystem->wp_content_dir()) . basename($this->backup_dir);
				ob_end_clean();
				if ( isset($wp_filesystem) ) :
					if ( is_callable(array($wp_filesystem, 'mkdir')) ) :
						if ( @$wp_filesystem->mkdir($ftp_backup_dir, 0777) && file_exists($this->backup_dir) ) :
							?><div class="updated wp-db-backup-updated"><p><?php _e('WordPress has successfully created the backup directory.', 'wp-db-backup'); ?></p></div><?php 
						else :
							?><div class="updated wp-db-backup-updated error"><p><?php _e('WordPress has failed to create the backup directory.', 'wp-db-backup'); ?></p></div><?php 
						endif; // mkdir returns true
					endif; // wp_filesystem->mkdir is callable
					
					if ( is_callable(array($wp_filesystem, 'chmod')) && ! is_writable($this->backup_dir) && file_exists($this->backup_dir) && @$wp_filesystem->chmod($ftp_backup_dir, 0777) ) :
						if ( is_writable($this->backup_dir) ) :
							?><div class="updated wp-db-backup-updated"><p><?php _e('WordPress has successfully made the backup directory writable.', 'wp-db-backup'); ?></p></div><?php 
						else :
							?><div class="updated wp-db-backup-updated error"><p><?php _e('WordPress has failed to make the backup directory writable.', 'wp-db-backup'); ?></p></div><?php 
						endif; // the backup directory now is writable
					endif; // wp_filesystem->chmod is callable and the directory is not writable
				endif;
			else :	
				?><div class="updated wp-db-backup-updated error"><p><?php _e('ERROR: Your attempt to create the backup directory has failed a permission check.', 'wp-db-backup'); ?></p></div><?php 
			endif;
		endif;

		// the file doesn't exist and can't create it
		if ( ! file_exists($this->backup_dir) && ! @mkdir($this->backup_dir) ) {
			?><div class="updated wp-db-backup-updated error"><p><?php _e('WARNING: Your backup directory does <strong>NOT</strong> exist, and we cannot create it automatically.','wp-db-backup'); ?></p>
			<p><?php printf(__('Using your FTP client, try to create the backup directory yourself: %s', 'wp-db-backup'), '<code>' . $this->backup_dir . '</code>'); ?></p>
			
			<?php 

			// if filesystem ftp available, let's suggest that:
			if ( function_exists( 'request_filesystem_credentials' ) ) :
				?><p><?php _e('Or you can put your FTP information for this site in the form below and let WordPress attempt to create the backup directory for you.', 'wp-db-backup'); ?></p>
				<?php 
				$nonce = function_exists('wp_nonce_field') ? 
					wp_nonce_field($this->referer_check_key, '_wpnonce', true, false) . wp_nonce_field('ftp-change', 'ftp-nonce') :
					'';
				@ob_start();
				request_filesystem_credentials('');
				$ftp_form = ob_get_clean();
				$ftp_form = str_replace('h2', 'h3', $ftp_form);
				$ftp_form = str_replace('class="wrap"', '', $ftp_form);
				$ftp_form = str_replace('</form>', "\n$nonce\n</form>", $ftp_form);
			endif;
			?></div><?php

			$whoops = true;
		// not writable due to write permissions
		} elseif ( !is_writable($this->backup_dir) && ! @chmod($this->backup_dir, $dir_perms) ) {
			?><div class="updated wp-db-backup-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable! We cannot create the backup files.','wp-db-backup'); ?></p>
			<p><?php printf(__('Using your FTP client, try to set the backup directory&rsquo;s write permission to %1$s or %2$s: %3$s', 'wp-db-backup'), '<code>777</code>', '<code>a+w</code>', '<code>' . $this->backup_dir . '</code>'); ?>
			</p></div><?php 
			$whoops = true;
		} else {
			$this->fp = $this->open($this->backup_dir . 'test' );
			if( $this->fp ) { 
				$this->close($this->fp);
				@unlink($this->backup_dir . 'test' );
			// the directory is not writable probably due to safe mode
			} else {
				?><div class="updated wp-db-backup-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable! We cannot create the backup files.','wp-db-backup'); ?></p><?php 
				if( ini_get('safe_mode') ){
					?><p><?php _e('This problem seems to be caused by your server&rsquo;s <code>safe_mode</code> file ownership restrictions, which limit what files web applications like WordPress can create.', 'wp-db-backup'); ?></p><?php 
				}
				?><?php printf(__('You can try to correct this problem by using your FTP client to delete and then re-create the backup directory: %s', 'wp-db-backup'), '<code>' . $this->backup_dir . '</code>');
				?></div><?php 
				$whoops = true;
			}
		}

		

		if ( !file_exists($this->backup_dir . 'index.php') )
			@ touch($this->backup_dir . 'index.php');
		?><div class='wrap'>
		<h2><?php _e('Backup','wp-db-backup') ?></h2>
		<?php if ( ! empty( $ftp_form ) ) :
			echo $ftp_form;
		endif; ?>
		<form method="post" action="">
		<?php if ( function_exists('wp_nonce_field') ) wp_nonce_field($this->referer_check_key); ?>
		<fieldset class="options"><legend><?php _e('Tables','wp-db-backup') ?></legend>
		<div class="tables-list core-tables alternate">
		<h4><?php _e('These core WordPress tables will always be backed up:','wp-db-backup') ?></h4><ul><?php
		$excs = (array) get_option('wp_db_backup_excs');
		foreach ($wp_backup_default_tables as $table) {
			if ( $table == $wpdb->comments ) {
				$checked = ( isset($excs['spam']) && is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) ? ' checked=\'checked\'' : '';
				echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code> <span class='instructions'> <input type='checkbox' name='exclude-spam[]' value='$table' $checked /> " . __('Exclude spam comments', 'wp-db-backup') . '</span></li>';
			} elseif ( function_exists('wp_get_post_revisions') && $table == $wpdb->posts ) {
					$checked = ( isset($excs['revisions']) && is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) ? ' checked=\'checked\'' : '';
				echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code> <span class='instructions'> <input type='checkbox' name='exclude-revisions[]' value='$table' $checked /> " . __('Exclude post revisions', 'wp-db-backup') . '</span></li>';
			} else {
				echo "<li><input type='hidden' name='core_tables[]' value='$table' /><code>$table</code></li>";
			}
		}
		?></ul>
		</div>
		<div class="tables-list extra-tables" id="extra-tables-list">
		<?php 
		if (count($other_tables) > 0) { 
			?>
			<h4><?php _e('You may choose to include any of the following tables:','wp-db-backup'); ?></h4>
			<ul>
			<?php
			foreach ($other_tables as $table) {
				?>
				<li><label><input type="checkbox" name="other_tables[]" value="<?php echo $table; ?>" /> <code><?php echo $table; ?></code></label>
				<?php 
			}
			?></ul><?php 
		}
		?></div>
		</fieldset>
		
		<fieldset class="options">
			<legend><?php _e('Backup Options','wp-db-backup'); ?></legend>
			<p><?php  _e('What to do with the backup file:','wp-db-backup'); ?></p>
			<ul>
			<li><label for="do_save">
				<input type="radio" id="do_save" name="deliver" value="none" style="border:none;" />
				<?php _e('Save to server','wp-db-backup'); 
				echo " (<code>" . $this->backup_dir . "</code>)"; ?>
			</label></li>
			<li><label for="do_download">
				<input type="radio" checked="checked" id="do_download" name="deliver" value="http" style="border:none;" />
				<?php _e('Download to your computer','wp-db-backup'); ?>
			</label></li>
			<li><label for="do_email">
				<input type="radio" name="deliver" id="do_email" value="smtp" style="border:none;" />
				<?php _e('Email backup to:','wp-db-backup'); ?>
				<input type="text" name="backup_recipient" size="20" value="<?php echo get_option('admin_email'); ?>" />
			</label></li>
			</ul>
			<?php if ( ! $whoops ) : ?>
			<input type="hidden" name="do_backup" id="do_backup" value="backup" /> 
			<p class="submit">
				<input type="submit" name="submit" onclick="document.getElementById('do_backup').value='fragments';" value="<?php _e('Backup now!','wp-db-backup'); ?>" />
			</p>
			<?php else : ?>
				<p class="error"><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable!','wp-db-backup'); ?></p>
			<?php endif; // ! whoops ?>
		</fieldset>
		<?php do_action('wp_db_b_backup_opts'); ?>
		</form>
		
		<?php
		// this stuff only displays if some sort of wp-cron is available 
		$cron = ( function_exists('wp_schedule_event') ) ? true : false; // wp-cron in WP 2.1+
		$cron_old = ( function_exists('wp_cron_init') && ! $cron ) ? true : false; // wp-cron plugin by Skippy
		if ( $cron_old || $cron ) :
			echo '<fieldset class="options"><legend>' . __('Scheduled Backup','wp-db-backup') . '</legend>';
			$datetime = get_option('date_format') . ' ' . get_option('time_format');
			if ( $cron ) :
				$next_cron = wp_next_scheduled('wp_db_backup_cron');
				if ( ! empty( $next_cron ) ) :
					?>
					<p id="backup-time-wrap">
					<?php printf(__('Next Backup: %s','wp-db-backup'), '<span id="next-backup-time">' . gmdate($datetime, $next_cron + (get_option('gmt_offset') * 3600)) . '</span>'); ?>
					</p>
					<?php 
				endif;
			elseif ( $cron_old ) :
				?><p><?php printf(__('Last WP-Cron Daily Execution: %s','wp-db-backup'), gmdate($datetime, get_option('wp_cron_daily_lastrun') + (get_option('gmt_offset') * 3600))); ?><br /><?php 
				printf(__('Next WP-Cron Daily Execution: %s','wp-db-backup'), gmdate($datetime, (get_option('wp_cron_daily_lastrun') + (get_option('gmt_offset') * 3600) + 86400))); ?></p><?php 
			endif;
			?><form method="post" action="">
			<?php if ( function_exists('wp_nonce_field') ) wp_nonce_field($this->referer_check_key); ?>
			<div class="tables-list">
			<h4><?php _e('Schedule: ','wp-db-backup'); ?></h4>
			<?php 
			if ( $cron_old ) :
				$wp_cron_backup_schedule = get_option('wp_cron_backup_schedule');
				$schedule = array(0 => __('None','wp-db-backup'), 1 => __('Daily','wp-db-backup'));
				foreach ($schedule as $value => $name) {
					echo ' <input type="radio" style="border:none;" name="cron_schedule"';
					if ($wp_cron_backup_schedule == $value) {
						echo ' checked="checked" ';
					}
					echo 'value="' . $value . '" /> ' . $name;
				}
			elseif ( $cron ) :
				echo apply_filters('wp_db_b_schedule_choices', wp_get_schedules() );
			endif;
			$cron_recipient = get_option('wp_cron_backup_recipient');
			if (! is_email($cron_recipient)) {
				$cron_recipient = get_option('admin_email');
			}
			$cron_recipient_input = '<p><label for="cron_backup_recipient">' . __('Email backup to:','wp-db-backup') . ' <input type="text" name="cron_backup_recipient" id="cron_backup_recipient" size="20" value="' . $cron_recipient . '" /></label></p>';
			echo apply_filters('wp_db_b_cron_recipient_input', $cron_recipient_input);
			if ( ! $whoops ) {
				echo '<p class="submit"><input type="submit" name="submit" value="' . __('Schedule backup','wp-db-backup') . '" /></p>';
			} else {
				echo '<p class="error">' . __('WARNING: Your backup directory is <strong>NOT</strong> writable!','wp-db-backup') . '</p>';
			}
			echo '</div>';
			$cron_tables = get_option('wp_cron_backup_tables');
			if (! is_array($cron_tables)) {
				$cron_tables = array();
			}
			if (count($other_tables) > 0) {
				echo '<div class="tables-list alternate" id="include-tables-list">';
				echo '<h4>' . __('Tables to include in the scheduled backup:','wp-db-backup') . '</h4><ul>';
				foreach ($other_tables as $table) {
					echo '<li><input type="checkbox" ';
					if (in_array($table, $cron_tables)) {
						echo 'checked="checked" ';
					}
					echo "name='wp_cron_backup_tables[]' value='{$table}' /> <code>{$table}</code></li>";
				}
				echo '</ul></div>';
			}
			echo '<input type="hidden" name="wp_cron_backup_options" value="SET" /></form>';
			echo '</fieldset>';
		endif; // end of wp_cron (legacy) section
		
		echo '</div><!-- .wrap -->';
		
	} // end print_backup_menu()

	function get_sched() {
		$options = array_keys( (array) wp_get_schedules() );
		$freq = get_option('wp_cron_backup_schedule'); 
		$freq = ( in_array( $freq , $options ) ) ? $freq : 'never';
		return $freq;
	}

	function schedule_choices($schedule) { // create the cron menu based on the schedule
		$wp_cron_backup_schedule = $this->get_sched();
		$next_cron = wp_next_scheduled('wp_db_backup_cron');
		$wp_cron_backup_schedule = ( empty( $next_cron ) ) ? 'never' : $wp_cron_backup_schedule;
		$sort = array();
		foreach ( (array) $schedule as $key => $value ) $sort[$key] = $value['interval'];
		asort( $sort );
		$schedule_sorted = array();
		foreach ( (array) $sort as $key => $value ) $schedule_sorted[$key] = $schedule[$key];
		$menu = '<ul>';
		$schedule = array_merge( array( 'never' => array( 'interval' => 0, 'display' => __('Never','wp-db-backup') ) ),
			(array) $schedule_sorted );
		foreach ( $schedule as $name => $settings) {
			$interval = (int) $settings['interval'];
			if ( 0 == $interval && ! 'never' == $name ) continue;
			$display = ( ! '' == $settings['display'] ) ? $settings['display'] : sprintf(__('%s seconds','wp-db-backup'),$interval);
			$menu .= "<li><input type='radio' name='wp_cron_schedule' style='border:none;' ";
			if ($wp_cron_backup_schedule == $name) {
				$menu .= " checked='checked' ";
			}
			$menu .= "value='$name' /> $display</li>";
		}
		$menu .= '</ul>';
		return $menu;
	} // end schedule_choices()
	
	/**
	 * Do a cron-spawned backup
	 */
	function do_cron_backup() {
		global $wpdb;

		$all_tables = (array) $wpdb->get_col('SHOW TABLES');
		$core_tables = array_intersect($all_tables, $this->_get_core_table_names());
		$other_tables = (array) get_option('wp_cron_backup_tables');
		$recipient = get_option('wp_cron_backup_recipient');
		$tables = array_filter(array_intersect($all_tables, array_merge($core_tables, $other_tables)));

		$backup_file = $this->do_db_backup($tables);

		if (false !== $backup_file) 
			return $this->deliver_backup($backup_file, 'smtp', $recipient, 'main');
		else 
			return false;
	}

	function add_cron_sched_options($sched = array()) {
		$sched['weekly'] = array('interval' => 604800, 'display' => __('Once Weekly','wp-db-backup'));
		return $sched;
	}

	/**
	 * Check whether WordPress has sufficient security measures to use the plugin.
	 * Generally, the problem is that without the nonce system introduced in 2.0.3, 
	 * this plugin is more likely to be vulnerable to surreptitious attacks (XSS and the like).
	 *
	 * @return bool Whether WP has sufficient features to use this plugin securely.
	 */
	function is_wp_secure_enough($kind = 'warn', $loc = 'main') 
	{
		if ( function_exists('wp_verify_nonce') )
			return true;
		else
			return false;
	}

	/**
	 * Checks that the user has sufficient permission to backup
	 *
	 * @return bool Whether the current user has sufficient permission to backup.
	 */
	function current_user_can_backup()
	{
		$can = false;
		// make sure WPMU users are site admins, not ordinary admins
		if ( function_exists('is_site_admin') && ! is_site_admin() )
			return false;
		if ( $this->is_wp_secure_enough() && current_user_can('import') )
			$can = true;
		return $can;
	}
}

function wp_db_backup_init() {
	new WP_DB_Backup; 	
}

add_action('plugins_loaded', 'wp_db_backup_init');
