<?php

/**
 * Plugin Name:       Mai Performance Enhancer
 * Plugin URI:        https://bizbudding.com/
 * GitHub Plugin URI: maithemewp/mai-performance-enhancer
 * Description:       An aggressive plugin to move all (most) scripts to the footer and do various performance dom cleanup tasks.
 * Version:           0.13.0
 *
 * Author:            BizBudding
 * Author URI:        https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main Mai_Performance_Enhancer_Plugin Class.
 *
 * @since 0.1.0
 */
final class Mai_Performance_Enhancer_Plugin {

	/**
	 * @var   Mai_Performance_Enhancer_Plugin The one true Mai_Performance_Enhancer_Plugin
	 * @since 0.1.0
	 */
	private static $instance;

	/**
	 * Main Mai_Performance_Enhancer_Plugin Instance.
	 *
	 * Insures that only one instance of Mai_Performance_Enhancer_Plugin exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since   0.1.0
	 * @static  var array $instance
	 * @uses    Mai_Performance_Enhancer_Plugin::setup_constants() Setup the constants needed.
	 * @uses    Mai_Performance_Enhancer_Plugin::includes() Include the required files.
	 * @uses    Mai_Performance_Enhancer_Plugin::hooks() Activate, deactivate, etc.
	 * @see     Mai_Performance_Enhancer_Plugin()
	 * @return  object | Mai_Performance_Enhancer_Plugin The one true Mai_Performance_Enhancer_Plugin
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			// Setup the setup.
			self::$instance = new Mai_Performance_Enhancer_Plugin;
			// Methods.
			self::$instance->setup_constants();
			self::$instance->includes();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @since   0.1.0
	 * @access  protected
	 * @return  void
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'mai-performance-enhancer' ), '1.0' );
	}

	/**
	 * Disable unserializing of the class.
	 *
	 * @since   0.1.0
	 * @access  protected
	 * @return  void
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'mai-performance-enhancer' ), '1.0' );
	}

	/**
	 * Setup plugin constants.
	 *
	 * @access  private
	 * @since   0.1.0
	 * @return  void
	 */
	private function setup_constants() {
		// Plugin version.
		if ( ! defined( 'MAI_PERFORMANCE_ENHANCER_VERSION' ) ) {
			define( 'MAI_PERFORMANCE_ENHANCER_VERSION', '0.13.0' );
		}

		// // Plugin Folder Path.
		// if ( ! defined( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_DIR' ) ) {
		// 	define( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		// }

		// // Plugin Folder URL.
		// if ( ! defined( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_URL' ) ) {
		// 	define( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		// }

		// // Plugin Root File.
		// if ( ! defined( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_FILE' ) ) {
		// 	define( 'MAI_PERFORMANCE_ENHANCER_PLUGIN_FILE', __FILE__ );
		// }

		// // Plugin Base Name
		// if ( ! defined( 'MAI_PERFORMANCE_ENHANCER_BASENAME' ) ) {
		// 	define( 'MAI_PERFORMANCE_ENHANCER_BASENAME', dirname( plugin_basename( __FILE__ ) ) );
		// }
	}

	/**
	 * Include required files.
	 *
	 * @access  private
	 * @since   0.1.0
	 * @return  void
	 */
	private function includes() {
		// Include vendor libraries.
		require_once __DIR__ . '/vendor/autoload.php';
		// Main class.
		include __DIR__ . '/classes/class-performance-enhancer.php';
	}

	/**
	 * Run the hooks.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function hooks() {
		add_action( 'plugins_loaded', [ $this, 'updater' ] );
	}

	/**
	 * Setup the updater.
	 *
	 * composer require yahnis-elsts/plugin-update-checker
	 *
	 * @since 0.1.0
	 *
	 * @uses https://github.com/YahnisElsts/plugin-update-checker/
	 *
	 * @return void
	 */
	public function updater() {
		// Bail if current user cannot manage plugins.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		// Bail if plugin updater is not loaded.
		if ( ! class_exists( 'Puc_v4_Factory' ) ) {
			return;
		}

		// Setup the updater.
		$updater = Puc_v4_Factory::buildUpdateChecker( 'https://github.com/maithemewp/mai-performance-enhancer', __FILE__, 'mai-performance-enhancer' );

		// Set the stable branch.
		$updater->setBranch( 'main' );

		// Maybe set github api token.
		if ( defined( 'MAI_GITHUB_API_TOKEN' ) ) {
			$updater->setAuthentication( MAI_GITHUB_API_TOKEN );
		}

		// Add icons for Dashboard > Updates screen.
		if ( function_exists( 'mai_get_updater_icons' ) && $icons = mai_get_updater_icons() ) {
			$updater->addResultFilter(
				function ( $info ) use ( $icons ) {
					$info->icons = $icons;
					return $info;
				}
			);
		}
	}
}

/**
 * The main function for that returns Mai_Performance_Enhancer_Plugin
 *
 * The main function responsible for returning the one true Mai_Performance_Enhancer_Plugin
 * Instance to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * Example: <?php $plugin = Mai_Performance_Enhancer_Plugin(); ?>
 *
 * @since 0.1.0
 *
 * @return object|Mai_Performance_Enhancer_Plugin The one true Mai_Performance_Enhancer_Plugin Instance.
 */
function mai_performance_enhancer_plugin() {
	return Mai_Performance_Enhancer_Plugin::instance();
}

// Get Mai_Performance_Enhancer_Plugin Running.
mai_performance_enhancer_plugin();
