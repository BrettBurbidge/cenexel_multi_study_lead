<?php
/**
 * Plugin Name: CenExel Location Lead Landing
 * Description: /studies?site=<slug> or /studies?_location_city_state=<legacy> lists Clinical Trial posts and submits leads to Azure.
 * Version: 0.4.5
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

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('rest_api_init', [$this, 'register_rest']);
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

  private function resolve_term_image_url(int $term_id): string {
    $candidates = [
      'location_image',
      'image',
      'thumbnail_id',
      'term_image',
      'featured_image',
      'jet_term_image',
    ];

    $raw = $this->get_term_meta_first_nonempty($term_id, $candidates);
    if ($raw === '') return '';

    if (filter_var($raw, FILTER_VALIDATE_URL)) {
      return esc_url_raw($raw);
    }

    if (ctype_digit($raw)) {
      $url = wp_get_attachment_image_url((int)$raw, 'large');
      return $url ? esc_url_raw($url) : '';
    }

    return '';
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

    $city_state = trim(implode(', ', array_filter([$city, $state])));
    $address_line = trim(implode(' ', array_filter([$address, $city_state, $zip])));

    ob_start(); ?>
      <section class="cenexel-location-hero">
        <div class="cenexel-location-hero-inner">
          <?php if ($image_url): ?>
            <div class="cenexel-location-hero-image">
              <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" />
            </div>
          <?php endif; ?>

          <div class="cenexel-location-hero-text">
            <h1 class="cenexel-location-hero-title"><?php echo esc_html($term->name); ?></h1>

            <?php if ($address_line): ?>
              <div class="cenexel-location-hero-address"><?php echo esc_html($address_line); ?></div>
            <?php endif; ?>

            <?php if ($phone): ?>
              <div class="cenexel-location-hero-phone">
                <span class="cenexel-location-hero-phone-label">Phone</span>
                <a href="tel:<?php echo esc_attr(preg_replace('/[^\d\+]/', '', $phone)); ?>">
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

    $term = $this->resolve_location_term($site_slug);
    $term_id = $term ? (int)$term->term_id : null;

    $posts = $this->query_trials_for_site($site_slug, $term_id);

    ob_start(); ?>
      <div class="cenexel-location-landing">

        <?php echo $term ? $this->render_location_hero($term) : ''; ?>

        <h2 class="cenexel-available-studies-title">Available Clinical Trial Studies</h2>

        <form id="cenexel-lead-form">
          <input type="hidden" name="location_term_id" value="<?php echo esc_attr($term_id ?: 0); ?>" />
          <input type="hidden" name="site_slug" value="<?php echo esc_attr($site_slug); ?>" />

          <div class="cenexel-fields">
            <label>First Name <input name="first_name" required /></label>
            <label>Last Name <input name="last_name" required /></label>
            <label>Email <input name="email" type="email" required /></label>
            <label>Phone <input name="phone" /></label>
            <label>ZIP <input name="zip" /></label>
            <label class="cenexel-consent">
              <input type="checkbox" name="consent" required />
              I agree to be contacted about clinical trials.
            </label>
          </div>

          <h2>Select studies you’re interested in</h2>

          <div class="cenexel-studies">
            <?php if (empty($posts)): ?>
              <div>No studies found for this site.</div>
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

          <button type="submit">Submit</button>
          <div id="cenexel-status" aria-live="polite"></div>
        </form>
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

    $payload = [
      'location_term_id' => (int)($data['location_term_id'] ?? 0),
      'site_slug'        => sanitize_title($data['site_slug'] ?? ''),
      'first_name'       => sanitize_text_field($data['first_name'] ?? ''),
      'last_name'        => sanitize_text_field($data['last_name'] ?? ''),
      'email'            => sanitize_email($data['email'] ?? ''),
      'phone'            => sanitize_text_field($data['phone'] ?? ''),
      'zip'              => sanitize_text_field($data['zip'] ?? ''),
      'consent'          => (bool)($data['consent'] ?? false),
      'post_ids'         => array_map('intval', is_array($data['post_ids'] ?? []) ? ($data['post_ids'] ?? []) : []),
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
}

new Cenexel_Location_Leads();