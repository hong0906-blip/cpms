<?php
// app/services/Cpms2ReadOnlySource.php
// PHP 5.6. No application bootstrap, schema helpers, or write SQL.
class Cpms2ReadOnlySource
{
    private $pdo;
    private $schema = array();
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function query($sql, $params = array())
    {
        self::assertReadOnly($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public static function assertReadOnly($sql)
    {
        if (!preg_match('/^\s*(SELECT|SHOW)\s/i', $sql)
            || preg_match('/;|\/\*|--|\b(INTO|OUTFILE|DUMPFILE|FOR\s+UPDATE|LOCK|SLEEP|BENCHMARK|GET_LOCK)\b/i', $sql)) {
            throw new RuntimeException('Exporter accepts read-only SELECT/SHOW only.');
        }
    }
    public function databaseName() { return $this->query('SELECT DATABASE()')->fetchColumn(); }
    public function prepare($sql) { self::assertReadOnly($sql); return $this->pdo->prepare($sql); }
    public function inspect($table, $mandatory = false, $requiredColumns = array('id'))
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new RuntimeException('Invalid source table.');
        $exists = (int)$this->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array($table))->fetchColumn() > 0;
        $columns = array();
        if ($exists) {
            $st = $this->query('SHOW COLUMNS FROM `' . $table . '`');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) $columns[] = $row['Field'];
        }
        $this->schema[$table] = array('exists'=>$exists, 'columns'=>$columns);
        if ($mandatory && (!$exists || count(array_diff($requiredColumns, $columns)))) {
            throw new RuntimeException('Mandatory source schema missing: ' . $table);
        }
        return $columns;
    }
    public function report() { return $this->schema; }
    public function columns($table) { return isset($this->schema[$table]) ? $this->schema[$table]['columns'] : $this->inspect($table); }
    public function rows($table, $fields, $where = '', $params = array())
    {
        $columns = $this->columns($table);
        if (!count($columns)) return;
        $fields = array_values(array_intersect($fields, $columns));
        if (!in_array('id', $fields)) throw new RuntimeException('Source primary key unavailable: ' . $table);
        $last = 0;
        do {
            $sql = 'SELECT `' . implode('`,`', $fields) . '` FROM `' . $table . '` WHERE id > ?';
            if (in_array('is_deleted', $columns)) $sql .= ' AND COALESCE(is_deleted,0) = 0';
            if (in_array('deleted_at', $columns)) $sql .= ' AND deleted_at IS NULL';
            if ($where !== '') $sql .= ' AND (' . $where . ')';
            $sql .= ' ORDER BY id LIMIT 500';
            $st = $this->query($sql, array_merge(array($last), $params));
            $count = 0;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) { $last = (int)$row['id']; $count++; yield $row; }
        } while ($count === 500);
    }
}
