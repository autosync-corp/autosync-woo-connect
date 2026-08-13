<?php
/**
 * Plugin Name: AutoSync WooCommerce Bridge
 * Description: Bridges AutoSync's real tire/wheel catalog into WooCommerce's
 *              headless Store API cart/checkout. The AutoSync catalog is never
 *              synced into WooCommerce - two generic hidden products (Wheel,
 *              Tire) carry the real SKU/brand as cart-item metadata, and the
 *              real price is verified server-side against the live AutoSync
 *              API on every total calculation, never trusted from the browser.
 * Version: 0.1.5
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
    // v2: bumped from v1 (which used a Flat Rate placeholder, since replaced
    // with Free Shipping) specifically so this runs again even on a site that
    // already ran v1 - the "skip a zone with a method" guard below would
    // otherwise leave the old Flat Rate method in place forever.
    if (get_option('aswb_shipping_provisioned_v2')) return;
    aswb_provision_shipping_methods();
    update_option('aswb_shipping_provisioned_v2', 1);
});

function aswb_provision_shipping_methods() {
    if (!class_exists('WC_Shipping_Zones') || !class_exists('WC_Shipping_Zone')) return;
    $zoneIds = [0]; // zone 0 = the always-existing "Locations not covered by your other zones"
    foreach (WC_Shipping_Zones::get_zones() as $zone) {
        $zoneIds[] = $zone['id'];
    }
    foreach (array_unique($zoneIds) as $zoneId) {
        $zone = new WC_Shipping_Zone($zoneId);
        if (!empty($zone->get_shipping_methods())) continue; // already has a method - leave it alone
        // Free Shipping, not a Flat Rate priced at 0 - "free" is unambiguous and
        // has no cost field to accidentally edit later. Editing this method's
        // cost would double-charge, since the real amount already lives in our
        // own "Shipping" fee (aswb_apply_fees) - a Flat Rate with a $0 field
        // sitting right there in wp-admin invites exactly that mistake.
        $instanceId = $zone->add_shipping_method('free_shipping');
        if (!$instanceId) continue;
        update_option('woocommerce_free_shipping_' . $instanceId . '_settings', [
            'title' => 'Standard Shipping',
            'requires' => '', // always available, no minimum-order/coupon condition
        ]);
    }
}

// ── Configuration ────────────────────────────────────────────────────────────
// API key and allowed origin are editable under Settings -> AutoSync Bridge in
// wp-admin (see the settings page below) - no code edit/re-upload needed to
// change deployments, just the two things every install actually differs on.
define('ASWB_AUTOSYNC_API_BASE', 'https://api.autosyncstudio.com');

function aswb_get_api_key() {
    return get_option('aswb_autosync_api_key', 'MFCtst');
}

function aswb_get_allowed_origin() {
    return get_option('aswb_allowed_origin', 'https://devcore.autosynccorp.com');
}

// ── Settings page: Settings -> AutoSync Bridge ──────────────────────────────
add_action('admin_menu', function () {
    add_options_page('AutoSync Bridge', 'AutoSync Bridge', 'manage_options', 'aswb-settings', 'aswb_render_settings_page');
});

add_action('admin_init', function () {
    register_setting('aswb_settings', 'aswb_autosync_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('aswb_settings', 'aswb_allowed_origin', ['sanitize_callback' => function ($v) { return rtrim(trim($v), '/'); }]);
});

function aswb_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>AutoSync Bridge</h1>
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
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}

// The generic hidden WooCommerce products created for this deployment. Only
// Wheel/Tire are used as real cart items - Disposal/Mount & Balance/Sales Tax
// are computed as WooCommerce "fees" instead (see aswb_apply_fees below), not
// cart items, so they don't need product IDs here even though matching hidden
// products exist in the store (harmless, just unused by this mechanism).
define('ASWB_PRODUCT_WHEEL', 14307);
define('ASWB_PRODUCT_TIRE', 14308);

// Fee business rules - mirrors checkout/index.html's computeQtyFee logic
// exactly. Edit these to match autosyncadmin's Fees & Shipping card.
define('ASWB_FEE_MOUNT_BALANCE_ENABLED', true);
define('ASWB_FEE_MOUNT_BALANCE_AMOUNT', 25);
define('ASWB_FEE_MOUNT_BALANCE_WAIVER', 'independent'); // 'never' | 'joint' | 'independent'
define('ASWB_FEE_DISPOSAL_ENABLED', true);
define('ASWB_FEE_DISPOSAL_AMOUNT', 3);
define('ASWB_FEE_DISPOSAL_WAIVER', 'joint');
define('ASWB_FEE_SHIPPING_ENABLED', true);
define('ASWB_FEE_SHIPPING_AMOUNT', 50);
define('ASWB_FEE_SHIPPING_WAIVER', 'independent');
define('ASWB_FEE_FREE_SET_SIZE', 4);
define('ASWB_FEE_SALES_TAX_ENABLED', true);
define('ASWB_FEE_SALES_TAX_DEFAULT_RATE', 6);
// Per-state overrides, e.g. ['CA' => 7.25] - keys uppercase, matched against
// the state the frontend sends via the cart/extensions call below.
define('ASWB_SALES_TAX_STATE_OVERRIDES', []);

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

    $freeSetSize = ASWB_FEE_FREE_SET_SIZE;
    $isPackage = $tireQty >= $freeSetSize && $wheelQty >= $freeSetSize;

    // Mirrors checkout/index.html's computeQtyFee() exactly - see that file's
    // comment for what each waiverMode/applyTo combination means.
    $qtyFee = function ($enabled, $amount, $waiverMode, $applyTo, $applies) use ($tireQty, $wheelQty, $freeSetSize, $isPackage) {
        if (!$enabled || !$applies) return 0;
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
    $mount = $qtyFee(ASWB_FEE_MOUNT_BALANCE_ENABLED, ASWB_FEE_MOUNT_BALANCE_AMOUNT, ASWB_FEE_MOUNT_BALANCE_WAIVER, 'both', $mode !== 'ship');
    $disposal = $qtyFee(ASWB_FEE_DISPOSAL_ENABLED, ASWB_FEE_DISPOSAL_AMOUNT, ASWB_FEE_DISPOSAL_WAIVER, 'tire', $mode !== 'ship');
    $shipping = $qtyFee(ASWB_FEE_SHIPPING_ENABLED, ASWB_FEE_SHIPPING_AMOUNT, ASWB_FEE_SHIPPING_WAIVER, 'both', $mode === 'ship');

    // Each fee is added non-taxable - sales tax below is our own flat-rate line,
    // not WooCommerce's tax engine, so these shouldn't also get taxed by it.
    if ($mount > 0) $cart->add_fee('Mount & Balance', $mount, false);
    if ($disposal > 0) $cart->add_fee('Tire Disposal', $disposal, false);
    if ($shipping > 0) $cart->add_fee('Shipping', $shipping, false);

    if (ASWB_FEE_SALES_TAX_ENABLED) {
        $subtotal = $cart->get_subtotal();
        $rate = ASWB_SALES_TAX_STATE_OVERRIDES[$state] ?? ASWB_FEE_SALES_TAX_DEFAULT_RATE;
        $tax = round($subtotal * ($rate / 100), 2);
        if ($tax > 0) $cart->add_fee('Sales Tax', $tax, false);
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
    foreach ($cart->get_cart() as $cart_item) {
        $as = $cart_item['autosync'] ?? null;
        if (!$as) continue;
        $cacheKey = $as['type'] . '|' . $as['brand'] . '|' . $as['sku'];
        if (!array_key_exists($cacheKey, $priceCache)) {
            $priceCache[$cacheKey] = aswb_lookup_real_price($as['type'], $as['brand'], $as['sku']);
        }
        $price = $priceCache[$cacheKey];
        // Fail closed: an unverifiable item (API down, SKU no longer real) prices
        // at $0 rather than trusting anything the browser suggested - never charge
        // for an item this plugin couldn't independently confirm.
        $cart_item['data']->set_price($price !== null ? $price : 0);
    }
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
