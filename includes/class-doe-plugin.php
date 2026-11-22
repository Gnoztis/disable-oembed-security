<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin parts together.
 */
final class DOE_Plugin {

	private static ?self $instance = null;

	private DOE_oEmbed           $oembed;
	private DOE_GitHub_Updater   $updater;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->oembed  = new DOE_oEmbed();
		$this->updater = new DOE_GitHub_Updater();
	}

	public function init(): void {
		// Must run before rest_api_init — init() is called from the plugin
		// file itself, so that holds.
		$this->oembed->register_hooks();
		$this->updater->register_hooks();
	}
}
