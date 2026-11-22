=== Simple CSV Mailer ===
Contributors: khrlzhfr
Tags: email, bulk email, csv, mail merge, wp_mail
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send personalised emails to a CSV list using wp_mail(), with merge tags, attachments and a sending rate, from Tools → Mailer.

== Description ==

Simple CSV Mailer sends one email per row of a CSV file through WordPress's own `wp_mail()`, so it uses whatever mail service your site already has (for example an SMTP or Amazon SES plugin).

* **Merge tags** – every CSV column becomes a tag such as `%first_name%` that you can use in the subject, body and attachment URLs.
* **Attachments** – one URL per line. Files on your site are read from disk; others are downloaded once and cached. A column can hold a different file for each person.
* **Sending rate** – choose how many emails to send every so many seconds. Keep the tab open and it sends in batches.
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

Sending stops while the tab is closed. Open Tools → Mailer and select **Resume** under Paused to continue where it stopped.

= I used an earlier version. Where are my lists? =

Versions before 1.2.0 kept files in `wp-content/mailer/`. They are moved to `wp-content/uploads/mailer/` automatically the first time you open Tools → Mailer after updating.

== Changelog ==

= 1.2.0 =
* First public release as Simple CSV Mailer.
* Files are now kept in the uploads folder; existing lists move there automatically.
* All text can be translated.

== Upgrade Notice ==

= 1.2.0 =
Lists move from wp-content/mailer to wp-content/uploads/mailer automatically.
