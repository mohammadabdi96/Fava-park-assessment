<?php

namespace Fava\Core;

use Fava\Domain\Settings;

/**
 * Database schema, written once and rendered for MySQL/MariaDB or SQLite.
 *
 * Column spec: [type, options]
 *   types: id | str:N | text | long | int | bool | dec | dt
 *   options: 'null' (nullable) and/or 'default' => value
 */
class Schema
{
    public const VERSION = 1;

    public static function tables(): array
    {
        return [
            'admins' => [
                'cols' => [
                    'id' => ['id'],
                    'username' => ['str:60'],
                    'password_hash' => ['str:255'],
                    'name' => ['str:150'],
                    'email' => ['str:190', 'null'],
                    'created_at' => ['dt'],
                    'last_login_at' => ['dt', 'null'],
                ],
                'unique' => [['username']],
            ],
            'settings' => [
                'cols' => [
                    'skey' => ['str:100'],
                    'svalue' => ['long', 'null'],
                ],
                'primary' => ['skey'],
            ],
            'judges' => [
                'cols' => [
                    'id' => ['id'],
                    'name' => ['str:150'],
                    'email' => ['str:190', 'null'],
                    'mobile' => ['str:30', 'null'],
                    'organization' => ['str:190', 'null'],
                    'expertise' => ['str:10', 'default' => 'both'], // tech | biz | both
                    'notes' => ['text', 'null'],
                    'is_active' => ['bool', 'default' => 1],
                    'created_at' => ['dt'],
                    'updated_at' => ['dt'],
                ],
                'index' => [['is_active']],
            ],
            'proposals' => [
                'cols' => [
                    'id' => ['id'],
                    'code' => ['str:30'],
                    // uploaded file
                    'original_name' => ['str:255'],
                    'stored_name' => ['str:100'],
                    'file_size' => ['int', 'default' => 0],
                    'file_hash' => ['str:64'],
                    'doc_type' => ['str:190', 'null'],
                    // extracted / reviewed data
                    'title' => ['text', 'null'],
                    'title_alt' => ['text', 'null'],
                    'company' => ['str:255', 'null'],
                    'expected_output' => ['text', 'null'],
                    'requested_amount' => ['dec', 'null'], // billion rials, set by the admin
                    'fields_json' => ['long', 'null'],
                    'warnings_json' => ['text', 'null'],
                    'extract_status' => ['str:20', 'default' => 'ok'], // ok | partial | failed
                    // process
                    'status' => ['str:20', 'default' => 'review'], // review | ready | judging | completed | finalized
                    'reviewed_at' => ['dt', 'null'],
                    'due_at' => ['dt', 'null'],
                    'admin_notes' => ['text', 'null'],
                    'share_doc' => ['bool', 'default' => 1],
                    'share_file' => ['bool', 'default' => 1],
                    'config_json' => ['text', 'null'], // scoring parameters frozen for this proposal
                    'config_at' => ['dt', 'null'],
                    // aggregated result
                    'tier_override' => ['str:1', 'null'],
                    'result_json' => ['long', 'null'],
                    'total_score' => ['dec', 'null'],
                    'system_decision' => ['str:20', 'null'], // approve | conditional | reject
                    'result_tier' => ['str:1', 'null'],
                    'result_amount' => ['dec', 'null'],
                    'needs_resolution' => ['bool', 'default' => 0],
                    'computed_at' => ['dt', 'null'],
                    // admin's final decision
                    'final_decision' => ['str:20', 'null'],
                    'final_amount' => ['dec', 'null'],
                    'final_note' => ['text', 'null'],
                    'finalized_at' => ['dt', 'null'],
                    'finalized_by' => ['int', 'null'],
                    'search_text' => ['text', 'null'],
                    'created_by' => ['int', 'null'],
                    'created_at' => ['dt'],
                    'updated_at' => ['dt'],
                ],
                'unique' => [['code']],
                'index' => [['status'], ['file_hash'], ['created_at']],
            ],
            'proposal_docs' => [
                'cols' => [
                    'proposal_id' => ['int'],
                    'doc_json' => ['long', 'null'],
                ],
                'primary' => ['proposal_id'],
            ],
            'assignments' => [
                'cols' => [
                    'id' => ['id'],
                    'proposal_id' => ['int'],
                    'role' => ['str:10'], // tech | biz
                    'judge_id' => ['int', 'null'],
                    'judge_name' => ['str:150', 'null'],
                    'judge_email' => ['str:190', 'null'],
                    'judge_mobile' => ['str:30', 'null'],
                    'token' => ['str:64'],
                    'status' => ['str:20', 'default' => 'pending'], // pending | sent | opened | draft | submitted
                    'sent_at' => ['dt', 'null'],
                    'last_sent_at' => ['dt', 'null'],
                    'sent_via' => ['str:20', 'null'], // email | manual
                    'send_count' => ['int', 'default' => 0],
                    'reminder_count' => ['int', 'default' => 0],
                    'last_reminder_at' => ['dt', 'null'],
                    'first_opened_at' => ['dt', 'null'],
                    'last_opened_at' => ['dt', 'null'],
                    'open_count' => ['int', 'default' => 0],
                    'draft_json' => ['long', 'null'],
                    'draft_saved_at' => ['dt', 'null'],
                    'submitted_at' => ['dt', 'null'],
                    'answers_json' => ['long', 'null'],
                    'metrics_json' => ['text', 'null'],
                    'submit_ip' => ['str:45', 'null'],
                    'submit_ua' => ['str:255', 'null'],
                    'reopened_at' => ['dt', 'null'],
                    'reopen_count' => ['int', 'default' => 0],
                    'created_at' => ['dt'],
                    'updated_at' => ['dt'],
                ],
                'unique' => [['token'], ['proposal_id', 'role']],
                'index' => [['judge_id'], ['status']],
            ],
            'activity_log' => [
                'cols' => [
                    'id' => ['id'],
                    'created_at' => ['dt'],
                    'actor_type' => ['str:10'], // admin | judge | system
                    'actor_name' => ['str:150', 'null'],
                    'proposal_id' => ['int', 'null'],
                    'action' => ['str:50'],
                    'message' => ['text', 'null'],
                    'ip' => ['str:45', 'null'],
                ],
                'index' => [['proposal_id'], ['created_at']],
            ],
            'mail_log' => [
                'cols' => [
                    'id' => ['id'],
                    'created_at' => ['dt'],
                    'to_email' => ['str:190'],
                    'subject' => ['str:255', 'null'],
                    'kind' => ['str:30', 'null'],
                    'status' => ['str:10'], // sent | failed
                    'error' => ['text', 'null'],
                    'proposal_id' => ['int', 'null'],
                    'assignment_id' => ['int', 'null'],
                ],
                'index' => [['created_at']],
            ],
            'login_attempts' => [
                'cols' => [
                    'id' => ['id'],
                    'created_at' => ['dt'],
                    'ip' => ['str:45'],
                    'username' => ['str:60', 'null'],
                    'success' => ['bool', 'default' => 0],
                ],
                'index' => [['ip', 'created_at']],
            ],
        ];
    }

    /** CREATE TABLE / CREATE INDEX statements for the given driver. */
    public static function statements(DB $db): array
    {
        $mysql = $db->isMysql();
        $out = [];
        foreach (self::tables() as $name => $def) {
            $table = $db->table($name);
            $lines = [];
            foreach ($def['cols'] as $col => $spec) {
                $lines[] = '  ' . $col . ' ' . self::columnSql($spec, $mysql);
            }
            if (!empty($def['primary'])) {
                $lines[] = '  PRIMARY KEY (' . implode(', ', $def['primary']) . ')';
            }
            foreach ($def['unique'] ?? [] as $cols) {
                $lines[] = '  CONSTRAINT ' . $table . '_' . implode('_', $cols) . '_uq UNIQUE (' . implode(', ', $cols) . ')';
            }
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . " (\n" . implode(",\n", $lines) . "\n)";
            if ($mysql) {
                $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }
            $out[] = $sql;
            foreach ($def['index'] ?? [] as $cols) {
                $idx = $table . '_' . implode('_', $cols) . '_ix';
                if ($mysql) {
                    // MySQL has no CREATE INDEX IF NOT EXISTS; handled in install() by checking first.
                    $out[] = ['index', $table, $idx, 'CREATE INDEX ' . $idx . ' ON ' . $table . ' (' . implode(', ', $cols) . ')'];
                } else {
                    $out[] = 'CREATE INDEX IF NOT EXISTS ' . $idx . ' ON ' . $table . ' (' . implode(', ', $cols) . ')';
                }
            }
        }
        return $out;
    }

    private static function columnSql(array $spec, bool $mysql): string
    {
        $type = $spec[0];
        $nullable = in_array('null', $spec, true);
        $hasDefault = array_key_exists('default', $spec);
        $len = 0;
        if (strpos($type, ':') !== false) {
            list($type, $len) = explode(':', $type);
            $len = (int) $len;
        }
        switch ($type) {
            case 'id':
                return $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            case 'str':
                $sql = $mysql ? 'VARCHAR(' . $len . ')' : 'TEXT';
                break;
            case 'text':
                $sql = 'TEXT';
                break;
            case 'long':
                $sql = $mysql ? 'MEDIUMTEXT' : 'TEXT';
                break;
            case 'int':
                $sql = $mysql ? 'INT' : 'INTEGER';
                break;
            case 'bool':
                $sql = $mysql ? 'TINYINT(1)' : 'INTEGER';
                break;
            case 'dec':
                $sql = $mysql ? 'DECIMAL(14,3)' : 'REAL';
                break;
            case 'dt':
                $sql = $mysql ? 'DATETIME' : 'TEXT';
                break;
            default:
                throw new \LogicException('Unknown column type ' . $type);
        }
        $sql .= $nullable ? ' NULL' : ' NOT NULL';
        if ($hasDefault) {
            $d = $spec['default'];
            $sql .= ' DEFAULT ' . (is_int($d) || is_float($d) ? $d : "'" . str_replace("'", "''", (string) $d) . "'");
        }
        return $sql;
    }

    /** Create all tables and indexes (idempotent). */
    public static function install(DB $db): void
    {
        foreach (self::statements($db) as $stmt) {
            if (is_array($stmt)) {
                list(, $table, $idx, $sql) = $stmt;
                $exists = $db->val(
                    'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                    [$table, $idx]
                );
                if (!$exists) {
                    $db->pdo->exec($sql);
                }
            } else {
                $db->pdo->exec($stmt);
            }
        }
    }

    /**
     * Bring an existing database up to Schema::VERSION: create missing tables/indexes and
     * add new columns. Does nothing once the stored version is current.
     */
    public static function upgrade(DB $db): void
    {
        if ((int) Settings::get('schema_version', 0) >= self::VERSION) {
            return;
        }
        self::install($db);
        foreach (self::tables() as $name => $def) {
            if (!$db->tableExists($name)) {
                continue;
            }
            $table = $db->table($name);
            $cols = self::columns($db, $table);
            foreach ($def['cols'] as $col => $spec) {
                if (in_array($col, $cols, true)) {
                    continue;
                }
                if (!in_array('null', $spec, true) && !array_key_exists('default', $spec)) {
                    // shortcut: a new NOT NULL column without a default needs a hand-written migration
                    continue;
                }
                try {
                    $db->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . self::columnSql($spec, $db->isMysql()));
                    $cols[] = $col;
                } catch (\Throwable $e) {
                    // a parallel request may have added it between the check and the ALTER
                    if (!in_array($col, self::columns($db, $table), true)) {
                        throw $e;
                    }
                }
            }
        }
        Settings::set('schema_version', self::VERSION);
    }

    /** Column names of an existing table. */
    private static function columns(DB $db, string $table): array
    {
        if ($db->isMysql()) {
            return array_map('strval', $db->col('SHOW COLUMNS FROM ' . $table));
        }
        $out = [];
        foreach ($db->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = (string) $row['name'];
        }
        return $out;
    }

    /** Tables of this application that already exist (used by the installer to warn before reuse). */
    public static function existingTables(DB $db): array
    {
        $found = [];
        foreach (array_keys(self::tables()) as $name) {
            if ($db->tableExists($name)) {
                $found[] = $db->table($name);
            }
        }
        return $found;
    }
}
