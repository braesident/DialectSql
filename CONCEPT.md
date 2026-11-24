# Projektkonzept und Entwicklungsrichtlinien

## Aufgabe und Umfang

DialectSql erzeugt SQL aus PHP-Arrays, PHP-Objekten und JSON-Definitionen für MySQL (einschließlich MariaDB), Microsoft SQL Server und PostgreSQL. Das Paket baut SQL-Strings und führt selbst keine Statements aus. Datenbankverbindungen, Transaktionen und Parameterbindung bleiben Aufgabe der Anwendung.

## Technik und Struktur

- PHP ab 8.0, `declare(strict_types=1)`, Namespace `Braesident\DialectSql`, PSR-4 unter `src/`.
- Pflichtabhängigkeiten bleiben PHP, `ext-mbstring` und `ext-pdo`; CipherPDO und DbMigration sind optionale Integrationen.
- `SqlBuilder` ist der Einstieg für DDL und DML; `SelectBuilder` verarbeitet SELECT-Strukturen und Ausdrücke. `WhereBuilder` sammelt Bedingungen und Parameter, `SqlRenderer` erkennt den PDO-Treiber und liefert bei Bedarf eine flache Statement-Liste.
- PostgreSQL-spezifische DDL-Erzeugung liegt in `PostgreSqlBuilder`; gemeinsame SELECT- und DML-Verarbeitung bleibt in den vorhandenen Buildern.
- Gemeinsame Dialekt-/Bezeichner-/Literalregeln liegen in `Dialect`, die strukturelle Eingabeprüfung in `DefinitionValidator`. Interne Helfer sind keine zusätzliche öffentliche Builder-API.
- Dialektschlüssel: `mysql`, `sqlsrv`, `pgsql`. Für PostgreSQL werden auch `postgres` und `postgresql` akzeptiert. MariaDB verwendet den MySQL-Dialekt mit optionalen Produkt-/Versionsinformationen.
- PostgreSQL-Identity-Spalten setzen PostgreSQL 10 oder neuer voraus. Neuere SQL-Funktionen sind nur bei dokumentierter Serverunterstützung verwendbar.

## Öffentliche Definitionen und Dialektunterschiede

- Bestehende öffentliche APIs und JSON-Feldnamen erhalten. Neue Fähigkeiten in `src/definition.schema.json` beschreiben.
- Gleiche Absichten erhalten möglichst dieselben Felder, etwa `limit` und `offset`. Der Builder wählt die passende SQL-Syntax.
- Fehlende Dialektfunktionen oder semantisch unsichere Übersetzungen ausdrücklich mit `InvalidArgumentException` ablehnen; keine stillschweigende Umdeutung oder Nichtbeachtung in neuen Implementierungen.
- Dialektabhängige Funktionen dürfen explizite Zusatzfelder oder Dialekt-Maps benötigen. Raw-SQL bleibt ein bewusster Ausweg und zählt nicht als strukturierte Unterstützung.
- Die [SQL-Funktionsmatrix](SQL_FEATURES.md) dokumentiert Implementierungsstand, Anwendung und Grenzen für alle drei Dialekte. Bei Änderungen an SQL-Funktionen auch die betroffenen Einträge und Beispiele aktualisieren.
- Strings in DML-Werten sind Literale; SQL-Ausdrücke und PDO-Platzhalter ausdrücklich mit Ausdrucksobjekten beziehungsweise `raw` angeben. Der Aufrufer bindet Parameterwerte selbst.
- Explizite `col`-Referenzen werden für alle Dialekte gequotet; `param` bezeichnet einen geprüften PDO-Platzhalter. Bestehende Stringausdrücke bleiben SQL. SQL Server erhält Unicode-Literale mit N-Präfix.
- Unbekannte Felder in SELECT-/Builder-/Spaltendefinitionen und ungültige Limits sind Fehler. Numerische Ganzzahlstrings werden für Limits aus Kompatibilitätsgründen akzeptiert. Leeres IN ist falsch, leeres NOT IN wahr; WhereBuilder entfernt leere Filter nicht automatisch.
- SELECT-CTEs verwenden `with` und optional `recursive`; globale UNION-Sortierung/Paginierung erhält eigene `union_*`-Felder. DML-Zielspalten werden über `returning` angefordert: PostgreSQL RETURNING, SQL Server OUTPUT; MySQL lehnt die Option ab.

## Qualität und Dateien

- Änderungen mit `php tests/run.php` beziehungsweise `composer test` prüfen. Tests benötigen keinen Datenbankserver und keine optionalen Integrationen. Neue Dialektübersetzungen und bewusst abgelehnte Kombinationen durch aussagekräftige Regressionstests abdecken.
- Serverfreie Tests bleiben der Standard. Separate Integrationstests (`php tests/integration.php`) prüfen die tatsächliche Ausführung gegen alle drei Dialekte; `--require-all` verhindert still übersprungene Pflichtläufe. Sie verwenden ausschließlich explizit konfigurierte Test-DSNs und räumen nur ihre eigenen, zufällig benannten Objekte auf. Anwendungskonfiguration und Anwendungsdatenbanken werden nicht automatisch herangezogen.
- `compose.test.yaml` stellt isolierte Testserver bereit; der CI-Workflow führt serverfreie Tests unter PHP 8.0/8.3 und Integrationstests mit allen drei Testservern aus. Ohne durchgeführten CI-/Datenbanklauf nur die lokal geprüften Teile als erfolgreich melden.
- Ohne echten Datenbanklauf nur SQL-Erzeugung als geprüft bezeichnen. JSON-Schema und Dokumentation zusammen mit der Implementierung pflegen.
- Zwei Leerzeichen für PHP-Einrückung und den vorhandenen Stil beibehalten. UTF-8 ohne BOM und ausschließlich LF verwenden; vorhandene CRLF-Zeilenumbrüche beim Bearbeiten bereinigen.
- Antworten auf Deutsch. Nach Dateiänderungen eine englische Git-Commit-Nachricht im Chat angeben. Keine Commits automatisch ausführen.
