<?php 
	Yii::app()->clientScript->registerScriptFile('js/ctxmenu/jquery.contextMenu.js');
	Yii::app()->clientScript->registerCssFile('js/ctxmenu/jquery.contextMenu.css');
?>

<?php $this->widget('TicketList', array(
	'onItemClick'=>'viewTicket(this)'
	));?>

<style>
.selectedItem {
	border-right: 3px solid #dea123;
	border-left: 3px solid #dea123;
	background: #f5f0e7;
}
</style>

<?php Yii::app()->clientScript->registerScript('view-ticket', 
'

registerCloseIssue = function(id){
	$("#closeIssue").bind("click", function(){
	
		$("#issueCloseForm > #issueId").attr("value",id);
		$("#issueCloseForm > #issueComment").attr("value","");
		
		$("#issueCloseDialog").dialog({
			modal: true,
			minWidth: 400,
			minHeight: 200,
			buttons: {
				Zamknij: function(){
					$.post("'.$this->createUrl('//ticket/close').'",
						$("#issueCloseForm").serialize(),
						function(data){
							viewTicket({"id":id});
						},
						"json"
					);
					$(this).dialog("close");
				},
				Anuluj: function(){
					console.log("Cancel");
					$(this).dialog("close");
				}
			}
		});
    });
}

viewTicket = function(item){
	
	if (typeof item.id === "undefined"){
      return;
    }

	issueId = item.id.replace("item","");
	
	$.getJSON(
		"'.$this->createUrl('//ticket/get').'", 
		{"id":issueId},
		function(data) {
			$("#ticketHeaderTitle > #title").html("#"+issueId+": "+data.subject);
			$("#ticketBody").html(data.description);
			$("#priority").html(data.priority);
			
			 $.contextMenu({
			        selector: "#priority", 
			        trigger: "left",
			        // only trigger on lift click!
			        ignoreRightClick: true,
			        callback: function(key, options) {
			            var option = key.replace("v","");
			            $.post(
			            	"'.$this->createUrl('//ticket/set').'"
			            	,{"id":issueId, "attr":"priority", "value":option}
			            	,function(data){
			            		viewTicket({"id":issueId});
							}
			            	,"json");
			        },
			       items: '.CJSON::encode(Ticket::getPriorities()).'
			    });
			
			
			$("#status").html(data.statusText);
			
			 $.contextMenu({
			        selector: "#status", 
			        trigger: "left",
			        // only trigger on lift click!
			        ignoreRightClick: true,
			        callback: function(key, options) {
			           	var option = key.replace("v","");
			            $.post(
			            	"'.$this->createUrl('//ticket/set').'"
			            	,{"id":issueId, "attr":"status", "value":option}
			            	,function(data){
								viewTicket({"id":issueId});
							}
			            	,"json");

			        },
			        items: '.CJSON::encode(Ticket::getStatuses()).'
			    });
			
			
			$("#reportedBy").html(data.author).bind("click", function(){return false;});
			$("#assignedTo").html(data.assignee);
			
			 $.contextMenu({
			        selector: "#assignedTo", 
			        trigger: "left",
			        // only trigger on lift click!
			        ignoreRightClick: true,
			        callback: function(key, options) {
			            $.post(
			            	"'.$this->createUrl('//ticket/assign').'"
			            	,{"iid":issueId, "uid":key.match(/\d+/)[0]}
			            	,function(data){
			            		viewTicket({"id":issueId});
							}
			            	,"json"
			            ); 
			        },
			        items: '.CJSON::encode(User::getUsers()).'
			    });
			
			$(".ticketListItem").each(function(){$(this).removeClass("selectedItem")});
			$("#item"+data.id).addClass("selectedItem");
			
			$("#ticketDiff").html("").hide();
			
			$("#ticketView").fadeIn();
			
			refreshAttachments();
			
			if (data.status < 5) {
				$("#editIssue").show();
				$("#closeIssue").show();
				registerCloseIssue(issueId);
			} else {
				$("#editIssue").hide();
				$("#closeIssue").hide();
			}
		},
		"json"
	);
	
	ticketHistory(issueId);
} 

$("#editIssue").bind("click", function(e){ showAddDialog(e) });

')?>

	<?php 
		$this->renderPartial('ticketPanels');
	?>

	<?php $this->widget('TicketAttachments', array(
			'getIdCallback'=>'function(){return $("#ticketHeaderTitle > #title").text().match(/\d+/)}',
			'containerId'=>'ticketAttachments',
	)); ?>
	<?php $this->widget('TicketHistory'); ?>	

<?php 
	Yii::app()->clientScript->registerCoreScript('jquery');
?>

