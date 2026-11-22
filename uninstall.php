<?php
/**
 * Removes plugin data on uninstall.
 *
 * The plugin stores nothing but the update-check transients; on multisite
 * each site keeps its own copy.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'doe_uninstall_site' ) ) {
	/**
	 * Deletes the cached update check for the current site.
	 */
	function doe_uninstall_site(): void {
		delete_transient( 'doe_github_release' );
		delete_transient( 'doe_github_release_error' );
	}
}

if ( is_multisite() ) {
	$doe_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $doe_sites as $doe_site_id ) {
		switch_to_blog( (int) $doe_site_id );
		doe_uninstall_site();
		restore_current_blog();
	}
} else {
	doe_uninstall_site();
}
