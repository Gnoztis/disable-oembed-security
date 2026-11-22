<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin configuration shared by the feature classes.
 */
final class DOE_Config {

	/** GitHub repository the updates come from. */
	public const REPO = 'Gnoztis/disable-oembed-security';

	/**
	 * Plugin slug in the repository — also the archive directory name on
	 * GitHub. Never use it as an update transient key: the directory of the
	 * installed plugin can have another name, see basename().
	 */
	public const SLUG = 'disable-oembed-security';

	/**
	 * Plugin basename relative to wp-content/plugins, computed at runtime.
	 *
	 * The directory can have any name on a given site (a zipball unpacked by
	 * hand, a renamed folder), and the update transient key has to match the
	 * installed plugin file exactly. With a hard-coded key the entry dangles:
	 * WordPress counts it in the update badge but cannot list the plugin on
	 * the updates screen.
	 */
	public static function basename(): string {
		return plugin_basename( DOE_PLUGIN_FILE );
	}

	/**
	 * Directory of the installed plugin — the slug WordPress knows it by.
	 *
	 * Empty when the main file sits directly in wp-content/plugins.
	 */
	public static function dirname(): string {
		$dir = dirname( self::basename() );

		return ( '.' === $dir || '' === $dir ) ? '' : $dir;
	}

	/** Transient holding the result of the last successful update check. */
	public const UPDATE_TRANSIENT = 'doe_github_release';

	/** Transient holding the failure marker of the last update check. */
	public const UPDATE_ERROR_TRANSIENT = 'doe_github_release_error';

	/** Successful update check is cached for six hours. */
	public const UPDATE_CACHE_TTL = 21600;

	/** A failed update check is retried after fifteen minutes. */
	public const UPDATE_ERROR_TTL = 900;

	/** REST namespace of the oEmbed endpoints. */
	public const OEMBED_ROUTE_PREFIX = '/oembed/1.0/';
}
