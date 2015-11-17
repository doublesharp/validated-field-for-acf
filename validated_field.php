<?php
/*
Plugin Name: Advanced Custom Fields: Validated Field
Plugin URI: http://www.doublesharp.com/
Description: Server side validation, input masking and more for Advanced Custom Fields
Author: Justin Silver
Version: 2.0beta
Author URI: http://doublesharp.com/
*/

if ( !defined( 'ACF_VF_VERSION' ) ){
	define( 'ACF_VF_VERSION', '2.0beta' );
}

if ( !defined( 'ACF_VF_PLUGIN_FILE' ) ){
	define( 'ACF_VF_PLUGIN_FILE', __FILE__ );
}

// Load the add-on field once the plugins have loaded, but before init ( this is when ACF registers the fields )
if ( !function_exists( 'load_textdomain_acf_vf' ) ){

	include_once 'common/acf_vf_dependencies.php';
	include_once 'common/acf_vf_options.php';
	include_once 'common/acf_vf_validated_field.php';
	include_once 'common/acf_vf_updates.php';

	// ACF 4
	function register_acf_validated_field()
	{
		// create field
		include_once 'v4/validated_field_v4.php';
	}
	add_action( 'acf/register_fields', 'register_acf_validated_field' );

	// ACF 5
	function include_acf_validated_field()
	{
		// create field
		include_once 'v5/validated_field_v5.php';
	}
	add_action( 'acf/include_fields', 'include_acf_validated_field' );

	// Translations
	function load_textdomain_acf_vf() 
	{
		load_plugin_textdomain( 'acf_vf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	add_action( 'plugins_loaded', 'load_textdomain_acf_vf' );

	function check_validated_field_dependencies()
	{
		// Only show these messages if the user can manage plugins, otherwise it's a moot point
		if ( current_user_can( 'manage_plugins' ) ) {
	
			$acf5 = new acf_vf_dependency( 'advanced-custom-fields-pro/acf.php' );
			$acf4 = new acf_vf_dependency( 'advanced-custom-fields/acf.php' );

			// Is ACF 4 or 5 active?
			if ( $acf5->check_active() || $acf4->check_active() ) {
				return;
			}

			// Since it can't work without ACF, link to deactivate this plugin
			$plugin_file = 'validated-field-for-acf/validated_field.php';
			$deactivate_link = wp_nonce_url( self_admin_url( 'plugins.php?action=deactivate&amp;plugin=' . $plugin_file  ), 'deactivate-plugin_' . $plugin_file  );
			
			// Neither are active, but is one installed?
			if ( $acf5->check() || $acf4->check() ) {
				// Admin message
				$nag_msg =  __( 'ACF Validated Field requires the %1$s plugin, which is installed but not activated.', 'acf_vf' );
				$buttons = '<a href="%2$s" class="button left" style="border-color:green">' . __( 'Activate %1$s', 'acf_vf' ) . '</a> <a href="%3$s" class="button right" style="border-color:red">' . __( 'Deactivate ACF Validated Field', 'acf_vf' ) . '</a>';
				$html = '<div class="update-nag"><p>' . $nag_msg . '</p><p>' . $buttons . '</p></div>';

				// Show the correct activate link depending on what is available.
				if ( $acf5->check() ) {
					// if ACF5 is installed, we want to use that
					printf( $html, 'Advanced Custom Fields Pro', $acf5->activate_link(), $deactivate_link );
				} elseif ( $acf4->check() ){
					// use ACF4 if it is available and Pro is not
					printf( $html, 'Advanced Custom Fields', $acf4->activate_link(), $deactivate_link );
				}
				return;
			}

			if ( $install_link = $acf4->install_link() ) {
				// Admin message
				$nag_msg =  __( 'ACF Validated Field requires the %1$s plugin, which is not installed but is available in the WordPress Plugin Repository.', 'acf_vf' );		
				$buttons = '<a href="%2$s" class="button left" style="border-color:green">' . __( 'Install %1$s', 'acf_vf' ) . '</a> <a href="%3$s" class="button right" style="border-color:red">' . __( 'Deactivate ACF Validated Field', 'acf_vf' ) . '</a>';
				$html = '<div class="update-nag"><p>' . $nag_msg . '</p><p>' . $buttons . '</p></div>';

				printf( $html, 'Advanced Custom Fields', $install_link, $deactivate_link );
				return;
			}
		}

	}
	add_action( 'admin_notices', 'check_validated_field_dependencies' );
}