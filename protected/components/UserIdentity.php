<?php

class UserIdentity extends CUserIdentity{

	private $_id;
	private $salt = 'aaa';

	public function authenticate() {
		
		$username = strtolower($this->username);
		$user = User::model()->find('LOWER(username)=?', array($username));

		if ($user===null) {
			$this->errorCode = self::ERROR_USERNAME_INVALID; 
		} else if (!$this->validatePassword($user->password)) {
			$this->errorCode=self::ERROR_PASSWORD_INVALID;
		} else {
            $this->_id=$user->id;
            $this->username=$user->username;
            $this->errorCode=self::ERROR_NONE;
        }
        return $this->errorCode==self::ERROR_NONE;
	}
	
    public function getId(){
        return $this->_id;
    }
    
    private function validatePassword($password) {
        return md5($this->salt.$this->password)===$password;
    }
    
}