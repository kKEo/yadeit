<?php

class m120815_131700_events extends CDbMigration
{
	public function up()
	{
		$sql = <<<SQL
create table EVENT (
	  id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, 
	  category INTEGER,
	  userId INTEGER,
	  message TEXT,
	  created INTEGER        	
);		
		
CREATE TABLE SESSION_HISTORY (
		id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
		sessionId VARCHAR(30) NOT NULL,
		ip VARCHAR(15) NOT NULL,
		userId INTEGER NOT NULL,
		category INTEGER DEFAULT 0,
		itemId INTEGER NOT NULL,
		created INTEGER,
		data VARCHAR(200));
SQL;
		$this->getDbConnection()->createCommand($sql)->execute();
	}
	
	public function down() {
		$this->getDbConnection()->createCommand('drop table EVENT')->execute();
		$this->getDbConnection()->createCommand('drop table SESSION_HISTORY')->execute();
	}

}