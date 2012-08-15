<?php

class m120704_144459_init extends CDbMigration{
	
	public function up(){
		
$transcation = $this->getDbConnection()->beginTransaction();

$this->getDbConnection()->createCommand(
'Create table wiki_article (
	id integer primary key autoincrement,
	title varchar(200) not null,
	seoTitle varchar(200) not null,
	abstract varchar(1000),
	tags varchar(200),
	avatar varchar(200),
	authorId integer,
	status integer default 0,
	displays integer default 0,
	likes integer default 0,
	dislike integer default 0,
	pinned integer default 0,
	createdBy integer not null,
	created integer not null
);')->execute();
	
$this->getDbConnection()->createCommand(
'Create table wiki_tags (
  id integer primary key autoincrement,
  tag varchar(20) not null,
  freq integer default 1
);')->execute();

$this->getDbConnection()->createCommand(	
'Create table wiki_section (
 id integer primary key autoincrement,
 title varchar(200), 
 content text not null,
 contentType varchar(20),
 position integer default 99,
 after integer,
 articleId integer not null,
 status integer default 0,
 createdBy integer not null,
 created integer not null
);')->execute();
	
$transcation->commit();	
	
}

	public function down(){
		
$dropStmts = array(
	'drop table wiki_article;'
,'drop table wiki_tags;'
,'drop table wiki_section;');		
		
foreach ($dropStmts as $stmt) {
	$this->getDbConnection()->createCommand($stmt)->execute();			
}
		
		return true;
	}
}