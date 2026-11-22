<?php
/**
 * Plugin Name: Disable oEmbed Endpoint (Security)
 * Description: Отключает REST API эндпоинт oEmbed и связанные функции для защиты от атак, использующих oEmbed в качестве прокси. Совместим с PHP 8.4+ и WordPress 7.0+.
 * Version:     1.3.0
 * Author:      Gnostis
 * License:     GPL-2.0-or-later
 * Text Domain: disable-oembed-security
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * Update URI:  https://github.com/Gnoztis/disable-oembed-security
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOE_VERSION', '1.3.0' );
define( 'DOE_PLUGIN_FILE', __FILE__ );
define( 'DOE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once DOE_PLUGIN_DIR . 'includes/class-doe-config.php';
require_once DOE_PLUGIN_DIR . 'includes/class-doe-oembed.php';
require_once DOE_PLUGIN_DIR . 'includes/class-doe-github-updater.php';
require_once DOE_PLUGIN_DIR . 'includes/class-doe-plugin.php';

DOE_Plugin::instance()->init();
