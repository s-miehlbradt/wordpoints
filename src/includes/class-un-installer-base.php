<?php

/**
 * Abstract class for un/installing a plugin/component/module.
 *
 * @package WordPoints
 * @since 1.8.0
 */

/**
 * Base class to be extended for un/installing a plugin/component/module.
 *
 * @since 1.8.0
 */
abstract class WordPoints_Un_Installer_Base {

	//
	// Protected Vars.
	//

	/**
	 * The prefix to use for the name of the options the un/installer uses.
	 *
	 * @since 1.8.0
	 *
	 * @type string $option_prefix
	 */
	protected $option_prefix;

	/**
	 * A list of versions of this entity with updates.
	 *
	 * @since 1.8.0
	 *
	 * @type array $updates
	 */
	protected $updates = array();

	/**
	 * Whether the entity is being installed network wide.
	 *
	 * @since 1.8.0
	 *
	 * @type bool $network_wide
	 */
	protected $network_wide;

	/**
	 * The version being updated from.
	 *
	 * @since 1.8.0
	 *
	 * @type string $updating_from
	 */
	protected $updating_from;

	/**
	 * The version being updated to.
	 *
	 * @since 1.8.0
	 *
	 * @type string $updating_to
	 */
	protected $updating_to;

	//
	// Public Methods.
	//

	/**
	 * Run the install routine.
	 *
	 * @since 1.8.0
	 *
	 * @param bool $network Whether the install should be network-wide on multisite.
	 */
	public function install( $network ) {

		$this->network_wide = $network;

		$this->before_install();

		/**
		 * Include the upgrade script so that we can use dbDelta() to create DBs.
		 *
		 * @since 1.8.0
		 */
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		if ( is_multisite() ) {

			$this->install_network();

			if ( $network ) {

				update_site_option( "{$this->option_prefix}network_installed", true );

				if ( $this->do_per_site_install() ) {

					$original_blog_id = get_current_blog_id();

					foreach ( $this->get_all_site_ids() as $blog_id ) {
						switch_to_blog( $blog_id );
						$this->install_site();
					}

					switch_to_blog( $original_blog_id );

					// See http://wordpress.stackexchange.com/a/89114/27757
					unset( $GLOBALS['_wp_switched_stack'] );
					$GLOBALS['switched'] = false;

				} else {

					// We'll check this later and let the user know that per-site
					// install was skipped.
					add_site_option( "{$this->option_prefix}network_install_skipped", true );
				}

			} else {

				$this->install_site();

				$sites = wordpoints_get_array_option( "{$this->option_prefix}installed_sites", 'site' );
				$sites[] = get_current_blog_id();

				update_site_option( "{$this->option_prefix}installed_sites", $sites );
			}

		} else {

			$this->install_single();
		}
	}

	/**
	 * Run the uninstallation routine.
	 *
	 * @since 1.8.0
	 */
	public function uninstall() {

		$this->load_dependencies();

		$this->before_uninstall();

		if ( is_multisite() ) {

			if ( $this->do_per_site_uninstall() ) {

				$original_blog_id = get_current_blog_id();

				foreach ( $this->get_installed_site_ids() as $blog_id ) {
					switch_to_blog( $blog_id );
					$this->uninstall_site();
				}

				switch_to_blog( $original_blog_id );

				// See http://wordpress.stackexchange.com/a/89114/27757
				unset( $GLOBALS['_wp_switched_stack'] );
				$GLOBALS['switched'] = false;
			}

			$this->uninstall_network();

			delete_site_option( "{$this->option_prefix}installed_sites" );
			delete_site_option( "{$this->option_prefix}network_installed" );
			delete_site_option( "{$this->option_prefix}network_install_skipped" );

		} else {

			$this->uninstall_single();
		}
	}

	/**
	 * Update the entity.
	 *
	 * @since 1.8.0
	 */
	public function update( $from, $to, $network = null ) {

		if ( null === $network ) {
			$network = is_wordpoints_network_active();
		}

		$this->network_wide = $network;

		foreach ( $this->updates as $index => $update ) {

			if ( ! version_compare( $from, $update, '<' ) ) {
				unset( $this->updates[ $index ] );
			}

			$this->updates[ $index ] = str_replace( '.', '_', $update );
		}

		if ( empty( $this->updates ) ) {
			return;
		}

		$this->updating_from = $from;
		$this->updating_to = $to;

		$this->before_update();

		/**
		 * Include the upgrade script so that we can use dbDelta() to create DBs.
		 *
		 * @since 1.8.0
		 */
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		if ( is_multisite() ) {

			$this->update_( 'network' );

			if ( $this->network_wide ) {

				if ( $this->do_per_site_update() ) {

					$original_blog_id = get_current_blog_id();

					foreach ( $this->get_installed_site_ids() as $blog_id ) {
						switch_to_blog( $blog_id );
						$this->update_( 'site' );
					}

					switch_to_blog( $original_blog_id );

					// See http://wordpress.stackexchange.com/a/89114/27757
					unset( $GLOBALS['_wp_switched_stack'] );
					$GLOBALS['switched'] = false;

				} else {

					// We'll check this later and let the user know that per-site
					// update was skipped.
					add_site_option( "{$this->option_prefix}network_update_skipped", true );
				}

			} else {

				$this->update_( 'site' );
			}

		} else {

			$this->update_( 'single' );
		}
	}

	//
	// Protected Methods.
	//

	/**
	 * Check whether we should run the install for each site in the network.
	 *
	 * On large networks we don't attempt the per-site install.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether to do the per-site installation.
	 */
	protected function do_per_site_install() {

		return ! wp_is_large_network();
	}

	/**
	 * Get the IDs of all sites on the network.
	 *
	 * @since 1.8.0
	 *
	 * @return array The IDs of all sites on the network.
	 */
	protected function get_all_site_ids() {

		global $wpdb;

		return $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	}

	/**
	 * Check if this entity is network installed.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether the code is network installed.
	 */
	protected function is_network_installed() {

		return (bool) get_site_option( "{$this->option_prefix}network_installed" );
	}

	/**
	 * Check if we should run the uninstall for each site on the network.
	 *
	 * On large multisite networks we don't attempt the per-site uninstall.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether to do the per-site uninstallation.
	 */
	protected function do_per_site_uninstall() {

		if ( $this->is_network_installed() && wp_is_large_network() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if we should run the update for each site on the network.
	 *
	 * On large multisite networks we don't attempt the per-site update.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether to do the per-site update.
	 */
	protected function do_per_site_update() {

		if ( $this->is_network_installed() && wp_is_large_network() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the IDs of all sites on which this is installed.
	 *
	 * @since 1.8.0
	 *
	 * @return array The IDs of the sites where this entity is installed.
	 */
	protected function get_installed_site_ids() {

		if ( $this->is_network_installed() ) {
			$sites = $this->get_all_site_ids();
		} else {
			$sites = wordpoints_get_array_option( "{$this->option_prefix}installed_sites", 'site' );
		}

		return $sites;
	}

	/**
	 * Set a component's version.
	 *
	 * For use when installing a component.
	 *
	 * @since 1.8.0
	 *
	 * @param string $component The component's slug.
	 * @param string $version   The installed component version.
	 */
	protected function set_component_version( $component, $version ) {

		$wordpoints_data = wordpoints_get_array_option( 'wordpoints_data', 'network' );

		if ( empty( $wordpoints_data['components'][ $component ]['version'] ) ) {
			$wordpoints_data['components'][ $component ]['version'] = $version;
		}

		wordpoints_update_network_option( 'wordpoints_data', $wordpoints_data );
	}

	/**
	 * Run before installing.
	 *
	 * @since 1.8.0
	 */
	protected function before_install() {}

	/**
	 * Run before uninstalling, but after loading dependencies.
	 *
	 * @since 1.8.0
	 */
	protected function before_uninstall() {}

	/**
	 * Run before updating.
	 *
	 * @since 1.8.0
	 */
	protected function before_update() {}

	/**
	 * Run an update.
	 *
	 * @since 1.8.0
	 *
	 * @param string $type The type of update to run.
	 */
	protected function update_( $type ) {

		foreach ( $this->updates as $version ) {
			$this->{"update_{$type}_to_{$version}"}();
		}
	}

	//
	// Abstract Methods.
	//

	/**
	 * Install on the network.
	 *
	 * This runs on multisite to install only the things that are common to the
	 * whole network. For example, it would add any "site" (network-wide) options.
	 *
	 * @since 1.8.0
	 */
	abstract protected function install_network();

	/**
	 * Install on a single site on the network.
	 *
	 * This runs on multisite to install on a single site on the network, which
	 * will be the current site when this method is called.
	 *
	 * @since 1.8.0
	 */
	abstract protected function install_site();

	/**
	 * Innstall on a single site.
	 *
	 * This runs when the WordPress site is not a multisite. It should completely
	 * install the entity.
	 *
	 * @since 1.8.0
	 */
	abstract protected function install_single();

	/**
	 * Load any dependencies of the unisntall code.
	 *
	 * @since 1.8.0
	 */
	abstract protected function load_dependencies();

	/**
	 * Uninstall from the network.
	 *
	 * This runs on multisite to uninstall only the things that are common to the
	 * whole network. For example, it would delete any "site" (network-wide) options.
	 *
	 * @since 1.8.0
	 */
	abstract protected function uninstall_network();

	/**
	 * Uninstall from a single site on the network.
	 *
	 * This runs on multisite to uninstall from a single site on the network, which
	 * will be the current site when this method is called.
	 *
	 * @since 1.8.0
	 */
	abstract protected function uninstall_site();

	/**
	 * Uninstall from a single site.
	 *
	 * This runs when the WordPress site is not a multisite. It should completely
	 * uninstall the entity.
	 *
	 * @since 1.8.0
	 */
	abstract protected function uninstall_single();
}

// EOF
