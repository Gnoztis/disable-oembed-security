<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin configuration shared by the feature classes.
 *
 * The update-related members that used to live here (repository, slug, the
 * transient names and TTLs, basename()/dirname()) are gone: the updater is
 * generated from tools/shared/updater/class-github-updater.php and owns those
 * values itself. Only the oEmbed route prefix is left, because both the
 * blocker and the endpoint map filter have to agree on it.
 */
final class DOE_Config {

	/** REST namespace of the oEmbed endpoints. */
	public const OEMBED_ROUTE_PREFIX = '/oembed/1.0/';
}
