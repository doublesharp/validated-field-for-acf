<?php
/*
Plugin Name: Advanced Custom Fields: Validated Field
Plugin URI: http://www.doublesharp.com/
Description: Server side validation, input masking and more for Advanced Custom Fields
Author: Justin Silver
Version: 1.8.0-dev
Requires at least: 6.0
Requires PHP: 8.0
Author URI: http://doublesharp.com/
*/

if ( ! defined( 'ACF_VF_VERSION' ) )
	define( 'ACF_VF_VERSION', '1.8.0-dev' );

if ( !defined('ACF_VF_PLUGIN_FILE') )
	define( 'ACF_VF_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Marketing destination shared by the settings page and workspace designer.
// Define this earlier in wp-config.php to override the default.
if ( ! defined( 'ACF_VF_PRO_UPGRADE_URL' ) ) {
	define( 'ACF_VF_PRO_UPGRADE_URL', 'https://www.doublesharp.com/' );
}

function include_acf_validated_field() {
	if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '6.0', '<' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-acf-vf-validation.php';
	require_once __DIR__ . '/includes/class-acf-field-validated-field.php';
	acf_register_field_type( 'acf_field_validated_field' );
	require_once __DIR__ . '/includes/class-acf-vf-polylang.php';
	new ACF_VF_Polylang();
}
add_action( 'acf/include_field_types', 'include_acf_validated_field' );

function load_textdomain_acf_vf() {
	load_plugin_textdomain( 'acf_vf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'load_textdomain_acf_vf', 5 );

require_once __DIR__ . '/includes/app/Plugin.php';
\ACF_VF\App\Plugin::boot();
