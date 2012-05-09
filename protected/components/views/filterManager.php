<div id="filter-manager" title="Filter manager" style="display:none;">
	<div id="filter-manager-header">
		Search: <input type="text" id="filter-manager-search" size="20"/>
	</div>
	<div id="filter-manager-right-bar">
		<a href="#" id="filter-manager-add">add</a>
	</div>
	<div id="filter-manager-items"></div>
	<div id="filter-manager-paginator">
		<span id="filter-manager-currentPage">1</span> /
		<span id="filter-manager-totalCount">1</span> -
		<a id="filter-manager-prev" title="">poprzedni</a> | 
		<a id="filter-manager-next" title="1">następny</a> 
	</div>
</div>

<style>
	#filter-manager-header {text-align: right; padding: 5px 10px; background: #eaeada;}
	#filter-manager-right-bar{width: 50px; float: right; height: 200px; background: #adddad;}
	#filter-manager-items{height:190px;}
	.filter{padding: 3px 10px; cursor: pointer;}
	.filter.even {background: #f1f1f1;}
	#filter-manager-paginator{height: 10px;}
	#filter-manager-next.disabled { color: #dadada; cursor: default;}
	#filter-manager-prev.disabled { color: #dadada; cursor: default;}
	.hint {font-size: x-small; color: #aeaeae; margin: 0 0 0.5em;}
	#<?php echo $linkId; ?> {float: right;}
</style>

<div id="filter-edit-dialog" title="Edytuj filter" style="display:none;">
	<form id="filter-edit-form">
		<input id="filterId" type="hidden" name="Filter[id]"/>
		<label>Nazwa</label><br/>
		<input id="filterName" type="text" name="Filter[name]" size="30"/><br/>
		<label>Opis</label><br/>
		<textarea id="filterDesc" name="Filter[description]" cols="35" rows="2"></textarea><br/>
		<label>Warunek</label><br/>
		<input id="filterCondition" type="text" name="Filter[condition]" size="40"/><br/>
		<p class="hint">
			Stałe: READY, INPROGRESS, WAITING, HANGED, CLOSED, UID
		</p>
		<label>Sortowanie</label><br/>
		<input id="filterOrderBy" type="text" name="Filter[orderBy]" size="30"/><br/>
	</form>
</div>