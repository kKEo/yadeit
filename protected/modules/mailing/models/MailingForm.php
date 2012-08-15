<?php
class MailingForm extends CFormModel {
	public $title;
	public $subject;
	public $content;
	public $pattern;
	
	public function rules(){
		return array(
			array('title,subject,content,pattern','required'),
		);
	}
}