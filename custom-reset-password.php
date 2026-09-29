<?php
/**
 * Plugin Name:       Custom Reset Password Page
 * Plugin URI:        https://github.com/lawrancebabu/custom-reset-password
 * Description:       Sends WordPress password reset links to a styled front-end page with a strength meter and secure password suggestions, then redirects after success.
 * Version:           1.3.0
 * Author:            Lawrance Babu Gain
 * Author URI:        https://github.com/lawrancebabu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       custom-reset-password
 * Domain Path:       /languages
 *
 * @package CustomResetPassword
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * Redirects core reset links to a front-end page, renders the reset form via
 * a shortcode and resets the password over AJAX using core APIs.
 */
final class Custom_Reset_Password {

	/**
	 * Plugin version, used for asset cache busting.
	 */
	public const VERSION = '1.3.0';

	/**
	 * Option that stores the plugin settings.
	 */
	public const OPTION_NAME = 'crp_options';

	/**
	 * Settings API group and settings page slug.
	 */
	private const SETTINGS_GROUP = 'crp_settings';

	/**
	 * Settings page slug.
	 */
	private const PAGE_SLUG = 'custom-reset-password';

	/**
	 * Path of the reset page used before 1.3.0 and created on activation.
	 */
	private const DEFAULT_PAGE_PATH = 'reset-password';

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'custom_reset_password_form';

	/**
	 * Nonce action (kept from 1.2.x for compatibility).
	 */
	private const NONCE_ACTION = 'crp_nonce';

	/**
	 * AJAX action name.
	 */
	private const AJAX_ACTION = 'crp_reset_password';

	/**
	 * Registers all hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'login_form_rp', array( $this, 'redirect_to_custom_page' ) );
		add_action( 'login_form_resetpass', array( $this, 'redirect_to_custom_page' ) );
		add_action( 'template_redirect', array( $this, 'intercept_front_end_reset' ) );
		add_action( 'template_redirect', array( $this, 'protect_reset_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_reset_password' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_reset_password' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Activation callback: makes sure a reset page exists and is selected.
	 *
	 * Reuses an existing /reset-password/ page (the 1.2.x convention) or
	 * creates one containing the shortcode.
	 */
	public static function activate(): void {
		$options = self::get_options();

		if ( $options['page_id'] && 'publish' === get_post_status( $options['page_id'] ) ) {
			return;
		}

		$page    = get_page_by_path( self::DEFAULT_PAGE_PATH );
		$page_id = $page ? (int) $page->ID : 0;

		if ( ! $page_id ) {
			$page_id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Reset Password', 'custom-reset-password' ),
					'post_name'    => self::DEFAULT_PAGE_PATH,
					'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				)
			);
		}

		$options['page_id'] = $page_id;
		update_option( self::OPTION_NAME, $options );
	}

	/**
	 * Loads translations from the plugin's /languages folder.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'custom-reset-password', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Handles wp-login.php?action=rp|resetpass by redirecting to the custom page.
	 *
	 * Falls back to the core screen when no published reset page is set.
	 */
	public function redirect_to_custom_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; core handles the POST.
		if ( isset( $_POST['pass1'] ) ) {
			return;
		}

		$this->do_redirect();
	}

	/**
	 * Redirects front-end requests that carry core reset parameters.
	 */
	public function intercept_front_end_reset(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( empty( $_GET['key'] ) || empty( $_GET['login'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page_id = self::get_reset_page_id();
		if ( ! $page_id || is_page( $page_id ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( false !== strpos( $request_uri, 'wp-login.php' )
			|| false !== strpos( $request_uri, 'action=rp' )
			|| false !== strpos( $request_uri, 'action=resetpass' ) ) {
			$this->do_redirect();
		}
	}

	/**
	 * Adds no-cache and no-referrer protection on the reset page.
	 *
	 * The reset key is in the URL, so it must not leak to third parties via
	 * the Referer header or be stored by page caches.
	 */
	public function protect_reset_page(): void {
		$page_id = self::get_reset_page_id();

		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}

		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Stores the reset credentials in core's cookie and redirects to the page.
	 */
	private function do_redirect(): void {
		$page_id = self::get_reset_page_id();

		if ( ! $page_id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The reset key itself is the credential.
		$rp_login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
		$rp_key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $rp_login && $rp_key ) {
			setcookie( self::get_rp_cookie_name(), $rp_login . ':' . $rp_key, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'login' => rawurlencode( $rp_login ),
					'key'   => rawurlencode( $rp_key ),
				),
				get_permalink( $page_id )
			)
		);
		exit;
	}

	/**
	 * Registers front-end assets and enqueues them on the reset page.
	 */
	public function register_assets(): void {
		wp_register_style( 'custom-reset-password', plugin_dir_url( __FILE__ ) . 'assets/crp.css', array(), self::VERSION );
		wp_add_inline_style( 'custom-reset-password', '.rp-wrap{--crp-accent:' . esc_html( self::get_options()['accent_color'] ) . ';}' );

		wp_register_script( 'custom-reset-password', plugin_dir_url( __FILE__ ) . 'assets/crp.js', array(), self::VERSION, true );
		wp_localize_script(
			'custom-reset-password',
			'CustomResetPassword',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'minLength' => self::get_options()['min_length'],
				'i18n'      => array(
					'weak'         => __( 'Weak', 'custom-reset-password' ),
					'fair'         => __( 'Fair', 'custom-reset-password' ),
					'good'         => __( 'Good', 'custom-reset-password' ),
					'strong'       => __( 'Strong', 'custom-reset-password' ),
					'strength'     => __( 'Password strength:', 'custom-reset-password' ),
					'match'        => __( 'Passwords match', 'custom-reset-password' ),
					'noMatch'      => __( 'Passwords do not match', 'custom-reset-password' ),
					'use'          => __( 'Use', 'custom-reset-password' ),
					'genericError' => __( 'An error occurred. Please try again.', 'custom-reset-password' ),
				),
			)
		);

		$page_id = self::get_reset_page_id();
		$post    = get_post();

		if ( ( $page_id && is_page( $page_id ) ) || ( is_singular() && $post && has_shortcode( $post->post_content, self::SHORTCODE ) ) ) {
			wp_enqueue_style( 'custom-reset-password' );
			wp_enqueue_script( 'custom-reset-password' );
		}
	}

	/**
	 * Renders the [custom_reset_password_form] shortcode.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		// Fallback for shortcodes placed outside the configured page.
		wp_enqueue_style( 'custom-reset-password' );
		wp_enqueue_script( 'custom-reset-password' );

		list( $rp_login, $rp_key ) = $this->get_reset_credentials();

		$user = null;
		if ( $rp_login && $rp_key ) {
			$user = check_password_reset_key( $rp_key, $rp_login );
		}

		if ( ! $user || is_wp_error( $user ) ) {
			return '<div class="rp-wrap"><div class="rp-error-box"><p>&#9888; '
				. esc_html__( 'This password reset link is invalid or has expired.', 'custom-reset-password' )
				. '</p><a href="' . esc_url( wp_lostpassword_url() ) . '" class="rp-link-btn">'
				. esc_html__( 'Request a new link', 'custom-reset-password' )
				. '</a></div></div>';
		}

		$options   = self::get_options();
		$eye_icon  = '<svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
		$eye_off   = '<svg class="eye-off-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
		$svg_allow = self::get_svg_allowed_html();

		ob_start();
		?>
		<div class="rp-wrap">
			<div id="rp-message" class="rp-message" style="display:none;" role="status" aria-live="polite"></div>

			<div class="rp-header">
				<div class="rp-icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
				</div>
				<h2><?php esc_html_e( 'Set new password', 'custom-reset-password' ); ?></h2>
				<p><?php esc_html_e( 'Choose a strong password for your account.', 'custom-reset-password' ); ?></p>
			</div>

			<form class="rp-form" id="rp-form" novalidate>

				<div class="rp-suggestions-box">
					<p class="rp-suggestions-label"><?php esc_html_e( 'Need help? Use a suggested password:', 'custom-reset-password' ); ?></p>
					<div class="rp-suggestions" id="rp-suggestions"></div>
					<button type="button" class="rp-refresh-btn" id="rp-refresh-btn">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2-8.83"></path></svg>
						<?php esc_html_e( 'Refresh', 'custom-reset-password' ); ?>
					</button>
				</div>

				<div class="rp-field-group">
					<label for="pass1"><?php esc_html_e( 'New password', 'custom-reset-password' ); ?></label>
					<div class="rp-field">
						<input type="password" name="pass1" id="pass1" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Enter new password', 'custom-reset-password' ); ?>" minlength="<?php echo esc_attr( $options['min_length'] ); ?>" required>
						<button type="button" class="rp-eye" id="eye-btn-1" aria-pressed="false">
							<span class="rp-sr-only"><?php esc_html_e( 'Show password', 'custom-reset-password' ); ?></span>
							<?php echo wp_kses( $eye_icon . $eye_off, $svg_allow ); ?>
						</button>
					</div>
					<div class="rp-strength-bars" id="rp-bars" aria-hidden="true">
						<span></span><span></span><span></span><span></span>
					</div>
					<p class="rp-strength-text" id="rp-strength-text" aria-live="polite"></p>
				</div>

				<div class="rp-field-group">
					<label for="pass2"><?php esc_html_e( 'Confirm password', 'custom-reset-password' ); ?></label>
					<div class="rp-field">
						<input type="password" name="pass2" id="pass2" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Repeat new password', 'custom-reset-password' ); ?>" required>
						<button type="button" class="rp-eye" id="eye-btn-2" aria-pressed="false">
							<span class="rp-sr-only"><?php esc_html_e( 'Show password', 'custom-reset-password' ); ?></span>
							<?php echo wp_kses( $eye_icon . $eye_off, $svg_allow ); ?>
						</button>
					</div>
					<p class="rp-match-text" id="rp-match-text" aria-live="polite"></p>
				</div>

				<input type="hidden" name="rp_login" id="rp_login" value="<?php echo esc_attr( $rp_login ); ?>">
				<input type="hidden" name="rp_key" id="rp_key" value="<?php echo esc_attr( $rp_key ); ?>">
				<input type="hidden" name="nonce" id="crp_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">

				<button type="submit" class="rp-submit-btn" id="rp-submit-btn">
					<span><?php esc_html_e( 'Save password', 'custom-reset-password' ); ?></span>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</button>
			</form>

			<p class="rp-back">
				<?php esc_html_e( 'Remember it?', 'custom-reset-password' ); ?>
				<a href="<?php echo esc_url( self::get_login_url() ); ?>"><?php esc_html_e( 'Back to login', 'custom-reset-password' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: validates the reset key and sets the new password.
	 */
	public function ajax_reset_password(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page and try again.', 'custom-reset-password' ) ) );
		}

		$rp_login = isset( $_POST['rp_login'] ) ? sanitize_user( wp_unslash( $_POST['rp_login'] ) ) : '';
		$rp_key   = isset( $_POST['rp_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rp_key'] ) ) : '';
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.
		$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
		$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$min_length = self::get_options()['min_length'];

		if ( '' === $pass1 || '' === $pass2 ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your new password in both fields.', 'custom-reset-password' ) ) );
		}

		if ( $pass1 !== $pass2 ) {
			wp_send_json_error( array( 'message' => __( 'Passwords do not match.', 'custom-reset-password' ) ) );
		}

		if ( strlen( $pass1 ) < $min_length ) {
			/* translators: %d: minimum password length. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Password must be at least %d characters long.', 'custom-reset-password' ), $min_length ) ) );
		}

		if ( ! $rp_login || ! $rp_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid password reset link.', 'custom-reset-password' ) ) );
		}

		$user = check_password_reset_key( $rp_key, $rp_login );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'This password reset link is invalid or has expired.', 'custom-reset-password' ) ) );
		}

		// Let password policy plugins validate, as wp-login.php does.
		$errors = new WP_Error();
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		if ( $errors->has_errors() ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $errors->get_error_message() ) ) );
		}

		reset_password( $user, $pass1 );

		setcookie( self::get_rp_cookie_name(), ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		wp_send_json_success(
			array(
				'message'  => __( 'Password reset successfully!', 'custom-reset-password' ),
				'redirect' => self::get_success_redirect_url(),
			)
		);
	}

	/**
	 * Adds the Settings > Reset Password Page screen.
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Reset Password Page', 'custom-reset-password' ),
			__( 'Reset Password Page', 'custom-reset-password' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the settings option with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => self::get_default_options(),
			)
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = self::get_options();
		$name    = self::OPTION_NAME;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reset Password Page', 'custom-reset-password' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="crp-page-id"><?php esc_html_e( 'Reset page', 'custom-reset-password' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'id'                => 'crp-page-id',
									'name'              => esc_attr( $name ) . '[page_id]',
									'selected'          => (int) $options['page_id'],
									'show_option_none'  => esc_html__( 'None (use the default WordPress screen)', 'custom-reset-password' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: shortcode tag. */
									esc_html__( 'The page must contain the %s shortcode.', 'custom-reset-password' ),
									'<code>[' . esc_html( self::SHORTCODE ) . ']</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crp-redirect-url"><?php esc_html_e( 'Redirect after success', 'custom-reset-password' ); ?></label></th>
						<td>
							<input id="crp-redirect-url" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[redirect_url]" value="<?php echo esc_url( $options['redirect_url'] ); ?>" placeholder="<?php echo esc_url( home_url( '/' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use the homepage. Must be on this site.', 'custom-reset-password' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crp-login-url"><?php esc_html_e( '"Back to login" link', 'custom-reset-password' ); ?></label></th>
						<td>
							<input id="crp-login-url" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[login_url]" value="<?php echo esc_url( $options['login_url'] ); ?>" placeholder="<?php echo esc_url( home_url( '/' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use the homepage.', 'custom-reset-password' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crp-accent-color"><?php esc_html_e( 'Button color', 'custom-reset-password' ); ?></label></th>
						<td>
							<input id="crp-accent-color" type="text" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $options['accent_color'] ); ?>" pattern="^#[0-9a-fA-F]{6}$">
							<p class="description"><?php esc_html_e( 'Default: #053776', 'custom-reset-password' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crp-min-length"><?php esc_html_e( 'Minimum password length', 'custom-reset-password' ); ?></label></th>
						<td>
							<input id="crp-min-length" class="small-text" type="number" min="6" max="64" name="<?php echo esc_attr( $name ); ?>[min_length]" value="<?php echo esc_attr( $options['min_length'] ); ?>">
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $options Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public function sanitize_options( $options ): array {
		$options  = is_array( $options ) ? $options : array();
		$defaults = self::get_default_options();
		$color    = sanitize_hex_color( (string) ( $options['accent_color'] ?? '' ) );
		$page_id  = absint( $options['page_id'] ?? 0 );

		return array(
			'page_id'      => ( $page_id && 'page' === get_post_type( $page_id ) ) ? $page_id : 0,
			'redirect_url' => esc_url_raw( (string) ( $options['redirect_url'] ?? '' ) ),
			'login_url'    => esc_url_raw( (string) ( $options['login_url'] ?? '' ) ),
			'accent_color' => $color ? $color : $defaults['accent_color'],
			'min_length'   => min( 64, max( 6, absint( $options['min_length'] ?? $defaults['min_length'] ) ) ),
		);
	}

	/**
	 * Reset credentials from core's reset cookie, falling back to the query string.
	 *
	 * @return array{0: string, 1: string} Login and key.
	 */
	private function get_reset_credentials(): array {
		$rp_login = '';
		$rp_key   = '';
		$cookie   = self::get_rp_cookie_name();

		if ( isset( $_COOKIE[ $cookie ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
			if ( false !== strpos( $value, ':' ) ) {
				list( $rp_login, $rp_key ) = explode( ':', $value, 2 );
				$rp_login                  = sanitize_user( $rp_login );
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The reset key itself is the credential.
		if ( '' === $rp_login && isset( $_GET['login'] ) ) {
			$rp_login = sanitize_user( wp_unslash( $_GET['login'] ) );
		}
		if ( '' === $rp_key && isset( $_GET['key'] ) ) {
			$rp_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array( $rp_login, $rp_key );
	}

	/**
	 * ID of the published reset page, or 0 when none is available.
	 *
	 * @return int
	 */
	private static function get_reset_page_id(): int {
		$page_id = (int) self::get_options()['page_id'];

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}

		return 0;
	}

	/**
	 * Where to send the user after a successful reset.
	 *
	 * @return string
	 */
	private static function get_success_redirect_url(): string {
		$url = self::get_options()['redirect_url'];

		return wp_validate_redirect( $url ? $url : home_url( '/' ), home_url( '/' ) );
	}

	/**
	 * Target of the "Back to login" link.
	 *
	 * @return string
	 */
	private static function get_login_url(): string {
		$url = self::get_options()['login_url'];

		return $url ? $url : home_url( '/' );
	}

	/**
	 * Name of WordPress core's password reset cookie.
	 *
	 * @return string
	 */
	private static function get_rp_cookie_name(): string {
		return 'wp-resetpass-' . COOKIEHASH;
	}

	/**
	 * Saved settings merged with defaults.
	 *
	 * @return array
	 */
	private static function get_options(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_default_options() );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	private static function get_default_options(): array {
		return array(
			'page_id'      => 0,
			'redirect_url' => '',
			'login_url'    => '',
			'accent_color' => '#053776',
			'min_length'   => 8,
		);
	}

	/**
	 * Allowed markup for the inline SVG icons.
	 *
	 * @return array
	 */
	private static function get_svg_allowed_html(): array {
		$shape = array(
			'd'      => true,
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'x1'     => true,
			'y1'     => true,
			'x2'     => true,
			'y2'     => true,
			'points' => true,
		);

		return array(
			'svg'    => array(
				'class'           => true,
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'style'           => true,
				'aria-hidden'     => true,
			),
			'path'   => $shape,
			'circle' => $shape,
			'line'   => $shape,
		);
	}
}

register_activation_hook( __FILE__, array( 'Custom_Reset_Password', 'activate' ) );
new Custom_Reset_Password();
