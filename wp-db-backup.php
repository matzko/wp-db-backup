<?php
/*
Plugin Name: WordPress Database Backup
Plugin URI: http://www.skippy.net/plugins/
Description: On-demand backup of your WordPress database.
Author: Scott Merrill
Version: 1.4
Author URI: http://www.skippy.net/

Much of this was modified from Mark Ghosh's One Click Backup, which
in turn was derived from phpMyAdmin.

*/

add_action('admin_menu', 'add_wp_backup_menu');
add_action('wp_cron_daily', 'wp_cron_db_backup');
global $wp_backup_dir, $wp_backup_error;
$wp_backup_error = '';
$wp_backup_dir = 'wp-content/backup/';

// this needs to live outside of any function so that the
// download can be sent to the browser
if (isset($_GET['backup'])) {
	wp_deliver_backup ($_GET['backup'], 'http');
	die();
}
if ( (isset($_POST['do_backup'])) && ('backup' == $_POST['do_backup']) ) {
	// should we compress the output?
	if ('gzip' == $_POST['gzip']) {
		$gzip = TRUE;
	} else {
		$gzip = FALSE;
	}
	// are we backing up any other tables?
	$also_backup = array();
	if (isset($_POST['other_tables'])) {
		$also_backup = $_POST['other_tables'];
	}
	$core_tables = $_POST['core_tables'];
	$backup_file = wp_db_backup($gzip, $core_tables, $also_backup);
	if (FALSE !== $backup_file) {
		if ('smtp' == $_POST['deliver']) {
			wp_deliver_backup ($backup_file, $_POST['deliver'], $_POST['backup_recipient']);
		} elseif ('http' == $_POST['deliver']) {
			header('Refresh: 3; ' . get_settings('siteurl') . "/wp-admin/edit.php?page=wp-db-backup.php&backup=$backup_file");
		}
		// we do this to say we're done.
		$_POST['do_backup'] = 'DONE';
		// and we do this to pass the filename, and avoid a global
		$_POST['gzip'] = $backup_file;
	}
}

///////////////////////////////
function add_wp_backup_menu() {
add_management_page('Backup', 'Backup', 9, __FILE__, 'wp_backup_menu');
}

/////////////////////////////////////////////////////////
function sql_addslashes($a_string = '', $is_like = FALSE)
{
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

///////////////////////////////////////////////////////////
function backquote($a_name)
{
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

/////////////////////////////
function backup_table($table) {
global $wp_backup_error, $wpdb;

/*
Taken partially from phpMyAdmin and partially from
Alain Wolf, Zurich - Switzerland
Website: http://restkultur.ch/personal/wolf/scripts/db_backup/

Modified by Scott Merril (http://www.skippy.net/) 
to use the WordPress $wpdb object
*/

$sql_statements  = '';
	
//
// Add SQL statement to drop existing table
$sql_statements .= "\n";
$sql_statements .= "\n";
$sql_statements .= "#\n";
$sql_statements .= "# Delete any existing table " . backquote($table) . "\n";
$sql_statements .= "#\n";
$sql_statements .= "\n";
$sql_statements .= "DROP TABLE IF EXISTS " . backquote($table) . ";\n";

// 
//Table structure
// Comment in SQL-file
$sql_statements .= "\n";
$sql_statements .= "\n";
$sql_statements .= "#\n";
$sql_statements .= "# Table structure of table " . backquote($table) . "\n";
$sql_statements .= "#\n";
$sql_statements .= "\n";

$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
if (FALSE === $create_table) {
	$wp_backup_error .= "Error with SHOW CREATE TABLE for $table.\r\n";
	return "#\n# Error with SHOW CREATE TABLE for $table!\n#\n";
}
$sql_statements .= $create_table[0][1] . ' ;';

$table_structure = $wpdb->get_results("DESCRIBE $table");
if (FALSE === $table_structure) {
	$wp_backup_error .= "Error getting table structure of $table\r\n";
	return "#\n# Error getting table structure of $table!\n#\n";
}

$table_data = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
if (FALSE === $table_data) {
	$wp_backup_error .= "Error getting table contents from $table\r\n";
	return "#\n# Error getting table contents fom $table!\n#\n";
}

//
// Comment in SQL-file
$sql_statements .= "\n";
$sql_statements .= "\n";
$sql_statements .= "#\n";
$sql_statements .= '# Data contents of table ' . backquote($table) . "\n";
$sql_statements .= "#\n";

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

$entries = 'INSERT INTO ' . backquote($table) . ' VALUES (';	
//    \x08\\x09, not required
$search = array("\x00", "\x0a", "\x0d", "\x1a");
$replace = array('\0', '\n', '\r', '\Z');
foreach ($table_data as $row) {
	$values = array();
	foreach ($row as $key => $value) {
		if ($ints[strtolower($key)]) {
			$values[] = $value;
		} else {
			$values[] = "'" . str_replace($search, $replace, sql_addslashes($value)) . "'";
		}
	}
	$sql_statements .= " \n" . $entries . implode(', ', $values) . ') ;';
}
// Create footer/closing comment in SQL-file
$sql_statements .= "\n";
$sql_statements .= "#\n";
$sql_statements .= "# End of data contents of table " . backquote($table) . "\n";
$sql_statements .= "# --------------------------------------------------------\n";
$sql_statements .= "\n";

return $sql_statements;

} // end backup_table()

////////////////////////////
function wp_db_backup($gzip = FALSE, $core_tables, $other_tables) {

global $wp_backup_dir, $wp_backup_error, $table_prefix, $wpdb;

$done = array();

$datum = date("Ymd_B");
$wp_backup_filename = DB_NAME . "_$table_prefix$datum.sql";
if ($gzip) {
	$wp_backup_filename .= '.gz';
}

//Begin new backup of MySql
$sql  = "# WordPress MySQL database backup\n";
$sql .= "#\n";
$sql .= "# Generated: " . date("l j. F Y H:i T") . "\n";
$sql .= "# Hostname: " . DB_HOST . "\n";
$sql .= "# Database: " . backquote(DB_NAME) . "\n";
$sql .= "# --------------------------------------------------------\n";

foreach ($core_tables as $table) {
	if (in_array($table, $done)) { continue; }
	// Increase script execution time-limit to 15 min for every table.
	if ( !ini_get('safe_mode')) @set_time_limit(15*60);
	//ini_set('memory_limit', '16M');
	// Create the SQL statements
	$tbl = "# --------------------------------------------------------\n";
	$tbl .= "# Table: " . backquote($table) . "\n";
	$tbl .= "# --------------------------------------------------------\n";
	$tbl .= backup_table($table);
	$sql .= $tbl;
	$done[] = $table;
}

if (count($other_tables) > 0) {
	foreach ($other_tables as $other_table) {
		if (in_array($other_table, $done)) { continue; }
		// Increase script execution time-limit to 15 min for every table.
		if ( !ini_get('safe_mode')) @set_time_limit(15*60);
		//ini_set('memory_limit', '16M');
		// Create the SQL statements
		$tbl = "# --------------------------------------------------------\n";
		$tbl .= "# Table: " . backquote($other_table) . "\n";
		$tbl .= "# --------------------------------------------------------\n";
		$tbl .= backup_table($other_table);
		$sql .= $tbl;
		$done[] = $other_table;
	} // foreach
} // if other_tables...

if (is_writable(ABSPATH . $wp_backup_dir)) {
	if ($gzip) {
		$sql = gzencode($sql);
	}
	$cachefp = fopen(ABSPATH . $wp_backup_dir . $wp_backup_filename, "w");
	fwrite($cachefp, $sql);
	fclose($cachefp);
}

if ('' == $wp_backup_error) {
	return $wp_backup_filename;
} else {
	return FALSE;
}

} //wp_db_backup

///////////////////////////
function wp_deliver_backup ($filename = '', $delivery = 'http', $recipient = '') {
global $wp_backup_dir;

if ('' == $filename) { return FALSE; }

$diskfile = ABSPATH . $wp_backup_dir . $filename;
if ('http' == $delivery) {
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Length: ' . filesize($diskfile));
	header("Content-Disposition: attachment; filename=$filename");
	readfile($diskfile);
	unlink($diskfile);
} elseif ('smtp' == $delivery) {
	if (! is_email ($recipient)) {
		$recipient = get_settings('admin_email');
	}
	$randomish = md5(time());
	$boundary = "==WPBACKUP-BY-SKIPPY-$randomish";
	$fp = fopen($diskfile,"rb");
	$file = fread($fp,filesize($diskfile)); 
	fclose($fp);
	$data = chunk_split(base64_encode($file));
	$headers = "MIME-Version: 1.0\n";
	$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";
	$headers .= 'From: ' . get_settings('admin_email') . "\n";

	$message = __('Attached to this email is', 'wp_backup') . "\n   $filename\n". __('Size', 'wp_backup') . ": " . round(filesize($diskfile)/1024) . ' ' . __('kilobytes', 'wp_backup') . "\n";
	// Add a multipart boundary above the plain message
	$message = "This is a multi-part message in MIME format.\n\n" .
        	"--{$boundary}\n" .
		"Content-Type: text/plain; charset=\"iso-8859-1\"\n" .
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
	
	mail ($recipient, get_bloginfo('name') . ' ' . __('Database Backup', 'wp_backup'), $message, $headers);
	
	unlink($diskfile);
}
return;
}

////////////////////////////
function wp_backup_menu() {
global $wp_backup_dir, $wp_backup_error, $table_prefix, $wpdb;
$feedback = '';
$gzip = FALSE;
$WHOOPS = FALSE;

// first, did we just do a backup?  If so, let's report the status
if ( (isset($_POST['do_backup'])) && ('DONE' == $_POST['do_backup']) ) {
	$feedback = '<div class="updated"><p>' . __('Backup Successful', 'wp_backup') . '!';
	// we stuff the filename into gzip to avoid another global
	$file = $_POST['gzip'];
	if ('http' == $_POST['deliver']) {
		$feedback .= '<br />' . __('Your backup file', 'wp_backup') . ': <a href="' . get_settings('siteurl') . "/$wp_backup_dir$file\">$file</a> " . __('should begin downloading shortly.', 'wp_backup');
	} elseif ('smtp' == $_POST['deliver']) {
		$feedback .= '<br />' . __('Your backup has been emailed to ', 'wp_backup');
		if (! is_email($_POST['backup_recipient'])) {
			$feedback .= get_settings('admin_email');
		} else {
			$feedback .= $_POST['backup_recipient'];
		}
	} elseif ('none' == $_POST['deliver']) {
		$feedback .= '<br />' . __('Your backup file', 'wp_backup') . ' ' .  __('is ready for download; right click and select "Save As"', 'wp_backup') . ':<br /> <a href="' . get_settings('siteurl') . "/$wp_backup_dir$file\">$file</a> : " . filesize(ABSPATH . $wp_backup_dir . $file) . __(' bytes', 'wp_backup');
	}
	$feedback .= '</p></div>';
} elseif ('' != $wp_backup_error) {
	$feedback = '<div class="updated">' . __('The following errors were reported', 'wp_backup') . ":<br /><pre>$wp_backup_error</pre></div>";
}

// did we just save options for wp-cron?
if ( (function_exists('wp_cron_db_backup')) && isset($_POST['wp_cron_backup_options']) ) {
	update_option('wp_cron_backup_schedule', intval($_POST['cron_schedule']), FALSE);
	if (is_email($_POST['cron_backup_recipient'])) {
		update_option('wp_cron_backup_recipient', $_POST['cron_backup_recipient'], FALSE);
	}
	$feedback .= '<div class="updated"><p>' . __('Scheduled Backup Options Saved!', 'wp_backup') . '</p></div>';
}

$gzip = FALSE;
$wp_backup_default_tables = array ($table_prefix . categories,
	$table_prefix . comments,
	$table_prefix . linkcategories,
	$table_prefix . links,
	$table_prefix . options,
	$table_prefix . post2cat,
	$table_prefix . postmeta,
	$table_prefix . posts,
	$table_prefix . users);

$other_tables = array();
$also_backup = array();

// let's get other tables in this database
$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
foreach ($all_tables as $table) {
        if (! in_array($table[0], $wp_backup_default_tables)) {
		$other_tables[] = $table[0];
	}
}

if ('' != $feedback) {
	echo $feedback;
}

if (! is_writable(ABSPATH . $wp_backup_dir)) {
	echo '<div class="updated"><p align="center">' . __('WARNING: Your backup directory is <strong>NOT</strong> writable!</strong>', 'wp_backup') . '<br />' . ABSPATH . "$wp_backup_dir</p></div>";
	$WHOOPS = TRUE;
}
echo "<div class='wrap'>";
echo '<h2>' . __('Backup', 'wp_backup') . '</h2>';
echo '<fieldset class="options"><legend>' . __('Tables', 'wp_backup') . '</legend>';
echo '<form method="post">';
echo '<table align="center" cellspacing="5" cellpadding="5"><tr><td width="50%" align="left" class="alternate">';
echo __('These core WordPress tables will always be backed up', 'wp_backup') . ': <br /><ul>';
foreach ($wp_backup_default_tables as $table) {
	echo "<input type='hidden' name='core_tables[]' value='$table' /><li>$table</li>";
}
echo '</ul></td><td width="50%" align="left">';
if (count($other_tables) > 0) {
	echo __('You may choose to include any of the following tables', 'wp_backup') . ': <br />';
	foreach ($other_tables as $table) {
		echo "<input type='checkbox' name='other_tables[]' value='$table' />$table<br />";
	}
}
echo '</tr></table></fieldset>';
echo '<fieldset class="options"><legend>' . __('Backup Options', 'wp_backup') . '</legend><table width="100%" align="center" cellpadding="5" cellspacing="5">';
echo '<tr><td align="center">';
echo __('Deliver backup file by', 'wp_backup') . ":<br />";
echo '<input type="radio" name="deliver" value="none" /> ' . __('None', 'wp_backup') . '&nbsp;&nbsp;&nbsp;&nbsp&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<br />';
echo '<input type="radio" name="deliver" value="smtp" /> ' . __('Email', 'wp_backup') . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<br />';
echo '<input type="radio" name="deliver" value="http" /> ' . __('Download', 'wp_backup');
echo '</td><td align="left">' . __('Email backup to', 'wp_backup') . ':<br /> <input type="text" name="backup_recipient" size="20" value="' . get_settings('admin_email') . '" /></td></tr>';
echo '<tr class="alternate"><td colspan="2" align="center">';
if (! $WHOOPS) {
	echo __('Use gzip compression', 'wp_backup') . '? <input type="checkbox" checked="checked" name="gzip" value="gzip" /><br />';
	echo '<input type="hidden" name="do_backup" value="backup" />';
	echo '<input type="submit" name="submit" value="' . __('Backup', 'wp_backup') . '!" / >';
} else {
	echo __('WARNING: Your backup directory is <strong>NOT</strong> writable!</strong>', 'wp_backup');
}
echo '</td></tr></form></table>';
echo '</fieldset>';

// this stuff only displays if wp_cron is installed
if (function_exists('wp_cron_db_backup')) {
	echo '<fieldset class="options"><legend>' . __('Scheduled', 'wp_backup') . ' ' . __('Backup', 'wp_backup') . '</legend>';
	echo '<p>' . __('Last WP-Cron Daily Execution', 'wp_backup') . ': ' . date('Y-m-d @ h:i', get_option('wp_cron_daily_lastrun')) . '<br />';
	echo __('Next WP-Cron Daily Execution', 'wp_backup') . ': ' . date('Y-m-d @ h:i', (get_option('wp_cron_daily_lastrun') + 86400)) . '</p>';
	echo '<form method="post">';
	echo '<table width="100%" callpadding="5" cellspacing="5">';
	echo '<tr><td align="center">';
	echo __('Schedule: ', 'wp_backup');
	$wp_cron_backup_schedule = get_option('wp_cron_backup_schedule');
	$schedule = array("None" => 0, "Daily" => 1);
	foreach ($schedule as $name => $value) {
		echo ' <input type="radio" name="cron_schedule"';
		if ($wp_cron_backup_schedule == $value) {
			echo ' checked="checked" ';
		}
		echo 'value="' . $value . '" /> ' . __($name, 'wp_backup');
	}
	echo '</td><td align="center">';
	$cron_recipient = get_option('wp_cron_backup_recipient');
	if (! is_email($cron_recipient)) {
		$cron_recipient = get_settings('admin_email');
	}
	echo __('Email backup to', 'wp_backup') . ': <input type="text" name="cron_backup_recipient" size="20" value="' . $cron_recipient . '" />';
	echo '</td></tr>';
	echo '<tr><td colspan="2" align="center"><input type="hidden" name="wp_cron_backup_options" value="SET" /><input type="submit" name="submit" value="' . __('Submit', 'wp_backup') . '" /></td></tr></table></form>';
	echo '</fieldset>';
}
// end of wp_cron section

echo '</fieldset></div>';

}// end wp_backup_menu()

/////////////////////////////
function wp_cron_db_backup() {

$schedule = intval(get_option('wp_cron_backup_schedule'));
if (0 == $schedule) {
        // Scheduled backup is disabled
        return;
}

global $wp_backup_dir, $wp_backup_error, $table_prefix;
$core_tables = array ($table_prefix . categories,
        $table_prefix . comments,
        $table_prefix . linkcategories,
        $table_prefix . links,
        $table_prefix . options,
        $table_prefix . post2cat,
        $table_prefix . postmeta,
        $table_prefix . posts,
        $table_prefix . users);

$recipient = get_option('wp_cron_backup_recipient');

$backup_file = wp_db_backup(TRUE, $core_tables);
if (FALSE !== $backup_file) {
        wp_deliver_backup ($backup_file, 'smtp', $recipient);
}

return;
} // wp_cron_db_backup

?>
