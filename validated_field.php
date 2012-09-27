<?php
/*
Plugin Name: Validated Field for ACF
Plugin URI: http://www.doublesharp.com/
Description: Server side validation and input masking for the Advanced Custom Fields plugin
Author: Justin Silver
Version: 0.1
Author URI: http://doublesharp.com/
*/

// make sure the acf_Field class has been loaded so that we can define our class
include_once(plugin_dir_path(__File__).'../advanced-custom-fields/core/fields/acf_field.php');

if (class_exists("acf_Field") && !class_exists("Validated_Field")):
class Validated_Field extends acf_Field {
	/*--------------------------------------------------------------------------------------
	 *
	*	Constructor
	*
	*	@author Justin Silver
	*
	*-------------------------------------------------------------------------------------*/

	function __construct($parent) {
		parent::__construct($parent);
			
		$this->name = 'validated_field';
		$this->title = __("Validated Field",'acf');

		add_action("wp_ajax_validate_fields", array(&$this, "ajax_validate_fields") );
	}
	
	function valid_date($date){
		if (preg_match("/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/", $date, $matches)) {
			if (checkdate($matches[2], $matches[3], $matches[1])) {
				return true;
			}
		}
	
		return false;
	}
	
	function ajax_validate_fields() {
		global $wpdb;
		$post_id = $_REQUEST['post_id'];
		$post_type = get_post_type( $post_id );
		$fields = $_REQUEST['fields'];
		if (!is_array($fields)) $fields = array();
		$flds = array();

		header('HTTP/1.1 200 OK'); 				// be positive
		foreach ($fields as $field){ 			// loop through each field
			$vld = true; 						// wait for a any test to fail
			$key = substr($field['id'], 7, -1); // key the acf key from the field id/name
			$val = $field['value']; 			// get the submitted value
			$fld = $this->parent->get_acf_field($key); // load the field config
			$func = $fld['function']; 			// what type of validation?
			$ptrn = $fld['pattern']; 			// string to use for validation
			if (!empty($func) && !empty($ptrn)){
				// only run these checks if we have a pattern
				switch ($func){
					case 'regex': 				// check for any matches to the regular expression
						if (!preg_match("/".str_replace("/", "\/", $ptrn)."/",$val)){
							$vld = false; 		// return false if there are no matches
						}
						break;
					case 'sql': 				// todo: sql checks?
						break;
					case 'php': 				// this code is a little tricky, one bad eval() can break the lot. needs a nonce.
						$message = $fld['message'];
						$prev_value = get_post_meta($post_id, $fld['name'], true);
											
						// it gets tricky but we are trying to account for an capture bad php code where possible
						$ptrn = trim($ptrn);
						if (substr($ptrn, -1)!=";") $ptrn.=";";
						$php.= 'function validate_php_function($post_id, $post_type, $name, $value, $prev_value, &$message) { ';
						$php.= '	try { ';
						$php.= '		return eval(\'' . addslashes($ptrn) . '\'); ';
						$php.= '	} catch (Exception $e){ ';
						$php.= '		$message = "Error: ".$e->getMessage(); return false; ';
						$php.= '	} ';
						$php.= '} ';
						$php.= '$vld = validate_php_function('.$post_id.', "'.$post_type.'", "'.$fld['name'].'", "'.addslashes($val).'", "'.addslashes($prev_value).'", $message); ';
						
						// run the eval() in the eval()
						if(@eval($php)!==true){
							$error = error_get_last();
							// check to see if this is our error or not.
							if (strpos($error['file'], "validated_field.php") && strpos($error['file'], "eval()'d code") ){
								$vld = false;
								$message = $error['message'];
							} 
						}
						$fld['message'] = $message;
						break;
				}
			} elseif (!empty($func)&&$func!="none") {
				$vld = false;
				$fld['message'] = __('This field\'s validation is not properly configured.', 'acf');
			}
				
			$unq = $fld['unique'];
			if ($vld && !empty($unq) && $unq!='non-unique'){
				$sql_prefix = "select meta_id, post_id, p.post_title from {$wpdb->postmeta} pm join {$wpdb->posts} p on p.ID = pm.post_id and p.post_status = 'publish'";
				switch ($unq){
					case 'global': 
						// check to see if this value exists anywhere in the postmeta table
						$sql = $wpdb->prepare("{$sql_prefix} where post_id != %d and meta_value = %s", $post_id, $val);
						break;
					case 'post_type':
						// check to see if this value exists in the postmeta table with this $post_id
						$sql = $wpdb->prepare("{$sql_prefix} and p.post_type = %s where post_id != %d meta_value = %s", $post_type, $post_id, $val);
						break;
					case 'post_key':
						// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
						$sql = $wpdb->prepare("{$sql_prefix} and p.post_type = %s where post_id != %d and meta_key = %s and meta_value = %s", $post_type, $post_id, $fld['name'], $val);
						break;
					default:
						// no dice, set $sql to null
						$sql = null;
						break;
				}
				// Only run if we hit a condition above
				if (!empty($sql)){
					// Execute the SQL
					$rows = $wpdb->get_results($sql);
					if (count($rows)>0){
						// We got some matches, but there might be more than one so we need to concatenate the collisions
						$conflicts = "";
						foreach ($rows as $row){
							$conflicts .= "<a href='/wp-admin/post.php?post={$row->post_id}&action=edit'>{$row->post_title}</a>";
							if ($row!==end($rows)) $conflicts.= ', ';
						}
						$fld['message'] = "This value '{$val}' is already in use by {$conflicts}.";
						$vld = false;
					}
				}
			}
			
			// Check to see if the validation was successful, if not return the error message
			if(empty($fld['message'])) $fld['message'] = 'Validation failed.';
			$fld['id'] = $field['id'];
			$fld['valid'] = $vld;
			$flds[]=$fld;
		}
		
		// Send the results back to the browser as JSON
		echo htmlentities(json_encode($flds), ENT_NOQUOTES, 'UTF-8');
		die();
	}

	// Add necessary javascript files to the header (admin only)
	function admin_print_scripts() {
		// Register our custom scripts
		wp_register_script('validated-field', plugin_dir_url(__FILE__).'/js/validated_field.js', array('jquery'));
		wp_register_script('jquery-masking', plugin_dir_url(__FILE__).'/js/jquery.maskedinput-1.3.min.js', array('jquery'));

		// Enqueue scripts
		wp_enqueue_script(array(
				'jquery',
				'jquery-ui-core',
				'jquery-ui-tabs',
				'jquery-masking',
				'validated-field',
		));
	}

	// Add necessary CSS files to the header (admin only)
	function admin_print_styles() {
		wp_enqueue_style('validated-field', plugin_dir_url(__FILE__) . '/css/validated_field.css');
	}

	function create_field($field) {
		// vars
		$sub_field = isset($field['sub_field']) ? $field['sub_field'] : array();
		$sub_value = isset($sub_field['default_value']) ? $sub_field['default_value'] : '';
		?>
		<div class="validated-field">
			<div class='validation-errors'></div>
			<table class="widefat">
				<thead>
					<tr>
						<th class="<?php echo $field['name']; ?>" style="width: 95%;"><span><?php echo $field['label']; ?></span></th>
					</tr>
				</thead>
				<tbody>
					<tr class="row">
						<td><?php 
						$sub_field = $this->setup_sub_field($field);
						$this->parent->fields[$sub_field['type']]->create_field($sub_field);
						?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		if(!empty($field['mask'])) { ?>
			<script type="text/javascript">
				jQuery(function($){
				   $('[name="<?php echo str_replace('[', '\\\\[', str_replace(']', '\\\\]', $field['name'])); ?>"]').mask('<?php echo $field['mask']?>');
				});
			</script>
		<?php
		}
	}

	function create_options($key, $field) {
		$fields_names = array();
		$sub_field = isset($field['sub_field']) ? $field['sub_field'] : array();

		foreach($this->parent->fields as $f) {
			$fields_names[$f->name] = $f->title;
		}

		?>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e("Validated Field",'acf'); ?> </label>
			<script type="text/javascript">
				jQuery(function($){
					$('#fields\\[<?php echo $key; ?>\\]\\[function\\]').change(function(){
						$('#validated-field-<?php echo $key; ?>-info div').hide();
						$('#validated-field-<?php echo $key; ?>-info .'+$(this).val()).show();
						if ($(this).val()!='none'){
							$('.field_option_<?php echo $this->name; ?>_validation').show();
						} else {
							$('.field_option_<?php echo $this->name; ?>_validation').hide();
						}
					});
					$('#fields\\[<?php echo $key; ?>\\]\\[function\\]').trigger('change');
				});
			</script>
			</td>
			<td>
				<div class="sub-field">
					<div class="fields">
						<div class="field sub_field">
							<div class="field_form">
								<table class="acf_input widefat">
									<tbody>
										<tr class="field_type">
											<td class="label"><label><span class="required">*</span> <?php _e("Field Type",'acf'); ?>
											</label></td>
											<td><?php 
											$this->parent->create_field(array(
													'type'	=>	'select',
													'name'	=>	'fields['.$key.'][sub_field][type]',
													'value'	=>	$sub_field['type'],
													'class'	=>	'type',
													'choices' => $fields_names
											));
											?>
											</td>
										</tr>
										<?php 
										if (isset($this->parent->fields[$sub_field['type']])){
											$this->parent->fields[$sub_field['type']]->create_options($key.'][sub_field', $sub_field);
										}
	
										?>
										<tr class="field_save">
											<td class="label">
												<!-- <label><?php _e("Save Field",'acf'); ?></label> -->
											</td>
											<td></td>
										</tr>
									</tbody>
								</table>
							</div>
							<!-- End Form -->
						</div>
					</div>
				</div>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e("Input Mask",'acf'); ?> </label>
			</td>
			<td><?php _e("Use 'a' to match A-Za-z, '9' to match 0-9, and '*' to match any alphanumeric. <a href='http://digitalbush.com/projects/masked-input-plugin/' target='_new'>More info.</a>",'acf'); ?><br />
				<?php 
				$this->parent->create_field(array(
						'type'	=>	'text',
						'name'	=>	'fields['.$key.'][mask]',
						'value'	=>	$field['mask']
				));
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e("Validation Function",'acf'); ?> </label>
			</td>
			<td><?php _e("How should the field be server side validated?",'acf'); ?><br />
				<?php 
				$choices = array (
						"none"=>"None",
						"regex"=>"Regular Expression",
						//"sql"=>"SQL Query",
						"php"=>"PHP Statement",
				);
				$this->parent->create_field(array(
						'type'	=>	'select',
						'name'	=>	'fields['.$key.'][function]',
						'value'	=>	$field['function'],
						'choices' => array($choices),
						'optgroup' => true,
						'multiple'	=>	'0',
				));
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation">
			<td class="label"><label><?php _e("Validation Pattern",'acf'); ?> </label>
			</td>
			<td>
				<div id="validated-field-<?php echo $key; ?>-info">
					<div class='regex'>
						<?php _e("Pattern match the input using <a href='http://php.net/manual/en/function.preg-match.php' target='_new'>PHP preg_match()</a>.",'acf'); ?>
						<br />
					</div>
					<div class='php'>
						<?php _e("Use any PHP code and return true or false."); ?><br/>
						<?php _e("Available variables - <b>\$post_id</b>, <b>\$post_type</b>, <b>\$name</b>, <b>\$value</b>, <b>\$prev_value</b>, <b>&amp;\$message</b> (returned to UI)."); ?><br/>
						<code><?php _e("if (empty(\$value)){ \$message=sprint_f(\$message, get_current_user()->user_login); return false; } else { return true; }",'acf'); ?></code><br/>
						<br />
					</div>
					<div class='sql'>
						<?php _e("SQL.",'acf'); ?>
						<br />
					</div>
				</div> 
				<?php 
				$this->parent->create_field(array(
						'type'	=>	'text',
						'name'	=>	'fields['.$key.'][pattern]',
						'value'	=>	$field['pattern'],
				));
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation">
			<td class="label"><label><?php _e("Error Message",'acf'); ?> </label>
			</td>
			<td><?php 
			$this->parent->create_field(array(
					'type'	=>	'text',
					'name'	=>	'fields['.$key.'][message]',
					'value'	=>	$field['message'],
			));
			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e("Unique Value?",'acf'); ?> </label>
			</td>
			<td><?php 
			$choices = array (
					"non-unique"=>"Non-Unique Value",
					"global"=>"Unique Globally",
					"post_type"=>"Unique For Post Type",
					"post_key"=>"Unique For Post Type -> Key",
			);
			$this->parent->create_field(array(
					'type'	=>	'select',
					'name'	=>	'fields['.$key.'][unique]',
					'value'	=>	$field['unique'],
					'choices' => $choices,
					'optgroup' => false,
					'multiple'	=>	'0',
			));
			?>
			</td>
		</tr>
		<?php
	}

	function pre_save_field($field) {
		return $field;
	}

	function update_value($post_id, $field, $value) {
		$sub_field = $this->setup_sub_field($field);
		return $this->parent->fields[$field['sub_field']['type']]->update_value($post_id, $sub_field, $value);
	}

	function get_value($post_id, $field) {
		$sub_field = $this->setup_sub_field($field);
		return $this->parent->fields[$field['sub_field']['type']]->get_value($post_id, $sub_field);
	}

	function get_value_for_api($post_id, $field) {
		$sub_field = $this->setup_sub_field($field);
		return $this->parent->fields[$field['sub_field']['type']]->get_value_for_api($post_id, $sub_field);
	}

	function setup_sub_field($field){
		$sub_field = $field['sub_field'];
		$sub_field['key'] = $field['key'];
		$sub_field['name'] = $field['name'];
		$sub_field['value'] = $field['value'];
		return $sub_field;
	}
}
endif;

// Load the add-on field once the plugins have loaded, but before init (this is when ACF registers the fields)
if (!function_exists("register_acf_validated_field")):
function register_acf_validated_field(){
	if(function_exists('register_field') && class_exists("Validated_Field")):
		register_field('Validated_Field', dirname(__File__) . '/validated_field.php');
	endif;
}
add_action("plugins_loaded", 'register_acf_validated_field');
endif;
?>
