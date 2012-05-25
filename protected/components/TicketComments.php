<?php
class TicketComments extends CWidget {
	
	public $containerId = 'issueComments';
	
	public function run() {
	
	Yii::app()->clientScript->registerScript('ticket-comments', 
'	

ticketComments = function(id) {
	
	var onSuccess = function(data){
		$("'.$this->containerId.'").html("");
			$("<div id=\"commentsTitle\">Komentarze: </div>").appendTo("#ticketBody");
			$.each(data, function(i,el) {
				$("<div class=\"commentItem\">"+format(el.created)+": "+el.user.username+" - <div class=\"commentText\">"+el.comment+"</div></div>").appendTo("#ticketBody");		
			});
	};
	
	$.getJSON("'.$this->getController()->createUrl('ticket/comments').'",
		{"issueId":id},
		onSuccess
	);
	
	}	
	
	
');
	
	
	}
}