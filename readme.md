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

## Auto-Updates

The plugin automatically checks for updates from GitHub. When a new release is published, WordPress will notify you and allow one-click updates.

See `GITHUB-UPDATES.md` for more details.

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
