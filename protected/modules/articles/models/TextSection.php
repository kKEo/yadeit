<?php
class TextSection extends Section {
	
	public function beforeSave() {
		
		if (parent::beforeSave()) {
			$this->contentType = 'Text';
			return true;
		}
		
		return false;
	}
	
	
	
	
}