<?php
class TicketHistory extends CWidget {
	
	public $ticketId;
	
	public function run() {

		
		Yii::app()->clientScript->registerScript('ticket-history', '

ticketHistory = function(id){

	var onSuccess = function(data){
		$("#ticketHistory").html("Historia zmian: ")
		$.each(data, function(i,el) {
			var $el = $("<div>"+el.updated+" - "+el.username+" (<a id=\"item"+el.id+"\" href=\"#\">różnice</a>)<div>").appendTo("#ticketHistory");
			
			$el.find("a").bind("click", function(){
				var histId = this.id.replace("item","");
				$.post("'.$this->getController()->createUrl('//ticket/get').'", {id:histId}, function(data){
					var diff = $("#ticketDiff");
					diff.html(data.description);
					diff.html(diffString(diff.html(),$("#ticketBody").html()));
					diff.show();
					
				},"json");
			});
		});
	};

	$.post("'.$this->getController()->createUrl('//ticket/history').'",{id:id},onSuccess,"json");
	}
		
	
	
');
	Yii::app()->clientScript->registerScriptFile('js/jsdiff.js');
	
	
		
	}
	
	
}