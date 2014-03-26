<?php
if (class_exists("acf_Field") && !class_exists("acf_field_validated_field")):
class acf_field_validated_field extends acf_field{
	// vars
	var $settings, // will hold info such as dir / path
		$defaults; // will hold default field options

	/*
	*  __construct
	*
	*  Set name / label needed for actions / filters
	*
	*  @since	3.6
	*  @date	23/01/13
	*/
	function __construct(){
		// vars
		$this->name = 'validated_field';
		$this->label = __('Validated Field');
		$this->category = __("Basic",'acf'); // Basic, Content, Choice, etc
		$this->defaults = array(
			// add default here to merge into your field. 
			'function' => 'none',
			'pattern' => '',
		);

		// do not delete!
    	parent::__construct();
    	
    	// settings
		$this->settings = array(
			'path' => apply_filters('acf/helpers/get_path', __FILE__),
			'dir' => apply_filters('acf/helpers/get_dir', __FILE__),
			'version' => '1.0.0'
		);

		add_action( 'wp_ajax_validate_fields', array(&$this, 'ajax_validate_fields') );
		add_action( 'admin_head', array(&$this, 'input_admin_head') );
	}

	function setup_sub_field($field){
		$sub_field = $field['sub_field'];
		$sub_field['key'] = isset( $field['key'] )? $field['key'] : '';
		$sub_field['name'] = isset( $field['name'] )? $field['name'] : '';
		$sub_field['value'] = isset( $field['value'] )? $field['value'] : '';
		return $sub_field;
	}
	
	function valid_date($date){
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/', $date, $matches)) {
			if (checkdate($matches[2], $matches[3], $matches[1])) {
				return true;
			}
		}
	
		return false;
	}
	
	function ajax_validate_fields() {
		global $wpdb;
		$post_id = isset( $_REQUEST['post_id'] )? $_REQUEST['post_id'] : 0 ;
		$post_type = get_post_type( $post_id );
		$fields = isset( $_REQUEST['fields'] )? $_REQUEST['fields'] : array();
		if (!is_array($fields)) $fields = array();
		$flds = array();

		header('HTTP/1.1 200 OK'); 				// be positive
		foreach ($fields as $field){ 			// loop through each field
			$val = $field['value']; 			// get the submitted value
			if ( $val == null )	continue;		// we don't process empty values, the required checkbox handles that
			$vld = true; 						// wait for a any test to fail
			$key = substr($field['id'], 7, -1); // key the acf key from the field id/name
			$fld = get_field_object($key, $post_id); // load the field config
			$func = $fld['function']; 			// what type of validation?
			$ptrn = $fld['pattern']; 			// string to use for validation
			if (!empty($func) && !empty($ptrn)){
				// only run these checks if we have a pattern
				switch ($func){
					case 'regex': 				// check for any matches to the regular expression
						$ptrn_fltr = "/".str_replace("/", "\/", $ptrn)."/";
						error_log($ptrn_fltr . " " . $val);
						if (!preg_match($ptrn_fltr,$val)){
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
						//$ptrn = str_replace(array("\n","\r"), "", $ptrn);
						if (substr($ptrn, -1)!=";") $ptrn.=";";
						$php.= 'function validate_php_function($post_id, $post_type, $name, $value, $prev_value, &$message) { '."\n";
						$php.= '	try { '."\n";
						$php.= '		$code = \'' . str_replace("'", "\'", $ptrn . ' return true;' ) . '\';'."\n";
						$php.= '		return eval($code); '."\n";
						$php.= '	} catch (Exception $e){ '."\n";
						$php.= '		$message = "Error: ".$e->getMessage(); return false; '."\n";
						$php.= '	} '."\n";
						$php.= '} '."\n";
						$php.= '$vld = validate_php_function('.$post_id.', "'.$post_type.'", "'.$fld['name'].'", "'.addslashes($val).'", "'.addslashes($prev_value).'", $message); '."\n";
						
						// run the eval() in the eval()
						if(eval($php)!==true){
							$error = error_get_last();	
							// check to see if this is our error or not.
							if (strpos($error['file'], "validated_field_v4.php") && strpos($error['file'], "eval()'d code") ){
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
						$sql = $wpdb->prepare("{$sql_prefix} and p.post_type = %s where post_id != %d and meta_value = %s", $post_type, $post_id, $val);
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

	/*
	*  create_options()
	*
	*  Create extra options for your field. This is rendered when editing a field.
	*  The value of $field['name'] can be used (like bellow) to save extra data to the $field
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field	- an array holding all the field's data
	*/
	function create_options( $field ){
		// defaults?
		$field = array_merge($this->defaults, $field);

		// key is needed in the field names to correctly save the data
		$key = $field['name'];

		$sub_field = isset($field['sub_field']) ? $field['sub_field'] : array();

		// get all of the registered fields for the sub type drop down
		$fields_names = apply_filters('acf/registered_fields', array());

		// remove types that don't jive well with this one
		unset( $fields_names[ __("Layout",'acf') ] );
		unset( $fields_names[ "Basic" ][ "validated_field" ] );

		?>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e("Validated Field",'acf'); ?> </label>
			<script type="text/javascript">
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

											do_action('acf/create_field', array(
												'type'	=>	'select',
												'name'	=>	'fields[' . $key . '][sub_field][type]',
												'value'	=>	isset($sub_field['type'])? $sub_field['type'] : "",
												'class'	=>	'type',
												'choices' => $fields_names
											));

											?>
											</td>
										</tr>
										<?php 
										if (isset($sub_field['type']) && isset($this->parent->fields[$sub_field['type']])){
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

				do_action('acf/create_field', array(
					'type'	=>	'text',
					'name'	=>	'fields[' . $key . '][mask]',
					'value'	=>	isset($field['mask'])? $field['mask'] : ""
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

				do_action('acf/create_field', array(
					'type'	=>	'select',
					'name'	=>	'fields[' . $key . '][function]',
					'value'	=>	$field['function'],
					'choices' => array($choices),
					'optgroup' => true,
					'multiple'	=>	'0',
					'class' => 'validated_select',
				));
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation">
			<td class="label"><label><?php _e("Validation Pattern",'acf'); ?> </label>
			</td>
			<td>
				<div id="validated-<?php echo $key; ?>-info">
					<div class='validation-type regex'>
						<?php _e("Pattern match the input using <a href='http://php.net/manual/en/function.preg-match.php' target='_new'>PHP preg_match()</a>.",'acf'); ?>
						<br />
					</div>
					<div class='validation-type php'>
						<ul>
							<li><?php _e("Use any PHP code and return true or false. If nothing is returned it will evaluate to true.",'acf'); ?></li>
							<li><?php _e("Available variables - <b>\$post_id</b>, <b>\$post_type</b>, <b>\$name</b>, <b>\$value</b>, <b>\$prev_value</b>, <b>&amp;\$message</b> (returned to UI).",'acf'); ?></li>
							<li><?php _e("Example",'acf'); ?>: <code><?php _e("if (empty(\$value)){ \$message=sprint_f(\$message, get_current_user()->user_login); return false; }",'acf'); ?></code></li>
						</ul>
					</div>
					<div class='validation-type sql'>
						<?php _e("SQL.",'acf'); ?>
						<br />
					</div>
				</div> 
				<?php

				do_action('acf/create_field', array(
					'type'	=>	'textarea',
					'name'	=>	'fields['.$key.'][pattern]',
					'value'	=>	$field['pattern'],
					'class' => 'editor'					 
				)); 
				?>
				<div id="acf-field-<?php echo $key; ?>_editor" style="height:200px;"><?php echo $field['pattern']; ?></div>

			<script type="text/javascript">
			jQuery(document).ready(function(){
				jQuery("#acf-field-<?php echo $key; ?>_pattern").hide();
				var editor = ace.edit("acf-field-<?php echo $key; ?>_editor");
			    editor.setTheme("ace/theme/monokai");
			    editor.getSession().setMode("ace/mode/text");
			    editor.getSession().on('change', function(e){
			    	var val = editor.getValue();
			    	var func = jQuery('#acf-field-<?php echo $key; ?>_function').val();
			    	if (func=='php'){
			    		val = val.substr(val.indexOf('\n')+1);
			    	} else if (func=='regex'){
			    		if (val.indexOf('\n')>0){
			    			editor.setValue(val.trim().split('\n')[0]);
			    		}
			    	}
			    	jQuery("#acf-field-<?php echo $key; ?>_pattern").val(val);
			    });
			    jQuery("#acf-field-<?php echo $key; ?>_editor").data('editor', editor);

				jQuery('#acf-field-<?php echo $key; ?>_function').on('change',function(){
					jQuery('#validated-<?php echo $key; ?>-info div').hide();
					jQuery('#validated-<?php echo $key; ?>-info div.'+jQuery(this).val()).show();
					if (jQuery(this).val()!='none'){
						jQuery('.field_option_<?php echo $this->name; ?>_validation').show();
					} else {
						jQuery('.field_option_<?php echo $this->name; ?>_validation').hide();
					}
					var sPhp = '<'+'?'+'php';
					var editor = jQuery('#acf-field-<?php echo $key; ?>_editor').data('editor');
			    	var val = editor.getValue();
					if (jQuery(this).val()=='php'){
						if (val.indexOf(sPhp)!=0){
							editor.setValue(sPhp +'\n' + val);
						}
		    			editor.getSession().setMode("ace/mode/php");
		    			jQuery("#acf-field-<?php echo $key; ?>_editor").css('height','200px');
					} else {
						if (val.indexOf(sPhp)==0){
							editor.setValue(val.substr(val.indexOf('\n')+1));
						}
		    			editor.getSession().setMode("ace/mode/text");
		    			jQuery("#acf-field-<?php echo $key; ?>_editor").css('height','18px');
					}
		    		editor.resize()
		    		editor.gotoLine(1, 1, false);
				});

				// update function ui
				jQuery('#acf-field-<?php echo $key; ?>_function').trigger('change');
				// update sub field type ui
				jQuery('#acf-field-<?php echo $key; ?>_sub_field_type').trigger('change');
			});
			</script>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation">
			<td class="label"><label><?php _e("Error Message",'acf'); ?> </label>
			</td>
			<td><?php 

			do_action('acf/create_field', array(
				'type'	=>	'text',
				'name'	=>	'fields['.$key.'][message]',
				'value'	=>	isset($field['message'])? $field['message'] : ""
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

			do_action('acf/create_field', array(
				'type'	=>	'select',
				'name'	=>	'fields[' . $key . '][unique]',
				'value'	=>	isset($field['unique'])? $field['unique'] : "",
				'choices' => array($choices),
				'optgroup' => false,
				'multiple'	=>	'0',
				'class' => 'validated-select',
			));
			?>
			</td>
		</tr>
		<?php
	}

	/*
	*  create_field()
	*
	*  Create the HTML interface for your field
	*
	*  @param	$field - an array holding all the field's data
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function create_field( $field ){
		// defaults?
		$field = array_merge($this->defaults, $field);

		// create Field HTML
		$sub_field = $this->setup_sub_field($field);
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
						do_action('acf/create_field', $sub_field);
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

	/*
	*  input_admin_enqueue_scripts()
	*
	*  This action is called in the admin_enqueue_scripts action on the edit screen where your field is created.
	*  Use this action to add css + javascript to assist your create_field() action.
	*
	*  $info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function input_admin_enqueue_scripts(){
		// register acf scripts
		wp_register_script( 'acf-validated_field', $this->settings['dir'] . 'js/input.js', array('acf-input'), $this->settings['version'] );
		wp_register_script( 'jquery-masking', $this->settings['dir'] . 'js/jquery.maskedinput.min.js', array('jquery'), $this->settings['version']);
		wp_register_script( 'sh-core', $this->settings['dir'] . 'js/shCore.js', array('acf-input'), $this->settings['version'] );
		wp_register_script( 'sh-autoloader', $this->settings['dir'] . 'js/shAutoloader.js', array('sh-core'), $this->settings['version']);
		
		// register CSS styles
		wp_register_style( 'acf-validated_field', $this->settings['dir'] . 'css/input.css', array('acf-input'), $this->settings['version'] ); 

		// enqueue scripts
		wp_enqueue_script(array(
			'jquery',
			'jquery-ui-core',
			'jquery-ui-tabs',
			'jquery-masking',
			'acf-validated_field'
		));

		// enqueue CSS styles
		wp_enqueue_style(array(
			'acf-validated_field',	
		));
	}

	/*
	*  input_admin_head()
	*
	*  This action is called in the admin_head action on the edit screen where your field is created.
	*  Use this action to add css and javascript to assist your create_field() action.
	*
	*  @info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_head
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function input_admin_head(){ }

	/*
	*  field_group_admin_enqueue_scripts()
	*
	*  This action is called in the admin_enqueue_scripts action on the edit screen where your field is edited.
	*  Use this action to add css + javascript to assist your create_field_options() action.
	*
	*  $info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function field_group_admin_enqueue_scripts(){
		// enqueue scripts
		wp_enqueue_script( 'ace-editor', '//cdnjs.cloudflare.com/ajax/libs/ace/1.1.3/ace.js', array(), $this->settings['version'] );
	}

	/*
	*  field_group_admin_head()
	*
	*  This action is called in the admin_head action on the edit screen where your field is edited.
	*  Use this action to add css and javascript to assist your create_field_options() action.
	*
	*  @info	http://codex.wordpress.org/Plugin_API/Action_Reference/admin_head
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*/
	function field_group_admin_head(){ }

	/*
	*  load_value()
	*
	*  This filter is appied to the $value after it is loaded from the db
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value - the value found in the database
	*  @param	$post_id - the $post_id from which the value was loaded from
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$value - the value to be saved in te database
	*/
	function load_value( $value, $post_id, $field ){
		// Note: This function can be removed if not used
		return $value;
	}

	/*
	*  update_value()
	*
	*  This filter is appied to the $value before it is updated in the db
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value - the value which will be saved in the database
	*  @param	$post_id - the $post_id of which the value will be saved
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$value - the modified value
	*/
	function update_value( $value, $post_id, $field ){
		// defaults?
		$field = array_merge($this->defaults, $field);

		$sub_field = $this->setup_sub_field($field);
		do_action('acf/update_value', $value, $post_id, $sub_field );

		return $value;
	}

	/*
	*  format_value()
	*
	*  This filter is appied to the $value after it is loaded from the db and before it is passed to the create_field action
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value	- the value which was loaded from the database
	*  @param	$post_id - the $post_id from which the value was loaded
	*  @param	$field	- the field array holding all the field options
	*
	*  @return	$value	- the modified value
	*/
	function format_value( $value, $post_id, $field ){
		// defaults?
		$field = array_merge($this->defaults, $field);

		// Note: This function can be removed if not used
		return $value;
	}

	/*
	*  format_value_for_api()
	*
	*  This filter is appied to the $value after it is loaded from the db and before it is passed back to the api functions such as the_field
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$value	- the value which was loaded from the database
	*  @param	$post_id - the $post_id from which the value was loaded
	*  @param	$field	- the field array holding all the field options
	*
	*  @return	$value	- the modified value
	*/
	function format_value_for_api( $value, $post_id, $field ){
		// defaults?
		$field = array_merge($this->defaults, $field);

		$sub_field = $this->setup_sub_field($field);

		$value = apply_filters('acf/format_value_for_api', $value, $post_id, $sub_field);

		return $value;
	}

	/*
	*  load_field()
	*
	*  This filter is appied to the $field after it is loaded from the database
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field - the field array holding all the field options
	*
	*  @return	$field - the field array holding all the field options
	*/
	function load_field( $field ){
		// Note: This function can be removed if not used
		return $field;
	}

	/*
	*  update_field()
	*
	*  This filter is appied to the $field before it is saved to the database
	*
	*  @type	filter
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field - the field array holding all the field options
	*  @param	$post_id - the field group ID (post_type = acf)
	*
	*  @return	$field - the modified field
	*/
	function update_field( $field, $post_id ){
		// Note: This function can be removed if not used
		return $field;
	}
}

new acf_field_validated_field();
endif;