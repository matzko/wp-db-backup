<?php
/*
Plugin Name: WordPress Database Backup
Plugin URI: https://github.com/matzko/wp-db-backup
Description: On-demand backup of your WordPress database. Navigate to <a href="edit.php?page=wp-db-backup">Tools &rarr; Backup</a> to get started.
Author: Austin Matzko
Author URI: http://austinmatzko.com/
Version: 2.3.3

Copyright 2018  Austin Matzko  (email : austin at pressedcode.com)

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

if ( ! defined('ABSPATH') ) {
	die('Please do not load this file directly.');
}

$rand = substr( md5( md5( DB_PASSWORD ) ), -5 );
global $wpdbb_content_dir, $wpdbb_content_url;
$wpdbb_content_dir = ( defined('WP_CONTENT_DIR') ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
$wpdbb_content_url = ( defined('WP_CONTENT_URL') ) ? WP_CONTENT_URL : get_option('siteurl') . '/wp-content';

if ( ! defined('ROWS_PER_SEGMENT') ) {
	define('ROWS_PER_SEGMENT', 100);
}

/**
 * Set MOD_EVASIVE_OVERRIDE to true
 * and increase MOD_EVASIVE_DELAY
 * if the backup stops prematurely.
 */
// define('MOD_EVASIVE_OVERRIDE', false);
if ( ! defined('MOD_EVASIVE_DELAY') ) {
	define('MOD_EVASIVE_DELAY', '500');
}

class wpdbBackup {

	var $backup_complete = false;
	var $backup_file = '';
	var $backup_filename;
	var $core_table_names = array();
	var $errors = array();
	var $basename;
	var $page_url;
	var $referer_check_key;
	var $version = '2.3.3';

	function module_check() {
		$mod_evasive = false;
		if ( defined( 'MOD_EVASIVE_OVERRIDE' ) && true === MOD_EVASIVE_OVERRIDE ) return true;
		if ( ! defined( 'MOD_EVASIVE_OVERRIDE' ) || false === MOD_EVASIVE_OVERRIDE ) return false;
		if ( function_exists('apache_get_modules') )
			foreach( (array) apache_get_modules() as $mod )
				if ( false !== strpos($mod,'mod_evasive') || false !== strpos($mod,'mod_dosevasive') )
					return true;
		return false;
	}

	function __construct() {
		global $table_prefix, $wpdb;
		add_action('wp_ajax_save_backup_time', array(&$this, 'save_backup_time'));
		add_action('init', array(&$this, 'init_textdomain'));
		add_action('init', array(&$this, 'set_page_url'));
		add_action('admin_init', array(&$this, 'update_notice_action'));
		add_action('wp_db_backup_cron', array(&$this, 'cron_backup'));
		add_action('wp_cron_daily', array(&$this, 'wp_cron_daily'));
		add_filter('cron_schedules', array(&$this, 'add_sched_options'));
		add_filter('wp_db_b_schedule_choices', array(&$this, 'schedule_choices'));

		$table_prefix = ( isset( $table_prefix ) ) ? $table_prefix : $wpdb->prefix;
		$datum = date("Ymd_B");
		$this->backup_filename = DB_NAME . "_$table_prefix$datum.sql";

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
				$this->core_table_names[] = $wpdb->{$name};
			}
		}

		$requested_temp_dir = sanitize_text_field($_GET['wp_db_temp_dir']);
		$this->backup_dir = trailingslashit(apply_filters('wp_db_b_backup_dir', (isset($requested_temp_dir) && is_writable($requested_temp_dir)) ? $requested_temp_dir : get_temp_dir()));
		$this->basename = 'wp-db-backup';

		$this->referer_check_key = $this->basename . '-download_' . DB_NAME;
		if (isset($_POST['do_backup'])) {
			$this->wp_secure('fatal');
			check_admin_referer($this->referer_check_key);
			$this->can_user_backup('main');

			// save exclude prefs
			update_option('wp_db_backup_excs', array(
				'revisions' => $this->get_revisions_to_exclude(),
				'spam' => $this->get_spam_to_exclude()
			));
			switch($_POST['do_backup']) {
			case 'backup':
				add_action('init', array(&$this, 'perform_backup'));
				break;
			case 'fragments':
				add_action('admin_menu', array(&$this, 'fragment_menu'));
				break;
			}
		} elseif (isset($_GET['fragment'] )) {
			$this->can_user_backup('frame');
			add_action('init', array(&$this, 'init'));
		} elseif (isset($_GET['backup'] )) {
			$this->can_user_backup();
			add_action('init', array(&$this, 'init'));
		} else {
			add_action('admin_menu', array(&$this, 'admin_menu'));
		}
	}

	function init() {
		$this->can_user_backup();
		if (isset($_GET['backup'])) {
			$via = isset($_GET['via']) ? sanitize_text_field($_GET['via']) : 'http';

			$this->backup_file = sanitize_text_field($_GET['backup']);
			$this->validate_file($this->backup_file);

			switch($via) {
			case 'smtp':
			case 'email':
				$success = $this->deliver_backup($this->backup_file, 'smtp', sanitize_text_field($_GET['recipient']), 'frame');
				$this->error_display( 'frame' );
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
				$success = $this->deliver_backup($this->backup_file, $via);
				echo $this->error_display( 'frame', false );

				if ( $success ) {
					echo '
					<script type="text/javascript">
						window.parent.setProgress("' . __('Backup Complete!','wp-db-backup') . '");
					</script>
					';
				}
			}
			exit;
		}
		if (isset($_GET['fragment'] )) {
			list($table, $segment, $filename) = explode(':', sanitize_text_field($_GET['fragment']));
			$this->validate_file($filename);
			$this->backup_fragment($table, $segment, $filename);
		}

		die();
	}

	function init_textdomain() {
		load_plugin_textdomain('wp-db-backup', str_replace(ABSPATH, '', dirname(__FILE__)), dirname(plugin_basename(__FILE__)));
	}

	function set_page_url() {
		$query_args = array( 'page' => $this->basename );
		if ( function_exists('wp_create_nonce') )
			$query_args = array_merge( $query_args, array('_wpnonce' => wp_create_nonce($this->referer_check_key)) );
		$base = ( function_exists('site_url') ) ? site_url('', 'admin') : get_option('siteurl');
		$this->page_url = add_query_arg( $query_args, $base . '/wp-admin/edit.php');
	}

	/*
	 * Add a link to back up your database when doing a core upgrade.
	 */
	function update_notice_action() {
		global $pagenow;
		if (
			(
				isset($_REQUEST['action'])
				&& ('upgrade-core' == $_REQUEST['action'])
			)
			|| (
				!empty($pagenow) && ('update-core.php' == $pagenow)
			)
		) :
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

	function build_backup_script() {
		global $table_prefix, $wpdb;

		echo "<div class='wrap'>";
		echo '<fieldset class="options"><legend>' . __('Progress','wp-db-backup') . '</legend>
			<p><strong>' .
				__('DO NOT DO THE FOLLOWING AS IT WILL CAUSE YOUR BACKUP TO FAIL:','wp-db-backup').
			'</strong></p>
			<ol>
				<li>'.__('Close this browser','wp-db-backup').'</li>
				<li>'.__('Reload this page','wp-db-backup').'</li>
				<li>'.__('Click the Stop or Back buttons in your browser','wp-db-backup').'</li>
			</ol>
			<p><strong>' . __('Progress:','wp-db-backup') . '</strong></p>
			<div id="meterbox" style="height:11px;width:80%;padding:3px;border:1px solid #659fff;"><div id="meter" style="color:#fff;height:11px;line-height:11px;background-color:#659fff;width:0%;text-align:center;font-size:6pt;">&nbsp;</div></div>
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
				fram.src = "' . $this->page_url . '&fragment=" + table + ":" + segment + ":' . $this->backup_filename . ':&wp_db_temp_dir=' . $this->backup_dir . '";
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
				setProgress("' . __('Preparing download.','wp-db-backup') . '");
				window.onbeforeunload = null;
				fram.src = "' . $download_uri . '";

				setTimeout( function() {
					var secondFrame = document.createElement("iframe");
					fram.parentNode.insertBefore(secondFrame, fram);
					secondFrame.src = "' . $download_uri . '&download-retry=1";
				}, 30000 );
			';
			break;
		case 'smtp':
			$email = sanitize_text_field(wp_unslash($_POST['backup_recipient']));
			if ( get_option('wpdb_backup_recip') != $email) {
				update_option('wpdb_backup_recip', $email);
			}
			echo '
				setProgress("' . sprintf(__('Your backup has been emailed to %s','wp-db-backup'), $email) . '");
				window.onbeforeunload = null;
				fram.src = "' . $download_uri . '&via=email&recipient=' . $email . '";
			';
			break;
		default:
			echo '
				setProgress("' . __('Backup Complete!','wp-db-backup') . '");
				window.onbeforeunload = null;
			';
		}

		echo '
			}

			function backupStep(step) {
				switch(step) {
				case 0: backup("", 0); break;
		';

		$also_backup = $this->get_post_data_array('other_tables');
		$core_tables = $this->get_post_data_array('core_tables');
		$tables = array_merge($core_tables, $also_backup);
		$step_count = 1;
		foreach ($tables as $table) {
			$rec_count = $wpdb->get_var("SELECT count(*) FROM {$table}");
			$rec_segments = ceil($rec_count / ROWS_PER_SEGMENT);
			$table_count = 0;
			if ( $this->module_check() ) {
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
		$this->backup_menu();
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
				$this->error(__('Could not open the backup file for writing!','wp-db-backup'));
				$this->error(array('loc' => 'frame', 'kind' => 'fatal', 'msg' =>  __('The backup file could not be saved.  Please check the permissions for writing to your backup directory and try again.','wp-db-backup')));
			}
			else {
				if($table == '') {
					//Begin new backup of MySql
					$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
					$this->stow("#\n");
					$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
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
			$this->error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __('The backup directory is not writeable!  Please check the permissions for writing to your backup directory and try again.','wp-db-backup')));
		}

		if($this->fp) $this->close($this->fp);

		$this->error_display('frame');

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
			$also_backup = sanitize_text_field($_POST['other_tables']);
		$core_tables = sanitize_text_field($_POST['core_tables']);
		$this->backup_file = $this->db_backup($core_tables, $also_backup);
		if (false !== $this->backup_file) {
			if ('smtp' == $_POST['deliver']) {
				$email = sanitize_text_field(wp_unslash($_POST['backup_recipient']));
				$this->deliver_backup($this->backup_file, sanitize_text_field($_POST['deliver']), $email, 'main');
				if ( get_option('wpdb_backup_recip') != $email ) {
					update_option('wpdb_backup_recip', $email );
				}
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

	function admin_header() {
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
					for(var i=0; i < t[k].p.length; i++) {
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
				}

				<?php if ( function_exists('wp_schedule_event') ) : // needs to be at least WP 2.1 for ajax ?>
				if ( 'undefined' == typeof XMLHttpRequest )
					var xml = new ActiveXObject( navigator.userAgent.indexOf('MSIE 5') >= 0 ? 'Microsoft.XMLHTTP' : 'Msxml2.XMLHTTP' );
				else
					var xml = new XMLHttpRequest();

				var initTimeChange = function() {
					var timeWrap = document.getElementById('backup-time-wrap');
					var backupTime = document.getElementById('next-backup-time');
					if ( !! timeWrap && !! backupTime && ( 1 == <?php
						echo (int) ( 'en' == strtolower( substr( get_locale(), 0, 2 ) ) );
					?> ) ) {
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
				-moz-border-radius: 8px;
				-khtml-border-radius: 8px;
				-webkit-border-top-left-radius: 8px;
				-webkit-border-top-right-radius: 8px;
				-webkit-border-bottom-left-radius: 8px;
				-webkit-border-bottom-right-radius: 8px;
				border-radius: 8px;
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
		add_action('admin_head', array(&$this, 'admin_header'));
	}

	function admin_menu() {
		$_page_hook = add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', $this->basename, array(&$this, 'backup_menu'));
		add_action('load-' . $_page_hook, array(&$this, 'admin_load'));
		if (function_exists('get_current_screen')) {
			$screen = convert_to_screen($_page_hook);
			if (method_exists($screen,'add_help_tab')) {
				$screen->add_help_tab(array(
					'title' => __('Backup','wp-db-backup'),
					'id' => $_page_hook,
					'content' => $this->help_menu(),
				));
			}
		} elseif ( function_exists('add_contextual_help') ) {
			$text = $this->help_menu();
			add_contextual_help($_page_hook, $text);
		}
	}

	function fragment_menu() {
		$page_hook = add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', $this->basename, array(&$this, 'build_backup_script'));
		add_action('load-' . $page_hook, array(&$this, 'admin_load'));
	}

	/**
	 * Add WP-DB-Backup-specific help options to the 2.7 =< WP contextual help menu
	 * @return string The text of the help menu.
	 */
	function help_menu() {
		$text = "\n<a href=\"http://wordpress.org/extend/plugins/wp-db-backup/faq/\" target=\"_blank\">" . __('FAQ', 'wp-db-backup') . '</a>';
		return $text;
	}

	function save_backup_time() {
		if ( $this->can_user_backup() ) {
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
		if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
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
		$fp = @fopen($filename, $mode);
		return $fp;
	}

	function close($fp) {
		fclose($fp);
	}

	/**
	 * Write to the backup file
	 * @param string $query_line the line to write
	 * @return null
	 */
	function stow($query_line) {
		if(false === @fwrite($this->fp, $query_line))
			$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
	}

	/**
	 * Logs any error messages
	 * @param array $args
	 * @return bool
	 */
	function error($args = array()) {
		if ( is_string( $args ) )
			$args = array('msg' => $args);
		$args = array_merge( array('loc' => 'main', 'kind' => 'warn', 'msg' => ''), $args);
		$this->errors[$args['kind']][] = $args['msg'];
		if ( 'fatal' == $args['kind'] || 'frame' == $args['loc'])
			$this->error_display($args['loc']);
		return true;
	}

	/**
	 * Displays error messages
	 * @param array $errs
	 * @param string $loc
	 * @return string
	 */
	function error_display($loc = 'main', $echo = true) {
		$errs = $this->errors;
		unset( $this->errors );
		if ( ! count($errs) ) return;
		$msg = '';
		$errs['fatal'] = isset( $errs['fatal'] ) ? (array) $errs['fatal'] : array();
		$errs['warn'] = isset( $errs['warn'] ) ? (array) $errs['warn'] : array();
		$err_list = array_slice( array_merge( $errs['fatal'], $errs['warn'] ), 0, 10);
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
			$this->error(__('Error getting table details','wp-db-backup') . ": $table");
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
				$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');

			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
				$this->error($err_msg);
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
				$row_inc = ROWS_PER_SEGMENT;
			} else {
				$row_start = $segment * ROWS_PER_SEGMENT;
				$row_inc = ROWS_PER_SEGMENT;
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
							if ( !empty($ints[strtolower($key)]) ) {
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

	function db_backup($core_tables, $other_tables) {
		global $table_prefix, $wpdb;

		if (is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $this->backup_filename);
			if(!$this->fp) {
				$this->error(__('Could not open the backup file for writing!','wp-db-backup'));
				return false;
			}
		} else {
			$this->error(__('The backup directory is not writeable!','wp-db-backup'));
			return false;
		}

		//Begin new backup of MySql
		$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");

			if ( (is_array($other_tables)) && (count($other_tables) > 0) )
			$tables = array_merge($core_tables, $other_tables);
		else
			$tables = $core_tables;

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

	} //wp_db_backup

	/**
	 * Sends the backed-up file via email
	 * @param string $to
	 * @param string $subject
	 * @param string $message
	 * @return bool
	 */
	function send_mail( $to, $subject, $message, $diskfile) {
		global $phpmailer;

		$filename = basename($diskfile);

		extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message' ) ) );

		if ( !is_object( $phpmailer ) || ( strtolower(get_class( $phpmailer )) != 'phpmailer' ) ) {
			if ( file_exists( ABSPATH . WPINC . '/class-phpmailer.php' ) )
				require_once ABSPATH . WPINC . '/class-phpmailer.php';
			if ( file_exists( ABSPATH . WPINC . '/class-smtp.php' ) )
				require_once ABSPATH . WPINC . '/class-smtp.php';
			if ( class_exists( 'PHPMailer') )
				$phpmailer = new PHPMailer();
		}

		// try to use phpmailer directly (WP 2.2+)
		if ( is_object( $phpmailer ) && ( strtolower(get_class( $phpmailer )) == 'phpmailer' ) ) {

			// Get the site domain and get rid of www.
			$sitename = $this->get_sitename();
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
			$phpmailer->AddAttachment($diskfile, $filename);
			$phpmailer->Body = $message;
			$phpmailer->CharSet = apply_filters( 'wp_mail_charset', get_bloginfo('charset') );
			$phpmailer->From = apply_filters( 'wp_mail_from', $from_email );
			$phpmailer->FromName = apply_filters( 'wp_mail_from_name', $from_name );
			$phpmailer->IsMail();
			$phpmailer->Subject = $subject;

			do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );

			$result = @$phpmailer->Send();

		// old-style: build the headers directly
		} else {
			$randomish = md5(time());
			$boundary = "==WPBACKUP-$randomish";
			$fp = fopen($diskfile,"rb");
			$file = fread($fp,filesize($diskfile));
			$this->close($fp);

			$data = chunk_split(base64_encode($file));

			$headers .= "MIME-Version: 1.0\n";
			$headers = 'From: wordpress@' . preg_replace('#^www\.#', '', sanitize_text_field(strtolower($_SERVER['SERVER_NAME']))) . "\n";
			$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";

			// Add a multipart boundary above the plain message
			$message = "This is a multi-part message in MIME format.\n\n" .
				"--{$boundary}\n" .
				"Content-Type: text/plain; charset=\"" . get_bloginfo('charset') . "\"\n" .
				"Content-Transfer-Encoding: 7bit\n\n" .
				$message . "\n\n";

			// Add file attachment to the message
			$message .= "--{$boundary}\n" .
				"Content-Type: application/octet-stream;\n" .
				" name=\"{$filename}\"\n" .
				"Content-Disposition: attachment;\n" .
				" filename=\"{$filename}\"\n" .
				"Content-Transfer-Encoding: base64\n\n" .
				$data . "\n\n" .
				"--{$boundary}--\n";

			$result = @wp_mail($to, $subject, $message, $headers);
		}
		return $result;

	}

	function deliver_backup($filename = '', $delivery = 'http', $recipient = '', $location = 'main') {
		if ('' == $filename) { return false; }

		$diskfile = $this->backup_dir . $filename;
		$gz_diskfile = "{$diskfile}.gz";

		/**
		 * Try upping the memory limit before gzipping
		 */
		if ( function_exists('memory_get_usage') && ( (int) @ini_get('memory_limit') < 64 ) ) {
			@ini_set('memory_limit', '64M' );
		}

		if ( file_exists( $diskfile ) && empty( $_GET['download-retry'] ) ) {
			/**
			 * Try gzipping with an external application
			 */
			if ( file_exists( $diskfile ) && ! file_exists( $gz_diskfile ) ) {
				@exec( "gzip $diskfile" );
			}

			if ( file_exists( $gz_diskfile ) ) {
				if ( file_exists( $diskfile ) ) {
					unlink($diskfile);
				}
				$diskfile = $gz_diskfile;
				$filename = "{$filename}.gz";

			/**
			 * Try to compress to gzip, if available
			 */
			} else {
				if ( function_exists('gzencode') ) {
					if ( function_exists('file_get_contents') ) {
						$text = file_get_contents($diskfile);
					} else {
						$text = implode("", file($diskfile));
					}
					$gz_text = gzencode($text, 9);
					$fp = fopen($gz_diskfile, "w");
					fwrite($fp, $gz_text);
					if ( fclose($fp) ) {
						unlink($diskfile);
						$diskfile = $gz_diskfile;
						$filename = "{$filename}.gz";
					}
				}
			}
			/*
			 *
			 */
		} elseif ( file_exists( $gz_diskfile ) && empty( $_GET['download-retry'] ) ) {
			$diskfile = $gz_diskfile;
			$filename = "{$filename}.gz";
		}

		if ('http' == $delivery) {
			if ( ! file_exists( $diskfile ) ) {
				if ( empty( $_GET['download-retry'] ) ) {
					$this->error(array('kind' => 'fatal', 'msg' => sprintf(__('File not found:%s','wp-db-backup'), "&nbsp;<strong>$filename</strong><br />") . '<br /><a href="' . $this->page_url . '">' . __('Return to Backup','wp-db-backup') . '</a>'));
				} else {
					return true;
				}
			} elseif ( file_exists( $diskfile ) ) {
				header('Content-Description: File Transfer');
				header('Content-Type: application/octet-stream');
				header('Content-Length: ' . filesize($diskfile));
				header("Content-Disposition: attachment; filename=$filename");
				$success = readfile($diskfile);
				if ( $success ) {
					unlink($diskfile);
				}
			}
		} elseif ('smtp' == $delivery) {
			if (! file_exists($diskfile)) {
				$msg = sprintf(__('File %s does not exist!','wp-db-backup'), $diskfile);
				$this->error($msg);
				return false;
			}
			if (! is_email($recipient)) {
				$recipient = get_option('admin_email');
			}
			$message = sprintf(__("Attached to this email is\n   %1s\n   Size:%2s kilobytes\n",'wp-db-backup'), $filename, round(filesize($diskfile)/1024));
			$success = $this->send_mail($recipient, get_bloginfo('name') . ' ' . __('Database Backup','wp-db-backup'), $message, $diskfile);

			if ( false === $success ) {
				$msg = __('The following errors were reported:','wp-db-backup') . "\n ";
				if ( function_exists('error_get_last') ) {
					$err = error_get_last();
					$msg .= $err['message'];
				} else {
					$msg .= __('ERROR: The mail application has failed to deliver the backup.','wp-db-backup');
				}
				$this->error(array('kind' => 'fatal', 'loc' => $location, 'msg' => $msg));
			} else {
				if ( file_exists( $diskfile ) ) {
					unlink($diskfile);
				}
			}
		}
		return $success;
	}

	function backup_menu() {
		global $table_prefix, $wpdb;
		$feedback = '';
		$whoops = false;

		// did we just do a backup?  If so, let's report the status
		if ( $this->backup_complete ) {
			$feedback = '<div class="updated wp-db-backup-updated"><p>' . __('Backup Successful','wp-db-backup') . '!';
			$file = $this->backup_file;
			switch($_POST['deliver']) {
			case 'http':
				$feedback .= '<br />' . sprintf(__('Your backup file: %2s should begin downloading shortly.','wp-db-backup'), "{$this->backup_file}", $this->backup_file);
				break;
			case 'smtp':
				$email = sanitize_text_field(wp_unslash($_POST['backup_recipient']));
				if (! is_email($email)) {
					$feedback .= get_option('admin_email');
				} else {
					$feedback .= $email;
				}
				$feedback = '<br />' . sprintf(__('Your backup has been emailed to %s','wp-db-backup'), $feedback);
				break;
			}
			$feedback .= '</p></div>';
		}

		// security check
		$this->wp_secure();

		if (count($this->errors)) {
			$feedback .= '<div class="updated wp-db-backup-updated error"><p><strong>' . __('The following errors were reported:','wp-db-backup') . '</strong></p>';
			$feedback .= '<p>' . $this->error_display( 'main', false ) . '</p>';
			$feedback .= "</p></div>";
		}

		// did we just save options for wp-cron?
		if ( (function_exists('wp_schedule_event') || function_exists('wp_cron_init'))
			&& isset($_POST['wp_cron_backup_options']) ) :
			do_action('wp_db_b_update_cron_options');
			if ( function_exists('wp_schedule_event') ) {
				wp_clear_scheduled_hook( 'wp_db_backup_cron' ); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = sanitize_text_field(strval($_POST['wp_cron_schedule']));
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
			update_option('wp_cron_backup_tables', $this->get_submitted_tables_to_backup_in_cron());
			if (is_email($_POST['cron_backup_recipient'])) {
				update_option('wp_cron_backup_recipient', sanitize_text_field($_POST['cron_backup_recipient']), false);
			}
			$feedback .= '<div class="updated wp-db-backup-updated"><p>' . __('Scheduled Backup Options Saved!','wp-db-backup') . '</p></div>';
		endif;

		$other_tables = array();
		$also_backup = array();

		// Get complete db table list
		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		// Get list of WP tables that actually exist in this DB (for 1.6 compat!)
		$wp_backup_default_tables = array_intersect($all_tables, $this->core_table_names);
		// Get list of non-WP tables
		$other_tables = array_diff($all_tables, $wp_backup_default_tables);

		if ('' != $feedback)
			echo $feedback;

		if ( ! $this->wp_secure() )
			return;

		// Give the new dirs the same perms as wp-content.
//		$stat = stat( ABSPATH . 'wp-content' );
//		$dir_perms = $stat['mode'] & 0000777; // Get the permission bits.
		$dir_perms = '0777';

		// the file doesn't exist and can't create it
		if ( ! file_exists($this->backup_dir) && ! @mkdir($this->backup_dir) ) {
			?><div class="updated wp-db-backup-updated error"><p><?php _e('WARNING: Your backup directory does <strong>NOT</strong> exist, and we cannot create it.','wp-db-backup'); ?></p>
			<p><?php printf(__('Using your FTP client, try to create the backup directory yourself: %s', 'wp-db-backup'), '<code>' . $this->backup_dir . '</code>'); ?></p></div><?php
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
			<li><label for="do_download">
				<input type="radio" checked="checked" id="do_download" name="deliver" value="http" style="border:none;" />
				<?php _e('Download to your computer','wp-db-backup'); ?>
			</label></li>
			<li><label for="do_email">
				<input type="radio" name="deliver" id="do_email" value="smtp" style="border:none;" />
				<?php _e('Email backup to:','wp-db-backup'); ?>
				<input type="text" name="backup_recipient" size="20" value="<?php
					$backup_recip = get_option('wpdb_backup_recip');
					if ( empty( $backup_recip ) ) {
						$backup_recip = get_option('admin_email');
					}

					echo $backup_recip; ?>" />
			</label></li>
			</ul>
			<?php if ( ! $whoops ) : ?>
			<input type="hidden" name="do_backup" id="do_backup" value="backup" />
			<p class="submit">
				<input type="submit" name="submit" onclick="document.getElementById('do_backup').value='fragments';" value="<?php _e('Backup now!','wp-db-backup'); ?>" />
			</p>
			<?php else : ?>
				<div class="updated wp-db-backup-updated error"><p><?php _e('WARNING: Your backup directory is <strong>NOT</strong> writable!','wp-db-backup'); ?></p></div>
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
			echo '<p class="submit"><input type="submit" name="submit" value="' . __('Schedule backup','wp-db-backup') . '" /></p>';
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

	} // end wp_backup_menu()

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

	function wp_cron_daily() { // for legacy cron plugin
		$schedule = intval(get_option('wp_cron_backup_schedule'));
		// If scheduled backup is disabled
		if (0 == $schedule)
		        return;
		else return $this->cron_backup();
	}

	function cron_backup() {
		global $table_prefix, $wpdb;
		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		$core_tables = array_intersect($all_tables, $this->core_table_names);
		$other_tables = get_option('wp_cron_backup_tables');
		$recipient = get_option('wp_cron_backup_recipient');
		$backup_file = $this->db_backup($core_tables, $other_tables);
		if (false !== $backup_file)
			return $this->deliver_backup($backup_file, 'smtp', $recipient, 'main');
		else return false;
	}

	function add_sched_options($sched) {
		$sched['weekly'] = array('interval' => 604800, 'display' => __('Once Weekly','wp-db-backup'));
		return $sched;
	}

	/**
	 * Checks that WordPress has sufficient security measures
	 * @param string $kind
	 * @return bool
	 */
	function wp_secure($kind = 'warn', $loc = 'main') {
		global $wp_version;
		if ( function_exists('wp_verify_nonce') ) return true;
		else {
			$this->error(array('kind' => $kind, 'loc' => $loc, 'msg' => sprintf(__('Your WordPress version, %1s, lacks important security features without which it is unsafe to use the WP-DB-Backup plugin.  Hence, this plugin is automatically disabled.  Please consider <a href="%2s">upgrading WordPress</a> to a more recent version.','wp-db-backup'),$wp_version,'http://wordpress.org/download/')));
			return false;
		}
	}

	/**
	 * Checks that the user has sufficient permission to backup
	 * @param string $loc
	 * @return bool
	 */
	function can_user_backup($loc = 'main') {
		$can = false;
		// make sure WPMU users are site admins, not ordinary admins
		if ( function_exists('is_site_admin') && ! is_site_admin() )
			return false;
		if ( ( $this->wp_secure('fatal', $loc) ) && current_user_can('import') )
			$can = $this->verify_nonce($_REQUEST['_wpnonce'], $this->referer_check_key, $loc);
		if ( false == $can )
			$this->error(array('loc' => $loc, 'kind' => 'fatal', 'msg' => __('You are not allowed to perform backups.','wp-db-backup')));
		return $can;
	}

	/**
	 * Verify that the nonce is legitimate
	 * @param string $rec 	the nonce received
	 * @param string $nonce	what the nonce should be
	 * @param string $loc 	the location of the check
	 * @return bool
	 */
	function verify_nonce($rec = '', $nonce = 'X', $loc = 'main') {
		if ( wp_verify_nonce($rec, $nonce) )
			return true;
		else
			$this->error(array('loc' => $loc, 'kind' => 'fatal', 'msg' => sprintf(__('There appears to be an unauthorized attempt from this site to access your database located at %1s.  The attempt has been halted.','wp-db-backup'),get_option('home'))));
	}

	/**
	 * Check whether a file to be downloaded is
	 * surreptitiously trying to download a non-backup file
	 * @param string $file
	 * @return null
	 */
	function validate_file($file) {
		if ( (false !== strpos($file, '..')) || (false !== strpos($file, './')) || (':' == substr($file, 1, 1)) )
			$this->error(array('kind' => 'fatal', 'loc' => 'frame', 'msg' => __("Cheatin' uh ?",'wp-db-backup')));
	}

	/**
	 * Get the sitename by query $_SERVER['SERVER_NAME']. 
	 * If it is not set, then use site_url() instead
	 * @return string
	 */
	function get_sitename() {
		$sitename='';
		if ( isset($_SERVER['SERVER_NAME']) ) {
			$sitename = strtolower( sanitize_text_field( $_SERVER['SERVER_NAME'] ) );
		} else {
			if ( function_exists('site_url') ) {
				// site_url() was added since 3.0.0
				// force http scheme so we can easily get rid of leading http://
				$sitename = strtolower( site_url( '', 'http' ) );
				$sitename = substr( $sitename, 7 );
			} else {
				// try to be compatible with versions < 3.0.0
				$sitename = strtolower( get_option( 'siteurl' ) );
				if ( substr( $sitename, 0, 7 ) == 'http://' ) {
					$sitename = substr( $sitename, 7 );
				} elseif ( substr( $sitename, 0, 8 ) == 'https://' ) {
					$sitename = substr( $sitename, 8 );
				}
			}
		}
		// get rid of www
		if ( substr( $sitename, 0, 4 ) == 'www.' ) {
			$sitename = substr( $sitename, 4 );
		}
		return $sitename;
	}


	/**
	 * Sanitize an array of content.
	 *
	 * @param array $array_of_data
	 *
	 * @return array
	 */
	function sanitize_array($array_to_sanitize) {
		$sanitized = array();
		foreach ( $array_to_sanitize as $key => $value ) {
			$sanitized[$key] = sanitize_text_field($value);
		}
		return $sanitized;
	}

	/**
	 * Get a sanitized array of submitted $_POST values
	 *
	 * @param string $post_key The key of the $_POST array.
	 *
	 * @return array
	 */
	function get_post_data_array($post_key) {
		$sanitized_data = array();
		if (isset( $_POST[$post_key] )) {
			$sanitized_data = (array) $_POST[$post_key];
		}
		return $this->sanitize_array($sanitized_data);
	}

	/**
	 * Get the revisions to exclude.
	 *
	 * @return array
	 */
	function get_revisions_to_exclude() {
		return $this->get_post_data_array('exclude-revisions');
	}

	/**
	 * Get the spam to exclude.
	 *
	 * @return array
	 */
	function get_spam_to_exclude() {
		return $this->get_post_data_array('exclude-spam');
	}

	/**
	 * Get the submitted tables to backup.
	 *
	 * @return array
	 */
	function get_submitted_tables_to_backup_in_cron() {
		return $this->get_post_data_array('wp_cron_backup_tables');
	}
}

function wpdbBackup_init() {
	global $mywpdbbackup;
	$mywpdbbackup = new wpdbBackup();
}

add_action('plugins_loaded', 'wpdbBackup_init');
?>
