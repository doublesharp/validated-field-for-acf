<?php
if ( !class_exists( 'acf_vf_dependency' ) ):
class acf_vf_dependency {
	// input information from the theme
	var $slug;
	var $file;

	// installed plugins and their fields
	private static $plugins; // holds the list of plugins and their info
	private static $files; // holds the plugin filenames for more accurate searching

	// path/file required for checking things
	function __construct( $file ) {
		$this->file = $file;

		// extract the slug
		$this->slug = explode( "/", $file )[0];

		// get installed plugins
		if ( empty( self::$plugins ) ) {
			self::$plugins = get_plugins();
		}

		// get plugin files from array
		if ( empty( self::$files ) ) {
			$files = array_keys( self::$plugins );
			self::$files = array_combine( $files, $files );
		}
	}

	// return true if installed, false if not
	function check() {
		return in_array( $this->file, self::$files );
	}

	// return true if installed and activated, false if not
	function check_active() {
		$plugin_file = $this->get_plugin_file();
		if ( $plugin_file ) {
			return is_plugin_active( $plugin_file );
		}
		return false;
	}

	// gives a link to activate the plugin
	function activate_link() {
		$plugin_file = $this->get_plugin_file();
		if ( $plugin_file ) {
			return wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin='.$plugin_file ), 'activate-plugin_'.$plugin_file );
		}
		return false;
	}

	// return a nonced installation link for the plugin. checks wordpress.org to make sure it's there first.
	function install_link() {
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$info = plugins_api( 'plugin_information', array( 'slug' => $this->slug ) );

		if ( is_wp_error( $info ) ) 
			return false; // plugin not available from wordpress.org

		return wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . $this->slug ), 'install-plugin_' . $this->slug );
	}

	// return array key of plugin if installed, false if not, private because this isn't needed for themes, generally
	private function get_plugin_file() {
		return array_search( $this->file, self::$files );
	}
}
endif;