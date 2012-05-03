<div id="ticketList">
	
	<div id="add-dialog" title="Dodaj nowy ticket" style="display:none;">
		<form id="add-form">
			<input id="ticketId" type="hidden" name="Ticket[id]"/>
			<label>Temat</label><br/>
			<input id="subject" type="text" name="Ticket[subject]" size="60"/><br/>
			<label>Opis</label><br/>
			<textarea id="description" name="Ticket[description]" cols="65" rows="15"></textarea>
		</form>
	</div>
	
	<div id="ticketListHeader">
		<div id="ticketListTitle">
			Lista zadań 
				<span style="padding: 5px; text-align: right; float: right;">
					<a href="#" id="addNew"><img src="images/buttons/add.png" height="22"></img></a>
					<img src="images/buttons/help.png" height="22"></img>
				</span>
		</div>
	<?php 	
	Yii::app()->clientScript->registerScript('slideOutTickets', '

	var format = function(dint){
		var d = new Date(parseInt(dint)*1000);
		var minutes = (d.getMinutes()<10)?"0"+d.getMinutes():d.getMinutes();
		var hours = (d.getHours()<10)?"0"+d.getHours():d.getHours();
		return hours+":"+minutes+" "+d.getDate()+"/"+(d.getMonth()+1)+"/"+d.getFullYear();
	}
	
	var stars = function(dint) {
       var i=0;
       var o="*";
	   for (i=0;i<dint;i++) {
	     o+="*";
       }
       return o;
    }

var refresh = function(f,p){

	var filter = f||1;
	var page = p||1;
	
	$("#ticketItems").attr("f",filter);
	
	$.getJSON("'.$this->getController()->createUrl('//ticket/list').'", 
	{"filter":filter, "page":page}, 
	function(data) {
	
		$(".ticketListItem").fadeOut().remove();
		
		for (var i in data) {
			if (typeof(data[i].subject)=== "undefined") continue;
		
			var item = $("<div id=\"item"+data[i].id+"\" class=\"ticketListItem\">"
			  +data[i].subject+"<div class=\"ticketDetails\">"+data[i].project.name+" <br/> "+format(data[i].created)+
			  "<span style=\"float: right;\"> "+stars(data[i].priority)+" / "+stars(data[i].status)+"</span></div></div>");
			item.css("display","none");
			item.appendTo("#ticketItems");
			item.hover(
				function(){
					$(this).addClass("over")
				}, function(){
					$(this).removeClass("over")
				});
			item.click(function(){
				'.$onItemClick.'
			});
		}
		
		var i = 0;
		$(".ticketListItem").each(function(){
			$(this).delay(50*i++).fadeIn(1000);
		});	
		
		//update prev/next buttons
		$("#prev").each(function() {
			if (data.curpage > 1) {
				var prevPage = parseInt(data.curpage) - 1;
				$(this).removeClass("disabled").attr("href","#").attr("title", "page "+prevPage)
				.unbind("click").bind("click", function() {return refresh(filter, prevPage)})
			} else {
				$(this).unbind("click").removeAttr("href").addClass("disabled");
			}
		});
		
		$("#next").each(function(){
			if (data.curpage < data.pagecnt) {
				var nextPage = parseInt(data.curpage) + 1;
				$(this).removeClass("disabled").attr("href","#").attr("title","page "+nextPage)
				.unbind("click").bind("click", function() {return refresh(filter,nextPage)});
			} else {
				$(this).unbind("click").removeAttr("href").addClass("disabled");
			}
		});
			
		$.each([$(".ticketListItem")[0]],function(){
			'.$onItemClick.'
		});
		
		$("#currentPage").text(data.curpage);
		$("#totalCount").text(data.pagecnt);	
		
	});

	return false;
};
refresh(1,1);	

');
	?>
		<?php 
		$this->widget('Filters');
		$this->widget('FilterManager', array(
			'linkId'=>'filterEdit',
			'containerId'=>'moreFiltersPanel',
		));
		?>
		
	</div>
	<div id="ticketItems" f="1"></div>
	<div id="ticketFooter">
		Strona: 
			<span id="currentPage">0</span> /
			<span id="totalCount">0</span> 
			&nbsp; 
			<a id="prev" href="#">&lt; poprzednie</a> | 
			<a id="next" href="#">następne &gt;</a>
	</div>
	
	
</div>