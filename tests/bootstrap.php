<?php
/**
 * In-memory stand-in for the parts of REDCap and the External Module framework
 * that Easy Double Entry touches. Lets the real module class run complete
 * enter → compare → merge → finalize workflows without a REDCap server.
 *
 * Behaviors deliberately mirror REDCap where the module depends on them:
 *   - checkbox export columns use Project::getExtendedCheckboxCodeFormatted
 *   - saveData rejects unknown columns, writes to calc/file fields, and a
 *     redcap_event_name key in classic projects
 *   - module log queries are scoped to the current project (LogPseudoQuery)
 *   - log timestamps have one-second resolution; ties on ORDER BY timestamp
 *     come back in reverse insertion order (MySQL leaves the order undefined)
 */

namespace EdeTest {

    final class Fake
    {
        public static int $pid = 15;
        public static string $recordIdField = 'record_id';
        public static bool $longitudinal = false;
        /** event_id => unique event name (what getEventNames reports) */
        public static array $events = [];
        /** event_id => unique name used in exports, for events getEventNames omits */
        public static array $ghostEvents = [];
        /** form => [field => meta] */
        public static array $forms = [];
        public static array $repeatingForms = [];
        /** "record|event_id|repeat_form|instance" => [column => value] */
        public static array $data = [];
        /** record => group_id */
        public static array $recordGroups = [];
        public static array $users = [];
        public static array $superUsers = [];
        public static string $currentUser = 'alice';
        public static array $settings = [];
        public static array $subSettings = [];
        public static array $logs = [];
        public static int $nextLogId = 1;
        public static int $clock = 1767225600;
        public static array $emails = [];
        public static array $calls = [];

        public static function reset(): void
        {
            self::$pid = 15;
            self::$longitudinal = false;
            self::$events = [41 => 'event_1_arm_1'];
            self::$ghostEvents = [];
            self::$forms = [];
            self::$repeatingForms = [];
            self::$data = [];
            self::$recordGroups = [];
            self::$users = [];
            self::$superUsers = [];
            self::$currentUser = 'alice';
            self::$settings = [];
            self::$subSettings = [];
            self::$logs = [];
            self::$nextLogId = 1;
            self::$clock = 1767225600;
            self::$emails = [];
            self::$calls = [];
        }

        public static function count(string $name): void
        {
            self::$calls[$name] = (self::$calls[$name] ?? 0) + 1;
        }

        public static function firstEventId(): int
        {
            $ids = array_keys(self::$events + self::$ghostEvents);
            sort($ids);
            return $ids[0];
        }

        public static function eventName(int $eventId): string
        {
            return self::$events[$eventId] ?? self::$ghostEvents[$eventId] ?? '';
        }

        /** REDCap's Project::getExtendedCheckboxCodeFormatted */
        public static function checkboxCode(string $code): string
        {
            $code = str_replace(['-', '.'], '_', $code);
            return preg_replace('/[^a-z_0-9]/', '', strtolower($code));
        }

        public static function formOf(string $field): ?string
        {
            foreach (self::$forms as $form => $fields) {
                if (isset($fields[$field])) return $form;
            }
            if (str_ends_with($field, '_complete') && isset(self::$forms[substr($field, 0, -9)])) {
                return substr($field, 0, -9);
            }
            return null;
        }

        /** Export columns for a field (checkboxes expand to field___code). */
        public static function columnsOf(string $field): array
        {
            $form = self::formOf($field);
            $meta = $form !== null ? (self::$forms[$form][$field] ?? null) : null;
            if ($meta && $meta['field_type'] === 'checkbox') {
                $cols = [];
                foreach (explode('|', $meta['select_choices_or_calculations']) as $choice) {
                    $code = trim(explode(',', $choice, 2)[0]);
                    $cols[] = $field . '___' . self::checkboxCode($code);
                }
                return $cols;
            }
            return [$field];
        }

        public static function key(string $record, int $eventId, string $form, $instance): string
        {
            return "$record|$eventId|$form|$instance";
        }

        public static function createRecord(string $record, ?int $groupId = null, ?int $eventId = null): void
        {
            $eventId ??= self::firstEventId();
            $k = self::key($record, $eventId, '', '');
            self::$data[$k] = (self::$data[$k] ?? []) + [self::$recordIdField => $record];
            if ($groupId !== null) self::$recordGroups[$record] = $groupId;
        }

        public static function recordExists(string $record): bool
        {
            foreach (array_keys(self::$data) as $k) {
                if (explode('|', $k)[0] === $record) return true;
            }
            return false;
        }

        public static function deleteInstance(string $record, string $form, int $instance, ?int $eventId = null): void
        {
            unset(self::$data[self::key($record, $eventId ?? self::firstEventId(), $form, $instance)]);
        }

        public static function value(string $record, string $form, int $instance, string $column, ?int $eventId = null): ?string
        {
            $row = self::$data[self::key($record, $eventId ?? self::firstEventId(), $form, $instance)] ?? null;
            return $row === null ? null : ($row[$column] ?? '');
        }

        public static function tick(int $seconds = 1): void
        {
            self::$clock += $seconds;
        }
    }

    final class Result
    {
        private int $i = 0;
        public function __construct(private array $rows) {}
        public function fetch_assoc(): ?array
        {
            return $this->rows[$this->i++] ?? null;
        }
    }

    final class FakeUser
    {
        public function __construct(private string $username) {}
        public function getUsername(): string { return $this->username; }
        public function isSuperUser(): bool { return in_array($this->username, Fake::$superUsers, true); }
        public function getRights(): array { return Fake::$users[$this->username] ?? []; }
    }

    final class FakeFramework
    {
        public function getSubSettings(string $key): array
        {
            return Fake::$subSettings[$key] ?? [];
        }
        public function getUser($username = null): FakeUser
        {
            return new FakeUser($username ?? Fake::$currentUser);
        }
        public function getProjectId(): int
        {
            return Fake::$pid;
        }
    }

    /**
     * Minimal parser for the module's log pseudo-SQL:
     *   SELECT cols WHERE a = ? AND b = ? [ORDER BY col [DESC]] [LIMIT n]
     */
    final class LogQuery
    {
        public static function run(string $sql, array $params): array
        {
            if (!preg_match('/^\s*SELECT\s+(.+?)\s+WHERE\s+(.+?)(?:\s+ORDER BY\s+(\w+)(\s+DESC)?)?(?:\s+LIMIT\s+(\d+))?\s*$/is', $sql, $m)) {
                throw new \RuntimeException("Unsupported log query: $sql");
            }
            $cols = array_map('trim', explode(',', $m[1]));
            $rows = self::filter($m[2], $params);
            $orderCol = $m[3] ?? '';
            if ($orderCol !== '') {
                $desc = !empty($m[4]);
                // Reverse insertion order first so ties are NOT in insertion order,
                // like an unordered MySQL result — then a stable sort on the column
                $rows = array_reverse($rows);
                usort($rows, static function ($a, $b) use ($orderCol, $desc) {
                    $cmp = ($a[$orderCol] ?? '') <=> ($b[$orderCol] ?? '');
                    return $desc ? -$cmp : $cmp;
                });
            }
            if (!empty($m[5])) $rows = array_slice($rows, 0, (int)$m[5]);
            return array_map(static function ($r) use ($cols) {
                $out = [];
                foreach ($cols as $c) {
                    $out[$c] = isset($r[$c]) ? (string)$r[$c] : null;
                }
                return $out;
            }, $rows);
        }

        public static function filter(string $where, array $params): array
        {
            $conds = preg_split('/\s+AND\s+/i', trim($where));
            $pairs = [];
            foreach ($conds as $i => $c) {
                if (!preg_match('/^(\w+)\s*=\s*\?$/', trim($c), $cm)) {
                    throw new \RuntimeException("Unsupported log condition: $c");
                }
                $pairs[$cm[1]] = $params[$i];
            }
            // Framework adds project scoping when the clause does not name it
            if (!array_key_exists('project_id', $pairs)) $pairs['project_id'] = Fake::$pid;
            return array_values(array_filter(Fake::$logs, static function ($log) use ($pairs) {
                foreach ($pairs as $col => $val) {
                    if (!array_key_exists($col, $log) || (string)$log[$col] !== (string)$val) return false;
                }
                return true;
            }));
        }
    }
}

namespace ExternalModules {

    use EdeTest\Fake;
    use EdeTest\FakeFramework;
    use EdeTest\LogQuery;
    use EdeTest\Result;

    abstract class AbstractExternalModule
    {
        public $framework;

        public function __construct()
        {
            $this->framework = new FakeFramework();
        }

        public function getProjectSetting($key)
        {
            return Fake::$settings[$key] ?? null;
        }

        public function getUrl($path)
        {
            return "https://redcap.test/ExternalModules/?prefix=ede&page=$path";
        }

        public function log($message, $parameters = [])
        {
            Fake::count('log');
            $row = [
                'log_id' => Fake::$nextLogId++,
                'timestamp' => date('Y-m-d H:i:s', Fake::$clock),
                'message' => $message,
                'project_id' => Fake::$pid,
            ];
            foreach ($parameters as $k => $v) {
                $row[$k] = $v === null ? null : (string)$v;
            }
            Fake::$logs[] = $row;
            return $row['log_id'];
        }

        public function queryLogs($sql, $parameters = [])
        {
            Fake::count('queryLogs');
            return new Result(LogQuery::run($sql, $parameters));
        }

        public function removeLogs($where, $parameters = [])
        {
            Fake::count('removeLogs');
            $doomed = array_column(LogQuery::filter($where, $parameters), 'log_id');
            Fake::$logs = array_values(array_filter(
                Fake::$logs,
                static fn($l) => !in_array($l['log_id'], $doomed, true)
            ));
        }

        public function query($sql, $parameters = [])
        {
            if (str_contains($sql, 'redcap_events_metadata')) {
                return new Result([['event_id' => (string)Fake::firstEventId()]]);
            }
            throw new \RuntimeException("Unsupported SQL: $sql");
        }
    }
}

namespace {

    use EdeTest\Fake;

    if (!defined('APP_PATH_WEBROOT')) define('APP_PATH_WEBROOT', '/redcap_v14.5.29/');

    class REDCap
    {
        public static function getRecordIdField()
        {
            return Fake::$recordIdField;
        }

        public static function isLongitudinal()
        {
            return Fake::$longitudinal;
        }

        public static function getEventNames($unique = false, $assoc = false, $eventId = null)
        {
            if (!Fake::$longitudinal) return false;
            if ($eventId !== null) return Fake::$events[$eventId] ?? false;
            return Fake::$events;
        }

        public static function getUserRights($username = null)
        {
            return isset(Fake::$users[$username]) ? [$username => Fake::$users[$username]] : [];
        }

        public static function getDataDictionary($pid, $format = 'array', $html = false, $fields = null, $forms = null)
        {
            Fake::count('getDataDictionary');
            $out = [];
            foreach ((array)$forms as $form) {
                foreach (Fake::$forms[$form] ?? [] as $name => $meta) {
                    $out[$name] = $meta + ['field_name' => $name, 'form_name' => $form];
                }
            }
            return $out;
        }

        public static function email($to, $from, $subject, $body)
        {
            Fake::$emails[] = compact('to', 'from', 'subject', 'body');
            return true;
        }

        public static function getData($params)
        {
            Fake::count('getData');
            $rid = Fake::$recordIdField;
            $records = $params['records'] ?? null;
            $events = $params['events'] ?? null;
            $groups = $params['groups'] ?? null;

            // Requested fields → export columns
            $fields = [];
            if (!empty($params['forms'])) {
                foreach ($params['forms'] as $form) {
                    $fields = array_merge($fields, array_keys(Fake::$forms[$form] ?? []), [$form . '_complete']);
                }
            }
            if (!empty($params['fields'])) $fields = array_merge($fields, $params['fields']);
            if (empty($params['forms']) && empty($params['fields'])) {
                foreach (Fake::$forms as $form => $fs) {
                    $fields = array_merge($fields, array_keys($fs), [$form . '_complete']);
                }
            }
            $fields = array_values(array_unique(array_merge([$rid], $fields)));
            $columns = [];
            foreach ($fields as $f) {
                foreach (Fake::columnsOf($f) as $c) $columns[] = $c;
            }

            $rows = [];
            $keys = array_keys(Fake::$data);
            sort($keys);
            foreach ($keys as $k) {
                [$record, $eventId, $form, $instance] = explode('|', $k);
                if ($records !== null && !in_array($record, array_map('strval', $records), true)) continue;
                if ($events !== null && !in_array((int)$eventId, array_map('intval', $events), true)) continue;
                if ($groups !== null && !in_array(Fake::$recordGroups[$record] ?? null, $groups)) continue;

                $stored = Fake::$data[$k];
                $out = [$rid => $record];
                if (Fake::$longitudinal) $out['redcap_event_name'] = Fake::eventName((int)$eventId);
                $out['redcap_repeat_instrument'] = $form;
                $out['redcap_repeat_instance'] = $instance === '' ? '' : (int)$instance;
                $hasRequested = $form === '';
                foreach ($columns as $c) {
                    if ($c === $rid) continue;
                    $base = explode('___', $c)[0];
                    $owner = Fake::formOf($base);
                    $inThisRow = $form === '' ? !in_array($owner, Fake::$repeatingForms, true) : $owner === $form;
                    $out[$c] = $inThisRow ? (string)($stored[$c] ?? (str_contains($c, '___') ? '0' : '')) : '';
                    if ($inThisRow) $hasRequested = true;
                }
                if ($hasRequested) $rows[] = $out;
            }
            return json_encode($rows);
        }

        public static function saveData($pid, $format, $json, $overwrite = 'normal')
        {
            Fake::count('saveData');
            $rid = Fake::$recordIdField;
            $errors = [];
            $pending = [];
            foreach (json_decode($json, true) as $row) {
                $record = (string)($row[$rid] ?? '');
                if ($record === '') {
                    $errors[] = 'Missing record ID';
                    continue;
                }
                if (Fake::$longitudinal) {
                    $eventId = array_search($row['redcap_event_name'] ?? '', Fake::$events, true);
                    if ($eventId === false) {
                        $errors[] = "Invalid event name '" . ($row['redcap_event_name'] ?? '') . "'";
                        continue;
                    }
                } else {
                    if (array_key_exists('redcap_event_name', $row)) {
                        $errors[] = "redcap_event_name is not valid in a classic project";
                        continue;
                    }
                    $eventId = Fake::firstEventId();
                }
                $form = (string)($row['redcap_repeat_instrument'] ?? '');
                $instance = $form === '' ? '' : (int)($row['redcap_repeat_instance'] ?? 1);
                $values = [];
                foreach ($row as $col => $val) {
                    if (in_array($col, [$rid, 'redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance'], true)) continue;
                    $base = explode('___', $col)[0];
                    $owner = Fake::formOf($base);
                    if ($owner === null || !in_array($col, Fake::columnsOf($base), true) && $col !== $base . '_complete') {
                        $errors[] = "Unknown field or checkbox column '$col'";
                        continue;
                    }
                    if ($owner !== $form) {
                        $errors[] = "Field '$col' does not belong to repeating instrument '$form'";
                        continue;
                    }
                    $type = Fake::$forms[$owner][$base]['field_type'] ?? 'text';
                    if (in_array($type, ['calc', 'file'], true)) {
                        $errors[] = "Cannot import into $type field '$col'";
                        continue;
                    }
                    $values[$col] = (string)$val;
                }
                $pending[] = [$record, (int)$eventId, $form, $instance, $values];
            }
            if ($errors) return ['errors' => $errors, 'ids' => []];
            foreach ($pending as [$record, $eventId, $form, $instance, $values]) {
                if (!Fake::recordExists($record)) Fake::createRecord($record, null, $eventId);
                $k = Fake::key($record, $eventId, $form, $instance);
                Fake::$data[$k] = array_merge(Fake::$data[$k] ?? [Fake::$recordIdField => $record], $values);
            }
            return ['errors' => [], 'ids' => array_column($pending, 0)];
        }
    }

    require_once __DIR__ . '/../EasyDoubleEntry.php';
}
