<?php
class TicketList extends CWidget {
	
	public $onItemClick;
	
	public function init(){
		Yii::app()->getClientScript()->registerCoreScript( 'jquery.ui' );
		Yii::app()->getClientScript()->registerCssFile('http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.17/themes/base/jquery-ui.css');
		
		Yii::app()->clientScript->registerScriptFile('js/cleditor/jquery.cleditor.min.js');
		Yii::app()->clientScript->registerCssFile('js/cleditor/jquery.cleditor.css');
	
		Yii::app()->clientScript->registerScript('ticket-list',
'
var showAddDialog = function(e){

	var isNew = typeof(e) !== "undefined" && e.currentTarget.id === "addNew";

	$("#add-dialog").dialog({
		title: (isNew)?"Dodaj nowe zadanie":"Edycja zadania",
		modal: true,
		minWidth: 800,
		minHeight: 500,
		buttons: {
			"Zapisz": function() {
					if (isNew) {
						$.post("'.$this->getController()->createUrl('//ticket/create').'", 
							$("#add-form").serialize(), 
							function(data) {
								refresh();
								viewTicket({id: data.id});
							}, "json");
					} else {
						var id = $(e.currentTarget).parent().find("#title").text().match(/#([0-9]+)/)[1];
						//cle[0].updateTextArea(); 
						
						$.post("'.$this->getController()->createUrl('//ticket/update').'",
							$("#add-form").serialize(),
							function(data){
								viewTicket({id: id});
							}, "json");
					}
					
					$(this).dialog("close");
				},
			Anuluj: function() {
					$(this).dialog("close");
				}
		}	
	});

	if (typeof(cle) === "undefined") {
		var te = $("#add-form > textarea");
		cle = te.cleditor({"width":600,"height":280,"useCSS":true});
	}
	
	if (!isNew) {
		var id = $(e.currentTarget).parent().find("#title").text().match(/#([0-9]+)/)[1]; 
		$.post(
			"'.$this->getController()->createUrl('//ticket/get', array('id'=>'TICKET_ID')).'".replace("TICKET_ID",id), 
			"",
			function(data) { 
				$("#add-form > #ticketId").attr("value",data.id);
				$("#add-form > #subject").attr("value",data.subject);
				
				cle[0].clear();
				cle[0].execCommand("inserthtml", data.description, null, null);
			},
			"json");
	} else {
		$("#add-form > input").attr("value","");
		cle[0].clear();
	}	
	
	return false;
}

$("#addNew").bind("click", showAddDialog);
');
	}
	
	public function run(){
		$this->render('ticketList', array(
			'onItemClick'=>$this->onItemClick
		));
	
	}
	
	
	
}