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
    private ?array $userRightsCache = null;

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

        // A DDE instrument that is not repeating on this event is a configuration
        // error — instances 1/2/3 cannot exist, so warn instead of claiming "Round 1"
        global $Proj;
        if ($Proj && method_exists($Proj, 'isRepeatingForm') && !$Proj->isRepeatingForm($event_id, $instrument)) {
            echo '<div class="alert alert-warning" style="margin: -5px 0 15px 0; font-size: 14px;">';
            echo '<i class="fas fa-exclamation-triangle mr-2"></i>';
            echo '<strong>Easy Double Entry configuration issue:</strong> this instrument is selected for double data entry ';
            echo 'but is not set up as a repeating instrument on this event. ';
            echo 'Enable repeating for it under <b>Project Setup &rarr; Repeating Instruments</b>.';
            echo '</div>';
            return;
        }

        $repeat_instance = $this->normalizeRoundInstance($repeat_instance);

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

        $repeat_instance = $this->normalizeRoundInstance($repeat_instance);

        // Only trigger when Round 1 or Round 2 is saved
        if (!in_array($repeat_instance, [self::ROUND_1, self::ROUND_2])) return;

        // Check if both rounds now have data
        if ($this->bothRoundsComplete($project_id, $record, $instrument, $event_id)) {
            $email = $this->getProjectSetting('notification-email');
            if (!empty($email) && !$this->notificationAlreadySent($project_id, $record, $instrument, $event_id)) {
                $this->sendBothRoundsCompleteNotification($email, $project_id, $record, $instrument);
                $this->log('DDE notification sent', [
                    'record' => $record,
                    'instrument' => $instrument,
                    'event_id' => $event_id
                ]);
            }
        }
    }

    /**
     * AJAX router.
     */
    function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        $instrumentActions = ['get-record-rounds', 'compare-rounds', 'merge-field', 'finalize-merge'];
        $writeActions = ['merge-field', 'finalize-merge'];
        $recordActions = ['get-record-rounds', 'compare-rounds', 'merge-field', 'finalize-merge'];

        if (in_array($action, $instrumentActions, true)) {
            $payloadInstrument = trim((string)($payload['instrument'] ?? ''));
            if ($payloadInstrument === '' || !$this->isConfiguredDDEInstrument($payloadInstrument)) {
                return ['error' => 'This instrument is not configured for Easy Double Entry in this project'];
            }
            // All instrument-scoped actions require at least read rights on the form
            if (!$this->userCanReadInstrument($user_id, $payloadInstrument)) {
                return ['error' => 'You do not have read access to this instrument'];
            }
            if (in_array($action, $writeActions, true) && !$this->userCanEditInstrument($user_id, $payloadInstrument)) {
                return ['error' => 'You do not have edit rights on this instrument'];
            }
        }

        // Record-scoped actions: record must exist (prevents record creation via
        // saveData) and must be in the user's DAG when the user is DAG-restricted
        if (in_array($action, $recordActions, true)) {
            $payloadRecord = trim((string)($payload['record'] ?? ''));
            $accessError = $this->checkRecordAccess($user_id, $project_id, $payloadRecord);
            if ($accessError !== null) {
                return ['error' => $accessError];
            }
        }

        switch ($action) {
            case 'get-record-rounds':
                return $this->ajaxGetRecordRounds($project_id, $payload);
            case 'compare-rounds':
                return $this->ajaxCompareRounds($project_id, $payload);
            case 'merge-field':
                return $this->ajaxMergeField($project_id, $payload);
            case 'finalize-merge':
                return $this->ajaxFinalizeMerge($project_id, $payload);
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

    // ─── Configuration ───────────────────────────────────────────────

    /**
     * Get the list of instruments enabled for DDE.
     */
    public function getDDEInstruments(): array
    {
        // New sub_settings structure (must use getSubSettings for EM framework v14)
        $configs = $this->framework->getSubSettings('dde-instrument-config') ?? [];
        if (!empty($configs)) {
            $instruments = [];
            foreach ($configs as $cfg) {
                $inst = $cfg['dde-instrument'] ?? '';
                if ($inst !== '') $instruments[] = $inst;
            }
            return $instruments;
        }

        // Backwards compatibility with old flat repeatable setting
        $instruments = $this->getProjectSetting('dde-instruments') ?? [];
        return array_filter($instruments);
    }

    /**
     * Get the list of field variable names to exclude from DDE comparison
     * for a specific instrument.
     */
    public function getExcludedFields(string $instrument): array
    {
        $configs = $this->framework->getSubSettings('dde-instrument-config') ?? [];
        foreach ($configs as $cfg) {
            if (($cfg['dde-instrument'] ?? '') === $instrument) {
                $raw = trim($cfg['dde-exclude-fields'] ?? '');
                if ($raw === '') return [];
                return array_map('trim', preg_split('/[\s,]+/', $raw));
            }
        }
        return [];
    }

    private function isConfiguredDDEInstrument(string $instrument): bool
    {
        return in_array($instrument, $this->getDDEInstruments(), true);
    }

    private function getMergeTargetInstance(): int
    {
        $setting = $this->getProjectSetting('merge-target-instance');
        return $setting == '1' ? self::ROUND_1 : self::FINAL_INSTANCE;
    }

    /**
     * Whether excluded (not-double-entered) fields should be copied from
     * Round 1 into the final entry on finalize. Default: copy, so the final
     * instance is a complete record.
     */
    private function shouldCopyExcludedOnFinalize(): bool
    {
        return $this->getProjectSetting('copy-excluded-on-finalize') !== 'skip';
    }

    // ─── Permissions ─────────────────────────────────────────────────

    private function getUserRightsCached(string $user_id): array
    {
        if ($this->userRightsCache === null) {
            $rights = REDCap::getUserRights($user_id);
            $this->userRightsCache = $rights[$user_id] ?? [];
        }
        return $this->userRightsCache;
    }

    /**
     * Whether the user has at least read access to a form.
     */
    public function userCanReadInstrument(string $user_id, string $instrument): bool
    {
        $rights = $this->getUserRightsCached($user_id);
        if (empty($rights)) return false;
        return $this->getFormRightsLevel($rights, $instrument) !== '0';
    }

    /**
     * Whether the user has edit access to a form (View & Edit, or Edit survey responses).
     */
    public function userCanEditInstrument(string $user_id, string $instrument): bool
    {
        $rights = $this->getUserRightsCached($user_id);
        if (empty($rights)) return false;
        return in_array($this->getFormRightsLevel($rights, $instrument), ['1', '3'], true);
    }

    /**
     * Verify a record exists and is accessible to the user (DAG-aware).
     * Returns an error message, or null if access is allowed.
     */
    private function checkRecordAccess(string $user_id, int $project_id, string $record): ?string
    {
        if ($record === '') return 'Record not found';

        $recordIdField = REDCap::getRecordIdField();
        $params = [
            'project_id' => $project_id,
            'records' => [$record],
            'fields' => [$recordIdField],
            'return_format' => 'json'
        ];
        $rows = json_decode(REDCap::getData($params), true);
        if (empty($rows)) return 'Record not found';

        $rights = $this->getUserRightsCached($user_id);
        $dagId = $rights['group_id'] ?? null;
        if ($dagId) {
            $params['groups'] = [$dagId];
            $rows = json_decode(REDCap::getData($params), true);
            if (empty($rows)) return 'This record belongs to a different Data Access Group';
        }

        return null;
    }

    /**
     * Extract per-form rights level from a user rights array.
     *
     * REDCap::getUserRights() returns 'forms' as an associative array,
     * but the EM framework's $user->getRights() may return it as a
     * comma-separated string like "form1:1,form2:3". Handle both.
     */
    private function getFormRightsLevel(array $rights, string $formName): string
    {
        $forms = $rights['forms'] ?? '';

        if (is_array($forms)) {
            return (string)($forms[$formName] ?? '0');
        }

        if (is_string($forms) && $forms !== '') {
            foreach (explode(',', $forms) as $pair) {
                $parts = explode(':', $pair, 2);
                if (count($parts) === 2 && trim($parts[0]) === $formName) {
                    return trim($parts[1]);
                }
            }
        }

        return '0';
    }

    // ─── Core Logic ──────────────────────────────────────────────────

    private function normalizeRoundInstance($instance): int
    {
        if ($instance === null || $instance === '') {
            return self::ROUND_1;
        }

        return (int)$instance;
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
            'forms' => [$instrument],
            'fields' => [REDCap::getRecordIdField(), $instrument . '_complete'],
            'events' => [$event_id],
            'return_format' => 'json'
        ]);
        $rows = json_decode($data, true);

        $hasR1 = false;
        $hasR2 = false;
        foreach ($rows as $row) {
            $inst = $this->normalizeRoundInstance($row['redcap_repeat_instance'] ?? null);
            $form = $row['redcap_repeat_instrument'] ?? '';
            if ($form === $instrument) {
                if ($inst == self::ROUND_1) $hasR1 = true;
                if ($inst == self::ROUND_2) $hasR2 = true;
            }
        }

        return $hasR1 && $hasR2;
    }

    /**
     * Fetch the Round 1 and Round 2 data rows for a record + instrument + event.
     * Returns [round1Row, round2Row]; either may be an empty array.
     */
    private function getRoundRows(int $project_id, string $record, string $instrument, int $event_id, array $fieldNames): array
    {
        $recordIdField = REDCap::getRecordIdField();
        $params = [
            'project_id' => $project_id,
            'records' => [$record],
            'fields' => array_merge([$recordIdField], $fieldNames),
            'return_format' => 'json'
        ];
        if ($event_id) $params['events'] = [$event_id];

        $rows = json_decode(REDCap::getData($params), true) ?: [];

        $round1 = [];
        $round2 = [];
        foreach ($rows as $row) {
            if (($row['redcap_repeat_instrument'] ?? '') !== $instrument) continue;
            $instance = $this->normalizeRoundInstance($row['redcap_repeat_instance'] ?? null);
            if ($instance === self::ROUND_1) {
                $round1 = $row;
            } elseif ($instance === self::ROUND_2) {
                $round2 = $row;
            }
        }
        return [$round1, $round2];
    }

    /**
     * Map checkbox choice codes to their flat-export column names.
     * REDCap export convention: fieldname___code with the code lowercased,
     * '-' converted to '_', and any other non-alphanumerics removed.
     */
    private function getCheckboxExportColumns(string $fieldName, string $choicesRaw): array
    {
        $columns = [];
        foreach (explode('|', $choicesRaw) as $choice) {
            $parts = explode(',', $choice, 2);
            $code = trim($parts[0]);
            if ($code === '') continue;
            $sanitized = strtolower($code);
            $sanitized = str_replace('-', '_', $sanitized);
            $sanitized = preg_replace('/[^a-z0-9_]/', '', $sanitized);
            $columns[$code] = $fieldName . '___' . $sanitized;
        }
        return $columns;
    }

    /**
     * Whether a field's value can be written to the merge target via saveData.
     * Returns [writable(bool), reason(string)].
     */
    private function getFieldWritability(array $fieldMeta): array
    {
        $type = $fieldMeta['field_type'] ?? 'text';
        if ($type === 'calc') {
            return [false, 'Calculated field — REDCap recalculates it automatically'];
        }
        if ($type === 'file') {
            // Covers both file uploads and signature fields
            return [false, 'File/signature field — cannot be copied by merge'];
        }
        if (str_contains($fieldMeta['field_annotation'] ?? '', '@CALCTEXT')) {
            return [false, 'Calculated text (@CALCTEXT) — REDCap recalculates it automatically'];
        }
        return [true, ''];
    }

    /**
     * Compare Round 1 vs Round 2 for a record + instrument + event.
     * Returns structured comparison with discrepancies, resolution state,
     * and finalization state.
     */
    public function compareRounds(int $project_id, string $record, string $instrument, int $event_id): array
    {
        $dd = REDCap::getDataDictionary($project_id, 'array', false, null, [$instrument]);
        $recordIdField = REDCap::getRecordIdField();

        [$round1Data, $round2Data] = $this->getRoundRows($project_id, $record, $instrument, $event_id, array_keys($dd));

        $base = [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id,
            'fields' => [],
            'resolved_fields' => [],
            'finalized' => false,
            'unresolved_count' => 0
        ];

        if (empty($round1Data) && empty($round2Data)) {
            return array_merge($base, ['status' => 'no_data']);
        }

        if (empty($round1Data) || empty($round2Data)) {
            return array_merge($base, [
                'status' => 'incomplete',
                'missing_round' => empty($round1Data) ? 1 : 2
            ]);
        }

        // Skip metadata fields, form status fields, and per-instrument excluded fields
        $skipFields = [$recordIdField, 'redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance'];
        $skipFields = array_merge($skipFields, $this->getExcludedFields($instrument));
        // Also skip *_complete fields (form completion status — not real data)
        foreach (array_keys($dd) as $fn) {
            if (str_ends_with($fn, '_complete')) {
                $skipFields[] = $fn;
            }
        }

        $fields = [];
        $discrepancyCount = 0;
        $totalCompared = 0;

        foreach ($dd as $fieldName => $fieldMeta) {
            $type = $fieldMeta['field_type'] ?? 'text';
            if ($type === 'descriptive') continue;
            if (in_array($fieldName, $skipFields)) continue;

            $isCheckbox = $type === 'checkbox';

            if ($isCheckbox) {
                // Checkbox data lives in field___code export columns, never under
                // the base field name — compare each choice column
                $columns = $this->getCheckboxExportColumns($fieldName, $fieldMeta['select_choices_or_calculations'] ?? '');
                $checked1 = [];
                $checked2 = [];
                $match = true;
                foreach ($columns as $code => $col) {
                    $c1 = ($round1Data[$col] ?? '') === '1';
                    $c2 = ($round2Data[$col] ?? '') === '1';
                    if ($c1) $checked1[] = $code;
                    if ($c2) $checked2[] = $code;
                    if ($c1 !== $c2) $match = false;
                }
                $val1 = implode(', ', $checked1);
                $val2 = implode(', ', $checked2);
            } else {
                $val1 = $round1Data[$fieldName] ?? '';
                $val2 = $round2Data[$fieldName] ?? '';
                $match = $this->valuesMatch($val1, $val2);
            }

            $totalCompared++;
            if (!$match) $discrepancyCount++;

            [$writable, $skipReason] = $this->getFieldWritability($fieldMeta);

            $fields[] = [
                'field_name' => $fieldName,
                'field_label' => strip_tags($fieldMeta['field_label'] ?? $fieldName),
                'field_type' => $type,
                'select_choices' => $fieldMeta['select_choices_or_calculations'] ?? '',
                'is_checkbox' => $isCheckbox,
                'merge_writable' => $writable,
                'merge_skip_reason' => $skipReason,
                'round1_value' => $val1,
                'round2_value' => $val2,
                'match' => $match
            ];
        }

        $status = $discrepancyCount === 0 ? 'concordant' : 'discrepant';

        // Resolution state from the module audit log (survives page reloads)
        $resolved = $this->getResolvedFields($project_id, $record, $instrument, $event_id);
        $resolvedNames = array_keys($resolved);
        $unresolvedCount = 0;
        foreach ($fields as $f) {
            if (!$f['match'] && $f['merge_writable'] && !in_array($f['field_name'], $resolvedNames, true)) {
                $unresolvedCount++;
            }
        }

        return array_merge($base, [
            'status' => $status,
            'total_fields' => $totalCompared,
            'matching_fields' => $totalCompared - $discrepancyCount,
            'discrepancy_count' => $discrepancyCount,
            'agreement_pct' => $totalCompared > 0 ? round((($totalCompared - $discrepancyCount) / $totalCompared) * 100, 1) : 100,
            'fields' => $fields,
            'resolved_fields' => $resolvedNames,
            'finalized' => $this->isFinalized($project_id, $record, $instrument, $event_id),
            'unresolved_count' => $unresolvedCount
        ]);
    }

    /**
     * Fields already resolved for this record/instrument/event, read from the
     * module log (source of truth for adjudication state).
     * Returns field_name => ['source_round' => ..., 'timestamp' => ...].
     */
    public function getResolvedFields(int $project_id, string $record, string $instrument, int $event_id): array
    {
        $result = $this->queryLogs(
            "SELECT timestamp, field, source_round WHERE message = ? AND project_id = ? AND record = ? AND instrument = ? AND event_id = ? ORDER BY timestamp",
            ['Merged field', $project_id, $record, $instrument, $event_id]
        );

        $resolved = [];
        while ($row = $result->fetch_assoc()) {
            $field = $row['field'] ?? '';
            if ($field === '') continue;
            // Latest resolution wins
            $resolved[$field] = [
                'source_round' => $row['source_round'] ?? '',
                'timestamp' => $row['timestamp'] ?? ''
            ];
        }
        return $resolved;
    }

    /**
     * Whether the merge has been finalized for this record/instrument/event.
     */
    public function isFinalized(int $project_id, string $record, string $instrument, int $event_id): bool
    {
        $result = $this->queryLogs(
            "SELECT timestamp WHERE message = ? AND project_id = ? AND record = ? AND instrument = ? AND event_id = ?",
            ['Finalized merge', $project_id, $record, $instrument, $event_id]
        );
        return (bool)$result->fetch_assoc();
    }

    /**
     * Map of finalized merges for the whole project, keyed "record|instrument|event_id".
     */
    private function getFinalizedMap(int $project_id): array
    {
        $result = $this->queryLogs(
            "SELECT record, instrument, event_id WHERE message = ? AND project_id = ?",
            ['Finalized merge', $project_id]
        );
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[($row['record'] ?? '') . '|' . ($row['instrument'] ?? '') . '|' . ($row['event_id'] ?? '')] = true;
        }
        return $map;
    }

    /**
     * Merge a single field value into the target instance (discrepancy resolution).
     * Returns ['success' => bool, 'error' => ?, 'unresolved_count' => int].
     */
    public function mergeField(int $project_id, string $record, string $instrument, int $event_id, string $fieldName, string $value, int $sourceRound, string $comment = ''): array
    {
        $dd = REDCap::getDataDictionary($project_id, 'array', false, null, [$instrument]);
        if (!array_key_exists($fieldName, $dd)) {
            return ['success' => false, 'error' => 'Field does not belong to this instrument'];
        }

        $recordIdField = REDCap::getRecordIdField();
        if ($fieldName === $recordIdField || str_ends_with($fieldName, '_complete')) {
            return ['success' => false, 'error' => 'This field cannot be merged'];
        }
        if (in_array($fieldName, $this->getExcludedFields($instrument), true)) {
            return ['success' => false, 'error' => 'This field is excluded from comparison'];
        }

        $fieldMeta = $dd[$fieldName];
        [$writable, $skipReason] = $this->getFieldWritability($fieldMeta);
        if (!$writable) {
            return ['success' => false, 'error' => $skipReason];
        }

        $targetInstance = $this->getMergeTargetInstance();

        $saveRow = [$recordIdField => $record];
        $eventName = $this->buildEventNameForSave($event_id);
        if ($eventName !== null) $saveRow['redcap_event_name'] = $eventName;
        $saveRow['redcap_repeat_instrument'] = $instrument;
        $saveRow['redcap_repeat_instance'] = $targetInstance;

        if (($fieldMeta['field_type'] ?? '') === 'checkbox') {
            // Checkboxes are written per-choice column, copied server-side from
            // the chosen round (a client-supplied scalar cannot represent them)
            if (!in_array($sourceRound, [self::ROUND_1, self::ROUND_2], true)) {
                return ['success' => false, 'error' => 'Checkbox fields must be resolved by choosing Round 1 or Round 2'];
            }
            [$round1Data, $round2Data] = $this->getRoundRows($project_id, $record, $instrument, $event_id, [$fieldName]);
            $sourceRow = $sourceRound === self::ROUND_1 ? $round1Data : $round2Data;
            if (empty($sourceRow)) {
                return ['success' => false, 'error' => 'Source round has no data for this record'];
            }
            $checked = [];
            $columns = $this->getCheckboxExportColumns($fieldName, $fieldMeta['select_choices_or_calculations'] ?? '');
            foreach ($columns as $code => $col) {
                $isChecked = ($sourceRow[$col] ?? '') === '1';
                $saveRow[$col] = $isChecked ? '1' : '0';
                if ($isChecked) $checked[] = $code;
            }
            $loggedValue = implode(', ', $checked);
        } else {
            $saveRow[$fieldName] = $value;
            $loggedValue = mb_substr($value, 0, 200);
        }

        $result = REDCap::saveData($project_id, 'json', json_encode([$saveRow]), 'overwrite');

        if (!empty($result['errors'])) {
            $errors = $result['errors'];
            $errorMsg = is_array($errors) ? implode('; ', array_map('strval', $errors)) : (string)$errors;
            return ['success' => false, 'error' => $errorMsg];
        }

        $this->log("Merged field", [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id,
            'field' => $fieldName,
            'source_round' => $sourceRound,
            'target_instance' => $targetInstance,
            'value' => $loggedValue,
            'comment' => $comment
        ]);

        $comparison = $this->compareRounds($project_id, $record, $instrument, $event_id);

        return [
            'success' => true,
            'unresolved_count' => $comparison['unresolved_count'],
            'finalized' => $comparison['finalized']
        ];
    }

    /**
     * Finalize the merge: verify every discrepancy is resolved, then copy all
     * matching fields (and optionally excluded fields) into the target instance
     * and mark the form Complete, so the merged instance is a full entry.
     */
    public function finalizeMerge(int $project_id, string $record, string $instrument, int $event_id): array
    {
        $comparison = $this->compareRounds($project_id, $record, $instrument, $event_id);
        if (!in_array($comparison['status'], ['concordant', 'discrepant'], true)) {
            return ['success' => false, 'error' => 'Both rounds must be complete before finalizing'];
        }

        // Server-side verification — never trust client state
        $resolved = $this->getResolvedFields($project_id, $record, $instrument, $event_id);
        $unresolvedFields = [];
        foreach ($comparison['fields'] as $f) {
            if (!$f['match'] && $f['merge_writable'] && !isset($resolved[$f['field_name']])) {
                $unresolvedFields[] = $f['field_name'];
            }
        }
        if (!empty($unresolvedFields)) {
            return [
                'success' => false,
                'error' => 'All discrepancies must be resolved before finalizing',
                'unresolved' => $unresolvedFields
            ];
        }

        $targetInstance = $this->getMergeTargetInstance();
        $inPlace = $targetInstance === self::ROUND_1;
        $dd = REDCap::getDataDictionary($project_id, 'array', false, null, [$instrument]);
        $recordIdField = REDCap::getRecordIdField();

        $saveRow = [$recordIdField => $record];
        $eventName = $this->buildEventNameForSave($event_id);
        if ($eventName !== null) $saveRow['redcap_event_name'] = $eventName;
        $saveRow['redcap_repeat_instrument'] = $instrument;
        $saveRow['redcap_repeat_instance'] = $targetInstance;

        $copiedMatching = 0;
        $copiedExcluded = 0;
        $skippedFields = [];

        // In merge-to-Instance-1 mode the matching and excluded values are already
        // in place; writing them again would only pollute the data audit log
        if (!$inPlace) {
            [$round1Data, ] = $this->getRoundRows($project_id, $record, $instrument, $event_id, array_keys($dd));

            foreach ($comparison['fields'] as $f) {
                if (!$f['match']) continue; // discrepancies were written via mergeField
                if (!$f['merge_writable']) {
                    $skippedFields[] = ['field' => $f['field_name'], 'reason' => $f['merge_skip_reason']];
                    continue;
                }
                if ($f['is_checkbox']) {
                    $columns = $this->getCheckboxExportColumns($f['field_name'], $dd[$f['field_name']]['select_choices_or_calculations'] ?? '');
                    foreach ($columns as $col) {
                        $saveRow[$col] = ($round1Data[$col] ?? '') === '1' ? '1' : '0';
                    }
                } else {
                    $saveRow[$f['field_name']] = $f['round1_value'];
                }
                $copiedMatching++;
            }

            if ($this->shouldCopyExcludedOnFinalize()) {
                foreach ($this->getExcludedFields($instrument) as $ef) {
                    if (!isset($dd[$ef])) continue;
                    $meta = $dd[$ef];
                    $type = $meta['field_type'] ?? 'text';
                    if ($type === 'descriptive') continue;
                    [$writable, ] = $this->getFieldWritability($meta);
                    if (!$writable) continue;
                    if ($type === 'checkbox') {
                        foreach ($this->getCheckboxExportColumns($ef, $meta['select_choices_or_calculations'] ?? '') as $col) {
                            $saveRow[$col] = ($round1Data[$col] ?? '') === '1' ? '1' : '0';
                        }
                    } else {
                        $saveRow[$ef] = (string)($round1Data[$ef] ?? '');
                    }
                    $copiedExcluded++;
                }
            }
        }

        // The finalized entry is by definition Complete
        $saveRow[$instrument . '_complete'] = '2';

        $result = REDCap::saveData($project_id, 'json', json_encode([$saveRow]), 'overwrite');
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
            $errorMsg = is_array($errors) ? implode('; ', array_map('strval', $errors)) : (string)$errors;
            return ['success' => false, 'error' => $errorMsg];
        }

        $this->log('Finalized merge', [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id,
            'target_instance' => $targetInstance,
            'copied_matching' => $copiedMatching,
            'copied_excluded' => $copiedExcluded,
            'mode' => $inPlace ? 'in_place' : 'copy'
        ]);

        $this->dashboardCache = null;

        return [
            'success' => true,
            'mode' => $inPlace ? 'in_place' : 'copy',
            'copied_matching' => $copiedMatching,
            'copied_excluded' => $copiedExcluded,
            'skipped_fields' => $skippedFields
        ];
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
        if ($dagId) $ddeParams['groups'] = [$dagId];
        $ddeData = json_decode(REDCap::getData($ddeParams), true);

        // Build event name => event_id map for resolving numeric IDs
        $eventNameToId = $this->getEventNameToIdMap();
        $fallbackEventId = $this->getFirstEventId();

        // Per-record instance map: record => instrument => event_name =>
        // [instance => form_complete value]
        $instanceMap = [];
        foreach ($ddeData as $row) {
            $rid = $row[$recordIdField];
            $inst = $row['redcap_repeat_instrument'] ?? '';
            $instNum = $this->normalizeRoundInstance($row['redcap_repeat_instance'] ?? null);
            $eventName = $row['redcap_event_name'] ?? '';
            if ($inst !== '' && in_array($inst, $ddeInstruments)) {
                $instanceMap[$rid][$inst][$eventName][$instNum] = (string)($row[$inst . '_complete'] ?? '');
            }
        }

        $targetInstance = $this->getMergeTargetInstance();
        $finalizedMap = $this->getFinalizedMap($project_id);

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
                    $hasR1 = array_key_exists(self::ROUND_1, $instances);
                    $hasR2 = array_key_exists(self::ROUND_2, $instances);
                    $hasFinal = array_key_exists(self::FINAL_INSTANCE, $instances);
                    $eventId = $eventNameToId[$eventName] ?? $fallbackEventId;
                    $finalizedLogged = isset($finalizedMap[$rid . '|' . $instName . '|' . $eventId]);

                    if ($targetInstance === self::FINAL_INSTANCE) {
                        // Instance 3 marked Complete (or a finalize log entry) means done;
                        // an Instance 3 without either is a merge still in progress
                        if ($hasFinal && ($finalizedLogged || ($instances[self::FINAL_INSTANCE] ?? '') === '2')) {
                            $status = 'merged';
                        } elseif ($hasFinal) {
                            $status = 'merge_in_progress';
                        } elseif ($hasR1 && $hasR2) {
                            $status = 'ready_to_compare';
                        } elseif ($hasR1 || $hasR2) {
                            $status = 'partial';
                        } else {
                            $status = 'pending';
                        }
                    } else {
                        // Merge-to-Instance-1 mode: only the finalize log can signal completion
                        if ($finalizedLogged) {
                            $status = 'merged';
                        } elseif ($hasR1 && $hasR2) {
                            $status = 'ready_to_compare';
                        } elseif ($hasR1 || $hasR2) {
                            $status = 'partial';
                        } else {
                            $status = 'pending';
                        }
                    }

                    $instrumentStatuses[] = [
                        'instrument' => $instName,
                        'instrument_label' => $this->getInstrumentLabel($instName),
                        'event_name' => $eventName,
                        'event_id' => $eventId,
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
     * Get task list — instruments needing action (entry, comparison, or finalization).
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
                } elseif ($inst['status'] === 'merge_in_progress') {
                    $tasks[] = [
                        'record' => $row['record'],
                        'instrument' => $inst['instrument'],
                        'instrument_label' => $inst['instrument_label'],
                        'event_name' => $inst['event_name'] ?? '',
                        'event_id' => $inst['event_id'] ?? 0,
                        'action' => 'Finish Merge',
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
            $instNum = $this->normalizeRoundInstance($row['redcap_repeat_instance'] ?? null);
            if ($inst === $instrument) {
                $rounds[] = $instNum;
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

        return $this->mergeField(
            $project_id,
            $payload['record'] ?? '',
            $payload['instrument'] ?? '',
            (int)($payload['event_id'] ?? 0),
            $payload['field_name'] ?? '',
            $payload['value'] ?? '',
            (int)($payload['source_round'] ?? 0),
            $comment
        );
    }

    private function ajaxFinalizeMerge(int $project_id, $payload): array
    {
        return $this->finalizeMerge(
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
            'merge_in_progress' => 0,
            'merged' => 0,
            'total_instrument_records' => 0
        ];

        foreach ($dashboard as $row) {
            foreach ($row['instruments'] as $inst) {
                $stats['total_instrument_records']++;
                $stats[$inst['status']] = ($stats[$inst['status']] ?? 0) + 1;
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

    /**
     * Unique event name for saveData payloads, or null when the key must be
     * omitted (classic projects — getEventNames returns false there).
     */
    private function buildEventNameForSave(int $event_id): ?string
    {
        if (!REDCap::isLongitudinal()) return null;
        $name = REDCap::getEventNames(true, false, $event_id);
        return (is_string($name) && $name !== '') ? $name : null;
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

    private function notificationAlreadySent(int $project_id, string $record, string $instrument, int $event_id): bool
    {
        $result = $this->queryLogs(
            "SELECT timestamp WHERE message = ? AND project_id = ? AND record = ? AND instrument = ? AND event_id = ?",
            ['DDE notification sent', $project_id, $record, $instrument, $event_id]
        );
        return (bool)$result->fetch_assoc();
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
