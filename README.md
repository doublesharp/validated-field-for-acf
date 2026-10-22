# Validated Field for ACF

Validated Field adds validation and data-management tools to WordPress and Advanced Custom Fields. This is the public Free plugin repository.

The development build includes the original validated-field wrapper, masks, regex/PHP validation, uniqueness and read-only settings, plus native rules, authorized record services, opt-in native ACF tables, scalar inline editing, Quick Add and personal table columns.

## Development status

Version 2.0.0 requires WordPress 6.0+, PHP 8.0+ and ACF 6.0+. It is an unreleased development build. Current-runtime qualification does not establish minimum-runtime support or complete PHP branch coverage. Existing ACF settings and legacy field identities are retained.

ACF must be installed separately. The optional Validated Field Pro add-on extends these shared services with rich editing, advanced queries, workflows and background tools. Neither edition bundles ACF.

The plugin folder and WordPress.org slug remain `validated-field-for-acf`; the bootstrap is `validated_field.php`. Installing a Git checkout may include dormant legacy assets; release archives use reviewed manifests in the private development workspace.

[WordPress.org](https://wordpress.org/plugins/validated-field-for-acf/) | [Doublesharp](https://doublesharp.com/)

See `readme.txt` for the feature description and compatibility details. Licensed under GPL-2.0-or-later; see `LICENSE`.

The current ACF 6+ field adapter is `includes/class-acf-field-validated-field.php`. The old `validated_field_v4.php` stub and `validated_field_v5.php` filename have been removed. Field type names, saved settings and the WordPress plugin entry point remain unchanged.
