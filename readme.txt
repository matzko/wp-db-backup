=== WP-DB-Backup ===
Contributors: filosofo, skippy, Firas, LaughingLizard, MtDewVirus, Podz, Ringmaster
Donate link: http://www.ilfilosofo.com/blog/wp-db-backup/
Tags: mysql, database, backup, cron
Requires at least: 2.0.3
Tested up to: 2.3
Stable tag: 2.1.2

On-demand backup of your WordPress database.

== Description ==

WP-DB-Backup allows you easily to backup your core WordPress database tables.  You may also backup other tables in the same database.

Released under the terms of the GNU GPL, version 2.
   http://www.fsf.org/licensing/licenses/gpl.html

              NO WARRANTY.

	Copyright (c) 2007 Austin Matzko

== Installation ==
1. Copy the wp-db-backup.php file to /wp-content/plugins/
1. Activate the plugin at your blog's Admin -> Plugins screen
1. The plugin will attempt to create a directory /wp-content/backup-*/ inside your WordPress directory.
1. You may need to make /wp-content writable (at least temporarily) for it to create this directory. 
   For example:
   `$ cd /wordpress/`
   `$ chgrp www-data wp-content` (where "`www-data`" is the group your FTP client uses)
   `$ chmod g+w backup`

== Frequently Asked Questions ==

= What are wp-db-backup.mo and wp-db-backup.pot for? =

These files are used by non-English users to translate the display into their native language.  Translators are encouraged to send me translated files, which will be made available to others here:
http://www.ilfilosofo.com/blog/wp-db-backup/i18n/
http://dev.wp-plugins.org/browser/wp-db-backup/i18n/

= Why are only the core database files backed up by default? =

Because it's a fairly safe bet that the core WordPress files will be successfully backed up.  Plugins vary wildly in the amount of data that they store.  For instance, it's not uncommon for some statistics plugins to have tens of megabytes worth of visitor statistics.  These are not exactly essential items to restore after a catastrophic failure.  Most poeple can reasonably live without this data in their backups.

= Will you add a button so that I can automatically select all my other tables to back up? =

No.  Such a button would encourage people to click it.  The way it is now, you must deliberately select which additional tables to include in the backup.  This is a safety mechanism as much for me as it is for you.

== Usage ==
1. Click the Manage menu in your WordPress admin area.
1. Click the Backup sub-menu.

The following core WordPress tables will be included in every backup:
* wp_categories
* wp_comments
* wp_linkcategories / wp_link2cat
* wp_links
* wp_options
* wp_post2cat
* wp_postmeta
* wp_posts
* wp_users
(Where "wp_" will automatically be replaced by whatever table prefix you use.)

1. The plugin will look for other tables in the same database.  You may elect to include other tables in the backup.
  ** NOTE **
  Including other tables in your backup may substantially increase the size of the backup file!
  This may prevent you from emailing the backup file because it's too big.

1. Select how you'd like the backup to be delivered:
* Save to server : this will create a file in /wp-content/backup-*/ for you to retreive later
* Download to your computer : this will send the backup file to your browser to be downloaded
* Email : this will email the backup file to the address you specify

1. Click "Backup!" and your database backup will be delivered to you.

The filename of the backup file will be of the form
   DB_prefix_date.sql
DB = the name of your WordPress database, as defined in wp-config.php
prefix = the table prefix for this WordPress blog, as defined in wp-config.php
date = CCYYmmdd_B format:  20050711_039
       the "B" is the internet "Swatch" time.  
       See the PHP date() function for details.

When having the database backup emailed or sent to your browser for immediate download, the backup file will be _deleted_ from the server when the transfer is finished.  Only if you select delivery method "Save to server" will the backup file remain on your server.

   *** SECURITY WARNING ***
   Your database backup contains sensitive information,
   and should not be left on the server for any extended
   period of time.  The "Save to server" delivery method is provided
   as a convenience only.  I will not accept any responsibility
   if other people obtain your backup file.
   *** SECURITY WARNING ***

== Advanced ==
If you are using WordPress version 2.1 or newer, you can schedule automated backups to be sent to the email address 
of your choice.

== Changelog ==
2.0
Support for WordPress 2.1's built-in cron, for automated scheduled backups.

1.7
Better error handling.  Updated documentation.

1.6
Integrated Owen's massive rewrite, the most noticable element being the introduction of a progress meter.  The backup is now spooled to disk, a few rows at a time, to ensure that databases of all sizes can be backed up.  Additionally, gzip support is now automatically detected, and used if available.  This has been tested on a database over 30 megabytes uncompressed.  Version 1.6 of wp-db-backup successfully backed up the whole thing without error, and transparently compressed it to just over 10 megabytes (many thanks to Lorelle for being such a willing guinea pig!).

1.5
Applied patch from Owen (http://dev.wp-plugins.org/ticket/219)
 -- the database dump is now spooled to disk to better support large databases.
 If the user has selected immediate delivery, the file size will be evaluated.  If less than 2 MB, the file will be gzip compressed (if the user asked for it); otherwise a helpful error message will be displayed.

1.4
Initial relase.
