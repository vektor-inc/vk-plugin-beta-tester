<?php
/*
* Plugin Name: VK Plugin Beta Tester
* Plugin URI: https://github.com/vektor-inc/vk-plugin-beta-tester
* Description: Lets you easily beta test plugins by notifying you of beta versions. Also lets you upgrade to beta versions from within WordPress. This plugin
* Version: 0.2.9
* Author: Vektor,Inc.
* Text Domain: vk-plugin-beta-tester
* Author URI: https://vektor-inc.co.jp
*/

define( 'PLUGIN_BETA_TESTER_VERSION', '0.5' );
if ( ! defined( 'PLUGIN_BETA_TESTER_EXPIRATION' ) ) {
	define( 'PLUGIN_BETA_TESTER_EXPIRATION', 60 * 60 * 24 );
}

define( 'VK_PLUGIN_BETA_TESTER_BASENAME', plugin_basename( __FILE__ ) );

require_once dirname( __FILE__ ) . '/inc/vk-admin/vk-admin-config.php';

class VK_Plugin_Beta_Tester {
	private $api_cache = array();

	function __construct() {

		// add "stable" + "beta" markers to the version numbers on the plugins page
		add_filter( 'plugin_row_meta', array( $this, 'meta_filter' ), 10, 4 );

		add_filter( 'plugin_row_meta', array( $this, 'add_update_link_to_plugins_row' ), 10, 4 );

		// add messages to the plugins page
		add_action( 'pre_current_active_plugins', array( $this, 'register_messages' ) );

		// hijack the upgrades response from wordpress.org
		add_filter( 'http_response', array( $this, 'http_filter' ), 10, 3 );

		add_action( 'admin_footer', array( $this, 'insert_update_check_javascript' ) );

		add_action( 'wp_ajax_check_update_manually', array( $this, 'check_update_manually' ), 10, 0 );

		add_action( 'admin_menu', array( $this, 'add_main_setting' ), 10, 0 );

		add_filter( 'plugin_action_links_' . VK_PLUGIN_BETA_TESTER_BASENAME, array( $this, 'add_setting_link' ), 10, 1 );

	}


	function add_setting_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( '/options-general.php?page=vk-plugin-beta-tester-setting' ) ) . '">' . __( 'Setting', 'vk-plugin-beta-tester' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
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
			$slug        = $this->get_plugin_slug( $file, $plugin );
			$text_domain = $plugin['TextDomain'];

			if ( ! $slug ) {
				continue;
			}

			if ( ! $this->is_slug_allowed_beta_notice( $text_domain ) ) {

				$this->return_stable($wpapi_response,$file,$upgrades);
			} else {

				$versions = $this->versions( $slug );
				if ( $versions && $this->version_compare( $versions->latest, $plugin['Version'] ) ) {
					$upgrades[ $file ]                 = new stdClass;
					$upgrades[ $file ]->slug           = $slug;
					$upgrades[ $file ]->plugin         = $file;
					$upgrades[ $file ]->stable_version = $versions->stable;
					$upgrades[ $file ]->new_version    = $versions->latest;
					$upgrades[ $file ]->url            = "http://wordpress.org/extend/plugins/$slug/";
					$upgrades[ $file ]->package        = "http://downloads.wordpress.org/plugin/$slug.{$versions->latest}.zip";

					if ( $this->version_compare( $versions->latest, $upgrades[ $file ]->stable_version ) ) {
						$upgrades[ $file ]->upgrade_notice = ' <strong>' . __( 'This release is a beta.', 'vk-plugin-beta-tester' ) . '</strong>';
					}
				}else{

					$this->return_stable($wpapi_response,$file,$upgrades);
				}
			}
		}
		return $upgrades;
	}

	function return_stable($wpapi_response,$file,$upgrades){
		if ( isset( $wpapi_response->$file ) ) {
			return $upgrades[ $file ] = $wpapi_response->$file;
		}
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

		if ( $this->is_slug_allowed_beta_notice( $plugin_data['TextDomain'] ) ) {
			if ( $this->version_compare( $r->new_version, $r->stable_version ) ) {
				echo ' <span style="color:red;">' . sprintf( __( 'Please note that version %s is a beta.', 'vk-plugin-beta-tester' ), $r->new_version ) . '</span> ' . sprintf( __( 'The latest stable version is %s.', 'vk-plugin-beta-tester' ), $r->stable_version );
			}
		}
	}
	function meta_filter( $plugin_meta, $plugin_file, $plugin_data, $context ) {

		$slug        = $this->get_plugin_slug( $plugin_file, $plugin_data );
		$text_domain = $plugin_data['TextDomain'];

		if ( ! $slug ) {
			return $plugin_meta;
		}

		if ( ! $this->is_slug_allowed_beta_notice( $text_domain ) ) {
			return $plugin_meta;
		}

		$versions = $this->versions( $slug );
		if ( $versions && $stable = $versions->stable ) {
			if ( $stable == $plugin_data['Version'] ) {
				$plugin_meta[0] .= __( ' (stable)', 'vk-plugin-beta-tester' );
			} elseif ( $this->version_compare( $plugin_data['Version'], $stable ) ) {
				$plugin_meta[0] .= __( ' (<strong>beta</strong>)', 'vk-plugin-beta-tester' );
			}
		}
		return $plugin_meta;
	}

	/**
	 * @param $text_domain
	 *
	 * @return Boolean
	 */
	function is_slug_allowed_beta_notice( $text_domain ) {

		$config = get_option( 'vkpbt_active_plugin_for_beta_notice', [] );
		if ( ! isset( $config[ $text_domain ] ) ) {
			$config[ $text_domain ] = false;
		} else {
			return $config[ $text_domain ];
		}
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

	function insert_update_check_javascript() {

		$new_content  = '<script type="text/javascript">';
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
		VK_Plugin_Beta_Tester::reset_custom_site_transient();
		wp_update_plugins();
	}

	static function reset_custom_site_transient() {

		$plugins = get_plugins();
		foreach ( $plugins as $plugin ) {
			delete_site_transient( 'pbt_' . md5( $plugin['TextDomain'] ) );
		}
	}

	function add_update_link_to_plugins_row( $plugin_meta, $plugin_file, $plugin_data, $status ) {

		if ( ! array_key_exists( 'slug', $plugin_data ) || ! $this->is_slug_allowed_beta_notice( $plugin_data['TextDomain'] ) ) {
			return $plugin_meta;
		}

		$new_content = '<a onclick="checkPluginUpdate()" style="cursor: pointer;">' . __( 'Check for beta updates', 'vk-plugin-beta-tester' ) . '</a>';
		array_push( $plugin_meta, $new_content );

		return $plugin_meta;
	}

	function add_main_setting() {
		// $capability_required = veu_get_capability_required();
		$custom_page = add_submenu_page(
			'options-general.php',            // parent
			__( 'VK Plugin Beta Tester Setting', 'vk-plugin-beta-tester' ),   // Name of page
			__( 'VK Plugin Beta Tester Setting', 'vk-plugin-beta-tester' ),   // Label in menu
			'activate_plugins',               // veu_get_capability_required()でないのは edit_theme_options権限を付与したユーザーにもアクセスさせないためにactivate_pluginsにしている。
			// $capability_required,          // Capability
			'vk-plugin-beta-tester-setting',            // ユニークなこのサブメニューページの識別子
			array( $this, 'vkpbt_add_customSettingPage' )       // メニューページのコンテンツを出力する関数
		);
		if ( ! $custom_page ) {
			return;
		}

		add_action( 'admin_head-' . $custom_page, array( $this, 'update_to_current' ) );
	}

	function update_to_current() {
		$this->update_active_plugin_for_beta_notice();
	}

	/*-------------------------------------------*/
	/*	Setting Page
	/*-------------------------------------------*/
	function vkpbt_add_customSettingPage() {
		$get_page_title = __( 'VK Plugin Beta Tester Setting', 'vk-plugin-beta-tester' );
		$get_logo_html  = '';
		$get_menu_html  = '<li><a href="#beta-update-notice-setting">' . __( 'Beta Update Notice Setting', 'vk-plugin-beta-tester' ) . '</a></li>';

		Vk_Admin::admin_page_frame(
			$get_page_title, array(
				$this,
				'vkpbt_the_admin_body',
			), $get_logo_html, $get_menu_html
		);
	}


	function vkpbt_the_admin_body() {
		$this->vkpbt_save_data();
		echo '<h3>' . __( 'Beta Update Notice Setting' ) . '</h3>';
		echo '<div id="beta-update-notice-setting" class="sectionBox">';
		echo $this->vkpbt_create_common_form();
		echo '</div>';
	}

	function update_active_plugin_for_beta_notice() {

		$config       = get_option( 'vkpbt_active_plugin_for_beta_notice', [] );
		$config_key   = array_keys( $config );
		$plugins_slug = $this->get_plugins_text_domain();
		$update       = [];

		foreach ( $plugins_slug as $slug ) {

			if ( array_search( $slug, $config_key ) !== false ) {
				$update[ $slug ] = $config[ $slug ];
			} else {
				$update[ $slug ] = false;
			}
		}

		update_option( 'vkpbt_active_plugin_for_beta_notice', $update );
	}

	/**
	 * If option value is false, return default value.
	 * @return array
	 */
	function set_default_active_plugin_for_beta_notice() {

		$default      = [];
		$plugins_slug = $this->get_plugins_text_domain();
		foreach ( $plugins_slug as $slug ) {
			$default[ $slug ] = false;
		}

		return $default;
	}

	/**
	 * Get plugins TextDomain.
	 * @return array
	 */
	function get_plugins_text_domain() {
		$plugin_array = get_plugins();

		// First check if we have plugins, else return false
		if ( empty( $plugin_array ) ) {
			return false;
		}

		// Define our variable as an empty array to avoid bugs if $plugin_array is empty
		$text_domain = [];

		foreach ( $plugin_array as $plugin_data => $values ) {

			array_push( $text_domain, $values['TextDomain'] );

		}

		return $text_domain;
	}

	/**
	 *  Save data from the form.
	 */
	function vkpbt_save_data() {

		// nonce
		if ( isset( $_POST['vkpbt_nonce'] ) ) {

			if ( ! wp_verify_nonce( $_POST['vkpbt_nonce'], 'standing_on_the_shoulder_of_giants' ) ) {
				return false;
			}

			$isData = array_key_exists( 'vkpbt_active_plugin_for_beta_notice', $_POST );
			if ( ! $isData ) {
				$sanitized_post = [];
			} else {

				$sanitized_post = [];
				$post           = isset( $_POST['vkpbt_active_plugin_for_beta_notice'] ) ? wp_unslash( $_POST['vkpbt_active_plugin_for_beta_notice'] ) : [];

				if ( is_array( $post ) ) {
					foreach ( $post as $value ) {
						array_push( $sanitized_post, sanitize_text_field( $value ) );
					}
				}
			}

			$current_plugins = $this->get_plugins_text_domain();
			$config          = get_option( 'vkpbt_active_plugin_for_beta_notice', [] );
			if ( ! $config ) {
				$config = [];
			}

			foreach ( $current_plugins as $slug ) {

				if ( array_search( $slug, $sanitized_post ) !== false ) {
					$config[ $slug ] = true;
				} else {
					$config[ $slug ] = false;
				}
			}
			update_option( 'vkpbt_active_plugin_for_beta_notice', $config );
			$this->check_update_manually();
		}
	}

	function vkpbt_create_common_form() {
		$form  = '<form method="post" action="' . htmlspecialchars( $_SERVER['REQUEST_URI'] ) . '">';
		$form .= wp_nonce_field( 'standing_on_the_shoulder_of_giants', 'vkpbt_nonce' );
		$form .= '<p>' . __( 'Select plugins to display beta update notices', 'vk-google-job-posting-manager' ) . '</p>';
		$form .= $this->vkpbt_post_type_check_list();
		$form .= '<input type="submit" value="' . __( 'Save Changes', 'vk-google-job-posting-manager' ) . '" class="button button-primary">';
		$form .= '</form>';
		return $form;
	}

	function vkpbt_post_type_check_list() {

		$config = get_option( 'vkpbt_active_plugin_for_beta_notice', [] );
		$list   = '<ul>';
		foreach ( $config as $slug => $checked_saved ) {
			$checked = $checked_saved ? ' checked' : '';
			$list   .= '<li><label>';
			$list   .= '<input type="checkbox" name="vkpbt_active_plugin_for_beta_notice[]" value="' . esc_attr( $slug ) . '"' . esc_attr( $checked ) . ' />' . esc_html( $slug );
			$list   .= '</label></li>';
		}
		$list .= '</ul>';
		return $list;
	}
}

$plugin_beta_tester = new VK_Plugin_Beta_Tester;

// clear the transient "upgrade plugins" cache when switching
register_activation_hook( __FILE__, array( $plugin_beta_tester, 'reset_transient' ) );
register_deactivation_hook( __FILE__, array( $plugin_beta_tester, 'reset_transient' ) );
