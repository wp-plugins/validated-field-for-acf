
var vf = {
	valid : false
};

(function($){
	
	$('form#post').live("submit", function(){
		if (!vf.valid){
			do_validation();
			return false;
		} else {
			vf.valid = false;
		}
	});

	function do_validation(){
		fields = [];
		$('.field-validated_field .validation-errors').empty().hide();;
		$('.field-validated_field').removeClass('error');

		$('.field-validated_field:visible').each(function(){
			parent = $(this).closest('.field');
			
			$(this).find('input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked').each(function(index, elem){
				var field = { 
						id: $(elem).attr('name'),
						value: $(elem).attr('value'),
						message: 'Server-side validation error.',
						valid: false,
				};
				fields.push(field);
			});
		});

		$.ajax({
			url: ajaxurl,
			data: {
				action: 'validate_fields',
				post_id: $("#post_ID").val(),
				fields: fields
			},
			type: 'post',
			dataType: 'json',
			success: function(json){
				ajax_returned(json);				
			}, 
			error: function(jqXHR, exception){
				ajax_returned(fields);
			}
		});
		
		function ajax_returned(fields){
			vf.valid = false;
			valid = true;
			if (fields){
				for (var i=0; i<fields.length; i++){
					var fld = fields[i];
					if (!fld.valid){
						valid = false;
						msg = $('<div/>').html(fld.message).text();
						$('[name="'+fld.id.replace('[', '\\[').replace(']', '\\]')+'"]').closest('.field').addClass('error').find('.validation-errors').append("<div>"+msg+"</div>").show();
					}
				}
				vf.valid = valid;
			}
			
			if(!vf.valid) {
				$('#publish').removeClass('button-primary-disabled');
				$('#ajax-loading').attr('style','');
			} else {
				$('#publish').click();
			}
			
			$('.acf_postbox:hidden').remove();
		}
	}
	
})(jQuery);