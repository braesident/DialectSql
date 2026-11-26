# Changelog

## Unreleased – 1.0.0

- Gemeinsame Upsert-API mit conflictColumns, ausdrücklichem SET, Incoming-/Bestandswerten, Parameterunterstützung und PostgreSQL-/SQL-Server-Rückgaben ergänzt.
- MySQL/MariaDB verwenden ausdrücklich gewähltes any_unique-Verhalten; PostgreSQL nutzt ON CONFLICT, SQL Server die Upsert-Teilmenge von MERGE mit HOLDLOCK. Mehrzeilen-, Schlüssel- und Grenzfälle sind durch Regressionstests und neue Integrationstests erfasst.
- MySQL-OFFSET ohne LIMIT und SQL-Server-SELECT mit LIMIT 0 korrigiert; unzulässige MySQL-DML-Joins mit LIMIT sowie SQL-Server-JOIN USING werden abgelehnt.
- Strukturierte Eingabeprüfung und gemeinsame Dialekt-/Literal-/Bezeichnerregeln ergänzt; ungültige Grenzen, unbekannte Felder/Dialekte/Typen und nicht unterstützte Spaltenoptionen werden als Fehler gemeldet.
- `col` quotet explizite Referenzen für alle Dialekte; `param` unterstützt geprüfte PDO-Platzhalter. SQL-Server-Textliterale erhalten N-Präfix, Backslashes werden dialektgerecht erhalten.
- Leere IN-/NOT-IN-Listen und NULL-Vergleiche definiert; WhereBuilder erhält leere Filter, leere Strings und NULL-Werte.
- SELECT-CTEs einschließlich Rekursion, globale UNION-Sortierung/Paginierung und gemeinsame DML-Rückgabe über PostgreSQL RETURNING / SQL Server OUTPUT ergänzt.
- Separate Integrationstests, Docker-Testserver und CI-Workflow ergänzt; Standardtests bleiben serverfrei.
- SQL-Funktionsmatrix mit Implementierungsstand, Anwendung, Dialektunterschieden und offenen Projektaufgaben ergänzt.
- PostgreSQL-Dialekt mit SELECT, DML, Tabellen-/View-/Trigger-DDL, Typabbildungen und expliziten Grenzen eingeführt; Dialekt-Maps, PDO-Erkennung und JSON-Schema erweitert.
- Verschachtelte PHP-Objekte in Builder-Definitionen und camelCase-Felder im Renderer erhalten; strukturierte Datumsarithmetik korrigiert.
- Projektkonzept und Regressionstests für PostgreSQL und gemeinsame Dialektübersetzungen ergänzt.
- Eigenständiges Composer-Paket ohne Pflichtabhängigkeit auf die anderen ausgegliederten Pakete.
- Übernahme der bestehenden API und ihres Verhaltens mit neuem Namespace.
