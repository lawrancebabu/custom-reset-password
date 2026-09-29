<?php
/**
 * Uninstall handler for Custom Reset Password Page.
 *
 * Removes the plugin settings. The reset page itself is site content and is
 * intentionally left in place.
 *
 * @package CustomResetPassword
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'crp_options' );
