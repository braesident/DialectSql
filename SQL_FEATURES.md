# SQL-Funktionsmatrix und Projektliste

Stand: 26.11.2026. Diese Liste beschreibt den aktuellen Quellcode einschließlich des PostgreSQL-Dialekts, der Eingabeprüfung und der SELECT-/DML-/Upsert-Funktionen. Sie dient zugleich als Bestandsaufnahme und Arbeitsliste. SQL-Befehle, Klauseln, Operatoren und wichtige Funktionsgruppen werden gemeinsam erfasst, weil beispielsweise `LIMIT` kein eigenständiger Befehl ist.

Die Liste deckt die üblichen Abfragen, Datenänderungen, Schemaänderungen, Datentypen, Transaktionen sowie größere Verwaltungsbereiche ab. Sie ist kein vollständiger Katalog sämtlicher Herstellerfunktionen, Serveroptionen, Erweiterungen oder versionsabhängiger Schlüsselwörter. Seltene Verwaltungsbefehle sind teilweise als Befehlsfamilie zusammengefasst.

## Lesen und anwenden

| Status | Bedeutung im Projekt |
| --- | --- |
| **Ja** | Strukturierte Unterstützung vorhanden; die Anwendung steht in der letzten Spalte. |
| **Teil** | Nur Teilumfang, generische Ausdrucksausgabe oder bekannte Einschränkungen; Details stehen dabei. |
| **Offen** | Im SQL-Dialekt vorhanden, aber ohne eigene strukturierte Projekt-API. Explizites SQL ist über `raw` beziehungsweise Dialekt-Maps möglich. |
| **—** | Diese Syntax gibt es in diesem Dialekt nicht; mögliche Entsprechungen stehen dabei. |

**„Offen“ bedeutet nicht „unmöglich“.** Häufig lässt sich eine gemeinsame API ergänzen. **„Dialektspezifisch“** bezeichnet dagegen Unterschiede, deren Syntax oder Bedeutung eine bewusste Entscheidung des Aufrufers erfordert. Auch „Ja“ garantiert keine identische Laufzeitsemantik aller Datenbanken, etwa bei NULL, Kollation oder Zeitstempeln.

In den Tabellen stehen kurze JSON-Ausschnitte. SELECT-Strukturen werden an `SelectBuilder::build($query, $dialect)` oder `SqlRenderer::render($query)` übergeben. Definitionen mit `type` gehen an `SqlBuilder::build($definition, $dialect)` oder den Renderer. In PHP funktionieren dieselben Strukturen als Arrays oder Objekte. `SqlBuilder::build()` akzeptiert außerdem einen JSON-String; beim `SelectBuilder` und Renderer zuerst `json_decode()` verwenden. Rückgaben können ein SQL-String oder mehrere Statements sein; `renderAll()` liefert eine flache Liste.

Dialektschlüssel sind `mysql`, `sqlsrv` und **`pgsql`**. PostgreSQL akzeptiert auch `postgres` und `postgresql`, einschließlich Kontext `{"dialect":"postgresql"}`, Dialekt-Maps und Renderer-Filtern. PDO-Treiber `pgsql` wird erkannt. MariaDB läuft über den MySQL-Dialekt; Unterschiede sind nicht generell als eigener Dialekt implementiert.

## 1. SELECT, Quellen und Ergebnismengen

Syntaxgrundlagen: [MySQL SELECT](https://dev.mysql.com/doc/refman/8.4/en/select.html), [SQL Server SELECT](https://learn.microsoft.com/en-us/sql/t-sql/queries/select-transact-sql?view=sql-server-ver17), [PostgreSQL SELECT](https://www.postgresql.org/docs/18/sql-select.html).

| Befehl / Klausel | MySQL | SQL Server | PostgreSQL | Anwendung / gemeinsame Umsetzung / Grenze |
| --- | --- | --- | --- | --- |
| SELECT | Ja | Ja | Ja | `{"select":["id","name"],"from":"products"}`; fluent: `select()->from()`. |
| SELECT ohne FROM | Ja | Ja | Ja | `{"select":{"value":1}}`. |
| SELECT * / alias.* | Ja | Ja | Ja | `{"select":"p.*","from":{"table":"products","as":"p"}}`. |
| Spaltenalias AS | Ja | Ja | Ja | `{"select":{"col":"p.name","as":"label"}}`; `select($expr, 'label')`. |
| DISTINCT | Ja | Ja | Ja | `{"distinct":true}`; `distinct()`. |
| SELECT ALL | Teil | Teil | Teil | Standardverhalten ohne `distinct`; kein ausdrückliches `all`-Feld. |
| DISTINCT ON | — | — | Offen | PostgreSQL-spezifische Auswahl je Gruppe; keine gemeinsame API. |
| FROM Tabelle / View | Ja | Ja | Ja | `{"from":"products"}` oder `{"from":{"table":"products","schema":"shop"}}`. |
| FROM Unterabfrage | Ja | Ja | Ja | `{"from":{"query":{"select":"id","from":"products"},"as":"p"}}`; Alias angeben. |
| Mehrere FROM-Quellen | Offen | Offen | Offen | Kein strukturiertes FROM-Array; Raw-Quelle oder spätere Cross-Join-API. |
| INNER JOIN / JOIN ON | Ja | Ja | Ja | `{"join":[{"table":"stock","as":"s","on":["p.id","=","s.product_id"]}]}`. |
| LEFT [OUTER] JOIN | Ja | Ja | Ja | `left_join` / `leftJoin`; fluent: `leftJoin()`. |
| RIGHT [OUTER] JOIN | Ja | Ja | Ja | `right_join` / `rightJoin`; fluent: `rightJoin()`. |
| JOIN USING | Ja | — | Ja | Join-Objekt `{"table":"stock","using":["id"]}`. SQL Server benötigt `ON`; USING wird ausdrücklich abgelehnt. |
| CROSS JOIN | Offen | Offen | Offen | Keine eigene strukturierte Join-Art. |
| FULL [OUTER] JOIN | — | Offen | Offen | MySQL benötigt eine bewusste Emulation; keine allgemeine Übersetzung vorhanden. |
| NATURAL JOIN | Offen | — | Offen | Spaltenabhängige Semantik; explizites ON ist die gemeinsame Alternative. |
| LATERAL / CROSS APPLY / OUTER APPLY | Offen | Offen | Offen | Verwandte Fähigkeiten, aber keine einfache Gleichsetzung aller Varianten. |
| STRAIGHT_JOIN | Offen | — | — | MySQL-spezifische Optimierersteuerung. |
| WHERE | Ja | Ja | Ja | `{"where":["id",">",0]}`; Bedingungen siehe Abschnitt 3. |
| GROUP BY | Ja | Ja | Ja | `{"group_by":["category_id"]}` / `groupBy()`. Keine Anpassung unterschiedlicher Gruppierungsregeln. |
| HAVING | Ja | Ja | Ja | `{"having":[{"fn":"count","args":["*"]},">",1]}`. |
| ORDER BY / ASC / DESC | Ja | Ja | Ja | `{"order_by":[{"expr":"id","dir":"DESC"}]}` / `orderBy('id', 'DESC')`. |
| NULLS FIRST / NULLS LAST | — | — | Teil | In PostgreSQL als `dir:"ASC NULLS LAST"` oder String möglich; keine portable NULL-Sortierung. |
| LIMIT / TOP / FETCH | Ja | Ja | Ja | Gemeinsames Feld `limit`; unterschiedliche Ausgabe, siehe Abschnitt 2. |
| OFFSET | Ja | Ja | Ja | Gemeinsames Feld `offset`; MySQL ohne Limit verwendet einen unbegrenzten Ersatzwert als SQL-String. |
| FETCH FIRST / NEXT als Eingabefeld | — | Offen | Offen | Es gibt kein Feld `fetch`; SQL Server erzeugt FETCH aus `limit`. PostgreSQL erzeugt LIMIT. |
| TOP PERCENT / WITH TIES | — | Offen | — | Kein `percent`-/`with_ties`-Feld; nicht auf ein festes Limit reduzierbar. |
| FETCH ... WITH TIES | — | — | Offen | PostgreSQL benötigt ORDER BY; keine API dafür. |
| UNION | Ja | Ja | Ja | `{"union":[{"select":"id","from":"archive"}]}` / `union()`; Teilabfragen werden geklammert. |
| UNION ALL | Ja | Ja | Ja | `union_all` / `unionAll`; fluent: `union($query, true)`. |
| ORDER BY / LIMIT über gesamtes UNION | Ja | Ja | Ja | `union_order_by`, `union_limit`, `union_offset` bzw. camelCase; äußere SELECT-Abfrage. Bestehendes `order_by`/`limit` betrifft weiterhin nur die erste Teilabfrage. |
| INTERSECT / INTERSECT ALL | Offen | Offen / — | Offen | Kein Set-Operator-Feld; MySQL ab 8.0.31, SQL Server ohne ALL. |
| EXCEPT / EXCEPT ALL | Offen | Offen / — | Offen | Kein Set-Operator-Feld; MySQL ab 8.0.31, SQL Server ohne ALL. |
| WITH / CTE | Ja | Ja | Ja | SELECT-CTEs: `with:[{"name":"recent","columns":["id"],"query":{"select":"id","from":"products"}}]`; fluent `with()`. MySQL ab 8, MariaDB ab 10.2.2 bei bekanntem Versionskontext. |
| WITH RECURSIVE / rekursive CTE | Teil | Teil | Teil | Zusätzlich `recursive:true`; SQL Server erhält WITH ohne RECURSIVE. Serverregeln und Rekursionsgrenzen bleiben unterschiedlich; kein SEARCH-/CYCLE-AST. |
| WINDOW / OVER / PARTITION BY | Offen | Offen | Offen | SQL-Fenster benötigen Raw-Ausdrücke; `fn` allein erzeugt kein OVER. |
| ROW_NUMBER / RANK / DENSE_RANK | Teil | Teil | Teil | Funktionsname durch `fn` ausgebbar, vollständige Fensterklausel nur raw; daher keine fertige Fensterunterstützung. |
| GROUPING SETS / CUBE | — | Offen | Offen | Keine strukturierte Gruppierung dieser Art. |
| ROLLUP | Offen | Offen | Offen | Syntaxunterschiede; `group_by`-Strings können Raw-Ausdrücke enthalten. |
| PIVOT / UNPIVOT | — | Offen | — | Dialektspezifisch; andere Systeme benötigen Aggregation oder Erweiterungen. |
| SELECT INTO neue Tabelle / CREATE TABLE AS | Offen | Offen | Offen | Kein eigenes Feld; außerdem unterschiedliche Tabellen- und Variablenbedeutung von INTO. |
| TABLE / VALUES als eigenständige Abfrage | Offen | — / Offen | Offen | SQL Server nutzt VALUES als Tabellenwertkonstruktor; keine gemeinsame Query-API. |
| TABLESAMPLE | — | Offen | Offen | Keine strukturierte API; Stichprobenmethoden unterscheiden sich. |
| FOR UPDATE / FOR SHARE | Offen | — | Offen | SQL Server arbeitet mit Lock-Hints; keine automatische Gleichsetzung. |
| NOWAIT / SKIP LOCKED | Offen | Offen | Offen | Lock-Syntax und Verhalten unterscheiden sich; kein gemeinsames Feld. |
| Optimierer- / Tabellen- / Index-Hints | Offen | Offen | Teil | Herstellerabhängig; PostgreSQL hat keine allgemeine eingebaute MySQL-/T-SQL-Hint-Syntax. |
| FOR JSON / FOR XML | — | Offen | — | SQL Server; andere Dialekte verwenden eigene JSON-/XML-Funktionen. |
| INTO OUTFILE / DUMPFILE | Offen | — | — | Dateiexport ist kein portables SELECT-Feld. |

## 2. Zeilenbegrenzung: ein Objekt, verschiedene Syntax

```php
$query = [
  'select' => '*',
  'from' => 'products',
  'order_by' => 'id',
  'limit' => 10,
  'offset' => 20,
];

SelectBuilder::build($query, 'mysql');
// SELECT * FROM `products` ORDER BY id LIMIT 20, 10

SelectBuilder::build($query, 'sqlsrv');
// SELECT * FROM [products] ORDER BY id OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY

SelectBuilder::build($query, 'pgsql');
// SELECT * FROM "products" ORDER BY id LIMIT 10 OFFSET 20
```

| Fall | MySQL-Ausgabe | SQL-Server-Ausgabe | PostgreSQL-Ausgabe | Bewertung |
| --- | --- | --- | --- | --- |
| SELECT, `limit:10` | `LIMIT 10` | `OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY` | `LIMIT 10` | Gemeinsame API vorhanden; TOP wird bei SELECT nur für limit=0 verwendet. |
| SELECT, zusätzlich `offset:20` | `LIMIT 20, 10` | `OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY` | `LIMIT 10 OFFSET 20` | Gemeinsame API vorhanden. |
| SELECT, nur `offset:20` | `LIMIT 20, 18446744073709551615` | `OFFSET 20 ROWS` | `OFFSET 20` | MySQL-Ersatzwert wird ohne PHP-Integerüberlauf als SQL-String erzeugt. |
| SELECT ohne ORDER BY | Kein ORDER BY ergänzt | `ORDER BY (SELECT 1)` ergänzt | Kein ORDER BY ergänzt | Keine garantierte Reihenfolge; für verlässliche Seiten eine eindeutige Sortierung angeben. |
| SELECT, `limit:0` | `LIMIT 0` | `SELECT [DISTINCT] TOP (0) ...` | `LIMIT 0` | SQL Server erhält kein FETCH 0; gilt auch bei Aggregaten, OFFSET und globalem UNION-Limit. |
| UPDATE, `limit:10` | `UPDATE ... LIMIT 10` | `UPDATE TOP (10) ...` | Fehler | PostgreSQL hat keine direkte UPDATE-LIMIT-Klausel. |
| DELETE, `limit:10` | `DELETE ... LIMIT 10` | `DELETE TOP (10) ...` | Fehler | PostgreSQL hat keine direkte DELETE-LIMIT-Klausel. |
| UPDATE/DELETE mit Join und Limit | Fehler | TOP vorhanden | Fehler | MySQL-Mehrtabellensyntax mit LIMIT wird abgelehnt; dies gilt auch für Alias-DELETE mit LIMIT in der bisherigen Mehrtabellensyntax. |

Für PostgreSQL lässt sich die Absicht begrenzter Datenänderungen mit einer ausdrücklichen Auswahl stabiler Schlüssel darstellen. Das ist eine bewusste Query-Struktur und keine automatische LIMIT-Emulation:

```json
{
  "type": "delete",
  "table": "products",
  "where": [
    "id", "IN",
    {"query": {
      "select": "id", "from": "products",
      "order_by": "id", "limit": 10
    }}
  ]
}
```

Für parallele Verarbeitung sind Schlüsselwahl, Sperren und Transaktionsgrenzen zusätzlich zu berücksichtigen. Die Unterabfrage ist keine Garantie für identisches Verhalten aller Datenbanken. Native Regeln: [SQL Server OFFSET/FETCH](https://learn.microsoft.com/en-us/sql/t-sql/queries/select-order-by-clause-transact-sql?view=sql-server-ver17), [TOP](https://learn.microsoft.com/en-us/sql/t-sql/queries/top-transact-sql?view=sql-server-ver17), [MySQL UPDATE](https://dev.mysql.com/doc/refman/8.4/en/update.html), [DELETE](https://dev.mysql.com/doc/refman/8.4/en/delete.html), [PostgreSQL UPDATE](https://www.postgresql.org/docs/18/sql-update.html), [DELETE](https://www.postgresql.org/docs/18/sql-delete.html).

## 3. Bedingungen, Operatoren und Ausdrücke

Strings sind in SELECT-Ausdrücken und Vergleichstripeln grundsätzlich SQL-Ausdrücke. Textwerte deshalb ausdrücklich mit `{"value":"Text"}` angeben. In INSERT-Werten, UPDATE-SET und der DML-WHERE-Kurzform sind Strings dagegen Literale. `{"col":"p.ProductId"}` quotet Spaltenreferenzen in allen Dialekten; einfache Stringausdrücke werden unverändert ausgegeben. SQL Server erhält N-Literale für Unicode. Backslashes werden in MySQL über einen UTF-8-Hex-Ausdruck und in PostgreSQL über E-Literale erhalten.

| Funktion / Operator | MySQL | SQL Server | PostgreSQL | Anwendung / Grenze |
| --- | --- | --- | --- | --- |
| =, <>, !=, <, <=, >, >= | Ja | Ja | Ja | `{"where":["id",">=",10]}`; `op`, `left`, `right` ebenfalls möglich. |
| AND / OR / NOT | Ja | Ja | Ja | `{"where":{"and":[["id",">",0],{"not":{"is_null":"name"}}]}}`. |
| Klammergruppen | Ja | Ja | Ja | Verschachtelte `and`-/`or`-Objekte; `WhereBuilder::andGroup()` / `orGroup()`. |
| IN / NOT IN, Werteliste | Ja | Ja | Ja | `["id","IN",[1,2]]`; Strings werden Literale. Leeres IN ergibt `1=0`, leeres NOT IN `1=1`; WhereBuilder erhält leere Filter statt sie zu entfernen. |
| IN / NOT IN, Unterabfrage | Ja | Ja | Ja | `["id","IN",{"query":{"select":"id","from":"archive"}}]`. |
| EXISTS / NOT EXISTS | Ja | Ja | Ja | `{"exists":{"select":{"value":1},"from":"stock"}}` / `not_exists`. |
| IS NULL / IS NOT NULL | Ja | Ja | Ja | `{"is_null":"deleted_at"}` / `is_not_null`; Vergleiche `= null`, `!= null`, `<> null` oder mit `value:null` werden ebenfalls umgeschrieben. |
| BETWEEN / NOT BETWEEN | Ja | Ja | Ja | `{"between":{"expr":"price","min":10,"max":20,"not":true}}`. |
| LIKE / NOT LIKE | Teil | Teil | Teil | Vergleichstripel mit `LIKE` und Literal; keine gemeinsame ESCAPE-/Kollationssteuerung. |
| ILIKE | — | — | Teil | `["name","ILIKE",{"value":"a%"}]`; generischer Operator, keine Übersetzung für andere Dialekte. |
| REGEXP / RLIKE / ~ / !~ | Teil | — | Teil | Generische Operatoren oder raw; Syntax, Flags und Regex-Engines unterscheiden sich. |
| NULL-sicherer Vergleich `<=>` / IS [NOT] DISTINCT FROM | Teil | Teil | Teil | Nur expliziter Operator/raw; keine portable Abbildung; SQL-Server-Unterstützung versionsabhängig. |
| ANY / SOME / ALL | Teil | Teil | Teil | Rechtsausdruck ausdrücklich über raw; keine eigene Quantoren-Struktur. |
| Arithmetik +, -, *, /, % | Teil | Teil | Teil | `{"op":"+","left":"price","right":1}`; generische Ausgabe, keine Typ-/Divisionsnormalisierung. |
| Bitoperatoren | Teil | Teil | Teil | Über `op`; Operatorschreibweise und Typen sind nicht vereinheitlicht. |
| Zeichenkettenverkettung | Teil | Teil | Teil | `fn:"concat"` generisch; NULL-Verhalten unterscheidet sich. `\|\|` ist keine allgemeine MySQL-Verkettung. |
| CASE WHEN / THEN / ELSE / END | Ja | Ja | Ja | `{"case":{"when":[{"cond":["id",">",0],"then":{"value":"ja"}}],"else":{"value":"nein"}}}`. |
| CAST / CONVERT / Typcasts :: | Teil | Teil | Teil | Spezialsyntax nur raw; `fn:"cast"` mit normalen Argumenten bildet `CAST(x AS type)` nicht ab. |
| COLLATE | Teil | Teil | Teil | Als Raw-Ausdruck möglich; Kollationsnamen sind dialektspezifisch. |
| Skalare Unterabfrage | Ja | Ja | Ja | `{"query":{"select":{"fn":"max","args":["id"]},"from":"products"}}`. |
| Zeilen-/Tupelvergleiche | Offen | Teil | Offen | Keine Tupel-AST; SQL Server hat nur Teilentsprechungen. |
| Boolesche Literale | Ja | Ja | Ja | PHP/JSON `true`/`false`: MySQL/SQL Server `1`/`0`, PostgreSQL `TRUE`/`FALSE`. |
| PDO-Platzhalter | Ja | Ja | Ja | `{"param":"id"}` / `SelectBuilder::param('id')`; Parameternamen werden geprüft. Bestehendes `{"raw":":id"}` bleibt möglich; Werte separat binden. |
| Upsert-Eingabewert / Bestandswert | Ja | Ja | Ja | Nur im Upsert-SET: `{"incoming":"stock"}` / `SelectBuilder::incoming('stock')` bezeichnet den Eingabewert, `{"col":"stock"}` den vorhandenen Wert. MySQL/MariaDB qualifizieren Bestandsreferenzen mit der Zieltabelle, um Mehrdeutigkeit mit dem Eingabealias zu vermeiden. Verschachtelte Ausdrücke, Funktionen und CASE sind möglich. |
| WhereBuilder + Parameterlisten | Ja | Ja | Ja | `andEqualsParam()`, `andIn()`, `orIn()`, `conditions()`, `params()`; erzeugt keine eigene Verbindung. |

Die generischen `op`-/`fn`-Felder prüfen nicht, ob ein beliebiger Operator oder Funktionsname auf dem Zielserver existiert. Syntaxübernahme ist daher nur **Teil**-Unterstützung und keine Dialektübersetzung.

## 4. INSERT, UPDATE, DELETE und Upsert

| Befehl / Variante | MySQL | SQL Server | PostgreSQL | Anwendung / gemeinsame Umsetzung / Grenze |
| --- | --- | --- | --- | --- |
| INSERT INTO ... VALUES | Ja | Ja | Ja | `{"type":"insert","table":"products","values":[{"name":"Öl","active":true}]}`. |
| Mehrzeilen-INSERT | Ja | Ja | Ja | `columns:["id","name"], values:[[1,"A"],[2,"B"]]` oder Objektzeilen. |
| INSERT ... SELECT | Ja | Ja | Ja | `columns:["id"], query:{"select":"id","from":"archive"}`; alternativ `select` als SQL/Dialekt-Map. |
| DEFAULT für einzelne INSERT-Werte | Ja | Ja | Ja | `{"default":true}`; automatisch generierte ID alternativ weglassen. In der gemeinsamen Upsert-API sind DEFAULT-Zellen und SET DEFAULT nicht unterstützt. |
| INSERT DEFAULT VALUES | — | Offen | Offen | Kein eigenes Feld für eine Zeile nur mit Standardwerten; MySQL hat abweichende Leersyntax. |
| INSERT ... SET | Offen | — | — | Keine API; derselbe Zweck ist mit `values` darstellbar. |
| INSERT IGNORE | Offen | — | — | Fehlerbehandlung unterscheidet sich von ON CONFLICT DO NOTHING. |
| Gemeinsames Upsert mit VALUES | Ja | Ja | Ja | `{"type":"upsert","table":"products","conflictColumns":["sku"],"values":[{"sku":"A","stock":5}],"set":{"stock":{"incoming":"stock"}},"mysqlConflict":"any_unique"}`. Nicht nullable PRIMARY-/UNIQUE-Schlüssel erforderlich; ein oder mehrere Datensätze. [Anwendung und Grenzen](README.md#upsert-einfügen-oder-aktualisieren). |
| ON DUPLICATE KEY UPDATE | Teil | — | — | Über `type:"upsert"`; `mysqlConflict:"any_unique"` ausdrücklich erforderlich, weil jeder UNIQUE-Konflikt auslösen kann. `conflictColumns` erzwingt hier kein Konfliktziel. Zeilenalias ab MySQL 8.0.19; bei bekanntem älteren MySQL/MariaDB VALUES(column). Kein INSERT-SELECT-Upsert. |
| ON CONFLICT (...) DO UPDATE | — | — | Teil | `conflictColumns` und `set` wählen das Ziel und die Änderungen. Neue Werte über `incoming`, vorhandene über `col`. Keine Indexausdrücke, Konfliktbedingungen oder INSERT-SELECT-Upserts. |
| ON CONFLICT DO NOTHING | — | — | Offen | Keine gemeinsame Ignorier-API; leeres SET wird ausdrücklich abgelehnt. |
| MERGE | — | Teil | Offen | SQL Server: Upsert-Teilmenge über `type:"upsert"`, zusammengesetztes ON, WHEN MATCHED UPDATE, WHEN NOT MATCHED INSERT und HOLDLOCK. Kein DELETE oder allgemeiner Synchronisierungsmodus; PostgreSQL-MERGE bleibt raw. |
| REPLACE INTO | Offen | — | — | Kann Löschen und Einfügen auslösen; keine allgemeine UPDATE-Entsprechung. |
| RETURNING | — | — | Teil | Gemeinsames `returning:["id",{"col":"name","as":"label"}]` oder `"*"`, auch für Upserts; Zielspalten, keine beliebigen Rückgabeausdrücke. MySQL lehnt ab. |
| OUTPUT inserted/deleted | — | Teil | — | Dasselbe `returning`-Feld: INSERT/UPDATE/Upsert nutzt INSERTED, DELETE DELETED. Kein OUTPUT INTO; Servergrenzen bei Triggern beachten. |
| INSERT/UPDATE/DELETE mit CTE | Teil | Offen | Teil | SELECT-CTE als INSERT-Quelle bei MySQL/PostgreSQL möglich. Eigenes WITH vor DML ist noch offen; SQL Server INSERT SELECT mit WITH wird abgelehnt. |
| UPDATE ... SET | Ja | Ja | Ja | `{"type":"update","table":"products","set":{"name":"neu"},"where":["id","=",1]}`. |
| SET aus Spalte / Ausdruck / Unterabfrage | Ja | Ja | Ja | `set:{"price":{"op":"+","left":"price","right":1}}`; Spalten ausdrücklich mit `col`. |
| INNER-JOIN-UPDATE | Ja | Ja | Ja | `join` + `set`: MySQL UPDATE JOIN, SQL Server UPDATE FROM JOIN, PostgreSQL UPDATE FROM mit Join-Bedingung im WHERE. |
| LEFT-/RIGHT-JOIN-UPDATE | Ja | Ja | Offen | PostgreSQL-Übersetzung bewusst abgelehnt; Outer-Join-Erhaltung benötigt eine ausdrückliche Unterabfrage. |
| UPDATE mehrerer Zieltabellen | Teil | — | — | MySQL kann entsprechende SET-Spalten ausgeben; keine eigene Mehrziel-API oder Prüfung. |
| DELETE FROM | Ja | Ja | Ja | `{"type":"delete","table":"products","where":["id","=",1]}`. |
| INNER-JOIN-DELETE | Ja | Ja | Ja | `join`: PostgreSQL übersetzt in DELETE USING und WHERE. |
| LEFT-/RIGHT-JOIN-DELETE | Ja | Ja | Offen | Für PostgreSQL bewusst abgelehnt; keine sichere allgemeine Umschreibung implementiert. |
| DELETE mehrerer Zieltabellen | Offen | — | — | Es gibt nur ein `table`-Ziel in der API. |
| DML-Zielalias | Ja | Ja | Ja | `as:"p"`; bei PostgreSQL nur Zielspalten in SET, Qualifizierung des Zielalias wird entfernt. |
| DML-WHERE als Objekt | Ja | Ja | Ja | `where:{"id":1,"deleted_at":null}` wird zu Vergleichen und IS NULL. Für SELECT explizite Bedingungen verwenden. |
| DML LIMIT / TOP | Teil | Ja | — | MySQL nur ohne Joins, bei DELETE auch ohne Alias-Mehrtabellensyntax. PostgreSQL wirft bei `limit` einen Fehler. |
| DML ORDER BY / OFFSET | Offen / — | — | — | Keine strukturierte DML-Sortierung. PostgreSQL lehnt solche Felder ausdrücklich ab. |
| WHERE CURRENT OF | Offen | Offen | Offen | Cursorverwaltung und positionierte Änderungen benötigen Raw-SQL. |
| TRUNCATE TABLE | Offen | Offen | Offen | Kein `type:"truncate"`; Identitäts-, FK- und Transaktionsverhalten unterscheiden sich. |
| LOAD DATA / LOAD XML | Offen | — | — | Importfunktionen gehören nicht zur bisherigen Builder-API. |
| BULK INSERT / OPENROWSET | — | Offen | — | SQL-Server-spezifisch. |
| COPY / \copy | — | — | Offen | COPY serverseitig nur raw; `\copy` ist ein psql-Clientbefehl und kein SQL-Statement. |

## 5. Tabellen, Spalten, Indizes und Constraints

DDL-Grundlagen: [MySQL DDL](https://dev.mysql.com/doc/refman/8.4/en/sql-data-definition-statements.html), [SQL Server CREATE TABLE](https://learn.microsoft.com/en-us/sql/t-sql/statements/create-table-transact-sql?view=sql-server-ver17), [PostgreSQL CREATE TABLE](https://www.postgresql.org/docs/18/sql-createtable.html), [ALTER TABLE](https://www.postgresql.org/docs/18/sql-altertable.html).

| Befehl / Variante | MySQL | SQL Server | PostgreSQL | Anwendung / gemeinsame Umsetzung / Grenze |
| --- | --- | --- | --- | --- |
| CREATE TABLE | Ja | Ja | Ja | `type:"create_table", table:"products", columns:[{"name":"id","type":"int"}]`. |
| IF NOT EXISTS bei Tabellen | Ja | Ja | Ja | `ifNotExists:true` ist Standard; SQL Server verwendet OBJECT_ID-Prüfung. |
| DROP TABLE / IF EXISTS | Ja | Ja | Ja | `type:"drop_table", table:"products", ifExists:true`; Standard true. |
| ALTER TABLE, mehrere Änderungen | Ja | Ja | Ja | `type:"alter_table", actions:[...]`; kann mehrere SQL-Statements liefern. |
| ADD COLUMN | Ja | Ja | Ja | Action `{"action":"add_column","column":{"name":"label","type":"string","length":80}}`. |
| MODIFY / ALTER COLUMN | Ja | Teil | Teil | Action `modify_column` mit `column`. SQL Server verbietet default/onUpdate/identity. PostgreSQL ändert Typ, nullable/default nur bei gesetztem Feld. |
| PostgreSQL ALTER TYPE ... USING | — | — | Offen | Explizite Konvertierung derzeit raw; Typwechsel müssen vom Server implizit konvertierbar sein. |
| DROP COLUMN | Ja | Ja | Ja | Action `{"action":"drop_column","name":"label"}`. |
| RENAME COLUMN | Teil | Ja | Ja | `from`, `to`; MySQL verwendet CHANGE COLUMN und benötigt die vollständige Spaltendefinition. SQL Server sp_rename; PostgreSQL RENAME COLUMN. |
| RENAME TABLE | Ja | Ja | Ja | Action `rename_table`, `to:"articles"`; PostgreSQL neuer Name ohne Schema. Folgeactions verwenden den neuen Namen. |
| FIRST / AFTER | Ja | — | — | Nur MySQL bei add/modify; PostgreSQL lehnt die Optionen ab. |
| PRIMARY KEY bei CREATE | Ja | Ja | Ja | `primaryKey:["id"]`. |
| ADD PRIMARY KEY | Ja | Ja | Ja | Action `add_primary_key`, `columns:["id"]`, optional `name`. |
| DROP PRIMARY KEY | Ja | Teil | Teil | Action `drop_primary_key`; SQL Server und PostgreSQL benötigen `name`. |
| UNIQUE bei CREATE | Ja | Ja | Ja | `unique:[{"name":"uq_name","columns":["name"]}]`; PostgreSQL-NULL-Verhalten entspricht nicht generell SQL Server. |
| ADD UNIQUE | Ja | Ja | Ja | Action `add_unique`, `name`, `columns` oder verschachteltes `unique`. |
| DROP UNIQUE | Ja | Ja | Teil | `drop_unique` per `name`; MySQL/SQL Server alternativ Spaltenlookup, PostgreSQL nur Name. |
| INDEX bei CREATE TABLE | Ja | Ja | Ja | `indexes:[{"name":"idx_name","columns":["name"]}]`; PostgreSQL liefert zusätzliche CREATE-INDEX-Statements. |
| CREATE / ADD INDEX | Ja | Ja | Ja | `alter_table` mit Action `add_index`; kein eigener Typ `create_index`. |
| DROP INDEX | Ja | Ja | Teil | Action `drop_index`; MySQL/SQL Server Name oder Spaltenlookup, PostgreSQL Name erforderlich und schemaqualifizierter DROP INDEX. |
| UNIQUE INDEX als eigener Indextyp | Offen | Offen | Offen | Stattdessen `add_unique` als Constraint; keine `unique:true`-Indexoption. |
| Clustered / Nonclustered Index | — | Offen | — | SQL Server; PostgreSQL CLUSTER hat andere Bedeutung. |
| Index INCLUDE / Filter / Ausdruck | Offen | Offen | Offen | Keine dedizierten Indexoptionen; Raw-SQL nötig. |
| Index FULLTEXT / SPATIAL / GIN / GiST / BRIN / HASH | Offen | Offen | Offen | Herstellerabhängige Indexfamilien; nicht jeder genannte Typ existiert in jedem Dialekt. |
| CREATE INDEX CONCURRENTLY / ONLINE | Teil | Offen | Offen | Keine API; MySQL arbeitet mit anderen Online-DDL-Optionen. |
| ALTER / RENAME INDEX | Offen | Offen | Offen | Keine Action; unterschiedliche Syntax und Fähigkeiten. |
| FOREIGN KEY bei CREATE | Ja | Ja | Ja | `foreignKeys:[{"name":"fk_category","columns":["category_id"],"refTable":"categories","refColumns":["id"]}]`. |
| ADD FOREIGN KEY | Ja | Ja | Ja | Action `add_foreign_key`, dieselben FK-Felder oder `foreignKey:{...}`. |
| DROP FOREIGN KEY | Ja | Ja | Teil | Name; MySQL/SQL Server alternativ Lookup mit `columns`/`refTable`, PostgreSQL nur benannter DROP CONSTRAINT. |
| ON DELETE / ON UPDATE CASCADE | Ja | Ja | Ja | FK-Felder `onDelete:"cascade"`, `onUpdate:"cascade"`. |
| RESTRICT / NO ACTION | Ja | Ja | Ja | SQL Server übersetzt RESTRICT zu NO ACTION; Verzögerungssemantik nicht vereinheitlicht. |
| SET NULL | Ja | Ja | Ja | FK-Feld `onDelete:"set_null"`; Spalten müssen dafür geeignet sein. |
| SET DEFAULT | Teil | Ja | Ja | Builder gibt es aus; MySQL/InnoDB unterstützt diese Aktion nicht. |
| DEFERRABLE / INITIALLY DEFERRED | — | — | Offen | PostgreSQL-spezifische Constraint-Eigenschaften ohne API. |
| CHECK bei CREATE | Teil | Ja | Ja | `checks:[{"name":"ck_price","expression":"price >= 0"}]`; MySQL vor 8.0.16 erzwingt CHECK nicht. |
| ADD / DROP CHECK | Teil | Ja | Ja | Actions `add_check` (`check` oder `expression`) / `drop_check` (`name`); bei älterem MySQL kann DROP anhand der Kontextversion entfallen, CHECK-Erzwingung bleibt versionsabhängig. |
| VALIDATE CONSTRAINT / WITH CHECK / NOCHECK | — | Offen | Offen | Unterschiedliche Vertrauens-/Validierungszustände; raw. |
| NULL / NOT NULL | Ja | Ja | Ja | Spaltenfeld `nullable`; bei CREATE Standard true, Identity in PostgreSQL implizit NOT NULL. |
| DEFAULT-Konstanten | Ja | Ja | Ja | Spaltenfeld `default`; Bool-Werte werden zu 1/0 beziehungsweise TRUE/FALSE. SQL Server verwendet Unicode-Textliterale. |
| DEFAULT aktueller Zeitstempel | Ja | Ja | Ja | `default:"CURRENT_TIMESTAMP"` oder `"NOW"`; SQL Server SYSDATETIME(). |
| Beliebige DEFAULT-Ausdrücke | Offen | Offen | Offen | Kein Ausdrucksobjekt für Defaults im Schema; bei komplexen Ausdrücken bewusst Raw-DDL verwenden. |
| DEFAULT ändern | Teil | — | Ja | Über `modify_column`; MySQL vollständige Spaltendefinition. SQL Server braucht eigene Constraint-Behandlung. |
| DROP DEFAULT | Ja | Ja | Ja | Action `drop_default`: MySQL `columns` oder `name` als Spaltenname, SQL Server `name` als Constraintname oder `columns` zum Lookup; PostgreSQL nur `columns`. |
| AUTO_INCREMENT / IDENTITY | Ja | Ja | Ja | `autoIncrement:true` oder `identity:true`; PostgreSQL `GENERATED BY DEFAULT AS IDENTITY` auf Ganzzahltypen. |
| Identity-Modus ALWAYS / Seed / Increment / Restart | Offen | Offen | Offen | Keine gemeinsame Konfiguration; bei PostgreSQL identity in modify_column ausdrücklich abgelehnt. |
| UNSIGNED | Ja | — | — | `unsigned:true`; PostgreSQL und SQL Server lehnen das Flag ausdrücklich ab. |
| ENUM-Wertebereich | Ja | Ja | Ja | `enum:["neu","aktiv"]`; MySQL ENUM, SQL Server NVARCHAR+CHECK, PostgreSQL VARCHAR+CHECK. Kein PostgreSQL CREATE TYPE. |
| Enum ändern | Teil | Teil | Offen | MySQL vollständige Spalte; SQL Server Constraint-Verwaltung begrenzt. PostgreSQL modify_column mit enum wird abgelehnt. |
| ON UPDATE CURRENT_TIMESTAMP | Ja | — | — | Spaltenfeld `onUpdate`; PostgreSQL und SQL Server benötigen explizite Logik und lehnen das Feld ab. |
| Spaltenkommentar | Ja | Ja | Ja | `comment:"Beschreibung"` bei CREATE; MySQL inline, SQL Server Extended Property, PostgreSQL COMMENT ON. |
| Kommentar bei ALTER ändern | Teil | Offen | Ja | PostgreSQL add/modify erzeugt COMMENT ON; MySQL modify inline, SQL-Server-ALTER aktuell ohne Kommentarweitergabe. |
| Tabellen-/Index-/Constraint-Kommentare | Offen | Offen | Offen | Keine gemeinsame Kommentar-API für diese Objekte. |
| Generierte / berechnete Spalten | Offen | Offen | Offen | Keine strukturierte Ausdrucksdefinition; Identity ist gesondert unterstützt. |
| TEMPORARY / #temp / UNLOGGED Tabellen | Offen | Offen | Offen | Keine eigene Tabellenoption; Lebensdauer und Sichtbarkeit unterscheiden sich. |
| CREATE TABLE LIKE / AS / SELECT | Offen | Offen | Offen | Keine strukturierte Klon-/CTAS-API; SQL Server nutzt abweichende Verfahren. |
| Partitionierung / Vererbung / Systemversionierung | Offen | Offen | Offen | Herstellerabhängige Modelle und Optionen, keine gemeinsamen Felder. |
| ENGINE / CHARSET / COLLATE / TABLESPACE | Teil | Offen | Offen | MySQL CREATE TABLE setzt fest `ENGINE = InnoDB`; keine allgemeine Options-API. |
| CASCADE / RESTRICT bei DROP | Offen | Offen | Offen | Kein Feld; SQL Server hat keine allgemeine DROP-CASCADE-Entsprechung. |
| ALTER TABLE ... SET SCHEMA | — | Offen | Offen | SQL Server ALTER SCHEMA TRANSFER; keine Action. |

## 6. Views, Trigger und programmierbare Objekte

| Befehl / Variante | MySQL | SQL Server | PostgreSQL | Anwendung / gemeinsame Umsetzung / Grenze |
| --- | --- | --- | --- | --- |
| CREATE VIEW | Ja | Ja | Ja | `type:"create_view", view:"active_products", query:{...}` oder `select` als SQL/Dialekt-Map. |
| CREATE OR REPLACE / ALTER VIEW | Ja | Ja | Ja | `replace:true` ist Standard. SQL Server liefert Dummy-CREATE bei Bedarf und ALTER VIEW als getrennte Statements. PostgreSQL erfordert kompatible bestehende View-Spalten. |
| DROP VIEW / IF EXISTS | Ja | Ja | Ja | `type:"drop_view", view:"active_products"`; `ifExists` standardmäßig true. |
| WITH CHECK OPTION / SECURITY / SCHEMABINDING | Offen | Offen | Offen | Keine eigene View-Optionsstruktur; nicht jede Option ist überall vorhanden. |
| Materialized View / Indexed View | — | Offen | Offen | Verschiedene Modelle; keine gemeinsame API für Erstellung oder Aktualisierung. |
| REFRESH MATERIALIZED VIEW | — | — | Offen | PostgreSQL-spezifisch. |
| CREATE TRIGGER | Ja | Ja | Teil | `type:"create_trigger", name, table, timing, event/events`; PostgreSQL braucht `function`, andere Dialekte `body`. |
| ALTER TRIGGER | Ja | Ja | Teil | `type:"alter_trigger"`; MySQL und PostgreSQL DROP+CREATE, SQL Server ALTER TRIGGER. |
| DROP TRIGGER | Ja | Ja | Ja | `type:"drop_trigger", name`; PostgreSQL zusätzlich `table`, optional `schema`. |
| BEFORE / AFTER / INSTEAD OF | Ja / Ja / — | — / Ja / Ja | Ja / Ja / Ja | `timing` kann Dialekt-Map sein; INSTEAD OF ist in PostgreSQL nur für passende Views und zeilenweise zulässig. |
| INSERT / UPDATE / DELETE als Triggerereignis | Ja | Ja | Ja | `event:"insert"` oder `events:["insert","update"]`; MySQL nur ein Ereignis pro Trigger. |
| TRUNCATE als Triggerereignis | — | — | Ja | PostgreSQL `event:"truncate", forEachRow:false`. |
| FOR EACH ROW / STATEMENT | Ja / — | — / Ja | Ja / Ja | `forEachRow`; SQL Server arbeitet statementweise mit inserted/deleted. |
| Triggerbody als Builder / SQL / Dialekt-Map | Ja | Ja | — | `body` mit INSERT/UPDATE/DELETE-Definitionen möglich. PostgreSQL führt eine bereits definierte Triggerfunktion aus. |
| Triggerfunktion mit Argumenten | — | — | Ja | `function:"audit.record_change", functionArgs:["product"]`; Funktion separat erstellen, siehe Beispiel. |
| OLD / NEW / inserted / deleted | Teil | Teil | Teil | Nur ausdrückliche SQL-/Spaltenausdrücke; keine automatische Zeilenmengenübersetzung. |
| WHEN / UPDATE OF / Transition Tables | — / Teil | Teil | Offen | Keine dedizierten Triggerfelder; vollständige Syntax über raw. |
| ENABLE / DISABLE TRIGGER | — | Offen | Offen | MySQL hat keine entsprechende allgemeine Umschaltung. |
| CREATE / ALTER / DROP FUNCTION | Offen | Offen | Offen | Keine Builder-Definition für Routinen, einschließlich PostgreSQL-Triggerfunktionen. |
| CREATE / ALTER / DROP PROCEDURE | Offen | Offen | Offen | Parameter, Sprache, Rückgaben und Bodies sind dialektspezifisch. |
| CALL / EXEC / EXECUTE | Offen | Offen | Offen | Keine strukturierte Aufruf-API. |
| DO / anonymer Block / BEGIN END | Offen | Offen | Offen | Unterschiedliche Blocksprachen; MySQL DO ist kein PostgreSQL DO. |
| CREATE / ALTER / DROP EVENT | Offen | — | — | MySQL Event Scheduler, keine gemeinsame Entsprechung. |
| CREATE / ALTER / DROP SEQUENCE | — | Offen | Offen | MariaDB kennt eigene Sequenzen; für MySQL hier keine Zusage. |
| CREATE / ALTER / DROP TYPE / DOMAIN | — | Offen / — | Offen | PostgreSQL-native Enums/Domains und SQL-Server-Typen benötigen Raw-DDL. |
| Synonym / Rule / Aggregate / Operator | — | Offen | Offen | Unterschiedliche Objektfamilien; keine gemeinsame Builder-API. |

PostgreSQL-Triggerbeispiel (die Funktion `audit.record_change()` muss bereits existieren und `RETURNS trigger` liefern):

```json
{
  "type": "create_trigger",
  "name": "trg_products_audit",
  "table": "products",
  "schema": "shop",
  "timing": "after",
  "events": ["insert", "update"],
  "forEachRow": true,
  "function": "audit.record_change",
  "functionArgs": ["products"]
}
```

Ausgabe: `CREATE TRIGGER "trg_products_audit" AFTER INSERT OR UPDATE ON "shop"."products" FOR EACH ROW EXECUTE PROCEDURE "audit"."record_change"('products')`. `EXECUTE PROCEDURE` bezeichnet hier eine Triggerfunktion und wurde für Kompatibilität mit PostgreSQL 10 gewählt; neuere Server akzeptieren diese Syntax weiterhin. Ein `body` wird nicht automatisch in PL/pgSQL übersetzt. [PostgreSQL CREATE TRIGGER](https://www.postgresql.org/docs/18/sql-createtrigger.html), [PostgreSQL 10 CREATE TRIGGER](https://www.postgresql.org/docs/10/sql-createtrigger.html).

## 7. Datentypen und Abbildungen

Diese Tabelle bezieht sich auf Spaltendefinitionen in CREATE/ALTER. Typnamen werden nur im hier angegebenen Umfang abgebildet. Ein abweichender Typ kann ausdrücklich über ein Dialektfeld wie `pgsql:"INET"` oder `raw` vorgegeben werden. Das ersetzt keine automatische Werte- oder Semantikübersetzung.

| Objektfeld `type` / Eigenschaft | MySQL-Ausgabe / Status | SQL-Server-Ausgabe / Status | PostgreSQL-Ausgabe / Status | Grenze |
| --- | --- | --- | --- | --- |
| `int` | INT, Ja | INT, Ja | INTEGER, Ja | Wertebereiche für signed INT vergleichbar. |
| `tinyint` | TINYINT, Ja | TINYINT, Ja | SMALLINT, Teil | SQL Server TINYINT ist nicht vorzeichenbehaftet; PostgreSQL-SMALLINT ist größer. |
| `smallint` | SMALLINT, Ja | SMALLINT, Ja | SMALLINT, Ja | UNSIGNED gesondert behandeln. |
| `bigint` | BIGINT, Ja | BIGINT, Ja | BIGINT, Ja | Große PHP-Werte gegebenenfalls als gebundene Parameter behandeln. |
| `boolean` | TINYINT(1), Ja | BIT, Ja | BOOLEAN, Ja | MySQL erzwingt nicht allein durch TINYINT(1) die Werte 0/1. |
| `string` / `varchar` | VARCHAR(length), Ja | NVARCHAR(length), Ja | VARCHAR(length), Ja | Standardlänge 255; Unicode und Kollation bleiben serverabhängig. |
| `text` | TEXT, Ja | NVARCHAR(MAX), Ja | TEXT, Ja | Größenlimits unterscheiden sich. |
| `mediumtext` / `longtext` | MEDIUMTEXT / LONGTEXT, Ja | NVARCHAR(MAX), Teil | TEXT, Teil | Keine identischen Maximalgrößen. |
| `json` | JSON, Ja | NVARCHAR(MAX), Teil | JSON, Ja | SQL Server erzwingt hier keinen JSON-CHECK; PostgreSQL JSON ist nicht JSONB. |
| `jsonb` | Offen | Offen | JSONB, Ja | In anderen Dialekten Typ explizit vorgeben; unbekannte Typnamen führen zu einem Fehler. |
| `tinyblob` | TINYBLOB, Ja | VARBINARY(255), Ja | BYTEA, Teil | PostgreSQL erhält kein 255-Byte-Limit. |
| `blob` / `mediumblob` / `longblob` | Native BLOB-Typen, Ja | VARBINARY(MAX), Teil | BYTEA, Teil | Unterschiedliche Größen- und Treiberbehandlung. |
| `datetime` | DATETIME, Ja | DATETIME2, Ja | TIMESTAMP WITHOUT TIME ZONE, Ja | Zeitzonen- und Genauigkeitsregeln nicht automatisch angeglichen. |
| `timestamp` | TIMESTAMP, Teil | DATETIME2, Teil | TIMESTAMP WITHOUT TIME ZONE, Teil | MySQL-TIMESTAMP kann zeitzonenabhängig sein; SQL Server TIMESTAMP bedeutet nativ etwas anderes als diese Projektabbildung. |
| `date` | DATE, Ja | DATE, Ja | DATE, Ja | Zulässige Datumsbereiche unterscheiden sich. |
| `decimal` | DECIMAL(p,s), Ja | DECIMAL(p,s), Ja | NUMERIC(p,s), Ja | Standard precision 10, scale 2. |
| `time` | Offen | Offen | TIME WITHOUT TIME ZONE, Ja | Andere Dialekte benötigen aktuell Typoverride. |
| `timestamptz` | — | — | TIMESTAMP WITH TIME ZONE, Ja | Keine automatische Abbildung auf SQL Server DATETIMEOFFSET. |
| `uuid` | Offen | Offen | UUID, Ja | MySQL CHAR/BINARY bzw. SQL Server UNIQUEIDENTIFIER nur explizit. |
| `float` / `real` / `double` | Offen | Offen | REAL / REAL / DOUBLE PRECISION, Ja | Bestehender MySQL-/SQL-Server-Mapper kennt diese Namen nicht. |
| `char` / `nvarchar` / `numeric` | Teil | Teil | CHAR / VARCHAR / NUMERIC, Ja | MySQL/SQL Server erkennen nvarchar als Stringalias und numeric als Decimalalias; char benötigt dort einen Typoverride. |
| `enum:[...]` | ENUM, Ja | NVARCHAR + CHECK, Ja | VARCHAR + CHECK, Ja | Typname beliebig; keine identische Enum-Sortierung. |
| `unsigned:true` | UNSIGNED, Ja | Fehler | Fehler | Keine automatische Bereichemulation. |
| `autoIncrement:true` | AUTO_INCREMENT, Ja | IDENTITY(1,1), Ja | GENERATED BY DEFAULT AS IDENTITY, Ja | PostgreSQL 10+, Ganzzahltyp; kein zusätzliches default oder nullable:true. |
| Arrays / Range / Multirange / HSTORE / INET / XML / Spatial | Offen | Offen | Offen | Manche Typen nur in einzelnen Dialekten oder als Erweiterung; explizite Typvorgabe möglich. |

## 8. Funktionen und Datumsarithmetik

Anwendung: `{"fn":"sum","args":["amount"]}` oder `SelectBuilder::fn('sum', 'amount')`. Textargumente als `{"value":"Text"}` angeben, außer dem ausdrücklich behandelten Formatstring in `date_format` und der Intervalleinheit in `date_add`/`date_sub`.

| Funktion / Absicht | MySQL | SQL Server | PostgreSQL | Umsetzung / Grenze |
| --- | --- | --- | --- | --- |
| COUNT / SUM / AVG / MIN / MAX | Ja | Ja | Ja | Gleichnamiger Funktionsaufruf über `fn`; keine besonderen Aggregatoptionen. |
| Aggregat DISTINCT / FILTER / ORDER BY | Teil | Teil | Teil | Spezialargumente/ganze Ausdrücke raw; kein eigener Aggregat-AST. |
| IFNULL / ISNULL / `is_null` als Funktion | Ja | Ja | Ja | Gemeinsame Funktionsaliase erzeugen IFNULL / ISNULL / COALESCE. Nicht mit der WHERE-Bedingung `is_null` verwechseln. |
| COALESCE | Ja | Ja | Ja | Gleichnamig; Typauflösung bleibt datenbankspezifisch. |
| NOW / CURRENT_TIMESTAMP | Ja | Ja | Ja | CURRENT_TIMESTAMP / SYSDATETIME() / CURRENT_TIMESTAMP; in PostgreSQL Transaktionszeit, keine identische Uhrzeitsemantik. |
| CURDATE / CURRENT_DATE | Ja | Ja | Ja | CURRENT_DATE / CONVERT(date, GETDATE()) / CURRENT_DATE. |
| CURTIME / CURRENT_TIME | Ja | Ja | Ja | CURRENT_TIME / CONVERT(time, GETDATE()) / CURRENT_TIME; PostgreSQL-Zeitzonentyp beachten. |
| LENGTH / LEN / CHAR_LENGTH | Teil | Teil | Teil | Wird auf CHAR_LENGTH / LEN / CHAR_LENGTH vereinheitlicht. Bytezählung und nachgestellte Leerzeichen sind nicht vereinheitlicht. |
| DATE_FORMAT / FORMAT / TO_CHAR | Teil | Teil | Teil | Gemeinsames `fn:"date_format"`; Formatumsetzung eingeschränkt. SQL Server FORMAT, PostgreSQL TO_CHAR. |
| DATE_ADD / DATE_SUB | Ja | Ja | Ja | `args:["created_at",2,"day"]`; MySQL INTERVAL, SQL Server DATEADD, PostgreSQL Intervallarithmetik. |
| Datumsarithmetik mit Quartal | Ja | Ja | Ja | Einheit `quarter`; PostgreSQL multipliziert `INTERVAL '3 months'`. |
| Zusammengesetzte Intervalle / Zeitzonen | Offen | Offen | Offen | Keine API für DAY_SECOND usw.; Kalender-/DST-Verhalten muss gezielt geprüft werden. |
| GREATEST / LEAST | Teil | Teil | Teil | MySQL/PostgreSQL nativ; SQL Server CASE-Emulation. Unterschiedliches NULL-Verhalten bleibt bestehen. |
| LOWER / UPPER / ABS / ROUND / FLOOR / CEILING | Teil | Teil | Teil | Generischer gleichnamiger Funktionsaufruf; Arity und Datentypen werden nicht normalisiert. |
| CONCAT / CONCAT_WS | Teil | Teil | Teil | Generischer Aufruf; NULL-Verhalten und Serverversion beachten. |
| SUBSTRING / TRIM / REPLACE / LEFT / RIGHT | Teil | Teil | Teil | Nur Funktionsform mit passenden Argumenten; keine Übersetzung besonderer SQL-Grammatiken. |
| NULLIF | Teil | Teil | Teil | Gleichnamiger generischer Aufruf möglich. |
| GROUP_CONCAT / STRING_AGG | Teil | Teil | Teil | Kein gemeinsamer Alias oder Sortierungs-/Separator-AST; explizit gültiges SQL angeben. |
| RANDOM / RAND / NEWID | Offen | Offen | Offen | Keine portable Zufalls-/UUID-Funktion; generischer Name wird nicht übersetzt. |
| DATEDIFF / TIMESTAMPDIFF / AGE / EXTRACT / DATE_TRUNC | Offen | Offen | Offen | Keine gemeinsame Semantik; teilweise Spezialsyntax, daher raw oder passender nativer Aufruf. |
| JSON lesen / schreiben / aggregieren | Teil | Teil | Teil | Native Funktionen über `fn`/raw ausgebbar; keine gemeinsame Pfad-/Operator-API. |
| Volltextsuche / Ranking | Offen | Offen | Offen | MATCH AGAINST, CONTAINS und tsvector/tsquery sind keine austauschbaren Aufrufe. |
| Mathematische / statistische / räumliche Spezialfunktionen | Teil | Teil | Teil | Generischer Aufruf möglich, keine Bibliothek zur Dialekt- oder Wertebereichsprüfung. |
| Benutzerdefinierte Funktion | Teil | Teil | Teil | `fn` gibt Namen/Argumente aus; Schema, Rückgabetyp und Serverexistenz bleiben Verantwortung der Anwendung. |

PostgreSQL `date_format` unterstützt `%Y %y %m %c %d %e %H %k %h %I %l %i %s %S %T %r %b %M %a %W %j %p %f %%`. Andere Tokens, zum Beispiel `%U`, und dynamische Formatspalten werden mit Fehler abgelehnt. Literaltext im Format wird für TO_CHAR geschützt. Sprachabhängige Monats-/Wochentagsnamen sind nicht vollständig vereinheitlicht. Regeln: [PostgreSQL Formatierung](https://www.postgresql.org/docs/18/functions-formatting.html), [Datumsfunktionen](https://www.postgresql.org/docs/18/functions-datetime.html).

## 9. Transaktionen, Rechte, Diagnose und Verwaltung

Die folgenden Fähigkeiten haben **keine eigene strukturierte Builder-Definition**. Auch PDO-Transaktionen werden nicht vom Renderer gestartet. „Offen“ bedeutet hier: selbst per PDO oder mit ausdrücklich dialektspezifischem Raw-SQL ausführen. Verwaltungsfunktionen sind überwiegend dialektspezifisch. Referenzen: [MySQL Transaktionen](https://dev.mysql.com/doc/refman/8.4/en/sql-transactional-statements.html), [MySQL Verwaltung](https://dev.mysql.com/doc/refman/8.4/en/sql-server-administration-statements.html), [T-SQL-Befehlsgruppen](https://learn.microsoft.com/en-us/sql/t-sql/statements/statements?view=sql-server-ver17), [PostgreSQL Befehlsreferenz](https://www.postgresql.org/docs/18/sql-commands.html).

| Befehl / Befehlsfamilie | MySQL | SQL Server | PostgreSQL | Gemeinsame Umsetzung / Bedeutung |
| --- | --- | --- | --- | --- |
| BEGIN / START TRANSACTION | Offen | Offen | Offen | PDO `beginTransaction()` ist die vorhandene Anwendungsebene. |
| COMMIT | Offen | Offen | Offen | PDO `commit()`; kein Builder-Typ. |
| ROLLBACK / ABORT | Offen | Offen | Offen | PDO `rollBack()`; ABORT ist PostgreSQL-Alias. |
| SAVEPOINT / SAVE TRANSACTION | Offen | Offen | Offen | Gemeinsame API denkbar; bisher raw. |
| ROLLBACK TO SAVEPOINT | Offen | Offen | Offen | SQL Server ROLLBACK TRANSACTION mit Savepointname. |
| RELEASE SAVEPOINT | Offen | — | Offen | Keine direkte T-SQL-Entsprechung. |
| SET TRANSACTION / Isolation | Offen | Offen | Offen | Ebenen und Geltungsdauer bewusst wählen. |
| SET AUTOCOMMIT | Offen | — | — | SQL Server IMPLICIT_TRANSACTIONS; PostgreSQL kein gleichartiger SQL-Schalter. |
| XA / PREPARE TRANSACTION / verteilte Transaktion | Offen | Offen | Offen | Unterschiedliche Protokolle und Administration. |
| LOCK / UNLOCK TABLES | Offen | — | Offen / — | PostgreSQL LOCK TABLE, Freigabe über Transaktionsende; SQL Server Lock-Hints. |
| Advisory Locks / GET_LOCK / sp_getapplock | Offen | Offen | Offen | Keine gemeinsame Lock-API. |
| PREPARE / EXECUTE / DEALLOCATE | Offen | Offen | Offen | PDO-Prepare liegt außerhalb des Builders; interne Lookup-SQLs sind keine öffentliche Prepare-API. |
| DECLARE / OPEN / FETCH / CLOSE Cursor | Offen | Offen | Offen | Kein Cursor-AST oder Lebenszyklus. |
| DECLARE / SET Variablen | Offen | Offen | Offen | T-SQL/MySQL-Sitzungsvariablen und PL/pgSQL-Variablen unterscheiden sich. |
| IF / WHILE / LOOP / REPEAT / LEAVE / RETURN | Offen | Offen | Offen | Prozedurale Sprachen; nicht alle Schlüsselwörter gelten in jedem System. |
| SIGNAL / RESIGNAL / THROW / RAISERROR / RAISE | Offen | Offen | Offen | Fehlerauslösung nur ausdrücklicher Dialektcode. |
| CREATE / ALTER / DROP DATABASE | Offen | Offen | Offen | Betrieb und Rechte außerhalb des bisherigen Builderumfangs. |
| CREATE / ALTER / DROP SCHEMA | Teil | Offen | Offen | MySQL SCHEMA ist ein Datenbankalias; keine plattformübergreifend gleiche Bedeutung. |
| USE / Suchpfad | Offen | Offen | Offen | PostgreSQL `SET search_path`; Datenbankwechsel erfordert eine andere Verbindung. |
| CREATE / ALTER / DROP USER | Offen | Offen | Offen | Konten und Rollenmodelle unterscheiden sich. |
| CREATE / ALTER / DROP ROLE | Offen | Offen | Offen | Keine Rechte- oder Rollen-Definitionen. |
| CREATE / ALTER / DROP LOGIN | — | Offen | — | SQL Server trennt Server-Login und Datenbankbenutzer. |
| GRANT / REVOKE | Offen | Offen | Offen | Keine gemeinsame Berechtigungs-API. |
| DENY | — | Offen | — | Keine direkte allgemeine Entsprechung. |
| ALTER DEFAULT PRIVILEGES | — | — | Offen | PostgreSQL-spezifisch. |
| CREATE / ALTER / DROP POLICY / Row-Level Security | — | Offen | Offen | Unterschiedliche Sicherheitsmodelle, keine automatische Emulation. |
| SET ROLE / SESSION AUTHORIZATION / EXECUTE AS | Offen | Offen | Offen | Unterschiedliche Identitätswechsel. |
| EXPLAIN / SHOWPLAN | Offen | Offen | Offen | Kein gemeinsames Plan-Format oder Explain-Feld. |
| EXPLAIN ANALYZE / tatsächlicher Ausführungsplan | Offen | Offen | Offen | Unterschiedliche Syntax; teilweise tatsächliche Ausführung. |
| ANALYZE TABLE / UPDATE STATISTICS / ANALYZE | Offen | Offen | Offen | Keine Wartungs-API. |
| OPTIMIZE / REPAIR / CHECK TABLE | Offen | — | — | PostgreSQL-/SQL-Server-Wartung verwendet andere Befehle. |
| VACUUM / VACUUM FULL | — | — | Offen | PostgreSQL-spezifisch. |
| REINDEX / ALTER INDEX REBUILD | Teil | Offen | Offen | MySQL arbeitet anders; keine gemeinsame Wartungsabsicht modelliert. |
| CLUSTER | — | — | Offen | PostgreSQL-Tabellenumsortierung; nicht SQL Server Clustered Index. |
| CHECKPOINT | — | Offen | Offen | Kein Builder-Typ. |
| SHOW / DESCRIBE / DESC | Offen | — | Offen / — | PostgreSQL SHOW liest Einstellungen; Objektbeschreibung über Kataloge oder psql-Clientbefehle. |
| INFORMATION_SCHEMA / Systemkataloge | Teil | Teil | Teil | Als normale SELECT-Quellen nutzbar; einige interne Lookups in MySQL/SQL Server, keine öffentliche Introspektions-API. |
| SHOW PROCESSLIST / KILL / Backend abbrechen | Offen | Offen | Offen | Herstellerabhängige Sitzungsverwaltung. |
| SET Session-/Serveroptionen / RESET | Offen | Offen | Offen | Keine normalisierte Optionsstruktur. |
| FLUSH / RESET / PURGE BINARY LOGS | Offen | — | — | MySQL-Betrieb, kein gemeinsames SQL. |
| BACKUP / RESTORE / pg_dump / mysqldump | Teil | Offen | Teil | pg_dump/mysqldump sind externe Programme; keine Builder-API oder portable SQL-Anweisung. |
| Replikation / Publication / Subscription | Offen | Offen | Offen | Umfangreiche unterschiedliche Modelle; keine strukturierten Definitionen. |
| Extension / Language / FDW / Foreign Table / Server | — | Teil | Offen | PostgreSQL-Objektfamilien; SQL Server hat teilweise andere externe Datenobjekte. |
| Tablespace / Filegroup / Dateien | Offen | Offen | Offen | Herstellerabhängige Speicherverwaltung. |
| LISTEN / NOTIFY / UNLISTEN | — | — | Offen | PostgreSQL-Benachrichtigungen, keine gemeinsame API. |
| Service Broker / Queue / Conversation | — | Offen | — | SQL-Server-spezifische Messaging-Funktionen. |
| Event Trigger / Rule / Large Object / Statistics Object | — | Teil | Offen | Unterschiedliche Objektfamilien, raw. |
| COMMENT ON / SECURITY LABEL | — | — | Teil | COMMENT ON COLUMN wird erzeugt; andere Objekte und SECURITY LABEL sind offen. |
| DISCARD / DEALLOCATE ALL / ALTER SYSTEM | — | — | Offen | PostgreSQL-Sitzungs-/Systemverwaltung ohne API. |

## 10. Dialekt-Maps als bewusster Ausweg

Für nicht vereinheitlichte Fähigkeiten kann eine Definition pro Dialekt angegeben werden. Das stellt das SQL bereit und fügt keine automatische Übersetzung hinzu:

```php
$definition = [
  'type' => 'raw',
  'mysql' => 'SELECT UUID()',
  'sqlsrv' => 'SELECT NEWID()',
  'pgsql' => 'SELECT gen_random_uuid()', // Verfügbarkeit auf dem Zielserver prüfen.
];
$sql = SqlBuilder::build($definition, 'pgsql');
```

Der Renderer akzeptiert auch Maps ohne `type`, optional mit `only`/`dialects`/`include` oder `except`/`exclude`. SQL-Strings sowie Listen werden unverändert weitergereicht. `sql` und `default` sind Fallbacks. Ein einzelner Raw-String ist nicht automatisch portabel. Bei SQL-Funktionen oder Typoverrides ersetzt eine Map keine Prüfung der verwendeten Serverversion.

## 11. Bekannte Grenzen und nächste Projektaufgaben

| Status / Priorität | Aufgabe | Begründung / Ziel |
| --- | --- | --- |
| Teilweise geprüft / Hoch | MySQL-Upsert-Korrektur im CI prüfen | Im GitHub-Lauf vom 26.11.2026 waren beide Unit-Jobs und die SQL-Server-/PostgreSQL-Integration erfolgreich. MySQL meldete Fehler 1052 bei einer unqualifizierten Bestandsreferenz. Der Builder qualifiziert diese nun mit der Zieltabelle; die Korrektur und zusätzliche Funktionen-/CASE-Fälle benötigen einen neuen Serverlauf. [Teststand und Anleitung](tests/README.md). |
| Erledigt | MySQL OFFSET ohne LIMIT | Ersatzwert wird als SQL-String erzeugt, ohne PHP-Integerüberlauf. |
| Erledigt | SQL Server SELECT limit=0 | TOP (0) liefert eine leere Ergebnismenge ohne FETCH 0. |
| Erledigt | MySQL DML-Joins mit LIMIT | Ungültige Kombination wird ebenso wie Alias-DELETE mit LIMIT ausdrücklich abgelehnt. |
| Erledigt | SELECT JOIN USING für SQL Server | Wird mit Verweis auf ON abgelehnt. |
| Erledigt | Globale UNION-Sortierung und -Paginierung | `union_order_by`, `union_limit`, `union_offset` bzw. fluente Methoden ergänzen die bisherigen Teilabfragefelder. |
| Erledigt | Gemeinsame Upsert-API mit expliziten Dialektgrenzen | VALUES-Upserts, zusammengesetzte Schlüssel, Incoming-/Bestandswerte, Parameter und Zielspalten-Rückgaben sind vorhanden. MySQLs abweichende Konfliktregeln verlangen `mysqlConflict:"any_unique"`; NULL-/Schlüsseländerungen und bekannte doppelte Quellschlüssel werden abgelehnt. |
| Offen / Mittel | Weitere Konflikt- und Rückgabefunktionen | DO NOTHING, INSERT-SELECT-Upserts, bedingte Konfliktupdates, allgemeines MERGE, OUTPUT INTO und komplexere Rückgabeausdrücke nach Bedarf ergänzen; Unterschiede ausdrücklich definieren. |
| Teilweise / Mittel | CTEs, weitere Set-Operatoren und Fensterfunktionen | SELECT-CTEs einschließlich Rekursion sind vorhanden; eigene DML-CTEs, INTERSECT/EXCEPT und Fenster-AST bleiben offen. |
| Mittel | PostgreSQL-Constraints/Indizes per Katalog suchen | Bisher verlangen die Drop-Actions bekannte Namen; bestehende MySQL-/SQL-Server-Lookups sind keine portable API-Implementierung. |
| Teilweise / Mittel | Defaults, UNSIGNED, Enum und NULL-Semantik | Bool-Defaults, NULL-Vergleiche und leere IN-Listen sind korrigiert. UNSIGNED/onUpdate ohne passende Dialektunterstützung werden abgelehnt; vollständige Bereichs-, Enum- und Kollationsgleichheit bleibt offen. |
| Teilweise / Mittel | Ausdrucksquoting und Objektverarbeitung | `col` quotet für alle Dialekte; `param` prüft Platzhalter; Literale folgen gemeinsamen Regeln. Stringausdrücke und Raw-SQL bleiben ausdrücklich SQL. Weitere historische DDL-Sonderpfade gesondert prüfen. |
| Teilweise / Mittel | JSON-Schema und Laufzeitparser | Unbekannte SELECT-/Builder-/Spaltenfelder werden abgelehnt und neue Felder sind im Schema erfasst. Das Schema ist weiterhin keine vollständige Dialekt-/Versions- oder SQL-Semantikprüfung. |
| Niedrig | Verwaltung, Rechte, Wartung und Spezialtypen priorisieren | Nach tatsächlichem Bedarf; die Matrix macht den fehlenden strukturierten Umfang sichtbar. |

Quellcode für den Projektstatus: [SqlBuilder](src/SqlBuilder.php), [SelectBuilder](src/SelectBuilder.php), [PostgreSqlBuilder](src/PostgreSqlBuilder.php), [SqlRenderer](src/SqlRenderer.php), [WhereBuilder](src/WhereBuilder.php), [JSON-Schema](src/definition.schema.json). Ausführbare Übersetzungs- und Fehlerbeispiele: [PostgreSQL-Tests](tests/postgresql.php), [Korrektheitstests](tests/correctness.php), [Upsert-Tests](tests/upsert.php), [Integrationstests](tests/integration.php), [Upsert-Integrationstests](tests/integration-upsert.php). Beispiele für CTEs, UNION, Rückgaben und Upserts stehen in der [README](README.md). Bei neuen Fähigkeiten Status, Anwendungsbeispiel, Grenzen und Tests gemeinsam pflegen.
