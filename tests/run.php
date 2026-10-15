<?php
/**
 * Workflow tests for Easy Double Entry against the in-memory REDCap fake.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php checkbox   run tests whose name contains "checkbox"
 *
 * Each test drives the module the way users do: data-entry saves fire
 * redcap_save_record, and the compare/merge/finalize/dashboard pages go
 * through redcap_module_ajax with a real user id.
 */

require __DIR__ . '/bootstrap.php';

use CCHMC\EasyDoubleEntry\EasyDoubleEntry;
use EdeTest\Fake;

// ─── Fixture ─────────────────────────────────────────────────────────

const FORM = 'cog_exam';

function fixture(array $opts = []): EasyDoubleEntry
{
    Fake::reset();
    Fake::$longitudinal = $opts['longitudinal'] ?? false;
    if (Fake::$longitudinal) {
        Fake::$events = [41 => 'baseline_arm_1', 42 => 'followup_arm_1'];
    }
    Fake::$forms = [
        'demographics' => [
            'record_id' => ['field_type' => 'text', 'field_label' => 'Record ID'],
            'cohort' => ['field_type' => 'radio', 'select_choices_or_calculations' => 'asd, ASD | td, TD'],
        ],
        FORM => [
            'score' => ['field_type' => 'text', 'field_label' => 'Score'],
            'mood' => ['field_type' => 'radio', 'field_label' => 'Mood', 'select_choices_or_calculations' => '1, Low | 2, Mid | 3, High'],
            'symptoms' => ['field_type' => 'checkbox', 'field_label' => 'Symptoms', 'select_choices_or_calculations' => '1.5, Half | -1, Negative | 2, Two'],
            'notes' => ['field_type' => 'notes', 'field_label' => 'Notes'],
            'total' => ['field_type' => 'calc', 'field_label' => 'Total', 'select_choices_or_calculations' => '[score]*2'],
            'clin_only' => ['field_type' => 'text', 'field_label' => 'Clinician only'],
            'scan' => ['field_type' => 'file', 'field_label' => 'Scan'],
        ],
    ];
    foreach (Fake::$forms as $form => &$fields) {
        foreach ($fields as $name => &$meta) {
            $meta += ['field_name' => $name, 'form_name' => $form, 'field_annotation' => '', 'select_choices_or_calculations' => '', 'field_label' => $name];
        }
    }
    unset($fields, $meta);
    Fake::$repeatingForms = [FORM];
    Fake::$subSettings['dde-instrument-config'] = [
        ['dde-instrument' => FORM, 'dde-exclude-fields' => 'clin_only'],
    ];
    Fake::$settings['merge-target-instance'] = $opts['target'] ?? '3';
    Fake::$settings['notification-email'] = $opts['email'] ?? '';
    Fake::$users = [
        'alice' => ['forms' => [FORM => '1', 'demographics' => '1'], 'group_id' => null],
        'viewer' => ['forms' => [FORM => '2', 'demographics' => '2'], 'group_id' => null],
        'dag_b' => ['forms' => [FORM => '1', 'demographics' => '1'], 'group_id' => 2],
    ];
    Fake::$currentUser = 'alice';
    return new EasyDoubleEntry();
}

/**
 * A data-entry save of one round, as the REDCap form would perform it,
 * followed by the save hook. Checkbox values are given as a list of codes.
 */
function enterRound(EasyDoubleEntry $m, string $record, int $instance, array $values, ?int $eventId = null): void
{
    $eventId ??= Fake::firstEventId();
    if (!Fake::recordExists($record)) Fake::createRecord($record, null, $eventId);
    $k = Fake::key($record, $eventId, FORM, $instance);
    $row = Fake::$data[$k] ?? [Fake::$recordIdField => $record];
    foreach ($values as $field => $v) {
        if (is_array($v)) {
            foreach (Fake::columnsOf($field) as $col) $row[$col] = '0';
            foreach ($v as $code) $row[$field . '___' . Fake::checkboxCode((string)$code)] = '1';
        } else {
            $row[$field] = (string)$v;
        }
    }
    $row[FORM . '_complete'] = $row[FORM . '_complete'] ?? '2';
    Fake::$data[$k] = $row;
    $m = new EasyDoubleEntry(); // the save is its own request
    $m->redcap_save_record(Fake::$pid, $record, FORM, $eventId, null, null, null, $instance);
}

function ajax(EasyDoubleEntry $m, string $action, array $payload = [], ?string $user = null): array
{
    $user ??= Fake::$currentUser;
    Fake::$currentUser = $user;
    $m = new EasyDoubleEntry(); // every REDCap request gets a fresh module instance
    $payload += ['instrument' => FORM];
    if (!isset($payload['event_id'])) $payload['event_id'] = Fake::firstEventId();
    return $m->redcap_module_ajax($action, $payload, Fake::$pid, null, null, null, null, null, null, null, null, null, $user, null);
}

function merge(EasyDoubleEntry $m, string $record, string $field, int $sourceRound, string $value = '', array $extra = []): array
{
    return ajax($m, 'merge-field', $extra + ['record' => $record, 'field_name' => $field, 'source_round' => $sourceRound, 'value' => $value]);
}

/** Two rounds that disagree on score, mood, symptoms and notes; agree elsewhere. */
function seedDiscrepant(EasyDoubleEntry $m, string $record = '101', ?int $eventId = null): void
{
    enterRound($m, $record, 1, ['score' => '5', 'mood' => '1', 'symptoms' => ['1.5', '-1'], 'notes' => 'same', 'clin_only' => 'R1 clinician'], $eventId);
    enterRound($m, $record, 2, ['score' => '7', 'mood' => '2', 'symptoms' => ['2'], 'notes' => 'same', 'clin_only' => 'R2 clinician'], $eventId);
}

function statusOf(EasyDoubleEntry $m, string $record, ?string $eventName = null): ?string
{
    foreach (ajax($m, 'get-dashboard-data') as $row) {
        if ((string)$row['record'] !== $record) continue;
        foreach ($row['instruments'] as $inst) {
            if ($eventName === null || $inst['event_name'] === $eventName) return $inst['status'];
        }
    }
    return null;
}

// ─── Assertions ──────────────────────────────────────────────────────

final class AssertionFailed extends Exception {}

function check(bool $cond, string $msg): void
{
    if (!$cond) throw new AssertionFailed($msg);
}

function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(array $result, string $msg = ''): array
{
    if (!empty($result['error']) || (array_key_exists('success', $result) && !$result['success'])) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'unexpected failure ' . json_encode($result));
    }
    return $result;
}

// ─── Tests ───────────────────────────────────────────────────────────

$tests = [];

$tests['copy mode: full adjudication produces a complete final entry'] = function () {
    $m = fixture();
    seedDiscrepant($m);

    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    eq('discrepant', $cmp['status']);
    $unresolved = $cmp['unresolved_fields'];
    sort($unresolved);
    eq(['mood', 'score', 'symptoms'], $unresolved, 'discrepant writable fields');
    check(!in_array('clin_only', array_column($cmp['fields'], 'field_name'), true), 'excluded field is not compared');

    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 0, '3'));
    ok(merge($m, '101', 'symptoms', 2));
    eq(0, ok(ajax($m, 'compare-rounds', ['record' => '101']))['unresolved_count']);

    $fin = ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('copy', $fin['mode']);

    eq('5', Fake::value('101', FORM, 3, 'score'), 'Keep R1');
    eq('3', Fake::value('101', FORM, 3, 'mood'), 'custom value');
    eq('1', Fake::value('101', FORM, 3, 'symptoms___2'), 'Keep R2 checkbox (code 2)');
    eq('0', Fake::value('101', FORM, 3, 'symptoms___1_5'), 'Keep R2 checkbox (code 1.5)');
    eq('0', Fake::value('101', FORM, 3, 'symptoms____1'), 'Keep R2 checkbox (code -1 exports as _1)');
    eq('same', Fake::value('101', FORM, 3, 'notes'), 'matching field copied');
    eq('R1 clinician', Fake::value('101', FORM, 3, 'clin_only'), 'excluded field copied from R1');
    eq('2', Fake::value('101', FORM, 3, FORM . '_complete'));

    eq('merged', statusOf($m, '101'));
    eq([], ajax($m, 'get-task-list'), 'nothing left to do');
};

$tests['checkbox codes with "." and "-" are compared and merged'] = function () {
    $m = fixture();
    enterRound($m, '101', 1, ['score' => '1', 'mood' => '1', 'symptoms' => ['1.5']]);
    enterRound($m, '101', 2, ['score' => '1', 'mood' => '1', 'symptoms' => ['-1']]);

    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    $sym = array_values(array_filter($cmp['fields'], fn($f) => $f['field_name'] === 'symptoms'))[0];
    eq('1.5', $sym['round1_value']);
    eq('-1', $sym['round2_value']);
    eq(false, $sym['match']);

    ok(merge($m, '101', 'symptoms', 1));
    ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('1', Fake::value('101', FORM, 3, 'symptoms___1_5'));
    eq('0', Fake::value('101', FORM, 3, 'symptoms____1'));
};

$tests['in-place mode: custom value resolves and finalizes'] = function () {
    $m = fixture(['target' => '1']);
    seedDiscrepant($m);

    ok(merge($m, '101', 'score', 0, '6'));
    eq('6', Fake::value('101', FORM, 1, 'score'), 'custom written into Round 1');
    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    check(in_array('score', $cmp['resolved_fields'], true), 'score counts as resolved after in-place custom merge');
    check(!in_array('score', $cmp['unresolved_fields'], true), 'score not unresolved');

    ok(merge($m, '101', 'mood', 1));   // Keep R1 — Round 1 unchanged
    ok(merge($m, '101', 'symptoms', 2)); // Keep R2 — Round 1 now matches Round 2
    eq(0, ok(ajax($m, 'compare-rounds', ['record' => '101']))['unresolved_count']);

    $fin = ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('in_place', $fin['mode']);
    eq('2', Fake::value('101', FORM, 1, FORM . '_complete'));
    eq(null, Fake::value('101', FORM, 3, 'score'), 'no instance 3 in in-place mode');
    eq('merged', statusOf($m, '101'));
};

$tests['in-place mode: editing Round 1 after a custom merge re-opens the field'] = function () {
    $m = fixture(['target' => '1']);
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 0, '6'));
    enterRound($m, '101', 1, ['score' => '9']);
    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    check(in_array('score', $cmp['unresolved_fields'], true), 'hand edit of the merged value invalidates the resolution');
};

$tests['switching merge target 3 → 1 after resolving forces re-resolution'] = function () {
    $m = fixture(['target' => '3']);
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 2));
    ok(merge($m, '101', 'mood', 2));
    ok(merge($m, '101', 'symptoms', 2));

    Fake::$settings['merge-target-instance'] = '1';
    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    eq(3, $cmp['unresolved_count'], 'resolutions written to instance 3 do not count for in-place mode');
    $fin = ajax($m, 'finalize-merge', ['record' => '101']);
    eq(false, $fin['success'] ?? null, 'finalize refused');
    eq('5', Fake::value('101', FORM, 1, 'score'), 'Round 1 untouched');
};

$tests['switching merge target 1 → 3 after resolving forces re-resolution'] = function () {
    $m = fixture(['target' => '1']);
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 0, '6'));
    ok(merge($m, '101', 'mood', 1));

    Fake::$settings['merge-target-instance'] = '3';
    $cmp = ok(ajax($m, 'compare-rounds', ['record' => '101']));
    check(in_array('mood', $cmp['unresolved_fields'], true), 'in-place resolution is not valid for copy mode');
    eq(false, ajax($m, 'finalize-merge', ['record' => '101'])['success'] ?? null, 'finalize refused');
    eq(null, Fake::value('101', FORM, 3, FORM . '_complete'), 'no incomplete instance 3 marked Complete');
};

$tests['copy mode: finalize restores adjudicated values if instance 3 was deleted'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    $long = str_repeat('Custom adjudicated note. ', 20); // > 200 chars
    enterRound($m, '101', 2, ['notes' => 'different']);
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 0, '3'));
    ok(merge($m, '101', 'symptoms', 2));
    ok(merge($m, '101', 'notes', 0, $long));

    Fake::deleteInstance('101', FORM, 3);
    ok(ajax($m, 'finalize-merge', ['record' => '101']));

    eq('5', Fake::value('101', FORM, 3, 'score'));
    eq('3', Fake::value('101', FORM, 3, 'mood'));
    eq('1', Fake::value('101', FORM, 3, 'symptoms___2'));
    eq($long, Fake::value('101', FORM, 3, 'notes'), 'long custom value restored in full');
    eq('2', Fake::value('101', FORM, 3, FORM . '_complete'));
};

$tests['copy mode: finalize overwrites a hand-edited adjudicated value'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 1));
    ok(merge($m, '101', 'symptoms', 1));
    Fake::$data[Fake::key('101', Fake::firstEventId(), FORM, 3)]['score'] = '999';
    ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('5', Fake::value('101', FORM, 3, 'score'));
};

$tests['re-resolving a field within the same second keeps the latest choice'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'score', 2)); // same fake second
    ok(merge($m, '101', 'mood', 1));
    ok(merge($m, '101', 'symptoms', 1));
    eq('2', $m->getResolvedFields(Fake::$pid, '101', FORM, Fake::firstEventId())['score']['source_round']);
    ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('7', Fake::value('101', FORM, 3, 'score'), 'Round 2 value wins');
};

$tests['notifications: one email when both rounds are complete, no repeats'] = function () {
    $m = fixture(['email' => 'coord@example.org']);
    enterRound($m, '101', 1, ['score' => '5', 'mood' => '1']);
    eq(0, count(Fake::$emails), 'not after Round 1');
    enterRound($m, '101', 2, ['score' => '7', 'mood' => '1']);
    eq(1, count(Fake::$emails), 'after Round 2');
    enterRound($m, '101', 2, ['score' => '7']);
    eq(1, count(Fake::$emails), 'resave does not re-send');
};

$tests['notifications: post-finalize saves only re-notify when the merge is invalidated'] = function () {
    $m = fixture(['email' => 'coord@example.org']);
    seedDiscrepant($m);
    eq(1, count(Fake::$emails));
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 1));
    ok(merge($m, '101', 'symptoms', 1));
    ok(ajax($m, 'finalize-merge', ['record' => '101']));

    Fake::tick();
    enterRound($m, '101', 1, ['clin_only' => 'typo fixed']); // excluded field
    enterRound($m, '101', 2, ['notes' => 'same']);           // no change
    eq(1, count(Fake::$emails), 'saves that leave the merge valid send nothing');

    Fake::tick();
    enterRound($m, '101', 2, ['notes' => 'changed after finalize']);
    eq(2, count(Fake::$emails), 'new discrepancy after finalize notifies once');
    enterRound($m, '101', 2, ['notes' => 'changed again']);
    eq(2, count(Fake::$emails), 'and only once');
};

$tests['notification markers of other projects are untouched'] = function () {
    $m = fixture(['email' => 'coord@example.org']);
    Fake::$logs[] = ['log_id' => 999, 'timestamp' => '2026-01-01 00:00:00', 'message' => 'DDE notification sent', 'project_id' => 99, 'record' => '101', 'instrument' => FORM, 'event_id' => (string)Fake::firstEventId()];
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 1));
    ok(merge($m, '101', 'symptoms', 1));
    ok(ajax($m, 'finalize-merge', ['record' => '101']));
    check(in_array(999, array_column(Fake::$logs, 'log_id'), true), 'project 99 marker survives');
};

$tests['padded instrument name in payload still compares'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    $cmp = ok(ajax($m, 'compare-rounds', ['record' => ' 101 ', 'instrument' => ' ' . FORM . ' ']));
    eq('discrepant', $cmp['status']);
    eq(FORM, $cmp['instrument']);
};

$tests['longitudinal: statuses and merges are per event'] = function () {
    $m = fixture(['longitudinal' => true]);
    seedDiscrepant($m, '101', 42);
    enterRound($m, '101', 1, ['score' => '1'], 41);

    eq('partial', statusOf($m, '101', 'baseline_arm_1'));
    eq('ready_to_compare', statusOf($m, '101', 'followup_arm_1'));

    eq('A valid event is required for this action', ajax($m, 'compare-rounds', ['record' => '101', 'event_id' => 0])['error'] ?? null);
    ok(merge($m, '101', 'score', 1, '', ['event_id' => 42]));
    ok(merge($m, '101', 'mood', 1, '', ['event_id' => 42]));
    ok(merge($m, '101', 'symptoms', 1, '', ['event_id' => 42]));
    ok(ajax($m, 'finalize-merge', ['record' => '101', 'event_id' => 42]));
    eq('5', Fake::value('101', FORM, 3, 'score', 42));
    eq(null, Fake::value('101', FORM, 3, 'score', 41));
    eq('merged', statusOf($m, '101', 'followup_arm_1'));
    eq('partial', statusOf($m, '101', 'baseline_arm_1'));
};

$tests['longitudinal: rows from an unresolvable event are not attributed to the first event'] = function () {
    $m = fixture(['longitudinal' => true]);
    Fake::$ghostEvents = [40 => 'retired_arm_9'];
    Fake::createRecord('101', null, 41);
    Fake::$data[Fake::key('101', 40, FORM, 1)] = ['record_id' => '101', 'score' => '1', FORM . '_complete' => '2'];
    Fake::$data[Fake::key('101', 40, FORM, 2)] = ['record_id' => '101', 'score' => '2', FORM . '_complete' => '2'];
    foreach (ajax($m, 'get-dashboard-data') as $row) {
        foreach ($row['instruments'] as $inst) {
            check($inst['event_id'] !== 40 && !($inst['event_name'] === 'retired_arm_9' && $inst['event_id'] > 0),
                'unmapped event row must not be linked to a real event id: ' . json_encode($inst));
        }
    }
};

$tests['classic project: merge payloads carry no event name'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 1), 'saveData would reject redcap_event_name in a classic project');
};

$tests['stale tab: Keep R1 copies the server value, not the browser value'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    enterRound($m, '101', 1, ['score' => '8']); // R1 edited after the page loaded
    ok(merge($m, '101', 'score', 1, '5'));
    eq('8', Fake::value('101', FORM, 3, 'score'));
};

$tests['post-finalize round edit demotes the dashboard to Finish Merge'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    ok(merge($m, '101', 'score', 1));
    ok(merge($m, '101', 'mood', 1));
    ok(merge($m, '101', 'symptoms', 1));
    ok(ajax($m, 'finalize-merge', ['record' => '101']));
    eq('merged', statusOf($m, '101'));
    enterRound($m, '101', 2, ['score' => '4']);
    eq('merge_in_progress', statusOf($m, '101'));
    eq('Finish Merge', ajax($m, 'get-task-list')[0]['action'] ?? null);
};

$tests['permissions: read-only user cannot merge or finalize'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    ok(ajax($m, 'compare-rounds', ['record' => '101'], 'viewer'));
    eq('You do not have edit rights on this instrument', merge($m, '101', 'score', 1)['error'] ?? null);
    eq('You do not have edit rights on this instrument', ajax($m, 'finalize-merge', ['record' => '101'], 'viewer')['error'] ?? null);
};

$tests['permissions: DAG user cannot reach records outside their group'] = function () {
    $m = fixture();
    seedDiscrepant($m);
    Fake::$recordGroups['101'] = 1;
    eq('Record not found in your Data Access Group', ajax($m, 'compare-rounds', ['record' => '101'], 'dag_b')['error'] ?? null);
    eq([], ajax($m, 'get-dashboard-data', [], 'dag_b'));
};

$tests['merge into a nonexistent record is refused and creates nothing'] = function () {
    $m = fixture();
    eq('Record not found', merge($m, '999', 'score', 0, '1')['error'] ?? null);
    check(!Fake::recordExists('999'), 'no junk record');
};

$tests['dashboard cost does not grow per merged record'] = function () {
    $m = fixture();
    foreach (['101', '102', '103', '104', '105', '106'] as $rid) {
        seedDiscrepant($m, $rid);
        ok(merge($m, $rid, 'score', 1));
        ok(merge($m, $rid, 'mood', 1));
        ok(merge($m, $rid, 'symptoms', 1));
        ok(ajax($m, 'finalize-merge', ['record' => $rid]));
    }
    $m2 = new EasyDoubleEntry(); // fresh instance: no dashboard cache
    Fake::$calls = [];
    $stats = ajax($m2, 'get-dde-stats');
    eq(6, $stats['merged']);
    check((Fake::$calls['getData'] ?? 0) <= 3, 'getData calls: ' . (Fake::$calls['getData'] ?? 0));
    check((Fake::$calls['queryLogs'] ?? 0) <= 3, 'queryLogs calls: ' . (Fake::$calls['queryLogs'] ?? 0));
    check((Fake::$calls['getDataDictionary'] ?? 0) <= 1, 'getDataDictionary calls: ' . (Fake::$calls['getDataDictionary'] ?? 0));
};

// ─── Runner ──────────────────────────────────────────────────────────

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
foreach ($tests as $name => $fn) {
    if ($filter !== '' && stripos($name, $filter) === false) continue;
    try {
        $fn();
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        $where = $e instanceof AssertionFailed ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "  \033[31m✗\033[0m $name\n      " . get_class($e) . ': ' . $e->getMessage() . "$where\n";
    }
}
echo "\n$passed passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
