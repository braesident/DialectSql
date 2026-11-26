<?php

declare(strict_types=1);

namespace Braesident\DialectSql;

use InvalidArgumentException;
use stdClass;

final class SelectBuilder
{
  private array $context;
  private string $dialect;

  private bool $distinct = false;
  private array $select = [];
  private ?array $from = null;
  private array $joins = [];
  private array $where = [];
  private array $groupBy = [];
  private array $having = [];
  private array $orderBy = [];
  private ?int $limit = null;
  private ?int $offset = null;
  private array $unions = [];
  private array $ctes = [];
  private bool $recursive = false;
  private array $unionOrderBy = [];
  private ?int $unionLimit = null;
  private ?int $unionOffset = null;

  /**
   * Creates a SelectBuilder for the given dialect.
   */
  public function __construct(string|array $dialect = 'mysql')
  {
    $this->context = self::normalizeDialectContext($dialect);
    $this->dialect = $this->context['dialect'];
  }

  /**
   * Builds a SELECT statement from a query, builder, or select definition.
   */
  public static function build(mixed $selectOrQuery, string|array $dialect): string
  {
    if ($selectOrQuery instanceof self) {
      return $selectOrQuery->statement();
    }

    if (self::looksLikeQuery($selectOrQuery)) {
      return self::fromQuery($selectOrQuery, $dialect)->statement();
    }

    return self::buildSelectSql($selectOrQuery, $dialect);
  }

  /**
   * Renders a single expression into SQL.
   */
  public static function expr(mixed $expr, string|array $dialect): string
  {
    $builder = new self($dialect);

    return $builder->renderExpr($expr);
  }

  /**
   * Renders a condition group into SQL.
   */
  public static function condition(mixed $conditions, string|array $dialect, string $glue = 'AND'): string
  {
    $builder = new self($dialect);

    return $builder->renderConditionGroup($conditions, $glue);
  }

  /**
   * Creates a builder from a query definition.
   */
  public static function fromQuery(mixed $query, string|array $dialect): self
  {
    if ($query instanceof self) {
      return $query;
    }

    $builder = new self($dialect);
    $builder->applyQuery($query);

    return $builder;
  }

  /**
   * Builds a function expression AST.
   */
  public static function fn(string $name, mixed ...$args): array
  {
    return [
      'fn'   => $name,
      'args' => $args
    ];
  }

  /**
   * Builds a binary operation expression AST.
   */
  public static function calc(mixed $left, string $op, mixed $right): array
  {
    return [
      'op'    => $op,
      'left'  => $left,
      'right' => $right
    ];
  }

  /**
   * Builds a column reference expression AST.
   */
  public static function col(string $name, ?string $as = null): array
  {
    $col = ['col' => $name];
    if (\is_string($as) && '' !== $as) {
      $col['as'] = $as;
    }

    return $col;
  }

  /**
   * Builds a literal value expression AST.
   */
  public static function val(mixed $value): array
  {
    return ['value' => $value];
  }

  public static function param(string $name): array
  {
    return ['param' => $name];
  }

  /** References a value proposed for insertion inside an upsert SET expression. */
  public static function incoming(string $column): array
  {
    return ['incoming' => $column];
  }

  /** Builds an explicit LIKE predicate; scalar string patterns are literals. */
  public static function like(mixed $expr, mixed $pattern, array $options = []): array
  {
    DefinitionValidator::keys($options, ['caseSensitive', 'collation', 'escape', 'not'], 'LIKE-Optionen');

    return ['like' => array_merge(['expr' => $expr, 'pattern' => $pattern], $options)];
  }

  /**
   * Builds a CASE WHEN item.
   */
  public static function when(mixed $condition, mixed $then): array
  {
    return [
      'cond' => $condition,
      'then' => $then
    ];
  }

  /**
   * Builds a CASE expression AST.
   */
  public static function case(array $when, mixed $else = null): array
  {
    $case = ['when' => $when];
    if (func_num_args() > 1) {
      $case['else'] = $else;
    }

    return ['case' => $case];
  }

  /**
   * Enables or disables DISTINCT.
   */
  public function distinct(bool $distinct = true): self
  {
    $this->distinct = $distinct;

    return $this;
  }

  /**
   * Adds a SELECT expression.
   */
  public function select(mixed $expr, ?string $as = null): self
  {
    if (\is_string($as) && '' !== $as) {
      $this->select[] = ['expr' => $expr, 'as' => $as];

      return $this;
    }

    $this->select[] = $expr;

    return $this;
  }

  /**
   * Sets the FROM clause.
   */
  public function from(mixed $table, ?string $as = null): self
  {
    $this->from = $this->normalizeFrom($table, $as);

    return $this;
  }

  /**
   * Adds an INNER JOIN.
   */
  public function join(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('INNER', $table, $as, $on);
  }

  /**
   * Adds a LEFT JOIN.
   */
  public function leftJoin(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('LEFT', $table, $as, $on);
  }

  /**
   * Adds a RIGHT JOIN.
   */
  public function rightJoin(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('RIGHT', $table, $as, $on);
  }

  private function addJoin(string $type, mixed $table, ?string $as, mixed $on): self
  {
    if ($table instanceof self) {
      $join = ['query' => $table];
    } elseif (\is_object($table)) {
      $table = ($table instanceof stdClass) ? (array) $table : get_object_vars($table);
      $join  = $table;
    } elseif (\is_array($table)) {
      $join = $table;
    } elseif (\is_string($table)) {
      $join = ['table' => $table];
    } else {
      throw new InvalidArgumentException('join benötigt table oder query.');
    }

    if (null !== $as && ! isset($join['as']) && ! isset($join['alias'])) {
      $join['as'] = $as;
    }
    if (null !== $on && ! isset($join['on'])) {
      $join['on'] = $on;
    }

    $this->joins[] = $this->normalizeJoin($join, $type);

    return $this;
  }

  /**
   * Adds a WHERE condition.
   */
  public function where(mixed $condition): self
  {
    if (null !== $condition) {
      $this->where[] = $condition;
    }

    return $this;
  }

  /**
   * Adds a HAVING condition.
   */
  public function having(mixed $condition): self
  {
    if (null !== $condition) {
      $this->having[] = $condition;
    }

    return $this;
  }

  /**
   * Adds GROUP BY columns.
   */
  public function groupBy(mixed $columns): self
  {
    foreach ($this->normalizeList($columns) as $column) {
      $this->groupBy[] = $column;
    }

    return $this;
  }

  /**
   * Adds an ORDER BY expression.
   */
  public function orderBy(mixed $expr, ?string $direction = null): self
  {
    $this->orderBy[] = [
      'expr' => $expr,
      'dir'  => $direction
    ];

    return $this;
  }

  /**
   * Sets LIMIT and optional OFFSET.
   */
  public function limit(?int $limit, ?int $offset = null): self
  {
    $this->limit  = DefinitionValidator::count($limit, 'limit');
    $this->offset = DefinitionValidator::count($offset, 'offset');

    return $this;
  }

  /**
   * Adds a UNION or UNION ALL query.
   */
  public function union(mixed $query, bool $all = false): self
  {
    $this->unions[] = [
      'query' => $query,
      'all'   => $all
    ];

    return $this;
  }

  /** Adds a SELECT common table expression; recursive applies to the WITH clause. */
  public function with(string $name, mixed $query, array $columns = [], bool $recursive = false): self
  {
    Dialect::identifier($name, $this->dialect);
    if (isset($this->ctes[$name])) {
      throw new InvalidArgumentException('Doppelter CTE-Name: '.$name);
    }
    foreach ($columns as $column) {
      if ( ! \is_string($column)) {
        throw new InvalidArgumentException('CTE columns benötigt Spaltennamen.');
      }
      Dialect::identifier($column, $this->dialect);
    }
    $this->ctes[$name] = ['query' => $query, 'columns' => $columns];
    $this->recursive = $this->recursive || $recursive;

    return $this;
  }

  /** Sorts the complete UNION result, independently of its first SELECT. */
  public function unionOrderBy(mixed $expr, ?string $direction = null): self
  {
    $this->unionOrderBy[] = ['expr' => $expr, 'dir' => $direction];

    return $this;
  }

  public function unionLimit(?int $limit, ?int $offset = null): self
  {
    $this->unionLimit = DefinitionValidator::count($limit, 'union_limit');
    $this->unionOffset = DefinitionValidator::count($offset, 'union_offset');

    return $this;
  }

  /**
   * Returns the final SELECT statement.
   */
  public function statement(): string
  {
    if ($this->recursive && [] === $this->ctes) {
      throw new InvalidArgumentException('recursive benötigt mindestens einen WITH-Eintrag.');
    }
    $with = $this->renderWith();
    $select = $this->select;
    if ([] === $select) {
      $select = ['*'];
    }

    $sql = 'SELECT ';
    if ($this->distinct) {
      $sql .= 'DISTINCT ';
    }
    if ('sqlsrv' === $this->dialect && 0 === $this->limit) {
      $sql .= 'TOP (0) ';
    }
    $sql .= $this->renderSelectList($select);

    if (null !== $this->from) {
      $sql .= ' FROM '.$this->renderFrom($this->from);
    }

    foreach ($this->joins as $join) {
      $sql .= ' '.$this->renderJoin($join);
    }

    if ([] !== $this->where) {
      $sql .= ' WHERE '.$this->renderConditionGroup($this->where, 'AND');
    }

    if ([] !== $this->groupBy) {
      $sql .= ' GROUP BY '.$this->renderGroupBy($this->groupBy);
    }

    if ([] !== $this->having) {
      $sql .= ' HAVING '.$this->renderConditionGroup($this->having, 'AND');
    }

    if ([] !== $this->orderBy) {
      $sql .= ' ORDER BY '.$this->renderOrderBy($this->orderBy);
    }

    if ((null !== $this->limit || null !== $this->offset) && ! ('sqlsrv' === $this->dialect && 0 === $this->limit)) {
      $sql .= ' '.$this->renderLimitOffset($this->limit, $this->offset);
    }

    if ([] === $this->unions) {
      if ([] !== $this->unionOrderBy || null !== $this->unionLimit || null !== $this->unionOffset) {
        throw new InvalidArgumentException('union_order_by/union_limit/union_offset benötigt UNION.');
      }

      return $with.$sql;
    }

    $statement = '('.$sql.')';
    foreach ($this->unions as $union) {
      $unionSql = $this->renderUnionQuery($union['query']);
      $statement .= ' UNION'.($union['all'] ? ' ALL' : '').' ('.$unionSql.')';
    }

    if ([] !== $this->unionOrderBy || null !== $this->unionLimit || null !== $this->unionOffset) {
      $outer = new self($this->context);
      $outer->select('*')->from('('.$statement.')', '__dialectsql_union');
      $outer->orderBy = $this->unionOrderBy;
      $outer->limit($this->unionLimit, $this->unionOffset);
      $statement = $outer->statement();
    }

    return $with.$statement;
  }

  private function renderWith(): string
  {
    if ([] === $this->ctes) {
      return '';
    }
    $version = (int) ($this->context['serverVersionId'] ?? 0);
    if ('mysql' === $this->dialect && 0 !== $version) {
      $minimum = 'mariadb' === ($this->context['serverProduct'] ?? '') ? 100202 : 80000;
      if ($version < $minimum) {
        throw new InvalidArgumentException('WITH wird von dieser MySQL-/MariaDB-Version nicht unterstützt.');
      }
    }
    $parts = [];
    foreach ($this->ctes as $name => $cte) {
      $query = $this->renderUnionQuery($cte['query']);
      if ('sqlsrv' === $this->dialect && preg_match('/^\s*;?\s*WITH\b/i', $query)) {
        throw new InvalidArgumentException('SQL Server unterstützt kein verschachteltes WITH; definiere die CTE am Root.');
      }
      $columns = [] !== $cte['columns'] ? ' ('.implode(', ', array_map([$this, 'quoteIdentifier'], $cte['columns'])).')' : '';
      $parts[] = $this->quoteIdentifier((string) $name).$columns.' AS ('.$query.')';
    }

    return 'WITH '.($this->recursive && 'sqlsrv' !== $this->dialect ? 'RECURSIVE ' : '').implode(', ', $parts).' ';
  }

  private static function buildSelectSql(mixed $select, string|array $dialect): string
  {
    $dialectName = self::normalizeDialect($dialect);

    if (\is_string($select)) {
      return self::normalizeSqlBody($select, 'select');
    }

    if (\is_object($select)) {
      $select = ($select instanceof stdClass) ? (array) $select : get_object_vars($select);
    }

    if (\is_array($select)) {
      if (self::isList($select)) {
        return self::normalizeSqlBody($select, 'select');
      }

      $normalized = [];
      foreach ($select as $key => $value) {
        $normalized[mb_strtolower((string) $key)] = $value;
      }

      $dialectMap = [
        'mysql'  => ['mysql', 'mariadb'],
        'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql'],
        'pgsql'  => ['pgsql', 'postgres', 'postgresql']
      ];

      if (isset($dialectMap[$dialectName])) {
        foreach ($dialectMap[$dialectName] as $key) {
          if (\array_key_exists($key, $normalized)) {
            return self::normalizeSqlBody($normalized[$key], 'select');
          }
        }
      }

      if (\array_key_exists('sql', $normalized)) {
        return self::normalizeSqlBody($normalized['sql'], 'select');
      }

      if (\array_key_exists('default', $normalized)) {
        return self::normalizeSqlBody($normalized['default'], 'select');
      }

      throw new InvalidArgumentException('Kein SELECT für Dialekt "'.$dialectName.'" gefunden.');
    }

    throw new InvalidArgumentException('select muss string, array oder object sein.');
  }

  private function applyQuery(mixed $query): void
  {
    if (\is_object($query)) {
      $query = ($query instanceof stdClass) ? (array) $query : get_object_vars($query);
    }
    if ( ! \is_array($query)) {
      throw new InvalidArgumentException('query muss array oder object sein.');
    }

    $normalized = [];
    foreach ($query as $key => $value) {
      $normalized[self::normalizeKey($key)] = $value;
    }
    DefinitionValidator::query($normalized);

    $this->recursive = $normalized['recursive'] ?? false;
    if (isset($normalized['with'])) {
      $ctes = $normalized['with'];
      if (\is_object($ctes)) {
        $ctes = get_object_vars($ctes);
      }
      if ( ! \is_array($ctes) || [] === $ctes || ! self::isList($ctes)) {
        throw new InvalidArgumentException('with benötigt eine nicht leere Liste benannter SELECT-CTEs.');
      }
      foreach ($ctes as $cte) {
        $cte = \is_object($cte) ? get_object_vars($cte) : $cte;
        if ( ! \is_array($cte) || ! isset($cte['name'], $cte['query']) || ! \is_string($cte['name'])) {
          throw new InvalidArgumentException('WITH-Eintrag benötigt name und query.');
        }
        DefinitionValidator::keys($cte, ['name', 'query', 'columns'], 'WITH');
        $this->with($cte['name'], $cte['query'], $cte['columns'] ?? []);
      }
    }

    if (isset($normalized['distinct'])) {
      $this->distinct((bool) $normalized['distinct']);
    }

    if (isset($normalized['select'])) {
      foreach ($this->normalizeSelectList($normalized['select']) as $item) {
        $this->select[] = $item;
      }
    }

    if (isset($normalized['from'])) {
      $this->from = $this->normalizeFrom($normalized['from'], null);
    }

    foreach (['join' => 'INNER', 'leftjoin' => 'LEFT', 'rightjoin' => 'RIGHT'] as $key => $type) {
      if (isset($normalized[$key])) {
        foreach ($this->normalizeJoinList($normalized[$key], $type) as $join) {
          $this->joins[] = $join;
        }
      }
    }

    if (isset($normalized['where'])) {
      foreach ($this->normalizeConditionList($normalized['where']) as $condition) {
        $this->where[] = $condition;
      }
    }

    if (isset($normalized['groupby'])) {
      $this->groupBy($normalized['groupby']);
    }

    if (isset($normalized['having'])) {
      foreach ($this->normalizeConditionList($normalized['having']) as $condition) {
        $this->having[] = $condition;
      }
    }

    if (isset($normalized['orderby'])) {
      foreach ($this->normalizeOrderList($normalized['orderby']) as $order) {
        $this->orderBy[] = $order;
      }
    }

    if (array_key_exists('limit', $normalized)) {
      $limit = $normalized['limit'];
      $this->limit = DefinitionValidator::count($limit, 'limit');
    }

    if (array_key_exists('offset', $normalized)) {
      $offset = $normalized['offset'];
      $this->offset = DefinitionValidator::count($offset, 'offset');
    }

    if (isset($normalized['union'])) {
      foreach ($this->normalizeUnionList($normalized['union'], false) as $union) {
        $this->unions[] = $union;
      }
    }

    if (isset($normalized['unionall'])) {
      foreach ($this->normalizeUnionList($normalized['unionall'], true) as $union) {
        $this->unions[] = $union;
      }
    }
    if (isset($normalized['unionorderby'])) {
      $this->unionOrderBy = $this->normalizeOrderList($normalized['unionorderby']);
    }
    $this->unionLimit = DefinitionValidator::count($normalized['unionlimit'] ?? null, 'union_limit');
    $this->unionOffset = DefinitionValidator::count($normalized['unionoffset'] ?? null, 'union_offset');
  }

  private function normalizeSelectList(mixed $select): array
  {
    if (\is_string($select) || \is_object($select) || ! \is_array($select) || ! self::isList($select)) {
      return [$select];
    }

    return $select;
  }

  private function normalizeFrom(mixed $from, ?string $as): array
  {
    if ($from instanceof self) {
      return [
        'type'  => 'query',
        'value' => $from,
        'as'    => $as
      ];
    }

    if (\is_object($from)) {
      $from = ($from instanceof stdClass) ? (array) $from : get_object_vars($from);
    }

    if (\is_string($from)) {
      return [
        'type'  => 'table',
        'value' => $from,
        'as'    => $as
      ];
    }

    if (\is_array($from)) {
      DefinitionValidator::keys($from, ['table', 'name', 'as', 'alias', 'schema', 'query'], 'FROM');
      $alias = $from['as'] ?? $from['alias'] ?? $as;
      $schema = Dialect::schema($from['schema'] ?? null, $this->context);
      if (isset($from['query'])) {
        return [
          'type'  => 'query',
          'value' => $from['query'],
          'as'    => $alias
        ];
      }
      $table = $from['table'] ?? $from['name'] ?? null;
      if (\is_string($table) && '' !== $table) {
        return [
          'type'  => 'table',
          'value' => $table,
          'as'    => $alias,
          'schema' => $schema
        ];
      }
    }

    throw new InvalidArgumentException('from benötigt table oder query.');
  }

  private function normalizeJoinList(mixed $joins, string $type): array
  {
    $list = $this->normalizeList($joins);
    $result = [];
    foreach ($list as $join) {
      $result[] = $this->normalizeJoin($join, $type);
    }

    return $result;
  }

  private function normalizeJoin(mixed $join, string $type): array
  {
    if (\is_object($join)) {
      $join = ($join instanceof stdClass) ? (array) $join : get_object_vars($join);
    }

    if (\is_string($join)) {
      return [
        'type'  => $type,
        'table' => $join,
        'as'    => null,
        'on'    => null,
        'using' => null
      ];
    }

    if (\is_array($join)) {
      DefinitionValidator::keys($join, ['table', 'name', 'query', 'as', 'alias', 'on', 'using', 'schema'], 'JOIN');
      if (isset($join['on'], $join['using'])) {
        throw new InvalidArgumentException('JOIN darf nicht gleichzeitig ON und USING enthalten.');
      }
      $table = $join['table'] ?? $join['name'] ?? null;
      $query = $join['query'] ?? null;
      $alias = $join['as'] ?? $join['alias'] ?? null;
      $on    = $join['on'] ?? null;
      $using = $join['using'] ?? null;
      $schema = Dialect::schema($join['schema'] ?? null, $this->context);

      if (null === $table && null === $query) {
        throw new InvalidArgumentException('join benötigt table oder query.');
      }

      return [
        'type'  => $type,
        'table' => $table,
        'query' => $query,
        'schema' => $schema,
        'as'    => $alias,
        'on'    => $on,
        'using' => $using
      ];
    }

    throw new InvalidArgumentException('join muss array oder string sein.');
  }

  private function normalizeOrderList(mixed $orderBy): array
  {
    $list = $this->normalizeList($orderBy);
    $result = [];
    foreach ($list as $order) {
      if (\is_string($order)) {
        $result[] = ['expr' => $order, 'dir' => null];
        continue;
      }
      if (\is_object($order)) {
        $order = ($order instanceof stdClass) ? (array) $order : get_object_vars($order);
      }
      if (\is_array($order)) {
        if (self::isList($order) && \count($order) >= 1) {
          $result[] = ['expr' => $order[0], 'dir' => $order[1] ?? null];
          continue;
        }
        $expr = $order['expr'] ?? $order;
        if ( ! isset($order['expr'])) {
          if (\array_key_exists('col', $order)) {
            $expr = ['col' => $order['col']];
          } elseif (\array_key_exists('column', $order)) {
            $expr = ['col' => $order['column']];
          }
        }
        $result[] = [
          'expr' => $expr,
          'dir'  => $order['dir'] ?? $order['direction'] ?? null
        ];
        continue;
      }
    }

    return $result;
  }

  private function normalizeUnionList(mixed $unions, bool $all): array
  {
    $list = $this->normalizeList($unions);
    $result = [];
    foreach ($list as $union) {
      if (\is_object($union)) {
        $union = ($union instanceof stdClass) ? (array) $union : get_object_vars($union);
      }
      if (\is_array($union)) {
        $result[] = [
          'query' => $union['query'] ?? $union,
          'all'   => \array_key_exists('all', $union) ? (bool) $union['all'] : $all
        ];
      } else {
        $result[] = [
          'query' => $union,
          'all'   => $all
        ];
      }
    }

    return $result;
  }

  private function normalizeConditionList(mixed $conditions): array
  {
    if (\is_array($conditions) && self::isList($conditions)) {
      if ($this->isConditionTriple($conditions)) {
        return [$conditions];
      }

      return $conditions;
    }

    return [$conditions];
  }

  private function normalizeList(mixed $value): array
  {
    if (\is_array($value) && self::isList($value)) {
      return $value;
    }

    return [$value];
  }

  private function renderSelectList(array $select): string
  {
    $parts = [];
    foreach ($select as $item) {
      $parts[] = $this->renderSelectItem($item);
    }

    return implode(', ', $parts);
  }

  private function renderSelectItem(mixed $item): string
  {
    if (\is_string($item)) {
      return $item;
    }

    if (\is_object($item)) {
      $item = ($item instanceof stdClass) ? (array) $item : get_object_vars($item);
    }

    if (\is_array($item)) {
      if (self::isList($item) && \count($item) >= 1) {
        $expr  = $this->renderExpr($item[0]);
        $alias = $item[1] ?? null;
        if (\is_string($alias) && '' !== $alias) {
          return $expr.' AS '.$this->quoteIdentifier($alias);
        }

        return $expr;
      }

      $alias = $item['as'] ?? $item['alias'] ?? null;
      $expr  = $this->renderExpr($item);
      if (\is_string($alias) && '' !== $alias) {
        return $expr.' AS '.$this->quoteIdentifier($alias);
      }

      return $expr;
    }

    return $this->renderExpr($item);
  }

  private function renderFrom(array $from): string
  {
    $alias = $from['as'] ?? null;

    if ('query' === ($from['type'] ?? '')) {
      $sql = $this->renderSubquery($from['value'] ?? null);
      if (\is_string($alias) && '' !== $alias) {
        return $sql.' AS '.$this->quoteIdentifier($alias);
      }

      return $sql;
    }

    $table = (string) ($from['value'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('from benötigt table.');
    }

    $schema = $from['schema'] ?? null;
    if (\is_string($schema) && '' !== $schema && false === strpos($table, '.')) {
      $table = $schema.'.'.$table;
    }

    $tableRef = $this->quoteIdentifierPath($table);

    if (\is_string($alias) && '' !== $alias) {
      return $tableRef.' AS '.$this->quoteIdentifier($alias);
    }

    return $tableRef;
  }

  private function renderJoin(array $join): string
  {
    $type = $join['type'] ?? 'INNER';
    $table = $join['table'] ?? null;
    $query = $join['query'] ?? null;
    $alias = $join['as'] ?? null;
    $schema = $join['schema'] ?? null;

    if (null !== $query) {
      $source = $this->renderSubquery($query);
    } elseif (\is_string($table) && '' !== $table) {
      if (\is_string($schema) && '' !== $schema && false === strpos($table, '.')) {
        $table = $schema.'.'.$table;
      }
      $source = $this->quoteIdentifierPath($table);
    } else {
      throw new InvalidArgumentException('join benötigt table oder query.');
    }

    if (\is_string($alias) && '' !== $alias) {
      $source .= ' AS '.$this->quoteIdentifier($alias);
    }

    $sql = $type.' JOIN '.$source;
    if (isset($join['on']) && null !== $join['on']) {
      $sql .= ' ON '.$this->renderConditionGroup($join['on'], 'AND');
    } elseif (isset($join['using']) && null !== $join['using']) {
      if ('sqlsrv' === $this->dialect) {
        throw new InvalidArgumentException('SQL Server unterstützt kein JOIN USING; nutze ON.');
      }
      $cols = $this->normalizeList($join['using']);
      if ([] === $cols) {
        throw new InvalidArgumentException('JOIN USING benötigt mindestens eine Spalte.');
      }
      $sql .= ' USING ('.implode(', ', array_map([$this, 'quoteIdentifier'], $cols)).')';
    }

    return $sql;
  }

  private function renderGroupBy(array $columns): string
  {
    $parts = [];
    foreach ($columns as $column) {
      $parts[] = \is_string($column) ? $column : $this->renderExpr($column);
    }

    return implode(', ', $parts);
  }

  private function renderOrderBy(array $orderBy): string
  {
    $parts = [];
    foreach ($orderBy as $order) {
      $expr = $order['expr'] ?? $order;
      $dir  = $order['dir'] ?? null;
      $sql  = \is_string($expr) ? $expr : $this->renderExpr($expr);
      if (\is_string($dir) && '' !== $dir) {
        $direction = mb_strtoupper(trim($dir));
        $allowed = 'pgsql' === $this->dialect
          ? ['ASC', 'DESC', 'ASC NULLS FIRST', 'ASC NULLS LAST', 'DESC NULLS FIRST', 'DESC NULLS LAST']
          : ['ASC', 'DESC'];
        if ( ! \in_array($direction, $allowed, true)) {
          throw new InvalidArgumentException('Ungültige ORDER-BY-Richtung: '.$dir);
        }
        $sql .= ' '.$direction;
      }
      $parts[] = $sql;
    }

    return implode(', ', $parts);
  }

  private function renderLimitOffset(?int $limit, ?int $offset): string
  {
    if ('pgsql' === $this->dialect) {
      if ((null !== $limit && $limit < 0) || (null !== $offset && $offset < 0)) {
        throw new InvalidArgumentException('PostgreSQL limit/offset darf nicht negativ sein.');
      }

      return (null !== $limit ? 'LIMIT '.$limit : '')
        .(null !== $offset ? (null !== $limit ? ' ' : '').'OFFSET '.$offset : '');
    }

    if ('sqlsrv' === $this->dialect) {
      $off = max(0, (int) ($offset ?? 0));
      $lim = null !== $limit ? max(0, (int) $limit) : null;
      $sql = 'OFFSET '.$off.' ROWS';
      if (null !== $lim) {
        $sql .= ' FETCH NEXT '.$lim.' ROWS ONLY';
      }
      if ([] === $this->orderBy) {
        return 'ORDER BY (SELECT 1) '.$sql;
      }

      return $sql;
    }

    if (null === $limit && null !== $offset) {
      return 'LIMIT '.(int) $offset.', 18446744073709551615';
    }

    if (null === $offset || 0 === $offset) {
      return 'LIMIT '.(int) $limit;
    }

    return 'LIMIT '.(int) $offset.', '.(int) $limit;
  }

  private function renderUnionQuery(mixed $query): string
  {
    if ($query instanceof self) {
      $sql = $query->statement();
    } elseif (\is_string($query)) {
      $sql = $query;
    } else {
      $sql = self::fromQuery($query, $this->context)->statement();
    }
    if ('sqlsrv' === $this->dialect && preg_match('/^\s*;?\s*WITH\b/i', $sql)) {
      throw new InvalidArgumentException('SQL Server unterstützt kein verschachteltes WITH; definiere die CTE am Root.');
    }

    return $sql;
  }

  private function renderConditionGroup(mixed $conditions, string $glue): string
  {
    if (\is_array($conditions) && self::isList($conditions)) {
      if ($this->isConditionTriple($conditions)) {
        return $this->renderCondition($conditions);
      }

      $parts = [];
      foreach ($conditions as $condition) {
        $parts[] = $this->renderCondition($condition);
      }
      if (\count($parts) > 1) {
        return '('.implode(' '.mb_strtoupper($glue).' ', $parts).')';
      }
      if (1 === \count($parts)) {
        return $parts[0];
      }

      return '1=1';
    }

    return $this->renderCondition($conditions);
  }

  private function renderCondition(mixed $condition): string
  {
    if (\is_object($condition)) {
      $condition = ($condition instanceof stdClass) ? (array) $condition : get_object_vars($condition);
    }

    if (\is_array($condition) && self::isList($condition) && $this->isConditionTriple($condition)) {
      return $this->renderOp($condition[0], (string) $condition[1], $condition[2]);
    }

    if (\is_array($condition) && ! self::isList($condition)) {
      $normalized = [];
      foreach ($condition as $key => $value) {
        $normalized[mb_strtolower((string) $key)] = $value;
      }

      if (isset($normalized['and'])) {
        return $this->renderConditionGroup($normalized['and'], 'AND');
      }
      if (isset($normalized['or'])) {
        return $this->renderConditionGroup($normalized['or'], 'OR');
      }
      if (isset($normalized['not'])) {
        return 'NOT ('.$this->renderCondition($normalized['not']).')';
      }
      if (isset($normalized['exists'])) {
        return 'EXISTS '.$this->renderSubquery($normalized['exists']);
      }
      if (isset($normalized['not_exists'])) {
        return 'NOT EXISTS '.$this->renderSubquery($normalized['not_exists']);
      }
      if (isset($normalized['is_null'])) {
        return $this->renderExpr($normalized['is_null']).' IS NULL';
      }
      if (isset($normalized['is_not_null'])) {
        return $this->renderExpr($normalized['is_not_null']).' IS NOT NULL';
      }
      if (\array_key_exists('like', $normalized)) {
        return $this->renderLike($normalized['like']);
      }
      if (isset($normalized['between'])) {
        $between = $normalized['between'];
        if (\is_object($between)) {
          $between = get_object_vars($between);
        }
        $expr    = $between['expr'] ?? $between['col'] ?? null;
        $min     = $between['min'] ?? null;
        $max     = $between['max'] ?? null;
        if (null === $expr) {
          throw new InvalidArgumentException('between benötigt expr.');
        }
        $sql = $this->renderExpr($expr).' BETWEEN '.$this->renderExpr($min).' AND '.$this->renderExpr($max);
        if ( ! empty($between['not'])) {
          return 'NOT ('.$sql.')';
        }

        return $sql;
      }
    }

    return $this->renderExpr($condition);
  }

  private function isConditionTriple(array $value): bool
  {
    if (3 !== \count($value)) {
      return false;
    }
    if ( ! \array_key_exists(1, $value)) {
      return false;
    }

    return \is_string($value[1]) && '' !== trim($value[1]);
  }

  private function renderExpr(mixed $expr): string
  {
    if (null === $expr) {
      return 'NULL';
    }

    if (\is_bool($expr)) {
      if ('pgsql' === $this->dialect) {
        return $expr ? 'TRUE' : 'FALSE';
      }

      return $expr ? '1' : '0';
    }

    if (\is_int($expr) || \is_float($expr)) {
      return Dialect::literal($expr, $this->dialect);
    }

    if (\is_string($expr)) {
      return $expr;
    }

    if ($expr instanceof self) {
      return $this->renderSubquery($expr);
    }

    if (\is_object($expr)) {
      $expr = ($expr instanceof stdClass) ? (array) $expr : get_object_vars($expr);
    }

    if (\is_array($expr)) {
      if (self::isList($expr)) {
        $parts = array_map([$this, 'renderExpr'], $expr);

        return implode(', ', $parts);
      }

      $expr = $this->stripKeys($expr, ['as', 'alias']);

      foreach (['value', 'val', 'literal', 'lit'] as $key) {
        if (\array_key_exists($key, $expr)) {
          return $this->renderLiteral($expr[$key]);
        }
      }

      if (\array_key_exists('param', $expr)) {
        $name = $expr['param'];
        if ( ! \is_string($name) || ! preg_match('/^:?[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
          throw new InvalidArgumentException('param benötigt einen gültigen PDO-Parameternamen.');
        }

        return ':'.ltrim($name, ':');
      }

      if (\array_key_exists('raw', $expr)) {
        if ( ! \is_string($expr['raw']) || '' === trim($expr['raw'])) {
          throw new InvalidArgumentException('raw benötigt einen nicht leeren SQL-String.');
        }

        return trim($expr['raw']);
      }

      if (\array_key_exists('like', $expr)) {
        return $this->renderLike($expr['like']);
      }

      if (\array_key_exists('col', $expr)) {
        if (\is_string($expr['col'])) {
          if (isset($this->context['upsert'])) {
            $name = $expr['col'];
            if ('*' === $name || str_contains($name, '.')) {
              throw new InvalidArgumentException('upsert col benötigt einen unqualifizierten Spaltennamen.');
            }
            $target = $this->context['upsert']['target'];

            return Dialect::path($target, $this->dialect).'.'.$this->quoteIdentifier($name);
          }
          return Dialect::path($expr['col'], $this->dialect);
        }

        throw new InvalidArgumentException('col benötigt einen Bezeichner als String.');
      }

      if (\array_key_exists('incoming', $expr)) {
        $scope = $this->context['upsert'] ?? null;
        $column = $expr['incoming'];
        if (null === $scope || ! \is_string($column) || ! \in_array($column, $scope['columns'], true)) {
          throw new InvalidArgumentException('incoming benötigt im upsert set eine in values enthaltene Spalte.');
        }
        $quoted = $this->quoteIdentifier($column);

        return $scope['mysqlValues'] ? 'VALUES('.$quoted.')' : $this->quoteIdentifier($scope['source']).'.'.$quoted;
      }

      if (isset($expr['expr'])) {
        return $this->renderExpr($expr['expr']);
      }

      if (isset($expr['calc'])) {
        $calc = $expr['calc'];
        if (\is_object($calc)) {
          $calc = ($calc instanceof stdClass) ? (array) $calc : get_object_vars($calc);
        }
        if (\is_array($calc)) {
          $left  = $calc['left'] ?? $calc['col'] ?? null;
          $op    = $calc['op'] ?? null;
          $right = $calc['right'] ?? null;
          if (null === $right) {
            foreach ($calc as $key => $value) {
              if ( ! \in_array($key, ['left', 'col', 'op'], true)) {
                $right = [$key => $value];
                break;
              }
            }
          }
          if (null !== $left && null !== $op && null !== $right) {
            return $this->renderOp($left, $op, $right);
          }
        }
      }

      if (isset($expr['op'])) {
        $left  = $expr['left'] ?? null;
        $right = $expr['right'] ?? null;
        $op    = $expr['op'];

        if (\array_key_exists('right', $expr)) {
          return $this->renderOp($left, $op, $right);
        }

        return $this->renderUnaryOp($op, $left);
      }

      if (isset($expr['case'])) {
        return $this->renderCase($expr['case']);
      }

      if (isset($expr['query'])) {
        if (isset($this->context['upsert'])) {
          throw new InvalidArgumentException('upsert set unterstützt keine Unterabfragen; nutze ausdrücklich raw.');
        }
        return $this->renderSubquery($expr['query']);
      }

      if (isset($expr['fn']) || isset($expr['function'])) {
        $name = (string) ($expr['fn'] ?? $expr['function']);
        $args = $expr['args'] ?? [];

        return $this->renderFunction($name, $args);
      }

      if (1 === \count($expr)) {
        $key = (string) array_key_first($expr);
        $val = $expr[$key];

        return $this->renderFunction($key, $val);
      }
    }

    throw new InvalidArgumentException('Ungültiger Ausdruck.');
  }

  private function renderLike(mixed $like): string
  {
    if (\is_object($like)) {
      $like = get_object_vars($like);
    }
    if ( ! \is_array($like)) {
      throw new InvalidArgumentException('like benötigt ein Objekt mit expr und pattern.');
    }
    DefinitionValidator::keys($like, ['expr', 'pattern', 'caseSensitive', 'collation', 'escape', 'not'], 'LIKE');
    foreach (['expr', 'pattern'] as $key) {
      if ( ! \array_key_exists($key, $like)) {
        throw new InvalidArgumentException('like benötigt '.$key.'.');
      }
    }
    foreach (['caseSensitive', 'not'] as $key) {
      if (\array_key_exists($key, $like) && ! \is_bool($like[$key])) {
        throw new InvalidArgumentException('like '.$key.' muss boolean sein.');
      }
    }
    $like = Dialect::fields($like, ['collation'], $this->context);
    $collation = $like['collation'] ?? null;
    if (null !== $collation && (! \is_string($collation) || '' === trim($collation))) {
      throw new InvalidArgumentException('like collation benötigt einen Kollationsnamen.');
    }
    $sensitive = $like['caseSensitive'] ?? null;
    if ('pgsql' === $this->dialect && true === $sensitive) {
      $collation ??= 'C';
      if ( ! \in_array($collation, ['C', 'POSIX'], true)) {
        throw new InvalidArgumentException('caseSensitive:true benötigt bei PostgreSQL C oder POSIX für eine eindeutige Suche.');
      }
    }
    if (null !== $sensitive && 'pgsql' !== $this->dialect) {
      if (null === $collation) {
        throw new InvalidArgumentException('caseSensitive benötigt bei MySQL/SQL Server eine ausdrückliche collation.');
      }
      $mode = 'mysql' === $this->dialect
        ? ($sensitive ? '/_(?:cs|bin)$/iD' : '/_ci$/iD')
        : ($sensitive ? '/_(?:CS|BIN2?)(?:_|$)/i' : '/_CI(?:_|$)/i');
      if ( ! preg_match($mode, $collation)) {
        throw new InvalidArgumentException('collation passt nicht zu caseSensitive; verwende eine passende CI- oder CS/BIN-Kollation.');
      }
    }
    $left = $this->renderExpr($like['expr']);
    $pattern = $like['pattern'];
    if (null === $pattern || \is_string($pattern)) {
      $right = $this->renderLiteral($pattern);
    } elseif ((\is_array($pattern) && ! self::isList($pattern)) || \is_object($pattern)) {
      $right = $this->renderExpr($pattern);
    } else {
      throw new InvalidArgumentException('like pattern benötigt einen String, NULL oder einen Ausdruck.');
    }
    if ('' === trim($left) || '' === trim($right)) {
      throw new InvalidArgumentException('like expr und pattern dürfen keine leeren Ausdrücke sein.');
    }
    if (null !== $collation) {
      $quoted = Dialect::collation($collation, $this->dialect);
      $left = 'NULL' === $left ? $left : '('.$left.' COLLATE '.$quoted.')';
      $right = 'NULL' === $right ? $right : '('.$right.' COLLATE '.$quoted.')';
    }
    $operator = 'pgsql' === $this->dialect && false === $sensitive ? 'ILIKE' : 'LIKE';
    $sql = $left.(! empty($like['not']) ? ' NOT ' : ' ').$operator.' '.$right;
    if (\array_key_exists('escape', $like)) {
      $escape = $like['escape'];
      if ( ! \is_string($escape) || 1 !== strlen($escape) || 0 === ord($escape) || ord($escape) > 127) {
        throw new InvalidArgumentException('like escape benötigt genau ein ASCII-Zeichen ohne NUL.');
      }
      $sql .= ' ESCAPE '.$this->renderLiteral($escape);
    }

    return $sql;
  }

  private function renderOp(mixed $left, string $op, mixed $right): string
  {
    $operator = mb_strtoupper(trim($op));
    $leftSql  = $this->renderExpr($left);

    $literal = \is_object($right) ? get_object_vars($right) : $right;
    $isNull = null === $literal;
    if (\is_array($literal)) {
      foreach (['value', 'val', 'literal', 'lit'] as $key) {
        if (\array_key_exists($key, $literal) && null === $literal[$key]) {
          $isNull = true;
        }
      }
    }
    if ($isNull && \in_array($operator, ['=', '!=', '<>'], true)) {
      return $leftSql.('=' === $operator ? ' IS NULL' : ' IS NOT NULL');
    }

    if (\in_array($operator, ['IN', 'NOT IN'], true)) {
      if ([] === $right) {
        return 'IN' === $operator ? '1=0' : '1=1';
      }
      $rightSql = $this->renderInList($right);

      return $leftSql.' '.$operator.' '.$rightSql;
    }

    if (\in_array($operator, ['AND', 'OR'], true)) {
      return '('.$this->renderConditionGroup([$left, $right], $operator).')';
    }

    $rightSql = $this->renderExpr($right);

    return $leftSql.' '.$operator.' '.$rightSql;
  }

  private function renderUnaryOp(string $op, mixed $expr): string
  {
    $operator = mb_strtoupper(trim($op));
    if ('' === $operator) {
      throw new InvalidArgumentException('Operator fehlt.');
    }

    return $operator.' '.$this->renderExpr($expr);
  }

  private function renderInList(mixed $value): string
  {
    if ($value instanceof self) {
      return $this->renderSubquery($value);
    }

    if (\is_object($value)) {
      $value = ($value instanceof stdClass) ? (array) $value : get_object_vars($value);
    }

    if (\is_array($value)) {
      if (self::isList($value)) {
        $items = [];
        foreach ($value as $item) {
          if (\is_string($item) || \is_int($item) || \is_float($item) || \is_bool($item) || null === $item) {
            $items[] = $this->renderLiteral($item);
          } else {
            $items[] = $this->renderExpr($item);
          }
        }

        return '('.implode(', ', $items).')';
      }

      if (isset($value['query'])) {
        return $this->renderSubquery($value['query']);
      }
    }

    if (\is_string($value)) {
      return $value;
    }

    return '('.$this->renderExpr($value).')';
  }

  private function renderCase(mixed $case): string
  {
    if (\is_object($case)) {
      $case = ($case instanceof stdClass) ? (array) $case : get_object_vars($case);
    }
    if ( ! \is_array($case)) {
      throw new InvalidArgumentException('CASE muss array sein.');
    }

    $whens = $case['when'] ?? $case['whens'] ?? null;
    if (null === $whens) {
      throw new InvalidArgumentException('CASE benötigt "when".');
    }

    $parts = ['CASE'];
    foreach ($this->normalizeList($whens) as $when) {
      if (\is_object($when)) {
        $when = ($when instanceof stdClass) ? (array) $when : get_object_vars($when);
      }
      if ( ! \is_array($when)) {
        throw new InvalidArgumentException('CASE when ungültig.');
      }
      $cond = $when['cond'] ?? $when['when'] ?? null;
      $then = $when['then'] ?? null;
      if (null === $cond) {
        throw new InvalidArgumentException('CASE when benötigt "cond".');
      }
      $parts[] = 'WHEN '.$this->renderCondition($cond).' THEN '.$this->renderExpr($then);
    }

    if (\array_key_exists('else', $case)) {
      $parts[] = 'ELSE '.$this->renderExpr($case['else']);
    }

    $parts[] = 'END';

    return implode(' ', $parts);
  }

  private function renderFunction(string $name, mixed $args): string
  {
    $fn = mb_strtolower($name);
    if ('' === $fn) {
      throw new InvalidArgumentException('Funktionsname fehlt.');
    }

    if (\in_array($fn, ['date_add', 'dateadd'], true)) {
      return $this->renderDateAddSub($args, false);
    }
    if (\in_array($fn, ['date_sub', 'datesub'], true)) {
      return $this->renderDateAddSub($args, true);
    }

    $normalizedArgs = $this->normalizeFunctionArgs($fn, $args);

    if (\in_array($fn, ['ifnull', 'isnull', 'is_null'], true)) {
      $fnName = match ($this->dialect) {
        'sqlsrv' => 'ISNULL',
        'pgsql' => 'COALESCE',
        default => 'IFNULL'
      };

      return $fnName.'('.implode(', ', $normalizedArgs).')';
    }

    if ('coalesce' === $fn) {
      return 'COALESCE('.implode(', ', $normalizedArgs).')';
    }

    if (\in_array($fn, ['now', 'current_timestamp', 'currenttimestamp'], true)) {
      return 'sqlsrv' === $this->dialect ? 'SYSDATETIME()' : 'CURRENT_TIMESTAMP';
    }

    if (\in_array($fn, ['curdate', 'current_date', 'currentdate'], true)) {
      return 'sqlsrv' === $this->dialect ? 'CONVERT(date, GETDATE())' : 'CURRENT_DATE';
    }

    if (\in_array($fn, ['curtime', 'current_time', 'currenttime'], true)) {
      return 'sqlsrv' === $this->dialect ? 'CONVERT(time, GETDATE())' : 'CURRENT_TIME';
    }

    if (\in_array($fn, ['length', 'len', 'char_length', 'character_length'], true)) {
      $fnName = 'sqlsrv' === $this->dialect ? 'LEN' : 'CHAR_LENGTH';

      return $fnName.'('.implode(', ', $normalizedArgs).')';
    }

    if ('date_format' === $fn || 'dateformat' === $fn) {
      if (\count($normalizedArgs) < 2) {
        throw new InvalidArgumentException('date_format benötigt 2 Argumente.');
      }

      if ('sqlsrv' === $this->dialect) {
        $format = $this->extractLiteral($args, 1);
        $format = $this->convertDateFormatToSqlSrv($format);
        $formatSql = $this->renderLiteral($format);

        return 'FORMAT('.$normalizedArgs[0].', '.$formatSql.')';
      }

      if ('pgsql' === $this->dialect) {
        $format = $this->extractLiteral($args, 1);
        if ('' === $format && "''" !== $normalizedArgs[1]) {
          throw new InvalidArgumentException('PostgreSQL date_format benötigt einen literalen Formatstring.');
        }

        return 'TO_CHAR('.$normalizedArgs[0].', '.$this->renderLiteral($this->convertDateFormatToPostgreSql($format)).')';
      }

      return 'DATE_FORMAT('.$normalizedArgs[0].', '.$normalizedArgs[1].')';
    }

    if (\in_array($fn, ['greatest', 'least'], true)) {
      if (\count($normalizedArgs) < 2) {
        throw new InvalidArgumentException($fn.' benötigt mindestens 2 Argumente.');
      }

      if ('sqlsrv' === $this->dialect) {
        $operator = 'greatest' === $fn ? '>=' : '<=';

        return $this->renderGreatestLeast($normalizedArgs, $operator);
      }

      return mb_strtoupper($fn).'('.implode(', ', $normalizedArgs).')';
    }

    $fnName = mb_strtoupper($fn);

    return $fnName.'('.implode(', ', $normalizedArgs).')';
  }

  private function renderGreatestLeast(array $normalizedArgs, string $operator): string
  {
    $expr = array_shift($normalizedArgs);
    foreach ($normalizedArgs as $next) {
      $expr = 'CASE WHEN '.$expr.' '.$operator.' '.$next.' THEN '.$expr.' ELSE '.$next.' END';
    }

    return $expr;
  }

  private function normalizeFunctionArgs(string $fn, mixed $args): array
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }

    if ( ! \is_array($args) || ! self::isList($args)) {
      if (\is_array($args)) {
        if (isset($args['args'])) {
          $args = $args['args'];
        } elseif (\in_array($fn, ['ifnull', 'isnull', 'is_null'], true)) {
          $cond = $args['cond'] ?? $args['expr'] ?? null;
          $then = $args['then'] ?? $args['else'] ?? null;
          if (null !== $cond) {
            $args = [$cond, $then];
          } else {
            $args = [$args];
          }
        } else {
          $args = [$args];
        }
      } else {
        $args = [$args];
      }
    }

    $out = [];
    foreach ($args as $index => $arg) {
      if ('date_format' === $fn || 'dateformat' === $fn) {
        if (1 === $index && \is_string($arg)) {
          $out[] = $this->renderLiteral($arg);
          continue;
        }
      }
      $out[] = $this->renderExpr($arg);
    }

    return $out;
  }

  private function extractLiteral(mixed $args, int $index): string
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }
    if (\is_array($args) && self::isList($args) && \array_key_exists($index, $args)) {
      $val = $args[$index];
      if (\is_string($val)) {
        return $val;
      }
      if (\is_array($val) && (\array_key_exists('value', $val) || \array_key_exists('literal', $val) || \array_key_exists('val', $val) || \array_key_exists('lit', $val))) {
        foreach (['value', 'literal', 'val', 'lit'] as $key) {
          if (\array_key_exists($key, $val)) {
            return (string) $val[$key];
          }
        }
      }
    }

    return '';
  }

  private function renderDateAddSub(mixed $args, bool $subtract): string
  {
    [$exprSql, $amountSql, $unitSql] = $this->normalizeDateIntervalArgs($args);

    if ('sqlsrv' === $this->dialect) {
      if ($subtract) {
        $amountSql = '0 - ('.$amountSql.')';
      }

      return 'DATEADD('.$unitSql.', '.$amountSql.', '.$exprSql.')';
    }

    if ('pgsql' === $this->dialect) {
      $interval = 'QUARTER' === $unitSql ? '3 months' : '1 '.mb_strtolower($unitSql);

      return '('.$exprSql.($subtract ? ' - ' : ' + ').'('.$amountSql.") * INTERVAL '".$interval."')";
    }

    $fnName = $subtract ? 'DATE_SUB' : 'DATE_ADD';

    return $fnName.'('.$exprSql.', INTERVAL '.$amountSql.' '.$unitSql.')';
  }

  private function normalizeDateIntervalArgs(mixed $args): array
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }

    if (\is_array($args) && self::isList($args) && \count($args) >= 3) {
      $expr   = $args[0];
      $amount = $args[1];
      $unit   = $args[2];

      return [$this->renderExpr($expr), $this->renderExpr($amount), $this->normalizeIntervalUnit($unit)];
    }

    if (\is_array($args)) {
      $expr   = $args['expr'] ?? $args['date'] ?? $args['col'] ?? null;
      $amount = $args['amount'] ?? $args['value'] ?? $args['interval'] ?? null;
      $unit   = $args['unit'] ?? null;
      if (null !== $expr && null !== $amount && null !== $unit) {
        return [$this->renderExpr($expr), $this->renderExpr($amount), $this->normalizeIntervalUnit($unit)];
      }
    }

    throw new InvalidArgumentException('date_add/date_sub benötigt (expr, amount, unit).');
  }

  private function normalizeIntervalUnit(mixed $unit): string
  {
    if (\is_object($unit)) {
      $unit = ($unit instanceof stdClass) ? (array) $unit : get_object_vars($unit);
    }

    if (\is_array($unit)) {
      if (isset($unit['value'])) {
        $unit = $unit['value'];
      } elseif (isset($unit['literal'])) {
        $unit = $unit['literal'];
      } elseif (isset($unit['raw'])) {
        $unit = $unit['raw'];
      }
    }

    if ( ! \is_string($unit) || '' === trim($unit)) {
      throw new InvalidArgumentException('Interval-Unit fehlt.');
    }

    $unit = mb_strtolower(trim($unit));
    $map = [
      'year' => 'YEAR',
      'years' => 'YEAR',
      'yy' => 'YEAR',
      'yyyy' => 'YEAR',
      'quarter' => 'QUARTER',
      'quarters' => 'QUARTER',
      'qq' => 'QUARTER',
      'month' => 'MONTH',
      'months' => 'MONTH',
      'mm' => 'MONTH',
      'week' => 'WEEK',
      'weeks' => 'WEEK',
      'wk' => 'WEEK',
      'day' => 'DAY',
      'days' => 'DAY',
      'dd' => 'DAY',
      'hour' => 'HOUR',
      'hours' => 'HOUR',
      'hh' => 'HOUR',
      'minute' => 'MINUTE',
      'minutes' => 'MINUTE',
      'mi' => 'MINUTE',
      'min' => 'MINUTE',
      'second' => 'SECOND',
      'seconds' => 'SECOND',
      'ss' => 'SECOND'
    ];

    return $map[$unit] ?? mb_strtoupper($unit);
  }

  private function renderSubquery(mixed $query): string
  {
    if (isset($this->context['upsert'])) {
      throw new InvalidArgumentException('upsert set unterstützt keine Unterabfragen; nutze ausdrücklich raw.');
    }
    $sql = $query instanceof self ? $query->statement() : self::fromQuery($query, $this->context)->statement();
    if ('sqlsrv' === $this->dialect && preg_match('/^\s*;?\s*WITH\b/i', $sql)) {
      throw new InvalidArgumentException('SQL Server unterstützt kein WITH in einer Unterabfrage; definiere die CTE am Root.');
    }

    return '('.$sql.')';
  }

  private function renderLiteral(mixed $value): string
  {
    return Dialect::literal($value, $this->dialect);
  }

  private function quoteIdentifierPath(string $name): string
  {
    $name = trim($name);
    if ('' === $name) {
      return $name;
    }

    if ($this->isRawIdentifier($name)) {
      return $name;
    }

    $parts = explode('.', $name);
    $quoted = [];
    foreach ($parts as $part) {
      if ('' === $part) {
        return $name;
      }
      if ('*' === $part) {
        $quoted[] = $part;
        continue;
      }
      $quoted[] = $this->quoteIdentifier($part);
    }

    return implode('.', $quoted);
  }

  private function isRawIdentifier(string $name): bool
  {
    if (preg_match('/\\s|[()]/', $name)) {
      return true;
    }

    return false !== strpbrk($name, '`[]\"');
  }

  private function quoteIdentifier(string $name): string
  {
    return Dialect::identifier($name, $this->dialect);
  }

  private function convertDateFormatToSqlSrv(string $format): string
  {
    if ('' === $format) {
      return $format;
    }

    $map = [
      '%Y' => 'yyyy',
      '%y' => 'yy',
      '%m' => 'MM',
      '%c' => 'M',
      '%d' => 'dd',
      '%e' => 'd',
      '%H' => 'HH',
      '%h' => 'hh',
      '%I' => 'hh',
      '%i' => 'mm',
      '%s' => 'ss',
      '%S' => 'ss',
      '%T' => 'HH:mm:ss',
      '%b' => 'MMM',
      '%M' => 'MMMM',
      '%p' => 'tt',
      '%%' => '%'
    ];

    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
      $ch = $format[$i];
      if ('%' !== $ch) {
        $out .= $ch;
        continue;
      }
      $token = $i + 1 < $len ? $format[$i].$format[$i + 1] : $format[$i];
      if (isset($map[$token])) {
        $out .= $map[$token];
        $i++;
        continue;
      }
      $out .= $ch;
    }

    return $out;
  }

  private function convertDateFormatToPostgreSql(string $format): string
  {
    $map = [
      '%Y' => 'YYYY', '%y' => 'YY', '%m' => 'MM', '%c' => 'FMMM',
      '%d' => 'DD', '%e' => 'FMDD', '%H' => 'HH24', '%k' => 'FMHH24',
      '%h' => 'HH12', '%I' => 'HH12', '%l' => 'FMHH12', '%i' => 'MI',
      '%s' => 'SS', '%S' => 'SS', '%T' => 'HH24:MI:SS', '%r' => 'HH12:MI:SS AM',
      '%b' => 'Mon', '%M' => 'FMMonth', '%a' => 'Dy', '%W' => 'FMDay',
      '%j' => 'DDD', '%p' => 'AM', '%f' => 'US', '%%' => '"%"'
    ];
    $out = '';
    for ($index = 0, $length = strlen($format); $index < $length; ++$index) {
      if ('%' === $format[$index]) {
        $token = substr($format, $index, 2);
        if ( ! isset($map[$token])) {
          throw new InvalidArgumentException('PostgreSQL date_format unterstützt '.$token.' nicht; nutze TO_CHAR über raw.');
        }
        $out .= $map[$token];
        ++$index;
      } else {
        $start = $index;
        while ($index + 1 < $length && '%' !== $format[$index + 1]) {
          ++$index;
        }
        $out .= '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], substr($format, $start, $index - $start + 1)).'"';
      }
    }

    return $out;
  }

  private static function looksLikeQuery(mixed $value): bool
  {
    if (\is_object($value)) {
      $value = ($value instanceof stdClass) ? (array) $value : get_object_vars($value);
    }
    if ( ! \is_array($value)) {
      return false;
    }

    $keys = array_map(static fn ($key) => self::normalizeKey($key), array_keys($value));
    foreach (['select', 'from', 'where', 'groupby', 'having', 'orderby', 'join', 'leftjoin', 'rightjoin', 'limit', 'offset', 'distinct', 'union', 'unionall', 'with', 'recursive', 'unionorderby', 'unionlimit', 'unionoffset'] as $key) {
      if (\in_array($key, $keys, true)) {
        return true;
      }
    }

    return false;
  }

  private static function normalizeDialectContext(string|array $dialect): array
  {
    return Dialect::context($dialect);
  }

  private static function normalizeDialect(string|array $dialect): string
  {
    $context = self::normalizeDialectContext($dialect);

    return $context['dialect'];
  }

  private static function normalizeKey(string|int $key): string
  {
    $key = mb_strtolower((string) $key);

    return str_replace(['_', '-'], '', $key);
  }

  private static function stripKeys(array $value, array $keys): array
  {
    foreach ($keys as $key) {
      if (\array_key_exists($key, $value)) {
        unset($value[$key]);
      }
    }

    return $value;
  }

  private static function normalizeSqlBody(mixed $value, string $fieldName): string
  {
    if (\is_string($value)) {
      $sql = trim($value);
      if ('' === $sql) {
        throw new InvalidArgumentException($fieldName.' darf nicht leer sein.');
      }

      return $sql;
    }

    if (\is_array($value)) {
      if ( ! self::isList($value)) {
        throw new InvalidArgumentException($fieldName.' muss string oder Liste sein.');
      }
      $lines = [];
      foreach ($value as $line) {
        $line = trim((string) $line);
        if ('' !== $line) {
          $lines[] = $line;
        }
      }
      if ([] === $lines) {
        throw new InvalidArgumentException($fieldName.' darf nicht leer sein.');
      }

      return implode("\n", $lines);
    }

    throw new InvalidArgumentException($fieldName.' muss string oder Liste sein.');
  }

  private static function isList(array $value): bool
  {
    if ([] === $value) {
      return true;
    }
    $i = 0;
    foreach ($value as $key => $_) {
      if ($key !== $i) {
        return false;
      }
      ++$i;
    }

    return true;
  }
}
