var vf = {
	valid : false,
	lastclick : false
};

(function($){

	$(document).on("click", "form#post input", function(){
		vf.lastclick = $(event.srcElement);
	});
	
	$(document).on("submit", "form#post", function(){
		if (!vf.valid){
			return do_validation(vf.lastclick);
		} 
		return true;
	});

	function do_validation(clickObj){
		if (!clickObj) return;
		fields = [];
		$('.field_type-validated_field').find('.acf-error-message').remove();
		$('.validated-field:visible').each(function(){
			parent = $(this).closest('.field');
			
			$(this).find('input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked').each(function(index, elem){
				var field = { 
						id: $(elem).attr('name'),
						value: $(elem).val(),
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
						field = $('#'+fld.id).closest('.validated-field');
						label = field.parent().find('p.label:first');
						field.append('<span class="acf-error-message"><i class="bit"></i>' + msg + '</span>');
						field.find('.widefat').css('width','100%');
					}
				}
				vf.valid = valid;
			}
			
			clickObj.removeClass('button-primary-disabled').removeClass('disabled');
			$('#ajax-loading').attr('style','');
			$('#publishing-action .spinner').hide();
			if(vf.valid) {
				clickObj.click();
			} else {
				$('.field_type-validated_field .acf-error-message').show();
			}
		}
	}
})(jQuery);
