<?php

class m120506_182222_comments extends CDbMigration {
	public function up() {
			$this->getDbConnection()->createCommand(
'
CREATE TABLE comments (
	id integer primary key autoincrement,
	userId integer not null,
	created integer not null,
	comment string not null,
	itemId integer not null,
	categoryId integer default 0
);
'				
)->execute();
	}

	public function down()	{
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