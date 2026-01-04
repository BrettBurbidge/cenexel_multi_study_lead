<?php
/**
 * Plugin Name: CenExel Location Lead Landing
 * Description: /studies?site=<location-slug> landing page listing Therapeutic Areas by Location with lead submit to Azure.
 * Version: 0.2.1
 */

if (!defined('ABSPATH')) exit;

class Cenexel_Location_Leads {
  const TAXONOMY = 'location';
  const POST_TYPE = 'therapeutic-area';
  const TERM_META_LANDING_SLUG = 'landing_slug'; // optional alias for marketing slugs
  const QUERY_PARAM = 'site'; // <-- IMPORTANT: use ?site=... to avoid existing redirects

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('rest_api_init', [$this, 'register_rest']);

    // Optional, but useful if you later rewrite /studies/<site> to query var
    add_filter('query_vars', function ($vars) {
      $vars[] = self::QUERY_PARAM;
      return $vars;
    });
  }

  public function register_shortcode() {
    add_shortcode('cenexel_location_landing', [$this, 'render_landing']);
  }

  public function enqueue_assets() {
    // Load on location taxonomy pages OR any page containing the shortcode
    $should_load = is_tax(self::TAXONOMY);

    if (!$should_load && is_singular()) {
      global $post;
      if ($post && has_shortcode($post->post_content, 'cenexel_location_landing')) {
        $should_load = true;
      }
    }

    if (!$should_load) return;

    wp_register_script(
      'cenexel-location-leads',
      plugin_dir_url(__FILE__) . 'assets/cenexel-location-leads.js',
      [],
      '0.2.1',
      true
    );

    wp_localize_script('cenexel-location-leads', 'CENEXEL', [
      'restUrl' => esc_url_raw(rest_url('cenexel/v1/lead')),
      'nonce'   => wp_create_nonce('wp_rest'),
    ]);

    wp_enqueue_script('cenexel-location-leads');
  }

  private function get_requested_site_slug(): string {
    // Primary: /studies?site=atlanta-ga
    $slug = isset($_GET[self::QUERY_PARAM]) ? sanitize_title(wp_unslash($_GET[self::QUERY_PARAM])) : '';
    if ($slug) return $slug;

    // Optional fallback if used on taxonomy archive pages: /location/{term-slug}/
    if (is_tax(self::TAXONOMY)) {
      $term = get_queried_object();
      if ($term && !empty($term->slug)) return sanitize_title($term->slug);
    }

    // Optional fallback if you later add a rewrite that sets query_var('site')
    $maybe = get_query_var(self::QUERY_PARAM);
    return $maybe ? sanitize_title($maybe) : '';
  }

  private function resolve_location_term(string $requested_slug) {
    if (!$requested_slug) return null;

    // 1) Direct match to term slug
    $term = get_term_by('slug', $requested_slug, self::TAXONOMY);
    if ($term && !is_wp_error($term)) return $term;

    // 2) Match via term meta alias (landing_slug) if you use marketing slugs
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
    $term_id = (int) $wpdb->get_var($sql);
    if ($term_id) {
      $term2 = get_term($term_id, self::TAXONOMY);
      if ($term2 && !is_wp_error($term2)) return $term2;
    }

    return null;
  }

  private function query_posts_for_location_term_id(int $term_id): array {
    $q = new WP_Query([
      'post_type'      => self::POST_TYPE,
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
    return $q->posts ?: [];
  }

  public function render_landing() {
    $requested_slug = $this->get_requested_site_slug();

    if (!$requested_slug) {
      $param = esc_html(self::QUERY_PARAM);
      return "<div>Please select a site. Example: <code>/studies?{$param}=atlanta-ga</code></div>";
    }

    $term = $this->resolve_location_term($requested_slug);
    if (!$term) {
      return '<div>Site not found. Check the site slug.</div>';
    }

    $posts = $this->query_posts_for_location_term_id((int)$term->term_id);

    ob_start(); ?>
      <div class="cenexel-location-landing">
        <h1>Available Clinical Trial Studies</h1>
        <div class="cenexel-location-name"><?php echo esc_html($term->name); ?></div>

        <form id="cenexel-lead-form">
          <input type="hidden" name="location_term_id" value="<?php echo esc_attr($term->term_id); ?>" />
          <input type="hidden" name="site_slug" value="<?php echo esc_attr($term->slug); ?>" />

          <div class="cenexel-fields">
            <label>First Name <input name="first_name" required /></label>
            <label>Last Name <input name="last_name" required /></label>
            <label>Email <input name="email" type="email" required /></label>
            <label>Phone <input name="phone" /></label>
            <label>ZIP <input name="zip" /></label>
            <label>
              <input type="checkbox" name="consent" required />
              I agree to be contacted about clinical trials.
            </label>
          </div>

          <h2>Select studies you’re interested in</h2>

          <div class="cenexel-studies">
            <?php if (empty($posts)): ?>
              <div>No studies available for this site.</div>
            <?php else: ?>
              <?php foreach ($posts as $p): ?>
                <label class="cenexel-study">
                  <input type="checkbox" name="post_ids[]" value="<?php echo esc_attr($p->ID); ?>" />
                  <span><?php echo esc_html(get_the_title($p)); ?></span>
                </label>
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
      'permission_callback' => '__return_true', // nonce verified inside
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

    // Basic throttling by IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rl_key = 'cenexel_rl_' . md5($ip);
    if (get_transient($rl_key)) {
      return new WP_REST_Response(['error' => 'Too many requests.'], 429);
    }
    set_transient($rl_key, 1, 10);

    $data = $req->get_json_params();

    $location_term_id = (int)($data['location_term_id'] ?? 0);
    $post_ids = $data['post_ids'] ?? [];

    $payload = [
      'location_term_id' => $location_term_id,
      'site_slug'        => sanitize_title($data['site_slug'] ?? ''),
      'first_name'       => sanitize_text_field($data['first_name'] ?? ''),
      'last_name'        => sanitize_text_field($data['last_name'] ?? ''),
      'email'            => sanitize_email($data['email'] ?? ''),
      'phone'            => sanitize_text_field($data['phone'] ?? ''),
      'zip'              => sanitize_text_field($data['zip'] ?? ''),
      'consent'          => (bool)($data['consent'] ?? false),
      'post_ids'         => array_map('intval', is_array($post_ids) ? $post_ids : []),
      'submitted_at'     => gmdate('c'),
      'source'           => 'cenexelclinicaltrials.com',
      'ip'               => $ip,
      'user_agent'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ];

    if (!$payload['consent'] || !$payload['first_name'] || !$payload['last_name'] || !$payload['email'] || !$payload['location_term_id']) {
      return new WP_REST_Response(['error' => 'Missing required fields.'], 400);
    }
    if (empty($payload['post_ids'])) {
      return new WP_REST_Response(['error' => 'Select at least one study.'], 400);
    }

    // Validate selected posts belong to location term
    $valid_posts = $this->query_posts_for_location_term_id($payload['location_term_id']);
    $valid_ids = array_map(fn($p) => (int)$p->ID, $valid_posts);

    foreach ($payload['post_ids'] as $id) {
      if (!in_array($id, $valid_ids, true)) {
        return new WP_REST_Response(['error' => 'Invalid selection.'], 400);
      }
    }

    // Forward to Azure Function
    if (!defined('CENEXEL_AZURE_LEAD_ENDPOINT') || !CENEXEL_AZURE_LEAD_ENDPOINT) {
      return new WP_REST_Response(['error' => 'Azure endpoint not configured.'], 500);
    }

    $body = wp_json_encode($payload);

    $headers = ['Content-Type' => 'application/json'];

    // Option A: Azure Function Key
    if (defined('CENEXEL_AZURE_FUNCTION_KEY') && CENEXEL_AZURE_FUNCTION_KEY) {
      $headers['x-functions-key'] = CENEXEL_AZURE_FUNCTION_KEY;
    }

    // Option B: Additional HMAC signature
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