<?php
if ( class_exists( 'acf_Field' ) && ! class_exists( 'acf_field_validated_field' ) ):
class acf_field_validated_field extends acf_field {
	// vars
	var $settings,					// will hold info such as dir / path
		$defaults,					// will hold default field options
		$sub_defaults,				// will hold default sub field options
		$debug;						// if true, don't use minified and confirm form submit					

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
		$this->name		= 'validated_field';
		$this->label	= __( 'Validated Field', 'acf_vf' );
		$this->category	= __( 'Basic', 'acf' );
		$this->debug	= defined( 'ACF_VF_DEBUG' )? ACF_VF_DEBUG : false;
		$this->drafts	= defined( 'ACF_VF_DRAFTS' )? ACF_VF_DRAFTS : true;

		$this->defaults = array(
			'read_only' => false,
			'mask'		=> '',
			'function'	=> 'none',
			'pattern'	=> '',
			'message'	=>  __( 'Validation failed.', 'acf_vf' ),
			'unique'	=> 'non-unique',
			'unique_statuses' => apply_filters( 'acf_vf/unique_statuses', array( 'publish', 'future' ) ),
			'drafts'	=> true,
		);

		$this->sub_defaults = array(
			'type'		=> '',
			'key'		=> '',
			'name'		=> '',
			'_name'		=> '',
			'id'		=> '',
			'value'		=> '',
			'field_group' => '',
		);

		$this->input_defaults = array(
			'id'		=> '',
			'value'		=> '',
		);

		// do not delete!
		parent::__construct();

		// settings
		$this->settings = array(
			'path'		=> apply_filters( 'acf/helpers/get_path', __FILE__ ),
			'dir'		=> apply_filters( 'acf/helpers/get_dir', __FILE__ ),
			'version'	=> ACF_VF_VERSION,
		);

		add_action( 'wp_ajax_validate_fields', array( &$this, 'ajax_validate_fields' ) );
		add_action( 'admin_head', array( &$this, 'input_admin_head' ) );
	}

	function setup_field( $field ){
		// setup booleans, for compatibility
		$field['read_only'] = ( false == $field['read_only'] || 'false' === $field['read_only'] )? false : true;
		$field['drafts'] = ( false == $field['drafts'] || 'false' === $field['drafts'] )? false : true;
		return array_merge( $this->defaults, $field );
	}

	function setup_sub_field( $field ){
		$sub_field = isset( $field['sub_field'] )? 
			$field['sub_field'] :	// already set up
			array();				// create it
		// mask the sub field as the parent by giving it the same key values
		foreach( array( 'key', 'name', '_name', 'id', 'value', 'field_group' ) as $key ){
			$sub_field[$key] = isset( $field[$key] )? $field[$key] : '';
		}
		// make sure all the defaults are set
		return array_merge( $this->sub_defaults, $sub_field );
	}

	/*
	*  get_post_statuses()
	*
	*  Get the various post statuses that have been registered
	*
	*  @type		function
	*
	*/
	function get_post_statuses() {
		global $wp_post_statuses;
		return $wp_post_statuses;
	}

	/*
	*  ajax_validate_fields()
	*
	*  Parse the input when a page is submitted to determine if it is valid or not.
	*
	*  @type		ajax action
	*
	*/
	function ajax_validate_fields() {
		$post_id = isset( $_REQUEST['post_id'] )?				// the submitted post_id
			$_REQUEST['post_id'] : 
			0;
		$post_type = get_post_type( $post_id );					// the type of the submitted post

		$click_id =  isset( $_REQUEST['click_id'] )? 			// the ID of the clicked element, for drafts/publish
			$_REQUEST['click_id'] : 
			'publish';

		// the validated field inputs to process
		$inputs = ( isset( $_REQUEST['fields'] ) && is_array( $_REQUEST['fields'] ) )? 
			$_REQUEST['fields'] : 
			array();

		header( 'HTTP/1.1 200 OK' );							// be positive!
		$return_fields = array();								// JSON response to the client
		foreach ( $inputs as $i=>$input ){						// loop through each field
			// input defaults
			$input = array_merge( $this->input_defaults, $input );
			$valid = true;										// wait for a any test to fail

			// extract the field key
			preg_match( '/\\[([^\\]]*?)\\](\\[(\d*?)\\]\\[([^\\]]*?)\\])?/', $input['id'], $matches );
			$key = isset( $matches[1] )? $matches[1] : false;	// the key for this ACF
			$index = isset( $matches[3] )? $matches[3] : false;	// the field index, if it is a repeater
			$sub_key = isset( $matches[4] )? $matches[4] : false; // the key for the sub field, if it is a repeater

			// load the field config, set defaults
			$field = $this->setup_field( get_field_object( $key, $post_id ) );
			
			// if it's a repeater field, get the validated field so we can do meta queries...
			if ( $is_repeater = ( 'repeater' == $field['type'] && $index ) ){
				foreach ( $field['sub_fields'] as $repeater ){
					$repeater = $this->setup_field( $repeater );
					$sub_field = $this->setup_sub_field( $repeater );
					if ( $sub_key == $sub_field['key'] ){
						$parent_field = $field;					// we are going to map down a level, but track the top level field
						$field = $repeater;						// the '$field' should be the Validated Field
						break;
					}
					$sub_field = false;							// in case it isn't the right one
				}
			} else {
				// the wrapped field
				$sub_field = $this->setup_sub_field( $field );
			}

			$value = $input['value'];							// the submitted value
			if ( $field['required'] && empty( $value ) ){
				continue;										// let the required field handle it
			}

			if ( $click_id != 'publish' && !$field['drafts'] ){
				continue;										// we aren't publishing and we don't want to validate drafts
			}
			
			$function = $field['function'];						// what type of validation?
			$pattern = $field['pattern'];						// string to use for validation
			$message = $field['message'];						// failure message to return to the UI
			if ( ! empty( $function ) && ! empty( $pattern ) ){
				switch ( $function ){							// only run these checks if we have a pattern
					case 'regex':								// check for any matches to the regular expression
						$pattern_fltr = '/' . str_replace( "/", "\/", $pattern ) . '/';
						if ( ! preg_match( $pattern_fltr, $value ) ){
							$valid = false;						// return false if there are no matches
						}
						break;
					case 'sql':									// todo: sql checks?
						break;
					case 'php':									// this code is a little tricky, one bad eval() can break the lot. needs a nonce.
						$this_key = $field['name'];
						if ( $is_repeater ) $this_key .= '_' . $index . '_' . $sub_sub_field['name'];

						$message = $field['message'];
						$prev_value = get_post_meta( $post_id, $this_key, true );
						$function_name = 'validate_' . preg_replace( '~[\\[\\]]+~', '_', $input['id'] ) . 'function';
						
						// it gets tricky but we are trying to account for an capture bad php code where possible
						$pattern = trim( $pattern );
						if ( substr( $pattern, -1 ) != ';' ) $pattern.= ';';
						$php = 'function '.$function_name.'( $post_id, $post_type, $name, $value, $prev_value, &$message ) { '."\n";
						$php.= '	try { '."\n";
						$php.= '		$code = \'' . str_replace("'", "\'", $pattern . ' return true;' ) . '\';'."\n";
						$php.= '		return eval( $code ); '."\n";
						$php.= '	} catch ( Exception $e ){ '."\n";
						$php.= '		$message = "Error: ".$e->getMessage(); return false; '."\n";
						$php.= '	} '."\n";
						$php.= '} '."\n";
						$php.= '$valid = '.$function_name.'( '.$post_id.', "'.$post_type.'", "'.$this_key.'", "'.addslashes( $value ).'", "'.addslashes( $prev_value ).'", $message );'."\n";
						
						if ( true !== eval( $php ) ){			// run the eval() in the eval()
							$error = error_get_last();			// get the error from the eval() on failure
							// check to see if this is our error or not.
							if ( strpos( $error['file'], "validated_field_v4.php" ) && strpos( $error['file'], "eval()'d code" ) ){
								preg_match( '/eval\\(\\)\'d code\\((\d+)\\)/', $error['file'], $matches );
								$message = __( 'PHP Error', 'acf_vf' ) . ': ' . $error['message'] . ', line ' . $matches[1] . '.';
								$valid = false;
							} 
						}
						break;
				}
			} elseif ( ! empty( $function ) && $function != 'none' ) {
				$message = __( 'This field\'s validation is not properly configured.', 'acf_vf' );
				$valid = false;
			}
				
			$unique = $field['unique'];
			if ( $valid && ! empty( $unique ) && $unique != 'non-unique' ){
				global $wpdb;
				$status_in = "'" . implode( "','", $field['unique_statuses'] ) . "'";
				$sql_prefix = "SELECT meta_id, post_id, p.post_title FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND post_status IN ($status_in)";
				switch ( $unique ){
					case 'global': 
						// check to see if this value exists anywhere in the postmeta table
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND post_id != %d WHERE meta_value = %s", 
							$post_id, 
							$value
						);
						break;
					case 'post_type':
						// check to see if this value exists in the postmeta table with this $post_id
						$sql = $wpdb->prepare( 
							"{$sql_prefix} AND p.post_type = %s AND post_id != %d WHERE meta_value = %s", 
							$post_type, 
							$post_id, 
							$value 
						);
						break;
					case 'post_key':
						// check to see if this value exists in the postmeta table with both this $post_id and $meta_key
						if ( $is_repeater ){
							$this_key = $parent_field['name'] . '_' . $index . '_' . $field['name'];
							$meta_key = $parent_field['name'] . '_%_' . $field['name'];
							$sql = "{$sql_prefix} AND p.post_type = %s WHERE ( ( post_id = %d AND meta_key != %s AND meta_key LIKE %s ) OR ( post_id != %d AND meta_key LIKE %s ) ) AND meta_value = %s";
							$sql = $wpdb->prepare( 
								"{$sql_prefix} AND p.post_type = %s WHERE ( ( post_id = %d AND meta_key != %s AND meta_key LIKE %s ) OR ( post_id != %d AND meta_key LIKE %s ) ) AND meta_value = %s", 
								$post_type, 
								$post_id, 
								$this_key, 
								$meta_key, 
								$post_id, 
								$meta_key, 
								$value 
							);
						} else {
							$sql = $wpdb->prepare( 
								"{$sql_prefix} AND p.post_type = %s AND post_id != %d WHERE meta_key = %s AND meta_value = %s", 
								$post_type, 
								$post_id, 
								$field['name'], 
								$value 
							);
						}
						break;
					default:
						// no dice, set $sql to null
						$sql = null;
						break;
				}

				// Only run if we hit a condition above
				if ( ! empty( $sql ) ){
					// Execute the SQL
					$rows = $wpdb->get_results( $sql );
					if ( count( $rows ) ){
						// We got some matches, but there might be more than one so we need to concatenate the collisions
						$conflicts = "";
						foreach ( $rows as $row ){
							$conflicts .= "<a href='/wp-admin/post.php?post={$row->post_id}&action=edit' style='color:inherit;text-decoration:underline;'>{$row->post_title}</a>";
							if ( $row !== end( $rows ) ) $conflicts.= ', ';
						}
						$message = __( 'The value', 'acf_vf' ) . " '$value' " . __( 'is already in use by', 'acf_vf' ) . " {$conflicts}.";
						$valid = false;
					}
				}
			}

			$return_fields[] = array(
				'id'		=> $input['id'],
				'message'	=> ! $valid? ! empty( $message )? htmlentities( $message, ENT_NOQUOTES, 'UTF-8' ) : __( 'Validation failed.', 'acf_vf' ) : '',
				'valid'		=> $valid,
			);
		}
		
		// Send the results back to the browser as JSON
		echo json_encode( $return_fields, ( $this->debug )? JSON_PRETTY_PRINT : 0 );
		die();
	}

	/*
	*  create_options()
	*
	*  Create extra options for your field. This is rendered when editing a field.
	*  The value of $field['name'] can be used (like below) to save extra data to the $field
	*
	*  @type	action
	*  @since	3.6
	*  @date	23/01/13
	*
	*  @param	$field	- an array holding all the field's data
	*/
	function create_options( $field ){
		// defaults?
		$field = $this->setup_field( $field );

		// key is needed in the field names to correctly save the data
		$key = $field['name'];
		$html_key = preg_replace( '~[\\[\\]]+~', '_', $key );
		$sub_field = $this->setup_sub_field( $field );
		$sub_field['name'] = $key . '][sub_field';

		// get all of the registered fields for the sub type drop down
		$fields_names = apply_filters( 'acf/registered_fields', array() );

		// remove types that don't jive well with this one
		unset( $fields_names[__( 'Layout', 'acf' )] );
		unset( $fields_names[__( 'Basic', 'acf' )][ 'validated_field' ] );

		?>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_readonly" id="field_option_<?php echo $html_key; ?>_readonly">
			<td class="label"><label><?php _e( 'Read Only?', 'acf_vf' ); ?> </label>
			</td>
			<td><?php 
			do_action( 'acf/create_field', array(
				'type'	=> 'radio',
				'name'	=> 'fields['.$key.'][read_only]',
				'value'	=> ( false == $field['read_only'] || 'false' === $field['read_only'] )? 'false' : 'true',
				'choices' => array(
					'true'	=> __( 'Yes', 'acf_vf' ),
					'false' => __( 'No', 'acf_vf' ),
				),
				'class' => 'horizontal'
			));
			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_readonly" id="field_option_<?php echo $html_key; ?>_drafts">
			<td class="label"><label><?php _e( 'Validate Drafts/Preview?', 'acf_vf' ); ?> </label>
			</td>
			<td><?php 
			do_action( 'acf/create_field', array(
				'type'	=> 'radio',
				'name'	=> 'fields['.$key.'][drafts]',
				'value'	=> ( false == $field['drafts'] || 'false' === $field['drafts'] )? 'false' : 'true',
				'choices' => array(
					'true'	=> __( 'Yes', 'acf_vf' ),
					'false' => __( 'No', 'acf_vf' ),
				),
				'class' => 'horizontal'
			));

			if ( ! $this->drafts ){
				echo '<em>';
				_e( 'Warning', 'acf_vf' );
				echo ': <code>ACF_VF_DRAFTS</code> ';
				_e( 'has been set to <code>false</code> which overrides field level configurations', 'acf_vf' );
				echo '.</em>';
			}
			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?>">
			<td class="label"><label><?php _e( 'Validated Field', 'acf_vf' ); ?> </label>
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
											<td class="label"><label><span class="required">*</span> <?php _e( 'Field Type', 'acf' ); ?>
											</label></td>
											<td><?php
											// Create the drop down of field types
											do_action( 'acf/create_field', array(
												'type'	=> 'select',
												'name'	=> 'fields[' . $key . '][sub_field][type]',
												'value'	=> $sub_field['type'],
												'class'	=> 'type',
												'choices' => $fields_names
											));

											// Create the default sub field settings
											do_action( 'acf/create_field_options', $sub_field );
											?>
											</td>
										</tr>
										<tr class="field_save">
											<td class="label">
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
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Input Mask', 'acf_vf' ); ?> </label>
			</td>
			<td><?php _e( 'Use &#39;a&#39; to match A-Za-z, &#39;9&#39; to match 0-9, and &#39;*&#39; to match any alphanumeric.', 'acf_vf' ); ?> 
				<a href="http://digitalbush.com/projects/masked-input-plugin/" target="_new"><?php _e( 'More info.', 'acf_vf' ); ?></a><br />
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'text',
						'name'	=> 'fields[' . $key . '][mask]',
						'value'	=> $field['mask'],
					)
				);
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Validation Function', 'acf_vf' ); ?> </label>
			</td>
			<td><?php _e( "How should the field be server side validated?", 'acf_vf' ); ?><br />
				<?php 
				do_action( 'acf/create_field', 
					array(
						'type'	=> 'select',
						'name'	=> 'fields[' . $key . '][function]',
						'value'	=> $field['function'],
						'choices' => array(
							'none'	=> __( 'None', 'acf_vf' ),
							'regex' => __( 'Regular Expression', 'acf_vf' ),
							//'sql'	=> __( 'SQL Query', 'acf_vf' ),
							'php'	=> __( 'PHP Statement', 'acf_vf' ),
						),
						'optgroup' => true,
						'multiple' => '0',
						'class' => 'validated_select',
					)
				);
				?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_validation non_read_only" id="field_option_<?php echo $html_key; ?>_validation">
			<td class="label"><label><?php _e( 'Validation Pattern', 'acf_vf' ); ?></label>
			</td>
			<td>
				<div id="validated-<?php echo $html_key; ?>-info">
					<div class='validation-type regex'>
						<?php _e( 'Pattern match the input using', 'acf_vf' ); ?> <a href="http://php.net/manual/en/function.preg-match.php" target="_new">PHP preg_match()</a>.
						<br />
					</div>
					<div class='validation-type php'>
						<ul>
							<li><?php _e( "Use any PHP code and return true or false. If nothing is returned it will evaluate to true.", 'acf_vf' ); ?></li>
							<li><?php _e( 'Available variables', 'acf_vf' ); ?> - <b>$post_id</b>, <b>$post_type</b>, <b>$name</b>, <b>$value</b>, <b>$prev_value</b>, <b>&amp;$message</b> (<?php _e('returned to UI', 'acf_vf' ); ?>).</li>
							<li><?php _e( 'Example', 'acf_vf' ); ?>: <code>if ( empty( $value ) ){ $message = sprint_f( $message, get_current_user()->user_login ); return false; }</code></li>
						</ul>
					</div>
					<div class='validation-type sql'>
						<?php _e( 'SQL', 'acf_vf' ); ?>.
						<br />
					</div>
				</div> 
				<?php
				do_action( 'acf/create_field', array(
					'type'	=> 'textarea',
					'name'	=> 'fields['.$key.'][pattern]',
					'value'	=> $field['pattern'],
					'class' => 'editor'					 
				)); 
				?>
				<div id="acf-field-<?php echo $html_key; ?>_editor" style="height:200px;"><?php echo $field['pattern']; ?></div>

			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> field_option_<?php echo $this->name; ?>_message non_read_only" id="field_option_<?php echo $html_key; ?>_message">
			<td class="label"><label><?php _e( 'Error Message', 'acf_vf' ); ?></label>
			</td>
			<td><?php 
			do_action( 'acf/create_field', 
				array(
					'type'	=> 'text',
					'name'	=> 'fields['.$key.'][message]',
					'value'	=> $field['message'],
				)
			); 
			?>
			</td>
		</tr>
		<tr class="field_option field_option_<?php echo $this->name; ?> non_read_only">
			<td class="label"><label><?php _e( 'Unique Value?', 'acf_vf' ); ?> </label>
			</td>
			<td>
			<div id="validated-<?php echo $html_key; ?>-unique">
			<p><?php _e( 'Make sure this value is unique for...', 'acf_vf' ); ?><br/>
			<?php 

			do_action( 'acf/create_field', 
				array(
					'type'	=> 'select',
					'name'	=> 'fields[' . $key . '][unique]',
					'value'	=> $field['unique'],
					'choices' => array(
						'non-unique'=> __( 'Non-Unique Value', 'acf_vf' ),
						'global'	=> __( 'Unique Globally', 'acf_vf' ),
						'post_type'	=> __( 'Unique For Post Type', 'acf_vf' ),
						'post_key'	=> __( 'Unique For Post Type', 'acf_vf' ) . ' -&gt; ' . __( 'Key', 'acf_vf' ),
					),
					'optgroup'	=> false,
					'multiple'	=> '0',
					'class'		=> 'validated-select',
				)
			);
			?>
			</p>
			<div class="unique_statuses">
			<p><?php _e( 'Apply to which post statuses?', 'acf_vf'); ?><br/>
			<?php
			$statuses = $this->get_post_statuses();
			$choices = array();
			foreach ( $statuses as $value => $status ) {
				$choices[$value] = $status->label;
			}

			do_action( 'acf/create_field', 
				array(
					'type'	=> 'checkbox',
					'name'	=> 'fields['.$key.'][unique_statuses]',
					'value'	=> $field['unique_statuses'],
					'choices' => $choices,
				)
			); 
			?></p>
			</div>
			</div>
			<script type="text/javascript">
			jQuery(document).ready(function(){
				jQuery("#acf-field-<?php echo $html_key; ?>_pattern").hide();
				var editor = ace.edit("acf-field-<?php echo $html_key; ?>_editor");
				editor.setTheme("ace/theme/monokai");
				editor.getSession().setMode("ace/mode/text");
				editor.getSession().on('change', function(e){
					var val = editor.getValue();
					var func = jQuery('#acf-field-<?php echo $html_key; ?>_function').val();
					if (func=='php'){
						val = val.substr(val.indexOf('\n')+1);
					} else if (func=='regex'){
						if (val.indexOf('\n')>0){
							editor.setValue(val.trim().split('\n')[0]);
						}
					}
					jQuery("#acf-field-<?php echo $html_key; ?>_pattern").val(val);
				});
				jQuery("#acf-field-<?php echo $html_key; ?>_editor").data('editor', editor);

				jQuery('#acf-field-<?php echo $html_key; ?>_function').on('change',function(){
					jQuery('#validated-<?php echo $html_key; ?>-info div').hide(300);
					jQuery('#validated-<?php echo $html_key; ?>-info div.'+jQuery(this).val()).show(300);
					if (jQuery(this).val()!='none'){
						jQuery('#validated-<?php echo $html_key; ?>-info .field_option_<?php echo $this->name; ?>_validation').show();
					} else {
						jQuery('#validated-<?php echo $html_key; ?>-info .field_option_<?php echo $this->name; ?>_validation').hide();
					}
					var sPhp = '<'+'?'+'php';
					var editor = jQuery('#acf-field-<?php echo $html_key; ?>_editor').data('editor');
					var val = editor.getValue();
					if (jQuery(this).val()=='none'){
						jQuery('#field_option_<?php echo $html_key; ?>_validation, #field_option_<?php echo $html_key; ?>_message').hide(300);
					} else {
						if (jQuery(this).val()=='php'){
							if (val.indexOf(sPhp)!=0){
								editor.setValue(sPhp +'\n' + val);
							}
							editor.getSession().setMode("ace/mode/php");
							jQuery("#acf-field-<?php echo $html_key; ?>_editor").css('height','200px');
						} else {
							if (val.indexOf(sPhp)==0){
								editor.setValue(val.substr(val.indexOf('\n')+1));
							}
							editor.getSession().setMode("ace/mode/text");
							jQuery("#acf-field-<?php echo $html_key; ?>_editor").css('height','18px');
						}
						editor.resize()
						editor.gotoLine(1, 1, false);
						jQuery('#field_option_<?php echo $html_key; ?>_validation, #field_option_<?php echo $html_key; ?>_message').show(300);
					}
				});

				jQuery('#acf-field-<?php echo $html_key; ?>_unique').on('change',function(){
					var unqa = jQuery('#validated-<?php echo $html_key; ?>-unique .unique_statuses');
					var val = jQuery(this).val();
					if (val=='non-unique'||val=='') { unqa.hide(300); } else { unqa.show(300); }
				});

				// update ui
				jQuery('#acf-field-<?php echo $html_key; ?>_function').trigger('change');
				jQuery('#acf-field-<?php echo $html_key; ?>_unique').trigger('change');
				jQuery('#acf-field-<?php echo $html_key; ?>_sub_field_type').trigger('change');
			});
			</script>
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
		global $post, $pagenow;
		$is_new = $pagenow=='post-new.php';
		$field = $this->setup_field( $field );
		$sub_field = $this->setup_sub_field( $field );
		?>
		<div class="validated-field">
			<?php
			if ( $field['read_only'] ){
				?>
				<p><?php 
				ob_start();
				do_action( 'acf/create_field', $sub_field ); 
				$contents = ob_get_contents();
				$contents = preg_replace("~<(input|textarea|select)~", "<\${1} disabled=true readonly", $contents );
				ob_end_clean();
				echo $contents;
				?></p>
				<?php
			} else {
				do_action( 'acf/create_field', $sub_field ); 
			}
			?>
		</div>
		<?php
		if( !$is_new && isset( $sub_field['read_only'] ) && !$sub_field['read_only'] && ! empty( $field['mask'] ) ) { ?>
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
		$min = ( ! $this->debug )? '.min' : '';
		wp_register_script( 'acf-validated_field', $this->settings['dir'] . "js/input{$min}.js", array( 'acf-input' ), $this->settings['version'] );
		wp_register_script( 'jquery-masking', $this->settings['dir'] . "js/jquery.maskedinput{$min}.js", array( 'jquery' ), $this->settings['version']);
		wp_register_script( 'sh-core', $this->settings['dir'] . 'js/shCore.js', array( 'acf-input' ), $this->settings['version'] );
		wp_register_script( 'sh-autoloader', $this->settings['dir'] . 'js/shAutoloader.js', array( 'sh-core' ), $this->settings['version']);
		
		// enqueue scripts
		wp_enqueue_script( array(
			'jquery',
			'jquery-ui-core',
			'jquery-ui-tabs',
			'jquery-masking',
			'acf-validated_field',
		));
		if ( $this->debug ){ 
			add_action( 'admin_head', array( &$this, 'debug_admin_head' ), 20 );
		}
		if ( ! $this->drafts ){ 
			add_action( 'admin_head', array( &$this, 'drafts_admin_head' ), 20 );
		}
	}

	function debug_admin_head(){
		// set debugging for javascript
		echo '<script type="text/javascript">vf.debug=true;</script>';
	}

	function drafts_admin_head(){
		// don't validate drafts for anything
		echo '<script type="text/javascript">vf.drafts=false;</script>';
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
	function input_admin_head(){
		wp_enqueue_style( 'font-awesome', '//netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.min.css', array(), $this->settings['version'] );
		wp_enqueue_style( 'acf-validated_field', $this->settings['dir'] . 'css/input.css', array( 'acf-input' ), $this->settings['version'] ); 

	}
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
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/load_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
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
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/update_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
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
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/format_value/type='.$sub_field['type'], $value, $post_id, $sub_field );
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
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		return apply_filters( 'acf/format_value_for_api/type='.$sub_field['type'], $value, $post_id, $sub_field );
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
		global $currentpage;
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		$sub_field = apply_filters( 'acf/load_field/type='.$sub_field['type'], $sub_field );
		$field['sub_field'] = $sub_field;
		if ( $field['read_only'] && $currentpage == 'edit.php' ){
			$field['label'] = $field['label'].' <i class="fa fa-link" title="'. __('Read only', 'acf_vf' ) . '"></i>';
		}
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
		$sub_field = $this->setup_sub_field( $this->setup_field( $field ) );
		$sub_field = apply_filters( 'acf/update_field/type='.$sub_field['type'], $sub_field, $post_id );
		$field['sub_field'] = $sub_field;
		return $field;
	}
}

new acf_field_validated_field();
endif;
