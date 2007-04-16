<?php
/*
Plugin Name: WordPress Database Backup
Plugin URI: http://www.ilfilosofo.com/blog/wp-db-backup
Description: On-demand backup of your WordPress database. Navigate to <a href="edit.php?page=wp-db-backup.php">Manage &rarr; Backup</a> to get started.
Author: Austin Matzko 
Author URI: http://www.ilfilosofo.com/blog/
Version: 2.0.3

Development continued from that done by Skippy (http://www.skippy.net/)

Much of this was modified from Mark Ghosh's One Click Backup, which
in turn was derived from phpMyAdmin.

Many thanks to Owen (http://asymptomatic.net/wp/) for his patch
   http://dev.wp-plugins.org/ticket/219

Copyright 2007  Austin Matzko  (email : if.website at gmail.com)

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
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// CHANGE THIS IF YOU WANT TO USE A 
// DIFFERENT BACKUP LOCATION

$rand = substr( md5( md5( DB_PASSWORD ) ), -5 );

define('WP_BACKUP_DIR', 'wp-content/backup-' . $rand);

define('ROWS_PER_SEGMENT', 100);

class wpdbBackup {

	var $backup_complete = false;
	var $backup_file = '';
	var $backup_dir = WP_BACKUP_DIR;
	var $backup_errors = array();
	var $basename;

	function gzip() {
		return function_exists('gzopen');
	}

	function wpdbBackup() {
		global $table_prefix, $wpdb;
		load_plugin_textdomain('wp-db-backup');
		add_action('wp_db_backup_cron', array(&$this, 'cron_backup'));
		add_action('wp_cron_daily', array(&$this, 'wp_cron_daily'));
		add_filter('cron_schedules', array(&$this, 'add_sched_options'));
		add_filter('wp_db_b_schedule_choices', array(&$this, 'schedule_choices'));
		
		$table_prefix = ( isset( $table_prefix ) ) ? $table_prefix : $wpdb->prefix;
		if ( isset( $wpdb->link2cat ) )
			$this->core_table_names = explode(',',"$wpdb->categories,$wpdb->comments,$wpdb->link2cat,$wpdb->links,$wpdb->options,$wpdb->post2cat,$wpdb->postmeta,$wpdb->posts,$wpdb->users,$wpdb->usermeta");
		else 
			$this->core_table_names = explode(',',"$wpdb->categories,$wpdb->comments,$wpdb->linkcategories,$wpdb->links,$wpdb->options,$wpdb->post2cat,$wpdb->postmeta,$wpdb->posts,$wpdb->users,$wpdb->usermeta");
		
		$this->backup_dir = trailingslashit($this->backup_dir);
		$this->basename = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
	
		if (isset($_POST['do_backup'])) {
			if ( !$this->user_can_backup() ) die(__('You are not allowed to perform backups.','wp-db-backup'));
			switch($_POST['do_backup']) {
			case 'backup':
				$this->perform_backup();
				break;
			case 'fragments':
				add_action('admin_menu', array(&$this, 'fragment_menu'));
				break;				
			}
		} elseif (isset($_GET['fragment'] )) {
			if ( !$this->user_can_backup() ) die(__('You are not allowed to perform backups.','wp-db-backup'));
			add_action('init', array(&$this, 'init'));
		} elseif (isset($_GET['backup'] )) {
			if ( !$this->user_can_backup() ) die(__('You are not allowed to perform backups.','wp-db-backup'));
			add_action('init', array(&$this, 'init'));
		} else {
			add_action('admin_menu', array(&$this, 'admin_menu'));
		}
	}
	
	function init() {
		if ( !$this->user_can_backup() ) die(__('You are not allowed to perform backups.','wp-db-backup'));

		if (isset($_GET['backup'])) {
			$via = isset($_GET['via']) ? $_GET['via'] : 'http';
			
			$this->backup_file = $_GET['backup'];
			$this->validate_file($this->backup_file);

			switch($via) {
			case 'smtp':
			case 'email':
				$this->deliver_backup ($this->backup_file, 'smtp', $_GET['recipient']);
				echo '
					<!-- ' . $via . ' -->
					<script type="text/javascript"><!--\\
				';
				if($this->backup_errors) {
					foreach($this->backup_errors as $error) {
						echo "window.parent.addError('$error');\n";
					}
				}
				echo '
					alert("' . __('Backup Complete!','wp-db-backup') . '");
					</script>
				';
				break;
			default:
				$this->deliver_backup ($this->backup_file, $via);
			}
			die();
		}
		if (isset($_GET['fragment'] )) {
			list($table, $segment, $filename) = explode(':', $_GET['fragment']);
			$this->validate_file($filename);
			$this->backup_fragment($table, $segment, $filename);
		}

		die();
	}
	
	function build_backup_script() {
		global $table_prefix, $wpdb;
	
		$datum = date("Ymd_B");
		$backup_filename = DB_NAME . "_$table_prefix$datum.sql";
		if ($this->gzip()) $backup_filename .= '.gz';
		
		echo "<div class='wrap'>";
		//echo "<pre>" . print_r($_POST, 1) . "</pre>";
		echo '<h2>' . __('Backup','wp-db-backup') . '</h2>
			<fieldset class="options"><legend>' . __('Progress','wp-db-backup') . '</legend>
			<p><strong>' .
				__('DO NOT DO THE FOLLOWING AS IT WILL CAUSE YOUR BACKUP TO FAIL:','wp-db-backup').
			'</strong></p>
			<ol>
				<li>'.__('Close this browser','wp-db-backup').'</li>
				<li>'.__('Reload this page','wp-db-backup').'</li>
				<li>'.__('Click the Stop or Back buttons in your browser','wp-db-backup').'</li>
			</ol>
			<p><strong>' . __('Progress:','wp-db-backup') . '</strong></p>
			<div id="meterbox" style="height:11px;width:80%;padding:3px;border:1px solid #659fff;"><div id="meter" style="height:11px;background-color:#659fff;width:0%;text-align:center;font-size:6pt;">&nbsp;</div></div>
			<div id="progress_message"></div>
			<div id="errors"></div>
			</fieldset>
			<iframe id="backuploader" src="about:blank" style="border:0px solid white;height:1em;width:1em;"></iframe>
			<script type="text/javascript"><!--//
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
				fram.src = "' . $_SERVER['REQUEST_URI'] . '&fragment=" + table + ":" + segment + ":' . $backup_filename . '";
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

		$this_basename = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
		$download_uri = get_option('siteurl') . "/wp-admin/edit.php?page={$this_basename}&backup={$backup_filename}";
		switch($_POST['deliver']) {
		case 'http':
			echo '
				setProgress("' . sprintf(__("Backup complete, preparing <a href=\\\"%s\\\">backup</a> for download...",'wp-db-backup'), $download_uri) . '");
				fram.src = "' . $download_uri . '";
			';
			break;
		case 'smtp':
			echo '
				setProgress("' . sprintf(__("Backup complete, sending <a href=\\\"%s\\\">backup</a> via email...",'wp-db-backup'), $download_uri) . '");
				fram.src = "' . $download_uri . '&via=email&recipient=' . $_POST['backup_recipient'] . '";
			';
			break;
		default:
			echo '
				setProgress("' . sprintf(__("Backup complete, download <a href=\\\"%s\\\">here</a>.",'wp-db-backup'), $download_uri) . '");
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
			$rec_segments = ceil($rec_count / ROWS_PER_SEGMENT);
			$table_count = 0;
			do {
				echo "case {$step_count}: backup(\"{$table}\", {$table_count}); break;\n";
				$step_count++;
				$table_count++;
			} while($table_count < $rec_segments);
			echo "case {$step_count}: backup(\"{$table}\", -1); break;\n";
			$step_count++;
		}
		echo "case {$step_count}: finishBackup(); break;";
		
		echo '
				}
				if(step != 0) setMeter(100 * step / ' . $step_count . ');
			}

			nextStep();
			//--></script>
	</div>
		';
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
		
		echo '<script type="text/javascript"><!--//
		var msg = "' . $msg . '";
		window.parent.setProgress(msg);
		';
			
		if (is_writable(ABSPATH . $this->backup_dir)) {
			$this->fp = $this->open(ABSPATH . $this->backup_dir . $filename, 'a');
			if(!$this->fp) {
				$this->backup_error(__('Could not open the backup file for writing!','wp-db-backup'));
				$this->fatal_error = __('The backup file could not be saved.  Please check the permissions for writing to your backup directory and try again.','wp-db-backup');
			}
			else {
				if($table == '') {		
					//Begin new backup of MySql
					$this->stow("# WordPress MySQL database backup\n");
					$this->stow("#\n");
					$this->stow("# Generated: " . date("l j. F Y H:i T") . "\n");
					$this->stow("# Hostname: " . DB_HOST . "\n");
					$this->stow("# Database: " . $this->backquote(DB_NAME) . "\n");
					$this->stow("# --------------------------------------------------------\n");
				} else {
					if($segment == 0) {
						// Increase script execution time-limit to 15 min for every table.
						if ( !ini_get('safe_mode')) @set_time_limit(15*60);
						//ini_set('memory_limit', '16M');
						// Create the SQL statements
						$this->stow("# --------------------------------------------------------\n");
						$this->stow("# Table: " . $this->backquote($table) . "\n");
						$this->stow("# --------------------------------------------------------\n");
					}			
					$this->backup_table($table, $segment);
				}
			}
		} else {
			$this->backup_error(__('The backup directory is not writeable!','wp-db-backup'));
			$this->fatal_error = __('The backup directory is not writeable!  Please check the permissions for writing to your backup directory and try again.','wp-db-backup');
		}

		if($this->fp) $this->close($this->fp);
		
		if($this->backup_errors) {
			foreach($this->backup_errors as $error) {
				echo "window.parent.addError('$error');\n";
			}
		}
		if($this->fatal_error) {
			echo '
				alert("' . addslashes($this->fatal_error) . '");
				//--></script>
			';
		}
		else {
			echo '
				window.parent.nextStep();
				//--></script>
			';
		}
		
		die();
	}

	function perform_backup() {
		// are we backing up any other tables?
		$also_backup = array();
		if (isset($_POST['other_tables'])) {
			$also_backup = $_POST['other_tables'];
		}
		
		$core_tables = $_POST['core_tables'];
		$this->backup_file = $this->db_backup($core_tables, $also_backup);
		if (FALSE !== $this->backup_file) {
			if ('smtp' == $_POST['deliver']) {
				$this->deliver_backup ($this->backup_file, $_POST['deliver'], $_POST['backup_recipient']);
			} elseif ('http' == $_POST['deliver']) {
				$this_basename = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
				header('Refresh: 3; ' . get_option('siteurl') . "/wp-admin/edit.php?page={$this_basename}&backup={$this->backup_file}");
			}
			// we do this to say we're done.
			$this->backup_complete = true;
		}
	}
	
	function admin_menu() {
		add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', basename(__FILE__), array(&$this, 'backup_menu'));
	}

	function fragment_menu() {
		add_management_page(__('Backup','wp-db-backup'), __('Backup','wp-db-backup'), 'import', basename(__FILE__), array(&$this, 'build_backup_script'));
	}

	function sql_addslashes($a_string = '', $is_like = FALSE) {
	        /*
	                Better addslashes for SQL queries.
	                Taken from phpMyAdmin.
	        */
	    if ($is_like) {
	        $a_string = str_replace('\\', '\\\\\\\\', $a_string);
	    } else {
	        $a_string = str_replace('\\', '\\\\', $a_string);
	    }
	    $a_string = str_replace('\'', '\\\'', $a_string);

	    return $a_string;
	} // function sql_addslashes($a_string = '', $is_like = FALSE)

	function backquote($a_name) {
	        /*
	                Add backqouotes to tables and db-names in
	                SQL queries. Taken from phpMyAdmin.
	        */
	    if (!empty($a_name) && $a_name != '*') {
	        if (is_array($a_name)) {
	             $result = array();
	             reset($a_name);
	             while(list($key, $val) = each($a_name)) {
	                 $result[$key] = '`' . $val . '`';
	             }
	             return $result;
	        } else {
	            return '`' . $a_name . '`';
	        }
	    } else {
	        return $a_name;
	    }
	} // function backquote($a_name, $do_it = TRUE)

	function open($filename = '', $mode = 'w') {
		if ('' == $filename) return false;
		if ($this->gzip()) {
			$fp = @gzopen($filename, $mode);
		} else {
			$fp = @fopen($filename, $mode);
		}
		return $fp;
	}

	function close($fp) {
		if ($this->gzip()) {
			gzclose($fp);
		} else {
			fclose($fp);
		}
	}

	function stow($query_line) {
		if ($this->gzip()) {
			if(@gzwrite($this->fp, $query_line) === FALSE) {
				$this->backup_error(__('There was an error writing a line to the backup script:','wp-db-backup'));
				$this->backup_error('&nbsp;&nbsp;' . $query_line);
			}
		} else {
			if(@fwrite($this->fp, $query_line) === FALSE) {
				$this->backup_error(__('There was an error writing a line to the backup script:','wp-db-backup'));
				$this->backup_error('&nbsp;&nbsp;' . $query_line);
			}
		}
	}
	
	function backup_error($err) {
		if(count($this->backup_errors) < 20) {
			$this->backup_errors[] = $err;
		} elseif(count($this->backup_errors) == 20) {
			$this->backup_errors[] = __('Subsequent errors have been omitted from this log.','wp-db-backup');
		}
	}

	function backup_table($table, $segment = 'none') {
		global $wpdb;
		
		/*
		Taken partially from phpMyAdmin and partially from
		Alain Wolf, Zurich - Switzerland
		Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
		
		Modified by Scott Merril (http://www.skippy.net/) 
		to use the WordPress $wpdb object
		*/

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			$this->backup_error(__('Error getting table details','wp-db-backup') . ": $table");
			return FALSE;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# Delete any existing table " . $this->backquote($table) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# Table structure of table " . $this->backquote($table) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (FALSE === $create_table) {
				$this->backup_error(sprintf(__("Error with SHOW CREATE TABLE for %s.",'wp-db-backup'), $table));
				$this->stow("#\n# Error with SHOW CREATE TABLE for $table!\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (FALSE === $table_structure) {
				$this->backup_error(sprintf(__("Error getting table structure of %s",'wp-db-backup'), $table));
				$this->stow("#\n# Error getting table structure of $table!\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# Data contents of table ' . $this->backquote($table) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ||
					(0 === strpos(strtolower($struct->Type), 'timestamp')) ) {
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
				if ( !ini_get('safe_mode')) @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table LIMIT {$row_start}, {$row_inc}", ARRAY_A);

				/*
				if (FALSE === $table_data) {
					$wp_backup_error .= "Error getting table contents from $table\r\n";
					fwrite($fp, "#\n# Error getting table contents fom $table!\n#\n");
				}
				*/
					
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$values = array();
						foreach ($row as $key => $value) {
							if ($ints[strtolower($key)]) {
								$values[] = $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ') ;');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# End of data contents of table " . $this->backquote($table) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
		
	} // end backup_table()
	
	function return_bytes($val) {
	   $val = trim($val);
	   $last = strtolower($val{strlen($val)-1});
	   switch($last) {
	       // The 'G' modifier is available since PHP 5.1.0
	       case 'g':
	           $val *= 1024;
	       case 'm':
	           $val *= 1024;
	       case 'k':
	           $val *= 1024;
	   }
	
	   return $val;
	}
	
	function db_backup($core_tables, $other_tables) {
		global $table_prefix, $wpdb;
		
		$datum = date("Ymd_B");
		$wp_backup_filename = DB_NAME . "_$table_prefix$datum.sql";
			if ($this->gzip()) {
				$wp_backup_filename .= '.gz';
			}
		
		if (is_writable(ABSPATH . $this->backup_dir)) {
			$this->fp = $this->open(ABSPATH . $this->backup_dir . $wp_backup_filename);
			if(!$this->fp) {
				$this->backup_error(__('Could not open the backup file for writing!','wp-db-backup'));
				return false;
			}
		} else {
			$this->backup_error(__('The backup directory is not writeable!','wp-db-backup'));
			return false;
		}
		
		//Begin new backup of MySql
		$this->stow("# WordPress MySQL database backup\n");
		$this->stow("#\n");
		$this->stow("# Generated: " . date("l j. F Y H:i T") . "\n");
		$this->stow("# Hostname: " . DB_HOST . "\n");
		$this->stow("# Database: " . $this->backquote(DB_NAME) . "\n");
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
			$this->stow("# Table: " . $this->backquote($table) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->backup_table($table);
		}
				
		$this->close($this->fp);
		
		if (count($this->backup_errors)) {
			return false;
		} else {
			return $wp_backup_filename;
		}
		
	} //wp_db_backup
	
	function deliver_backup ($filename = '', $delivery = 'http', $recipient = '') {
		if ('' == $filename) { return FALSE; }
		
		$diskfile = ABSPATH . $this->backup_dir . $filename;
		if ('http' == $delivery) {
			if (! file_exists($diskfile)) {
				$msg = sprintf(__('File not found:%s','wp-db-backup'), "<br /><strong>$filename</strong><br />");
				$this_basename = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', __FILE__);
				$msg .= '<br /><a href="' . get_option('siteurl') . "/wp-admin/edit.php?page={$this_basename}" . '">' . __('Return to Backup','wp-db-backup');
			die($msg);
			}
			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Length: ' . filesize($diskfile));
			header("Content-Disposition: attachment; filename=$filename");
			readfile($diskfile);
			unlink($diskfile);
		} elseif ('smtp' == $delivery) {
			if (! file_exists($diskfile)) return false;

			if (! is_email ($recipient)) {
				$recipient = get_option('admin_email');
			}
			$randomish = md5(time());
			$boundary = "==WPBACKUP-BY-SKIPPY-$randomish";
			$fp = fopen($diskfile,"rb");
			$file = fread($fp,filesize($diskfile)); 
			$this->close($fp);
			$data = chunk_split(base64_encode($file));
			$headers = "MIME-Version: 1.0\n";
			$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";
			$headers .= 'From: ' . get_option('admin_email') . "\n";
		
			$message = sprintf(__("Attached to this email is\n   %1s\n   Size:%2s kilobytes\n",'wp-db-backup'), $filename, round(filesize($diskfile)/1024));
			// Add a multipart boundary above the plain message
			$message = "This is a multi-part message in MIME format.\n\n" .
		        	"--{$boundary}\n" .
				"Content-Type: text/plain; charset=\"utf-8\"\n" .
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
			
			if (function_exists('wp_mail')) {
				wp_mail ($recipient, get_bloginfo('name') . ' ' . __('Database Backup','wp-db-backup'), $message, $headers);
			} else {
				mail ($recipient, get_bloginfo('name') . ' ' . __('Database Backup','wp-db-backup'), $message, $headers);
			}
			
			unlink($diskfile);
		}
		return;
	}
	
	function backup_menu() {
		global $table_prefix, $wpdb;
		$feedback = '';
		$WHOOPS = FALSE;
		
		// did we just do a backup?  If so, let's report the status
		if ( $this->backup_complete ) {
			$feedback = '<div class="updated"><p>' . __('Backup Successful','wp-db-backup') . '!';
			$file = $this->backup_file;
			switch($_POST['deliver']) {
			case 'http':
				$feedback .= '<br />' . sprintf(__('Your backup file: <a href="%1s">%2s</a> should begin downloading shortly.','wp-db-backup'), get_option('siteurl') . "/{$this->backup_dir}{$this->backup_file}", $this->backup_file);
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
				$feedback .= ':<br /> <a href="' . get_option('siteurl') . "/{$this->backup_dir}$file\">$file</a> : " . sprintf(__('%s bytes','wp-db-backup'), filesize(ABSPATH . $this->backup_dir . $file));
			}
			$feedback .= '</p></div>';
		}
		
		if (count($this->backup_errors)) {
			$feedback .= '<div class="updated error">' . __('The following errors were reported:','wp-db-backup') . "<pre>";
			foreach($this->backup_errors as $error) {
				$feedback .= "{$error}\n";  //Errors are already localized
			}
			$feedback .= "</pre></div>";
		}
		
		// did we just save options for wp-cron?
		if ( (function_exists('wp_schedule_event') || function_exists('wp_cron_init')) 
			&& isset($_POST['wp_cron_backup_options']) ) :
			if ( function_exists('wp_schedule_event') ) {
				wp_clear_scheduled_hook( 'wp_db_backup_cron' ); // unschedule previous
				$scheds = (array) wp_get_schedules();
				$name = strval($_POST['wp_cron_schedule']);
				$interval = ( isset($scheds[$name]['interval']) ) ? 
					(int) $scheds[$name]['interval'] : 0;
				update_option('wp_cron_backup_schedule', $name, FALSE);
				if ( ! 0 == $interval ) {
					wp_schedule_event(time() + $interval, $name, 'wp_db_backup_cron');
				}
			}
			else {
				update_option('wp_cron_backup_schedule', intval($_POST['cron_schedule']), FALSE);
			}
			update_option('wp_cron_backup_tables', $_POST['wp_cron_backup_tables']);
			if (is_email($_POST['cron_backup_recipient'])) {
				update_option('wp_cron_backup_recipient', $_POST['cron_backup_recipient'], FALSE);
			}
			$feedback .= '<div class="updated"><p>' . __('Scheduled Backup Options Saved!','wp-db-backup') . '</p></div>';
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
		
		if ('' != $feedback) {
			echo $feedback;
		}

		// Give the new dirs the same perms as wp-content.
		$stat = stat( ABSPATH . 'wp-content' );
		$dir_perms = $stat['mode'] & 0000777; // Get the permission bits.

		if ( !file_exists( ABSPATH . $this->backup_dir) ) {
			if ( @ mkdir( ABSPATH . $this->backup_dir) ) {
				@ chmod( ABSPATH . $this->backup_dir, $dir_perms);
			} else {
				echo '<div class="updated error"><p align="center">' . __('WARNING: Your wp-content directory is <strong>NOT</strong> writable! We can not create the backup directory.','wp-db-backup') . '<br />' . ABSPATH . $this->backup_dir . "</p></div>";
			$WHOOPS = TRUE;
			}
		}
		
		if ( !is_writable( ABSPATH . $this->backup_dir) ) {
			echo '<div class="updated error"><p align="center">' . __('WARNING: Your backup directory is <strong>NOT</strong> writable! We can not create the backup directory.','wp-db-backup') . '<br />' . ABSPATH . "</p></div>";
		}

		if ( !file_exists( ABSPATH . $this->backup_dir . 'index.php') ) {
			@ touch( ABSPATH . $this->backup_dir . "index.php");
		}

		?><div class='wrap'>
		<h2><?php _e('Backup','wp-db-backup') ?></h2>
		<form method="post" action="">
		<fieldset class="options"><legend><?php _e('Tables','wp-db-backup') ?></legend>
		<table align="center" cellspacing="5" cellpadding="5"><tr><td width="50%" align="left" class="alternate" valign="top">
		<?php _e('These core WordPress tables will always be backed up:','wp-db-backup') ?><br /><ul><?php
		foreach ($wp_backup_default_tables as $table) {
			echo "<li><input type='hidden' name='core_tables[]' value='$table' />$table</li>";
		}
		?></ul></td><td width="50%" align="left" valign="top"><?php 
		if (count($other_tables) > 0) {
			echo __('You may choose to include any of the following tables:','wp-db-backup') . ' <br />';
			foreach ($other_tables as $table) {
				echo "<label style=\"display:block;\"><input type='checkbox' name='other_tables[]' value='{$table}' /> {$table}</label>";
			}
		}
		?></td></tr></table></fieldset>
		<fieldset class="options"><legend><?php _e('Backup Options','wp-db-backup') ?></legend>
		<?php _e('What to do with the backup file:','wp-db-backup') ?><br />
		<label for="do_save" style="display:block;"><input type="radio" id="do_save" name="deliver" value="none" style="border:none;" />
		<?php echo __('Save to server','wp-db-backup') . " ({$this->backup_dir})</label>";
		?><label for="do_download" style="display:block;"><input type="radio" checked="checked" id="do_download" name="deliver" value="http" style="border:none;" /> 
		<?php _e('Download to your computer','wp-db-backup') ?></label>
		<label for="do_email"><input type="radio" name="deliver" id="do_email" value="smtp" style="border:none;" />
		<?php _e('Email backup to:','wp-db-backup') ?></label><input type="text" name="backup_recipient" size="20" value="<?php echo get_option('admin_email') ?>" />

		<?php		
		// Check DB dize.
		$table_status = $wpdb->get_results("SHOW TABLE STATUS FROM " . $this->backquote(DB_NAME));
		$core_size = $db_size = 0;
		foreach($table_status as $table) {
			$table_size = $table->Data_length - $table->Data_free;
			if(in_array($table->Name, $wp_backup_default_tables)) {
				$core_size += $table_size;	
			}
			$db_size += $table_size;
		}
		$mem_limit = ini_get('memory_limit');
		$mem_limit = $this->return_bytes($mem_limit);
		$mem_limit = ($mem_limit == 0) ? 8*1024*1024 :  $mem_limit - 2000000;
		
		if (! $WHOOPS) {
			echo '<input type="hidden" name="do_backup" id="do_backup" value="backup" />';
			echo '<p class="submit"><input type="submit" name="submit" onclick="document.getElementById(\'do_backup\').value=\'fragments\';" value="' . __('Backup','wp-db-backup') . '!" /></p>';
		} else {
			echo '<p class="alternate">' . __('WARNING: Your backup directory is <strong>NOT</strong> writable!','wp-db-backup') . '</p>';
		}
		echo '</fieldset>';
		echo '</form>';
		
		// this stuff only displays if some sort of wp-cron is available 
		$cron = ( function_exists('wp_schedule_event') ) ? true : false; // wp-cron in WP 2.1+
		$cron_old = ( function_exists('wp_cron_init') && ! $cron ) ? true : false; // wp-cron plugin by Skippy
		if ( $cron_old || $cron ) :
			echo '<fieldset class="options"><legend>' . __('Scheduled Backup','wp-db-backup') . '</legend>';
			$datetime = get_option('date_format') . ' @ ' . get_option('time_format');
			if ( $cron ) :
				if ( ! ( 'never' == $this->get_sched() ) ) :
					echo '<p>' .  __('Next Backup','wp-db-backup') . ': ';
					echo gmdate($datetime, wp_next_scheduled('wp_db_backup_cron') + (get_option('gmt_offset') * 3600)) . '</p>';
				endif;
			elseif ( $cron_old ) :
				echo '<p>' . __('Last WP-Cron Daily Execution','wp-db-backup') . ': ' . gmdate($datetime, get_option('wp_cron_daily_lastrun') + (get_option('gmt_offset') * 3600)) . '<br />';
				echo __('Next WP-Cron Daily Execution','wp-db-backup') . ': ' . gmdate($datetime, (get_option('wp_cron_daily_lastrun') + (get_option('gmt_offset') * 3600) + 86400)) . '</p>';
			endif;
			?><form method="post" action="">
			<table width="100%" cellpadding="5" cellspacing="5">
			<tr><td align="center"><?php 
			echo __('Schedule: ','wp-db-backup');
			if ( $cron_old ) :
				$wp_cron_backup_schedule = get_option('wp_cron_backup_schedule');
				$schedule = array(0 => __('None','wp-db-backup'), 1 => __('Daily','wp-db-backup'));
				foreach ($schedule as $value => $name) {
					echo ' <input type="radio" style="border:none;" name="cron_schedule"';
					if ($wp_cron_backup_schedule == $value) {
						echo ' checked="checked" ';
					}
					echo 'value="' . $value . '" /> ' . __($name,'wp-db-backup');
				}
			elseif ( $cron ) :
				echo apply_filters('wp_db_b_schedule_choices', wp_get_schedules() );
			endif;
			echo '</td><td align="center">';
			$cron_recipient = get_option('wp_cron_backup_recipient');
			if (! is_email($cron_recipient)) {
				$cron_recipient = get_option('admin_email');
			}
			echo __('Email backup to:','wp-db-backup') . ' <input type="text" name="cron_backup_recipient" size="20" value="' . $cron_recipient . '" />';
			echo '</td></tr>';
			$cron_tables = get_option('wp_cron_backup_tables');
			if (! is_array($cron_tables)) {
				$cron_tables = array();
			}
			if (count($other_tables) > 0) {
				echo '<tr><td colspan="2" align="left">' . __('Tables to include:','wp-db-backup') . '<br />';
				foreach ($other_tables as $table) {
					echo '<input type="checkbox" ';
					if (in_array($table, $cron_tables)) {
						echo 'checked=checked ';
					}
					echo "name='wp_cron_backup_tables[]' value='{$table}' /> {$table}<br />";
				}
				echo '</td></tr>';
			}
			echo '<tr><td colspan="2" align="center"><input type="hidden" name="wp_cron_backup_options" value="SET" /><input type="submit" name="submit" value="' . __('Submit','wp-db-backup') . '" /></td></tr></table></form>';
			echo '</fieldset>';
		endif; // end of wp_cron (legacy) section
		
		echo '</div>';
		
	} // end wp_backup_menu()

	function get_sched() {
		$options = array_keys( (array) wp_get_schedules() );
		$freq = get_option('wp_cron_backup_schedule'); 
		$freq = ( in_array( $freq , $options ) ) ? $freq : 'never';
		return $freq;
	}

	function schedule_choices($schedule) { // create the cron menu based on the schedule
		$wp_cron_backup_schedule = $this->get_sched();
		$sort = array();
		foreach ( (array) $schedule as $key => $value ) $sort[$key] = $value['interval'];
		asort( $sort );
		$schedule_sorted = array();
		foreach ( (array) $sort as $key => $value ) $schedule_sorted[$key] = $schedule[$key];
		$menu = '<ul style="list-style: none; text-align: left">';
		$schedule = array_merge( array( 'never' => array( 'interval' => 0, 'display' => __('Never','wp-db-backup') ) ),
			(array) $schedule_sorted );
		foreach ( $schedule as $name => $settings) {
			$interval = (int) $settings['interval'];
			if ( 0 == $interval && ! 'never' == $name ) continue;
			$display = ( ! '' == $settings['display'] ) ? $settings['display'] : sprintf(__('%s seconds','wp-db-backup'),$interval);
			$menu .= "<li><input type='radio' name='wp_cron_schedule' style='border:none;'";
			if ($wp_cron_backup_schedule == $name) {
				$menu .= ' checked="checked" ';
			}
			$menu .= "value='$name' /> $display</li>";
		}
		$menu .= '</ul>';
		return $menu;
	} // end schedule_choices()
	
	function wp_cron_daily() { // for legacy cron plugin
		$schedule = intval(get_option('wp_cron_backup_schedule'));
		if (0 == $schedule) {
		        // Scheduled backup is disabled
		        return;
		}
		else { $this->cron_backup(); }
	} // wp_cron_daily

	function cron_backup() {
		global $table_prefix, $wpdb;

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		$core_tables = array_intersect($all_tables, $this->core_table_names);
		$other_tables = get_option('wp_cron_backup_tables');
		
		$recipient = get_option('wp_cron_backup_recipient');
		
		$backup_file = $this->db_backup($core_tables, $other_tables);
		if (FALSE !== $backup_file) {
			$this->deliver_backup ($backup_file, 'smtp', $recipient);
		}
		return;
	}

	function add_sched_options($sched) {
		$sched['weekly'] = array('interval' => 604800, 'display' => __('Once Weekly','wp-db-backup'));
		return $sched;
	}

	function user_can_backup() {
		return current_user_can('import');
	}

	function validate_file($file) {
		if (false !== strpos($file, '..'))
			die(__("Cheatin' uh ?",'wp-db-backup'));

		if (false !== strpos($file, './'))
			die(__("Cheatin' uh ?",'wp-db-backup'));

		if (':' == substr($file, 1, 1))
			die(__("Cheatin' uh ?",'wp-db-backup'));
	}

}

function wpdbBackup_init() {
	global $mywpdbbackup;
	$mywpdbbackup = new wpdbBackup(); 	
}

add_action('plugins_loaded', 'wpdbBackup_init');

?>
