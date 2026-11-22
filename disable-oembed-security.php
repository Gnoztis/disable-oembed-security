<?php
/**
 * Plugin Name: Disable oEmbed Endpoint (Security)
 * Description: Отключает REST API эндпоинт oEmbed и связанные функции для защиты от атак, использующих oEmbed в качестве прокси. Совместим с PHP 8.4+ и WordPress 7.0+.
 * Version: 1.1
 * Requires PHP: 8.4
 * Requires at least: 7.0
 * Author: Ваше Имя
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Disable_oEmbed_Endpoint {

    public function __construct() {
        // 1. Удаляем REST API маршрут oEmbed (основная цель)
        add_action( 'rest_api_init', array( $this, 'remove_oembed_route' ), 99 );

        // 2. Удаляем ссылки oEmbed из <head>
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

        // 3. Удаляем правила перезаписи для embed-запросов
        add_filter( 'rewrite_rules_array', array( $this, 'remove_embed_rewrite_rules' ) );

        // 4. Отключаем автоматическое обнаружение oEmbed для неизвестных провайдеров
        add_filter( 'embed_oembed_discover', '__return_false' );

        // 5. Удаляем JavaScript для iframe-коммуникации (wp-embed.js)
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );

        // 6. Удаляем всех зарегистрированных провайдеров oEmbed
        add_action( 'wp_loaded', array( $this, 'remove_known_providers' ) );

        // 7. Отключаем прокси-эндпоинт oEmbed (для защиты от SSRF)
        add_action( 'rest_api_init', array( $this, 'remove_oembed_proxy_route' ), 99 );
    }

    /**
     * Удаляет основной REST API маршрут oEmbed.
     */
    public function remove_oembed_route() {
        if ( function_exists( 'wp_oembed_register_route' ) ) {
            remove_action( 'rest_api_init', 'wp_oembed_register_route' );
        }
    }

    /**
     * Удаляет прокси-маршрут oEmbed (защита от SSRF).
     */
    public function remove_oembed_proxy_route() {
        // В WordPress 7.0+ прокси-маршрут может быть зарегистрирован отдельно.
        if ( function_exists( 'wp_oembed_register_proxy_route' ) ) {
            remove_action( 'rest_api_init', 'wp_oembed_register_proxy_route' );
        }
    }

    /**
     * Удаляет правила перезаписи, связанные с embed=true.
     */
    public function remove_embed_rewrite_rules( $rules ) {
        foreach ( $rules as $rule => $rewrite ) {
            if ( false !== strpos( $rewrite, 'embed=true' ) ) {
                unset( $rules[ $rule ] );
            }
        }
        return $rules;
    }

    /**
     * Удаляет всех известных oEmbed-провайдеров.
     */
    public function remove_known_providers() {
        if ( ! class_exists( '_WP_Editors' ) ) {
            return;
        }
        
        $oembed = _wp_oembed_get_object();
        if ( ! $oembed || empty( $oembed->providers ) ) {
            return;
        }

        foreach ( $oembed->providers as $format => $data ) {
            wp_oembed_remove_provider( $format );
        }
    }
}

new Disable_oEmbed_Endpoint();