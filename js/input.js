var vf = {
	valid 		: false,
	lastclick 	: false,
	debug 		: false
};

(function($){

	var inputSelector = 'input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked';

	$(document).on('change', inputSelector, function(){
		vf.valid = false;
	});

	$(document).on('click', 'form#post input', function(){
		vf.lastclick = $(event.srcElement);
	});
	
	$(document).on('submit', 'form#post', function(){
		$('.field_type-validated_field').find('.acf-error-message').remove();
		$(this).siblings('#acfvf_message').remove();
			
		if (!vf.valid){
			return do_validation(vf.lastclick);
		} 
		return true;
	});

	function do_validation(clickObj){
		if (!clickObj) return;
		fields = [];
		$('.validated-field:visible').each(function(){
			parent = $(this).closest('.field');
			
			$(this).find(inputSelector).each(function(index, elem){
				el = $(elem);
				if (el.attr('name') && el.attr('name').indexOf('acfcloneindex')<0){
					var field = { 
							id: el.attr('name'),
							value: el.val(),
							valid: false,
					};
					fields.push(field);
				}
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
					ajax_returned(json, clickObj);				
				}, 
				error: function(jqXHR, exception){
					ajax_returned(fields, clickObj);
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
						input = $('[name="'+fld.id.replace('[', '\\[').replace(']', '\\]')+'"]');
						input.parent().parent().append('<span class="acf-error-message"><i class="bit"></i>' + msg + '</span>');
						field = input.closest('.field');
						field.addClass('error');
						field.find('.widefat').css('width','100%');
					}
				}
				vf.valid = valid;
			}
			
			clickObj.removeClass('button-primary-disabled').removeClass('disabled');
			$('#ajax-loading').attr('style','');
			$('#publishing-action .spinner').hide();
			if ( !vf.valid ){
				$('form#post').before('<div id="acfvf_message" class="error"><p>Validation Failed. See errors below.</p></div>');
				$('.field_type-validated_field .acf-error-message').show();
			} else if ( vf.debug ){
				vf.valid = confirm("The fields are valid, do you want to submit the form?");
			} 
			if (vf.valid) {
				clickObj.click();
			}
		}
	}
})(jQuery);
