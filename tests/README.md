# Tests

## Serverfreie Regressionstests

```shell
php tests/run.php
```

Alternativ `composer test`. PHP-Warnungen und Notices werden als Testfehler behandelt. Die Tests benötigen keine laufende Datenbank und keine PDO-Treiber für einzelne Datenbanksysteme.

[Portabilitätstests](portability.php) prüfen typisierte PostgreSQL-Boolean-Defaults einschließlich numerischer Typ-Overrides, explizite Spaltenreferenzen in ORDER BY/UNION sowie WhereBuilder-Ausdrücke, Parameter, Gruppen und IN-Listen für alle drei Dialekte.

## Integrationstests

```shell
php tests/integration.php
php tests/integration.php --require-all
php tests/integration.php --dialect=mysql --require-all
```

Die Integrationstests führen generierte Statements wirklich aus. Sie vergleichen gespeicherte Werte und Ergebnismengen für Unicode, Apostrophe, Backslashes, Parameter, NULL/IN, Paginierung, rekursive SELECT-CTEs, globale UNION-Paginierung, Join-Änderungen, Tabellenänderungen und Views. PostgreSQL und SQL Server prüfen außerdem INSERT-/UPDATE-/DELETE-Rückgaben.

[Upsert-Integrationstests](integration-upsert.php) ergänzen neue und vorhandene Zeilen in einem Batch, zusammengesetzte UNIQUE-Schlüssel, automatisch erzeugte IDs, Incoming-/Bestandswerte, Parameter und Rückgaben. Funktionen und CASE kombinieren Eingabe- und Bestandsreferenzen auch verschachtelt. Eine zweite UNIQUE-Bedingung prüft gezielt den dokumentierten Unterschied: MySQLs ausdrücklich gewähltes `any_unique` aktualisiert die kollidierende Zeile; PostgreSQL und SQL Server melden außerhalb des gewählten Schlüssels eine Constraint-Verletzung.

[Typ- und Ausdruckstests](integration-portability.php) ergänzen numerisch angegebene Boolean-Defaults bei CREATE/ADD, numerische PostgreSQL-Typ-Overrides, typisiertes MODIFY bei MySQL/PostgreSQL und gequotete Spalten mit gemischter Groß-/Kleinschreibung in WHERE, ORDER BY und globalen UNION-Abfragen. Auch Funktionsausdrücke und IN-Objekte werden mit gebundenen Parametern ausgeführt. Die neuen Serverfälle sind lokal ohne Test-DSNs nicht ausgeführt; ihr CI-Nachweis steht noch aus.

CI-Stand vom 26.11.2026: Im [GitHub-Lauf 45794303822](https://github.com/braesident/DialectSql/actions/runs/45794303822) waren `unit (8.0)`, `unit (8.3)` und die SQL-Server-/PostgreSQL-Integration erfolgreich. MySQL scheiterte mit Fehler 1052 an der mehrdeutigen Bestandsreferenz `quantity`. Die Korrektur qualifiziert Bestandsreferenzen mit der Zieltabelle; ihre Ausführung und die zusätzlichen Funktionen-/CASE-Fälle stehen im nächsten CI-Lauf noch aus. Lokal sind dafür weiterhin passende DSNs und PDO-Treiber erforderlich.

Verbindungen werden ausschließlich aus folgenden Umgebungsvariablen gelesen:

| Dialekt | DSN | Benutzer | Passwort | PDO-Treiber |
| --- | --- | --- | --- | --- |
| MySQL | `DIALECTSQL_TEST_MYSQL_DSN` | `DIALECTSQL_TEST_MYSQL_USER` | `DIALECTSQL_TEST_MYSQL_PASSWORD` | `pdo_mysql` |
| SQL Server | `DIALECTSQL_TEST_SQLSRV_DSN` | `DIALECTSQL_TEST_SQLSRV_USER` | `DIALECTSQL_TEST_SQLSRV_PASSWORD` | `pdo_sqlsrv` |
| PostgreSQL | `DIALECTSQL_TEST_PGSQL_DSN` | `DIALECTSQL_TEST_PGSQL_USER` | `DIALECTSQL_TEST_PGSQL_PASSWORD` | `pdo_pgsql` |

Ohne DSN wird der Dialekt ausdrücklich übersprungen. Mit `--require-all` gilt jede fehlende Verbindung als Fehler; zusammen mit `--dialect` gilt die Pflicht für den ausgewählten Dialekt. Eine konfigurierte, aber fehlerhafte Verbindung oder ein fehlender Treiber ist immer ein Fehler. Die Tests legen keine Datenbank an und lesen keine Anwendungskonfiguration. Tabellen und Views erhalten pro Lauf zufällige Namen und werden im `finally`-Block entfernt, sofern der Prozess regulär beendet wird. Nur nach erfolgreicher Erstellung registrierte Testobjekte werden gelöscht.

Eine separate Testdatenbank mit CREATE-/ALTER-/DROP-/DML-Rechten verwenden. Die Tests setzen keinen generellen Transaktions-Rollback voraus, weil DDL-Transaktionen zwischen den Dialekten unterschiedlich funktionieren.

## Lokale Docker-Testserver

Voraussetzungen: Docker Compose mit Linux-Containern, für SQL Server ein x86-64-Host, PHP mit den drei genannten PDO-Treibern. Die bereitgestellten Passwörter sind ausschließlich lokale Testwerte. Die Ports sind an `127.0.0.1` gebunden. Bestehende Datenbankdienste auf Standardports werden nicht benötigt.

```shell
docker compose -f compose.test.yaml up -d --wait --wait-timeout 180
```

Beispielkonfiguration für PowerShell:

```powershell
$env:DIALECTSQL_TEST_MYSQL_DSN = 'mysql:host=127.0.0.1;port=13306;dbname=dialectsql_test;charset=utf8mb4'
$env:DIALECTSQL_TEST_MYSQL_USER = 'root'
$env:DIALECTSQL_TEST_MYSQL_PASSWORD = 'DialectSql_test_123!'
$env:DIALECTSQL_TEST_PGSQL_DSN = 'pgsql:host=127.0.0.1;port=15432;dbname=dialectsql_test;connect_timeout=5'
$env:DIALECTSQL_TEST_PGSQL_USER = 'dialectsql'
$env:DIALECTSQL_TEST_PGSQL_PASSWORD = 'DialectSql_test_123!'
$env:DIALECTSQL_TEST_SQLSRV_DSN = 'sqlsrv:Server=127.0.0.1,11433;Database=tempdb;Encrypt=1;TrustServerCertificate=1;LoginTimeout=5'
$env:DIALECTSQL_TEST_SQLSRV_USER = 'sa'
$env:DIALECTSQL_TEST_SQLSRV_PASSWORD = 'DialectSql_test_123!'
php tests/integration.php --require-all
```

Unter Linux dieselben Variablen mit `export NAME='Wert'` setzen. Nach dem Testlauf die zugehörigen Testcontainer und deren Testvolumes entfernen:

```shell
docker compose -f compose.test.yaml down -v
```

Der [CI-Workflow](../.github/workflows/tests.yml) startet diese drei Server und verlangt alle Dialekte. Ein angelegter Workflow ist noch kein Nachweis für einen erfolgreich durchgeführten CI-Lauf. Einzelne lokale Dienste reichen ohne passende DSNs und Treiber nicht für einen vollständigen Integrationstest.
