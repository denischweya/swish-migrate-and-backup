=== Swish Migrate and Backup ===
Contributors: afrothemes
Tags: backup, migration, restore, database, cloud storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress backup and migration plugin with cloud storage support and no limits.

== Description ==

Swish Migrate and Backup is a powerful WordPress plugin that allows you to create full backups of your website, including the database, plugins, themes, uploads, and core files. It supports multiple cloud storage providers and makes site migration seamless.

= Features =

* **Full Site Backups** - Backup your entire WordPress site including database, plugins, themes, and uploads
* **Database-Only Backups** - Create lightweight backups of just your database
* **Files-Only Backups** - Backup only your WordPress files without the database
* **Cloud Storage Support** - Store backups on Amazon S3, Dropbox, or Google Drive
* **Local Storage** - Keep backups on your server
* **Site Migration** - Easily migrate your site to a new domain with automatic URL replacement
* **Search and Replace** - Perform database search and replace operations with serialization support
* **Scheduled Backups** - Set up automatic backups on your preferred schedule
* **Chunked Processing** - Handle large sites without memory issues
* **Encrypted Credentials** - Cloud storage credentials are encrypted using AES-256-CBC
* **REST API** - Full REST API for integration with other tools

= Cloud Storage =

This plugin connects to the following external services when configured:

* **Amazon S3** - For storing backups on AWS S3. Requires AWS access credentials. [AWS Privacy Policy](https://aws.amazon.com/privacy/)
* **Dropbox** - For storing backups on Dropbox. Requires Dropbox API credentials. [Dropbox Privacy Policy](https://www.dropbox.com/privacy)
* **Google Drive** - For storing backups on Google Drive. Requires Google API credentials. [Google Privacy Policy](https://policies.google.com/privacy)

No data is sent to external services unless you explicitly configure and enable cloud storage.

= Requirements =

* WordPress 6.0 or higher
* PHP 8.1 or higher
* Write access to wp-content/uploads directory

== Installation ==

1. Upload the `swish-migrate-and-backup` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Swish Backup' in your admin menu to access the dashboard
4. Configure your preferred storage settings under 'Swish Backup > Settings'
5. Create your first backup from the dashboard

== Frequently Asked Questions ==

= How large of a site can this plugin handle? =

The plugin uses chunked processing for both database and file operations, allowing it to handle sites of virtually any size without running into memory limits.

= Where are backups stored by default? =

By default, backups are stored locally in `wp-content/uploads/swish-backups/`. You can configure additional cloud storage destinations in the settings.

= Can I schedule automatic backups? =

Yes, you can set up scheduled backups from the Schedules page. Choose your preferred frequency (hourly, daily, weekly, or monthly) and the plugin will automatically create backups.

= How do I migrate my site to a new domain? =

1. Create a full backup on your source site
2. Install and activate the plugin on your destination site
3. Upload the backup file or connect to cloud storage
4. Use the Migration tool to restore and automatically update URLs

= Are my cloud storage credentials secure? =

Yes, all cloud storage credentials are encrypted using AES-256-CBC encryption before being stored in the database.

= Can I restore a backup to a different site? =

Yes, you can download a backup and upload it to any WordPress site with this plugin installed, then use the migration tool to update URLs.

== Screenshots ==

1. Dashboard - Overview of your backups and storage status
2. Backups - List of all available backups with restore and download options
3. Settings - Configure storage adapters and backup options
4. Migration - Migrate your site or perform search and replace operations
5. Schedules - Set up automatic scheduled backups

== Changelog ==

= 1.0.1 =
* Added option to include/exclude WordPress core files in backups
* Enhanced CSS for admin layout with responsive design
* Refactored JavaScript asset enqueuing for improved performance
* Minor bug fixes and improvements

= 1.0.0 =
* Initial release
* Full site, database, and files backup support
* Local storage adapter
* Amazon S3 storage adapter
* Dropbox storage adapter
* Google Drive storage adapter
* Site migration with URL replacement
* Search and replace functionality
* Scheduled backups
* REST API endpoints

== Upgrade Notice ==

= 1.0.1 =
This update adds the option to include or exclude WordPress core files from backups and includes various UI improvements.

== Privacy Policy ==

This plugin stores backup files that may contain personal data from your WordPress database (such as user emails, names, and content). Backups are stored either locally on your server or on third-party cloud storage services that you configure.

When using cloud storage providers (Amazon S3, Dropbox, Google Drive), your backup data is transmitted to and stored on their servers according to their respective privacy policies.

The plugin does not collect or transmit any data to the plugin author or any other third party beyond the cloud storage services you explicitly configure.
