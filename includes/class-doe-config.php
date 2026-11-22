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

	/** Plugin slug — also the archive directory name on GitHub. */
	public const SLUG = 'disable-oembed-security';

	/** Plugin basename relative to wp-content/plugins. */
	public const BASENAME = 'disable-oembed-security/disable-oembed-security.php';

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
