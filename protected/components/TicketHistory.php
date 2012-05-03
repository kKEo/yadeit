<?php
class TicketHistory extends CWidget {
	
	public $ticketId;
	
	public function run() {
		echo '<div id="ticketHistory" class="ticketBody">Zmiany: </div>';
		echo '<div style="clear:both"></div>';
		echo '<div id="ticketDiff" class="ticketBody"></div>';
		
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