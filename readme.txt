=== Custom Reset Password Page ===
Contributors: lawrancebabu
Tags: reset password, lost password, login, password strength, shortcode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces the wp-login.php reset screen with a styled front-end page, strength meter and secure password suggestions.

== Description ==

Custom Reset Password Page sends WordPress password reset links to a page on your site that uses your theme. The form includes secure password suggestions, a strength meter, a match indicator and show / hide toggles, and submits over AJAX.

Features:

* Redirects core reset links to your own page.
* [custom_reset_password_form] shortcode.
* Creates or reuses a /reset-password/ page on activation.
* Three strong suggested passwords generated with the Web Crypto API.
* Strength meter, match indicator and show / hide toggles.
* Configurable redirect after success, "Back to login" link, button color and minimum length.
* Falls back to the default WordPress screen when no reset page is published.
* Fires core's validate_password_reset action so password policy plugins still apply.
* Translation ready.

== Installation ==

1. In WordPress admin, go to Plugins > Add New > Upload Plugin.
2. Upload the plugin zip file and activate it.
3. A "Reset Password" page is created or reused automatically.
4. Optional: adjust settings under Settings > Reset Password Page.

== Frequently Asked Questions ==

= Can I use my own page? =

Yes. Add the [custom_reset_password_form] shortcode to any page and select it under Settings > Reset Password Page.

= What if I delete the reset page? =

Reset links fall back to the default WordPress screen.

= What happens on uninstall? =

The plugin settings are deleted. The reset page is left in place.

== Screenshots ==

1. Reset form with suggested passwords.
2. Expired or invalid link message.

== Changelog ==

= 1.3.0 =
* Fixed: passwords containing quotes or backslashes were saved with added slashes, so users could not log in with the password they typed.
* Fixed: "Use" buttons on suggested passwords always inserted the last suggestion.
* Fixed: logged-in users could not submit the form (AJAX handler was guests only).
* Security: suggested passwords now use the Web Crypto API instead of Math.random().
* Security: reset page sends no-cache and no-referrer headers; post-reset redirect is validated.
* Fires core's validate_password_reset action before resetting.
* Falls back to the default WordPress screen if the reset page is missing, instead of redirecting to a 404.
* Added settings page (reset page, redirect URL, login link, button color, minimum length).
* Activation creates or reuses the reset page.
* Moved inline CSS and JS into enqueued assets; added styles for the invalid link message.
* Added translations support, accessibility labels, uninstall cleanup and WordPress Coding Standards compliance.
* Removed unused server-side password generator.

= 1.2.1 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Fixes passwords with quotes or backslashes not working after a reset. The plugin folder changed, so reactivate it after updating.
