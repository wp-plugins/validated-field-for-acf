var vf = {
	valid : false
};

(function($){
	
	$(document).on("submit", "form#post", function(){
		var clickId = jQuery(event.srcElement).attr('id');
		if (clickId=='post-preview') return true;
		if (!vf.valid){
			return do_validation(clickId);
		} else {
			vf.valid = false;
		}
	});

	function do_validation(clickId){
		if (!clickId) return;
		fields = [];
		$('.validated-field .validation-errors').empty().hide();
		$('.validated-field').removeClass('error');
		$('.validated-field:visible').each(function(){
			parent = $(this).closest('.field');
			
			$(this).find('input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked').each(function(index, elem){
				var field = { 
						id: $(elem).attr('name'),
						value: $(elem).val(),
						//message: 'Server-side validation error.',
						valid: false,
				};
				fields.push(field);
			});
		});

		$('.acf_postbox:hidden').remove();

		// if there are no fields, don't make an ajax call.
		if ( !fields.length ){
			vf.valid = true;
			$('#publish').click();
			return true;
		} else {
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
					ajax_returned(json, clickId);				
				}, 
				error: function(jqXHR, exception){
					ajax_returned(fields, clickId);
				}
			});
			return false;
		}
		
		function ajax_returned(fields, clickId){
			vf.valid = false;
			valid = true;
			if (fields){
				for (var i=0; i<fields.length; i++){
					var fld = fields[i];
					if (!fld.valid){
						valid = false;
						msg = $('<div/>').html(fld.message).text();
						field = $('[name="'+fld.id.replace('[', '\\[').replace(']', '\\]')+'"]').closest('.field');
						field.addClass('error').find('.validation-errors').append('<span class="acf-error-message"><i class="bit"></i>' + msg + '</span>').show();
						field.find('.widefat').css('width','100%');
					}
				}
				vf.valid = valid;
			}
			
			if(vf.valid) {
				$('#publish').click();
			} else {
				$('#publish').removeClass('button-primary-disabled');
				$('#ajax-loading').attr('style','');
				$('#publishing-action .spinner').hide();
			}
		}
	}
})(jQuery);
