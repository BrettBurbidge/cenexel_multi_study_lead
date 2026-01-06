# Debugging Location Image Fields

If the location image is not displaying, the plugin checks multiple meta keys. Here's how to find which field name is being used in your WordPress installation.

## Meta Keys Checked (in order):

1. `location_image`
2. `location_photo`
3. `building_image`
4. `site_image`
5. `facility_image`
6. `image`
7. `photo`
8. `thumbnail_id`
9. `term_image`
10. `featured_image`
11. `jet_term_image`
12. `wpcf-location-image` (Types/Toolset)
13. `acf_location_image` (ACF)
14. `location_thumbnail`
15. `_thumbnail_id`

## Finding the Correct Field Name

### Option 1: Using WordPress Admin

1. Go to your location taxonomy term edit page
2. Look at the URL - note the term ID (e.g., `tag_ID=123`)
3. Check what custom fields are visible on that page
4. Note the exact field name (it might be different from the label)

### Option 2: Using Database Query

Run this SQL query in phpMyAdmin or your database tool (replace `123` with your term ID):

```sql
SELECT meta_key, meta_value
FROM wp_termmeta
WHERE term_id = 123
AND (
  meta_key LIKE '%image%'
  OR meta_key LIKE '%photo%'
  OR meta_key LIKE '%thumbnail%'
  OR meta_key LIKE '%featured%'
)
ORDER BY meta_key;
```

### Option 3: Adding Temporary Debug Code

Add this temporarily to `render_location_hero()` function to see what's being found:

```php
// Temporary debug - remove after finding the field
error_log('Term ID: ' . $term_id);
error_log('Image URL: ' . $image_url);
error_log('Term meta keys: ' . print_r(get_term_meta($term_id), true));
```

Then check your WordPress debug.log file.

### Option 4: Using a Plugin

Install a plugin like "Show Current Screen" or use the "Query Monitor" plugin to inspect term meta values.

## If Image is Still Not Found

1. **Check if it's stored as a post attachment**: Some themes store location images as post attachments rather than term meta
2. **Check if it's in a custom table**: Some plugins store data in custom tables
3. **Check ACF field name format**: ACF might store as `field_XXXXX` - you'd need to check ACF field settings
4. **Check for nested arrays**: The image might be stored as `meta_key['url']` or similar

## Adding Custom Meta Key

If you find a meta key that's not in the list, you can add it to the `resolve_term_image_url()` function in `cenexel-location-leads.php`:

```php
$candidates = [
  'your_custom_field_name',  // Add here
  'location_image',
  // ... rest of the list
];
```

## Common WordPress Field Plugins

- **ACF (Advanced Custom Fields)**: Usually stores as `field_XXXXX` or the field name
- **Types/Toolset**: Usually `wpcf-` prefix
- **JetEngine**: Usually `jet_term_image` or similar
- **Pods**: Field name might be different
- **Meta Box**: Depends on configuration

Check your specific plugin's documentation for how term meta is stored.
