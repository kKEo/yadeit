<?php
class FilterManager extends CWidget {
	
	public $containerId;
	public $id = 'filterManager';
	public $linkId = 'editFilterLink';
	
	public function run() {
		
		Yii::app()->clientScript->registerScript('filter-manager', '

		var showFilterEditDialog = function(el) {
		
			var _dialog = $("#filter-edit-dialog");
			
			_dialog.find("input").val("");
			_dialog.find("textarea").val("");
			
			if (typeof el.id !== "undefined") {
				$.getJSON("'.$this->getController()->createUrl('ticket/filter').'",
					{"id":el.id.match(/\d/)[0]},
					function(data){
						if (data !== null) {
							$.each(data, function(k,v){
								_dialog.find("*[name=\"Filter["+k+"]\"]").val(v);
							});
						}
					}
				);	
			} 
			
			$("#filter-edit-dialog").dialog({
				title: "Edytuj filter",
				modal: true,
				minWidth: 400,
				minHeight: 400,
				buttons: {
					"Zapisz": function(){
						$.post("'.$this->getController()->createUrl('ticket/updateFilter').'",
							$("#filter-edit-form").serialize(),
							function(data){
								refreshFilters(1);
							},
							"json"
						);
						$(this).dialog("close");
					},
					"Anuluj": function(){
						$(this).dialog("close");
					},
				}
			});

			return false;
		}
		
		var showManager = function(e){
			$("#filter-manager").dialog({
				minWidth: 700,
				minHeight: 400,
				close: function() {
					refreshFiltersPanel(true);
				}	
			});
		}
		
		$("<a href=\"#\" id=\"'.$this->linkId.'\">(M)</a>").appendTo("#'.$this->containerId.'").bind("click", showManager);
		
		$("#filter-manager-add").bind("click", showFilterEditDialog);
		
		');
		
		// render dialogs
		$this->render('filterManager', array(
			'linkId'=>$this->linkId,	
		));
		
		Yii::app()->clientScript->registerScript('filter-manager-actions', '
		
			var refreshFilters = function(page){
				cur = $("#filter-manager-currentPage").text();
				cnt = $("#filter-manager-totalCount").text();
				
				next = $("#filter-manager-next").text();
				
				filter = "*";
				
				$.getJSON("'.$this->getController()->createUrl('ticket/filtered').'",
					{"page":page,"filter":filter},
					function(data){
					
					  $("#filter-manager-items").html("");
					
					  $.each(data.filters, function(key, val) {
					    cssClass = (key%2)?"filter even":"filter odd";
					    $("<div/>", {
					    	"id": "f"+val.id,
					    	"class": cssClass,
					    	"html": val.name + " - " + val.description
					    }).appendTo("#filter-manager-items");
					  });
					  
					  $(".filter").bind("click", function(){
						//var id = this.id.match(/\d/));
						showFilterEditDialog(this);
					  });
					  
					  $("#filter-manager-currentPage").text(data.current);
					  $("#filter-manager-totalCount").text(data.count);
					  
					  $("#filter-manager-next").each(function(){
					  	if (data.current >= data.count) {
							$(this).addClass("disabled").removeAttr("href").unbind("click");
						} else {
							$(this).removeClass("disabled").attr("href","#").unbind("click").bind("click", function() { refreshFilters(1 + data.current)});
						}
					  });
					  
					  $("#filter-manager-prev").each(function(){
						if (data.current < 2) {
							$(this).addClass("disabled").removeAttr("href").unbind("click");
						} else {
							$(this).removeClass("disabled").attr("href","#").unbind("click").bind("click", function(){refreshFilters(data.current - 1)});
						}	
					  });
					  
					  
					}
				);
				
			}  
		
			refreshFilters(1);
		
		');
		
		
		
	}
}