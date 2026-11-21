<?php
/**
 * AriunetClub Phase 4 functional tests — real PHP, no WordPress.
 *
 * Loads the REAL account module classes (ClubEndpoint, RulePublicSummary,
 * Money, JalaliDate, IdentityService, PhoneNormalizer, LedgerService) on a
 * runtime-stubbed WordPress/WooCommerce layer plus in-memory gateways, and
 * renders the real template through output buffering. Covers the phase 4
 * acceptance criteria: own-data-only, RTL output, no-phone guide message,
 * escaping, pagination, template override and asset loading.
 *
 * Run: php tools/account-functional-tests.php  (exit code 0 = all green)
 */

error_reporting( E_ALL );

// ---------------------------------------------------------------------------
// 1) WordPress / WooCommerce runtime stubs
// ---------------------------------------------------------------------------

$GLOBALS['ariunetclub_test_settings']       = array();
$GLOBALS['ariunetclub_test_user_meta']      = array();
$GLOBALS['ariunetclub_test_hooks']          = array();
$GLOBALS['ariunetclub_test_transients']     = array();
$GLOBALS['ariunetclub_test_query_vars']     = array();
$GLOBALS['ariunetclub_test_user_id']        = 2;
$GLOBALS['ariunetclub_test_user_logged_in'] = true;
$GLOBALS['ariunetclub_test_flushed']        = 0;
$GLOBALS['ariunetclub_test_enqueued']       = array();
$GLOBALS['ariunetclub_test_rewrite']        = array();
$GLOBALS['ariunetclub_test_template']       = null;
$GLOBALS['ariunetclub_test_is_account']     = true;
$GLOBALS['ariunetclub_test_endpoint']       = 'club';

define( 'ARIUNETCLUB_VERSION', '0.4.0' );
define( 'ARIUNETCLUB_FILE', __DIR__ . '/../ariunetclub.php' );
define( 'ARIUNETCLUB_PATH', __DIR__ . '/../' );
define( 'ARIUNETCLUB_URL', 'https://example.test/wp-content/plugins/ariunetclub/' );
define( 'EP_ROOT', 8 );
define( 'EP_PAGES', 512 );
define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/../vendor/autoload.php';

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html_e( $text, $domain = null ) { echo $text; }
function esc_attr_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ariunetclub_test_hooks'][ $hook ][] = array( $cb, $args ); }
function do_action( $hook, ...$args ) {
        foreach ( $GLOBALS['ariunetclub_test_hooks'][ $hook ] ?? array() as $pair ) {
                call_user_func_array( $pair[0], array_slice( $args, 0, $pair[1] ) );
        }
}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['ariunetclub_test_settings'][ $name ] ?? $default; }
function add_option( $name, $value ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['ariunetclub_test_settings'][ $name ] = $value; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return $GLOBALS['ariunetclub_test_user_id']; }
function is_user_logged_in() { return $GLOBALS['ariunetclub_test_user_logged_in']; }
function current_time( $type, $gmt = false ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, '.', ',' ); }
function wc_get_price_decimals() { return 0; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $seconds = 0 ) { return true; }
function delete_transient( $key ) { return true; }
function wp_upload_dir() { return array( 'error' => 'uploads disabled in tests', 'basedir' => '/tmp' ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_mkdir_p( $dir ) { return false; }
function get_user_meta( $user_id, $key, $single = false ) {
        return $GLOBALS['ariunetclub_test_user_meta'][ (int) $user_id ][ $key ] ?? '';
}
function get_query_var( $var, $default = '' ) {
        return $GLOBALS['ariunetclub_test_query_vars'][ $var ] ?? $default;
}
function locate_template( $templates, $load = false, $require_once = true ) {
        return $GLOBALS['ariunetclub_test_template'] ?? '';
}
function add_rewrite_endpoint( $name, $places ) {
        $GLOBALS['ariunetclub_test_rewrite'] = array( $name, $places );
}
function flush_rewrite_rules( $hard = true ) {
        $GLOBALS['ariunetclub_test_flushed']++;
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = null ) {
        $GLOBALS['ariunetclub_test_enqueued'][ $handle ] = $src;
}
function is_account_page() { return $GLOBALS['ariunetclub_test_is_account']; }
function is_wc_endpoint_url( $endpoint = '' ) { return $GLOBALS['ariunetclub_test_endpoint'] === (string) $endpoint; }
function wc_get_endpoint_url( $endpoint, $value = '', $permalink = '' ) {
        return 'https://example.test/my-account/' . $endpoint . ( '' !== $value ? '/' . $value : '' );
}
function wc_get_page_permalink( $page ) { return 'https://example.test/my-account/'; }
function get_woocommerce_currency_symbol( $currency = '' ) { return 'تومان'; }

// ---------------------------------------------------------------------------
// 2) In-memory gateways mirroring the SQL repositories
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../tests/unit/Fixtures.php';

use AriunetClub\Tests\InMemoryRulesLedger;
use AriunetClub\Tests\FakeRuleGateway;
use AriunetClub\Modules\Account\AccountModule;
use AriunetClub\Modules\Account\ClubEndpoint;
use AriunetClub\Modules\Identity\IdentityService;
use AriunetClub\Modules\Identity\PhoneNormalizer;
use AriunetClub\Modules\Ledger\LedgerService;

$PASS = 0;
$FAIL = 0;
function check( $label, $condition ) {
        global $PASS, $FAIL;
        if ( $condition ) {
                $PASS++;
                echo "  PASS  $label\n";
        } else {
                $FAIL++;
                echo "  FAIL  $label\n";
        }
}

function fresh_world() {
        $GLOBALS['ariunetclub_test_settings']       = array();
        $GLOBALS['ariunetclub_test_user_meta']      = array();
        $GLOBALS['ariunetclub_test_hooks']          = array();
        $GLOBALS['ariunetclub_test_query_vars']     = array();
        $GLOBALS['ariunetclub_test_flushed']        = 0;
        $GLOBALS['ariunetclub_test_enqueued']       = array();
        $GLOBALS['ariunetclub_test_rewrite']        = array();
        $GLOBALS['ariunetclub_test_template']       = null;
        $GLOBALS['ariunetclub_test_user_id']        = 2;
        $GLOBALS['ariunetclub_test_user_logged_in'] = true;
        $GLOBALS['ariunetclub_test_is_account']     = true;
        $GLOBALS['ariunetclub_test_endpoint']       = 'club';

        $ledger   = new InMemoryRulesLedger();
        $rules    = new FakeRuleGateway();
        $identity = new IdentityService( new PhoneNormalizer() );
        return array(
                new ClubEndpoint( $ledger, $rules, $identity ),
                $ledger,
                $rules,
                new LedgerService( $ledger ),
        );
}

function render_club( ClubEndpoint $endpoint ) {
        ob_start();
        $endpoint->render();
        return (string) ob_get_clean();
}

echo "=== 1) Hook wiring and rewrite registration ===\n";
list( $endpoint ) = fresh_world();
$endpoint->register();
check( 'rewrite endpoint hook registered on init', isset( $GLOBALS['ariunetclub_test_hooks']['init'] ) );
check( 'menu filter registered', isset( $GLOBALS['ariunetclub_test_hooks']['woocommerce_account_menu_items'] ) );
check( 'endpoint content hook registered', isset( $GLOBALS['ariunetclub_test_hooks']['woocommerce_account_club_endpoint'] ) );
check( 'enqueue hook registered', isset( $GLOBALS['ariunetclub_test_hooks']['wp_enqueue_scripts'] ) );
ClubEndpoint::register_rewrite();
check( 'add_rewrite_endpoint called with club + EP_ROOT|EP_PAGES', array( 'club', EP_ROOT | EP_PAGES ) === $GLOBALS['ariunetclub_test_rewrite'] );

echo "=== 2) Rewrite flush guard is one-shot ===\n";
list( $endpoint ) = fresh_world();
$endpoint->maybe_flush_rewrite();
check( 'flush happens when the version option is missing', 1 === $GLOBALS['ariunetclub_test_flushed'] );
check( 'version option stored', '1' === get_option( 'ariunetclub_rewrite_version' ) );
$endpoint->maybe_flush_rewrite();
check( 'second run does not flush again', 1 === $GLOBALS['ariunetclub_test_flushed'] );

echo "=== 3) Full render: own data only, balance, Jalali dates, Persian digits ===\n";
list( $endpoint, $ledger, $rules, $service ) = fresh_world();
$GLOBALS['ariunetclub_test_user_meta'][2]['billing_phone'] = '09121234567';
$service->credit( 2, 50000.0, 'u2-credit', array( 'created_at' => '2026-11-21 10:00:00', 'note' => 'کش‌بک سفارش', 'ref_type' => 'order', 'ref_id' => 120 ) );
$service->credit( 3, 90000.0, 'u3-credit', array( 'created_at' => '2026-11-21 09:00:00', 'note' => 'SECRET-NOTE-USER-3' ) );
$service->hold( 2, 20000.0, 'u2-hold', array( 'created_at' => '2026-11-20 08:00:00' ) );
$rules->seed( array(
        'id'         => 1,
        'event_type' => 'order.status.completed',
        'status'     => 'active',
        'priority'   => 5,
        'title'      => 'کش‌بک سفارش‌های تکمیل‌شده',
        'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 2, 'max_cap' => 50000 ) ),
) );

$html = render_club( $endpoint );
check( 'available balance rendered in Persian digits', false !== strpos( $html, '۵۰,۰۰۰' ) );
check( 'held balance rendered', false !== strpos( $html, '۲۰,۰۰۰' ) );
check( 'masked phone shown', false !== strpos( $html, '۰۹۱۲***۵۶۷' ) );
check( 'full phone never printed', false === strpos( $html, '09121234567' ) );
check( 'other user note not leaked (S5)', false === strpos( $html, 'SECRET-NOTE-USER-3' ) );
check( 'own note rendered', false !== strpos( $html, 'کش‌بک سفارش' ) );
check( 'Jalali date rendered in Persian digits', false !== strpos( $html, '۱۴۰۵/۰۸/۲۹' ) || false !== strpos( $html, '۱۴۰۵/۰۸/۳۰' ) );
check( 'order link rendered', false !== strpos( $html, 'view-order/120' ) );
check( 'currency symbol rendered', false !== strpos( $html, 'تومان' ) );
check( 'RTL direction set on the scope', false !== strpos( $html, 'dir="rtl"' ) );
check( 'rules section shows title', false !== strpos( $html, 'کش‌بک سفارش‌های تکمیل‌شده' ) );
check( 'rules section shows reward only', false !== strpos( $html, '۲٪ کش‌بک' ) );
check( 'rules section hides caps', false === strpos( $html, 'سقف' ) );
check( 'no-phone notice absent when phone exists', false === strpos( $html, 'شماره موبایلی برای حساب شما ثبت نشده است' ) );

echo "=== 4) XSS corpus is escaped (S7) ===\n";
list( $endpoint, $ledger, $rules, $service ) = fresh_world();
$GLOBALS['ariunetclub_test_user_meta'][2]['billing_phone'] = '09121234567';
$service->credit( 2, 1000.0, 'xss-1', array( 'created_at' => '2026-11-21 10:00:00', 'note' => '<script>alert("x")</script><img src=x onerror=alert(1)>"\'>' ) );
$rules->seed( array(
        'id'         => 2,
        'event_type' => 'order.status.completed',
        'status'     => 'active',
        'priority'   => 5,
        'title'      => '<script>alert("rule")</script>',
        'reward'     => wp_json_encode( array( 'type' => 'percent', 'value' => 5 ) ),
) );
$html = render_club( $endpoint );
check( 'no raw script tag in output', false === strpos( $html, '<script>' ) );
check( 'note escaped', false !== strpos( $html, '&lt;script&gt;' ) );
check( 'rule title escaped', false !== strpos( $html, '&lt;script&gt;alert(&quot;rule&quot;)&lt;/script&gt;' ) );
check( 'no raw img tag for attribute injection', false === strpos( $html, '<img' ) );
check( 'onerror only survives inside escaped text', substr_count( $html, 'onerror=' ) === substr_count( $html, '&lt;img src=x onerror=' ) );

echo "=== 5) No-phone guide message instead of an error (acceptance) ===\n";
list( $endpoint, $ledger, $rules, $service ) = fresh_world();
$service->credit( 2, 5000.0, 'np-1', array( 'created_at' => '2026-11-21 10:00:00' ) );
$html = render_club( $endpoint );
check( 'guide message rendered', false !== strpos( $html, 'شماره موبایلی برای حساب شما ثبت نشده است' ) );
check( 'guide links to edit-address', false !== strpos( $html, 'edit-address' ) );
check( 'balance still rendered', false !== strpos( $html, '۵,۰۰۰' ) );
check( 'history still rendered', false !== strpos( $html, 'تاریخچه تراکنش‌ها' ) );

echo "=== 6) Logged out: no data, no fatal ===\n";
list( $endpoint, $ledger, $rules, $service ) = fresh_world();
$GLOBALS['ariunetclub_test_user_logged_in'] = false;
$GLOBALS['ariunetclub_test_user_id']        = 0;
$service->credit( 2, 5000.0, 'lo-1', array( 'created_at' => '2026-11-21 10:00:00', 'note' => 'SECRET-LOGGED-IN-NOTE' ) );
$html = render_club( $endpoint );
check( 'login prompt shown', false !== strpos( $html, 'وارد حساب کاربری شوید' ) );
check( 'no ledger data for anonymous visitor', false === strpos( $html, 'SECRET-LOGGED-IN-NOTE' ) );

echo "=== 7) Pagination links with 20 per page ===\n";
list( $endpoint, $ledger, $rules, $service ) = fresh_world();
for ( $i = 1; $i <= 45; $i++ ) {
        $service->credit( 2, 100.0, "pg-{$i}", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $i * 60 ) ) );
}
$GLOBALS['ariunetclub_test_query_vars']['club'] = '';
$html = render_club( $endpoint );
check( 'pagination rendered for 3 pages', false !== strpos( $html, 'club/page/3' ) );
check( 'page one has 20 rows', 20 === substr_count( $html, '<tr>' ) - 1 ); // minus thead row
$GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/2';
$html = render_club( $endpoint );
check( 'page two url format honoured', false !== strpos( $html, 'club/page/2' ) );
check( 'aria-current marks the active page', false !== strpos( $html, 'aria-current="page"' ) );
$GLOBALS['ariunetclub_test_query_vars']['club'] = 'page/999';
$html = render_club( $endpoint );
check( 'beyond-last page falls back to the last page content', false === strpos( $html, 'هنوز تراکنشی ثبت نشده است' ) );

echo "=== 8) Empty states ===\n";
list( $endpoint ) = fresh_world();
$GLOBALS['ariunetclub_test_user_meta'][2]['billing_phone'] = '09121234567';
$html = render_club( $endpoint );
check( 'empty history guide shown', false !== strpos( $html, 'هنوز تراکنشی ثبت نشده است' ) );
check( 'empty rules guide shown', false !== strpos( $html, 'قانون کش‌بک فعالی وجود ندارد' ) );

echo "=== 9) Assets load on the club tab only ===\n";
list( $endpoint ) = fresh_world();
$GLOBALS['ariunetclub_test_is_account'] = false;
$GLOBALS['ariunetclub_test_endpoint']  = 'club';
$endpoint->enqueue_assets();
check( 'no stylesheet outside My Account', array() === $GLOBALS['ariunetclub_test_enqueued'] );
$GLOBALS['ariunetclub_test_is_account'] = true;
$GLOBALS['ariunetclub_test_endpoint']  = 'orders';
$endpoint->enqueue_assets();
check( 'no stylesheet on other endpoints', array() === $GLOBALS['ariunetclub_test_enqueued'] );
$GLOBALS['ariunetclub_test_endpoint'] = 'club';
$endpoint->enqueue_assets();
check( 'stylesheet enqueued on the club tab', isset( $GLOBALS['ariunetclub_test_enqueued']['ariunetclub-account'] ) && false !== strpos( $GLOBALS['ariunetclub_test_enqueued']['ariunetclub-account'], 'ariunetclub-account.css' ) );

echo "=== 10) Theme template override wins (yourtheme/ariunetclub/account/club.php) ===\n";
list( $endpoint ) = fresh_world();
$theme_file              = sys_get_temp_dir() . '/ariunetclub-club-override.php';
$GLOBALS['ariunetclub_test_template'] = $theme_file;
file_put_contents( $theme_file, '<?php echo "THEME-OVERRIDE-MARKER";' );
$html = render_club( $endpoint );
check( 'theme template used when present', false !== strpos( $html, 'THEME-OVERRIDE-MARKER' ) );
$GLOBALS['ariunetclub_test_template'] = null;
$html = render_club( $endpoint );
check( 'plugin template used as fallback', false !== strpos( $html, 'ariunetclub-account__panel' ) );

echo "=== 11) AccountModule boot is safe without wpdb ===\n";
fresh_world();
$module = new AccountModule();
$module->boot(); // Must be a silent no-op without a global wpdb.
check( 'module boot without wpdb does not fatal', true );
check( 'module metadata', 'account' === $module->id() && array( 'core', 'ledger', 'rules' ) === $module->dependencies() );

echo "\n================ RESULT: $PASS passed, $FAIL failed ================\n";
exit( $FAIL > 0 ? 1 : 0 );
