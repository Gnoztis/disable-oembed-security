<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism, sourced from GitHub
 * releases of Gnoztis/disable-oembed-security.
 *
 * The source of truth is the highest version found in the latest GitHub
 * release and in the tag list, whichever is newer: a tag can be published
 * without a matching release, and then the release alone would never offer an
 * update. WordPress sees the new version, shows it under Plugins and updates
 * from the package of the winning source. No third-party libraries and no
 * write requests to the repository.
 */
final class DOE_GitHub_Updater {

	private static ?array $header = null;

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ) );
		add_filter( 'plugin_action_links_' . DOE_Config::basename(), array( $this, 'action_links' ) );
	}

	/* ============================================================
	 *  Update plumbing
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a newer
	 * version than the installed one.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		// Entries written by an older version of this code under a key that no
		// longer matches the installed file: WordPress counts them in the
		// badge but get_plugin_updates() never lists them.
		$this->drop_stale_entries( $transient );

		$release = $this->get_release();
		if ( $release === null || version_compare( $release['version'], DOE_VERSION, '<=' ) ) {
			return $transient;
		}

		$basename = DOE_Config::basename();

		$transient->response[ $basename ] = (object) array(
			'id'            => $basename,
			'slug'          => DOE_Config::dirname(),
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . DOE_Config::REPO,
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Removes update entries that point at this plugin's main file while
	 * sitting under a key other than the installed basename.
	 *
	 * WordPress builds the "update available" badge from
	 * count( $transient->response ), but get_plugin_updates() only looks up
	 * keys that match a file returned by get_plugins(). A stale key therefore
	 * shows a badge together with an empty update list.
	 *
	 * @param object $transient
	 */
	private function drop_stale_entries( $transient ): void {
		$basename = DOE_Config::basename();
		$main     = basename( $basename );

		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( $key === $basename ) {
					continue;
				}

				$file = ( is_object( $entry ) && ! empty( $entry->plugin ) ) ? (string) $entry->plugin : (string) $key;
				if ( basename( $file ) === $main ) {
					unset( $transient->$bucket[ $key ] );
				}
			}
		}
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( DOE_Config::dirname() !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( $release === null ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) array(
			'name'          => 'Disable oEmbed Endpoint (Security)',
			'slug'          => DOE_Config::dirname(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
			'homepage'      => 'https://github.com/' . DOE_Config::REPO,
			'requires'      => $header['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => esc_html__( 'Отключает REST-эндпоинт oEmbed и связанные функции: маршруты /oembed/1.0/embed и /oembed/1.0/proxy, discovery-ссылки, wp-embed.js, провайдеров oEmbed и шаблон /embed/.', 'disable-oembed-security' ),
				'changelog'   => $release['body'] !== '' ? wpautop( $release['body'] ) : esc_html__( 'Описание изменений в релизе не заполнено.', 'disable-oembed-security' ),
			),
		);
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-disable-oembed-security-1a2b3c4 — rename it to the directory the
	 * plugin is actually installed in, otherwise WordPress unpacks the update
	 * next to the original and the site ends up with two copies.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || DOE_Config::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$dir = DOE_Config::dirname();
		if ( '' === $dir ) {
			return $source;
		}

		if ( ! is_dir( $source ) || ! is_file( trailingslashit( $source ) . basename( DOE_Config::basename() ) ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $dir;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Reset the cached check after an upgrade so the site does not keep
	 * advertising the version it has just installed.
	 *
	 * @param mixed $upgrader
	 * @param array $hook_extra
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = array() ): void {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( ! in_array( DOE_Config::basename(), $hook_extra['plugins'], true ) ) {
				return;
			}
		}
		$this->flush();
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'doe-force-check', '1', admin_url( 'plugins.php' ) ),
			'doe_force_check'
		);

		$links              = (array) $links;
		$links['doe_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'disable-oembed-security' ) . '</a>';

		return $links;
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['doe-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'doe_force_check' );

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	private function flush(): void {
		delete_transient( DOE_Config::UPDATE_TRANSIENT );
		delete_transient( DOE_Config::UPDATE_ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data: the highest version across the release and the tag
	 * list. The whole answer is cached for six hours, a failed check is
	 * retried after fifteen minutes.
	 */
	private function get_release(): ?array {
		$cached = get_transient( DOE_Config::UPDATE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		if ( get_transient( DOE_Config::UPDATE_ERROR_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_latest();

		if ( $release === null ) {
			set_transient( DOE_Config::UPDATE_ERROR_TRANSIENT, 1, DOE_Config::UPDATE_ERROR_TTL );
			return null;
		}

		set_transient( DOE_Config::UPDATE_TRANSIENT, $release, DOE_Config::UPDATE_CACHE_TTL );

		return $release;
	}

	/**
	 * Picks the higher of the two sources.
	 *
	 * Both requests are always made: a repository can keep an older release
	 * while a newer tag already exists, and "release first, otherwise tag"
	 * would never offer that tag. On equal versions the release wins, because
	 * it carries the release notes.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( $release === null ) {
			return $tag;
		}
		if ( $tag === null ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	/**
	 * Candidate from the latest GitHub release, with the package of that
	 * release.
	 */
	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', DOE_Config::REPO ) );
		if ( $body === null ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( $version === '' ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( $package === '' ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Candidate from the tag list: the highest semver tag and its zipball.
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get( sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', DOE_Config::REPO ) );
		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( $version === '' ) {
				continue;
			}
			if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( $best_version === '' || $best_tag === '' ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf( 'https://api.github.com/repos/%s/zipball/%s', DOE_Config::REPO, rawurlencode( $best_tag ) )
		);

		if ( $package === '' ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return array(
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		);
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen compares
	 * the stored value with the full running version ("7.1.5"):
	 * `version_compare( $update->tested, $cur_wp_version, '>=' )`. A branch
	 * claim is always lower than a patch release, which is why a bare "7.1"
	 * still renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the running
	 * patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		$args = array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array(
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => DOE_Config::SLUG . '/' . DOE_VERSION,
				'Content-Type' => 'application/json',
			),
		);

		$token = $this->token();
		if ( $token !== '' ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user input;
		// scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from a constant.
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only: the package URL must not point at an
	 * arbitrary host.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	private function token(): string {
		$token = defined( 'DOE_GITHUB_TOKEN' ) ? (string) DOE_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'doe_github_token', $token );

		return trim( $token );
	}

	/**
	 * Tested up to / Requires at least / Requires PHP from the plugin header.
	 *
	 * `tested` and `requires` are different headers and must not be mixed:
	 * WordPress compares `tested` with the installed version to decide whether
	 * the update row says "compatible" or "untested".
	 *
	 * @return array{tested:string,requires:string,requires_php:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					DOE_PLUGIN_FILE,
					array(
						'Tested up to'      => 'Tested up to',
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
					),
					'plugin'
				)
				: array();

			self::$header = array(
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
			);
		}

		return self::$header;
	}
}
