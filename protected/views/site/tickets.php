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
viewTicket = function(item){
	
	if (typeof item.id === "undefined"){
      return;
    }

	issueId = item.id.replace("item","");
	
	$.post(
		"'.$this->createUrl('//ticket/get', array('id'=>'TICKET_ID')).'".replace("TICKET_ID",issueId), 
		"",
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
								$("#priority").html(data.name);
							}
			            	,"json");
			        },
			       items: '.CJSON::encode(Ticket::getPriorities()).'
			    });
			
			
			$("#status").html(data.status);
			
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
								$("#status").html(data.name);
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
			            		if  (data.status == 0) {
									$("#assignedTo").html(data.name);
								}
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
		},
		"json"
	);
	
	ticketHistory(issueId);
} 

//$("#ticketHeaderTitle").hover(function(){$(this).find("#editIssue").css("display","inline-block")}, function(){$(this).find("#editIssue").css("display","none")});
$("#editIssue").bind("click", function(e){ showAddDialog(e) });

')?>

<div id="ticketView" style="display:none">
	<div id="ticketHeader">
		<div id="ticketHeaderTitle">
		<span id="title">#10000: Dodać widget umożliwiający przypisanie kategorii do zdjęcia</span>
		<span id="editIssue"></span>
		<br/>
		<span class="ticketStat">Priorytet: <a id="priority" href="">normalny</a></span>
		<span class="ticketStat">Status: <a id="status" href="">w trakcie</a></span>
		<span class="ticketStat">Zgłoszony przez: <a id="reportedBy" href="">krma</a></span>
		<span class="ticketStat">Przypisany do: <a id="assignedTo" href="">krma</a></span>
		</div>
	</div>
	<div id="ticketBody" class="ticketBody">
		Widget powinien wyswietlac drzewo kategorii. Kategorie juz przypisane do zdjecia nie powinny pojawiac sie w drzewku.<br/>
<br/>
Widget powinien zawierac dwie akcje:<br/>
 - onChoose - wywolywane po wybraniu kategorii<br/>
 - onSuccess - wywolywane po pomyslnym zakonceniu akcji onChoose<br/>
	</div>
	
	<div id="ticketAttachments" class="ticketBody">
		<?php $this->widget('TicketAttachments', array(
			'getIdCallback'=>'function(){return $("#ticketHeaderTitle > #title").text().match(/\d+/)}'
		)); ?>
	</div>
		<?php $this->widget('TicketHistory'); ?>	
	<div id="ticketFooter">
	</div>
	
</div>

<?php 
	Yii::app()->clientScript->registerCoreScript('jquery');
?>

