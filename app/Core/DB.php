<?php

namespace Fava\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper that works the same on MySQL/MariaDB and SQLite.
 * Table names are written as {name} in SQL and receive the configured prefix.
 */
class DB
{
    /** @var DB|null */
    private static $instance = null;

    /** @var PDO */
    public $pdo;
    /** @var string mysql|sqlite */
    public $driver;
    /** @var string */
    public $prefix;
    /** @var int */
    private $txDepth = 0;

    public function __construct(PDO $pdo, string $driver, string $prefix)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->prefix = $prefix;
    }

    public static function get(): DB
    {
        if (self::$instance === null) {
            self::$instance = self::connect((array) Config::get('db', []));
        }
        return self::$instance;
    }

    public static function setInstance(?DB $db): void
    {
        self::$instance = $db;
    }

    public static function connect(array $cfg): DB
    {
        $driver = $cfg['driver'] ?? 'mysql';
        $prefix = (string) ($cfg['prefix'] ?? 'fava_');
        if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix)) {
            throw new \RuntimeException('پیشوند جدول‌ها فقط می‌تواند شامل حروف انگلیسی، عدد و _ باشد.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($driver === 'sqlite') {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException('افزونه pdo_sqlite روی این سرور فعال نیست.');
            }
            $path = self::sqlitePath((string) ($cfg['path'] ?? ''));
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new \RuntimeException('پوشه پایگاه‌داده قابل ایجاد نیست: ' . $dir);
            }
            $options[PDO::ATTR_TIMEOUT] = 10;
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 10000');
            return new self($pdo, 'sqlite', $prefix);
        }

        if ($driver !== 'mysql') {
            throw new \RuntimeException('نوع پایگاه‌داده پشتیبانی نمی‌شود: ' . $driver);
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('افزونه pdo_mysql روی این سرور فعال نیست.');
        }
        $host = trim((string) ($cfg['host'] ?? 'localhost'));
        $port = (int) ($cfg['port'] ?? 0);
        if ($port === 0 && preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
        }
        $dsn = 'mysql:';
        if (!empty($cfg['socket'])) {
            $dsn .= 'unix_socket=' . $cfg['socket'];
        } else {
            $dsn .= 'host=' . $host . ($port ? ';port=' . $port : '');
        }
        $dsn .= ';dbname=' . ($cfg['name'] ?? '') . ';charset=utf8mb4';
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'";
        $options[PDO::ATTR_TIMEOUT] = 10;
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), $options);
        return new self($pdo, 'mysql', $prefix);
    }

    /** SQLite path may be absolute or relative to the storage directory. */
    public static function sqlitePath(string $path): string
    {
        if ($path === '') {
            throw new \RuntimeException('مسیر فایل SQLite مشخص نشده است.');
        }
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }
        return STORAGE_DIR . '/' . ltrim($path, '/');
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    /** Prefixed table name. */
    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /** Replace {table} tokens with prefixed names. */
    public function sql(string $sql): string
    {
        $prefix = $this->prefix;
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($prefix) {
            return $prefix . $m[1];
        }, $sql);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($this->sql($sql));
        $i = 1;
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $i++ : (strpos($key, ':') === 0 ? $key : ':' . $key);
            if ($value === null) {
                $stmt->bindValue($name, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($name, $value ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($name, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of the first row. */
    public function val(string $sql, array $params = [])
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** First column of every row. */
    public function col(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO {' . $table . '} (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = $col . ' = ?';
        }
        $sql = 'UPDATE {' . $table . '} SET ' . implode(', ', $set) . ' WHERE ' . $where;
        return $this->run($sql, array_merge(array_values($data), array_values($whereParams)))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM {' . $table . '} WHERE ' . $where, array_values($params))->rowCount();
    }

    /** Run a callback inside a transaction (nested calls join the outer one). */
    public function tx(callable $fn)
    {
        if ($this->txDepth > 0) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        $this->txDepth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            $this->txDepth = 0;
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $name): bool
    {
        $t = $this->table($name);
        if ($this->driver === 'sqlite') {
            return (bool) $this->val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
        }
        return (bool) $this->val(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$t]
        );
    }

    public function serverVersion(): string
    {
        try {
            if ($this->driver === 'sqlite') {
                return 'SQLite ' . $this->val('SELECT sqlite_version()');
            }
            return (string) $this->val('SELECT VERSION()');
        } catch (\Throwable $e) {
            return $this->driver;
        }
    }
}
