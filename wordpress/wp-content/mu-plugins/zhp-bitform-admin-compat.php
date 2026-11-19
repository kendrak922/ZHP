<?php
/**
 * Plugin Name: ZHP BitForm Admin Compat
 * Description: Isolates Bit Form admin screens (including the email template editor) from Promise polyfill and TinyMCE conflicts that surface as Bluebird's "the promise constructor cannot be invoked directly" error.
 * Version: 1.0.0
 * Author: ZHP
 *
 * Root cause context:
 * - Bit Form's React admin ErrorBoundary renders "Something went wrong." plus the thrown error.
 * - The goo.gl/MqrFmX message is Bluebird-specific and appears when Bluebird replaces window.Promise
 *   and another library constructs/subclasses Promise (Reflect.construct / extends Promise).
 * - Email templates use Bit Form's TinyMCE wrapper, which prefers a CDN TinyMCE build when
 *   window.tinymce is not already present; WordPress TinyMCE + third-party mce plugins can collide.
 * - This must-use plugin does not patch Bit Form vendor assets; it scopes admin isolation to Bit Form pages only.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Whether the current request is a Bit Form admin screen.
 *
 * @param string $hook Current admin hook suffix when available.
 */
function zhp_is_bitform_admin($hook = '')
{
	if (isset($_GET['page']) && is_string($_GET['page']) && false !== strpos($_GET['page'], 'bitform')) {
		return true;
	}

	if (is_string($hook) && false !== strpos($hook, 'bitform')) {
		return true;
	}

	return false;
}

/**
 * Capture the environment's Promise as early as possible on Bit Form screens.
 */
function zhp_bitform_capture_native_promise()
{
	if (!zhp_is_bitform_admin()) {
		return;
	}

	echo "<script data-zhp-bitform-promise-capture=\"1\">(function () {\n"
		. "  try {\n"
		. "    if (!window.__ZHP_NATIVE_PROMISE__ && typeof window.Promise === 'function') {\n"
		. "      window.__ZHP_NATIVE_PROMISE__ = window.Promise;\n"
		. "    }\n"
		. "  } catch (e) {}\n"
		. "})();</script>\n";
}
add_action('admin_head', 'zhp_bitform_capture_native_promise', 0);

/**
 * Restore a non-subclassable Promise implementation before Bit Form's module boots.
 *
 * @param string $tag    Script HTML.
 * @param string $handle Script handle.
 * @return string
 */
function zhp_bitform_restore_promise_before_module($tag, $handle)
{
	if ('index-BITFORM-MODULE' !== $handle || !zhp_is_bitform_admin()) {
		return $tag;
	}

	$restore = "<script data-zhp-bitform-promise-restore=\"1\">(function () {\n"
		. "  try {\n"
		. "    var nativePromise = window.__ZHP_NATIVE_PROMISE__;\n"
		. "    if (!nativePromise) { return; }\n"
		. "    var current = window.Promise;\n"
		. "    var isBluebird = !!(current && typeof current.map === 'function' && (typeof current.promisify === 'function' || typeof current.using === 'function'));\n"
		. "    var brokenConstruct = false;\n"
		. "    try {\n"
		. "      if (typeof Reflect !== 'undefined' && Reflect.construct) {\n"
		. "        Reflect.construct(current, [function (resolve) { resolve(1); }], function SubPromise() {});\n"
		. "      }\n"
		. "    } catch (err) {\n"
		. "      brokenConstruct = !!(err && String(err.message || err).indexOf('cannot be invoked directly') !== -1);\n"
		. "    }\n"
		. "    if (isBluebird || brokenConstruct) {\n"
		. "      window.Promise = nativePromise;\n"
		. "    }\n"
		. "  } catch (e) {}\n"
		. "})();</script>\n";

	return $restore . $tag;
}
add_filter('script_loader_tag', 'zhp_bitform_restore_promise_before_module', 0, 2);

/**
 * Dequeue scripts that commonly collide with Bit Form's SPA + TinyMCE email editor.
 *
 * @param string $hook Current admin page hook.
 */
function zhp_bitform_dequeue_conflicts($hook)
{
	if (!zhp_is_bitform_admin($hook)) {
		return;
	}

	$handles = array(
		// Visual Portfolio loads admin JS on every wp-admin screen.
		'visual-portfolio-admin',
		'visual-portfolio-tinymce-localize',
		// Theme assets are normally block-editor only; dequeue defensively if present.
		'theme-vendor-scripts',
		'theme-custom-scripts',
		'theme-custom-block-scripts',
		// Bit Form 2.x still enqueues a missing legacy index.js with unregistered deps.
		'bitforms-admin-script',
		// Prefer Bit Form's CDN TinyMCE path for the email template editor instead of a mixed WP TinyMCE stack.
		'tinymce_js',
		'wp-tinymce',
	);

	foreach ($handles as $handle) {
		wp_dequeue_script($handle);
		wp_deregister_script($handle);
	}
}
add_action('admin_enqueue_scripts', 'zhp_bitform_dequeue_conflicts', 100);

/**
 * Neutralize third-party TinyMCE button/plugin filters on Bit Form screens.
 */
function zhp_bitform_neutralize_tinymce_filters()
{
	if (!zhp_is_bitform_admin()) {
		return;
	}

	add_filter(
		'mce_external_plugins',
		static function ($plugins) {
			if (isset($plugins['visual_portfolio'])) {
				unset($plugins['visual_portfolio']);
			}
			return $plugins;
		},
		100
	);

	add_filter(
		'mce_buttons',
		static function ($buttons) {
			return array_values(
				array_filter(
					(array) $buttons,
					static function ($button) {
						return 'visual_portfolio' !== $button;
					}
				)
			);
		},
		100
	);

	// Theme TinyMCE customizations are for content editing, not Bit Form's email editor.
	remove_filter('tiny_mce_before_init', 'bansheeStarter_my_custom_styles');
	remove_filter('mce_buttons_2', 'bansheeStarter_add_style_select_buttons');
}
add_action('admin_head', 'zhp_bitform_neutralize_tinymce_filters', 1);
