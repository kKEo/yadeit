<?php

class m120506_182821_dicts extends CDbMigration{

	public function up() {
		
$this->getDbConnection()->createCommand(
'
CREATE TABLE dicts (
	id integer primary key autoincrement,
	pid integer,
	textValue string not null,
	intValue integer,
	code string,
	status integer default 0
);
'				
)->execute();
		
	}

	public function down() {
		echo "m120506_182821_dicts does not support migration down.\n";
		return false;
	}

	/*
	 // Use safeUp/safeDown to do migration with transaction
	public function safeUp()
	{
	}

	public function safeDown()
	{
	}
	*/
}