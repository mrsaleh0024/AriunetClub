<?php
/**
 * A wpdb-compatible read/write shim over PDO, shared by the phase 5 load
 * test (SQLite driver in CI-like sandboxes, MySQL on a real server) and the
 * functional dashboard tests. It translates the few MySQL-isms the plugin's
 * repositories use so the same SQL runs on both engines:
 *
 *   SELECT GET_LOCK(...)/RELEASE_LOCK(...)  -> simulated advisory lock
 *   ... FOR UPDATE                         -> stripped (SQLite has no locks)
 *   INSERT ... ON DUPLICATE KEY UPDATE ... -> INSERT OR IGNORE
 *   UNIX_TIMESTAMP(DATE(c + INTERVAL N MINUTE))
 *                                          -> CAST(strftime('%s', date(c, '+N minutes')) AS INTEGER)
 *
 * SQL string building semantics mirror wpdb: prepare() handles %d/%s/%f
 * with proper escaping; a query counter supports the no-N+1 assertions.
 *
 * @package AriunetClub
 */

if ( PHP_SAPI !== 'cli' ) {
        exit( 'CLI only.' );
}

// The plugin's repositories type-hint \wpdb; provide an empty base when the
// tool runs outside WordPress (same trick as tests/bootstrap.php).
if ( ! class_exists( 'wpdb' ) ) {
        class wpdb {
        }
}
if ( ! defined( 'ARRAY_A' ) ) {
        define( 'ARRAY_A', 'ARRAY_A' );
}

class AriunetClub_PdoWpdb extends wpdb {
        /** @var string */
        public $prefix = 'wp_';

        /** @var int */
        public $insert_id = 0;

        /** @var array<int,string> */
        public $queries = array();

        /** @var PDO */
        private $pdo;

        /** @var bool */
        private $sqlite;

        public function __construct( PDO $pdo, string $prefix = 'wp_', bool $sqlite = true ) {
                $this->pdo    = $pdo;
                $this->prefix = $prefix;
                $this->sqlite = $sqlite;
        }

        /**
         * wpdb-style prepare with %d, %s, %f and %% placeholders.
         *
         * @param string $query Query with placeholders.
         * @param mixed  ...$args
         * @return string
         */
        public function prepare( string $query, ...$args ): string {
                if ( 0 === count( $args ) ) {
                        return $query;
                }
                $pos     = 0;
                $arg_i   = 0;
                $out     = '';
                $length  = strlen( $query );
                while ( $pos < $length ) {
                        $char = $query[ $pos ];
                        if ( '%' !== $char ) {
                                $out .= $char;
                                ++$pos;
                                continue;
                        }
                        $next = $pos + 1 < $length ? $query[ $pos + 1 ] : '';
                        if ( '%' === $next ) {
                                $out .= '%';
                                $pos += 2;
                                continue;
                        }
                        $value = $args[ $arg_i ] ?? null;
                        ++$arg_i;
                        switch ( $next ) {
                                case 'd':
                                        $out .= null === $value ? 'NULL' : (string) (int) $value;
                                        break;
                                case 'f':
                                        $out .= null === $value ? 'NULL' : (string) (float) $value;
                                        break;
                                case 's':
                                        $out .= null === $value ? 'NULL' : $this->pdo->quote( (string) $value );
                                        break;
                                default:
                                        $out .= $char . $next;
                                        break;
                        }
                        $pos += 2;
                }
                return $out;
        }

        /**
         * @param string $query
         * @return mixed
         */
        public function get_var( string $query ) {
                $row = $this->run( $query )->fetch( PDO::FETCH_NUM );
                return false === $row ? null : $row[0];
        }

        /**
         * @param string $query
         * @return array<string,mixed>|null
         */
        public function get_row( string $query, $output = null ) {
                $row = $this->run( $query )->fetch( PDO::FETCH_ASSOC );
                return false === $row ? null : $row;
        }

        /**
         * @param string $query
         * @return array<int,array<string,mixed>>
         */
        public function get_results( string $query, $output = null ) {
                $rows = $this->run( $query )->fetchAll( PDO::FETCH_ASSOC );
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * @param string $query
         * @return int|false
         */
        public function query( string $query ) {
                return $this->run( $query )->rowCount();
        }

        /**
         * @param string              $table
         * @param array<string,mixed> $data
         * @param array<int,string>|null $format
         * @return int|false
         */
        public function insert( string $table, array $data, ?array $format = null ) {
                $columns = array_keys( $data );
                $values  = array();
                foreach ( $columns as $index => $column ) {
                        $type    = isset( $format[ $index ] ) ? $format[ $index ] : '%s';
                        $values[] = $this->prepare( $type, $data[ $column ] );
                }
                $sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';
                $rows = $this->run( $sql, false )->rowCount();
                if ( $rows > 0 ) {
                        $this->insert_id = (int) $this->get_var( $this->sqlite ? 'SELECT last_insert_rowid()' : 'SELECT LAST_INSERT_ID()' );
                        return $rows;
                }
                return false;
        }

        /**
         * wpdb-style UPDATE with %d/%s/%f formats.
         *
         * @param string              $table
         * @param array<string,mixed> $data
         * @param array<string,mixed> $where
         * @param array<int,string>|null $format
         * @param array<int,string>|null $where_format
         * @return int|false
         */
        public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ) {
                $sets   = array();
                $keys   = array_keys( $data );
                foreach ( $keys as $index => $column ) {
                        $type    = isset( $format[ $index ] ) ? $format[ $index ] : '%s';
                        $sets[]  = $column . ' = ' . $this->prepare( $type, $data[ $column ] );
                }
                $conditions = array();
                $wkeys      = array_keys( $where );
                foreach ( $wkeys as $index => $column ) {
                        $type         = isset( $where_format[ $index ] ) ? $where_format[ $index ] : '%s';
                        $conditions[] = $column . ' = ' . $this->prepare( $type, $where[ $column ] );
                }
                $sql  = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conditions );
                $rows = $this->run( $sql, false )->rowCount();
                return $rows > 0 ? $rows : 0;
        }

        /**
         * Translates MySQL-only syntax for SQLite, runs and counts the query.
         *
         * @param string $query
         * @param bool   $log
         * @return PDOStatement
         */
        private function run( string $query, bool $log = true ): PDOStatement {
                if ( $log ) {
                        $this->queries[] = $query;
                }
                if ( $this->sqlite ) {
                        $query = $this->translate_for_sqlite( $query );
                }
                $stmt = $this->pdo->prepare( $query );
                $ok   = $stmt->execute();
                if ( ! $ok ) {
                        throw new RuntimeException( 'SQL error: ' . implode( ' ', $stmt->errorInfo() ) . ' — ' . $query );
                }
                return $stmt;
        }

        /**
         * @param string $query
         * @return string
         */
        private function translate_for_sqlite( string $query ): string {
                // Transaction control: SQLite spells it BEGIN.
                if ( 1 === preg_match( '~^\s*START\s+TRANSACTION\s*$~i', $query ) ) {
                        $query = 'BEGIN IMMEDIATE';
                }

                // Advisory lock probes: acquire is always granted in the single-process
                // benchmark; a release is a plain no-op.
                if ( 1 === preg_match( '~^\s*SELECT\s+GET_LOCK\s*\(~i', $query ) ) {
                        $query = "SELECT '1'";
                }
                if ( 1 === preg_match( '~^\s*SELECT\s+RELEASE_LOCK\s*\(~i', $query ) ) {
                        $query = "SELECT '1'";
                }

                // Strip row locks.
                $query = (string) preg_replace( '~\s+FOR\s+UPDATE\s*$~i', '', $query );

                // ON DUPLICATE KEY UPDATE (only used with a no-op update column):
                // SQLite's INSERT OR IGNORE keeps the idempotent behaviour.
                if ( 1 === preg_match( '~\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$~i', $query ) ) {
                        $query = preg_replace( '~^(\s*)INSERT\s+INTO~i', '$1INSERT OR IGNORE INTO', $query );
                        $query = (string) preg_replace( '~\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$~i', '', $query );
                }

                // UNIX_TIMESTAMP(DATE(col + INTERVAL N MINUTE)) bucket keys.
                $query = (string) preg_replace_callback(
                        '~UNIX_TIMESTAMP\(DATE\((\w+)\s+\+\s+INTERVAL\s+(\d+)\s+MINUTE\)\)~i',
                        static function ( array $m ): string {
                                return "CAST(strftime('%s', date({$m[1]}, '+{$m[2]} minutes')) AS INTEGER)";
                        },
                        $query
                );

                // DATE(col + INTERVAL N MINUTE) inside GROUP BY without UNIX_TIMESTAMP.
                $query = (string) preg_replace_callback(
                        '~DATE\((\w+)\s+\+\s+INTERVAL\s+(\d+)\s+MINUTE\)~i',
                        static function ( array $m ): string {
                                return "date({$m[1]}, '+{$m[2]} minutes')";
                        },
                        $query
                );

                return $query;
        }

        /**
         * Creates the plugin schema (all four tables + the phase 5 index).
         */
        public function create_schema(): void {
                $prefix = $this->prefix;
                $statements = array(
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_events (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                type TEXT NOT NULL,
                                user_id INTEGER NULL,
                                object_type TEXT NOT NULL,
                                object_id INTEGER NULL,
                                payload TEXT NULL,
                                idempotency_key TEXT NOT NULL UNIQUE,
                                status TEXT NOT NULL DEFAULT 'pending',
                                attempts INTEGER NOT NULL DEFAULT 0,
                                error TEXT NULL,
                                created_at TEXT NOT NULL,
                                processed_at TEXT NULL
                        )",
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_ledger (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                user_id INTEGER NOT NULL,
                                asset TEXT NOT NULL,
                                entry_type TEXT NOT NULL,
                                amount REAL NOT NULL,
                                balance_after REAL NULL,
                                ref_type TEXT NULL,
                                ref_id INTEGER NULL,
                                rule_id INTEGER NULL,
                                idempotency_key TEXT NOT NULL UNIQUE,
                                note TEXT NULL,
                                created_by INTEGER NULL,
                                created_at TEXT NOT NULL
                        )",
                        "CREATE UNIQUE INDEX IF NOT EXISTS {$prefix}ledger_key ON {$prefix}ariunetclub_ledger (idempotency_key)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}ledger_user_asset_created ON {$prefix}ariunetclub_ledger (user_id, asset, created_at)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}ledger_reference ON {$prefix}ariunetclub_ledger (ref_type, ref_id)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}ledger_created_at ON {$prefix}ariunetclub_ledger (created_at)",
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_balances (
                                user_id INTEGER NOT NULL,
                                asset TEXT NOT NULL,
                                available REAL NOT NULL DEFAULT 0,
                                held REAL NOT NULL DEFAULT 0,
                                updated_at TEXT NOT NULL,
                                PRIMARY KEY (user_id, asset)
                        )",
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_rules (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                title TEXT NOT NULL,
                                status TEXT NOT NULL DEFAULT 'draft',
                                event_type TEXT NOT NULL,
                                conditions TEXT NULL,
                                reward TEXT NULL,
                                limits TEXT NULL,
                                priority INTEGER NOT NULL DEFAULT 10,
                                exclusive INTEGER NOT NULL DEFAULT 0,
                                starts_at TEXT NULL,
                                ends_at TEXT NULL
                        )",
                        "CREATE INDEX IF NOT EXISTS {$prefix}rules_active ON {$prefix}ariunetclub_rules (status, event_type, priority)",
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_credit_lots (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                user_id INTEGER NOT NULL,
                                source_type TEXT NOT NULL DEFAULT 'rule',
                                source_ref INTEGER NULL,
                                ref_type TEXT NULL,
                                ref_id INTEGER NULL,
                                rule_id INTEGER NULL,
                                initial_amount REAL NOT NULL DEFAULT 0,
                                remaining REAL NOT NULL DEFAULT 0,
                                held REAL NOT NULL DEFAULT 0,
                                expires_at TEXT NULL,
                                status TEXT NOT NULL DEFAULT 'active',
                                reason TEXT NULL,
                                campaign_id INTEGER NULL,
                                created_at TEXT NOT NULL
                        )",
                        "CREATE INDEX IF NOT EXISTS {$prefix}lots_user_status ON {$prefix}ariunetclub_credit_lots (user_id, status)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}lots_expiry ON {$prefix}ariunetclub_credit_lots (status, expires_at)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}lots_reference ON {$prefix}ariunetclub_credit_lots (ref_type, ref_id)",
                        "CREATE TABLE IF NOT EXISTS {$prefix}ariunetclub_lot_allocations (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                lot_id INTEGER NOT NULL,
                                user_id INTEGER NOT NULL,
                                order_id INTEGER NOT NULL DEFAULT 0,
                                amount REAL NOT NULL,
                                state TEXT NOT NULL DEFAULT 'held',
                                created_at TEXT NOT NULL,
                                released_at TEXT NULL
                        )",
                        "CREATE INDEX IF NOT EXISTS {$prefix}alloc_order ON {$prefix}ariunetclub_lot_allocations (order_id, state)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}alloc_lot ON {$prefix}ariunetclub_lot_allocations (lot_id, state)",
                        "CREATE INDEX IF NOT EXISTS {$prefix}alloc_user ON {$prefix}ariunetclub_lot_allocations (user_id)",
                );
                if ( ! $this->sqlite ) {
                        // MySQL schema comes from the real Migrator on production-like runs.
                        throw new RuntimeException( 'create_schema() is SQLite-only; use the Migrator for MySQL.' );
                }
                foreach ( $statements as $statement ) {
                        $this->run( $statement, false );
                }
        }
}
