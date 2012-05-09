<?php
class TicketAttachments extends CWidget {
	
	public $getIdCallback;
	public $containerId = 'ticketAttachments';

	public function run() {
		
		// Refresh attachments list
		Yii::app()->clientScript->registerScript('attachments', 
'
refreshAttachments = function(){

	var id = ('.$this->getIdCallback.')();
	
	$.post(
		"'.$this->getController()->createUrl('//ticket/attachments', array('id'=>'__IID')).'".replace("__IID",id),
		"",
		function(data){
			var baseUrl = "'.Yii::app()->baseUrl.'/";
		
			$("#attachments").html("");
			
			$.each(data, function(idx,el){
				var $el = $("<div class=\"attachment\"></div>");
				if (el.contentType.match(/^image/) !== null) {
					$el.html("<img src=\""+baseUrl+el.path+"\" width=140></img>");
				} else {
					$el.html("<a href=\""+baseUrl+el.path+"\">"+el.path+"<a>");
				}
				$el.appendTo("#attachments");
			});
		},
		"json"
	);
}
');

		$this->widget('ext.EAjaxUpload.EAjaxUpload',
         	array(
                       'id'=>'uploadFile',
					   'postParams'=>array(
								'id'=>'js:'.$this->getIdCallback,
						),
//					   'rawParams'=>'{id:3}',
                       'config'=>array(
							'action'=>$this->getController()->createUrl('/ticket/upload'),
		 					
		 					//'debug'=>'true',
                       		'allowedExtensions'=>array(),
                            'sizeLimit'=>5*1024*1024,// maximum file size in bytes
                            //'minSizeLimit'=>1*1024*1024,// minimum file size in bytes
                            'onComplete'=>"js:function(id, fileName, data){ 
								$('.qq-upload-list').hide();
								console.log(data);
								refreshAttachments();
							}",
                                       //'messages'=>array(
                                       //                  'typeError'=>"{file} has invalid extension. Only {extensions} are allowed.",
                                       //                  'sizeError'=>"{file} is too large, maximum file size is {sizeLimit}.",
                                       //                  'minSizeError'=>"{file} is too small, minimum file size is {minSizeLimit}.",
                                       //                  'emptyError'=>"{file} is empty, please select files again without it.",
                                       //                  'onLeave'=>"The files are being uploaded, if you leave now the upload will be cancelled."
                                       //                 ),
                                       //'showMessage'=>"js:function(message){ alert(message); }"
                            )
         ));
		 
		 //echo '<img src="images/buttons/new.png" width="88"></img>';
		
	}
	
	
}