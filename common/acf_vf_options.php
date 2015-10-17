<?php
if ( !class_exists('acf_vf_options' ) ):
class acf_vf_options {

	static function get_field_group(){
		return apply_filters( 'acf_vf/options_field_group', array(
			'key' => 'group_55d6baa806f00',
			'title' => __( 'Validated Field Settings', 'acf_vf' ),
			'fields' => array (
				array (
					'key' => 'field_55d6bd56d220f',
					'label' => __( 'General', 'acf_vf' ),
					'type' => 'tab',
					'placement' => 'top',
					'endpoint' => 0,
				),

				array (
					'key' => 'field_55d6bc95a04d4',
					'label' => __( 'Debugging', 'acf_vf' ),
					'name' => 'acf_vf_debug',
					'type' => 'true_false',
					'instructions' => __( 'Check this box to turn on debugging for Validated Fields.', 'acf_vf' ),
					'message' => __( 'Enable Debugging', 'acf_vf' ),
					'default_value' => 0,
				),
				array (
					'key' => 'field_55d6be22b0225',
					'label' => __( 'Draft Validation', 'acf_vf' ),
					'name' => 'acf_vf_drafts',
					'type' => 'true_false',
					'instructions' => __( 'Check this box to force Draft validation globally, or uncheck to allow it to be set per field.', 'acf_vf' ),
					'message' => __( 'Force Draft Validation', 'acf_vf' ),
					'default_value' => 0,
				),
				array (
					'key' => 'field_55d6c0d4b3ae0',
					'label' => 'Frontend Validation',
					'name' => 'acf_vf_frontend',
					'type' => 'true_false',
					'instructions' => sprintf( __( 'Check this box to turn on validation for front-end forms created with %1$s.', 'acf_vf' ), '<code>acf_form()</code>' ),
					'message' => __( 'Enable Frontend Validation', 'acf_vf' ),
					'default_value' => 0,
				),
				array (
					'key' => 'field_5606d52b87541',
					'label' => 'UI / UX',
					'type' => 'tab',
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_5606d0fdddb99',
					'label' => __( 'Link to Tab', 'acf_vf' ),
					'name' => 'acf_vf_link_to_tab',
					'type' => 'true_false',
					'instructions' => __( 'Uncheck this box to disable the "Link to Tab" functionality.', 'acf_vf' ),
					'message' => __( 'Enable Link to Tab', 'acf_vf' ),
					'default_value' => 1,
				),
				array (
					'key' => 'field_5606d206ddb9a',
					'label' => __( 'Link to Field Group Editor', 'acf_vf' ),
					'name' => 'acf_vf_link_to_field_group_editor',
					'type' => 'true_false',
					'instructions' => sprintf( __( 'Uncheck this box to disable the "Link to Field Group" functionality. This feature allows you to specify fields to open using the URL hash, and keep fields open when the page is refreshed. To open a field named $s%1, use the URL $s%2.', 'acf_vf' ), '<code>another_text_field</code>', '<code><i>/wp-admin/post.php?post=44&action=edit#another_text_field</i></code>' ),
					'message' => __( 'Enable Link to Field Group Editor', 'acf_vf' ),
					'default_value' => 1,
				),
				array (
					'key' => 'field_55d6c123b3ae1',
					'label' => __( 'Admin CSS on Frontend', 'acf_vf' ),
					'name' => 'acf_vf_frontend_css',
					'type' => 'true_false',
					'instructions' => sprintf( __( 'Uncheck this box to turn off "$s%1" admin theme enqueued by $s%2.', 'acf_vf' ), 'colors-fresh', '<code>acf_form_head()</code>' ),
					'message' => __( 'Enqueue Admin CSS on Frontend', 'acf_vf' ),
					'default_value' => 1,
				),
				array (
					'key' => 'field_55d6bd84d2210',
					'label' => __( 'Masked Input', 'acf_vf' ),
					'type' => 'tab',
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_55f1d1ec2c61c',
					'label' => __( 'Mask Patterns Upgrade', 'acf_vf' ),
					'type' => 'message',
					'message' => __( 'This is a message about how you can upgrade to enable custom mask patterns.', 'acf_vf' ),
					'esc_html' => 0,
				), 
				array (
					'key' => 'field_5604667c31fea',
					'label' => __( 'Field Level Security', 'acf_vf' ),
					'type' => 'tab',
					'placement' => 'top',
					'endpoint' => 0,
				),
				array (
					'key' => 'field_560466c231feb',
					'label' => __( 'Field Level Security Upgrade', 'acf_vf' ),
					'type' => 'message',
					'message' => __( 'This is a message about how you can upgrade to enable field level security.', 'acf_vf' ),
					'esc_html' => 0,
				),
			),
			'options' => array (
				'position' => 'normal',
				'layout' => 'no_box',
				'hide_on_screen' => array (
				),
			),
			'menu_order' => 100,
			'position' => 'normal',
			'style' => 'seamless',
			'label_placement' => 'top',
			'instruction_placement' => 'label',
			'hide_on_screen' => '',
			'active' => 1,
			'description' => '',
		) );
	}
}
endif;