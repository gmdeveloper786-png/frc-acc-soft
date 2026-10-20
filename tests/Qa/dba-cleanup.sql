-- DBA cleanup for the MySQL QA runners. Run as a MySQL administrator.
-- Removes ONLY the two disposable QA databases and the restricted QA account.
-- It never touches frc_accounting_software or any other database.

DROP DATABASE IF EXISTS `frc_acct_qa_concurrency_test`;
DROP DATABASE IF EXISTS `frc_acct_qa_mysql_test`;
DROP USER IF EXISTS 'frc_acct_qa'@'127.0.0.1';
