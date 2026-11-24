=== khrlzhfr Simple CSV Mailer ===
Contributors: khrlzhfr
Tags: email, bulk email, csv, mail merge, wp_mail
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send personalised emails to a CSV list using wp_mail(), with merge tags, attachments and a sending rate, from Tools → Mailer.

== Description ==

khrlzhfr Simple CSV Mailer sends one email per row of a CSV file through WordPress's own `wp_mail()`, so it uses whatever mail service your site already has (for example an SMTP or Amazon SES plugin).

* **Merge tags** – every CSV column becomes a tag such as `%first_name%` that you can use in the subject, body and attachment URLs.
* **Attachments** – one URL per line. Files on your site are read from disk; others are downloaded once and cached. A column can hold a different file for each person.
* **Background and scheduled sending** – choose how many emails to send every so many seconds, now or at a date and time. Sending runs in the background, so you can close the page.
* **Test first** – send a test to yourself using the first recipient's data.
* **Pause and resume** – each row gets an `email_status` (Sent, Skipped, Invalid or Failed) with a timestamp, so a paused list continues where it stopped.
* **Retry failed** – make a new list from the rows that failed.
* **Records** – when a list is done it is renamed `completed_…` and a copy of the email is saved next to it. Both can be downloaded.

Editors and administrators can use it.

= Use with care =

Only send to people who have asked to hear from you. Never send to purchased or scraped lists. Every email goes out from your site's mail service and under its reputation.

= Where files are kept =

Lists and records are stored in `wp-content/uploads/mailer/` (each site's own uploads folder on multisite), with an `.htaccess` file that blocks direct downloads on Apache. On Nginx, add:

`location ^~ /wp-content/uploads/mailer/ { deny all; }`

File names also include a random string. Delete lists when you no longer need them.

== External services ==

This plugin only connects to other sites when you list an attachment URL that is not on your own site. It then downloads that file once (cached for a day) to attach it to the emails. Nothing else is sent anywhere by the plugin itself. Emails are sent with `wp_mail()` through whatever mail service your site uses.

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools → Mailer** and select **Upload CSV**. The CSV needs a header row and an `email` column.
3. Fill in the From address, subject and body, send a test, then select **Send**.

== Frequently Asked Questions ==

= What does the CSV need? =

A header row and a column called `email` (or a column with "email" in its name). Every other column becomes a merge tag.

= Why are some emails not delivered? =

The plugin hands each email to `wp_mail()`. Delivery depends on your site's mail setup. The From address must be one your mail service allows, and some SMTP plugins replace it with their own.

= Can I close the tab while sending? =

Yes. Sending runs in the background with WP-Cron, so you can close the page. Progress is shown under Queue on Tools → Mailer, with Pause, Resume and Cancel. You can also choose a date and time to send.

= When does background sending run? =

WP-Cron runs when someone visits the site, so on a quiet site a scheduled send can start late. For reliable timing, have a server cron job or an uptime monitor request `https://your-site/wp-cron.php` every few minutes. See [Hooking WP-Cron Into the System Task Scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). Once a send has started, it carries on by itself at your chosen rate.

= I used an earlier version. Where are my lists? =

Versions before 1.2.0 kept files in `wp-content/mailer/`. They are moved to `wp-content/uploads/mailer/` automatically the first time you open Tools → Mailer after updating.

== Changelog ==

= 1.4.0 =
* Renamed to khrlzhfr Simple CSV Mailer. The folder and text domain are now `khrlzhfr-simple-csv-mailer`. To switch a site, install this version, then delete the old copy: settings and data are kept.
* If two copies are installed, only one runs and the Plugins screen says which. Deleting a copy no longer removes data another installed copy still uses.
* Downloaded CSVs are safe to open in a spreadsheet: values that would run as a formula get a leading apostrophe in the download. The stored list and the emails are unchanged.

= 1.3.0 =
* Sending runs in the background with WP-Cron, so the page no longer has to stay open.
* Schedule a send for a date and time.
* New Queue table with Pause, Resume and Cancel.
* The person who started a send sees the result the next time they open Mailer.

= 1.2.0 =
* First public release as Simple CSV Mailer.
* Files are now kept in the uploads folder; existing lists move there automatically.
* All text can be translated.

== Upgrade Notice ==

= 1.4.0 =
New name and folder. Install this version first, then delete the old copy; your settings and data are kept.

= 1.3.0 =
Background and scheduled sending. Lists paused in 1.2.0 appear under Queue; select Resume to finish them.

= 1.2.0 =
Lists move from wp-content/mailer to wp-content/uploads/mailer automatically.
