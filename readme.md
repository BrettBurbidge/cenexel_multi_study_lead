# CenExel Location Lead Landing Plugin

WordPress plugin for CenExel Clinical Trials that creates location-specific landing pages with study listings and lead capture forms.

## Features

- **Location-Based Landing Pages**: Dynamic pages for each CenExel location
- **Study Selection**: Multi-step form flow for selecting clinical trials
- **Lead Capture**: Comprehensive form with validation and Azure integration
- **Auto-Updates**: Automatic updates via GitHub releases
- **Responsive Design**: Mobile-friendly interface

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/BrettBurbidge/cenexel_multi_study_lead/releases)
2. Upload the zip file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin
4. Add the shortcode `[cenexel_location_landing]` to any page

## Usage

### Basic Usage

Add the shortcode to a page:

```
[cenexel_location_landing]
```

### URL Parameters

The plugin supports two URL parameter formats:

**Standard format:**

```
/studies?site=anaheim-ca
```

**Legacy format:**

```
/studies?_location_city_state=cenexel-anaheim--ca
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Configuration

The plugin automatically detects location data from:

- Location taxonomy terms
- Location custom post types

### Required Meta Fields

For locations to display properly, ensure these meta fields exist:

- `address` or `location_address`
- `city` or `location_city`
- `state` or `location_state`
- `zip` or `zipcode`
- `phone` or `location_phone`
- Location image (multiple field names supported)

See `DEBUG-IMAGE-FIELDS.md` for troubleshooting image fields.

### Debugging

To enable debug logging for this plugin only (without affecting other plugins), add this to your `wp-config.php`:

```php
define('CENEXEL_LOCATION_LEADS_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false); // Don't show errors on frontend
```

This will log debug information to `/wp-content/debug.log` when:

- Looking up location images
- Resolving location meta fields
- Finding attachment IDs

The debug logs will help identify which meta keys are being used for location data. Once you've found the correct fields, you can disable debugging by removing or setting the constant to `false`.

## Auto-Updates

This plugin includes built-in GitHub auto-update functionality. When a new release is published on GitHub, WordPress will automatically detect it and allow one-click updates from the admin dashboard.

### How It Works

1. **Automatic Checking**: WordPress periodically checks for plugin updates. This plugin hooks into that process to check GitHub releases.

2. **Version Comparison**: The plugin compares the installed version against the latest GitHub release tag (e.g., `v0.9.3`).

3. **Update Notification**: If a newer version is available, WordPress displays an update notification in the Plugins page.

4. **One-Click Update**: Click "Update Now" to download and install the new version directly from GitHub.

### Technical Details

The auto-update system uses these WordPress filters:

- `pre_set_site_transient_update_plugins` - Checks for new versions
- `plugins_api` - Provides plugin information for the update modal
- `upgrader_post_install` - Handles post-installation cleanup (renames extracted folder)

### GitHub Configuration

The plugin is configured to check releases from:

```
https://github.com/BrettBurbidge/cenexel_multi_study_lead
```

Release tags must follow the format `v0.0.0` (e.g., `v0.9.3`).

### Publishing Updates

To publish a new update:

```bash
./create-release.sh
```

When prompted, choose to publish to GitHub. This will:

1. Increment the patch version (e.g., `0.9.2` → `0.9.3`)
2. Create a zip file
3. Create a GitHub release with the `v0.9.3` tag
4. Upload the zip as a release asset

All WordPress installations with this plugin will see the update within a few hours (or immediately if they check for updates manually).

### Manual Update Check

To force WordPress to check for updates:

1. Go to **Dashboard → Updates**
2. Click **Check Again**

Or visit: `wp-admin/update-core.php?force-check=1`

### Troubleshooting

If updates aren't appearing:

1. **Check GitHub Release**: Ensure the release exists and has a zip file attached
2. **Check Version Format**: Release tag must start with `v` (e.g., `v0.9.3`)
3. **Check Plugin Version**: Ensure the installed version is lower than the release
4. **Enable Debug Mode**: Set `WP_DEBUG` to `true` to see error logs
5. **Clear Transients**: Delete `update_plugins` transient in the database

See `GITHUB-UPDATES.md` for detailed setup instructions.

## Development

### Creating Releases

```bash
./create-release.sh
```

This will:

- Auto-increment the version number
- Create a zip file in the `releases/` directory
- Optionally publish to GitHub (if GitHub CLI is configured)

### Version Bumping

```bash
./bump-version.sh 0.7.0
```

Manually set a specific version number.

## File Structure

```
cenexel-location-leads/
├── cenexel-location-leads.php  # Main plugin file
├── assets/
│   ├── cenexel-location-leads.css
│   └── cenexel-location-leads.js
├── readme.md                   # WordPress plugin readme
├── release.json                # Release metadata
└── releases/                   # Built releases (gitignored)
```

## Support

For issues, feature requests, or questions, please open an issue on [GitHub](https://github.com/BrettBurbidge/cenexel_multi_study_lead/issues).

## License

Copyright © CenExel. All rights reserved.
