<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * live_sessions.status: enum(scheduled|live|ended) -> varchar(24), and ix_sessions_status_scheduled.
 *
 * This is the only non-additive operation in the calendar schema set, so it refuses to act on a
 * shape it cannot name. Every branch is decided by a measured census (driver, column type,
 * nullability, default, CHECK-or-ENUM domain, real stored values, FK + index sets, dependent
 * SQLite views/triggers) and anything unrecognized aborts before the first DDL byte.
 *
 * Why the census is load-bearing: Laravel does not wrap MySQL or SQLite migrations in a
 * transaction, and the SQLite ALTER is a full rebuild (create temp, copy, drop, rename), so a
 * dependent view makes the rename fail after the original table is already gone and unrecoverable.
 * The SQLite branch therefore runs inside an explicit DB transaction and stops on any dependent
 * view or trigger first.
 *
 * down() drops only the index and keeps varchar(24). Collapsing back to the enum would reject or
 * silently blank rows in confirmed|cancelled|missed, which is data destruction, not a rollback;
 * that recovery stays a reviewed manual procedure.
 */
return new class extends Migration
{
    private const TABLE = 'live_sessions';

    private const COLUMN = 'status';

    private const INDEX = 'ix_sessions_status_scheduled';

    private const ENUM_TYPE = "enum('scheduled','live','ended')";

    private const DOMAIN = ['ended', 'live', 'scheduled'];

    private const DEFAULT_STATUS = 'scheduled';

    private const KNOWN = ['cancelled', 'confirmed', 'ended', 'live', 'missed', 'scheduled'];

    private const SHAPE_ENUM = 'enum';

    private const SHAPE_WIDE = 'wide';

    public function up(): void
    {
        $before = $this->census();
        $ddlRan = $before['shape'] === self::SHAPE_ENUM;

        if ($ddlRan) {
            $this->widen($before['driver']);
        }

        $indexAdded = $this->ensureIndex();

        $this->verify($before, $ddlRan || $indexAdded);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $indexes = $this->objectCensus($this->driver())['index'];

        if (! $this->hasIndex($indexes)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }

    /** Migration::getConnection() returns the unset $connection property, so ask the schema manager. */
    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    /** @return array{driver: string, shape: string, column: array, data: array, objects: array} */
    private function census(): array
    {
        $driver = $this->driver();

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            $this->abort('unsupported driver', ['driver' => $driver]);
        }

        if (! Schema::hasTable(self::TABLE)) {
            $this->abort('live_sessions does not exist', ['driver' => $driver]);
        }

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            $this->abort('live_sessions.status does not exist', ['driver' => $driver]);
        }

        if (! Schema::hasColumn(self::TABLE, 'scheduled_at')) {
            $this->abort('live_sessions.scheduled_at does not exist, so the index cannot be built', ['driver' => $driver]);
        }

        $column = $driver === 'sqlite' ? $this->sqliteColumn() : $this->mysqlColumn();

        return [
            'driver' => $driver,
            'shape' => $this->shape($driver, $column),
            'column' => $column,
            'data' => $this->dataCensus(),
            'objects' => $this->objectCensus($driver),
        ];
    }

    private function shape(string $driver, array $column): string
    {
        if ($column === []) {
            $this->abort('status column introspection returned nothing', ['driver' => $driver]);
        }

        if ($driver === 'mysql') {
            $checks = $this->mysqlStatusCheckClauses();

            if ($checks !== []) {
                $this->abort('a real CHECK on status survives MODIFY and keeps rejecting widened values', ['checks' => $checks]);
            }

            if (($column['is_nullable'] ?? null) !== 'NO' || ($column['column_default'] ?? null) !== self::DEFAULT_STATUS) {
                $this->abort('status is not NOT NULL with DEFAULT scheduled', ['column' => $column]);
            }

            if ($column['data_type'] === 'enum') {
                if ($column['column_type'] !== self::ENUM_TYPE) {
                    $this->abort('unexpected enum domain', ['column' => $column]);
                }

                return self::SHAPE_ENUM;
            }

            if ($column['data_type'] === 'varchar' && (int) $column['max_length'] === 24) {
                return self::SHAPE_WIDE;
            }

            $this->abort('status is neither the known enum nor the widened varchar(24)', ['column' => $column]);
        }

        if ($column['data_type'] !== 'varchar' || (int) $column['is_notnull'] !== 1 || $column['column_default'] !== "'".self::DEFAULT_STATUS."'") {
            $this->abort('status is not a NOT NULL varchar defaulting to scheduled', ['column' => $column]);
        }

        $domain = $this->sqliteStatusDomain();

        return $domain === null ? self::SHAPE_WIDE : self::SHAPE_ENUM;
    }

    private function mysqlColumn(): array
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable,
                    COLUMN_DEFAULT AS column_default, CHARACTER_MAXIMUM_LENGTH AS max_length
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, self::COLUMN]
        );

        return (array) $row;
    }

    private function sqliteColumn(): array
    {
        $row = DB::selectOne(
            'SELECT "type" AS data_type, "notnull" AS is_notnull, "dflt_value" AS column_default
               FROM pragma_table_info(\''.self::TABLE.'\') WHERE "name" = ?',
            [self::COLUMN]
        );

        return (array) $row;
    }

    /**
     * P6 on MySQL. information_schema.CHECK_CONSTRAINTS has no TABLE_NAME or COLUMN_NAME, so the
     * only correct form joins it to TABLE_CONSTRAINTS and reads CHECK_CLAUSE.
     */
    private function mysqlStatusCheckClauses(): array
    {
        $rows = DB::select(
            'SELECT tc.TABLE_NAME AS table_name, cc.CONSTRAINT_NAME AS constraint_name, cc.CHECK_CLAUSE AS check_clause
               FROM information_schema.CHECK_CONSTRAINTS cc
               JOIN information_schema.TABLE_CONSTRAINTS tc
                 ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
              WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
            [self::TABLE, 'CHECK']
        );

        return array_values(array_map(
            fn (stdClass $row) => $row->check_clause,
            array_filter($rows, fn (stdClass $row) => preg_match('/(^|[^a-z0-9_])`?status`?[^a-z0-9_]/i', $row->check_clause) === 1)
        ));
    }

    /**
     * P6 on SQLite: the CHECK is visible only in sqlite_master.sql, never through
     * getColumnType()/getColumns(), and the rebuild drops it for the whole table.
     */
    private function sqliteStatusDomain(): ?array
    {
        $sql = (string) ((DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', self::TABLE])->sql ?? ''));

        $domain = null;

        foreach ($this->checkClauses($sql) as $clause) {
            if (preg_match('/(^|[^a-z0-9_])"?status"?[^a-z0-9_]/i', $clause) !== 1) {
                $this->abort('a CHECK on another column of live_sessions would be destroyed by the rebuild', ['clause' => $clause]);
            }

            if (preg_match('/\bin\s*\((?<values>[^)]*)\)/i', $clause, $matches) !== 1) {
                $this->abort('a CHECK on status that is not an IN(...) domain cannot be reasoned about', ['clause' => $clause]);
            }

            $values = array_values(array_filter(
                array_map(fn (string $value) => trim(trim($value), "'"), explode(',', $matches['values'])),
                fn (string $value) => $value !== ''
            ));

            sort($values);

            if ($values !== self::DOMAIN) {
                $this->abort('the CHECK on status does not hold the expected domain', ['check' => $values, 'expected' => self::DOMAIN]);
            }

            $domain = $values;
        }

        return $domain;
    }

    /** Balanced-paren scan with quote tracking: a regex alone is never the final decision. */
    private function checkClauses(string $sql): array
    {
        $clauses = [];
        $length = strlen($sql);
        $buffer = '';
        $depth = 0;
        $scanning = false;
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];

            if ($inSingle) {
                if ($character === "'") {
                    $inSingle = false;
                }
                if ($scanning) {
                    $buffer .= $character;
                }

                continue;
            }

            if ($inDouble) {
                if ($character === '"') {
                    $inDouble = false;
                }
                if ($scanning) {
                    $buffer .= $character;
                }

                continue;
            }

            if ($character === "'") {
                $inSingle = true;
                if ($scanning) {
                    $buffer .= $character;
                }

                continue;
            }

            if ($character === '"') {
                $inDouble = true;
                if ($scanning) {
                    $buffer .= $character;
                }

                continue;
            }

            if ($character === '(') {
                if ($scanning) {
                    $buffer .= $character;
                    $depth++;

                    continue;
                }

                if (str_ends_with(rtrim(strtolower(substr($sql, 0, $i))), 'check')) {
                    $scanning = true;
                    $depth = 1;
                    $buffer = '(';
                }

                continue;
            }

            if ($character === ')' && $scanning) {
                $depth--;

                if ($depth === 0) {
                    $clauses[] = $buffer;
                    $scanning = false;
                    $buffer = '';

                    continue;
                }

                $buffer .= $character;

                continue;
            }

            if ($scanning) {
                $buffer .= $character;
            }
        }

        return $clauses;
    }

    /** P7: real rows decide, not the declared type. */
    private function dataCensus(): array
    {
        if ((int) DB::table(self::TABLE)->count() === 0) {
            return ['by_status' => [], 'total' => ['total' => 0, 'id_sum' => null]];
        }

        $rows = DB::select(
            'SELECT status, COUNT(*) AS row_count, MIN(id) AS min_id, MAX(id) AS max_id, SUM(id) AS id_sum
               FROM '.self::TABLE.' GROUP BY status ORDER BY status'
        );

        $byStatus = [];

        foreach ($rows as $row) {
            $value = (string) $row->status;

            if (! in_array($value, self::KNOWN, true)) {
                $this->abort('a status value outside the known domain is stored in live_sessions', ['value' => $value]);
            }

            $byStatus[$value] = [
                'row_count' => (int) $row->row_count,
                'min_id' => (int) $row->min_id,
                'max_id' => (int) $row->max_id,
                'id_sum' => (int) $row->id_sum,
            ];
        }

        $total = (array) DB::selectOne('SELECT COUNT(*) AS total, SUM(id) AS id_sum FROM '.self::TABLE);

        // MySQL sorts an ENUM by its declared order and a VARCHAR by collation, so the row order
        // legitimately changes here; the fingerprint has to be compared order-free.
        ksort($byStatus);

        return ['by_status' => $byStatus, 'total' => ['total' => (int) $total['total'], 'id_sum' => (int) $total['id_sum']]];
    }

    /** P8: every index and foreign key that must survive the operation, as comparable strings. */
    private function objectCensus(string $driver): array
    {
        $objects = [];

        if ($driver === 'mysql') {
            foreach (DB::select(
                'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS seq, NON_UNIQUE AS non_unique
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                  ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [self::TABLE]
            ) as $row) {
                $objects['index'][$row->name][] = $row->column_name;
            }

            foreach ($objects['index'] ?? [] as $name => $columns) {
                $objects['index'][$name] = $name.'('.implode(',', $columns).')';
            }

            foreach (DB::select(
                'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS ref_table,
                        REFERENCED_COLUMN_NAME AS ref_column
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                  ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
                [self::TABLE]
            ) as $row) {
                $objects['fk'][$row->name] ??= $row->name;
                $objects['fk'][$row->name] .= '('.$row->column_name.'->'.$row->ref_table.'.'.$row->ref_column.')';
            }
        } else {
            foreach (DB::select('SELECT name, "unique" FROM pragma_index_list(\''.self::TABLE.'\')') as $row) {
                // A bound parameter is read as a column name here, not as the function argument,
                // and this pragma names its ordinal column `seq`, not `seq_in_index`.
                $columns = array_map(
                    fn (stdClass $info) => (string) $info->name,
                    DB::select("SELECT seqno, name FROM pragma_index_info('".str_replace("'", "''", $row->name)."') ORDER BY seqno")
                );

                $objects['index'][$row->name] = $row->name.'('.implode(',', $columns).')';
            }

            foreach (DB::select('SELECT * FROM pragma_foreign_key_list(\''.self::TABLE.'\')') as $row) {
                $key = 'fk_'.$row->id.'_'.$row->table;
                $objects['fk'][$key] = $key.'('.$row->from.'->'.$row->table.'.'.$row->to.')';
            }

            foreach (DB::select('SELECT name FROM pragma_table_info(\''.self::TABLE.'\') WHERE pk > 0') as $row) {
                $objects['pk'][$row->name] = $row->name;
            }
        }

        foreach (['index', 'fk', 'pk'] as $kind) {
            $objects += [$kind => []];
            sort($objects[$kind]);
        }

        return $objects;
    }

    private function hasIndex(array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (str_starts_with($index, self::INDEX.'(')) {
                return true;
            }
        }

        return false;
    }

    private function widen(string $driver): void
    {
        $ddl = function (): void {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                // The default must be re-declared: MODIFY (MySQL) and the rebuild (SQLite) both
                // replace the whole column definition, so an omitted default is lost silently.
                $table->string(self::COLUMN, 24)->default(self::DEFAULT_STATUS)->change();
            });
        };

        if ($driver !== 'sqlite') {
            $ddl();

            return;
        }

        $this->assertNoSqliteDependents();
        DB::transaction($ddl);
    }

    /**
     * P9. sqlite_master.tbl_name for a view row is the view's own name, so a name match never
     * finds a view that depends on live_sessions; EXPLAIN QUERY PLAN is the semantic oracle and
     * also catches transitive views. Triggers do carry tbl_name.
     */
    private function assertNoSqliteDependents(): void
    {
        $dependents = [];

        foreach (DB::select('SELECT name, sql FROM sqlite_master WHERE type = ?', ['view']) as $view) {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM "'.str_replace('"', '""', $view->name).'"');

            foreach ($plan as $step) {
                if (str_contains((string) $step->detail, self::TABLE)) {
                    $dependents[] = 'view:'.$view->name;

                    break;
                }
            }
        }

        foreach (DB::select('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ?', ['trigger', self::TABLE]) as $trigger) {
            $dependents[] = 'trigger:'.$trigger->name;
        }

        if ($dependents !== []) {
            $this->abort('the SQLite rebuild drops live_sessions before renaming, so these objects would make it unrecoverable', [
                'dependents' => array_values(array_unique($dependents)),
                'procedure' => 'drop them, run the migration, recreate them inside the same transaction',
            ]);
        }
    }

    private function ensureIndex(): bool
    {
        if ($this->hasIndex($this->objectCensus($this->driver())['index'])) {
            return false;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index([self::COLUMN, 'scheduled_at'], self::INDEX));

        return true;
    }

    private function verify(array $before, bool $ddlRan): void
    {
        $after = $this->census();

        if ($after['shape'] !== self::SHAPE_WIDE) {
            $this->abort('status did not become the widened varchar(24)', ['after' => $after['column']], $ddlRan);
        }

        if ($after['data'] !== $before['data']) {
            $this->abort('row census changed during the widening', ['before' => $before['data'], 'after' => $after['data']], $ddlRan);
        }

        $expected = $before['objects'];

        foreach (['index', 'fk', 'pk'] as $kind) {
            $added = array_values(array_diff($after['objects'][$kind], $expected[$kind]));
            $removed = array_values(array_diff($expected[$kind], $after['objects'][$kind]));

            if ($removed !== []) {
                $this->abort('the operation dropped objects', ['kind' => $kind, 'missing' => $removed], $ddlRan);
            }

            $allowed = $kind === 'index' ? [self::INDEX.'(status,scheduled_at)'] : [];

            if (array_diff($added, $allowed) !== []) {
                $this->abort('the operation created objects it does not own', ['kind' => $kind, 'unexpected' => array_diff($added, $allowed)], $ddlRan);
            }
        }

        if (! $this->hasIndex($after['objects']['index'])) {
            $this->abort(self::INDEX.' is absent after the migration', ['indexes' => $after['objects']['index']], $ddlRan);
        }
    }

    private function abort(string $reason, array $evidence = [], bool $ddlRan = false): never
    {
        throw new RuntimeException(
            'Refusing to widen live_sessions.status: '.$reason.'.'
            .($evidence === [] ? '' : ' Census: '.json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            .($ddlRan
                ? ' The widening had already run and Laravel does not wrap this migration in a transaction, so'
                    .' live_sessions keeps the new column shape while the batch row is missing; inspect before retrying.'
                : ' No DDL was executed.')
        );
    }
};
