# Jeco IP Blacklist

## Description

Jeco IP Blacklist is a WordPress plugin that automatically downloads the latest IP blacklists from [Myip.ms](https://myip.ms/) and safely injects them into your `.htaccess` file to block spam bots. Configurable via the WordPress admin dashboard.

## Author

Jesus Carrero  
GitHub: [Jesusjeco](https://github.com/Jesusjeco)

---

## Features

- **Works on any host** — Fetches the blacklist using WordPress's built-in connection system, so it works reliably regardless of how your hosting provider is configured.
- **Non-destructive updates** — The plugin writes the blacklist into its own clearly marked section in your server configuration file, leaving all your other existing rules completely untouched.
- **Daily cron job** — Configurable run time; scheduled via WP-Cron.
- **Manual "Update Now" button** — Trigger a blacklist update instantly from the settings page without waiting for the cron.
- **Activity log** — Last 10 runs displayed on the settings page (timestamp + success/failure + message).
- **Status dashboard** — Shows the next scheduled run time and the result of the most recent update at a glance.
- **Automatic `.htaccess` backup** — Creates a timestamped `.htaccess_jeco_backup_*.bak` file before every write operation.

---

## Requirements

- WordPress 6.0+
- PHP 8.2+

---

## Installation

1. Clone the repository into your plugins directory:

   ```bash
   git clone https://github.com/Jesusjeco/jeco-ip-blacklist.git wp-content/plugins/jeco-ip-blacklist
   ```

   Or upload the plugin ZIP via **Plugins > Add New > Upload Plugin**.

2. Activate the plugin from the WordPress admin under **Plugins**.

3. After activating, deactivate and re-activate the plugin once to ensure the WP-Cron job is properly registered.

---

## Configuration

1. Go to **Jeco Blacklist** in the WordPress admin sidebar.
2. Set your desired **Cron Job Start Time (HH:MM)**.
3. Click **Save Changes** — the cron job will be rescheduled to the new time automatically.

---

## Usage

Once configured, the plugin will:

1. Download the latest IP blacklist (and user-submitted list) from Myip.ms daily at the configured time.
2. Back up your current `.htaccess` file with a timestamped filename.
3. Inject the new deny rules into a dedicated `# BEGIN Jeco IP Blacklist … # END Jeco IP Blacklist` block.
4. Log the result (success or error) to the Activity Log on the settings page.

Use the **Update Now** button to run the process on demand at any time.

---

## File Structure

```
jeco-ip-blacklist/
├── jeco-ip-blacklist.php              # Main plugin file (all logic lives here)
├── phpscript_httaccess_wordpress.php  # Deprecated — kept for reference only
└── README.md
```

---

## Acknowledgments

The IP blacklist data used by this plugin is provided by [Myip.ms](https://myip.ms/browse/blacklist/).

---

## Changelog

### 2.0.0

- Replaced `file_get_contents()` with `wp_remote_get()` for robust HTTP requests.
- Replaced manual `.htaccess` string manipulation with `insert_with_markers()`.
- Removed `die()` from the cron/update flow; errors are now logged gracefully.
- Added "Update Now" manual trigger button with live AJAX feedback.
- Added Activity Log (last 10 runs) stored in WP options.
- Added status dashboard (next run, last update result).
- Added automatic `.htaccess` backup before every write.
- Consolidated all logic into the `JECO_IPBL` class; deprecated `phpscript_httaccess_wordpress.php`.
- Full PHP 8.4 type declarations throughout.

### 1.0.0

- Initial release.
