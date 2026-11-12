# External databases

Besides the WordPress database, a backup can include databases on other servers or in other schemas. Each connection gets an ID, so its dumps are named `-db1`, `-db2` and so on. Each dump is written by the PHP exporter or by `mysqldump`/`mariadb-dump`, as for the WordPress database. Restoring a dump runs it on the saved connection with that ID. The same connection backs up and restores, so its account needs the grants of both.

## Account

Use an account that holds privileges on its schema and nowhere else. The account's grants are what keep a restore inside that schema:

```sql
CREATE USER 'bp_shop'@'%' IDENTIFIED BY '...';
GRANT SELECT, INSERT, CREATE, DROP, ALTER, REFERENCES, LOCK TABLES,
      CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE
   ON `shop`.* TO 'bp_shop'@'%';
```

These grants were checked on MariaDB 11.8 with both exporters. The account exported a schema containing tables with a foreign key, a view, a trigger, a procedure and a function, then restored it.

* Do not give the account global privileges (`*.*`), such as `FILE`, `SUPER`, `PROCESS`, `CREATE USER`, `SET_USER_ID` or `SYSTEM_VARIABLES_ADMIN`. Do not give it privileges on any other schema.
* Views, triggers and routines are recreated with the `DEFINER` recorded in the dump. Without `SET_USER_ID` (MySQL) or `SUPER` (MariaDB), the account can recreate only objects whose definer is itself. Create them as this account on the source, or the restore stops at the first other definer.
* On a server with binary logging, an account without `SUPER` can create triggers and routines only when the DBA sets `log_bin_trust_function_creators = ON`.

## Restore checks

Before each statement of an external dump runs, `BackupPlus_External_SQL_Guard` reads it the way the server will. It drops comments, and it runs or skips `/*!…*/` and `/*M!…*/` comments as the server's version would. It reads quotes under the session's current `sql_mode`, re-read after every `SET`. The statement is refused, and the restore stops before it runs, when it:

* is not one the exporters write: session `SET`, `INSERT`, `REPLACE`, `LOCK TABLES`, `UNLOCK TABLES`, `ALTER TABLE`, `COMMIT`, or `CREATE`/`DROP` of a table, view, trigger, procedure or function. `USE`, `CREATE DATABASE`, `CALL`, `GRANT`, `CREATE USER`, `CREATE EVENT` and `SET STATEMENT … FOR` are refused, for example;
* qualifies a name with any schema the account can see other than the target, anywhere in the statement, including view definitions and trigger and routine bodies;
* uses dynamic SQL (`PREPARE`, `EXECUTE`), changes global or persisted variables, or reads or writes server files (`LOAD_FILE`, `INTO OUTFILE`, `INTO DUMPFILE`);
* contains a second statement, a comment nested in an executable comment, or an executable comment whose version MySQL and MariaDB would read differently.

A session character set in which a backslash byte can end a multibyte character (Big5, GBK, GB18030, Shift-JIS, CP932) stops the restore. The restore also refuses to start when `MYSQL_CLIENT_FLAGS` enables multiple statements.

These checks are a second line of defence; the account's grants are the boundary. For example, an `INSERT` can call a stored function that already exists in the target schema, and the check cannot see what that function does.
