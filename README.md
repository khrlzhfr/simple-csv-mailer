# Simple CSV Mailer

By [Khairil Zhafri](https://khrlzh.fr). Version 1.2.0. Requires WordPress 6.4+ and PHP 7.4+. Folder, main file and text domain: `simple-csv-mailer`.

Send personalised emails to a CSV list using `wp_mail()`, from **Tools → Mailer**. Editors and administrators can use it.

- **Merge tags:** every CSV column becomes a tag such as `%first_name%` for the subject, body and attachment URLs.
- **Attachments:** one URL per line; site files are read from disk, others downloaded once and cached. Up to 10 MB per email.
- **Sending rate:** how many emails every so many seconds. Keep the tab open and it sends in batches.
- **Test, pause, resume, retry:** each row gets an `email_status` with a timestamp, so a paused list continues where it stopped, and failed rows can be sent again.
- **Records:** finished lists are renamed `completed_…` with a copy of the email saved alongside.

## Installing

**From GitHub (gets updates straight away):** download `simple-csv-mailer.zip` from the [latest release](https://github.com/khrlzhfr/simple-csv-mailer/releases/latest), then upload it under Plugins → Add New → Upload Plugin. New versions appear under Dashboard → Updates as soon as they are released on GitHub; select **Enable auto-updates** next to the plugin to install them automatically.

**From WordPress.org:** once approved in the Plugin Directory, install **Simple CSV Mailer** from Plugins → Add New. Updates arrive through WordPress.org.

Both are the same plugin. The GitHub copy also contains an updater (`includes/github-updater.php` and `lib/plugin-update-checker`, [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), MIT). WordPress.org doesn't allow updaters, so its copy leaves out everything listed in `.distignore`.

### Releasing a new version

1. Bump `Version:` and `SCM_VERSION` in `simple-csv-mailer.php`, `Stable tag:` in `readme.txt`, and add a changelog entry to both readmes.
2. Commit, then publish a release with a matching tag (for example `v1.2.1`) on GitHub's Releases page, or push the tag.
3. The Release workflow checks the version and attaches `simple-csv-mailer.zip`. Sites installed from GitHub see the update straight away.
4. Once the plugin is approved on WordPress.org, copy the same version to its SVN repository, leaving out the files in `.distignore`.

## Files

Lists and records live in `wp-content/uploads/mailer/` (`uploads/sites/{ID}/mailer/` on multisite), protected by `.htaccess` on Apache. On Nginx add:

```
location ^~ /wp-content/uploads/mailer/ { deny all; }
```

Versions before 1.2.0 used `wp-content/mailer/`; it is moved automatically.

Deleting the plugin removes its jobs, drafts and the `uploads/mailer` folder from every site.

## Changelog

### 1.2.0
- First public release as **Simple CSV Mailer**.
- Files moved to the uploads folder, with automatic migration.
- Styles and scripts loaded the WordPress way; form handling redirects on the server.
- All text translatable.

### 1.1.0
- Attachments, merge tags in attachment URLs, retry failed rows.
