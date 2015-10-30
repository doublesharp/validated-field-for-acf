<?php
/*
Plugin Name: Advanced Custom Fields: Validated Field
Plugin URI: http://www.doublesharp.com/
Description: Server side validation, input masking and more for Advanced Custom Fields
Author: Justin Silver
Version: 2.0beta
Author URI: http://doublesharp.com/
*/

if (!defined('ACF_VF_VERSION')){
    define('ACF_VF_VERSION', '2.0beta');
}

if (!defined('ACF_VF_PLUGIN_FILE')){
    define('ACF_VF_PLUGIN_FILE', __FILE__);
}

// Load the add-on field once the plugins have loaded, but before init (this is when ACF registers the fields)
if (!function_exists('load_textdomain_acf_vf')){

    include_once 'common/acf_vf_options.php';
    include_once 'common/acf_vf_validated_field.php';
    include_once 'common/acf_vf_updates.php';

    // ACF 4
    function register_acf_validated_field()
    {
        // create field
        include_once 'v4/validated_field_v4.php';
    }
    add_action('acf/register_fields', 'register_acf_validated_field');

    // ACF 5
    function include_acf_validated_field()
    {
        // create field
        include_once 'v5/validated_field_v5.php';
    }
    add_action('acf/include_fields', 'include_acf_validated_field');

    // Translations
    function load_textdomain_acf_vf() 
    {
        load_plugin_textdomain('acf_vf', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    add_action('plugins_loaded', 'load_textdomain_acf_vf');

}