# Deployment Guide

This guide explains how to create and manage plugin releases.

## Quick Start

1. Update `release.json` with your new version and release notes
2. Run the release script:
   ```bash
   ./create-release.sh
   ```
3. The zip file will be created in the `releases/` directory

## Release Process

### 1. Update Release Information

Edit `release.json` to update:
- **version**: Semantic version (e.g., "0.4.5", "1.0.0")
- **date**: Release date in YYYY-MM-DD format
- **notes**: Array of release notes/changelog items
- **author**: Your name or organization
- **plugin_slug**: Plugin directory name (usually unchanged)

Example:
```json
{
  "version": "0.4.6",
  "date": "2024-01-20",
  "notes": [
    "Fixed bug in form submission",
    "Improved error handling",
    "Updated styling"
  ],
  "author": "CenExel",
  "plugin_slug": "cenexel-location-leads"
}
```

### 2. Update Plugin Version

Make sure the version in `cenexel-location-leads.php` matches `release.json`:

```php
/**
 * Plugin Name: CenExel Location Lead Landing
 * Version: 0.4.6  // Update this to match release.json
 */
```

### 3. Create Release

Run the release script:
```bash
./create-release.sh
```

The script will:
- Read version from `release.json`
- Create a zip file named `cenexel-location-leads-v{version}.zip`
- Place it in the `releases/` directory
- Display release information and notes

### 4. What Gets Included

The release zip includes:
- `cenexel-location-leads.php` (main plugin file)
- `assets/` directory (CSS and JS files)
- `readme.md` (if present)

**Excluded:**
- `.git/` directory
- `releases/` directory
- Script files (`.sh` files)
- Configuration files (`.json` files)
- Other development files

## Release File Structure

```
releases/
  ├── cenexel-location-leads-v0.4.5.zip
  ├── cenexel-location-leads-v0.4.6.zip
  └── ...
```

Each zip file contains:
```
cenexel-location-leads/
  ├── cenexel-location-leads.php
  ├── assets/
  │   ├── cenexel-location-leads.css
  │   └── cenexel-location-leads.js
  └── readme.md
```

## Installation

To install a release on a WordPress site:

1. Extract the zip file
2. Upload the `cenexel-location-leads` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress admin

Or upload the zip directly via WordPress admin:
1. Go to Plugins → Add New
2. Click "Upload Plugin"
3. Select the zip file
4. Click "Install Now"

## Troubleshooting

### Script fails with "jq not found"
The script works without `jq`, but it's recommended for better JSON parsing. Install with:
- macOS: `brew install jq`
- Linux: `apt-get install jq` or `yum install jq`

### Zip file already exists
The script will prompt you before overwriting. You can also manually delete old releases from the `releases/` directory.

### Version mismatch
Ensure the version in `release.json` matches the version in the PHP file header comment for consistency.

## Best Practices

1. **Version Numbering**: Use semantic versioning (MAJOR.MINOR.PATCH)
   - MAJOR: Breaking changes
   - MINOR: New features (backward compatible)
   - PATCH: Bug fixes

2. **Release Notes**: Write clear, concise notes about what changed
   - Focus on user-facing changes
   - Include bug fixes
   - Mention new features

3. **Testing**: Always test the zip file before deployment
   - Extract and verify all files are present
   - Test plugin activation
   - Verify functionality

4. **Git**: Commit `release.json` changes but ignore `releases/` folder
   - The `.gitignore` file is already configured for this

## Advanced Usage

### Manual Release Creation

If you need to customize the release process, you can manually create the zip:

```bash
# Create temporary directory
mkdir -p temp/cenexel-location-leads

# Copy files
cp cenexel-location-leads.php temp/cenexel-location-leads/
cp -r assets temp/cenexel-location-leads/
cp readme.md temp/cenexel-location-leads/

# Create zip
cd temp
zip -r ../releases/cenexel-location-leads-v0.4.5.zip cenexel-location-leads

# Cleanup
cd ..
rm -rf temp
```
