-- DBA setup for the MySQL QA runners. Review before running. Run as a MySQL
-- administrator, ONCE, through tests/Qa/README.md step 1, which substitutes the
-- password in memory. Never edit a real password into this file.
--
-- Creates exactly two disposable databases and one restricted account that can
-- reach only those two databases. Underscores are escaped (\_) in the GRANTs:
-- unescaped, MySQL treats them as wildcards and the grant would match other names.

CREATE DATABASE `frc_acct_qa_concurrency_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `frc_acct_qa_mysql_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'frc_acct_qa'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_STRONG_UNIQUE_PASSWORD';

-- Only what migrations and tests need: no GRANT OPTION, no global privileges, no roles.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
    ON `frc\_acct\_qa\_concurrency\_test`.* TO 'frc_acct_qa'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
    ON `frc\_acct\_qa\_mysql\_test`.* TO 'frc_acct_qa'@'127.0.0.1';
