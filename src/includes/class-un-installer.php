<?php

/**
 * Class to un/install the plugin.
 *
 * @package WordPoints
 * @since 1.8.0
 */

/**
 * Un/install the plugin.
 *
 * @since 1.8.0
 */
class WordPoints_Un_Installer extends WordPoints_Un_Installer_Base {

	/**
	 * @since 1.8.0
	 */
	protected $network_install_skipped_option = 'wordpoints_network_install_skipped';

	/**
	 * @since 1.8.0
	 */
	protected $network_installed_option = 'wordpoints_network_installed';

	/**
	 * @since 1.8.0
	 */
	protected $installed_sites_option = 'wordpoints_installed_sites';

	/**
	 * The plugin's capabilities.
	 *
	 * Used to hold the list of capabilities during install and uninstall, so that
	 * they don't have to be retrieved all over again for each site (if multisite).
	 *
	 * @since 1.8.0
	 *
	 * @type array The plugin's capabilities.
	 */
	protected $capabilities;

	/**
	 * @since 1.8.0
	 */
	public function install( $network ) {

		$filter_func = ( $network ) ? '__return_true' : '__return_false';
		add_filter( 'is_wordpoints_network_active', $filter_func );

		// Check if the plugin has been activated/installed before.
		$installed = (bool) wordpoints_get_network_option( 'wordpoints_data' );

		$this->capabilities = wordpoints_get_custom_caps();

		parent::install( $network );

		// Activate the Points component, if this is the first activation.
		if ( false === $installed ) {
			$wordpoints_components = WordPoints_Components::instance();
			$wordpoints_components->load();
			$wordpoints_components->activate( 'points' );
		}

		remove_filter( 'is_wordpoints_network_active', $filter_func );
	}

	/**
	 * @since 1.8.0
	 */
	public function before_uninstall() {

		$this->capabilities = array_keys( wordpoints_get_custom_caps() );
	}

	/**
	 * @since 1.8.0
	 */
	protected function install_network() {

		// Add plugin data.
		wordpoints_add_network_option(
			'wordpoints_data',
			array(
				'version'    => WORDPOINTS_VERSION,
				'components' => array(), // Components use this to store data.
				'modules'    => array(), // Modules can use this to store data.
			)
		);
	}

	/**
	 * @since 1.8.0
	 */
	protected function install_site() {
		wordpoints_add_custom_caps( $this->capabilities );
	}

	/**
	 * @since 1.8.0
	 */
	protected function install_single() {

		$this->install_network();
		$this->install_site();
	}

	/**
	 * @since 1.8.0
	 */
	protected function load_dependencies() {
		require_once dirname( __FILE__ ) . '/uninstall-bootstrap.php';
	}

	/**
	 * @since 1.8.0
	 */
	protected function uninstall_network() {

		$this->uninstall_modules();
		$this->uninstall_components();

		delete_site_option( 'wordpoints_data' );
		delete_site_option( 'wordpoints_active_components' );
		delete_site_option( 'wordpoints_excluded_users' );
		delete_site_option( 'wordpoints_sitewide_active_modules' );
	}

	/**
	 * @since 1.8.0
	 */
	protected function uninstall_site() {

		delete_option( 'wordpoints_data' );
		delete_option( 'wordpoints_active_modules' );
		delete_option( 'wordpoints_active_components' );
		delete_option( 'wordpoints_excluded_users' );
		delete_option( 'wordpoints_recently_activated_modules' );

		wp_cache_delete( 'wordpoints_modules' );

		wordpoints_remove_custom_caps( $this->capabilities );
	}

	/**
	 * @since 1.8.0
	 */
	protected function uninstall_single() {

		$this->uninstall_modules();
		$this->uninstall_components();
		$this->uninstall_site();
	}

	/**
	 * Uninstall modules.
	 *
	 * Note that modules aren't active when they are uninstalled, so they need to
	 * include any dependencies in their uninstall.php files.
	 *
	 * @since 1.8.0
	 */
	protected function uninstall_modules() {

		wordpoints_deactivate_modules(
			wordpoints_get_array_option( 'wordpoints_active_modules', 'site' )
		);

		foreach ( array_keys( wordpoints_get_modules() ) as $module ) {
			wordpoints_uninstall_module( $module );
		}

		$this->delete_modules_dir();
	}

	/**
	 * Attempt to delete the modules directory.
	 *
	 * @since 1.8.0
	 */
	protected function delete_modules_dir() {

		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem ) {
			$wp_filesystem->delete( wordpoints_modules_dir(), true );
		}
	}

	/**
	 * Uninstall the components.
	 *
	 * @since 1.8.0
	 */
	protected function uninstall_components() {

		/*
		 * Back compat < 1.7.0
		 *
		 * The below notes no longer apply.
		 * --------------------------------
		 *
		 * Bulk 'deactivate' components. No other filters should be applied later than these
		 * (e.g., after 99) for this hook - doing so could have unexpected results.
		 *
		 * We do this so that we can load them to call the uninstall hooks, without them
		 * being active.
		 */
		add_filter( 'wordpoints_component_active', '__return_false', 100 );

		$components = WordPoints_Components::instance();

		// Back-compat < 1.7.0
		$components->load();

		// Uninstall the components.
		foreach ( $components->get() as $component => $data ) {
			$components->uninstall( $component );
		}
	}
}

// EOF
