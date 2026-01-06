# GitHub Auto-Updates Setup Guide

This guide explains how to set up automatic WordPress plugin updates from GitHub releases.

## Overview

The plugin now includes GitHub update checking functionality. When you publish a new release on GitHub, WordPress sites will automatically detect it and allow one-click updates.

## Setup Steps

### 1. Configure GitHub Repository

In `cenexel-location-leads.php`, update these constants with your GitHub information:

```php
const GITHUB_USERNAME = 'your-username'; // Your GitHub username
const GITHUB_REPO = 'cenexel-location-leads'; // Your repository name
```

**Example:**
```php
const GITHUB_USERNAME = 'CenExel';
const GITHUB_REPO = 'cenexel-location-leads';
```

### 2. Create GitHub Releases

When you run `./create-release.sh`, you can optionally publish to GitHub:

#### Option A: Automatic (Recommended)

1. Install GitHub CLI:
   ```bash
   brew install gh  # macOS
   # or visit https://cli.github.com/
   ```

2. Authenticate:
   ```bash
   gh auth login
   ```

3. Set environment variables (optional):
   ```bash
   export GITHUB_TOKEN=your_github_token
   export GITHUB_USERNAME=your-username
   export GITHUB_REPO=cenexel-location-leads
   ```

4. Run the release script - it will ask if you want to publish:
   ```bash
   ./create-release.sh
   ```

#### Option B: Manual

1. Run the release script to create the zip:
   ```bash
   ./create-release.sh
   ```

2. Go to your GitHub repository
3. Click "Releases" → "Draft a new release"
4. Tag version: `v0.6.0` (match your version number with 'v' prefix)
5. Release title: `v0.6.0` (or descriptive title)
6. Description: Copy release notes from `release.json`
7. Attach the zip file from `releases/` directory
8. Click "Publish release"

### 3. Release Naming Requirements

**Important:** GitHub release tags must match the version format:
- Plugin version: `0.6.0`
- GitHub tag: `v0.6.0` (with 'v' prefix)

The zip file should be named: `cenexel-location-leads-v0.6.0.zip`

### 4. How It Works

1. WordPress checks GitHub API every hour for new releases
2. Compares current plugin version with latest GitHub release
3. If a newer version exists, shows update notification in WordPress admin
4. User clicks "Update Now" → WordPress downloads and installs from GitHub
5. Plugin automatically reactivates after update

### 5. Testing Updates

To test the update mechanism:

1. Publish a release with version `0.6.1` (or higher)
2. Wait a few minutes (or clear transients)
3. Go to WordPress Admin → Plugins
4. You should see "Update Available" notification
5. Click "Update Now"

### 6. Troubleshooting

#### Updates Not Showing

- Check that GitHub constants are set correctly in PHP
- Verify the GitHub release tag matches version format (`v0.6.0`)
- Check that the zip file is attached to the GitHub release
- Clear WordPress transients: `delete_transient('cenexel_location_leads_github_release')`

#### Manual Transient Clear (for testing)

Add this to `wp-config.php` temporarily:
```php
add_action('admin_init', function() {
    delete_transient('cenexel_location_leads_github_release');
});
```

#### API Rate Limits

GitHub API allows 60 requests/hour for unauthenticated requests. The plugin caches responses for 1 hour to minimize API calls.

For higher limits, you can use a GitHub Personal Access Token (optional):
- Create token at: https://github.com/settings/tokens
- Add to `wp-config.php`: `define('GITHUB_TOKEN', 'your_token_here');`
- Modify the plugin to use this token in API requests (advanced)

### 7. Security Notes

- The plugin validates all update data from GitHub
- Downloads only happen from GitHub's official CDN
- File integrity is checked by WordPress during installation
- Always verify GitHub releases before publishing

### 8. Alternative: GitHub Updater Plugin

If you prefer, you can use the popular "GitHub Updater" plugin instead:
- Install: https://github.com/afragen/github-updater
- It handles GitHub releases automatically
- Works with private repositories too

However, the built-in solution is lighter and doesn't require an additional plugin.

## Workflow Example

```bash
# 1. Make your changes
# 2. Update release.json notes if needed
# 3. Run release script (auto-bumps version)
./create-release.sh

# 4. Review the changes and version bump
# 5. If prompted, publish to GitHub (Y)
# 6. Done! WordPress sites will see the update within 1 hour
```

## Benefits

✅ **No manual updates needed** - Sites update automatically  
✅ **Version control** - All releases tracked in GitHub  
✅ **Easy rollback** - Just create a new release with previous version  
✅ **Centralized** - One source of truth for releases  
✅ **Secure** - WordPress validates all downloads

