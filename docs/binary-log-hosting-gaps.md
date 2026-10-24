# Deferred PITR support: AWS RDS and Percona

Status: deferred until needed. These gaps do not expand the initial PITR release scope or commit to an implementation. Existing ordinary logical backups remain available subject to their own database permissions and compatibility.

The native dump, client and binlog executables run from WordPress scheduled PHP requests and connect to the source over verified TLS. Database hosting capabilities are separate from the MySQL/MariaDB engine family and from BackupPlus's remote archive storage providers.

| Deployment | Current PITR status |
| --- | --- |
| AWS RDS for MySQL or MariaDB | Unsupported end to end. The current coordinated full snapshot still requires a brief startup global read lock and a persistent schema guard; their managed-service availability and privileges are not validated. Service-specific capability checks and recovery tests are also missing. |
| Percona Server for MySQL 8.4 | Unvalidated. It can pass the current MySQL-family checks, but there is no Percona integration matrix or support guarantee. |
| Percona XtraDB Cluster | Unvalidated separately; standalone Percona Server compatibility would not establish cluster/failover support. |

Passing the existing capability probe does not certify an untested deployment. Client detection currently distinguishes MariaDB from the MySQL family; it does not enforce a complete client/server version compatibility matrix. Percona is treated as part of the MySQL family.

## AWS RDS gaps

1. **Coordinated full baseline.** BackupPlus now uses `--single-transaction --flush-logs`, commented source coordinates and a separate schema backup guard. This removes the previous full-duration write block but still requires a brief startup global read lock. AWS documents that RDS does not support the global read lock required by the coordinate-bearing dump operation. Removing those options does not establish a consistent snapshot with matching coordinates and is not a PITR workaround. [AWS dump limitations](https://repost.aws/knowledge-center/mysqldump-error-rds-mysql-mariadb)
2. **Binary-log retention and retrieval.** AWS documents remote `mysqlbinlog` access for RDS for MySQL with replication permissions. Its retention policy uses `mysql.rds_set_configuration('binlog retention hours', ...)` and must be inspected through the RDS configuration interface. The adapter currently checks `@@global.binlog_expire_logs_seconds`; that alone cannot establish the managed service's retention guarantee. RDS for MariaDB needs its own validated client/permission matrix. [AWS binary-log access and retention](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_LogAccess.MySQL.Binarylog.html)
3. **Capabilities and schema scope.** The current adapter expects conventional global grants, direct log rotation, native event formats and verified application-database scope. RDS administrative schemas, roles and restricted operations need explicit handling. Ignoring managed restrictions or weakening transaction/DDL validation would not prove safe replay.
4. **Recovery environment.** Current reconstruction requires an empty, isolated server with the recorded server version, binary logging disabled, the event scheduler disabled and no replication channels. RDS administrative schemas, configurable settings and availability of matching recovery builds need validation. Restoring to a compatible external server would also require a tested compatibility policy.
5. **Integration evidence.** There are no RDS tests for snapshot/coordinate agreement, managed retention, log rotation, permission restrictions, TLS, failover ancestry or whole/selected-table recovery. The existing local MySQL/MariaDB container matrix does not supply that evidence.

Possible baseline approaches, if RDS support becomes necessary, are a replica frozen at verified original-source coordinates with local writes and DDL excluded, or a separate provider-native PITR workflow. Both require design and end-to-end validation before use.

AWS-native PITR restores into a new instance. A future integration could export the recovered database or selected tables from that instance. That would be a separate recovery workflow; it is not implemented by the current portable full-dump/binlog chain. [AWS point-in-time recovery](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_PIT.html)

Aurora would need a separate hosting capability profile and acceptance tests; RDS support would not automatically establish Aurora support.

## Percona gaps

1. **Client/server matrix.** Test Percona Server 8.4 explicitly with the intended upstream MySQL and Percona client builds. The current version/vendor probes can admit it, but no Percona combinations have been validated. Percona 8.0 is outside the current MySQL 8.4 server allowlist.
2. **Snapshot strategy.** The implemented strategy is now a transactional snapshot with a persistent MySQL-family `LOCK INSTANCE FOR BACKUP` guard and verification of the covering log interval. This has not been tested on Percona. Percona offers `mysqldump --single-transaction --lock-for-backup`, but BackupPlus does not select or validate that mechanism. Any future use must prove DDL protection and agreement between the snapshot and recorded coordinates under concurrent writes. [Percona extended mysqldump](https://docs.percona.com/percona-server/8.4/extended-mysqldump.html)
3. **Storage and log features.** Application tables must still be InnoDB. MyRocks and other engines are outside the current scope. Percona-specific column formats, dump extensions and binary-log features need explicit tests; unsupported events must continue to fail closed.
4. **Recovery builds.** The existing recovery check compares the recorded server version string, allowing only a trailing `-log` difference. A Percona baseline therefore cannot assume recovery onto an upstream MySQL server with the same base version. Test matching Percona recovery builds before considering any broader compatibility policy.
5. **Clusters and failover.** A standalone-server test does not validate Percona XtraDB Cluster, proxy routing or a changed source after failover. Those deployments need separate ancestry, transaction-boundary and recovery tests.

## Conditions for revisiting

When a deployment requires this support, define its engine/client builds and hosting capabilities, choose a coordinated baseline strategy, and add tests for full snapshot plus multiple increments, an exact cutoff inside a later increment, whole-database recovery and selected-table historical recovery. Also verify restricted permissions, source-log loss, configuration changes and restart/failover behavior.

Keep the existing snapshot/coordinate, source ancestry, checksum and complete-chain requirements. No runtime behavior or validation has been relaxed as part of documenting these gaps.
