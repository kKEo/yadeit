<?php

class m120815_124108_add_mailing_queue extends CDbMigration
{
	public function up()
	{
		$sql = <<<SQL
create table email_queue (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	userId INTEGER NOT NULL,
	mail_to VARCHAR2(200) NOT NULL,
	mail_from VARCHAR2(200) NOT NULL,
	from_name VARCHAR2(200) NOT NULL,
	subject VARCHAR2(500) NOT NULL,
	message TEXT NOT NULL,
	category INTEGER DEFAULT 0,
	priority INTEGER DEFAULT 0,
	added_time INTEGER NOT NULL,
	sent_time INTEGER)
SQL;
		$this->getDbConnection()->createCommand($sql)->execute();


	}
	
	public function down() {
		$this->getDbConnection()->createCommand('drop table email_queue')->execute();
	}

}