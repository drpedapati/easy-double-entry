<?php
namespace CCHMC\EasyDoubleEntry;

use ExternalModules\AbstractExternalModule;
use REDCap;

class EasyDoubleEntry extends AbstractExternalModule
{
    /** @var int Instance number for Round 1 data entry */
    const ROUND_1 = 1;
    /** @var int Instance number for Round 2 data entry */
    const ROUND_2 = 2;
    /** @var int Instance number for the final merged record */
    const FINAL_INSTANCE = 3;

    private ?array $dashboardCache = null;

    // ─── Hooks ───────────────────────────────────────────────────────

    /**
     * Every page top: inject dashboard filtering and round selector UI.
     */
    function redcap_every_page_top($project_id)
    {
        if (!$project_id) return;

        $ddeInstruments = $this->getDDEInstruments();
        if (empty($ddeInstruments)) return;

        // On Record Status Dashboard — filter to show only relevant instruments
        if ($this->isRecordStatusDashboard()) {
            $this->injectDashboardFilter($ddeInstruments);
        }
    }

    /**
     * Data entry form top: inject round selector banner for DDE instruments.
     */
    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $ddeInstruments = $this->getDDEInstruments();
        if (!in_array($instrument, $ddeInstruments)) return;

        // Show round indicator banner
        $roundLabel = $this->getRoundLabel($repeat_instance);
        $otherRound = $repeat_instance == self::ROUND_1 ? self::ROUND_2 : self::ROUND_1;
        $otherLabel = $this->getRoundLabel($otherRound);
        $isFinal = $repeat_instance == self::FINAL_INSTANCE;

        $statusClass = $isFinal ? 'info' : ($repeat_instance == self::ROUND_1 ? 'primary' : 'success');

        echo '<div class="ede-round-banner alert alert-' . $statusClass . ' d-flex justify-content-between align-items-center" style="margin: -5px 0 15px 0; font-size: 14px;">';
        echo '<div>';
        echo '<i class="fas fa-' . ($isFinal ? 'check-double' : 'edit') . ' mr-2"></i>';
        echo '<strong>Currently editing: ' . htmlspecialchars($roundLabel) . '</strong>';
        if (!$isFinal) {
            echo ' &mdash; <span class="text-muted">Data entered here is for ' . htmlspecialchars($roundLabel) . ' only.</span>';
        }
        echo '</div>';

        if (!$isFinal) {
            // Link to switch to the other round
            $switchUrl = $this->buildFormUrl($record, $instrument, $event_id, $otherRound);
            echo '<a href="' . htmlspecialchars($switchUrl) . '" class="btn btn-sm btn-outline-secondary">';
            echo '<i class="fas fa-exchange-alt mr-1"></i>Switch to ' . htmlspecialchars($otherLabel);
            echo '</a>';
        }
        echo '</div>';
    }

    /**
     * After save: check if both rounds are complete, notify if configured.
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $ddeInstruments = $this->getDDEInstruments();
        if (!in_array($instrument, $ddeInstruments)) return;

        // Only trigger when Round 1 or Round 2 is saved
        if (!in_array($repeat_instance, [self::ROUND_1, self::ROUND_2])) return;

        // Check if both rounds now have data
        if ($this->bothRoundsComplete($project_id, $record, $instrument, $event_id)) {
            $email = $this->getProjectSetting('notification-email');
            if (!empty($email)) {
                $this->sendBothRoundsCompleteNotification($email, $project_id, $record, $instrument);
            }
        }
    }

    /**
     * AJAX router.
     */
    function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // Write actions require data entry rights
        $writeActions = ['merge-field', 'merge-bulk'];
        if (in_array($action, $writeActions)) {
            $user = $this->framework->getUser();
            $rights = $user->getRights();
            if (!$rights || $rights['data_entry'] === null) {
                return ['error' => 'You do not have data entry rights in this project'];
            }
        }

        switch ($action) {
            case 'get-record-rounds':
                return $this->ajaxGetRecordRounds($project_id, $payload);
            case 'compare-rounds':
                return $this->ajaxCompareRounds($project_id, $payload);
            case 'merge-field':
                return $this->ajaxMergeField($project_id, $payload);
            case 'merge-bulk':
                return $this->ajaxMergeBulk($project_id, $payload);
            case 'get-dashboard-data':
                return $this->ajaxGetDashboardData($project_id, $payload);
            case 'get-dde-stats':
                return $this->ajaxGetDDEStats($project_id);
            case 'get-task-list':
                return $this->ajaxGetTaskList($project_id);
            default:
                return ['error' => 'Unknown action'];
        }
    }

    // ─── Core Logic ──────────────────────────────────────────────────

    /**
     * Get the list of instruments enabled for DDE.
     */
    public function getDDEInstruments(): array
    {
        $instruments = $this->getProjectSetting('dde-instruments') ?? [];
        return array_filter($instruments);
    }

    /**
     * Get human-readable round label.
     */
    public function getRoundLabel(int $instance): string
    {
        return match ($instance) {
            self::ROUND_1 => 'Round 1',
            self::ROUND_2 => 'Round 2',
            self::FINAL_INSTANCE => 'Final (Merged)',
            default => "Instance $instance"
        };
    }

    /**
     * Check if both rounds have data for a given record + instrument + event.
     */
    public function bothRoundsComplete(int $project_id, string $record, string $instrument, int $event_id): bool
    {
        $data = REDCap::getData([
            'project_id' => $project_id,
            'records' => [$record],
            'fields' => [REDCap::getRecordIdField()],
            'events' => [$event_id],
            'return_format' => 'json'
        ]);
        $rows = json_decode($data, true);

        $hasR1 = false;
        $hasR2 = false;
        foreach ($rows as $row) {
            $inst = $row['redcap_repeat_instance'] ?? '';
            $form = $row['redcap_repeat_instrument'] ?? '';
            if ($form === $instrument) {
                if ($inst == self::ROUND_1) $hasR1 = true;
                if ($inst == self::ROUND_2) $hasR2 = true;
            }
        }

        return $hasR1 && $hasR2;
    }

    /**
     * Compare Round 1 vs Round 2 for a record + instrument + event.
     * Returns structured comparison with discrepancies.
     */
    public function compareRounds(int $project_id, string $record, string $instrument, int $event_id): array
    {
        // Get data dictionary for this instrument
        $dd = REDCap::getDataDictionary($project_id, 'array', false, null, [$instrument]);
        $fieldNames = array_keys($dd);
        $recordIdField = REDCap::getRecordIdField();

        // Get data for both rounds
        $data = REDCap::getData([
            'project_id' => $project_id,
            'records' => [$record],
            'fields' => array_merge([$recordIdField], $fieldNames),
            'events' => [$event_id],
            'return_format' => 'json'
        ]);
        $rows = json_decode($data, true);

        $round1Data = [];
        $round2Data = [];

        foreach ($rows as $row) {
            $inst = $row['redcap_repeat_instrument'] ?? '';
            $instance = $row['redcap_repeat_instance'] ?? '';
            if ($inst !== $instrument) continue;

            if ($instance == self::ROUND_1) {
                $round1Data = $row;
            } elseif ($instance == self::ROUND_2) {
                $round2Data = $row;
            }
        }

        if (empty($round1Data) && empty($round2Data)) {
            return [
                'record' => $record,
                'instrument' => $instrument,
                'event_id' => $event_id,
                'status' => 'no_data',
                'fields' => []
            ];
        }

        if (empty($round1Data) || empty($round2Data)) {
            return [
                'record' => $record,
                'instrument' => $instrument,
                'event_id' => $event_id,
                'status' => 'incomplete',
                'missing_round' => empty($round1Data) ? 1 : 2,
                'fields' => []
            ];
        }

        // Compare field by field
        $fields = [];
        $discrepancyCount = 0;
        $totalCompared = 0;

        // Skip metadata fields and form status fields
        $skipFields = [$recordIdField, 'redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance'];
        // Also skip *_complete fields (form completion status — not real data)
        foreach (array_keys($dd) as $fn) {
            if (str_ends_with($fn, '_complete')) {
                $skipFields[] = $fn;
            }
        }

        foreach ($dd as $fieldName => $fieldMeta) {
            if (in_array($fieldName, $skipFields)) continue;

            $val1 = $round1Data[$fieldName] ?? '';
            $val2 = $round2Data[$fieldName] ?? '';
            $totalCompared++;

            $match = $this->valuesMatch($val1, $val2);
            if (!$match) $discrepancyCount++;

            $fields[] = [
                'field_name' => $fieldName,
                'field_label' => strip_tags($fieldMeta['field_label'] ?? $fieldName),
                'field_type' => $fieldMeta['field_type'] ?? 'text',
                'select_choices' => $fieldMeta['select_choices_or_calculations'] ?? '',
                'round1_value' => $val1,
                'round2_value' => $val2,
                'match' => $match
            ];
        }

        $status = $discrepancyCount === 0 ? 'concordant' : 'discrepant';

        return [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id,
            'status' => $status,
            'total_fields' => $totalCompared,
            'matching_fields' => $totalCompared - $discrepancyCount,
            'discrepancy_count' => $discrepancyCount,
            'agreement_pct' => $totalCompared > 0 ? round((($totalCompared - $discrepancyCount) / $totalCompared) * 100, 1) : 100,
            'fields' => $fields
        ];
    }

    /**
     * Merge a single field value into the target instance.
     */
    public function mergeField(int $project_id, string $record, string $instrument, int $event_id, string $fieldName, string $value, int $sourceRound, string $comment = ''): bool
    {
        $targetInstance = $this->getMergeTargetInstance();
        $recordIdField = REDCap::getRecordIdField();

        $eventName = REDCap::getEventNames(true, false, $event_id);

        $saveData = [[
            $recordIdField => $record,
            'redcap_event_name' => $eventName,
            'redcap_repeat_instrument' => $instrument,
            'redcap_repeat_instance' => $targetInstance,
            $fieldName => $value
        ]];

        $result = REDCap::saveData($project_id, 'json', json_encode($saveData), 'overwrite');

        if (!empty($result['errors'])) {
            return false;
        }

        $this->log("Merged field", [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id,
            'field' => $fieldName,
            'source_round' => $sourceRound,
            'target_instance' => $targetInstance,
            'value' => mb_substr($value, 0, 200),
            'comment' => $comment
        ]);

        return true;
    }

    /**
     * Merge all matching fields (where R1 == R2) in bulk.
     */
    public function mergeBulkMatching(int $project_id, string $record, string $instrument, int $event_id): array
    {
        $comparison = $this->compareRounds($project_id, $record, $instrument, $event_id);
        if (!in_array($comparison['status'], ['concordant', 'discrepant'])) {
            return ['merged' => 0, 'skipped' => 0, 'error' => 'Both rounds must be complete'];
        }

        $targetInstance = $this->getMergeTargetInstance();
        $recordIdField = REDCap::getRecordIdField();
        $eventName = REDCap::getEventNames(true, false, $event_id);

        $saveRows = [];
        $merged = 0;
        $skipped = 0;

        foreach ($comparison['fields'] as $field) {
            if ($field['match']) {
                // Both match — safe to auto-merge
                $saveRows[] = [
                    $recordIdField => $record,
                    'redcap_event_name' => $eventName,
                    'redcap_repeat_instrument' => $instrument,
                    'redcap_repeat_instance' => $targetInstance,
                    $field['field_name'] => $field['round1_value']
                ];
                $merged++;
            } else {
                $skipped++;
            }
        }

        if (!empty($saveRows)) {
            $result = REDCap::saveData($project_id, 'json', json_encode($saveRows), 'overwrite');
            if (!empty($result['errors'])) {
                return ['merged' => 0, 'skipped' => $skipped, 'error' => implode('; ', $result['errors'])];
            }

            $this->log("Bulk merged matching fields", [
                'record' => $record,
                'instrument' => $instrument,
                'event_id' => $event_id,
                'merged_count' => $merged,
                'skipped_count' => $skipped,
                'target_instance' => $targetInstance
            ]);
        }

        return ['merged' => $merged, 'skipped' => $skipped];
    }

    /**
     * Get dashboard data: all records and their DDE status per instrument.
     * Applies filter rules based on participant attributes.
     */
    public function getDashboardData(int $project_id, ?string $filterRecord = null): array
    {
        // Only cache when fetching full project data (no filter)
        if ($filterRecord === null && $this->dashboardCache !== null) {
            return $this->dashboardCache;
        }

        $ddeInstruments = $this->getDDEInstruments();
        if (empty($ddeInstruments)) return [];

        $recordIdField = REDCap::getRecordIdField();

        // Check if current user is in a DAG
        $user = $this->framework->getUser();
        $rights = $user->getRights();
        $dagId = $rights['group_id'] ?? null;

        // Get filter rules up front so we can combine the record ID + filter field fetch
        $filterRules = $this->getFilterRules();
        $filterFields = array_unique(array_column($filterRules, 'field'));

        // Single call: fetch record IDs and filter field values together
        $fetchFields = array_merge([$recordIdField], $filterFields);
        $params = [
            'project_id' => $project_id,
            'fields' => array_unique($fetchFields),
            'return_format' => 'json'
        ];
        if ($filterRecord) $params['records'] = [$filterRecord];
        if ($dagId) $params['groups'] = [$dagId];

        $allData = json_decode(REDCap::getData($params), true);

        // Extract records and filter data from the combined result
        $records = [];
        $filterData = [];
        foreach ($allData as $row) {
            $rid = $row[$recordIdField];
            $records[$rid] = true;
            if (!empty($filterFields)) {
                if (!isset($filterData[$rid])) $filterData[$rid] = [];
                foreach ($filterFields as $ff) {
                    if (isset($row[$ff]) && $row[$ff] !== '') {
                        $filterData[$rid][$ff] = $row[$ff];
                    }
                }
            }
        }
        $records = array_keys($records);

        // Fetch repeat instance data for DDE instruments specifically
        $ddeParams = [
            'project_id' => $project_id,
            'forms' => $ddeInstruments,
            'return_format' => 'json'
        ];
        if ($filterRecord) $ddeParams['records'] = [$filterRecord];
        $ddeData = json_decode(REDCap::getData($ddeParams), true);

        // Build event name => event_id map for resolving numeric IDs
        $eventNameToId = $this->getEventNameToIdMap();

        // Build per-record instance map: record => instrument => event_name => [instances]
        $instanceMap = [];
        foreach ($ddeData as $row) {
            $rid = $row[$recordIdField];
            $inst = $row['redcap_repeat_instrument'] ?? '';
            $instNum = $row['redcap_repeat_instance'] ?? '';
            $eventName = $row['redcap_event_name'] ?? '';
            if ($inst !== '' && in_array($inst, $ddeInstruments)) {
                $instanceMap[$rid][$inst][$eventName][] = (int)$instNum;
            }
        }

        // Build dashboard rows
        $dashboard = [];
        foreach ($records as $rid) {
            // Determine which instruments this record should see
            $visibleInstruments = $this->getVisibleInstruments($rid, $ddeInstruments, $filterRules, $filterData[$rid] ?? []);

            $instrumentStatuses = [];
            foreach ($visibleInstruments as $instName) {
                $eventInstances = $instanceMap[$rid][$instName] ?? [];

                if (empty($eventInstances)) {
                    // No data yet for this instrument — show as pending with no event
                    $instrumentStatuses[] = [
                        'instrument' => $instName,
                        'instrument_label' => $this->getInstrumentLabel($instName),
                        'event_name' => '',
                        'event_id' => 0,
                        'has_round1' => false,
                        'has_round2' => false,
                        'has_final' => false,
                        'status' => 'pending'
                    ];
                    continue;
                }

                foreach ($eventInstances as $eventName => $instances) {
                    $hasR1 = in_array(self::ROUND_1, $instances);
                    $hasR2 = in_array(self::ROUND_2, $instances);
                    $hasFinal = in_array(self::FINAL_INSTANCE, $instances);

                    $status = 'pending';
                    if ($hasFinal) {
                        $status = 'merged';
                    } elseif ($hasR1 && $hasR2) {
                        $status = 'ready_to_compare';
                    } elseif ($hasR1 || $hasR2) {
                        $status = 'partial';
                    }

                    $instrumentStatuses[] = [
                        'instrument' => $instName,
                        'instrument_label' => $this->getInstrumentLabel($instName),
                        'event_name' => $eventName,
                        'event_id' => $eventNameToId[$eventName] ?? 0,
                        'has_round1' => $hasR1,
                        'has_round2' => $hasR2,
                        'has_final' => $hasFinal,
                        'status' => $status
                    ];
                }
            }

            $dashboard[] = [
                'record' => $rid,
                'instruments' => $instrumentStatuses
            ];
        }

        if ($filterRecord === null) {
            $this->dashboardCache = $dashboard;
        }
        return $dashboard;
    }

    /**
     * Get task list — instruments needing action (Round 2 pending, or comparison needed).
     */
    public function getTaskList(int $project_id): array
    {
        $dashboard = $this->getDashboardData($project_id);
        $tasks = [];

        foreach ($dashboard as $row) {
            foreach ($row['instruments'] as $inst) {
                if ($inst['status'] === 'pending') {
                    $tasks[] = [
                        'record' => $row['record'],
                        'instrument' => $inst['instrument'],
                        'instrument_label' => $inst['instrument_label'],
                        'event_name' => $inst['event_name'] ?? '',
                        'event_id' => $inst['event_id'] ?? 0,
                        'action' => 'Enter Round 1',
                        'priority' => 'normal',
                        'round_instance' => self::ROUND_1
                    ];
                } elseif ($inst['status'] === 'partial') {
                    $round = $inst['has_round1'] ? 'Round 2' : 'Round 1';
                    $roundInstance = $inst['has_round1'] ? self::ROUND_2 : self::ROUND_1;
                    $tasks[] = [
                        'record' => $row['record'],
                        'instrument' => $inst['instrument'],
                        'instrument_label' => $inst['instrument_label'],
                        'event_name' => $inst['event_name'] ?? '',
                        'event_id' => $inst['event_id'] ?? 0,
                        'action' => "Enter $round",
                        'priority' => 'normal',
                        'round_instance' => $roundInstance
                    ];
                } elseif ($inst['status'] === 'ready_to_compare') {
                    $tasks[] = [
                        'record' => $row['record'],
                        'instrument' => $inst['instrument'],
                        'instrument_label' => $inst['instrument_label'],
                        'event_name' => $inst['event_name'] ?? '',
                        'event_id' => $inst['event_id'] ?? 0,
                        'action' => 'Compare & Merge',
                        'priority' => 'high'
                    ];
                }
            }
        }

        // Sort: high priority first
        usort($tasks, fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) - ($b['priority'] === 'high' ? 0 : 1));

        return $tasks;
    }

    // ─── Dashboard Filtering ─────────────────────────────────────────

    /**
     * Get filter rules from project settings.
     */
    private function getFilterRules(): array
    {
        $rows = $this->framework->getSubSettings('filter-rules') ?? [];

        $rules = [];
        foreach ($rows as $row) {
            $field = $row['filter-field'] ?? '';
            if ($field === '') continue;
            $rules[] = [
                'field' => $field,
                'value' => $row['filter-value'] ?? '',
                'instruments' => $row['filter-instruments'] ?? 'all',
            ];
        }
        return $rules;
    }

    /**
     * Determine which DDE instruments are visible for a given record based on filter rules.
     */
    private function getVisibleInstruments(string $record, array $allDDEInstruments, array $filterRules, array $recordData): array
    {
        if (empty($filterRules)) return $allDDEInstruments;

        $visible = [];
        $hasMatchingRule = false;

        foreach ($filterRules as $rule) {
            $fieldVal = $recordData[$rule['field']] ?? '';
            if ((string)$fieldVal === (string)$rule['value']) {
                $hasMatchingRule = true;
                $ruleInstruments = trim($rule['instruments']);
                if (strtolower($ruleInstruments) === 'all') {
                    return $allDDEInstruments;
                }
                $parsed = array_map('trim', explode(',', $ruleInstruments));
                $visible = array_merge($visible, $parsed);
            }
        }

        if (!$hasMatchingRule) {
            // No rule matched — show all by default
            return $allDDEInstruments;
        }

        // Intersect with actual DDE instruments
        return array_values(array_intersect(array_unique($visible), $allDDEInstruments));
    }

    /**
     * Inject JS to add DDE status indicators to the Record Status Dashboard.
     */
    private function injectDashboardFilter(array $ddeInstruments): void
    {
        $instrumentsJson = json_encode($ddeInstruments);
        $ddePageUrl = json_encode($this->getUrl('pages/dashboard.php'));
        echo "<script>
            $(document).ready(function() {
                var ddeInstruments = {$instrumentsJson};
                // Add a link to the DDE Dashboard from the Record Status Dashboard
                var banner = $('<div class=\"alert alert-info alert-dismissible\" style=\"margin-top:10px;\">' +
                    '<i class=\"fas fa-columns mr-2\"></i>' +
                    '<b>Double Data Entry enabled</b> for ' + ddeInstruments.length + ' instrument(s). ' +
                    '<a href=' + {$ddePageUrl} + '>Open DDE Dashboard</a>' +
                    '<button type=\"button\" class=\"close\" data-dismiss=\"alert\">&times;</button>' +
                    '</div>');
                $('#record_status_table').before(banner);
            });
        </script>";
    }

    // ─── AJAX Handlers ───────────────────────────────────────────────

    private function ajaxGetRecordRounds(int $project_id, $payload): array
    {
        $record = $payload['record'] ?? '';
        $instrument = $payload['instrument'] ?? '';
        $event_id = (int)($payload['event_id'] ?? 0);

        $data = json_decode(REDCap::getData([
            'project_id' => $project_id,
            'records' => [$record],
            'events' => $event_id ? [$event_id] : null,
            'return_format' => 'json'
        ]), true);

        $rounds = [];
        foreach ($data as $row) {
            $inst = $row['redcap_repeat_instrument'] ?? '';
            $instNum = $row['redcap_repeat_instance'] ?? '';
            if ($inst === $instrument) {
                $rounds[] = (int)$instNum;
            }
        }

        return ['record' => $record, 'instrument' => $instrument, 'rounds' => $rounds];
    }

    private function ajaxCompareRounds(int $project_id, $payload): array
    {
        return $this->compareRounds(
            $project_id,
            $payload['record'] ?? '',
            $payload['instrument'] ?? '',
            (int)($payload['event_id'] ?? 0)
        );
    }

    private function ajaxMergeField(int $project_id, $payload): array
    {
        $requireComment = $this->getProjectSetting('require-merge-comment');
        $comment = $payload['comment'] ?? '';

        if ($requireComment && empty($comment)) {
            return ['error' => 'A comment is required when resolving discrepancies'];
        }

        $success = $this->mergeField(
            $project_id,
            $payload['record'] ?? '',
            $payload['instrument'] ?? '',
            (int)($payload['event_id'] ?? 0),
            $payload['field_name'] ?? '',
            $payload['value'] ?? '',
            (int)($payload['source_round'] ?? 0),
            $comment
        );

        return ['success' => $success];
    }

    private function ajaxMergeBulk(int $project_id, $payload): array
    {
        return $this->mergeBulkMatching(
            $project_id,
            $payload['record'] ?? '',
            $payload['instrument'] ?? '',
            (int)($payload['event_id'] ?? 0)
        );
    }

    private function ajaxGetDashboardData(int $project_id, $payload): array
    {
        $filterRecord = $payload['record'] ?? null;
        return $this->getDashboardData($project_id, $filterRecord);
    }

    private function ajaxGetDDEStats(int $project_id): array
    {
        $ddeInstruments = $this->getDDEInstruments();
        $dashboard = $this->getDashboardData($project_id);

        $stats = [
            'total_records' => count($dashboard),
            'instruments' => count($ddeInstruments),
            'pending' => 0,
            'partial' => 0,
            'ready_to_compare' => 0,
            'merged' => 0,
            'total_instrument_records' => 0
        ];

        foreach ($dashboard as $row) {
            foreach ($row['instruments'] as $inst) {
                $stats['total_instrument_records']++;
                $stats[$inst['status']]++;
            }
        }

        return $stats;
    }

    private function ajaxGetTaskList(int $project_id): array
    {
        return $this->getTaskList($project_id);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Compare two field values for equality after trimming whitespace.
     */
    private function valuesMatch(string|null $v1, string|null $v2): bool
    {
        return trim((string)$v1) === trim((string)$v2);
    }

    private function getMergeTargetInstance(): int
    {
        $setting = $this->getProjectSetting('merge-target-instance');
        return $setting == '1' ? self::ROUND_1 : self::FINAL_INSTANCE;
    }

    private function isRecordStatusDashboard(): bool
    {
        return defined('PAGE') && (
            PAGE === 'DataEntry/record_status_dashboard.php'
            || (isset($_GET['route']) && $_GET['route'] === 'DataEntryController:recordStatusDashboard')
        );
    }

    private function buildFormUrl(string $record, string $instrument, int $event_id, int $instance): string
    {
        $pid = $this->framework->getProjectId();
        return APP_PATH_WEBROOT . "DataEntry/index.php?pid={$pid}&page=" . urlencode($instrument) . "&id=" . urlencode($record) . "&event_id={$event_id}&instance={$instance}";
    }

    public function getFirstEventId(): int
    {
        $pid = $this->framework->getProjectId();
        $sql = "SELECT em.event_id FROM redcap_events_metadata em JOIN redcap_events_arms ea ON em.arm_id = ea.arm_id WHERE ea.project_id = ? ORDER BY em.event_id LIMIT 1";
        $result = $this->query($sql, [$pid]);
        $row = $result->fetch_assoc();
        return (int)($row["event_id"] ?? 0);
    }

    /**
     * Build a map of unique event name => numeric event_id for this project.
     * REDCap::getEventNames(true) returns event_id => unique_event_name; we flip it.
     */
    private function getEventNameToIdMap(): array
    {
        $map = [];
        $names = REDCap::getEventNames(true);
        if (is_array($names)) {
            foreach ($names as $eventId => $uniqueName) {
                $map[(string)$uniqueName] = (int)$eventId;
            }
        }
        return $map;
    }

    private function getInstrumentLabel(string $formName): string
    {
        global $Proj;
        return $Proj?->forms[$formName]['menu'] ?? $formName;
    }

    private function sendBothRoundsCompleteNotification(string $email, int $project_id, string $record, string $instrument): void
    {
        $label = $this->getInstrumentLabel($instrument);
        $safeRecord = htmlspecialchars($record, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $subject = "DDE Ready for Review — Record $safeRecord ($safeLabel)";
        $body = "Both Round 1 and Round 2 are complete for:<br><br>";
        $body .= "<b>Record:</b> $safeRecord<br>";
        $body .= "<b>Instrument:</b> $safeLabel<br><br>";
        $body .= "Please open the DDE Comparison & Merge page to review and adjudicate.";

        $fromEmail = $GLOBALS['homepage_contact_email'] ?? 'noreply@redcap.local';
        REDCap::email($email, $fromEmail, $subject, $body);
    }
}
