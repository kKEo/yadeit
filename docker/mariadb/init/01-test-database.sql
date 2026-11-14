-- Separate database for the automated tests.
CREATE DATABASE IF NOT EXISTS yadeit_test;
GRANT ALL PRIVILEGES ON yadeit_test.* TO 'yadeit'@'%';
