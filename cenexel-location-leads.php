<?php
/**
  * Plugin Name: CenExel Location Lead Landing
  * Description: /studies?site=<slug> or /studies?_location_city_state=<legacy> lists Clinical Trial posts and submits leads via email.
  * Version: 0.9.47
  */

if (!defined('ABSPATH')) exit;

class Cenexel_Location_Leads {
  const TAXONOMY = 'location';

  // URL params
  const QUERY_PARAM = 'site';
  const LEGACY_QUERY_PARAM = '_location_city_state';

  // term meta alias (optional)
  const TERM_META_LANDING_SLUG = 'landing_slug';

  // GitHub update configuration
  const GITHUB_USERNAME = 'brettburbidge';
  const GITHUB_REPO = 'cenexel_multi_study_lead';

  // Display meta keys (confirmed)
  const META_STUDY_TITLE = 'study_title';
  const META_SUBTITLE    = 'subtitle';

  // Candidate CPT slugs for "Clinical Trial"
  private array $clinical_trial_post_types = [
    'clinical-trial',
    'clinical_trial',
    'clinical-trials',
    'clinicaltrials',
  ];

  // Candidate meta keys for "UTM Term" filtering (keep as-is unless you later confirm exact key)
  private array $utm_term_meta_keys = [
    'utm_term',
    'UTM Term',
    'UTM_Term',
    'utm_fields_utm_term',
    'utm_term_field',
    'wpcf-utm-term',
  ];

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('rest_api_init', [$this, 'register_rest']);
    
    // GitHub update checker
    add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
    add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
  }

  public function register_shortcode() {
    add_shortcode('cenexel_location_landing', [$this, 'render_landing']);
  }

  public function enqueue_assets() {
    // Only load on pages where shortcode exists
    $should_load = false;
    if (is_singular()) {
      global $post;
      if ($post && has_shortcode($post->post_content, 'cenexel_location_landing')) {
        $should_load = true;
      }
    }
    if (!$should_load) return;

    wp_enqueue_style(
      'cenexel-location-leads',
      plugin_dir_url(__FILE__) . 'assets/cenexel-location-leads.css',
      [],
      '0.4.3'
    );

    wp_register_script(
      'cenexel-location-leads-js',
      plugin_dir_url(__FILE__) . 'assets/cenexel-location-leads.js',
      [],
      '0.4.3',
      true
    );

    wp_localize_script('cenexel-location-leads-js', 'CENEXEL', [
      'restUrl' => esc_url_raw(rest_url('cenexel/v1/lead')),
      'nonce'   => wp_create_nonce('wp_rest'),
    ]);

    wp_enqueue_script('cenexel-location-leads-js');
  }

  private function normalize_legacy_location(string $raw): string {
    // "cenexel-anaheim--ca" -> "anaheim-ca"
    $v = strtolower(trim($raw));
    $v = preg_replace('/^cenexel-/', '', $v);
    $v = str_replace('--', '-', $v);
    return sanitize_title($v);
  }

  private function get_requested_site_slug(): string {
    $slug = isset($_GET[self::QUERY_PARAM]) ? sanitize_title(wp_unslash($_GET[self::QUERY_PARAM])) : '';
    if ($slug) return $slug;

    $legacy = isset($_GET[self::LEGACY_QUERY_PARAM]) ? wp_unslash($_GET[self::LEGACY_QUERY_PARAM]) : '';
    if (is_string($legacy) && $legacy !== '') {
      $normalized = $this->normalize_legacy_location($legacy);
      if ($normalized) return $normalized;
    }

    return '';
  }

  private function resolve_location_term(string $requested_slug) {
    if (!$requested_slug) return null;

    $term = get_term_by('slug', $requested_slug, self::TAXONOMY);
    if ($term && !is_wp_error($term)) return $term;

    // Optional alias via term meta landing_slug
    global $wpdb;
    $sql = $wpdb->prepare(
      "SELECT t.term_id
       FROM {$wpdb->termmeta} tm
       INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
       WHERE tt.taxonomy = %s AND tm.meta_key = %s AND tm.meta_value = %s
       LIMIT 1",
      self::TAXONOMY,
      self::TERM_META_LANDING_SLUG,
      $requested_slug
    );

    $term_id = (int)$wpdb->get_var($sql);
    if ($term_id) {
      $term2 = get_term($term_id, self::TAXONOMY);
      if ($term2 && !is_wp_error($term2)) return $term2;
    }

    return null;
  }

  private function resolve_location_post(string $requested_slug) {
    if (!$requested_slug) return null;

    // Try common location post type slugs
    $location_post_types = ['location', 'locations', 'cenexel-location', 'cenexel-locations'];
    
    foreach ($location_post_types as $post_type) {
      if (!post_type_exists($post_type)) continue;
      
      $post = get_page_by_path($requested_slug, OBJECT, $post_type);
      if ($post && $post->post_status === 'publish') {
        return $post;
      }
    }

    // Also try by meta value (landing slug)
    global $wpdb;
    $sql = $wpdb->prepare(
      "SELECT p.ID
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
       WHERE p.post_status = 'publish'
       AND pm.meta_key = %s
       AND pm.meta_value = %s
       LIMIT 1",
      self::TERM_META_LANDING_SLUG,
      $requested_slug
    );

    $post_id = (int)$wpdb->get_var($sql);
    if ($post_id) {
      $post = get_post($post_id);
      if ($post) return $post;
    }

    return null;
  }

  private function get_clinical_trial_post_type(): string {
    foreach ($this->clinical_trial_post_types as $pt) {
      if (post_type_exists($pt)) return $pt;
    }
    return 'therapeutic-area';
  }

  private function get_term_meta_first_nonempty(int $term_id, array $keys): string {
    foreach ($keys as $k) {
      $v = get_term_meta($term_id, $k, true);
      if (is_string($v)) {
        $v = trim($v);
        if ($v !== '') return $v;
      } elseif (is_numeric($v)) {
        $v = trim((string)$v);
        if ($v !== '') return $v;
      }
    }
    return '';
  }

  private function get_post_meta_first_nonempty(int $post_id, array $keys): string {
    foreach ($keys as $k) {
      $v = get_post_meta($post_id, $k, true);
      if (is_string($v)) {
        $v = trim($v);
        if ($v !== '') return $v;
      } elseif (is_numeric($v)) {
        $v = trim((string)$v);
        if ($v !== '') return $v;
      }
    }
    return '';
  }

  private function normalize_study_gender(string $raw): array {
    $v_original = trim($raw);
    if ($v_original === '') return ['key' => 'unknown', 'label' => '—'];
    
    $v = strtolower($v_original);
    
    // Check for "all" cases first
    if (in_array($v, ['all', 'any', 'both', 'either', 'everyone', 'anyone'], true)) {
      return ['key' => 'all', 'label' => 'All'];
    }
    
    // Check if it contains both "male" and "female" (handles "Male, Female", "Male and Female", etc.)
    $has_male = str_contains($v, 'male') || $v === 'm' || str_contains($v, 'man') || str_contains($v, 'men');
    $has_female = str_contains($v, 'female') || $v === 'f' || str_contains($v, 'woman') || str_contains($v, 'women');
    
    if ($has_male && $has_female) {
      return ['key' => 'all', 'label' => 'All'];
    }
    
    // Single gender
    if ($has_female) {
      return ['key' => 'female', 'label' => 'Female'];
    }
    if ($has_male) {
      return ['key' => 'male', 'label' => 'Male'];
    }

    // Unknown format, return original
    return ['key' => 'unknown', 'label' => $v_original];
  }

  private function parse_study_age(int $post_id): array {
    $age_range = $this->get_post_meta_first_nonempty($post_id, [
      'age_range',
      'ages',
      'age',
      'eligibility_age',
      'eligibility_age_range',
      'participant_age_range',
      'eligibility_criteria_age',
    ]);

    $min_raw = $this->get_post_meta_first_nonempty($post_id, [
      'age_range_low',
      'age_low_ta',
      'min_age',
      'minimum_age',
      'age_min',
      'eligibility_min_age',
      'eligibility_age_min',
      'participant_min_age',
    ]);
    $max_raw = $this->get_post_meta_first_nonempty($post_id, [
      'age_range_high',
      'age_high_ta',
      'max_age',
      'maximum_age',
      'age_max',
      'eligibility_max_age',
      'eligibility_age_max',
      'participant_max_age',
    ]);

    $min = is_numeric($min_raw) ? (int)$min_raw : null;
    $max = is_numeric($max_raw) ? (int)$max_raw : null;

    $label = '—';
    if ($age_range !== '') {
      $label = $age_range;
    } elseif ($min !== null || $max !== null) {
      if ($min !== null && $max !== null) {
        // Show range without "Ages" prefix: "18–65"
        $label = "{$min}–{$max}";
      } elseif ($min !== null) {
        // Only min age, no max - show as "18+"
        $label = "{$min}+";
      } else {
        $label = "Up to {$max}";
      }
    }

    $buckets = [];
    // Bucket heuristics; if unknown, leave empty (will only show when no age filter selected)
    if ($min !== null || $max !== null) {
      $lo = $min ?? 0;
      $hi = $max ?? 200;
      if ($lo <= 17) $buckets[] = 'child';
      if ($hi >= 18 && $lo <= 64) $buckets[] = 'adult';
      if ($hi >= 65) $buckets[] = 'older';
    }

    return ['label' => $label, 'buckets' => $buckets, 'min' => $min, 'max' => $max];
  }

  private function parse_study_compensation(int $post_id): array {
    $raw = $this->get_post_meta_first_nonempty($post_id, [
      'compensation_up_to_x',
      'compensation_ta',
      'compensation',
      'study_compensation',
      'participant_compensation',
      'compensation_amount',
      'compensation_text',
      'stipend',
      'payment',
      'reimbursement',
      'pay',
    ]);

    $label = $raw !== '' ? $raw : '—';
    $has = ($raw !== '' && !in_array(strtolower(trim($raw)), ['0', 'none', 'n/a', 'na', 'no'], true)) ? '1' : '0';

    return ['label' => $label, 'has' => $has];
  }

  private function resolve_term_image_url(int $term_id): string {
    // Expanded list with JetEngine and other common meta keys
    $candidates = [
      // JetEngine specific (since you have JetEngine installed)
      'jet_thumbnail',
      'jet_term_thumbnail',
      'jet_thumbnail_id',
      'jet_image',
      'jet_term_image',
      'jet_term_thumbnail_id',
      '_jet_thumbnail_id',
      '_jet_image',
      'jet_location_image',
      'jet_location_thumbnail',
      
      // Location/image specific
      'location_image',
      'location_photo',
      'building_image',
      'site_image',
      'facility_image',
      'location_building_image',
      'header_image',
      'banner_image',
      'hero_image',
      
      // Common WordPress/ACF
      'image',
      'photo',
      'thumbnail_id',
      'term_image',
      'featured_image',
      '_thumbnail_id',
      'term_thumbnail',
      'term_thumbnail_id',
      
      // Toolset/Types
      'wpcf-location-image',
      'wpcf-image',
      'wpcf-featured-image',
      
      // ACF variations
      'acf_location_image',
      'acf_image',
      'field_location_image',
      
      // Meta Box
      'mb_location_image',
      'mb_image',
      
      // Custom variations
      'location_thumbnail',
      'location_featured_image',
      
      // Check all fields that might contain images (based on your actual meta keys)
      '_location_image',
      '_image',
      '_photo',
      '_thumbnail',
    ];

    $raw = $this->get_term_meta_first_nonempty($term_id, $candidates);
    
    if ($raw === '') {
      // Last resort: Query all numeric meta values that might be attachment IDs
      // Exclude known non-image fields like zip codes
      global $wpdb;
      $exclude_keys = ['zip', '_zip', 'phone', '_phone']; // Known non-image numeric fields
      $placeholders = implode(',', array_fill(0, count($exclude_keys), '%s'));
      
      $numeric_meta = $wpdb->get_results($wpdb->prepare(
        "SELECT tm.meta_key, tm.meta_value 
         FROM {$wpdb->termmeta} tm
         WHERE tm.term_id = %d 
         AND tm.meta_value REGEXP '^[0-9]+$'
         AND CAST(tm.meta_value AS UNSIGNED) > 10
         AND tm.meta_key NOT IN ($placeholders)
         ORDER BY CAST(tm.meta_value AS UNSIGNED) DESC
         LIMIT 10",
        array_merge([$term_id], $exclude_keys)
      ));
      
      foreach ($numeric_meta as $meta) {
        $attachment_id = (int)$meta->meta_value;
        // Skip if it looks like a zip code or phone number
        if ($attachment_id < 100) continue;
        
        $url = wp_get_attachment_image_url($attachment_id, 'large');
        if ($url) {
          return esc_url_raw($url);
        }
      }
      
      return '';
    }

    if (filter_var($raw, FILTER_VALIDATE_URL)) {
      return esc_url_raw($raw);
    }

    if (ctype_digit($raw)) {
      $url = wp_get_attachment_image_url((int)$raw, 'large');
      if ($url) return esc_url_raw($url);
      
      // Try full size if large doesn't exist
      $url = wp_get_attachment_image_url((int)$raw, 'full');
      return $url ? esc_url_raw($url) : '';
    }

    return '';
  }

  private function resolve_post_image_url(int $post_id): string {
    // First try featured image
    $thumbnail_id = get_post_thumbnail_id($post_id);
    if ($thumbnail_id) {
      $url = wp_get_attachment_image_url($thumbnail_id, 'large');
      if ($url) {
        return esc_url_raw($url);
      }
    }

    // Then try meta fields (expanded candidates)
    $candidates = [
      // JetEngine
      'jet_thumbnail',
      'jet_thumbnail_id',
      'jet_image',
      '_jet_thumbnail_id',
      '_jet_image',
      
      // Location/image specific
      'location_image',
      'location_photo',
      'building_image',
      'site_image',
      'facility_image',
      'location_building_image',
      'header_image',
      'banner_image',
      'hero_image',
      
      // Common
      'image',
      'photo',
      'thumbnail_id',
      '_thumbnail_id',
      
      // Toolset/ACF
      'wpcf-location-image',
      'acf_location_image',
      'field_location_image',
      
      // Custom variations
      '_location_image',
      '_image',
      '_photo',
    ];

    $raw = $this->get_post_meta_first_nonempty($post_id, $candidates);
    if ($raw === '') return '';

    if (filter_var($raw, FILTER_VALIDATE_URL)) {
      return esc_url_raw($raw);
    }

    if (ctype_digit($raw)) {
      $url = wp_get_attachment_image_url((int)$raw, 'large');
      if ($url) {
        return esc_url_raw($url);
      }
      $url = wp_get_attachment_image_url((int)$raw, 'full');
      return $url ? esc_url_raw($url) : '';
    }

    return '';
  }

  private function build_google_maps_url(string $address, string $city, string $state, string $zip): string {
    // Build a Google Maps search URL from address components
    $parts = array_filter([$address, $city, $state, $zip]);
    $query = urlencode(implode(', ', $parts));
    return "https://www.google.com/maps/search/?api=1&query={$query}";
  }

  /**
   * Find a related location post that matches the taxonomy term
   * This handles the case where locations exist as both taxonomy terms AND custom posts
   */
  private function find_related_location_post($term): ?WP_Post {
    if (!$term || is_wp_error($term)) return null;
    
    $term_name = $term->name;
    $term_slug = $term->slug;
    
    global $wpdb;
    
    // First try exact title match
    $post = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$wpdb->posts} 
       WHERE post_type = 'location' 
       AND post_status = 'publish' 
       AND post_title = %s
       LIMIT 1",
      $term_name
    ));
    
    if ($post) {
      return new WP_Post($post);
    }
    
    // Try partial match (term name contains city name, post title contains same)
    $post = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$wpdb->posts} 
       WHERE post_type = 'location' 
       AND post_status = 'publish' 
       AND (post_title LIKE %s OR post_name = %s)
       LIMIT 1",
      '%' . $wpdb->esc_like($term_name) . '%',
      $term_slug
    ));
    
    if ($post) {
      return new WP_Post($post);
    }
    
    // Try matching by slug variations
    $slug_variations = [
      $term_slug,
      str_replace('-', '_', $term_slug),
      'cenexel-' . $term_slug,
      str_replace('cenexel-', '', $term_slug),
    ];
    
    foreach ($slug_variations as $slug) {
      $post = get_page_by_path($slug, OBJECT, 'location');
      if ($post) {
        return $post;
      }
    }
    
    // Try extracting city name and searching
    $city_match = [];
    if (preg_match('/CenExel\s+([^,]+)/i', $term_name, $city_match)) {
      $city = trim($city_match[1]);
      $post = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} 
         WHERE post_type = 'location' 
         AND post_status = 'publish' 
         AND post_title LIKE %s
         LIMIT 1",
        '%' . $wpdb->esc_like($city) . '%'
      ));
      
      if ($post) {
        return new WP_Post($post);
      }
    }
    
    return null;
  }

  private function render_location_hero($term): string {
    if (!$term || is_wp_error($term)) return '';

    $term_id = (int)$term->term_id;

    $address = $this->get_term_meta_first_nonempty($term_id, [
      'address',
      'location_address',
      'street_address',
    ]);

    $city = $this->get_term_meta_first_nonempty($term_id, [
      'city',
      'location_city',
    ]);

    $state = $this->get_term_meta_first_nonempty($term_id, [
      'state',
      'state_acronym',
      'location_state',
    ]);

    $zip = $this->get_term_meta_first_nonempty($term_id, [
      'zip',
      'zipcode',
      'postal_code',
    ]);

    $phone = $this->get_term_meta_first_nonempty($term_id, [
      'phone',
      'location_phone',
      'telephone',
    ]);

    // First try to get image from term meta
    $image_url = $this->resolve_term_image_url($term_id);

    // If no image in term meta, try to find a related location post with featured image
    if (empty($image_url)) {
      $related_post = $this->find_related_location_post($term);
      if ($related_post) {
        $image_url = $this->resolve_post_image_url($related_post->ID);
      }
    }

    // Format title: "CenExel [Location], [State]" to match screenshot format
    $location_name = trim($term->name);
    
    // Ensure "CenExel" prefix
    if (stripos($location_name, 'CenExel') === false) {
      $formatted_title = 'CenExel ' . $location_name;
    } else {
      $formatted_title = $location_name;
    }
    
    // Add city, state if available and not already in the name
    if ($city && $state) {
      $city_state = $city . ', ' . $state;
      if (stripos($formatted_title, $city_state) === false && stripos($formatted_title, $city) === false) {
        // Append if not already present
        $formatted_title .= ', ' . $city_state;
      } elseif (stripos($formatted_title, $city) !== false && stripos($formatted_title, $state) === false) {
        // City is in name but state is not - try to add state
        $formatted_title .= ', ' . $state;
      }
    } elseif ($state && stripos($formatted_title, $state) === false) {
      $formatted_title .= ', ' . $state;
    }
    
    $formatted_title = esc_html($formatted_title);

    // Build complete address line (street, city, state, zip all on one line)
    $address_line = trim(implode(', ', array_filter([$address, $city, $state, $zip])));

    // Build Google Maps URL
    $google_maps_url = '';
    if ($address || $city || $state || $zip) {
      $google_maps_url = $this->build_google_maps_url($address, $city, $state, $zip);
    }

    ob_start(); ?>
      <section class="cenexel-location-hero elementor-section elementor-top-section elementor-section-boxed elementor-section-height-default elementor-section-height-default">
        <div class="cenexel-location-hero-overlay elementor-background-overlay"></div>
        <div class="cenexel-location-hero-container elementor-container elementor-column-gap-default">
          
          <?php if ($image_url): ?>
          <div class="cenexel-location-hero-col-left elementor-column elementor-col-50 elementor-top-column">
            <div class="elementor-widget-wrap elementor-element-populated">
              <div class="cenexel-location-hero-image elementor-element elementor-widget elementor-widget-image">
                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($formatted_title); ?>" class="attachment-full size-full" style="visibility: visible;" />
              </div>
            </div>
            </div>
          <?php endif; ?>

          <div class="cenexel-location-hero-col-right elementor-column elementor-col-50 elementor-top-column">
            <div class="elementor-widget-wrap elementor-element-populated">
              <section class="cenexel-location-hero-inner elementor-section elementor-inner-section elementor-section-content-middle elementor-section-boxed elementor-section-height-default elementor-section-height-default">
                <div class="elementor-container elementor-column-gap-default">
                  <div class="elementor-column elementor-col-100 elementor-inner-column">
                    <div class="elementor-widget-wrap elementor-element-populated">
                      
                      <div class="elementor-element elementor-widget elementor-widget-heading">
                        <h1 class="cenexel-location-hero-title elementor-heading-title elementor-size-default"><?php echo esc_html($formatted_title); ?></h1>
                      </div>

            <?php if ($address_line): ?>
                      <div class="elementor-element elementor-icon-list--layout-inline elementor-mobile-align-center elementor-list-item-link-full_width elementor-widget elementor-widget-icon-list cenexel-location-hero-address">
                        <ul class="elementor-icon-list-items elementor-inline-items">
                          <li class="elementor-icon-list-item elementor-inline-item">
                            <span class="elementor-icon-list-text">
                              <?php if ($google_maps_url): ?>
                                <a href="<?php echo esc_url($google_maps_url); ?>" target="_blank" rel="noopener noreferrer" class="cenexel-location-hero-address-link"><?php echo esc_html($address_line); ?></a>
                              <?php else: ?>
                                <?php echo esc_html($address_line); ?>
                              <?php endif; ?>
                            </span>
                          </li>
                        </ul>
                      </div>
            <?php endif; ?>

            <?php if ($phone): ?>
                      <div class="elementor-element elementor-icon-list--layout-inline elementor-mobile-align-center elementor-list-item-link-full_width elementor-widget elementor-widget-icon-list cenexel-location-hero-phone">
                        <ul class="elementor-icon-list-items elementor-inline-items">
                          <li class="elementor-icon-list-item elementor-inline-item">
                            <span class="elementor-icon-list-icon">
                              <svg aria-hidden="true" class="e-font-icon-svg e-fas-phone-alt" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M497.39 361.8l-112-48a24 24 0 0 0-28 6.9l-49.6 60.6A370.66 370.66 0 0 1 130.6 204.11l60.6-49.6a23.94 23.94 0 0 0 6.9-28l-48-112A24.16 24.16 0 0 0 122.6.61l-104 24A24 24 0 0 0 0 48c0 256.5 207.9 464 464 464a24 24 0 0 0 23.4-18.6l24-104a24.29 24.29 0 0 0-14.01-27.6z"></path></svg>
                            </span>
                            <span class="elementor-icon-list-text">
                              <strong>Phone</strong><a href="tel:<?php echo esc_attr(preg_replace('/[^\d\+]/', '', $phone)); ?>" class="cenexel-location-hero-phone-link"><?php echo esc_html($phone); ?></a>
                            </span>
                          </li>
                        </ul>
              </div>
            <?php endif; ?>

                    </div>
          </div>
                </div>
              </section>
            </div>
          </div>

        </div>
      </section>
    <?php
    return ob_get_clean();
  }

  private function render_location_hero_from_post($post): string {
    if (!$post || is_wp_error($post)) return '';

    $post_id = (int)$post->ID;

    $address = $this->get_post_meta_first_nonempty($post_id, [
      'address',
      'location_address',
      'street_address',
    ]);

    $city = $this->get_post_meta_first_nonempty($post_id, [
      'city',
      'location_city',
    ]);

    $state = $this->get_post_meta_first_nonempty($post_id, [
      'state',
      'state_acronym',
      'location_state',
    ]);

    $zip = $this->get_post_meta_first_nonempty($post_id, [
      'zip',
      'zipcode',
      'postal_code',
    ]);

    $phone = $this->get_post_meta_first_nonempty($post_id, [
      'phone',
      'location_phone',
      'telephone',
    ]);

    $image_url = $this->resolve_post_image_url($post_id);

    // Format title: "CenExel [Location], [State]" to match screenshot format
    $location_name = trim($post->post_title);
    
    // Ensure "CenExel" prefix
    if (stripos($location_name, 'CenExel') === false) {
      $formatted_title = 'CenExel ' . $location_name;
    } else {
      $formatted_title = $location_name;
    }
    
    // Add city, state if available
    if ($city && $state) {
      $city_state = $city . ', ' . $state;
      if (stripos($formatted_title, $city_state) === false && stripos($formatted_title, $city) === false) {
        $formatted_title .= ', ' . $city_state;
      } elseif (stripos($formatted_title, $city) !== false && stripos($formatted_title, $state) === false) {
        $formatted_title .= ', ' . $state;
      }
    } elseif ($state && stripos($formatted_title, $state) === false) {
      $formatted_title .= ', ' . $state;
    }
    
    $formatted_title = esc_html($formatted_title);

    // Build complete address line (street, city, state, zip all on one line)
    $address_line = trim(implode(', ', array_filter([$address, $city, $state, $zip])));

    // Build Google Maps URL
    $google_maps_url = '';
    if ($address || $city || $state || $zip) {
      $google_maps_url = $this->build_google_maps_url($address, $city, $state, $zip);
    }

    ob_start(); ?>
      <section class="cenexel-location-hero elementor-section elementor-top-section elementor-section-boxed elementor-section-height-default elementor-section-height-default">
        <div class="cenexel-location-hero-overlay elementor-background-overlay"></div>
        <div class="cenexel-location-hero-container elementor-container elementor-column-gap-default">
          
          <?php if ($image_url): ?>
          <div class="cenexel-location-hero-col-left elementor-column elementor-col-50 elementor-top-column">
            <div class="elementor-widget-wrap elementor-element-populated">
              <div class="cenexel-location-hero-image elementor-element elementor-widget elementor-widget-image">
                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($formatted_title); ?>" class="attachment-full size-full" style="visibility: visible;" />
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="cenexel-location-hero-col-right elementor-column elementor-col-50 elementor-top-column">
            <div class="elementor-widget-wrap elementor-element-populated">
              <section class="cenexel-location-hero-inner elementor-section elementor-inner-section elementor-section-content-middle elementor-section-boxed elementor-section-height-default elementor-section-height-default">
                <div class="elementor-container elementor-column-gap-default">
                  <div class="elementor-column elementor-col-100 elementor-inner-column">
                    <div class="elementor-widget-wrap elementor-element-populated">
                      
                      <div class="elementor-element elementor-widget elementor-widget-heading">
                        <h1 class="cenexel-location-hero-title elementor-heading-title elementor-size-default"><?php echo esc_html($formatted_title); ?></h1>
                      </div>

                      <?php if ($address_line): ?>
                      <div class="elementor-element elementor-icon-list--layout-inline elementor-mobile-align-center elementor-list-item-link-full_width elementor-widget elementor-widget-icon-list cenexel-location-hero-address">
                        <ul class="elementor-icon-list-items elementor-inline-items">
                          <li class="elementor-icon-list-item elementor-inline-item">
                            <span class="elementor-icon-list-text">
                              <?php if ($google_maps_url): ?>
                                <a href="<?php echo esc_url($google_maps_url); ?>" target="_blank" rel="noopener noreferrer" class="cenexel-location-hero-address-link"><?php echo esc_html($address_line); ?></a>
                              <?php else: ?>
                                <?php echo esc_html($address_line); ?>
                              <?php endif; ?>
                            </span>
                          </li>
                        </ul>
                      </div>
                      <?php endif; ?>

                      <?php if ($phone): ?>
                      <div class="elementor-element elementor-icon-list--layout-inline elementor-mobile-align-center elementor-list-item-link-full_width elementor-widget elementor-widget-icon-list cenexel-location-hero-phone">
                        <ul class="elementor-icon-list-items elementor-inline-items">
                          <li class="elementor-icon-list-item elementor-inline-item">
                            <span class="elementor-icon-list-icon">
                              <svg aria-hidden="true" class="e-font-icon-svg e-fas-phone-alt" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M497.39 361.8l-112-48a24 24 0 0 0-28 6.9l-49.6 60.6A370.66 370.66 0 0 1 130.6 204.11l60.6-49.6a23.94 23.94 0 0 0 6.9-28l-48-112A24.16 24.16 0 0 0 122.6.61l-104 24A24 24 0 0 0 0 48c0 256.5 207.9 464 464 464a24 24 0 0 0 23.4-18.6l24-104a24.29 24.29 0 0 0-14.01-27.6z"></path></svg>
                            </span>
                            <span class="elementor-icon-list-text">
                              <strong>Phone</strong><a href="tel:<?php echo esc_attr(preg_replace('/[^\d\+]/', '', $phone)); ?>" class="cenexel-location-hero-phone-link"><?php echo esc_html($phone); ?></a>
                            </span>
                          </li>
                        </ul>
                      </div>
                      <?php endif; ?>

                    </div>
                  </div>
                </div>
              </section>
            </div>
          </div>

        </div>
      </section>
    <?php
    return ob_get_clean();
  }

  private function query_trials_for_site(string $site_slug, ?int $term_id): array {
    $post_type = $this->get_clinical_trial_post_type();

    $meta_or = ['relation' => 'OR'];
    foreach ($this->utm_term_meta_keys as $k) {
      $meta_or[] = [
        'key'     => $k,
        'value'   => $site_slug,
        'compare' => '=',
      ];
    }

    $primary = new WP_Query([
      'post_type'      => $post_type,
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'meta_query'     => $meta_or,
    ]);

    $posts = $primary->posts ?: [];

    if ($term_id) {
      $secondary = new WP_Query([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [[
          'taxonomy' => self::TAXONOMY,
          'field'    => 'term_id',
          'terms'    => $term_id,
        ]]
      ]);

      $by_id = [];
      foreach ($posts as $p) $by_id[(int)$p->ID] = $p;
      foreach (($secondary->posts ?: []) as $p) $by_id[(int)$p->ID] = $p;
      $posts = array_values($by_id);
    }

    return $posts;
  }

  public function render_landing() {
    $site_slug = $this->get_requested_site_slug();
    if (!$site_slug) {
      $p = esc_html(self::QUERY_PARAM);
      $lp = esc_html(self::LEGACY_QUERY_PARAM);
      return "<div>
        Please select a site.<br/>
        Examples:<br/>
        <code>/studies?{$p}=anaheim-ca</code><br/>
        <code>/studies?{$lp}=cenexel-anaheim--ca</code>
      </div>";
    }

    // Try taxonomy term first (existing behavior)
    $term = $this->resolve_location_term($site_slug);
    $term_id = $term ? (int)$term->term_id : null;
    
    // If no term found, try as post
    $location_post = null;
    if (!$term) {
      $location_post = $this->resolve_location_post($site_slug);
    }

    $posts = $this->query_trials_for_site($site_slug, $term_id);

    ob_start(); ?>
      <div class="cenexel-location-landing">

        <?php 
        if ($term) {
          echo $this->render_location_hero($term);
        } elseif ($location_post) {
          echo $this->render_location_hero_from_post($location_post);
        }
        ?>

        <div class="cenexel-content-wrapper">

        <!-- Main Form -->
        <div id="cenexel-form-wrapper">
          <form id="cenexel-lead-form">
            <input type="hidden" name="location_term_id" value="<?php echo esc_attr($term_id ?: 0); ?>" />
            <input type="hidden" name="site_slug" value="<?php echo esc_attr($site_slug); ?>" />

            <h2 class="cenexel-form-title">Your Information</h2>
            <p class="cenexel-step-instructions">Please fill out the form below and select the studies you're interested in:</p>

            <div id="cenexel-validation-errors" class="cenexel-validation-errors" style="display: none;"></div>

            <div class="cenexel-form-card">
              <div class="cenexel-field">
                <label for="cenexel-first-name">First Name <span aria-hidden="true">*</span></label>
                <input id="cenexel-first-name" name="first_name" placeholder="Enter your first name" autocomplete="given-name" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-last-name">Last Name <span aria-hidden="true">*</span></label>
                <input id="cenexel-last-name" name="last_name" placeholder="Enter your last name" autocomplete="family-name" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-email">Email</label>
                <input id="cenexel-email" name="email" type="email" placeholder="Enter your email" autocomplete="email" />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-phone">Phone <span aria-hidden="true">*</span></label>
                <input id="cenexel-phone" name="phone" type="tel" placeholder="Enter your phone" autocomplete="tel" maxlength="10" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-zip">ZIP/Postal Code <span aria-hidden="true">*</span></label>
                <input id="cenexel-zip" name="zip" placeholder="Enter your zip" autocomplete="postal-code" required />
              </div>
              <div class="cenexel-field">
                <label>Date of Birth <span aria-hidden="true">*</span></label>
                <div class="cenexel-dob-dropdowns">
                  <select id="cenexel-dob-month" name="dob_month">
                    <option value="">Month</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                      <option value="<?php echo $m; ?>"><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                    <?php endfor; ?>
                  </select>
                  <select id="cenexel-dob-day" name="dob_day">
                    <option value="">Day</option>
                    <?php for ($d = 1; $d <= 31; $d++): ?>
                      <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                    <?php endfor; ?>
                  </select>
                  <select id="cenexel-dob-year" name="dob_year" required>
                    <option value="">Year *</option>
                    <?php
                      $current_year = (int)date('Y');
                      for ($y = $current_year; $y >= $current_year - 100; $y--):
                    ?>
                      <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
              <div class="cenexel-field">
                <label for="cenexel-gender">Gender</label>
                <select id="cenexel-gender" name="gender">
                  <option value="">Select</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
              </div>
              <label class="cenexel-checkbox">
                <input type="checkbox" name="is_caregiver" value="1" />
                <span>I am the caregiver or guardian.</span>
              </label>
              <label class="cenexel-checkbox cenexel-checkbox-required">
                <input type="checkbox" name="consent" required />
                <span>
                  I have read and agree to the
                  <a href="https://cenexelresearch.com/privacy-policy/" target="_blank" rel="noopener noreferrer">
                    Privacy Policy and Terms of Service
                  </a>.
                </span>
              </label>
              <label class="cenexel-checkbox">
                <input type="checkbox" name="sms_consent" />
                <span>I agree to receive SMS/text messages.</span>
              </label>
            </div>

            <!-- Study Selection -->
            <h2 class="cenexel-available-studies-title">Available Clinical Trials</h2>
            <p class="cenexel-step-instructions">Please select the studies you're interested in:</p>

            <div class="cenexel-studies">
              <?php if (empty($posts)): ?>
                <div class="cenexel-no-studies">No studies found for this site.</div>
              <?php else: ?>
                <?php foreach ($posts as $p):
                  $post_id = (int)$p->ID;

                  $study_title = trim((string)get_post_meta($post_id, self::META_STUDY_TITLE, true));
                  if ($study_title === '') $study_title = get_the_title($p);

                  $campaign_activity = trim((string)$this->get_post_meta_first_nonempty($post_id, [
                    'utm_content',
                    'utm_content_ta',
                    'campaign_activity',
                    'campaign-activity',
                    'campaign_activity_ta',
                    'campaign_activity_field',
                    'wpcf-campaign-activity',
                  ]));
                  $campaign_value = $campaign_activity !== '' ? $campaign_activity : (string)$post_id;

                  $checkbox_id = 'cenexel-study-' . $post_id;
                ?>
                  <label
                    class="cenexel-study-item"
                    data-post-id="<?php echo esc_attr($post_id); ?>"
                    for="<?php echo esc_attr($checkbox_id); ?>"
                  >
                    <input
                      class="cenexel-study-checkbox"
                      type="checkbox"
                      name="campaign_activities[]"
                      value="<?php echo esc_attr($campaign_value); ?>"
                      data-title="<?php echo esc_attr($study_title); ?>"
                      id="<?php echo esc_attr($checkbox_id); ?>"
                    />
                    <span class="cenexel-study-title"><?php echo esc_html($study_title); ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endif; ?>
              <label class="cenexel-study-item" for="cenexel-study-other">
                <input
                  class="cenexel-study-checkbox"
                  type="checkbox"
                  id="cenexel-study-other"
                  name="study_other_checked"
                  value="1"
                />
                <span class="cenexel-study-title">Other</span>
              </label>
              <div id="cenexel-study-other-field" class="cenexel-other-field" style="display: none;">
                <input
                  type="text"
                  id="cenexel-study-other-text"
                  name="study_other_text"
                  placeholder="Please describe what you're interested in"
                  maxlength="500"
                />
              </div>
            </div>

            <div id="cenexel-study-error" class="cenexel-error-message" style="display: none;"></div>
            <button type="submit" class="cenexel-submit-button">Submit</button>
            <div id="cenexel-status" aria-live="polite"></div>
          </form>
        </div>

        <!-- Thank You -->
        <div id="cenexel-step-thankyou" style="display: none;">
          <div class="cenexel-thank-you">
            <h2 class="cenexel-thank-you-title">Thank You!</h2>
            <p class="cenexel-thank-you-message">
              We have received your information and will contact you shortly about the clinical trial studies you're interested in.
            </p>
            <button type="button" id="cenexel-back-to-studies-btn" class="cenexel-back-button">Back to Studies</button>
          </div>
        </div>
        </div><!-- .cenexel-content-wrapper -->
      </div>
    <?php
    return ob_get_clean();
  }

  public function register_rest() {
    register_rest_route('cenexel/v1', '/lead', [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_submit'],
      'permission_callback' => '__return_true',
    ]);
  }

  private function require_rest_nonce(WP_REST_Request $req) {
    $nonce = $req->get_header('x_wp_nonce');
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_REST_Response(['error' => 'Invalid nonce.'], 403);
    }
    return null;
  }

  private function compute_hmac(string $secret, string $timestamp, string $body): string {
    $to_sign = $timestamp . '.' . $body;
    return base64_encode(hash_hmac('sha256', $to_sign, $secret, true));
  }

  public function handle_submit(WP_REST_Request $req) {
    $nonce_err = $this->require_rest_nonce($req);
    if ($nonce_err) return $nonce_err;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rl_key = 'cenexel_rl_' . md5($ip);
    if (get_transient($rl_key)) {
      return new WP_REST_Response(['error' => 'Too many requests.'], 429);
    }
    set_transient($rl_key, 1, 10);

    $data = $req->get_json_params();

    $campaign_activities_raw = is_array($data['campaign_activities'] ?? [])
      ? $data['campaign_activities']
      : [];
    $campaign_activities = array_values(array_filter(array_map(
      'sanitize_text_field',
      $campaign_activities_raw
    )));
    $raw_email = sanitize_email($data['email'] ?? '');
    $payload = [
      'location_term_id' => (int)($data['location_term_id'] ?? 0),
      'site_slug'        => sanitize_title($data['site_slug'] ?? ''),
      'first_name'       => sanitize_text_field($data['first_name'] ?? ''),
      'last_name'        => sanitize_text_field($data['last_name'] ?? ''),
      'email'            => $raw_email ?: 'noemail@cenexel.com',
      'phone'            => sanitize_text_field($data['phone'] ?? ''),
      'zip'              => sanitize_text_field($data['zip'] ?? ''),
      'date_of_birth'    => sanitize_text_field($data['date_of_birth'] ?? ''),
      'gender'           => sanitize_text_field($data['gender'] ?? ''),
      'is_caregiver'     => (bool)($data['is_caregiver'] ?? false),
      'consent'          => (bool)($data['consent'] ?? false),
      'sms_consent'      => (bool)($data['sms_consent'] ?? false),
      'campaign_activities' => $campaign_activities,
      'studies'          => is_array($data['studies'] ?? null) ? $data['studies'] : [],
      'study_other'      => sanitize_text_field($data['study_other'] ?? ''),
      'event'            => sanitize_text_field($data['event'] ?? ''),
      'submitted_at'     => gmdate('c'),
      'source'           => 'cenexelclinicaltrials.com',
      'ip'               => $ip,
      'user_agent'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
      // UTM Parameters (last-touch attribution)
      'utm_source'       => sanitize_text_field($data['utm_source'] ?? ''),
      'utm_medium'       => sanitize_text_field($data['utm_medium'] ?? ''),
      'utm_campaign'     => sanitize_text_field($data['utm_campaign'] ?? ''),
      'utm_content'      => sanitize_text_field($data['utm_content'] ?? ''),
      'utm_term'         => sanitize_text_field($data['utm_term'] ?? ''),
      // First-touch attribution (original acquisition)
      'first_utm_source'   => sanitize_text_field($data['first_utm_source'] ?? ''),
      'first_utm_medium'   => sanitize_text_field($data['first_utm_medium'] ?? ''),
      'first_utm_campaign' => sanitize_text_field($data['first_utm_campaign'] ?? ''),
      'first_utm_content'  => sanitize_text_field($data['first_utm_content'] ?? ''),
      'first_utm_term'     => sanitize_text_field($data['first_utm_term'] ?? ''),
    ];

    if (!$payload['consent'] || !$payload['first_name'] || !$payload['last_name'] || !$payload['site_slug']) {
      return new WP_REST_Response(['error' => 'Missing required fields.'], 400);
    }

    if (!defined('SENDGRID_API_KEY') || !SENDGRID_API_KEY) {
      error_log('[CenExel Lead] SENDGRID_API_KEY not defined in wp-config.php');
      return new WP_REST_Response(['error' => 'Email service not configured.'], 500);
    }
    if (!defined('CENEXEL_LEAD_EMAIL') || !CENEXEL_LEAD_EMAIL) {
      error_log('[CenExel Lead] CENEXEL_LEAD_EMAIL not defined in wp-config.php');
      return new WP_REST_Response(['error' => 'Lead recipient not configured.'], 500);
    }
    error_log('[CenExel Lead] Sending to ' . CENEXEL_LEAD_EMAIL . ' via SendGrid');

    // Build HTML email
    $td_label = 'style="padding:8px 12px;border:1px solid #ddd;font-weight:bold;background:#f8f9fa;white-space:nowrap;"';
    $td_value = 'style="padding:8px 12px;border:1px solid #ddd;"';
    $html = '<h2 style="color:#213eaa;">New Lead Submission</h2>';
    $html .= '<table style="border-collapse:collapse;width:100%;max-width:600px;font-family:Arial,sans-serif;">';
    $rows = [
      'Name'               => esc_html($payload['first_name'] . ' ' . $payload['last_name']),
      'Email'              => esc_html($payload['email']),
      'Phone'              => esc_html($payload['phone']),
      'ZIP Code'           => esc_html($payload['zip']),
      'Date of Birth'      => esc_html($payload['date_of_birth']),
      'Gender'             => esc_html($payload['gender']),
      'Caregiver'          => $payload['is_caregiver'] ? 'Yes' : 'No',
      'SMS Consent'        => $payload['sms_consent'] ? 'Yes' : 'No',
      'Site'               => esc_html($payload['site_slug']),
      'Event'              => esc_html($payload['event']),
      'Submitted'          => esc_html($payload['submitted_at']),
      'Source'             => esc_html($payload['source']),
    ];
    foreach ($rows as $label => $value) {
      if ($value === '') continue;
      $html .= '<tr>';
      $html .= '<td ' . $td_label . '>' . esc_html($label) . '</td>';
      $html .= '<td ' . $td_value . '>' . $value . '</td>';
      $html .= '</tr>';
    }

    // Add studies as individual rows
    $studies = $payload['studies'];
    $study_other = $payload['study_other'];
    $has_studies = !empty($studies) || $study_other !== '';
    if ($has_studies) {
      $row_index = 0;
      foreach ($studies as $study) {
        $title = sanitize_text_field($study['title'] ?? '');
        $ca = sanitize_text_field($study['campaign_activity'] ?? '');
        $display = $title ? $title . ' (' . $ca . ')' : $ca;
        $label = $row_index === 0 ? 'Studies' : '';
        $html .= '<tr>';
        $html .= '<td ' . $td_label . '>' . esc_html($label) . '</td>';
        $html .= '<td ' . $td_value . '>' . esc_html($display) . '</td>';
        $html .= '</tr>';
        $row_index++;
      }
      if ($study_other !== '') {
        $label = $row_index === 0 ? 'Studies' : '';
        $html .= '<tr>';
        $html .= '<td ' . $td_label . '>' . esc_html($label) . '</td>';
        $html .= '<td ' . $td_value . '>Other: ' . esc_html($study_other) . '</td>';
        $html .= '</tr>';
      }
    } else {
      $html .= '<tr>';
      $html .= '<td ' . $td_label . '>Studies</td>';
      $html .= '<td ' . $td_value . '>None Selected</td>';
      $html .= '</tr>';
    }
    $html .= '</table>';

    $event = $payload['event'];
    $subject = $event !== ''
      ? 'New Outreach Lead - ' . $event
      : 'New Outreach Lead';

    $to_list = array_values(array_filter(array_map(function($e) {
      $e = trim($e);
      return $e ? ['email' => $e] : null;
    }, explode(',', CENEXEL_LEAD_EMAIL))));

    $sg_payload = [
      'personalizations' => [[
        'to' => $to_list,
        'subject' => $subject,
      ]],
      'from' => ['email' => 'no-reply@cenexel.com', 'name' => 'CenExel Clinical Trials'],
      'content' => [['type' => 'text/html', 'value' => $html]],
    ];

    $resp = wp_remote_post('https://api.sendgrid.com/v3/mail/send', [
      'headers' => [
        'Authorization' => 'Bearer ' . SENDGRID_API_KEY,
        'Content-Type'  => 'application/json',
      ],
      'body'    => wp_json_encode($sg_payload),
      'timeout' => 15,
    ]);

    if (is_wp_error($resp)) {
      error_log('[CenExel Lead] SendGrid WP error: ' . $resp->get_error_message());
      return new WP_REST_Response(['error' => 'Failed to send email.'], 502);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
      error_log('[CenExel Lead] SendGrid HTTP ' . $code . ': ' . $body);
      return new WP_REST_Response(['error' => 'Email service error.'], 502);
    }

    return new WP_REST_Response(['ok' => true], 200);
  }

  /**
   * Check for plugin updates from GitHub
   */
  public function check_for_updates($transient) {
    if (empty($transient->checked)) {
      return $transient;
    }

    $plugin_slug = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];

    // Get latest release from GitHub
    $release_data = $this->get_github_release_info();

    if (!$release_data || !isset($release_data['tag_name'])) {
      return $transient;
    }

    $latest_version = ltrim($release_data['tag_name'], 'v');
    
    // Compare versions
    if (version_compare($current_version, $latest_version, '<')) {
      $transient->response[$plugin_slug] = (object)[
        'slug'        => dirname($plugin_slug),
        'plugin'      => $plugin_slug,
        'new_version' => $latest_version,
        'url'         => $plugin_data['PluginURI'] ?? '',
        'package'     => $this->get_github_download_url($release_data['tag_name']),
      ];
    }

    return $transient;
  }

  /**
   * Get plugin info for update details
   */
  public function plugin_info($false, $action, $args) {
    $plugin_slug = plugin_basename(__FILE__);

    if ($action !== 'plugin_information' || $args->slug !== dirname($plugin_slug)) {
      return $false;
    }

    $release_data = $this->get_github_release_info();
    
    if (!$release_data) {
      return $false;
    }

    $plugin_data = get_plugin_data(__FILE__);
    $latest_version = ltrim($release_data['tag_name'], 'v');
    
    $info = new \stdClass();
    $info->name = $plugin_data['Name'];
    $info->slug = dirname($plugin_slug);
    $info->version = $latest_version;
    $info->last_updated = $release_data['published_at'] ?? '';
    $info->download_link = $this->get_github_download_url($release_data['tag_name']);
    $info->homepage = 'https://github.com/' . self::GITHUB_USERNAME . '/' . self::GITHUB_REPO;
    $info->sections = [
      'description' => $plugin_data['Description'],
      'changelog' => $this->format_release_notes($release_data),
    ];
    $info->banners = [];
    $info->icons = [];

    return $info;
  }

  /**
   * Handle post-install actions
   */
  public function post_install($response, $hook_extra, $result) {
    $plugin_slug = plugin_basename(__FILE__);
    
    if ($hook_extra['plugin'] !== $plugin_slug) {
      return $response;
    }

    // Activate plugin after update
    activate_plugin($plugin_slug);
    
    return $response;
  }

  /**
   * Get latest release info from GitHub API
   */
  private function get_github_release_info() {
    $cache_key = 'cenexel_location_leads_github_release';
    $cache_time = 3600; // 1 hour

    $cached = get_transient($cache_key);
    if ($cached !== false) {
      return $cached;
    }

    $api_url = sprintf(
      'https://api.github.com/repos/%s/%s/releases/latest',
      self::GITHUB_USERNAME,
      self::GITHUB_REPO
    );

    $response = wp_remote_get($api_url, [
      'timeout' => 10,
      'headers' => [
        'Accept' => 'application/vnd.github.v3+json',
      ],
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!$data || !isset($data['tag_name'])) {
      return null;
    }

    // Cache the result
    set_transient($cache_key, $data, $cache_time);

    return $data;
  }

  /**
   * Get GitHub download URL for a release
   */
  private function get_github_download_url($tag) {
    // Extract version number from tag (remove 'v' prefix)
    $version = ltrim($tag, 'v');
    
    // The zip file is named using the plugin slug, not repo name
    $plugin_slug = 'cenexel-location-leads';
    
    // Try the standard GitHub release asset URL format
    // GitHub releases assets use the filename as uploaded
    return sprintf(
      'https://github.com/%s/%s/releases/download/%s/%s-v%s.zip',
      self::GITHUB_USERNAME,
      self::GITHUB_REPO,
      $tag,
      $plugin_slug,
      $version
    );
  }

  /**
   * Format release notes for display
   */
  private function format_release_notes($release_data) {
    $notes = '<h3>Release Notes</h3>';
    
    if (isset($release_data['body']) && !empty($release_data['body'])) {
      // Convert markdown to basic HTML
      $body = $release_data['body'];
      $body = esc_html($body);
      $body = nl2br($body);
      $notes .= '<p>' . $body . '</p>';
    } else {
      $notes .= '<p>No release notes available.</p>';
    }

    return $notes;
  }
}

new Cenexel_Location_Leads();