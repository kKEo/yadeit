<?php

class m120218_173518_initDb extends CDbMigration{
	
	public function up(){

		$this->getDbConnection()->createCommand(
"
CREATE TABLE projects (
	id integer not null primary key,
	parentId integer,
	name string not null,
	description string not null,
	status integer default 0,
	authorId integer not null,
	created integer not null,
	updated integer
);

CREATE TABLE tickets (
	id integer not null PRIMARY KEY,
	pid integer,
	projectId integer not null,
	subject string not null,
	description string not null,
	tags string,
	dueDate integer,
	categoryId integer not null,
	status integer default 2,
	assignedTo integer,
	priority integer default 1,
	authorId integer not null,
	versionId integer,
	created integer not null,
	updatedBy integer,
	updated integer
);

CREATE TABLE ticket_notes(
	ticketId integer not null,
	note string not null,
	status integer,
	userId integer not null,
	created integer not null,
	updated integer
);

CREATE TABLE sprints (
	id integer not null primary key,
	name string not null,
	description string,
	projectId integer not null,
	dueDate integer,
	created integer not null
);

CREATE TABLE attachments (
	id integer not null primary key,
	iid integer not null,
	path string not null,
	kbsize integer not null,
	contentType string not null,
	digest string,
	downloads integer default 0,
	uid integer not null,
	cdt integer not null
);

CREATE TABLE articles (
	id integer not null primary key,
	title string not null,
	status integer default 0,
	userId integer not null,
	created integer not null,
	updated integer
);

CREATE TABLE paragraph (
	id integer not null primary key,
	articleId integer not null,
	content string not null,
	userId integer not null,
	created integer not null,
	modified integer
);

CREATE TABLE comments (
	id integer not null primary key,
	comment string not null,
	userId integer not null,
	created integer not null
);

CREATE TABLE filters (
	id integer primary key,
	name string not null,
	description string,
	condition string,
	orderBy string,
	userId integer,
	isPublic integer
);

	")->execute();

	}

	public function down()	{
	}

}