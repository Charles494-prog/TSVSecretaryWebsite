<?php
declare(strict_types=1);

// A small PDO stand-in that talks to a Turso (libSQL) database over its HTTP API, so the site's existing
// PDO code runs unchanged on Vercel. The few MySQL-only bits of SQL the site uses are rewritten for SQLite.

final class TursoPDO extends PDO
{
    private string $endpoint;
    private string $token;
    private ?string $baton = null;
    private ?string $baseUrl = null;
    private int $transactionDepth = 0;
    public ?string $lastId = null;

    public function __construct(string $url, string $token)
    {
        // Not calling parent::__construct(): every PDO method the site uses is implemented here.
        $host = preg_replace('#^(libsql|https?)://#', '', rtrim($url, '/'));
        $this->endpoint = 'https://' . $host;
        $this->token = $token;
        register_shutdown_function(function (): void {
            if ($this->baton !== null) {
                try {
                    $this->pipeline([['type' => 'close']]);
                } catch (Throwable) {
                }
            }
        });
    }

    /** Sends requests on this PHP request's stream (the baton keeps transactions together). */
    public function pipeline(array $requests): array
    {
        $body = json_encode(['baton' => $this->baton, 'requests' => $requests], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $curl = curl_init(($this->baseUrl ?? $this->endpoint) . '/v2/pipeline');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        if ($raw === false || $status !== 200) {
            $this->baton = null;
            throw new PDOException('Turso request failed (' . ($error ?: "HTTP {$status}") . ')' . ($raw ? ': ' . substr((string) $raw, 0, 300) : ''));
        }
        $response = json_decode((string) $raw, true);
        $this->baton = $response['baton'] ?? null;
        if (!empty($response['base_url'])) {
            $this->baseUrl = rtrim($response['base_url'], '/');
        }
        $results = [];
        foreach ($response['results'] ?? [] as $result) {
            if (($result['type'] ?? '') === 'error') {
                throw new PDOException('Database error: ' . ($result['error']['message'] ?? 'unknown'));
            }
            $results[] = $result['response'] ?? [];
        }
        return $results;
    }

    /** Runs one statement; returns [columns, rows (typed), affected rows]. */
    public function run(string $sql, array $params = []): array
    {
        $stmt = ['sql' => self::translate($sql)];
        if ($params && array_is_list($params)) {
            $stmt['args'] = array_map([self::class, 'value'], $params);
        } elseif ($params) {
            $stmt['named_args'] = [];
            foreach ($params as $name => $value) {
                $stmt['named_args'][] = ['name' => ':' . ltrim((string) $name, ':'), 'value' => self::value($value)];
            }
        }
        $result = $this->pipeline([['type' => 'execute', 'stmt' => $stmt]])[0]['result'] ?? [];
        if (isset($result['last_insert_rowid'])) {
            $this->lastId = (string) $result['last_insert_rowid'];
        }
        $columns = array_map(static fn(array $c): string => (string) $c['name'], $result['cols'] ?? []);
        $rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $rows[] = array_map([self::class, 'decode'], $row);
        }
        return [$columns, $rows, (int) ($result['affected_row_count'] ?? 0)];
    }

    private static function value(mixed $value): array
    {
        return match (true) {
            $value === null => ['type' => 'null'],
            is_bool($value) => ['type' => 'integer', 'value' => $value ? '1' : '0'],
            is_int($value) => ['type' => 'integer', 'value' => (string) $value],
            is_float($value) => ['type' => 'float', 'value' => $value],
            default => ['type' => 'text', 'value' => (string) $value],
        };
    }

    private static function decode(array $cell): mixed
    {
        return match ($cell['type'] ?? 'null') {
            'null' => null,
            'integer' => (int) $cell['value'],
            'float' => (float) $cell['value'],
            'blob' => base64_decode((string) $cell['base64']),
            default => (string) $cell['value'],
        };
    }

    /** The MySQL-only SQL this site uses, in SQLite form. */
    public static function translate(string $sql): string
    {
        $now = "datetime('now', '+8 hours')";
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        $sql = preg_replace('/\bNOW\(\)\s*-\s*INTERVAL\s+(\d+)\s+DAY\b/i', "datetime('now', '+8 hours', '-$1 day')", $sql);
        $sql = preg_replace('/\b(\w+)\s*\+\s*INTERVAL\s+(\d+)\s+SECOND\b/i', "datetime($1, '+$2 second')", $sql);
        $sql = preg_replace('/\bNOW\(\)/i', $now, $sql);
        $sql = preg_replace('/\bGREATEST\(/i', 'MAX(', $sql);
        $sql = preg_replace_callback('/\b(YEAR|MONTH|DAY)\(\s*(\w+)\s*\)/i', static fn(array $m): string => "CAST(strftime('" . ['YEAR' => '%Y', 'MONTH' => '%m', 'DAY' => '%d'][strtoupper($m[1])] . "', {$m[2]}) AS INTEGER)", $sql);
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$/is', $sql, $m)) {
            $sets = preg_replace('/\bVALUES\s*\(\s*(`?\w+`?)\s*\)/i', 'excluded.$1', $m[1]);
            $sql = substr($sql, 0, -strlen($m[0])) . 'ON CONFLICT DO UPDATE SET' . $sets;
        }
        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TursoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/^\s*SHOW\s+CREATE\s+TABLE\s+`?(\w+)`?/i', $query, $m)) {
            return TursoStatement::fromRows([$m[1], self::mysqlCreateTable($m[1])]);
        }
        $statement = new TursoStatement($this, $query);
        $statement->execute();
        return $statement;
    }

    public function exec(string $statement): int|false
    {
        return $this->run($statement)[2];
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastId ?? '0';
    }

    public function beginTransaction(): bool
    {
        $this->run('BEGIN');
        $this->transactionDepth = 1;
        return true;
    }

    public function commit(): bool
    {
        $this->run('COMMIT');
        $this->transactionDepth = 0;
        return true;
    }

    public function rollBack(): bool
    {
        try {
            $this->run('ROLLBACK');
        } finally {
            $this->transactionDepth = 0;
        }
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    /** Quotes like MySQL does, because the SQL backups this site writes are read by MySQL and the desktop app. */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . strtr($string, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]) . "'";
    }

    /** The MySQL table definitions written into SQL backups (the same as database.sql). */
    private static function mysqlCreateTable(string $table): string
    {
        $tables = [
            'officers' => "CREATE TABLE `officers` (\n  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,\n  `masterlist_number` int(10) unsigned DEFAULT NULL,\n  `name` varchar(150) NOT NULL,\n  `tungkulin` varchar(150) NOT NULL,\n  `oath_taking_date` date DEFAULT NULL,\n  `oath_taking_text` varchar(50) DEFAULT NULL,\n  `ndi_signing_date` date DEFAULT NULL,\n  `ndi_signing_text` varchar(50) DEFAULT NULL,\n  `is_active` tinyint(1) NOT NULL DEFAULT 1,\n  `birthday` date DEFAULT NULL,\n  `wedding_anniversary` date DEFAULT NULL,\n  `contact_telegram` varchar(50) DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `uq_officers_masterlist_number` (`masterlist_number`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'memory_posts' => "CREATE TABLE `memory_posts` (\n  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,\n  `taken_on` date NOT NULL,\n  `caption` varchar(2000) NOT NULL DEFAULT '',\n  `category` varchar(20) NOT NULL DEFAULT 'memories',\n  `created_at` datetime NOT NULL,\n  `updated_at` datetime NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `idx_memory_posts_taken_on` (`taken_on`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'memory_photos' => "CREATE TABLE `memory_photos` (\n  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,\n  `post_id` int(10) unsigned NOT NULL,\n  `position` smallint(5) unsigned NOT NULL DEFAULT 0,\n  `kind` varchar(5) NOT NULL DEFAULT 'photo',\n  `checksum` char(40) NOT NULL,\n  `mime` varchar(20) NOT NULL,\n  `width` smallint(5) unsigned NOT NULL,\n  `height` smallint(5) unsigned NOT NULL,\n  `duration_ms` int(10) unsigned NOT NULL DEFAULT 0,\n  `size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,\n  `complete` tinyint(1) NOT NULL DEFAULT 1,\n  `uploaded_at` datetime DEFAULT NULL,\n  `photo` longtext NOT NULL,\n  `thumb` mediumtext NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `idx_memory_photos_post` (`post_id`,`position`),\n  KEY `idx_memory_photos_checksum` (`checksum`)\n) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin",
            'memory_video_chunks' => "CREATE TABLE `memory_video_chunks` (\n  `media_id` int(10) unsigned NOT NULL,\n  `seq` int(10) unsigned NOT NULL,\n  `data` mediumtext NOT NULL,\n  PRIMARY KEY (`media_id`,`seq`)\n) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin",
        ];
        return $tables[$table] ?? throw new PDOException("Unknown table {$table}");
    }

    /** The site's tables in SQLite form (the same layout the desktop app uses). */
    public function ensureSchema(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS officers (id INTEGER PRIMARY KEY AUTOINCREMENT, masterlist_number INTEGER DEFAULT NULL UNIQUE, name TEXT NOT NULL, tungkulin TEXT NOT NULL, oath_taking_date TEXT DEFAULT NULL, oath_taking_text TEXT DEFAULT NULL, ndi_signing_date TEXT DEFAULT NULL, ndi_signing_text TEXT DEFAULT NULL, is_active INTEGER NOT NULL DEFAULT 1, birthday TEXT DEFAULT NULL, wedding_anniversary TEXT DEFAULT NULL, contact_telegram TEXT DEFAULT NULL)",
            "CREATE TABLE IF NOT EXISTS suguan_weeks (week_start TEXT NOT NULL PRIMARY KEY, services TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS app_settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE IF NOT EXISTS memory_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, taken_on TEXT NOT NULL, caption TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT 'memories', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE INDEX IF NOT EXISTS idx_memory_posts_taken_on ON memory_posts (taken_on)",
            "CREATE TABLE IF NOT EXISTS memory_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0, kind TEXT NOT NULL DEFAULT 'photo', checksum TEXT NOT NULL, mime TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, duration_ms INTEGER NOT NULL DEFAULT 0, size_bytes INTEGER NOT NULL DEFAULT 0, complete INTEGER NOT NULL DEFAULT 1, uploaded_at TEXT DEFAULT NULL, photo TEXT NOT NULL, thumb TEXT NOT NULL)",
            "CREATE INDEX IF NOT EXISTS idx_memory_photos_post ON memory_photos (post_id, position)",
            "CREATE INDEX IF NOT EXISTS idx_memory_photos_checksum ON memory_photos (checksum)",
            "CREATE TABLE IF NOT EXISTS memory_video_chunks (media_id INTEGER NOT NULL, seq INTEGER NOT NULL, data TEXT NOT NULL, PRIMARY KEY (media_id, seq))",
            "INSERT OR IGNORE INTO app_settings (setting_key, setting_value) VALUES ('pastor', ''), ('pd_tagasubaybay', ''), ('tsv_lead', ''), ('assistant_tsv_lead', ''), ('tsv_secretary', ''), ('locale_name', 'FYM AVENUE'), ('district_name', 'MARIKINA'), ('district_supervisor', 'CYRUS JOSE E. DIZON'), ('district_tsv_overseer', ''), ('webex_display_name', 'MKN-FYM Avenue'), ('weekly_meeting_datetime', ''), ('weekly_meeting_venue', ''), ('monthly_recruits', '[]')",
        ];
        $this->pipeline(array_map(static fn(string $sql): array => ['type' => 'execute', 'stmt' => ['sql' => $sql]], $statements));
        // Databases made before Formal Activities get the category column.
        $columns = array_column($this->run('PRAGMA table_info(memory_posts)')[1], 1);
        if (!in_array('category', $columns, true)) {
            $this->run("ALTER TABLE memory_posts ADD COLUMN category TEXT NOT NULL DEFAULT 'memories'");
        }
    }
}

final class TursoStatement extends PDOStatement
{
    private array $columns = [];
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;
    private array $bound = [];

    public function __construct(private ?TursoPDO $connection = null, private string $sql = '')
    {
    }

    public static function fromRows(array $row): self
    {
        $statement = new self();
        $statement->columns = array_map('strval', array_keys($row));
        $statement->rows = [array_values($row)];
        return $statement;
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $type === PDO::PARAM_INT && $value !== null ? (int) $value : $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= $this->bound;
        if ($params && !array_is_list($params) && array_key_first($params) === 1) {
            $params = array_values($params);
        }
        [$this->columns, $this->rows, $this->affected] = $this->connection->run($this->sql, $params);
        $this->cursor = 0;
        return true;
    }

    private function shape(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_NUM => $row,
            PDO::FETCH_COLUMN => $row[0] ?? null,
            PDO::FETCH_BOTH => array_combine($this->columns, $row) + $row,
            default => array_combine($this->columns, $row),
        };
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        return $this->shape($this->rows[$this->cursor++], $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return array_map(fn(array $row): mixed => $this->shape($row, $mode), $rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        return $this->rows[$this->cursor++][$column] ?? null;
    }

    public function getIterator(): Iterator
    {
        $rows = $this->fetchAll();
        return new ArrayIterator($rows);
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        return true;
    }
}
