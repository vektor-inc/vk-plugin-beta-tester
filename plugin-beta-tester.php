<?php
/*
* Plugin Name: VK Plugin Beta Tester
* Plugin URI: https://github.com/vektor-inc/vk-plugin-beta-tester
* Description: Lets you easily beta test plugins by notifying you of beta versions. Also lets you upgrade to beta versions from within WordPress. This plugin
* Version: 0.1.0
* Author: Vektor,Inc.
* Text Domain: vk-plugin-beta-tester
* Author URI: https://vektor-inc.co.jp
*/

define( 'PLUGIN_FILE', 'vk-plugin-beta-tester/plugin-beta-tester.php' );
define( 'PLUGIN_BETA_TESTER_VERSION', '0.5' );
if ( ! defined( 'PLUGIN_BETA_TESTER_EXPIRATION' ) ) {
	define( 'PLUGIN_BETA_TESTER_EXPIRATION', 60 * 60 * 24 );
}

class Plugin_Beta_Tester {
	private $api_cache = array();

	function __construct() {

		// add "stable" + "beta" markers to the version numbers on the plugins page
		add_filter( 'plugin_row_meta', array( $this, 'meta_filter' ), 10, 4 );

		// add messages to the plugins page
		add_action( 'pre_current_active_plugins', array( $this, 'register_messages' ) );

		// hijack the upgrades response from wordpress.org
		add_filter( 'http_response', array( $this, 'http_filter' ), 10, 3 );

		add_filter( 'plugin_row_meta', array( $this, 'add_update_link_to_plugins_row' ), 10, 4 );

		add_action( 'admin_footer', array( $this, 'insert_update_check_javascript' ) );

		add_action( 'wp_ajax_check_update_manually', array( $this, 'check_update_manually' ), 10, 0 );

	}

	function reset_transient() {
		delete_site_transient( 'update_plugins' ); // force an update
	}

	// This is how we hijack the upgrade info from wordpress.org
	function http_filter( $response, $r, $url ) {

		if ( $url == 'http://api.wordpress.org/plugins/update-check/1.0/' ) {
			$wpapi_response   = unserialize( $response['body'] );
			$response['body'] = serialize( $this->upgradable( $wpapi_response ) );
			return $response;
		}

		// For WordPress 3.7 and later:
		// http://make.wordpress.org/core/2013/10/25/json-encoding-ssl-api-wordpress-3-7/
		if ( $url == 'https://api.wordpress.org/plugins/update-check/1.1/' ) {
			$wpapi_response          = json_decode( $response['body'] );
			$override                = (object) $this->upgradable( $wpapi_response->plugins );
			$wpapi_response->plugins = $override;
			$response['body']        = json_encode( $wpapi_response );
			return $response;
		}

		return $response;
	}

	// This is where the magic happens.
	private function upgradable( $wpapi_response = array() ) {
		$plugins  = get_plugins();
		$upgrades = array();
		foreach ( $plugins as $file => $plugin ) {
			$slug = $this->get_plugin_slug( $file, $plugin );

			if ( ! $slug ) {
				continue;
			}

			$versions = $this->versions( $slug );
			if ( $versions && $this->version_compare( $versions->latest, $plugin['Version'] ) ) {
				$upgrades[ $file ]                 = new stdClass;
				$upgrades[ $file ]->slug           = $slug;
				$upgrades[ $file ]->stable_version = $versions->stable;
				$upgrades[ $file ]->new_version    = $versions->latest;
				$upgrades[ $file ]->url            = "http://wordpress.org/extend/plugins/$slug/";
				$upgrades[ $file ]->package        = "http://downloads.wordpress.org/plugin/$slug.{$versions->latest}.zip";
				if ( $this->version_compare( $versions->latest, $upgrades[ $file ]->stable_version ) ) {
					$upgrades[ $file ]->upgrade_notice = ' <strong>' . __( 'This release is a beta.', 'plugin-beta-tester' ) . '</strong>';
				}
			}
		}
		return $upgrades;
	}

	function version_compare( $a, $b ) {
		// remove unnecessary whitespace and lowercase all the things
		$a = trim( preg_replace( array( '!(\d)\s(\D)!', '!(\D)\s(\d)!' ), '\1\2', strtolower( $a ) ) );
		$b = trim( preg_replace( array( '!(\d)\s(\D)!', '!(\D)\s(\d)!' ), '\1\2', strtolower( $b ) ) );

		return version_compare( $a, $b, '>' );
	}

	// UI FUNCTIONS:
	// The following functions add some information to the plugins page
	function register_messages() {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! isset( $transient->response ) ) {
			return;
		}

		foreach ( $transient->response as $file => $plugin ) {
			add_action( "in_plugin_update_message-$file", array( $this, 'beta_message' ), 10, 2 );
		}
	}
	function beta_message( $plugin_data, $r ) {
		if ( $this->version_compare( $r->new_version, $r->stable_version ) ) {
			echo ' <span style="color:red;">' . sprintf( __( 'Please note that version %s is a beta.', 'plugin-beta-tester' ), $r->new_version ) . '</span> ' . sprintf( __( 'The latest stable version is %s.', 'plugin-beta-tester' ), $r->stable_version );
		}
	}
	function meta_filter( $plugin_meta, $plugin_file, $plugin_data, $context ) {

		$slug = $this->get_plugin_slug( $plugin_file, $plugin_data );

		if ( ! $slug ) {
			return $plugin_meta;
		}

		$versions = $this->versions( $slug );

		if ( $versions && $stable = $versions->stable ) {
			if ( $stable == $plugin_data['Version'] ) {
				$plugin_meta[0] .= __( ' (stable)', 'plugin-beta-tester' );
			} elseif ( $this->version_compare( $plugin_data['Version'], $stable ) ) {
				$plugin_meta[0] .= __( ' (<strong>beta</strong>)', 'plugin-beta-tester' );
			}
		}
		return $plugin_meta;
	}

	// UTILITIES:
	// The rest of these are utility functions

	// @param $slug
	function versions( $slug ) {

		// PLUGINS AUTHORS: use this hook to override latest and stable versions
		if ( $versions_info = apply_filters( 'pbt_versions', false, $slug ) ) {
			return $versions_info;
		}

		if ( $versions_info = get_site_transient( 'pbt_' . md5( $slug ) ) ) {
			return $versions_info;
		}

		include_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // for plugins_api
		$api = plugins_api(
			'plugin_information', array(
				'slug'   => $slug,
				'fields' => array( 'versions' => true ),
			)
		);

		if ( is_object( $api ) && isset( $api->versions ) && is_array( $api->versions ) && count( $api->versions ) ) {
			$versions = $api->versions;
			unset( $versions['trunk'] );
			$versions = array_keys( $versions );

			usort( $versions, 'version_compare' );
			$versions = array_reverse( $versions );

			$versions_info = (object) array(
				'latest' => $versions[0],
				'stable' => $api->version,
			);

			set_site_transient( 'pbt_' . md5( $slug ), $versions_info, PLUGIN_BETA_TESTER_EXPIRATION );

			return $versions_info;
		}
		return false;
	}

	function get_plugin_slug( $plugin_file, $plugin_data ) {
		$slug = dirname( $plugin_file );
		if ( empty( $slug ) || '.' == $slug ) {
			return false;
		}
		return $slug;
	}

	/*
	function check_compatibility($slug, $version) {
		$api = $this->get_api($slug);
		if ( !$api )
			return false;
		$keys = array_keys($api->compatibility);
		return $api->compatibility[$keys[0]][$version];
	}

	function get_api($slug) {
		if ( isset($this->api_cache[$slug]) )
			return $this->api_cache[$slug];

		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$api = plugins_api('plugin_information', array('slug' => $slug));
		if ( is_wp_error($api) )
			return false;

		$this->api_cache[$slug] = $api;
		return $api;
	}
	*/

	function insert_update_check_javascript() {

		$new_content = '<script type="text/javascript">';
		$new_content .= 'function checkPluginUpdate(){';
		$new_content .= 'jQuery(document).ready(function($) {';
		$new_content .= 'var data = {';
		$new_content .= '"action": "check_update_manually",';
		$new_content .= '};';
		$new_content .= 'jQuery.post(ajaxurl, data, function(response) {';
		$new_content .= 'console.log("Response: " + response);';
		$new_content .= 'location.reload(true);';
		$new_content .= '});';
		$new_content .= '});';
		$new_content .= '}';
		$new_content .= '</script>';
		echo $new_content;
	}

	function check_update_manually() {
		delete_site_transient( 'update_plugins' ); // force an update
		Plugin_Beta_Tester::reset_custom_site_transient();
		wp_update_plugins();
		wp_die();
	}

	static function reset_custom_site_transient() {

		$plugins = get_plugins();
		foreach ( $plugins as $plugin ) {
			delete_site_transient( 'pbt_' . md5( $plugin['TextDomain'] ) );
		}
	}

	function add_update_link_to_plugins_row( $plugin_meta, $plugin_file, $plugin_data, $status ) {

		if ( PLUGIN_FILE === $plugin_file ) {
			$new_content = '<a onclick="checkPluginUpdate()" style="cursor: pointer;">' . __( 'Check for updates', 'vk-plugin-beta-tester' ) . '</a>';
			array_push( $plugin_meta, $new_content );
		}

		return $plugin_meta;
	}
}

$plugin_beta_tester = new Plugin_Beta_Tester;

// clear the transient "upgrade plugins" cache when switching
register_activation_hook( __FILE__, array( $plugin_beta_tester, 'reset_transient' ) );
register_deactivation_hook( __FILE__, array( $plugin_beta_tester, 'reset_transient' ) );
