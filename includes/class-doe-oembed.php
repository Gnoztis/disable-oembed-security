<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns off the oEmbed surface of WordPress.
 *
 * What is removed:
 *  - the REST routes /oembed/1.0/embed and /oembed/1.0/proxy (the latter is
 *    the SSRF vector: it fetches a remote URL on behalf of the site);
 *  - the discovery <link> tags and wp-embed.js in <head>;
 *  - every registered oEmbed provider, so wp_oembed_get() falls back to a
 *    plain link instead of an iframe;
 *  - provider discovery for unknown URLs (embed_oembed_discover);
 *  - the front-end /post-slug/embed/ template.
 */
final class DOE_oEmbed {

	/**
	 * Hooks the blockers.
	 *
	 * Called while the plugin file loads, i.e. before rest_api_init fires —
	 * which is exactly what removing wp_oembed_register_route requires.
	 */
	public function register_hooks(): void {
		// Core registers the route on rest_api_init (priority 10). Removing
		// the action from a rest_api_init callback, as the previous version
		// did at priority 99, is too late: the route already exists there.
		if ( function_exists( 'wp_oembed_register_route' ) ) {
			remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		}

		// Belt and braces: strip the routes from the endpoint map as well, in
		// case another plugin registered them again.
		add_filter( 'rest_endpoints', array( $this, 'remove_oembed_endpoints' ) );

		// Discovery links and the iframe communication script.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );

		// No providers at all: wp_oembed_get() returns false and the content
		// keeps a plain link. This replaces poking at the WP_oEmbed object.
		add_filter( 'oembed_providers', array( $this, 'remove_providers' ), 999 );

		// Never discover providers for unknown URLs.
		add_filter( 'embed_oembed_discover', '__return_false' );

		// /post-slug/embed/ is not served any more.
		add_action( 'template_redirect', array( $this, 'block_embed_template' ), 1 );
	}

	/**
	 * Removes /oembed/1.0/* from the REST endpoint map.
	 *
	 * @param array $endpoints
	 * @return array
	 */
	public function remove_oembed_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( (string) $route, DOE_Config::OEMBED_ROUTE_PREFIX ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Empties the provider list.
	 *
	 * @param array $providers
	 * @return array
	 */
	public function remove_providers( $providers ) {
		return array();
	}

	/**
	 * Answers 404 for embed requests instead of rendering the embed template.
	 */
	public function block_embed_template(): void {
		if ( ! is_embed() ) {
			return;
		}

		if ( ! apply_filters( 'doe_block_embed_template', true ) ) {
			return;
		}

		wp_die(
			esc_html__( 'Embedding is disabled on this site.', 'disable-oembed-security' ),
			'',
			array( 'response' => 404 )
		);
	}
}
