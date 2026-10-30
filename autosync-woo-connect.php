<?php
/**
 * Plugin Name: AutoSync Woo Connect
 * Description: Bridges AutoSync's real tire/wheel catalog into WooCommerce's
 *              headless Store API cart/checkout. The AutoSync catalog is never
 *              synced into WooCommerce - two generic hidden products (Wheel,
 *              Tire) carry the real SKU/brand as cart-item metadata, and the
 *              real price is verified server-side against the live AutoSync
 *              API on every total calculation, never trusted from the browser.
 * Version: 0.10.7
 *
 * Install: upload the zip via Plugins -> Add New Plugin -> Upload Plugin,
 * then Activate. After the first install, updates are automatic (see below) -
 * WordPress will show a normal "Update available" notice on the Plugins page,
 * no manual re-download/re-upload needed. Check the Version number above/in
 * the Plugins list to confirm an update actually took - bump it on every edit.
 */

if (!defined('ABSPATH')) exit; // no direct access

// ── Self-update via the plugin's own GitHub repo ────────────────────────────
// This plugin is developed inside the autosynccore monorepo, but distributed
// from its own dedicated public repo (autosync-corp/autosync-woo-connect) -
// autosynccore is private, and a client-managed WordPress site can't check a
// private repo for updates without an embedded access token, which isn't
// worth the risk for a plugin that has no secrets in it to begin with (API
// keys/fee config live in fee-config.php/client.js, never here).
//
// Tracks the 'main' branch's Version header directly (not GitHub Releases/
// tags) - bump the version above, publish to that branch, done. Using any
// branch name other than 'master' skips PUC's tag/release-first fallback
// logic entirely, so this is intentionally never named 'master'.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$aswbUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/autosync-corp/autosync-woo-connect/',
    __FILE__,
    'autosync-woo-connect'
);
$aswbUpdateChecker->setBranch('main');

// ── Auto-provision a placeholder shipping method, on every zone missing one ─
// Checkout requires a real shipping rate whenever an order needs shipping -
// with zero methods configured anywhere, checkout fails outright ("this order
// requires a shipping option"). The real shipping CHARGE from autosyncadmin is
// priced onto whichever rate this provides (see aswb_price_shipping_rates
// below), so this is both the rate checkout selects and the line it is paid on,
// customer-facing title just "Standard Shipping" (clean - the client manages
// real orders/shipping through this same wp-admin, so nothing engineering-
// internal should leak into what they or their customers see). Idempotent:
// skips any zone that already has a method (including a developer's own
// manually-configured zone), and only runs once per site via the option flag.
register_activation_hook(__FILE__, 'aswb_provision_shipping_methods');
add_action('admin_init', function () {
    // register_activation_hook only fires on a fresh install, not a re-upload
    // over an existing one - this catches that case on the next admin visit.
    if (get_option('aswb_shipping_provisioned_v3')) return;
    aswb_provision_shipping_methods();
    update_option('aswb_shipping_provisioned_v3', 1);
});

// Handle the manual "Run shipping provisioning now" button on the settings page -
// added because the automatic run (above) produced no visible effect on the real
// site and there's no server-log access to see why silently. This makes the
// attempt observable: every zone checked, and exactly what happened to it, is
// stored in aswb_shipping_provision_log and displayed on the settings page.
add_action('admin_post_aswb_run_shipping_provisioning', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('aswb_run_shipping_provisioning');
    aswb_provision_shipping_methods();
    wp_redirect(add_query_arg('aswb_ran', '1', admin_url('options-general.php?page=aswb-settings')));
    exit;
});

function aswb_provision_shipping_methods() {
    $log = [];
    if (!class_exists('WC_Shipping_Zones') || !class_exists('WC_Shipping_Zone')) {
        $log[] = 'WooCommerce shipping-zone classes not found - is WooCommerce active?';
        update_option('aswb_shipping_provision_log', $log);
        return;
    }
    try {
        $zoneIds = [0]; // zone 0 = the always-existing "Locations not covered by your other zones"
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            $zoneIds[] = $zone['id'];
        }
        foreach (array_unique($zoneIds) as $zoneId) {
            $zone = new WC_Shipping_Zone($zoneId);
            $existing = $zone->get_shipping_methods();
            $enabledCount = 0;
            foreach ($existing as $m) {
                $isEnabled = method_exists($m, 'is_enabled') ? $m->is_enabled() : ($m->enabled === 'yes');
                if ($isEnabled) $enabledCount++;
            }
            if ($enabledCount > 0) {
                $log[] = "Zone $zoneId (\"{$zone->get_zone_name()}\"): already has $enabledCount enabled method(s) - skipped.";
                continue;
            }
            if (!empty($existing)) {
                // A method exists (probably our own earlier attempt) but none are
                // enabled - WooCommerce doesn't default a newly-added instance to
                // enabled, and the earlier version of this function never set it
                // explicitly. Enable the first one instead of adding a duplicate.
                $m = reset($existing);
                update_option('woocommerce_' . $m->id . '_' . $m->instance_id . '_settings',
                    array_merge($m->instance_settings, ['enabled' => 'yes', 'title' => 'Standard Shipping']));
                $log[] = "Zone $zoneId (\"{$zone->get_zone_name()}\"): found a disabled method (instance {$m->instance_id}) - enabled it.";
                continue;
            }
            // Free Shipping rather than a Flat Rate: it has no cost field in
            // wp-admin to edit, and aswb_price_shipping_rates overrides the cost
            // anyway. A Flat Rate would put an editable $0 box in front of the
            // client that looks like it sets the shipping price and does not -
            // autosyncadmin is the only place that number comes from.
            $instanceId = $zone->add_shipping_method('free_shipping');
            if (!$instanceId) {
                $log[] = "Zone $zoneId (\"{$zone->get_zone_name()}\"): add_shipping_method('free_shipping') returned nothing - failed.";
                continue;
            }
            $saved = update_option('woocommerce_free_shipping_' . $instanceId . '_settings', [
                'title' => 'Standard Shipping',
                'requires' => '',
                'enabled' => 'yes',
            ]);
            $log[] = "Zone $zoneId (\"{$zone->get_zone_name()}\"): added Free Shipping, instance $instanceId, settings saved=" . ($saved ? 'yes' : 'no (already matched? or save failed)');
        }
    } catch (\Throwable $e) {
        $log[] = 'Exception: ' . $e->getMessage();
    }
    update_option('aswb_shipping_provision_log', $log);
}

// ── Configuration ────────────────────────────────────────────────────────────
// API key and allowed origin are editable under Settings -> AutoSync Woo Connect
// in wp-admin (see the settings page below) - no code edit/re-upload needed to
// change deployments, just the two things every install actually differs on.
define('ASWB_AUTOSYNC_API_BASE', 'https://api.autosyncstudio.com');

// The last-resort deposit percentage, used only when autosyncadmin's own
// value cannot be read. Defined up here rather than beside the deposit code
// because aswb_fee_settings() and aswb_deposit_pct() both reference it from
// further up the file, and relying on call-order for that is the kind of
// thing that passes every check and then fails once at runtime.
define('ASWB_DEPOSIT_PCT', 25);

function aswb_get_api_key() {
    return get_option('aswb_autosync_api_key', 'MFCtst');
}

function aswb_get_allowed_origin() {
    return get_option('aswb_allowed_origin', 'https://devcore.autosynccorp.com');
}

// ── Settings page: Settings -> AutoSync Woo Connect ─────────────────────────
// Page slug ('aswb-settings') is left as-is - it's an internal identifier,
// not user-visible, and changing it isn't a cosmetic change (see below).
add_action('admin_menu', function () {
    add_options_page('AutoSync Woo Connect', 'AutoSync Woo Connect', 'manage_options', 'aswb-settings', 'aswb_render_settings_page');
});

add_action('admin_init', function () {
    register_setting('aswb_settings', 'aswb_autosync_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('aswb_settings', 'aswb_allowed_origin', ['sanitize_callback' => function ($v) { return rtrim(trim($v), '/'); }]);
    register_setting('aswb_settings', 'aswb_core_site_url', [
        'sanitize_callback' => function ($v) {
            delete_transient('aswb_fee_settings_live'); // force a fresh fetch next time, don't serve a stale cache against a newly-changed URL
            return rtrim(trim($v), '/');
        },
    ]);
    register_setting('aswb_settings', 'aswb_fee_settings', ['sanitize_callback' => 'aswb_sanitize_fee_settings']);
    register_setting('aswb_settings', 'aswb_disable_gutenberg', ['sanitize_callback' => function ($v) { return $v ? '1' : '0'; }]);
});

// Off by default - only disables the block editor (posts/pages, and the
// widgets screen) if a client explicitly opts in on the settings page below.
// Doesn't need the separate Classic Editor plugin - these are the same two
// core WordPress filters that plugin itself uses under the hood. Safe with
// this site's headless setup specifically: the REST API always returns
// content.rendered (fully-resolved HTML), regardless of which editor wrote
// it, so the frontend this content actually displays on is never affected -
// this only changes the wp-admin writing experience.
add_action('init', function () {
    if (!get_option('aswb_disable_gutenberg')) return;
    add_filter('use_block_editor_for_post_type', '__return_false');
    add_filter('use_widgets_block_editor', '__return_false');
});

// Every site's fee amounts/rules differ - same reasoning as the API key/
// origin above, extended to cover the numbers that used to be hardcoded PHP
// constants (and could silently drift out of sync with autosyncadmin's Fees
// & Shipping card, which drives the exact same values on the Medusa side).
function aswb_sanitize_fee_settings($raw) {
    $raw = is_array($raw) ? $raw : [];
    $overrides = [];
    foreach (explode("\n", $raw['salesTaxStateOverridesText'] ?? '') as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode(':', $line, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '') {
            $overrides[strtoupper(trim($parts[0]))] = (float) trim($parts[1]);
        }
    }
    return [
        'mountBalanceEnabled' => !empty($raw['mountBalanceEnabled']),
        'mountBalanceAmount' => (float) ($raw['mountBalanceAmount'] ?? 0),
        'mountBalanceWaiver' => sanitize_text_field($raw['mountBalanceWaiver'] ?? 'never'),
        'disposalEnabled' => !empty($raw['disposalEnabled']),
        'disposalAmount' => (float) ($raw['disposalAmount'] ?? 0),
        'disposalWaiver' => sanitize_text_field($raw['disposalWaiver'] ?? 'never'),
        'shippingEnabled' => !empty($raw['shippingEnabled']),
        'shippingAmount' => (float) ($raw['shippingAmount'] ?? 0),
        'shippingWaiver' => sanitize_text_field($raw['shippingWaiver'] ?? 'never'),
        'freeSetSize' => (float) ($raw['freeSetSize'] ?? 4),
        'salesTaxEnabled' => !empty($raw['salesTaxEnabled']),
        'salesTaxDefaultRate' => (float) ($raw['salesTaxDefaultRate'] ?? 0),
        'salesTaxStateOverrides' => $overrides,
    ];
}

function aswb_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>AutoSync Woo Connect</h1>
      <p>Must match this deployment's frontend exactly - <code>window.AUTOSYNC_CONFIG.apiKey</code> for the API key, and the real customer-facing origin for the allowed origin (e.g. <code>http://localhost:8080</code> while testing locally, the real production domain once deployed).</p>
      <form method="post" action="options.php">
        <?php settings_fields('aswb_settings'); ?>
        <table class="form-table">
          <tr>
            <th><label for="aswb_autosync_api_key">AutoSync API Key</label></th>
            <td><input type="text" id="aswb_autosync_api_key" name="aswb_autosync_api_key" value="<?php echo esc_attr(aswb_get_api_key()); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="aswb_allowed_origin">Allowed Frontend Origin</label></th>
            <td><input type="text" id="aswb_allowed_origin" name="aswb_allowed_origin" value="<?php echo esc_attr(aswb_get_allowed_origin()); ?>" class="regular-text" placeholder="https://www.example.com"></td>
          </tr>
          <tr>
            <th><label for="aswb_core_site_url">Core Site URL</label></th>
            <td>
              <input type="text" id="aswb_core_site_url" name="aswb_core_site_url" value="<?php echo esc_attr(get_option('aswb_core_site_url', '')); ?>" class="regular-text" placeholder="https://devcore.autosynccorp.com">
              <p class="description">The core site's own URL (not this WordPress site). When set, fee/shipping/tax values below are fetched live from <code>{this URL}/fee-config.php</code> and cached for 10 minutes - <code>autosyncadmin</code> becomes the real source of truth. Leave blank to always use the values below directly.</p>
            </td>
          </tr>
          <tr>
            <th>Editor</th>
            <td>
              <input type="hidden" name="aswb_disable_gutenberg" value="0">
              <label><input type="checkbox" id="aswb_disable_gutenberg" name="aswb_disable_gutenberg" value="1" <?php checked(get_option('aswb_disable_gutenberg')); ?>> Disable the block editor (Gutenberg) - use the Classic Editor instead</label>
              <p class="description">Applies to posts, pages, and the Widgets screen. No separate plugin needed. Safe with this site's headless setup either way - the content this WordPress instance actually publishes to the storefront is always fully-rendered HTML from the REST API, regardless of which editor wrote it, so this only changes the writing experience in wp-admin.</p>
            </td>
          </tr>
        </table>

        <h2>Fees &amp; Shipping <span style="font-weight:normal; font-size:13px; color:#666;">(read-only - source of truth is <code>autosyncadmin</code>)</span></h2>
        <p>Shown here for visibility only. Edit these in <code>autosyncadmin</code>'s "Fees &amp; Shipping" card - this plugin only ever reads them (live from Core Site URL above, falling back to the last-saved copy below if that's blank or unreachable). Disabled fields aren't submitted, so clicking "Save Changes" never touches these.</p>
        <?php $fees = aswb_fee_settings(); $waiverModes = ['never' => 'Always charge', 'joint' => 'Waive for a full package', 'independent' => 'Waive per category independently', 'always' => 'Always free']; ?>
        <table class="form-table aswb-readonly-fees">
          <tr>
            <th>Checkout mode</th>
            <td>
              <select disabled>
                <?php foreach (['both' => 'Both - install or ship', 'ship' => 'Shipping only', 'install' => 'Install only'] as $k => $l): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected(aswb_store_mode(), $k); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
              </select>
              &nbsp; <span style="color:#666;">A mode this store does not offer is rejected, whatever the browser sends.</span>
            </td>
          </tr>
          <tr>
            <th>Deposit at checkout</th>
            <td>
              <input type="text" disabled value="<?php echo esc_attr(aswb_deposit_pct()); ?>" size="4">%
              &nbsp; <span style="color:#666;">Install-mode card orders only. Ignored when Shipping only.</span>
            </td>
          </tr>
          <tr>
            <th>Mount &amp; Balance</th>
            <td>
              <label><input type="checkbox" disabled <?php checked($fees['mountBalanceEnabled']); ?>> Enabled</label>
              &nbsp; $<input type="text" disabled value="<?php echo esc_attr($fees['mountBalanceAmount']); ?>" size="4"> per item
              &nbsp; <select disabled><?php foreach ($waiverModes as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($fees['mountBalanceWaiver'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
            </td>
          </tr>
          <tr>
            <th>Tire Disposal</th>
            <td>
              <label><input type="checkbox" disabled <?php checked($fees['disposalEnabled']); ?>> Enabled</label>
              &nbsp; $<input type="text" disabled value="<?php echo esc_attr($fees['disposalAmount']); ?>" size="4"> per tire
              &nbsp; <select disabled><?php foreach ($waiverModes as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($fees['disposalWaiver'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
            </td>
          </tr>
          <tr>
            <th>Shipping</th>
            <td>
              <label><input type="checkbox" disabled <?php checked($fees['shippingEnabled']); ?>> Enabled</label>
              &nbsp; $<input type="text" disabled value="<?php echo esc_attr($fees['shippingAmount']); ?>" size="4"> per item
              &nbsp; <select disabled><?php foreach ($waiverModes as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($fees['shippingWaiver'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
            </td>
          </tr>
          <tr>
            <th><label for="aswb_free_set_size">Free Set Size</label></th>
            <td><input type="text" id="aswb_free_set_size" disabled value="<?php echo esc_attr($fees['freeSetSize']); ?>" size="4"></td>
          </tr>
          <tr>
            <th>Sales Tax</th>
            <td>
              <label><input type="checkbox" disabled <?php checked($fees['salesTaxEnabled']); ?>> Enabled</label>
              &nbsp; Default rate: <input type="text" disabled value="<?php echo esc_attr($fees['salesTaxDefaultRate']); ?>" size="4">%
            </td>
          </tr>
          <tr>
            <th><label for="aswb_state_overrides">State Overrides</label></th>
            <td>
              <textarea id="aswb_state_overrides" disabled rows="4" class="regular-text" placeholder="CA:7.25&#10;NY:8"><?php
                foreach ($fees['salesTaxStateOverrides'] as $st => $rate) echo esc_textarea("$st:$rate") . "\n";
              ?></textarea>
              <p class="description">One per line, <code>STATE:RATE</code> - e.g. <code>CA:7.25</code>. Only add a line for states that differ from the default rate above.</p>
            </td>
          </tr>
        </table>
        <style>.aswb-readonly-fees input:disabled, .aswb-readonly-fees select:disabled, .aswb-readonly-fees textarea:disabled { background: #f0f0f1; color: #555; opacity: 1; }</style>
        <?php submit_button(); ?>
      </form>

      <hr>
      <h2>Shipping Method Provisioning</h2>
      <p>Checkout requires a real shipping rate on any zone that needs shipping. This runs automatically once, but you can re-run it manually here (safe - it always skips a zone that already has a method).</p>
      <?php if (isset($_GET['aswb_ran'])): ?>
        <div class="notice notice-info"><p>Ran. See the log below.</p></div>
      <?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="aswb_run_shipping_provisioning">
        <?php wp_nonce_field('aswb_run_shipping_provisioning'); ?>
        <?php submit_button('Run Shipping Provisioning Now', 'secondary'); ?>
      </form>
      <?php $log = get_option('aswb_shipping_provision_log'); if ($log): ?>
        <p><strong>Last run result:</strong></p>
        <ul style="list-style:disc; margin-left:20px;">
          <?php foreach ($log as $line): ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <hr>
      <h2>Price Verification Debug Log <span style="font-weight:normal; font-size:13px; color:#666;">(temporary - tracking down a real mispricing incident)</span></h2>
      <p>Every cart/checkout price calculation logs one row per AutoSync line item here (newest first, capped at 60). If a line item is ever missing its AutoSync metadata, it shows up as <code>no_autosync_metadata</code> instead of <code>priced</code> - that's the case that can silently charge the generic product's raw placeholder price.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
        <input type="hidden" name="action" value="aswb_clear_price_debug_log">
        <?php wp_nonce_field('aswb_clear_price_debug_log'); ?>
        <?php submit_button('Clear Log', 'secondary', 'submit', false); ?>
      </form>
      <?php $priceLog = get_option('aswb_price_debug_log', []); if ($priceLog): ?>
        <table class="widefat striped" style="max-width:1400px;">
          <thead><tr><th>Time</th><th>Run</th><th>Event</th><th>Product ID</th><th>Type</th><th>Brand</th><th>SKU</th><th>Price set</th><th>Other fields</th></tr></thead>
          <tbody>
            <?php $knownKeys = ['time', 'run', 'event', 'product_id', 'type', 'brand', 'sku', 'price_set']; ?>
            <?php foreach ($priceLog as $row): ?>
              <tr>
                <td><?php echo esc_html($row['time'] ?? ''); ?></td>
                <td><?php echo esc_html($row['run'] ?? ''); ?></td>
                <td><?php echo esc_html($row['event'] ?? ''); ?></td>
                <td><?php echo esc_html($row['product_id'] ?? ''); ?></td>
                <td><?php echo esc_html($row['type'] ?? ''); ?></td>
                <td><?php echo esc_html($row['brand'] ?? ''); ?></td>
                <td><?php echo esc_html($row['sku'] ?? ''); ?></td>
                <td><?php echo isset($row['price_set']) ? esc_html('$' . number_format((float) $row['price_set'], 2)) : ''; ?></td>
                <td style="font-family:monospace; font-size:11px; white-space:pre-wrap;"><?php
                    $extra = array_diff_key($row, array_flip($knownKeys));
                    echo $extra ? esc_html(wp_json_encode($extra)) : '';
                ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p><em>No entries yet - the log fills in as carts get calculated.</em></p>
      <?php endif; ?>
    </div>
    <?php
}

add_action('admin_post_aswb_clear_price_debug_log', function () {
    check_admin_referer('aswb_clear_price_debug_log');
    delete_option('aswb_price_debug_log');
    wp_safe_redirect(add_query_arg('aswb_cleared', '1', admin_url('options-general.php?page=aswb-settings')));
    exit;
});

// The generic hidden WooCommerce products created for this deployment. Only
// Wheel/Tire are used as real cart items - Disposal/Mount & Balance/Sales Tax
// are computed as WooCommerce "fees" instead (see aswb_apply_fees below;
// shipping is a real shipping line, not a fee), not
// cart items, so they don't need product IDs here even though matching hidden
// products exist in the store (harmless, just unused by this mechanism).
define('ASWB_PRODUCT_WHEEL', 14307);
define('ASWB_PRODUCT_TIRE', 14308);

// Fee business rules - mirrors checkout/index.html's computeQtyFee logic
// exactly. autosyncadmin (on the core site) is the single source of truth -
// this fetches the live values from fee-config.php on every call (cached
// briefly so a cart calculation never waits on a slow/down request), and only
// falls back to the locally-saved copy (editable under Settings -> AutoSync
// Woo Connect, below) if the core site is unreachable. The local copy is a safety
// net, not a second real source - keep it roughly in sync manually, but it
// only ever matters when the fetch itself fails.
function aswb_fee_settings() {
    $fallback = get_option('aswb_fee_settings', [
        'mountBalanceEnabled' => true, 'mountBalanceAmount' => 25, 'mountBalanceWaiver' => 'independent',
        'disposalEnabled' => true, 'disposalAmount' => 3, 'disposalWaiver' => 'joint',
        'shippingEnabled' => true, 'shippingAmount' => 50, 'shippingWaiver' => 'independent',
        'freeSetSize' => 4,
        'salesTaxEnabled' => true, 'salesTaxDefaultRate' => 6,
        'salesTaxStateOverrides' => [], // ['CA' => 7.25, ...] - uppercase state keys
        // Permissive on purpose: if the core site is unreachable, or is still
        // running a fee-config.php that predates these keys, the plugin must
        // behave exactly as it did before rather than start refusing orders.
        'checkoutModes' => 'both', 'depositPct' => ASWB_DEPOSIT_PCT,
    ]);

    $coreUrl = rtrim(get_option('aswb_core_site_url', ''), '/');
    if ($coreUrl === '') return $fallback;

    $cached = get_transient('aswb_fee_settings_live');
    if ($cached !== false) return $cached;

    $url = $coreUrl . '/fee-config.php?' . http_build_query(['key' => aswb_get_api_key()]);
    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $fallback; // core site unreachable/misconfigured - don't cache a failure, just use the fallback for this one calculation
    }
    $live = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($live) || !isset($live['mountBalanceAmount'])) return $fallback;

    set_transient('aswb_fee_settings_live', $live, 10 * MINUTE_IN_SECONDS);
    return $live;
}

// The storefront's own business name, from autosyncadmin via fee-config.php,
// falling back to this WordPress site's title. Never a literal - this plugin
// ships to every client, so a name baked in here would be someone else's.
// Returns '' when neither is set, and every caller must handle that rather
// than substituting a placeholder.
function aswb_business_name() {
    $fees = aswb_fee_settings();
    $n = isset($fees['businessName']) ? trim((string) $fees['businessName']) : '';
    if ($n !== '') return $n;
    return trim((string) get_bloginfo('name'));
}

// Resolve the install location the customer picked. The browser sends an id;
// the record itself comes from autosyncadmin, same trust model as the fee
// amounts. Returns null when it cannot be resolved - an unknown id is a
// missing location, never a reason to fall back to some other shop's address.
function aswb_resolve_location($id) {
    $id = trim((string) $id);
    $fees = aswb_fee_settings();
    $rows = (isset($fees['locations']) && is_array($fees['locations'])) ? $fees['locations'] : [];
    if (!$rows) return null;
    if ($id !== '') {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) return $row;
        }
        return null;
    }
    // A single-location store whose checkout never sent an id still has exactly
    // one possible answer. Two or more and we genuinely do not know.
    return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
}

// One formatter for a stored location, so the email, the order totals and the
// admin screen can never drift apart. Returns [] when there is nothing real to
// print - an empty location is data, not a reason to print anything at all.
function aswb_location_lines($loc) {
    if (!is_array($loc)) return [];
    $out = [];
    $name = trim((string) ($loc['name'] ?? ''));
    if ($name !== '') $out[] = $name;
    $street = trim((string) ($loc['addr'] ?? ''));
    if ($street !== '') $out[] = $street;
    $city = trim((string) ($loc['city'] ?? ''));
    $state = trim((string) ($loc['state'] ?? ''));
    $zip = trim((string) ($loc['zip'] ?? ''));
    $line = trim($city . (($city !== '' && $state !== '') ? ', ' : '') . $state);
    $line = trim($line . ($zip !== '' ? ' ' . $zip : ''));
    if ($line !== '') $out[] = $line;
    $phone = trim((string) ($loc['phone'] ?? ''));
    if ($phone !== '') $out[] = $phone;
    return $out;
}

// The install location recorded on an order at checkout time, so an email sent
// later never depends on a session that is long gone.
function aswb_order_location($order) {
    if (!($order instanceof WC_Order)) return null;
    $loc = $order->get_meta('_autosync_install_location');
    return is_array($loc) && $loc ? $loc : null;
}

function aswb_order_is_install($order) {
    return ($order instanceof WC_Order) && $order->get_meta('_autosync_mode') === 'install';
}

// ── The store's own rules, which the client does not get a vote on ─────────
// The mode decides whether an order pays a deposit and whether shipping is
// charged, so it is worth as much money as the prices themselves - and it was
// the one such input still taken from the browser. A ship-only store can now
// never price an order as an install, whatever arrives over the Store API and
// whatever a stale session is still holding.
function aswb_store_mode() {
    $fees = aswb_fee_settings();
    $m = isset($fees['checkoutModes']) ? $fees['checkoutModes'] : 'both';
    return in_array($m, ['both', 'ship', 'install'], true) ? $m : 'both';
}

// The mode this request is actually allowed to use. Call this everywhere the
// mode is read - clamping only where the session is SET would still let a
// value stored before a mode change survive in an open cart.
function aswb_effective_mode() {
    $store = aswb_store_mode();
    if ($store === 'ship' || $store === 'install') return $store;
    // 'both' - the client chooses, and install stays the default it has always
    // been for a session that never said.
    $m = WC()->session ? WC()->session->get('autosync_mode', 'install') : 'install';
    return $m === 'ship' ? 'ship' : 'install';
}

// autosyncadmin owns the percentage; ASWB_DEPOSIT_PCT is only the floor under
// an unreachable core site. Without this the storefront could advertise 40%
// and Stripe would charge 25%.
function aswb_deposit_pct() {
    $fees = aswb_fee_settings();
    $p = isset($fees['depositPct']) ? (float) $fees['depositPct'] : 0;
    return ($p > 0 && $p <= 100) ? $p : ASWB_DEPOSIT_PCT;
}

// ── CORS: let the headless frontend call the Store API cross-origin ─────────
// WooCommerce 9.8+ already exposes the Cart-Token response header for CORS
// automatically - this only handles the base Access-Control-Allow-Origin
// permission, which WordPress's default REST CORS behavior doesn't reliably
// cover for the Store API's specific needs (custom headers, credentials).
add_filter('rest_pre_serve_request', function ($served, $result, $request) {
    aswb_maybe_send_cors_headers($request->get_route());
    return $served;
}, 10, 3);

add_action('init', function () {
    $isOptions = ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS';
    $isStoreApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-json/wc/store/') !== false;
    if ($isOptions && $isStoreApi) {
        aswb_maybe_send_cors_headers($_SERVER['REQUEST_URI']);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        status_header(200);
        exit;
    }
});

// ── Expose the real AutoSync item as clean structured JSON per cart item ────
// woocommerce_get_item_data (further down) already shows this as human-
// readable strings for the cart/checkout page's own display - this is the
// same data again, but as real JSON under item.extensions.autosync, for the
// frontend to rebuild its own cart state from without parsing display text.
add_action('woocommerce_blocks_loaded', function () {
    if (!function_exists('woocommerce_store_api_register_endpoint_data')) return;
    if (!class_exists('\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema')) return;
    woocommerce_store_api_register_endpoint_data([
        'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
        'namespace' => 'autosync',
        'data_callback' => function ($cart_item) {
            return $cart_item['autosync'] ?? null;
        },
        'schema_callback' => function () {
            return [
                'type' => ['type' => ['string', 'null']],
                'sku' => ['type' => ['string', 'null']],
                'brand' => ['type' => ['string', 'null']],
                'name' => ['type' => ['string', 'null']],
                'size' => ['type' => ['string', 'null']],
                'vehicle' => ['type' => ['string', 'null']],
                'image' => ['type' => ['string', 'null']],
            ];
        },
        'schema_type' => ARRAY_A,
    ]);
});

// ── Receive delivery mode + resolved state from the frontend ────────────────
// The frontend calls the Store API's blocks-provided extensionCartUpdate():
//   extensionCartUpdate({ namespace: 'autosync-fees', data: { mode: 'ship', state: 'MI' } })
// which POSTs to /wp-json/wc/store/v1/cart/extensions - mirrors exactly what
// checkout/index.html already computes today (mode from the install/ship
// toggle, state from shipAddr.state or the picked-up-from location's state).
add_action('woocommerce_blocks_loaded', function () {
    if (!function_exists('woocommerce_store_api_register_update_callback')) return;
    woocommerce_store_api_register_update_callback([
        'namespace' => 'autosync-fees',
        'callback' => function ($data) {
            // Recorded, not obeyed: every read goes through aswb_effective_mode(),
            // which clamps this to what the store actually offers.
            if (isset($data['mode']) && in_array($data['mode'], ['install', 'ship'], true)) {
                WC()->session->set('autosync_mode', sanitize_text_field($data['mode']));
            }
            // 'state' is still accepted and deliberately ignored. The checkout
            // sends it, and an older cached checkout will keep sending it, but
            // nothing reads it any more: the tax state is resolved server-side
            // by aswb_taxable_state(). It was trusted once and charged a
            // Nevada customer California tax - see that function.
            // WHICH shop the customer picked. An id only - the name, address and
            // phone that end up on the order are looked up server-side from
            // autosyncadmin's own locations list (aswb_resolve_location), so a raw
            // API call cannot write an address of its own onto an order.
            if (array_key_exists('location_id', $data)) {
                WC()->session->set('autosync_location_id', sanitize_text_field((string) $data['location_id']));
            }
            // Deliberately no depositPct here - eligibility and the actual
            // percentage charged are decided entirely server-side (see
            // ASWB_DEPOSIT_PCT below), never accepted from the client. A raw
            // API call must not be able to influence what gets charged.
        },
    ]);
});

// ── Apply Mount & Balance / Disposal / Shipping / Sales Tax as WooCommerce
// "fees" - not cart items. Fees are the native mechanism for a dynamically
// computed charge with no product behind it (no fragile add/remove/update of
// a fake cart item needed); this hook re-runs on every totals calculation, so
// there's nothing to keep in sync as the cart's real tire/wheel qty changes.
// How many tires and wheels are in this cart. Items without our own metadata
// are not ours to charge fees on and are skipped.
function aswb_cart_quantities($cart) {
    $tireQty = 0;
    $wheelQty = 0;
    foreach ($cart->get_cart() as $item) {
        $as = $item['autosync'] ?? null;
        if (!$as) continue;
        if ($as['type'] === 'tire') $tireQty += $item['quantity'];
        if ($as['type'] === 'wheel') $wheelQty += $item['quantity'];
    }
    return [$tireQty, $wheelQty];
}

// Mirrors checkout/index.php's computeQtyFee() exactly - see that file's
// comment for what each waiverMode/applyTo combination means.
function aswb_qty_fee($enabled, $amount, $waiverMode, $applyTo, $applies, $tireQty, $wheelQty, $freeSetSize) {
    if (!$enabled || !$applies || $waiverMode === 'always') return 0;
    $isPackage = $tireQty >= $freeSetSize && $wheelQty >= $freeSetSize;
    if ($waiverMode === 'independent') {
        if ($applyTo === 'both') {
            return ($tireQty >= $freeSetSize ? 0 : $tireQty * $amount) + ($wheelQty >= $freeSetSize ? 0 : $wheelQty * $amount);
        }
        $qty = $applyTo === 'tire' ? $tireQty : $wheelQty;
        return $qty >= $freeSetSize ? 0 : $qty * $amount;
    }
    $qty = $applyTo === 'tire' ? $tireQty : ($applyTo === 'wheel' ? $wheelQty : ($tireQty + $wheelQty));
    if ($waiverMode === 'joint') return $isPackage ? 0 : $qty * $amount;
    return $qty * $amount; // 'never'
}

// What shipping costs this cart - $0 unless the order is actually being
// shipped. Called from the shipping-rate filter, which runs BEFORE fees, so
// this has to be computable on its own rather than read back from them.
function aswb_shipping_amount($cart) {
    $fees = aswb_fee_settings();
    list($tireQty, $wheelQty) = aswb_cart_quantities($cart);
    return aswb_qty_fee(
        $fees['shippingEnabled'], (float) $fees['shippingAmount'], $fees['shippingWaiver'],
        'both', aswb_effective_mode() === 'ship',
        $tireQty, $wheelQty, (float) $fees['freeSetSize']
    );
}

// ── Shipping is a shipping line, not a fee ─────────────────────────────────
// Priced onto the rate the order actually uses. As a fee it summed into the
// right total but printed as "Fees $73.49 / Shipping $0.00" in wp-admin and in
// the customer's email, with the $0 coming from the placeholder method above.
add_filter('woocommerce_package_rates', 'aswb_price_shipping_rates', 100, 2);
function aswb_price_shipping_rates($rates, $package) {
    if (!WC()->cart) return $rates;
    $amount = aswb_shipping_amount(WC()->cart);
    $mode = aswb_effective_mode();
    foreach ($rates as $rate) {
        $rate->set_cost($amount);
        // Our own flat Sales Tax fee is the only tax on these orders - every
        // fee is added non-taxable for the same reason - so the rate must not
        // pick up WooCommerce's tax engine either.
        $rate->set_taxes([]);
        if ($mode === 'install') {
            // Nothing ships on an install order, so the method's own name
            // ("Standard Shipping") is not merely redundant, it is wrong - the
            // same reasoning that renamed "Shipping: Shipping" in 0.9.0.
            // The rate is relabelled rather than removed: the checkout requires
            // a rate to EXIST at all (checkout/index.php throws "No shipping
            // method configured on the WooCommerce site" the moment the list
            // comes back empty), so suppressing the package would break install
            // checkout rather than tidy an order.
            $business = aswb_business_name();
            $rate->set_label(apply_filters(
                'aswb_install_shipping_label',
                $business !== '' ? 'Install at ' . $business : 'Install in store',
                $rate,
                $package
            ));
            // ...and deliberately no "Items:" meta below: a list of what is in
            // the box only means something when there is a box.
            continue;
        }
        if ($amount > 0) {
            // 0.9.0 set this to "Shipping". The order email prints the method
            // name under its own "Shipping:" heading, so it read
            // "Shipping: Shipping". Only the auto-provisioned Free Shipping
            // method is renamed now - its name is actively wrong once it has
            // a cost - while a method the store owner named themselves is
            // left alone, because WooCommerce > Settings > Shipping is where
            // they expect to control that text.
            //
            // A delivery promise ("3-5 business days") belongs in that method
            // title, per store. It must never be baked in here, where it
            // would ship to every other client as a claim about a service
            // they may not offer.
            $label = trim((string) $rate->get_label());
            if ($label === '' || stripos($label, 'free shipping') !== false) {
                $rate->set_label(apply_filters('aswb_shipping_label', 'Standard shipping', $rate, $package));
            }
        }
        // WooCommerce builds the rate's "Items" meta from each line's PRODUCT
        // name, which here is the generic placeholder, so the shipping line on
        // the order read "Autosync Tires &times; 4, Autosync Wheels &times; 1".
        // Rebuild it from the real AutoSync items - the same names the order
        // lines themselves carry. The rate's meta is where this lives: it is
        // copied onto the shipping order item later, so fixing it here fixes
        // the cart, the checkout, the order and the emails in one place.
        if (method_exists($rate, 'add_meta_data')) {
            $names = [];
            foreach ((array) ($package['contents'] ?? []) as $ci) {
                $as = $ci['autosync'] ?? null;
                $nm = $as ? aswb_line_item_name($as) : '';
                if ($nm === '' && isset($ci['data']) && is_object($ci['data'])
                    && method_exists($ci['data'], 'get_name')) {
                    $nm = (string) $ci['data']->get_name();
                }
                if ($nm === '') continue;
                $names[] = $nm . ' &times; ' . (int) ($ci['quantity'] ?? 0);
            }
            if ($names) {
                $rate->add_meta_data(__('Items', 'woocommerce'), implode(', ', $names));
            }
        }
    }
    return $rates;
}

// WooCommerce caches calculated rates against a hash of the package, and the
// package has no idea the checkout mode exists - so a cart that switched
// install <-> ship would keep being served the previous mode's price. Putting
// the mode into the package puts it into the hash.
add_filter('woocommerce_cart_shipping_packages', 'aswb_mode_in_shipping_package', 10, 1);
function aswb_mode_in_shipping_package($packages) {
    $mode = aswb_effective_mode();
    foreach ($packages as $i => $package) {
        $packages[$i]['autosync_mode'] = $mode;
    }
    return $packages;
}

// ── The state that decides the sales tax, resolved server-side ────────────
// This used to be whatever the browser POSTed to /cart/extensions, and that
// is how a Nevada order came to be charged 7.25% California tax: the
// storefront pre-filled its own shop state into a shipping form that stays
// hidden while "Same as billing" is ticked, then sent it. The frontend bug is
// fixed, but a session value written by a client call was never a fact about
// the order, and a cached older checkout would still send the old one.
//
// Both answers are already on the server without having to ask for them:
//   ship    - the customer's shipping address. The checkout sets it through
//             /cart/update-customer one call BEFORE it sends the fee context,
//             so it is present and correct by the time fees are calculated.
//   install - the shop the customer picked, looked up from autosyncadmin's own
//             list by id, exactly as aswb_record_mode_on_order stamps it on
//             the order.
// Neither can be steered by a crafted API call, which is the rule the deposit
// percentage and the store address already follow.
function aswb_taxable_state($mode) {
    if ($mode === 'install') {
        $id = WC()->session ? (string) WC()->session->get('autosync_location_id', '') : '';
        $loc = aswb_resolve_location($id);
        return $loc ? strtoupper(trim((string) ($loc['state'] ?? ''))) : '';
    }
    if (!WC()->customer) return '';
    $state = (string) WC()->customer->get_shipping_state();
    // A store configured to ship to the billing address only never populates
    // the shipping fields; that order still ships somewhere real.
    if (trim($state) === '') $state = (string) WC()->customer->get_billing_state();
    return strtoupper(trim($state));
}

add_action('woocommerce_cart_calculate_fees', 'aswb_apply_fees', 10, 1);
function aswb_apply_fees($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    $mode = aswb_effective_mode();
    $state = aswb_taxable_state($mode);

    list($tireQty, $wheelQty) = aswb_cart_quantities($cart);

    $fees = aswb_fee_settings();
    $freeSetSize = (float) $fees['freeSetSize'];

    // Mount & Balance / Disposal are pickup-only services - unconditionally $0
    // for ship-mode regardless of waiverMode, same rule as the frontend.
    $mount = aswb_qty_fee($fees['mountBalanceEnabled'], (float) $fees['mountBalanceAmount'], $fees['mountBalanceWaiver'], 'both', $mode !== 'ship', $tireQty, $wheelQty, $freeSetSize);
    $disposal = aswb_qty_fee($fees['disposalEnabled'], (float) $fees['disposalAmount'], $fees['disposalWaiver'], 'tire', $mode !== 'ship', $tireQty, $wheelQty, $freeSetSize);
    // Shipping is deliberately absent here: it is a shipping line now, priced
    // in aswb_price_shipping_rates. Adding it as a fee as well would charge twice.

    // Each fee is added non-taxable - sales tax below is our own flat-rate line,
    // not WooCommerce's tax engine, so these shouldn't also get taxed by it.
    if ($mount > 0) $cart->add_fee('Mount & Balance', $mount, false);
    if ($disposal > 0) $cart->add_fee('Tire Disposal', $disposal, false);

    if ($fees['salesTaxEnabled']) {
        $subtotal = $cart->get_subtotal();
        $overrides = $fees['salesTaxStateOverrides'] ?: [];
        $rate = $overrides[$state] ?? (float) $fees['salesTaxDefaultRate'];
        $tax = round($subtotal * ($rate / 100), 2);
        if ($tax > 0) $cart->add_fee('Sales Tax', $tax, false);
    }
}

// ── Deposit checkout: parent (full-value) order + child (deposit) order ────
// "Install at Your Tire Shop" promises "25% today, rest at install" (matches
// the existing Medusa checkout's real deposit charge). WooCommerce assumes
// order total == amount charged, so simply filtering the Stripe charge
// amount on a full-value order would leave Woo's own refund button, sales
// reports, and payment reconciliation believing the full amount was
// collected. Instead: the customer's real order (parent) keeps its full
// total untouched; a second, internal child order carrying only the
// deposit amount is what Stripe actually charges. Reviewed and revised
// twice against real WooCommerce/Store API source before building - see
// project memory for the full design discussion.
//
// Percentage/eligibility are decided ENTIRELY here, never trusted from the
// frontend - a raw API call cannot influence what gets charged. The
// percentage itself now comes from autosyncadmin via aswb_deposit_pct();
// ASWB_DEPOSIT_PCT (defined near the top, since functions above this point
// reference it) is only the floor under an unreachable core site.

// ── Expose deposit info to the frontend for DISPLAY only (never authoritative
// for charging) - same mechanism already used for per-item autosync data,
// just registered on the cart schema instead of the per-item schema.
add_action('woocommerce_blocks_loaded', function () {
    if (!function_exists('woocommerce_store_api_register_endpoint_data')) return;
    if (!class_exists('\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema')) return;
    woocommerce_store_api_register_endpoint_data([
        'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
        'namespace' => 'autosync_deposit',
        'data_callback' => function () {
            // Only 'install' vs 'ship' is known at cart stage (WooCommerce mode
            // only ever offers card/stripe_cc for this cart module - see
            // checkout/index.html's payment-method gating), so mode alone is a
            // sufficient eligibility proxy for display purposes here.
            $mode = aswb_effective_mode();
            $eligible = $mode === 'install';
            $pct = aswb_deposit_pct();
            $fullTotal = (float) WC()->cart->get_total('edit');
            $due = $eligible ? round($fullTotal * ($pct / 100), 2) : $fullTotal;
            return [
                'eligible' => $eligible,
                'percentage' => $eligible ? $pct : 100,
                'full_total' => $fullTotal,
                'due_today' => $due,
                'balance_due' => round($fullTotal - $due, 2),
            ];
        },
        'schema_callback' => function () {
            return [
                'eligible' => ['type' => 'boolean'],
                'percentage' => ['type' => 'number'],
                'full_total' => ['type' => 'number'],
                'due_today' => ['type' => 'number'],
                'balance_due' => ['type' => 'number'],
            ];
        },
        'schema_type' => ARRAY_A,
    ]);
});

// ── Priority 5 (before Stripe, which hooks the same action at priority 10 -
// confirmed directly against the installed woo-stripe-payment plugin's own
// source): prepare the deposit child order and swap PaymentContext to it.
// PaymentContext::set_order() only changes what THIS context points to -
// Checkout.php builds its API response from its own separate $this->order
// property (confirmed against WooCommerce core source), which still refers
// to the parent - so the "order" field in the checkout response stays the
// parent automatically, with no extra code needed for that part.
add_action('woocommerce_rest_checkout_process_payment_with_context', 'aswb_prepare_deposit_child', 5, 2);
function aswb_prepare_deposit_child($context, &$result) {
    $parent = $context->order;
    if (!($parent instanceof WC_Order)) return;

    $mode = aswb_effective_mode();
    // $context->payment_method is WooCommerce's own authoritative record of the
    // chosen gateway - never a frontend-supplied label.
    $eligible = ($mode === 'install') && ($context->payment_method === 'stripe_cc');
    if (!$eligible) return;

    $depositAmount = round((float) $parent->get_total() * (aswb_deposit_pct() / 100), 2);

    // Idempotent: a retry (double-click, network retry, a prior failed
    // attempt) reuses the existing pending/failed child instead of creating
    // a new one every time - Store API supports retrying a checkout order.
    $childId = (int) $parent->get_meta('_autosync_deposit_order_id');
    $child = $childId ? wc_get_order($childId) : false;
    if (!$child || !$child->has_status(['pending', 'failed'])) {
        $child = new WC_Order();
        $child->set_parent_id($parent->get_id());
        $child->set_customer_id($parent->get_customer_id());
        $child->set_currency($parent->get_currency());
        foreach (['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $f) {
            $getter = 'get_billing_' . $f;
            $setter = 'set_billing_' . $f;
            $child->$setter($parent->$getter());
        }
        $child->set_payment_method($context->payment_method);
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $child->set_payment_method_title(isset($gateways[$context->payment_method]) ? $gateways[$context->payment_method]->get_title() : $context->payment_method);
        $child->update_meta_data('_autosync_deposit_payment', 'yes');
        $child->update_meta_data('_autosync_parent_order_id', $parent->get_id());

        // A real fee line, not a bare set_total() - keeps the child a
        // coherent order for admin inspection/refunds, and deliberately NOT
        // attached to the generic Wheel/Tire products so it can never affect
        // their stock or sales reporting.
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(aswb_deposit_pct() . '% Deposit for Order #' . $parent->get_order_number());
        $fee->set_amount($depositAmount);
        $fee->set_total($depositAmount);
        $fee->set_tax_status('none');
        $child->add_item($fee);
        $child->calculate_totals(false);
        $child->set_status('pending');
        $child->save();

        $parent->update_meta_data('_autosync_deposit_order_id', $child->get_id());
        $parent->update_meta_data('_autosync_full_total', (float) $parent->get_total());
        $parent->update_meta_data('_autosync_deposit_amount', $depositAmount);
        // Stored per order: the percentage can change later, and a past order's
        // summary must keep describing what was actually charged.
        $parent->update_meta_data('_autosync_deposit_pct', aswb_deposit_pct());
        $parent->update_meta_data('_autosync_balance_due', round((float) $parent->get_total() - $depositAmount, 2));
        $parent->save();
    }

    $context->set_order($child);
}

// ── Priority 20 (after Stripe has processed the child at priority 10): the
// customer-facing frontend lives on a different (sub)domain than this
// WooCommerce install, so the customer must never be sent to WooCommerce's
// own order-received page - only to our own headless confirmation route,
// using the PARENT order's id/key.
// Priority 1000, not 20: stripe_cc is a classic WC_Payment_Gateway, so
// WooCommerce core itself runs it through its own "Legacy" compatibility
// bridge (Automattic\WooCommerce\StoreApi\Legacy::process_legacy_payment),
// registered on this SAME action at priority 999 - confirmed directly
// against WooCommerce core source. That bridge unconditionally overwrites
// redirect_url from the gateway's own return value AFTER running, which
// silently clobbered this fix when it ran at priority 20. Must run later.
add_action('woocommerce_rest_checkout_process_payment_with_context', 'aswb_fix_deposit_redirect', 1000, 2);
function aswb_fix_deposit_redirect($context, &$result) {
    $child = $context->order;
    if (!($child instanceof WC_Order) || $child->get_meta('_autosync_deposit_payment') !== 'yes') return;
    $parentId = (int) $child->get_meta('_autosync_parent_order_id');
    $parent = $parentId ? wc_get_order($parentId) : false;
    if (!$parent) return;
    $frontendUrl = rtrim(aswb_get_allowed_origin(), '/');
    if ($frontendUrl && method_exists($result, 'set_redirect_url')) {
        $result->set_redirect_url($frontendUrl . '/checkout/?confirmed=1&order=' . $parent->get_id() . '&key=' . $parent->get_order_key());
    }
}

// ── Authoritative parent sync - independent of the checkout request itself,
// so 3DS/webhook-delayed completions are handled the same as the synchronous
// path. Stock reservation and coupon holds happen against the PARENT at
// checkout time (confirmed against WooCommerce core - Checkout.php reserves
// stock/holds coupons on $this->order before payment ever runs), but the
// CHILD is what receives payment_complete() - so finalize both explicitly on
// the parent here. Both helper functions already no-op safely on their own
// (wc_reduce_stock_levels skips non-stock-managed products entirely -
// intentional for the generic Wheel/Tire placeholders, whose real inventory
// lives in AutoSync, not WooCommerce; wc_update_coupon_usage_counts has its
// own recorded-usage guard) - no extra idempotency needed here.
add_action('woocommerce_payment_complete', 'aswb_sync_parent_on_deposit_paid', 20, 1);
function aswb_sync_parent_on_deposit_paid($order_id) {
    $child = wc_get_order($order_id);
    if (!$child || $child->get_meta('_autosync_deposit_payment') !== 'yes') return;
    $parentId = (int) $child->get_meta('_autosync_parent_order_id');
    $parent = $parentId ? wc_get_order($parentId) : false;
    if (!$parent) return;

    wc_reduce_stock_levels($parent->get_id());
    wc_update_coupon_usage_counts($parent->get_id());

    // Never $parent->payment_complete() - that records a payment date/
    // transaction and pushes toward Woo's normal "fully paid" semantics,
    // which is wrong here: only the child actually collected money.
    if (!$parent->has_status('aswb-deposit-paid')) {
        $parent->update_status('aswb-deposit-paid', '');
    }
    // WooCommerce binds every transactional email to a fixed list of status
    // TRANSITIONS, not to a status: WC_Email_New_Order's constructor hooks
    // exactly nine, all of them ending in processing/completed/on-hold (read
    // off core, not remembered). pending -> aswb-deposit-paid is in none of
    // them, so until 0.10.4 an install order told nobody - not the shop, not
    // the customer - while a ship order mailed both. Trigger them by hand.
    $mailer = function_exists('WC') ? WC()->mailer() : null;
    $mails = $mailer ? $mailer->get_emails() : [];
    if (isset($mails['WC_Email_New_Order'])) {
        // Safe to reach twice: core keeps its own _new_order_email_sent guard,
        // and a 3DS return and a webhook can both land here for one payment.
        $mails['WC_Email_New_Order']->trigger($parent->get_id(), $parent);
    }
    if (isset($mails['ASWB_Email_Customer_Deposit'])) {
        $mails['ASWB_Email_Customer_Deposit']->trigger($parent->get_id(), $parent);
    }

    $parent->add_order_note(sprintf(
        'Deposit checkout: $%s charged via Stripe (Order #%s). $%s balance due at install - collect separately, this plugin does not charge it automatically.',
        number_format((float) $parent->get_meta('_autosync_deposit_amount'), 2),
        $child->get_order_number(),
        number_format((float) $parent->get_meta('_autosync_balance_due'), 2)
    ));
}

// ── Register the "Deposit Paid" order status - a real, dedicated status
// rather than repurposing an existing one, so it's never confused with a
// fully-paid order in the orders list, reports, or automation.
add_action('init', function () {
    register_post_status('wc-aswb-deposit-paid', [
        'label' => 'Deposit Paid',
        'public' => false,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => ['Deposit Paid <span class="count">(%s)</span>', 'Deposit Paid <span class="count">(%s)</span>'],
    ]);
});
add_filter('wc_order_statuses', function ($statuses) {
    $statuses['wc-aswb-deposit-paid'] = 'Deposit Paid';
    return $statuses;
});

// ── Deposit child orders are an internal payment/accounting artifact, not
// something a customer should ever see - hide from My Account order history
// and skip customer-facing notification emails. Fully visible to staff in
// wp-admin (Orders list, refunds) for audit purposes.
add_filter('woocommerce_my_account_my_orders_query', function ($args) {
    $args['meta_query'] = $args['meta_query'] ?? [];
    $args['meta_query'][] = ['key' => '_autosync_deposit_payment', 'compare' => 'NOT EXISTS'];
    return $args;
});
foreach (['new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice', 'aswb_customer_deposit'] as $aswbEmailId) {
    add_filter("woocommerce_email_enabled_{$aswbEmailId}", function ($enabled, $order) {
        if ($order instanceof WC_Order && $order->get_meta('_autosync_deposit_payment') === 'yes') return false;
        return $enabled;
    }, 10, 2);
}

// ── Also exclude deposit child orders from the DEFAULT wp-admin Orders list -
// staff work with one real order (the parent); the child stays a real,
// fully accessible WooCommerce order (Stripe transaction/refund record,
// direct URL, linked from the parent's own screen below) without cluttering
// the main list as if it were a second customer purchase. meta_query works
// against both classic (postmeta) and HPOS order storage - confirmed
// directly against WooCommerce core's OrdersTableQuery, which supports the
// same meta_query shape as classic WP_Query.
add_filter('woocommerce_order_list_table_prepare_items_query_args', function ($args) {
    $args['meta_query'] = $args['meta_query'] ?? [];
    $args['meta_query'][] = ['key' => '_autosync_deposit_payment', 'compare' => 'NOT EXISTS'];
    return $args;
});

// ── Payment summary + link on the PARENT order's own edit screen, so staff
// have one place to see full total / deposit / balance and reach the
// (otherwise hidden-from-the-list) deposit child for refunds or Stripe
// transaction detail.
add_action('woocommerce_admin_order_data_after_order_details', function ($order) {
    $childId = (int) $order->get_meta('_autosync_deposit_order_id');
    if (!$childId) return; // not a deposit order
    $child = wc_get_order($childId);
    $fullTotal = (float) $order->get_meta('_autosync_full_total');
    $depositAmount = (float) $order->get_meta('_autosync_deposit_amount');
    $balance = (float) $order->get_meta('_autosync_balance_due');
    ?>
    <div class="order_data_column" style="clear:both; width:100%; margin-top:10px; padding-top:10px; border-top:1px solid #eee;">
        <h3>Deposit Payment Summary</h3>
        <p>
            Full Order Total: <strong><?php echo wp_kses_post(wc_price($fullTotal)); ?></strong><br>
            Deposit Required (<?php echo esc_html($order->get_meta('_autosync_deposit_pct') ?: ASWB_DEPOSIT_PCT); ?>%): <?php echo wp_kses_post(wc_price($depositAmount)); ?><br>
            Deposit Paid: <?php echo wp_kses_post(wc_price($depositAmount)); ?><br>
            Remaining Balance: <strong><?php echo wp_kses_post(wc_price($balance)); ?></strong> - collect separately, not charged automatically.
        </p>
        <?php if ($child): ?>
            <p>
                Deposit payment: <a href="<?php echo esc_url($child->get_edit_order_url()); ?>">Order #<?php echo esc_html($child->get_order_number()); ?></a>
                (<?php echo esc_html(wc_get_order_status_name($child->get_status())); ?>, <?php echo wp_kses_post(wc_price((float) $child->get_total())); ?>)
                - refunds for the actual Stripe charge happen there.
            </p>
        <?php endif; ?>
    </div>
    <?php
});

// ── Record mode + install location ON THE ORDER ────────────────────────
// Hook name confirmed against WooCommerce's own StoreApi/Routes/V1/Checkout.php:
// it fires inside create_or_update_draft_order(), before payment, with the
// order as its only argument. The session is the wrong place to read this back
// from later - a 3DS return or a webhook arrives without one.
add_action('woocommerce_store_api_checkout_update_order_meta', 'aswb_record_mode_on_order', 10, 1);
function aswb_record_mode_on_order($order) {
    if (!($order instanceof WC_Order)) return;
    $mode = aswb_effective_mode();
    $order->update_meta_data('_autosync_mode', $mode);
    if ($mode === 'install') {
        $id = WC()->session ? (string) WC()->session->get('autosync_location_id', '') : '';
        $loc = aswb_resolve_location($id);
        if ($loc) $order->update_meta_data('_autosync_install_location', $loc);
    }
    $order->save();
}

// ── An install order has no shipping address to show ──────────────
// Nothing ships, so printing the customer's home address under a "Shipping
// address" heading is simply untrue. Both emails/email-addresses.php and its
// plain twin gate that column on
//   ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() && $shipping
// and WC_Order::needs_shipping_address() ends in this filter, which is handed
// the order - so it can be answered per order rather than per store.
//
// Install orders answer true today only because we deliberately keep a shipping
// rate alive for them (relabelled "Install at {business}" in
// aswb_price_shipping_rates): checkout/index.php throws the moment
// wooGetShippingRates() comes back empty, so the rate cannot just be removed.
//
// What this moves, checked rather than assumed: the two email address templates
// and templates/order/order-details-customer.php (My Account -> View order).
// What it does NOT move: the admin order screen, which keys its shipping column
// off $hide_core_shipping_details instead - staff keep the address. Nothing is
// erased from the order record either; only the customer-facing display changes.
//
// Hiding rather than relabelling because the heading is a bare
// esc_html_e('Shipping address', 'woocommerce') with no filter of its own, so
// renaming it would take a gettext hook or a template override. Nothing is lost:
// the Billing address column still carries the same address, under a heading
// that is actually true.
add_filter('woocommerce_order_needs_shipping_address', 'aswb_hide_install_shipping_address', 10, 3);
function aswb_hide_install_shipping_address($needs, $hide, $order) {
    return aswb_order_is_install($order) ? false : $needs;
}


// ── The totals block: what was actually collected, and where it is going ──
// Filter signature confirmed against WC_Abstract_Order::get_order_item_totals().
// This one filter reaches the customer email, the admin email, the order-received
// page and My Account together, so the figure can never say one thing in an
// email and another on a page.
add_filter('woocommerce_get_order_item_totals', 'aswb_order_item_totals', 10, 2);
function aswb_order_item_totals($rows, $order) {
    if (!is_array($rows) || !($order instanceof WC_Order)) return $rows;

    if (aswb_order_is_install($order) && isset($rows['shipping'])) {
        $business = aswb_business_name();
        $rows['shipping']['label'] = 'Installation:';
        $rows['shipping']['value'] = $business !== '' ? 'Install at ' . $business : 'Install in store';
        // 'meta' carries the shipping METHOD name, and the email template
        // rewrites a row to "Free!" when value and meta are equal on a free
        // method - which would throw this label away. Dropping meta here is
        // what keeps the line readable; checked against the template, not
        // assumed.
        unset($rows['shipping']['meta']);
    }

    $deposit = (float) $order->get_meta('_autosync_deposit_amount');
    if ($deposit <= 0) return $rows;
    $balance = (float) $order->get_meta('_autosync_balance_due');

    // A deposit order's "Total" is the full value of the goods, which is right -
    // but on its own it reads as the amount charged. These two rows say what was
    // taken and what is left, and the second one changes wording once staff mark
    // the order complete, so the "Completed order" email WooCommerce sends after
    // installation does not still ask for money already collected.
    $add = [];
    $add['aswb_deposit'] = [
        'type' => 'aswb_deposit',
        'label' => 'Deposit paid:',
        'value' => wc_price($deposit, ['currency' => $order->get_currency()]),
    ];
    if ($balance > 0) {
        $settled = $order->has_status(['completed', 'refunded']);
        $add['aswb_balance'] = [
            'type' => 'aswb_balance',
            'label' => $settled ? 'Balance paid at install:' : 'Balance due at install:',
            'value' => wc_price($balance, ['currency' => $order->get_currency()]),
        ];
    }

    // Insert immediately after the total, preserving every other row's order.
    $out = [];
    foreach ($rows as $k => $v) {
        $out[$k] = $v;
        if ($k === 'order_total') $out += $add;
    }
    if (!isset($out['aswb_deposit'])) $out += $add; // no order_total row - append
    return $out;
}

// ── The deposit summary, above the item table in every order email ──────
// Hooked where both the HTML and the PLAIN-TEXT order-details templates fire it
// (checked in both), so this renders in either - the admin's "New order" mail
// and the customer's deposit mail alike, each told apart by $sent_to_admin.
add_action('woocommerce_email_before_order_table', 'aswb_email_deposit_block', 10, 4);
function aswb_email_deposit_block($order, $sent_to_admin, $plain_text, $email) {
    if (!($order instanceof WC_Order)) return;
    $deposit = (float) $order->get_meta('_autosync_deposit_amount');
    if ($deposit <= 0) return;
    $full = (float) $order->get_meta('_autosync_full_total');
    $balance = (float) $order->get_meta('_autosync_balance_due');
    $settled = $order->has_status(['completed', 'refunded']);
    $cur = ['currency' => $order->get_currency()];

    // Kept as wc_price()'s own HTML. Stripping the tags here would leave the
    // currency symbol as the raw entity wc_price() emits ("&#36;"), which
    // esc_html() then turns into visible "&amp;#36;" - so each branch below
    // renders it the way that branch actually needs.
    $pairs = [];
    if ($full > 0) $pairs[] = ['Order total', wc_price($full, $cur)];
    $pairs[] = ['Deposit paid today', wc_price($deposit, $cur)];
    if ($balance > 0) {
        $pairs[] = [$settled ? 'Balance paid at install' : 'Balance due at install',
                    wc_price($balance, $cur)];
    }

    $locLines = aswb_location_lines(aswb_order_location($order));
    // The next step belongs to the customer, not to the shop that is taking it.
    $next = (!$sent_to_admin && !$settled) ? aswb_install_next_step() : '';

    if ($plain_text) {
        echo "\n" . ($settled ? 'DEPOSIT SUMMARY' : 'DEPOSIT PAID — BALANCE DUE AT INSTALL') . "\n";
        foreach ($pairs as $p) {
            echo $p[0] . ': ' . html_entity_decode(wp_strip_all_tags($p[1]), ENT_QUOTES, 'UTF-8') . "\n";
        }
        if ($locLines) {
            echo "\n" . 'Install location:' . "\n";
            foreach ($locLines as $l) echo $l . "\n";
        }
        if ($next !== '') echo "\n" . $next . "\n";
        echo "\n----------------------------------------\n\n";
        return;
    }

    echo '<div style="margin:0 0 24px;padding:16px 18px;border:1px solid #e4e4e4;border-radius:6px;">';
    echo '<p style="margin:0 0 10px;font-weight:600;">'
        . esc_html($settled ? 'Deposit summary' : 'Deposit paid — balance due at install')
        . '</p>';
    echo '<table cellspacing="0" cellpadding="3" border="0" style="width:100%;">';
    foreach ($pairs as $p) {
        echo '<tr><td style="text-align:left;">' . esc_html($p[0]) . '</td>'
            . '<td style="text-align:right;white-space:nowrap;">' . wp_kses_post($p[1]) . '</td></tr>';
    }
    echo '</table>';
    if ($locLines) {
        echo '<p style="margin:12px 0 0;"><strong>' . esc_html('Install location') . '</strong><br>'
            . implode('<br>', array_map('esc_html', $locLines)) . '</p>';
    }
    if ($next !== '') echo '<p style="margin:12px 0 0;">' . esc_html($next) . '</p>';
    echo '</div>';
}

// What happens next after a deposit. Filterable so a shop that does not work
// this way can say what it actually does, rather than this being a promise
// made on its behalf.
function aswb_install_next_step() {
    return (string) apply_filters(
        'aswb_install_next_step',
        "We'll call you within 24 hours to schedule your install."
    );
}

// ── The customer's deposit confirmation ──────────────────────────
// WooCommerce has no email for "took a deposit, rest due in person", and every
// stock email is bound to a status TRANSITION it will never see (see the
// trigger in aswb_sync_parent_on_deposit_paid). Registering a real WC_Email
// rather than calling wp_mail() gets this a row under WooCommerce > Settings >
// Emails, with the subject, heading and closing text editable per store, and
// the store's own email branding for free.
add_filter('woocommerce_email_classes', 'aswb_register_email_classes');
function aswb_register_email_classes($emails) {
    if (!class_exists('WC_Email')) return $emails;
    aswb_define_deposit_email();
    $emails['ASWB_Email_Customer_Deposit'] = new ASWB_Email_Customer_Deposit();
    return $emails;
}

function aswb_define_deposit_email() {
    if (class_exists('ASWB_Email_Customer_Deposit', false)) return;

    class ASWB_Email_Customer_Deposit extends WC_Email {
        public function __construct() {
            $this->id = 'aswb_customer_deposit';
            $this->customer_email = true;
            $this->title = 'Deposit received (install order)';
            $this->description = 'Sent to the customer when an install order\'s deposit is paid. '
                . 'The balance is collected in the shop, so WooCommerce sends nothing for this on its own.';
            $this->placeholders = [
                '{order_date}' => '',
                '{order_number}' => '',
                '{business}' => '',
            ];
            parent::__construct();
        }

        public function get_default_subject() {
            return 'Your install at {business} is reserved - order #{order_number}';
        }

        public function get_default_heading() {
            return 'Your install is reserved';
        }

        public function get_default_additional_content() {
            return 'Questions before your install? Just reply to this email.';
        }

        public function trigger($order_id, $order = false) {
            $this->setup_locale();
            if ($order_id && !is_a($order, 'WC_Order')) $order = wc_get_order($order_id);
            if (is_a($order, 'WC_Order')) {
                // Same guard core keeps on its own New Order mail: a 3DS return
                // and a webhook can both reach the trigger for one payment.
                if ($order->get_meta('_aswb_deposit_email_sent') === 'yes') {
                    $this->restore_locale();
                    return;
                }
                $this->object = $order;
                $this->recipient = $order->get_billing_email();
                $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
                $this->placeholders['{order_number}'] = $order->get_order_number();
                $business = aswb_business_name();
                $this->placeholders['{business}'] = $business !== '' ? $business : $this->get_blogname();
            }
            $sent = false;
            if ($this->is_enabled() && $this->get_recipient()) {
                $sent = $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }
            if ($sent && is_a($this->object, 'WC_Order')) {
                $this->object->update_meta_data('_aswb_deposit_email_sent', 'yes');
                $this->object->save();
            }
            $this->restore_locale();
        }

        public function get_content_html() { return $this->aswb_render(false); }
        public function get_content_plain() { return $this->aswb_render(true); }

        // Composed from WooCommerce's own email actions rather than a template
        // file of our own, so the store's header, footer, item table (including
        // the line names, Part Number/Brand/Size labels and thumbnails this
        // plugin already supplies) and address block all come through unchanged.
        protected function aswb_render($plain) {
            $order = $this->object;
            $first = is_a($order, 'WC_Order') ? $order->get_billing_first_name() : '';
            $intro = 'Thanks - your deposit is in and your set is reserved. '
                . 'The balance is due when we fit them.';
            ob_start();
            if ($plain) {
                echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
                echo esc_html(wp_strip_all_tags($this->get_heading()));
                echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
                if ($first !== '') echo esc_html(sprintf('Hi %s,', $first)) . "\n\n";
                echo esc_html($intro) . "\n\n";
            } else {
                do_action('woocommerce_email_header', $this->get_heading(), $this);
                if ($first !== '') echo '<p>' . esc_html(sprintf('Hi %s,', $first)) . '</p>';
                echo '<p>' . esc_html($intro) . '</p>';
            }
            do_action('woocommerce_email_order_details', $order, false, $plain, $this);
            do_action('woocommerce_email_order_meta', $order, false, $plain, $this);
            do_action('woocommerce_email_customer_details', $order, false, $plain, $this);
            $extra = $this->get_additional_content();
            if ($extra) {
                if ($plain) {
                    echo "\n----------------------------------------\n\n";
                    echo esc_html(wp_strip_all_tags(wptexturize($extra))) . "\n\n";
                } else {
                    echo wpautop(wptexturize($extra));
                }
            }
            if ($plain) {
                echo "\n" . esc_html(wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))));
            } else {
                do_action('woocommerce_email_footer', $this);
            }
            return ob_get_clean();
        }
    }
}


function aswb_maybe_send_cors_headers($route) {
    if (strpos($route, '/wc/store/') === false) return;
    $origin = get_http_origin();
    if (!$origin || $origin !== aswb_get_allowed_origin()) return;
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-WC-Store-API-Nonce, Cart-Token');
    header('Access-Control-Expose-Headers: Cart-Token');
}

// ── Accept AutoSync item data on add-to-cart, structurally validated only ───
// The real price is NEVER read from here - see aswb_verify_cart_prices() below,
// which looks it up fresh against the live AutoSync API every time totals are
// calculated. This filter only records which real item a generic product
// represents (type/sku/brand/name/size/vehicle), all of it display-only.
// $add_to_cart_data's real shape, confirmed directly against WooCommerce's
// current source (CartAddItem.php): {id, quantity, variation, cart_item_data: []} -
// custom data MUST be nested under ['cart_item_data'], not set at the top
// level (an earlier draft of this got that wrong - caught via a real curl
// test against the live site: item_data/extensions came back empty and the
// price never got overridden, because this filter's return value was never
// actually reaching the cart item). The request field itself is a plain
// top-level JSON key (`autosync`), not nested under an "extensions" wrapper -
// that wrapper is a different, response-side mechanism
// (woocommerce_store_api_register_endpoint_data), unrelated to this filter.
add_filter('woocommerce_store_api_add_to_cart_data', function ($add_to_cart_data, $request) {
    $data = $request['autosync'] ?? null;
    if (!$data || empty($data['type']) || empty($data['sku']) || empty($data['brand'])) {
        return $add_to_cart_data; // not an AutoSync item, or malformed - leave untouched
    }
    if (!in_array($data['type'], ['wheel', 'tire'], true)) {
        return $add_to_cart_data; // only wheel/tire are catalog-verified against AutoSync
    }
    $add_to_cart_data['cart_item_data']['autosync'] = [
        'type' => sanitize_text_field($data['type']),
        'sku' => sanitize_text_field($data['sku']),
        'brand' => sanitize_text_field($data['brand']),
        'name' => sanitize_text_field($data['name'] ?? ''),
        'size' => sanitize_text_field($data['size'] ?? ''),
        'vehicle' => sanitize_text_field($data['vehicle'] ?? ''),
        // Cosmetic only, and host-restricted - see aswb_safe_image_url.
        'image' => aswb_safe_image_url($data['image'] ?? ''),
    ];
    return $add_to_cart_data;
}, 10, 2);

// ── Verify price server-side and override the cart line - every calculation ─
// Runs on every cart/checkout view, not just once at add-to-cart time, so a
// price change (or a since-removed SKU) is always caught before payment, and
// a submitted browser price is structurally never even read, let alone trusted.
add_action('woocommerce_before_calculate_totals', 'aswb_verify_cart_prices', 20, 1);
function aswb_verify_cart_prices($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    static $priceCache = [];
    static $runNumber = 0;
    $runNumber++;
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $as = $cart_item['autosync'] ?? null;
        if (!$as) {
            // TEMPORARY (2026-08-14): tracking down a real incident where one line
            // item in a mixed tire+wheel cart got charged the generic product's raw
            // placeholder price instead of the verified AutoSync price - this branch
            // is the only way that can happen (metadata missing entirely at
            // calculate-totals time). Remove this log once the cause is confirmed.
            aswb_debug_log('no_autosync_metadata', [
                'run' => $runNumber,
                'cart_item_key' => $cart_item_key,
                'product_id' => $cart_item['product_id'] ?? null,
                'quantity' => $cart_item['quantity'] ?? null,
            ]);
            continue;
        }
        $cacheKey = $as['type'] . '|' . $as['brand'] . '|' . $as['sku'];
        if (!array_key_exists($cacheKey, $priceCache)) {
            $priceCache[$cacheKey] = aswb_lookup_real_price($as['type'], $as['brand'], $as['sku']);
        }
        $price = $priceCache[$cacheKey];
        aswb_debug_log($price !== null ? 'priced' : 'lookup_failed', [
            'run' => $runNumber,
            'cart_item_key' => $cart_item_key,
            'product_id' => $cart_item['product_id'] ?? null,
            'type' => $as['type'], 'brand' => $as['brand'], 'sku' => $as['sku'],
            'price_set' => $price !== null ? $price : 0,
        ]);
        // Fail closed: an unverifiable item (API down, SKU no longer real) prices
        // at $0 rather than trusting anything the browser suggested - never charge
        // for an item this plugin couldn't independently confirm.
        $cart_item['data']->set_price($price !== null ? $price : 0);
    }
}

// TEMPORARY (2026-08-14): rolling debug log for the price-verification incident
// above, capped and readable from the settings page without server access - see
// aswb_verify_cart_prices() above and the "Price Verification Debug Log" section
// on the settings page. Remove this whole function + call sites once resolved.
function aswb_debug_log($event, $data) {
    $log = get_option('aswb_price_debug_log', []);
    array_unshift($log, array_merge(['event' => $event, 'time' => current_time('mysql')], $data));
    update_option('aswb_price_debug_log', array_slice($log, 0, 60), false);
}

function aswb_lookup_real_price($type, $brand, $sku) {
    $endpoint = $type === 'tire' ? '/tires' : '/wheels';
    $listKey = $type === 'tire' ? 'Tires' : 'Wheels'; // response is {"MoreItems":..., "Tires"/"Wheels": [...]}, not a bare array - confirmed via direct curl, not assumed
    $url = ASWB_AUTOSYNC_API_BASE . $endpoint . '?' . http_build_query([
        'key' => aswb_get_api_key(),
        'f-pn' => $sku,
        'f-brand' => $brand,
        'i-price' => 'true',
    ]);
    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $items = is_array($body) ? ($body[$listKey] ?? []) : [];
    if (empty($items[0]['Price'])) return null;
    return (float) $items[0]['Price'];
}

// Catalog image URLs travel from the browser with the cart item. An order
// email is read by the store owner as well as the customer, so an arbitrary
// URL here would be a tracking pixel aimed at their inbox. Every AutoSync
// catalog image is served from one host over https; anything else is dropped
// rather than rendered.
// Confirmed against the live API, not inferred: wheel images are served from
// wheels.autosyncstudio.com (ImgUrlBase and Img500UrlBase), while tire images
// and brand logos come from storage.googleapis.com (autosync_tires/,
// autosync_tires/_spins/, autosync-brand-logos/). 0.10.0 listed only the
// second host, which silently dropped every wheel image - the catalog this
// store actually sells.
//
// Exact host, https only. The base arrives in the API response rather than
// being built here, so a deployment served a new host can add it through the
// filter without waiting on a plugin release.
function aswb_image_hosts() {
    return (array) apply_filters('aswb_image_hosts', [
        'wheels.autosyncstudio.com',
        'storage.googleapis.com',
    ]);
}
function aswb_safe_image_url($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') return '';
    $hosts = array_map('strtolower', aswb_image_hosts());
    if (empty($parts['host']) || !in_array(strtolower($parts['host']), $hosts, true)) return '';
    return esc_url_raw($url);
}

// Every order line was named after the generic placeholder product the cart
// actually holds - one per type - so a wheels order read "Wheel" on every
// line, with the real identity sitting in meta underneath. Built from the
// same fields the cart and checkout already display.
function aswb_line_item_name($as) {
    $brand = trim((string) ($as['brand'] ?? ''));
    $name  = trim((string) ($as['name'] ?? ''));
    // The catalog name usually leads with the brand already - do not say it
    // twice ("Fuel Fuel Rebel").
    if ($brand !== '' && $name !== '' && stripos($name, $brand) !== 0) {
        $name = $brand . ' ' . $name;
    } elseif ($name === '') {
        $name = $brand;
    }
    if ($name === '') $name = trim((string) ($as['sku'] ?? ''));
    $size = trim((string) ($as['size'] ?? ''));
    if ($name !== '' && $size !== '' && stripos($name, $size) === false) {
        $name .= ' - ' . $size;
    }
    return $name;
}

// ── Show the real AutoSync item on cart/checkout and the final order ────────
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
    $as = $cart_item['autosync'] ?? null;
    if (!$as) return $item_data;
    if ($as['name']) $item_data[] = ['name' => 'Item', 'value' => $as['name']];
    if ($as['size']) $item_data[] = ['name' => 'Size', 'value' => $as['size']];
    $item_data[] = ['name' => 'Part Number', 'value' => $as['sku']];
    if ($as['vehicle']) $item_data[] = ['name' => 'Vehicle', 'value' => $as['vehicle']];
    return $item_data;
}, 10, 2);

// WooCommerce renders the PRODUCT's thumbnail on an order line, which here is
// the placeholder product's - the same picture on every line, or none at all.
// The real one travelled with the item.
add_filter('woocommerce_order_item_thumbnail', 'aswb_order_item_thumbnail', 10, 2);
// The email and the admin screen want very different sizes, so build the tag
// once and let each caller say how big. (0.10.2 returned the 64px email image
// to the admin screen too, where the .thumb cell is about 38px - it overflowed
// its grey box and ran across the item name.)
//
// On object-fit: WordPress's kses DOES allow it, so it survives wp_kses_post
// in both callers. It is used for the admin, which renders in a real browser.
// It is deliberately NOT used for the email, where Outlook and Gmail ignore it
// and a fixed square would squash a non-square catalog shot; there, height:auto
// cannot distort anything, and the width ATTRIBUTE still gives Outlook a size.
function aswb_item_image_tag($item, $fallback, $px, $fit) {
    if (!is_object($item) || !method_exists($item, 'get_meta')) return $fallback;
    $url = aswb_safe_image_url((string) $item->get_meta('_autosync_image'));
    if ($url === '') return $fallback;
    $px = (int) $px;
    $style = $fit
        ? 'width:' . $px . 'px;height:' . $px . 'px;object-fit:contain;vertical-align:middle;'
        : 'width:' . $px . 'px;height:auto;vertical-align:middle;';
    return '<img src="' . esc_url($url) . '" alt="" width="' . $px . '" style="' . esc_attr($style) . '" />';
}

function aswb_order_item_thumbnail($image, $item) {
    return aswb_item_image_tag($item, $image, 64, false);
}

// The admin order screen does NOT use the filter above - confirmed in
// includes/admin/meta-boxes/views/html-order-item.php, which applies
// 'woocommerce_admin_order_item_thumbnail' ($thumbnail, $item_id, $item)
// around $product->get_image() instead. Without this, the shop owner keeps
// seeing the placeholder product's blank square on every line while the
// customer's email shows the real wheel.
add_filter('woocommerce_admin_order_item_thumbnail', 'aswb_admin_order_item_thumbnail', 10, 3);
function aswb_admin_order_item_thumbnail($thumbnail, $item_id, $item) {
    return aswb_item_image_tag($item, $thumbnail, 38, true);
}

// The admin order screen does NOT hide item meta by underscore prefix. The
// underscore only hides a key from the CUSTOMER-facing views; on the admin
// side the template skips exact matches against
// OrderItemMetaUtil::get_hidden_keys(), a fixed 12-key list, so
// _autosync_image was printed on every line as a raw URL.
add_filter('woocommerce_hidden_order_itemmeta', function ($keys) {
    $keys[] = '_autosync_image';
    return $keys;
});

// Order emails ship with images off by default, which would leave the line art
// above rendering nowhere a customer actually looks.
add_filter('woocommerce_email_order_items_args', function ($args) {
    $args['show_image'] = true;
    $args['image_size'] = [64, 64];
    return $args;
});

add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    $as = $values['autosync'] ?? null;
    if (!$as) return;
    $name = aswb_line_item_name($as);
    if ($name !== '') $item->set_name($name);
    // Labels now match what the cart and checkout already show (see
    // woocommerce_get_item_data above). "AutoSync Part Number" was our
    // internal wording on a line a customer reads.
    $item->add_meta_data('Part Number', $as['sku']);
    $item->add_meta_data('Brand', $as['brand']);
    if ($as['size']) $item->add_meta_data('Size', $as['size']);
    if ($as['vehicle']) $item->add_meta_data('Vehicle', $as['vehicle']);
    // Underscore-prefixed so WooCommerce keeps it out of the customer's view:
    // it is read back by the thumbnail filter, not something to read.
    $image = aswb_safe_image_url($as['image'] ?? '');
    if ($image !== '') $item->add_meta_data('_autosync_image', $image);
}, 10, 4);
