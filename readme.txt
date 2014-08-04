=== Advanced Custom Fields: Validated Field ===
Contributors: doublesharp
Tags: acf, advanced custom fields, validation, validate, regex, php, mask, input, readonly, add-on, unique, input, edit
Requires at least: 3.0
Tested up to: 3.9.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The Validated Field add-on for Advanced Custom Fields provides input masking and validation of other field types.

== Description ==
= Validated Field Add-On =
The **Validated Field** add-on for [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/)
provides a wrapper for other input types which allows you to provide client side input masking using the jQuery 
[Masked Input Plugin](http://digitalbush.com/projects/masked-input-plugin/), server side validation using either PHP regular expressions 
or PHP code, the option of ensuring a field's uniqueness by `post_type` and `meta_key`, `post_type`, or site wide, as well as marking a field as read-only.

= Features =
1. **Input Masking** - easily set masks on text inputs to ensure data is properly formatted.
2. **Server Side Validation** - validate the inputs using server side PHP code or regular expressions.
3. **Uniqueness** - ensure that the value being updated is not already in use.
4. **Repeater Fields** - validated fields within a [Repeater Field](http://www.advancedcustomfields.com/add-ons/repeater-field/).
5. **Read Only** - specify a field a read-only allowing it to be displayed but not updated. *BETA*

= Compatibility =
Requires [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/) version 4.0 or greater.

== Installation ==
1. Download the plugin and extract to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure validated fields within the Advanced Custom Fields menus

== Frequently Asked Questions ==
= I've activated the Validated Field plugin, but nothing happens =
Ensure that you have [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/) installed, and that it is activated. 
Validated Field will appear as a new input type in the field group editor.

= Configuration Options =
Global configurations for the Validated Field plugin can be found in the WordPress Admin under `Custom Fields > Validated Field Settings`.

== Screenshots ==
1. Example configuration to validate a telephone number field.
2. Example of a conflict - the same telephone number already exists, with a link to the existing record.
3. Example of PHP validation - checking the length of a field’s value.
4. Example of PHP validation failure with the error message raised to the UI.

== Changelog ==
= 1.3.1 =
* Bug Fix: Apply input masking to fields for new posts, not just editing existing ones.

= 1.3 =
* Support front end validation using [`acf_form()`](http://www.advancedcustomfields.com/resources/functions/acf_form/).
* Support for WPML, props @gunnyst.
* Move configuration to WordPress Admin under `Custom Fields > Validated Field Settings`.
 * Debug - enable debugging, defaults to off.
 * Drafts - enable draft validation, defaults to on.
 * Front End - enable front end validation, defaults to off.
 * Front End Admin CSS - enable `acf_form_head()` to enqueue an admin theme, defaults to on.
* Improved SQL for unique queries to support Relationship fields - check both arrays and single IDs.
* Fix conflicts with ACF client side validation (required fields, etc).
* Fix reference to `$sub_field['read_only']` with `$field['read_only']` for jQuery masking, props @johnny_br.

= 1.2.7 =
* Bug Fix: Post Preview fix when WordPress 'click' event triggers a 'submit' before the clicked element can be tracked by the plugin.
* Added comments to unpacked JavaScript.

= 1.2.6 =
* Critical Bug Fix: Fix compatibility issues with Firefox.

= 1.2.5.1 =
* Remove debug `error_log()` statement from v1.2.5.

= 1.2.5 =
* Finish text localization, include `es_ES` translation.
* Pack and compress validation javascript.
* Bug Fix: prevent PHP array index notice for non-repeater fields.
* Code formatting.

= 1.2.3 =
* Support for globally bypassing Draft/Preview validation by setting `ACF_VF_DRAFTS` to `false`.
* Support for bypassing Draft/Preview validation per field (defaults to validate).
* Bug fixes: properly hide Draft spinner, cleaned up JavaScript.

= 1.2.2 =
* Properly include plugin version number on JavaScript enqueue for caching and PHP notices.
* Use minified JavaScript unless `ACF_VF_DEBUG` is set to `true`.
* Tested up to WordPress 3.9.1

= 1.2.1 =
* Show 'Validation Failed' message in header as needed.
* Mark form as dirty when input element values change.
* Fix return of `$message` from field configuration to UI.

= 1.2 =
* Support for [Repeater Field](http://www.advancedcustomfields.com/add-ons/repeater-field/) Validated Fields.
* Support for debugging with `ACF_VF_DEBUG` constant.
* Clean up variable names, more code standardization.
* Better handling of required fields with validation.

= 1.1.1.1 =
* Remove debug `error_log()` statement from v1.1.1.

= 1.1.1 =
* Clean up PHP to WordPress standards.
* Fix PHP Notice breaking AJAX call.
* Use defaults to prevent invalid array indexes.
* Update JavaScript for UI Errors.
* More localization prep for text.

= 1.1 = 
* Add Read-only functionality (beta).
* Use standard ACF error/messaging.
* Correctly process "preview" clicks, fixes error where the post would be published.
* Register CSS only in required locations.
* Properly apply subfield filters for `acf/load_value/type=`, `acf/update_value/type=`, `acf/format_value/type=`, `acf/format_value_for_api/type=`, `acf/load_field/type=`, `acf/update_field/type=`.
* Tested up to WordPress 3.9.

= 1.0.7 =
* Critical bug fix for selecting Validated Field type.

= 1.0.6 =
* Bug fix `$sub_field` properties not saving (use `acf/create_field_options` action).
* Bug fix multiple Validated Fields in a set - correct to always use unique selectors.
* Allow for unique query to be run on selected post statuses.
* Set default statuses included in unique queries with filter of `acf_vf/unique_statuses`.
* Remove redundant table wrapper on validated fields.
* Clean up potential strict PHP warnings.

= 1.0.5 =
* Hide spinner for update if a validation error is encountered.
* Allow for uniqueness queries to apply to only published or all post statuses.
* Clean up debugging code writing to the error log for regex validations.

= 1.0.4 =
* Fix javascript error when including ace.js, props @nikademo.
* Fix "Undefined index" PHP notice, props @ikivanov.

= 1.0.3 =
* Bug fix for unique field values per `post_type`. Props @ikivanov.

= 1.0.2 =
* Bug fix for editing a validated field. Ensure proper type is selected and UI refresh is triggered. Props @fab4_33.

= 1.0.1 =
* Clean up strict warnings

= 1.0 =
* Update for compatibility with Advanced Custom Fields 4+
* Implement ace.js for syntax highlighting

= 0.1 =
* Initial version.