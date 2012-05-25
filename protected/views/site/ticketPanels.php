<div id="ticketView" style="display:none">
	<div id="ticketHeader">
		<div id="ticketHeaderTitle">
		<span id="title">#10000: Dodać widget umożliwiający przypisanie kategorii do zdjęcia</span>
		<span id="editIssue" title="Edytuj zadanie"></span>
		<span id="closeIssue" title="Zamknij zadanie"></span>
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
		<h4 name="id">Zalaczniki</h4>
		<div id="uploadFile"></div>
		<div id="attachments"></div>
	</div>
	
	<div id="ticketHistory" class="ticketBody">Zmiany: </div>
	<div style="clear:both"></div>
	<div id="ticketDiff" class="ticketBody"></div>
	
	<div id="ticketFooter"></div>
</div>

<!-- ISSUE RELATED DIALOGS -->
<div id="issueCommentDialog" title="Dodaj komentarz" style="display:none">
	<form id="issueCommentForm">
		<input id="issueId" type="hidden" name="Issue[id]"/>
		<label>Komentarz</label><br/>
		<textarea id="issueComment" name="Issue[comment]" cols="52" rows="7"></textarea>
	</form>
</div>

