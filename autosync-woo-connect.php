<?php
/**
 * Plugin Name: AutoSync Woo Connect
 * Description: Bridges AutoSync's real tire/wheel catalog into WooCommerce's
 *              headless Store API cart/checkout. The AutoSync catalog is never
 *              synced into WooCommerce - two generic hidden products (Wheel,
 *              Tire) carry the real SKU/brand as cart-item metadata, and the
 *              real price is verified server-side against the live AutoSync
 *              API on every total calculation, never trusted from the browser.
 * Version: 0.5.0
 *
 * Install: upload the zip via Plugins -> Add New Plugin -> Upload Plugin,
 * then Activate (or re-upload over an existing install - WordPress offers to
 * replace it). Check the Version number above/in the Plugins list after
 * uploading to confirm the new copy actually took - bump it on every edit.
 */

if (!defined('ABSPATH')) exit; // no direct access

// ── Auto-provision a placeholder shipping method, on every zone missing one ─
// Checkout requires a real shipping rate whenever an order needs shipping -
// with zero methods configured anywhere, checkout fails outright ("this order
// requires a shipping option"). The real shipping CHARGE is already computed
// independently as our own "Shipping" fee (see aswb_apply_fees below) - this
// only satisfies checkout's rate-selection requirement with a Free Shipping method,
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
            // Free Shipping, not a Flat Rate priced at 0 - "free" is unambiguous and
            // has no cost field to accidentally edit later. Editing this method's
            // cost would double-charge, since the real amount already lives in our
            // own "Shipping" fee (aswb_apply_fees) - a Flat Rate with a $0 field
            // sitting right there in wp-admin invites exactly that mistake.
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
        </table>

        <h2>Fees &amp; Shipping <span style="font-weight:normal; font-size:13px; color:#666;">(read-only - source of truth is <code>autosyncadmin</code>)</span></h2>
        <p>Shown here for visibility only. Edit these in <code>autosyncadmin</code>'s "Fees &amp; Shipping" card - this plugin only ever reads them (live from Core Site URL above, falling back to the last-saved copy below if that's blank or unreachable). Disabled fields aren't submitted, so clicking "Save Changes" never touches these.</p>
        <?php $fees = aswb_fee_settings(); $waiverModes = ['never' => 'Always charge', 'joint' => 'Waive for a full package', 'independent' => 'Waive per category independently', 'always' => 'Always free']; ?>
        <table class="form-table aswb-readonly-fees">
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
// are computed as WooCommerce "fees" instead (see aswb_apply_fees below), not
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
            if (isset($data['mode']) && in_array($data['mode'], ['install', 'ship'], true)) {
                WC()->session->set('autosync_mode', sanitize_text_field($data['mode']));
            }
            if (array_key_exists('state', $data)) {
                WC()->session->set('autosync_state', strtoupper(sanitize_text_field((string) $data['state'])));
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
add_action('woocommerce_cart_calculate_fees', 'aswb_apply_fees', 10, 1);
function aswb_apply_fees($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    $mode = WC()->session ? WC()->session->get('autosync_mode', 'install') : 'install';
    $state = WC()->session ? WC()->session->get('autosync_state', '') : '';

    $tireQty = 0;
    $wheelQty = 0;
    foreach ($cart->get_cart() as $item) {
        $as = $item['autosync'] ?? null;
        if (!$as) continue;
        if ($as['type'] === 'tire') $tireQty += $item['quantity'];
        if ($as['type'] === 'wheel') $wheelQty += $item['quantity'];
    }

    $fees = aswb_fee_settings();
    $freeSetSize = (float) $fees['freeSetSize'];
    $isPackage = $tireQty >= $freeSetSize && $wheelQty >= $freeSetSize;

    // Mirrors checkout/index.html's computeQtyFee() exactly - see that file's
    // comment for what each waiverMode/applyTo combination means.
    $qtyFee = function ($enabled, $amount, $waiverMode, $applyTo, $applies) use ($tireQty, $wheelQty, $freeSetSize, $isPackage) {
        if (!$enabled || !$applies || $waiverMode === 'always') return 0;
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
    };

    // Mount & Balance / Disposal are pickup-only services - unconditionally $0
    // for ship-mode regardless of waiverMode, same rule as the frontend.
    $mount = $qtyFee($fees['mountBalanceEnabled'], (float) $fees['mountBalanceAmount'], $fees['mountBalanceWaiver'], 'both', $mode !== 'ship');
    $disposal = $qtyFee($fees['disposalEnabled'], (float) $fees['disposalAmount'], $fees['disposalWaiver'], 'tire', $mode !== 'ship');
    $shipping = $qtyFee($fees['shippingEnabled'], (float) $fees['shippingAmount'], $fees['shippingWaiver'], 'both', $mode === 'ship');

    // Each fee is added non-taxable - sales tax below is our own flat-rate line,
    // not WooCommerce's tax engine, so these shouldn't also get taxed by it.
    if ($mount > 0) $cart->add_fee('Mount & Balance', $mount, false);
    if ($disposal > 0) $cart->add_fee('Tire Disposal', $disposal, false);
    if ($shipping > 0) $cart->add_fee('Shipping', $shipping, false);

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
// frontend - a raw API call cannot influence what gets charged.
define('ASWB_DEPOSIT_PCT', 25);

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
            $mode = WC()->session ? WC()->session->get('autosync_mode', 'install') : 'install';
            $eligible = $mode === 'install';
            $fullTotal = (float) WC()->cart->get_total('edit');
            $due = $eligible ? round($fullTotal * (ASWB_DEPOSIT_PCT / 100), 2) : $fullTotal;
            return [
                'eligible' => $eligible,
                'percentage' => $eligible ? ASWB_DEPOSIT_PCT : 100,
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

    $mode = WC()->session ? WC()->session->get('autosync_mode', 'install') : 'install';
    // $context->payment_method is WooCommerce's own authoritative record of the
    // chosen gateway - never a frontend-supplied label.
    $eligible = ($mode === 'install') && ($context->payment_method === 'stripe_cc');
    if (!$eligible) return;

    $depositAmount = round((float) $parent->get_total() * (ASWB_DEPOSIT_PCT / 100), 2);

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
        $fee->set_name(ASWB_DEPOSIT_PCT . '% Deposit for Order #' . $parent->get_order_number());
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
foreach (['new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice'] as $aswbEmailId) {
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
            Deposit Required (<?php echo esc_html(ASWB_DEPOSIT_PCT); ?>%): <?php echo wp_kses_post(wc_price($depositAmount)); ?><br>
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

add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    $as = $values['autosync'] ?? null;
    if (!$as) return;
    $item->add_meta_data('AutoSync Part Number', $as['sku']);
    $item->add_meta_data('AutoSync Brand', $as['brand']);
    if ($as['size']) $item->add_meta_data('Size', $as['size']);
    if ($as['vehicle']) $item->add_meta_data('Vehicle', $as['vehicle']);
}, 10, 4);
