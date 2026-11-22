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
 *  - every oEmbed lookup that would leave the site, before the HTTP request
 *    (pre_oembed_result): an empty provider list alone is not enough,
 *    because provider discovery is on by default for direct wp_oembed_get()
 *    calls and fetches the embedded URL;
 *  - provider discovery for unknown URLs (embed_oembed_discover);
 *  - the front-end /post-slug/embed/ template.
 *
 * Embeds of the site's own posts keep working: WordPress builds them from
 * the local post and never makes an HTTP request for them.
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

		// Discovery links and the iframe communication script. Core prints the
		// links at wp_head priority 4 since 6.9 and keeps a back-compat copy at
		// priority 10 that only fires while the priority-10 hook is registered;
		// removing both does not depend on that fallback staying in place.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );

		// No providers at all: wp_oembed_get() returns false and the content
		// keeps a plain link. This replaces poking at the WP_oEmbed object.
		add_filter( 'oembed_providers', array( $this, 'remove_providers' ), 999 );

		// Providers alone do not stop the lookup: WP_oEmbed::get_provider()
		// runs discovery (a remote GET to the embedded URL) when no provider
		// matches and `discover` is not set, which is the case for every
		// direct wp_oembed_get() call. Short-circuit before the HTTP request.
		add_filter( 'pre_oembed_result', array( $this, 'block_oembed_result' ), 999, 3 );

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
	 * Stops an oEmbed lookup before it can reach a third-party host.
	 *
	 * Runs after core's own pre_oembed_result callback, so a non-null result
	 * is either core's local markup for one of our own posts or an answer from
	 * another plugin; both are passed through untouched. What is left is a URL
	 * on another host, and for it the lookup would end in
	 * WP_oEmbed::discover() and a remote GET.
	 *
	 * Returning false makes wp_oembed_get() fail exactly like a lookup without
	 * a provider, so the content keeps a plain link.
	 *
	 * @param mixed        $result Result of the earlier callbacks; null while
	 *                             nothing has answered yet.
	 * @param string       $url    URL that is about to be embedded.
	 * @param array|string $args   oEmbed arguments.
	 * @return mixed
	 */
	public function block_oembed_result( $result, $url = '', $args = array() ) {
		if ( null !== $result ) {
			return $result;
		}

		/**
		 * Filters whether external oEmbed lookups are blocked.
		 *
		 * Returning false restores the stock WordPress behaviour, including
		 * provider discovery; only do that if the site needs embeds back.
		 *
		 * @param bool   $block Whether to block the lookup. Default true.
		 * @param string $url   URL that is about to be embedded.
		 */
		if ( ! apply_filters( 'doe_block_oembed_result', true, $url ) ) {
			return $result;
		}

		// Same-site embeds are rendered from the local post, without any HTTP
		// request, so they are not a vector and stay available.
		if ( function_exists( 'get_oembed_response_data_for_url' ) && get_oembed_response_data_for_url( $url, (array) $args ) ) {
			return $result;
		}

		return false;
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
			esc_html__( 'Встраивание на этом сайте отключено.', 'disable-oembed-security' ),
			'',
			array( 'response' => 404 )
		);
	}
}
