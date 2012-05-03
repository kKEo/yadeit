<?php
class TicketController extends Controller {

	const CURPAGE = 'ticketListCurrentPage';

	public function actionList($page, $filter) {
		
		$filterName = Yii::app()->getRequest()->getQuery('filter');
		$dataProvider = new CActiveDataProvider('Ticket');
		$dataProvider->getPagination()->setPageSize(6);

		$dataProvider->setCriteria(array(
			'select'=>'t.id,subject,created,status,priority',
			'with'=>'project',
		));
		
		$condition = 'pid is null';
		$orderBy = 't.id desc';
		if ($filterName !== null) {
			$filter = Filter::model()->findByAttributes(array('id'=>$filterName));
			assert($filter !== null);
			$condition .=  ' and '.$filter->getCondition();
			$orderBy = $filter->orderBy;
		}
		
		$dataProvider->getCriteria()->condition = $condition;
		$dataProvider->getSort()->defaultOrder = $orderBy;

		$dataProvider->getCriteria()->together = true;
		
		$dataProvider->getPagination()->setCurrentPage($page-1);

		$data = $dataProvider->getData();
		$pageCount = $dataProvider->getPagination()->getPageCount();

		$data['curpage'] = $page;
		$data['pagecnt'] = $pageCount;

		echo CJSON::encode($data);
	}


	public function actionGet() {

		$id = Yii::app()->getRequest()->getParam('id');

		$ticket = Ticket::model()->findByPk($id);

		$res = $ticket->attributes;
		$res['status'] = $ticket->getStatus();
		$res['priority'] = $ticket->getPriority();

		$res['author'] = $ticket->author->username;
		$res['assignee'] = ($ticket->assignedTo !== null)?$ticket->assignee->username:"nie przypisano";

		echo CJSON::encode($res);
	}
	
	public function actionAttachments() {
		
		$id = Yii::app()->getRequest()->getQuery("id");
		
		$attachments = Attachment::model()->findAll(array(
			'select'=>'id,path,kbsize,cdt,contentType',
			'condition'=>'iid = '.$id));
		
		$res = array();
		foreach ($attachments as $a) {
			$res[] = $a->attributes;
		}
		
		echo CJSON::encode($res);
	}

	public function actionHistory(){
		$id = $_POST['id'];
		$tickets = Ticket::model()->getHistory($id);

		$res = array();
		foreach ($tickets as $ticket) {
			$res[] = array(
				'id'=>$ticket['id'],
				'updated'=>date('Y/m/d H:i',$ticket['updated']),
				'username'=>$ticket['username'],
				);
		}

		echo CJSON::encode($res);
	}
	
	public function actionAssign(){
		
		$iid = isset($_POST['iid'])?$_POST['iid']:null;
		$uid = isset($_POST['uid'])?$_POST['uid']:null;
		
		assert($iid !== null);
		assert($uid !== null);
		
		$ticket = Ticket::model()->findByPk($iid);
		
		if ($ticket->assignedTo == $uid) {
			throw new CHttpException(500, 'Issue is already assigned to this user.');
		}
		
		$ticket->assignedTo = $uid;
		
		$res = array();
		if ($ticket->update(array('assignedTo'))) {
			$res['status']=0;
			$res['name']=($ticket->assignedTo !== null)?$ticket->assignee->username:"nie przypisane";
		} else {
			$res['status']=1;
			$res['error']=$ticket->getErrors();
		}
		
		echo CJSON::encode($res);
	}
	
	public function actionSet(){

		$id = $_POST['id'];
		$attr = $_POST['attr'];
		$value = $_POST['value'];

		$ticket = Ticket::model()->findByPk($id);

		if ($attr !== 'priority' && $attr !== 'status') {
			throw new CHttpException(500, 'Illegal attribute modification!!');
		}

		$ticket->$attr = $value;

		
		if ($ticket->update(array($attr))){
			
			if ($attr === 'priority') {
				$p = Ticket::getPriorities();
			} else {
				$p = Ticket::getStatuses();
			}
			echo CJSON::encode($p['v'.$value]);
		} else {
			echo CJSON::encode($ticket->getErrors());
		}

	}

	public function actionCreate(){

		$model = new Ticket;
		$res = array('status'=>0);

		if (isset($_POST['Ticket'])){
			$model->attributes = $_POST['Ticket'];

			if ($model->save()){
				$res['status'] = 1;
			} else {
				$res['error']=$model->getErrors();
			}
		}

		echo CJSON::encode($res);
	}

	public function actionUpdate(){
		$res = array('status'=>1);
		if (isset($_POST['Ticket'])){
			$model = Ticket::model()->findByPk($_POST['Ticket']['id']);

			$old = new Ticket();
			$old->attributes = $model->attributes;
			$old->pid = $model->id;
			$old->updated = time();
			$old->updatedBy = Yii::app()->user->id;
			if ($old->save()){
				$model->attributes = $_POST['Ticket'];
				if ($model->update(array('subject','description'))){
					$res['status'] = 0;
				} else {
					// failed to update entry
					$old->delete();
					$res['error'] = $model->getErrors();
				}
			} else {
				// failed to create history record
				$res['status'] = 2;
				$res['errors'] = $old->getErrors();
			}
		}
		echo CJSON::encode($res);
	}

	public function actionFilters(){
		
		$criteria = new CDbCriteria(array(
			'condition'=>'id > 3',
			'order'=>'id desc',
		));
		
		$filters = Filter::model()->findAll($criteria);
		
		echo CJSON::encode($filters);
	}
	
	public function actionFilter($id){
		echo CJSON::encode(Filter::model()->findByPk($id));
	}
	
	public function actionUpdateFilter(){
		
		$resp = array();
		
		if (isset($_POST)) {

			$filterId = $_POST['Filter']['id'];
			
			$model = Filter::model()->findByPk($filterId);
			
			if ($model === null) {
				$model = new Filter;
			}
			
			$model->attributes = $_POST['Filter'];
			
			if ($model->isNewRecord) {
				if ($model->save()){
					$resp['status'] = 'success';
				} else {
					$resp['status'] = 'failure';
				}
			} else {
				if ($model->update(array('name','description', 'condition', 'orderBy'))){
					$resp['status'] = 'success';
				} else {
					$resp['status'] = 'failure';
				}
			}
		}
		
		echo CJSON::encode($resp);
		
	}
	
	public function actionFiltered($page, $filter = ""){
		
		$perPage = 7;
		
		$criteria = new CDbCriteria(array(
			//'condition'=>'id > 3',
			'order'=>'id desc',
			'limit'=>$perPage,
			'offset'=>($page-1)*$perPage
		));
		
		$filters = Filter::model()->findAll($criteria);
		
		$cnt = Filter::model()->count($criteria);
		
		echo CJSON::encode(array(
			 'current'=>0+$page
			,'count'=>1+($cnt - $cnt % $perPage) / $perPage
			,'filters'=>$filters	
		));
	}
	
	public function actionUpload(){

		Yii::import("ext.EAjaxUpload.qqFileUploader");
		
		$id = Yii::app()->getRequest()->getQuery('id');
		
		assert($id !== null);
		
		$folder='upload/';
		$allowedExtensions = array();
		$sizeLimit = 10 * 1024 * 1024;
		$uploader = new qqFileUploader($allowedExtensions, $sizeLimit);
		$result = $uploader->handleUpload($folder);
		
		if (!isset($result['error'])) {
			
			$filepath = $folder.$result['filename'];

			$a = new Attachment();
			$a->iid = $id;
			$a->path = $filepath;
			$a->kbsize =filesize($filepath)/1024;
			$a->contentType=mime_content_type($filepath);
			$a->digest = hash_file('md5', $filepath);
			
			if ($a->save()){
				$result['saved'] = true;
			}
			
		}
		
		$return = htmlspecialchars(json_encode($result), ENT_NOQUOTES);
		echo $return;

	}

}