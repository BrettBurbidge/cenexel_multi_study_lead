<?php
/**
 * Plugin Name: CenExel Location Lead Landing
 * Description: /studies?site=<slug> or /studies?_location_city_state=<legacy> lists Clinical Trial posts and submits leads to Azure.
 * Version: 0.7.0
 */

if (!defined('ABSPATH')) exit;

class Cenexel_Location_Leads {
  const TAXONOMY = 'location';

  // URL params
  const QUERY_PARAM = 'site';
  const LEGACY_QUERY_PARAM = '_location_city_state';

  // term meta alias (optional)
  const TERM_META_LANDING_SLUG = 'landing_slug';

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

  // GitHub update configuration
  const GITHUB_USERNAME = 'brettburbidge'; // Change this
  const GITHUB_REPO = 'cenexel_multi_study_lead'; // Change this to your repo name
  const GITHUB_PLUGIN_FILE = 'cenexel-location-leads.php'; // Plugin file path in repo

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

  private function resolve_term_image_url(int $term_id): string {
    // Expanded list of common meta keys for location images
    $candidates = [
      'location_image',
      'location_photo',
      'building_image',
      'site_image',
      'facility_image',
      'image',
      'photo',
      'thumbnail_id',
      'term_image',
      'featured_image',
      'jet_term_image',
      'wpcf-location-image',
      'acf_location_image',
      'location_thumbnail',
      '_thumbnail_id',
    ];

    $raw = $this->get_term_meta_first_nonempty($term_id, $candidates);
    if ($raw === '') return '';

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
      if ($url) return esc_url_raw($url);
    }

    // Then try meta fields (same candidates as term meta)
    $candidates = [
      'location_image',
      'location_photo',
      'building_image',
      'site_image',
      'facility_image',
      'image',
      'photo',
      'thumbnail_id',
      '_thumbnail_id',
      'wpcf-location-image',
      'acf_location_image',
    ];

    $raw = $this->get_post_meta_first_nonempty($post_id, $candidates);
    if ($raw === '') return '';

    if (filter_var($raw, FILTER_VALIDATE_URL)) {
      return esc_url_raw($raw);
    }

    if (ctype_digit($raw)) {
      $url = wp_get_attachment_image_url((int)$raw, 'large');
      if ($url) return esc_url_raw($url);
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

    $image_url = $this->resolve_term_image_url($term_id);

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

    // Build address line for display
    $address_parts = array_filter([$address, $city, $state]);
    $address_line = trim(implode(', ', $address_parts));
    if ($zip) {
      $address_line .= ($address_line ? ', ' : '') . $zip;
    }

    // Build Google Maps URL
    $google_maps_url = '';
    if ($address || $city || $state || $zip) {
      $google_maps_url = $this->build_google_maps_url($address, $city, $state, $zip);
    }

    ob_start(); ?>
      <section class="cenexel-location-hero">
        <div class="cenexel-location-hero-inner">
          <?php if ($image_url): ?>
            <div class="cenexel-location-hero-image">
              <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($formatted_title); ?>" />
            </div>
          <?php endif; ?>

          <div class="cenexel-location-hero-text">
            <h1 class="cenexel-location-hero-title"><?php echo esc_html($formatted_title); ?></h1>

            <?php if ($address_line): ?>
              <div class="cenexel-location-hero-address">
                <?php if ($google_maps_url): ?>
                  <a href="<?php echo esc_url($google_maps_url); ?>" target="_blank" rel="noopener noreferrer" class="cenexel-location-hero-address-link">
                    <?php echo esc_html($address_line); ?>
                  </a>
                <?php else: ?>
                  <?php echo esc_html($address_line); ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($phone): ?>
              <div class="cenexel-location-hero-phone">
                <span class="cenexel-location-hero-phone-label">Phone</span>
                <a href="tel:<?php echo esc_attr(preg_replace('/[^\d\+]/', '', $phone)); ?>" class="cenexel-location-hero-phone-link">
                  <?php echo esc_html($phone); ?>
                </a>
              </div>
            <?php endif; ?>
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

    // Build address line for display
    $address_parts = array_filter([$address, $city, $state]);
    $address_line = trim(implode(', ', $address_parts));
    if ($zip) {
      $address_line .= ($address_line ? ', ' : '') . $zip;
    }

    // Build Google Maps URL
    $google_maps_url = '';
    if ($address || $city || $state || $zip) {
      $google_maps_url = $this->build_google_maps_url($address, $city, $state, $zip);
    }

    ob_start(); ?>
      <section class="cenexel-location-hero">
        <div class="cenexel-location-hero-inner">
          <?php if ($image_url): ?>
            <div class="cenexel-location-hero-image">
              <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($formatted_title); ?>" />
            </div>
          <?php endif; ?>

          <div class="cenexel-location-hero-text">
            <h1 class="cenexel-location-hero-title"><?php echo esc_html($formatted_title); ?></h1>

            <?php if ($address_line): ?>
              <div class="cenexel-location-hero-address">
                <?php if ($google_maps_url): ?>
                  <a href="<?php echo esc_url($google_maps_url); ?>" target="_blank" rel="noopener noreferrer" class="cenexel-location-hero-address-link">
                    <?php echo esc_html($address_line); ?>
                  </a>
                <?php else: ?>
                  <?php echo esc_html($address_line); ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($phone): ?>
              <div class="cenexel-location-hero-phone">
                <span class="cenexel-location-hero-phone-label">Phone</span>
                <a href="tel:<?php echo esc_attr(preg_replace('/[^\d\+]/', '', $phone)); ?>" class="cenexel-location-hero-phone-link">
                  <?php echo esc_html($phone); ?>
                </a>
              </div>
            <?php endif; ?>
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

        <!-- Step 1: Study Selection -->
        <div id="cenexel-step-studies" class="cenexel-step">
          <h2 class="cenexel-available-studies-title">Available Clinical Trial Studies</h2>
          <p class="cenexel-step-instructions">Please select the studies you're interested in:</p>

          <div class="cenexel-studies">
            <?php if (empty($posts)): ?>
              <div class="cenexel-no-studies">No studies found for this site.</div>
            <?php else: ?>
              <?php foreach ($posts as $p):
                $post_id = (int)$p->ID;

                $study_title = trim((string)get_post_meta($post_id, self::META_STUDY_TITLE, true));
                if ($study_title === '') $study_title = get_the_title($p);

                $subtitle = trim((string)get_post_meta($post_id, self::META_SUBTITLE, true));

                $permalink = get_permalink($p);
                $checkbox_id = 'cenexel-study-' . $post_id;
              ?>
                <div class="cenexel-study-row">
                  <div class="cenexel-study-left">
                    <input
                      class="cenexel-study-checkbox"
                      type="checkbox"
                      name="post_ids[]"
                      value="<?php echo esc_attr($post_id); ?>"
                      id="<?php echo esc_attr($checkbox_id); ?>"
                    />
                    <div class="cenexel-study-text">
                      <a class="cenexel-study-title" href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($study_title); ?>
                      </a>

                      <?php if ($subtitle !== ''): ?>
                        <div class="cenexel-study-subtitle">
                          <?php echo esc_html($subtitle); ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <?php if (!empty($posts)): ?>
            <button type="button" id="cenexel-continue-btn" class="cenexel-continue-button" disabled>
              Continue
            </button>
            <div id="cenexel-study-error" class="cenexel-error-message" style="display: none;"></div>
          <?php endif; ?>
        </div>

        <!-- Step 2: Form -->
        <div id="cenexel-step-form" class="cenexel-step" style="display: none;">
          <h2 class="cenexel-form-title">Your Information</h2>
          <p class="cenexel-step-instructions">Please fill out the form below to continue:</p>

          <form id="cenexel-lead-form">
            <input type="hidden" name="location_term_id" value="<?php echo esc_attr($term_id ?: 0); ?>" />
            <input type="hidden" name="site_slug" value="<?php echo esc_attr($site_slug); ?>" />

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
                <label for="cenexel-email">Email <span aria-hidden="true">*</span></label>
                <input id="cenexel-email" name="email" type="email" placeholder="Enter your email" autocomplete="email" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-phone">Phone <span aria-hidden="true">*</span></label>
                <input id="cenexel-phone" name="phone" type="tel" placeholder="Enter your phone" autocomplete="tel" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-zip">ZIP/Postal Code <span aria-hidden="true">*</span></label>
                <input id="cenexel-zip" name="zip" placeholder="Enter your zip" autocomplete="postal-code" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-dob">Date of Birth <span aria-hidden="true">*</span></label>
                <input id="cenexel-dob" name="date_of_birth" type="date" placeholder="mm/dd/yyyy" required />
              </div>
              <div class="cenexel-field">
                <label for="cenexel-gender">Gender <span aria-hidden="true">*</span></label>
                <select id="cenexel-gender" name="gender" required>
                  <option value="">Please select</option>
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                  <option value="non-binary">Non-binary</option>
                  <option value="prefer-not-to-say">Prefer not to say</option>
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
            </div>

            <button type="submit" class="cenexel-submit-button">Submit</button>
            <div id="cenexel-status" aria-live="polite"></div>
          </form>
        </div>

        <!-- Step 3: Thank You -->
        <div id="cenexel-step-thankyou" class="cenexel-step" style="display: none;">
          <div class="cenexel-thank-you">
            <h2 class="cenexel-thank-you-title">Thank You!</h2>
            <p class="cenexel-thank-you-message">
              We have received your information and will contact you shortly about the clinical trial studies you're interested in.
            </p>
          </div>
        </div>
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

    $post_ids_raw = is_array($data['post_ids'] ?? []) ? $data['post_ids'] : [];
    $payload = [
      'location_term_id' => (int)($data['location_term_id'] ?? 0),
      'site_slug'        => sanitize_title($data['site_slug'] ?? ''),
      'first_name'       => sanitize_text_field($data['first_name'] ?? ''),
      'last_name'        => sanitize_text_field($data['last_name'] ?? ''),
      'email'            => sanitize_email($data['email'] ?? ''),
      'phone'            => sanitize_text_field($data['phone'] ?? ''),
      'zip'              => sanitize_text_field($data['zip'] ?? ''),
      'date_of_birth'    => sanitize_text_field($data['date_of_birth'] ?? ''),
      'gender'           => sanitize_text_field($data['gender'] ?? ''),
      'is_caregiver'     => (bool)($data['is_caregiver'] ?? false),
      'consent'          => (bool)($data['consent'] ?? false),
      'post_ids'         => array_map('intval', $post_ids_raw),
      'submitted_at'     => gmdate('c'),
      'source'           => 'cenexelclinicaltrials.com',
      'ip'               => $ip,
      'user_agent'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ];

    if (!$payload['consent'] || !$payload['first_name'] || !$payload['last_name'] || !$payload['email'] || !$payload['site_slug']) {
      return new WP_REST_Response(['error' => 'Missing required fields.'], 400);
    }
    if (empty($payload['post_ids'])) {
      return new WP_REST_Response(['error' => 'Select at least one study.'], 400);
    }

    if (!defined('CENEXEL_AZURE_LEAD_ENDPOINT') || !CENEXEL_AZURE_LEAD_ENDPOINT) {
      return new WP_REST_Response(['error' => 'Azure endpoint not configured.'], 500);
    }

    $body = wp_json_encode($payload);
    $headers = ['Content-Type' => 'application/json'];

    if (defined('CENEXEL_AZURE_FUNCTION_KEY') && CENEXEL_AZURE_FUNCTION_KEY) {
      $headers['x-functions-key'] = CENEXEL_AZURE_FUNCTION_KEY;
    }
    if (defined('CENEXEL_AZURE_SHARED_SECRET') && CENEXEL_AZURE_SHARED_SECRET) {
      $ts = (string) time();
      $sig = $this->compute_hmac(CENEXEL_AZURE_SHARED_SECRET, $ts, $body);
      $headers['x-cenexel-ts'] = $ts;
      $headers['x-cenexel-sig'] = $sig;
    }

    $resp = wp_remote_post(CENEXEL_AZURE_LEAD_ENDPOINT, [
      'headers' => $headers,
      'body'    => $body,
      'timeout' => 15,
    ]);

    if (is_wp_error($resp)) {
      return new WP_REST_Response(['error' => 'Submission failed.'], 502);
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
      return new WP_REST_Response(['error' => 'Azure rejected submission.'], 502);
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
      $plugin_data = get_plugin_data(__FILE__);
      
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
    return sprintf(
      'https://github.com/%s/%s/releases/download/%s/%s-v%s.zip',
      self::GITHUB_USERNAME,
      self::GITHUB_REPO,
      $tag,
      self::GITHUB_REPO,
      ltrim($tag, 'v')
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