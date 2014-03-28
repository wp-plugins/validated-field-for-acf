=== Plugin Name ===
Contributors: doublesharp
Tags: acf, advanced custom fields, validation, validate, regex, php, mask, input
Requires at least: 3.0.1
Tested up to: 3.8.1
Stable tag: 1.0.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add-on field for Advanced Custom Fields 4+ that provides input masking and validation of other field types.

== Description ==

The Validated Field add-on for [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/) *version 4+* provides a wrapper for other 
input types which allows you to provide client side input masking using the jQuery [Masked Input Plugin](http://digitalbush.com/projects/masked-input-plugin/), server side validation using either
PHP regular expressions or PHP code, as well as the option of ensuring a field's uniqueness by post type &amp; key, post type, or side wide.

== Installation ==

1. Download the plugin and extract to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure validated fields within the Advanced Custom Fields menus

Please note that this plugin requires [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/) and only works with version 4 or greater.

== Frequently Asked Questions ==

= I've activated the Validated Fields plugin, but nothing happens =

Ensure that you have [Advanced Custom Fields](http://wordpress.org/extend/plugins/advanced-custom-fields/) installed, and that it is activated. Validated Field should appear as a new input type.


== Screenshots ==

1. Example configuration for a validate telephone number field. 

2. Example of a conflict - the same telephone number already exists, with a link to the existing record.

3. Example of PHP validation - checking the length of a field’s value.

4. Example of PHP validation failure with the error message raised to the UI.

== Changelog ==

= 1.0.5 =
* Hide spinner for update if a validation error is encountered.
* Allow for uniqueness queries to apply to only published or all post statuses.
* Clean up debugging code writing to the error log for regex validations.

= 1.0.4 =
* Fix javascript error when including ace.js, props @nikademo.
* Fix “Undefined index” PHP notice, props @ikivanov.

= 1.0.3 =
* Bug fix for unique field values per post_type. Props @ikivanov.

= 1.0.2 =
* Bug fix for editing a validated field. Ensure proper type is selected and UI refresh is triggered. Props @fab4_33.

= 1.0.1 =
* Clean up strict warnings

= 1.0 =
* Update for compatibility with Advanced Custom Fields 4+
* Implement ace.js for syntax highlighting

= 0.1 =
* Initial version.