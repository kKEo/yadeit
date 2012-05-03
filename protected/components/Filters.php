<?php
class Filters extends CWidget {
	
	public $onClickCallback = 'refresh';
	public $filtersPanel = 'moreFiltersPanel';
	
	public function run() {
		
		$filters = Filter::model()->findAll('id < 4');
		
		echo '<div style="clear:both"></div><span id="basicFilters">';
		foreach ($filters as $filter) {
			echo ' <a href="#" title="'.$filter->description.'" id="filter'.$filter->id.'">'.$filter->name.'</a>';
		}
		echo ' </span><span id="moreFilters">'
			.'  <a id="toggleFiltersPanel" href="#">(więcej)</a>'
//			.'  <div style="clear:both"></div>'
			.'  <div id="'.$this->filtersPanel.'"></div>'
		    .'</span>';
	
		Yii::app()->clientScript->registerScript('filters', '

			var onFilterClick = function(){
				var id = this.id.match(/\d/)[0];
				'.$this->onClickCallback.'(id);
			};
		
			$("#basicFilters > a").bind("click", onFilterClick);

			refreshFiltersPanel = function(open){

				var _panel = $("#moreFiltersPanel");
				var _openCloseLink = $("#moreFilters").find("a#toggleFiltersPanel"); 
			
				var closeFiltersPanel = function() {
					_panel.hide();
					_openCloseLink.text("(wiecej)");
					_openCloseLink.unbind("click").bind("click", openFiltersPanel);
					return false;
				};
				
				var openFiltersPanel = function() {
					
					$.post(
						"'.$this->getController()->createUrl('ticket/filters').'",
						"",
						function(data){
								_panel.find("span").remove();
							
								$.each(data, function(k,v){
									var filter = $("<span><a href=\"#\" id=\"filter"+v.id+"\" title=\""+v.desc+"\">"+v.name+"</span>").appendTo("#moreFiltersPanel");
									filter.find("a").unbind("click").bind("click", onFilterClick);
								});
								
								_openCloseLink.unbind("click").bind("click",closeFiltersPanel);
								_openCloseLink.text("(ukryj)");
								
								_panel.show();
							},
						"json"
					);
				}
				_openCloseLink.bind("click", openFiltersPanel);
				
				if (open){
					openFiltersPanel();
				}
				
			}
			
			$("#moreFiltersPanel").hide();
			refreshFiltersPanel();
		');
		
	}
}