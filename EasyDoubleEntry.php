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
    private array $userRightsCache = [];
    private array $dictionaryCache = [];
    private array $excludedFieldsCache = [];

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

        $email = $this->getProjectSetting('notification-email');
        if (empty($email)) return;
        if (!$this->bothRoundsComplete($project_id, $record, $instrument, $event_id)) return;

        // One email when both rounds first become complete. After a finalize,
        // one more only if a round edit actually invalidates the finalized
        // merge — routine re-saves and excluded-field fixes stay quiet.
        $lastNotice = $this->latestLogId('DDE notification sent', $project_id, $record, $instrument, $event_id);
        $needsReReview = false;
        if ($lastNotice !== null) {
            $lastFinalize = $this->latestLogId('Finalized merge', $project_id, $record, $instrument, $event_id);
            if ($lastFinalize === null || $lastFinalize < $lastNotice) return;
            $comparison = $this->compareRounds($project_id, $record, $instrument, $event_id);
            if (($comparison['unresolved_count'] ?? 0) === 0) return;
            $needsReReview = true;
        }

        $this->sendBothRoundsCompleteNotification($email, $project_id, $record, $instrument, $needsReReview);
        $this->log('DDE notification sent', [
            'record' => $record,
            'instrument' => $instrument,
            'event_id' => $event_id
        ]);
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
            // Handlers must act on the exact instrument name that was verified
            $payload['instrument'] = $payloadInstrument;
            // All instrument-scoped actions require at least read rights on the form
            if (!$this->userCanReadInstrument($user_id, $payloadInstrument)) {
                return ['error' => 'You do not have read access to this instrument'];
            }
            if (in_array($action, $writeActions, true) && !$this->userCanEditInstrument($user_id, $payloadInstrument)) {
                return ['error' => 'You do not have edit rights on this instrument'];
            }
        }

        // Record-scoped actions: record must exist (prevents record creation via
        // saveData) and must be in the user's DAG when the user is DAG-restricted.
        // The trimmed record is written back so handlers act on the exact record
        // that was verified.
        if (in_array($action, $recordActions, true)) {
            $payload['record'] = trim((string)($payload['record'] ?? ''));
            $accessError = $this->checkRecordAccess($user_id, $project_id, $payload['record']);
            if ($accessError !== null) {
                return ['error' => $accessError];
            }

            // Longitudinal projects: a resolvable event is required, otherwise
            // reads would pool rows across events and writes could land in the
            // wrong event
            $payloadEventId = (int)($payload['event_id'] ?? 0);
            if (REDCap::isLongitudinal() && !$this->isValidEventId($payloadEventId)) {
                return ['error' => 'A valid event is required for this action'];
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
        return $this->excludedFieldsCache[$instrument] ??= $this->readExcludedFields($instrument);
    }

    private function readExcludedFields(string $instrument): array
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
        if (!isset($this->userRightsCache[$user_id])) {
            $rights = REDCap::getUserRights($user_id);
            $this->userRightsCache[$user_id] = $rights[$user_id] ?? [];
        }
        return $this->userRightsCache[$user_id];
    }

    /**
     * REDCap administrators may access a project without an explicit
     * user-rights row; they must not be locked out of the module.
     */
    private function isSuperUser(string $user_id): bool
    {
        try {
            $user = $this->framework->getUser($user_id);
            return $user !== null && method_exists($user, 'isSuperUser') && $user->isSuperUser();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the user has at least read access to a form.
     */
    public function userCanReadInstrument(string $user_id, string $instrument): bool
    {
        if ($user_id === '') return false;
        if ($this->isSuperUser($user_id)) return true;
        $rights = $this->getUserRightsCached($user_id);
        if (empty($rights)) return false;
        return $this->getFormRightsLevel($rights, $instrument) !== '0';
    }

    /**
     * Whether the user has edit access to a form (View & Edit, or Edit survey responses).
     */
    public function userCanEditInstrument(string $user_id, string $instrument): bool
    {
        if ($user_id === '') return false;
        if ($this->isSuperUser($user_id)) return true;
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

        // DAG-restricted users only ever see records in their own group, so a
        // single group-filtered fetch covers both existence and membership
        $dagId = null;
        if (!$this->isSuperUser($user_id)) {
            $rights = $this->getUserRightsCached($user_id);
            $dagId = $rights['group_id'] ?? null;
        }
        if ($dagId) $params['groups'] = [$dagId];

        $rows = json_decode(REDCap::getData($params), true);
        if (empty($rows)) {
            return $dagId
                ? 'Record not found in your Data Access Group'
                : 'Record not found';
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
     * Mirrors REDCap's Project::getExtendedCheckboxCodeFormatted: '-' and '.'
     * become '_', then the code is lowercased and other characters removed.
     */
    private function getCheckboxExportColumns(string $fieldName, string $choicesRaw): array
    {
        $columns = [];
        foreach (explode('|', $choicesRaw) as $choice) {
            $parts = explode(',', $choice, 2);
            $code = trim($parts[0]);
            if ($code === '') continue;
            $sanitized = str_replace(['-', '.'], '_', $code);
            $sanitized = preg_replace('/[^a-z0-9_]/', '', strtolower($sanitized));
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
        $annotation = $fieldMeta['field_annotation'] ?? '';
        if (str_contains($annotation, '@CALCTEXT')) {
            return [false, 'Calculated text (@CALCTEXT) — REDCap recalculates it automatically'];
        }
        if (str_contains($annotation, '@CALCDATE')) {
            return [false, 'Calculated date (@CALCDATE) — REDCap recalculates it automatically'];
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
        $dd = $this->getInstrumentDictionary($project_id, $instrument);
        [$round1Data, $round2Data] = $this->getRoundRows($project_id, $record, $instrument, $event_id, array_keys($dd));

        return $this->buildComparison(
            $record, $instrument, $event_id, $dd, $round1Data, $round2Data,
            fn() => $this->getResolvedFields($project_id, $record, $instrument, $event_id),
            fn() => $this->isFinalized($project_id, $record, $instrument, $event_id)
        );
    }

    /**
     * Comparison of two already-fetched round rows. Resolution log and
     * finalized flag are supplied lazily so callers holding project-wide
     * data (the dashboard) avoid per-record queries.
     */
    private function buildComparison(string $record, string $instrument, int $event_id, array $dd, array $round1Data, array $round2Data, callable $resolvedLog, callable $finalized): array
    {
        $recordIdField = REDCap::getRecordIdField();

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
            $val1 = $this->fieldValue($round1Data, $fieldName, $fieldMeta);
            $val2 = $this->fieldValue($round2Data, $fieldName, $fieldMeta);
            // Checkbox values are the sorted list of checked codes, so string
            // equality is per-choice equality
            $match = $isCheckbox ? $val1 === $val2 : $this->valuesMatch($val1, $val2);

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

        // Resolution state from the module audit log (survives page reloads).
        // A resolution is only honored while the round values it adjudicated
        // are unchanged and it was written to the current merge target.
        $resolved = $resolvedLog();
        $targetInstance = $this->getMergeTargetInstance();
        $resolvedNames = [];
        $unresolvedFields = [];
        foreach ($fields as $f) {
            if ($f['match'] || !$f['merge_writable']) continue;
            $entry = $resolved[$f['field_name']] ?? null;
            if ($entry !== null && $this->resolutionStillValid($entry, $f['round1_value'], $f['round2_value'], $targetInstance)) {
                $resolvedNames[] = $f['field_name'];
            } else {
                $unresolvedFields[] = $f['field_name'];
            }
        }

        return array_merge($base, [
            'status' => $status,
            'total_fields' => $totalCompared,
            'matching_fields' => $totalCompared - $discrepancyCount,
            'matching_writable_fields' => count(array_filter(
                $fields,
                static fn($f) => $f['match'] && $f['merge_writable']
            )),
            'discrepancy_count' => $discrepancyCount,
            'agreement_pct' => $totalCompared > 0 ? round((($totalCompared - $discrepancyCount) / $totalCompared) * 100, 1) : 100,
            'fields' => $fields,
            'resolved_fields' => $resolvedNames,
            'unresolved_fields' => $unresolvedFields,
            'finalized' => $finalized(),
            'unresolved_count' => count($unresolvedFields)
        ]);
    }

    /**
     * A field's comparable value from an export row. Checkboxes live in
     * field___code columns, never under the base name, and are represented
     * as the comma-joined list of checked codes.
     */
    private function fieldValue(array $row, string $fieldName, array $fieldMeta): string
    {
        if (($fieldMeta['field_type'] ?? '') !== 'checkbox') {
            return (string)($row[$fieldName] ?? '');
        }
        $checked = [];
        foreach ($this->getCheckboxExportColumns($fieldName, $fieldMeta['select_choices_or_calculations'] ?? '') as $code => $col) {
            if (($row[$col] ?? '') === '1') $checked[] = $code;
        }
        return implode(', ', $checked);
    }

    /**
     * saveData columns that copy a field's value from a source export row
     * (every choice column for a checkbox, so unchecked choices are cleared).
     */
    private function fieldColumnsFrom(array $sourceRow, string $fieldName, array $fieldMeta): array
    {
        if (($fieldMeta['field_type'] ?? '') !== 'checkbox') {
            return [$fieldName => (string)($sourceRow[$fieldName] ?? '')];
        }
        $columns = [];
        foreach ($this->getCheckboxExportColumns($fieldName, $fieldMeta['select_choices_or_calculations'] ?? '') as $col) {
            $columns[$col] = ($sourceRow[$col] ?? '') === '1' ? '1' : '0';
        }
        return $columns;
    }

    /**
     * Data dictionary for one instrument, cached per request.
     */
    private function getInstrumentDictionary(int $project_id, string $instrument): array
    {
        return $this->dictionaryCache[$instrument] ??= REDCap::getDataDictionary($project_id, 'array', false, null, [$instrument]);
    }

    private function fingerprint(string $value): string
    {
        return sha1(trim($value));
    }

    /**
     * A logged resolution is stale when it was written to a different merge
     * target than the current setting, or when either round's current value
     * differs from the snapshot taken at resolution time. In merge-to-Instance-1
     * mode the Round 1 snapshot is the value the merge wrote into Round 1.
     * Legacy log entries (no snapshots) are accepted as-is.
     */
    private function resolutionStillValid(array $entry, string $currentR1, string $currentR2, int $targetInstance): bool
    {
        if (($entry['target_instance'] ?? null) !== null && (int)$entry['target_instance'] !== $targetInstance) {
            return false;
        }
        $snap1 = $entry['round1_snapshot'];
        $snap2 = $entry['round2_snapshot'];
        if ($snap1 === null && $snap2 === null) return true;

        $r2ok = $entry['round2_hash'] !== null
            ? hash_equals($entry['round2_hash'], $this->fingerprint($currentR2))
            : trim((string)$snap2) === trim(mb_substr($currentR2, 0, 200));
        if (!$r2ok) return false;

        if ($entry['round1_hash'] !== null) {
            return hash_equals($entry['round1_hash'], $this->fingerprint($currentR1));
        }
        // Entries logged before hashes: in-place merges snapshotted Round 1
        // before overwriting it, so the merged value is what Round 1 now holds
        $currentPrefix = trim(mb_substr($currentR1, 0, 200));
        return trim((string)$snap1) === $currentPrefix
            || ($targetInstance === self::ROUND_1 && trim((string)$entry['value']) === $currentPrefix);
    }

    private const RESOLUTION_LOG_COLUMNS = 'log_id, record, instrument, event_id, field, source_round, target_instance, value, value_complete, round1_snapshot, round2_snapshot, round1_hash, round2_hash, timestamp';

    /**
     * Fields already resolved for this record/instrument/event, read from the
     * module log (source of truth for adjudication state).
     * Returns field_name => latest resolution entry.
     */
    public function getResolvedFields(int $project_id, string $record, string $instrument, int $event_id): array
    {
        $result = $this->queryLogs(
            "SELECT " . self::RESOLUTION_LOG_COLUMNS . " WHERE message = ? AND project_id = ? AND record = ? AND instrument = ? AND event_id = ? ORDER BY log_id",
            ['Merged field', $project_id, $record, $instrument, $event_id]
        );
        return $this->collectResolutions($result)[$record . '|' . $instrument . '|' . $event_id] ?? [];
    }

    /**
     * Every resolution in the project, keyed "record|instrument|event_id" then
     * field — one query for the dashboard instead of one per record.
     */
    private function getAllResolutions(int $project_id): array
    {
        $result = $this->queryLogs(
            "SELECT " . self::RESOLUTION_LOG_COLUMNS . " WHERE message = ? AND project_id = ? ORDER BY log_id",
            ['Merged field', $project_id]
        );
        return $this->collectResolutions($result);
    }

    /**
     * Group 'Merged field' log rows (ordered by log_id) by cell and field;
     * the latest resolution of a field wins. log_id, not timestamp: two
     * re-resolutions within the same second must still order correctly.
     */
    private function collectResolutions($result): array
    {
        $resolved = [];
        while ($row = $result->fetch_assoc()) {
            $field = $row['field'] ?? '';
            if ($field === '') continue;
            $key = ($row['record'] ?? '') . '|' . ($row['instrument'] ?? '') . '|' . ($row['event_id'] ?? '');
            $resolved[$key][$field] = [
                'source_round' => $row['source_round'] ?? '',
                'timestamp' => $row['timestamp'] ?? '',
                'target_instance' => $row['target_instance'] ?? null,
                'value' => $row['value'] ?? '',
                'value_complete' => ($row['value_complete'] ?? '') === '1',
                'round1_snapshot' => $row['round1_snapshot'] ?? null,
                'round2_snapshot' => $row['round2_snapshot'] ?? null,
                'round1_hash' => $row['round1_hash'] ?? null,
                'round2_hash' => $row['round2_hash'] ?? null
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
        $dd = $this->getInstrumentDictionary($project_id, $instrument);
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
        $saveRow = $this->buildSaveRowHeader($record, $instrument, $event_id, $targetInstance);

        // Current round values are snapshotted into the resolution log entry so
        // a later edit to either round invalidates this adjudication
        [$round1Data, $round2Data] = $this->getRoundRows($project_id, $record, $instrument, $event_id, [$fieldName]);

        if (($fieldMeta['field_type'] ?? '') === 'checkbox') {
            // Checkboxes are written per-choice column, copied server-side from
            // the chosen round (a client-supplied scalar cannot represent them)
            if (!in_array($sourceRound, [self::ROUND_1, self::ROUND_2], true)) {
                return ['success' => false, 'error' => 'Checkbox fields must be resolved by choosing Round 1 or Round 2'];
            }
        } elseif (in_array($sourceRound, [self::ROUND_1, self::ROUND_2], true)) {
            // Keep R1/R2: copy from server, never trust the browser (stale tab)
        } else {
            $sourceRound = 0; // custom value
        }

        if ($sourceRound !== 0) {
            $sourceRow = $sourceRound === self::ROUND_1 ? $round1Data : $round2Data;
            if (empty($sourceRow)) {
                return ['success' => false, 'error' => 'Source round has no data for this record'];
            }
            $saveRow = array_merge($saveRow, $this->fieldColumnsFrom($sourceRow, $fieldName, $fieldMeta));
            $value = $this->fieldValue($sourceRow, $fieldName, $fieldMeta);
        } else {
            $saveRow[$fieldName] = $value;
        }

        $snapshot1 = $this->fieldValue($round1Data, $fieldName, $fieldMeta);
        $snapshot2 = $this->fieldValue($round2Data, $fieldName, $fieldMeta);
        // Merge-to-Instance-1 overwrites Round 1, so the adjudicated state of
        // Round 1 is the value being written, not the value it held before
        if ($targetInstance === self::ROUND_1) {
            $snapshot1 = $value;
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
            // Full value: finalize re-applies custom values from the log
            'value' => $value,
            'value_complete' => '1',
            'round1_snapshot' => mb_substr($snapshot1, 0, 200),
            'round2_snapshot' => mb_substr($snapshot2, 0, 200),
            'round1_hash' => $this->fingerprint($snapshot1),
            'round2_hash' => $this->fingerprint($snapshot2),
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

        // Server-side verification — never trust client state. compareRounds
        // already validated each resolution against current round values, so
        // stale adjudications (rounds edited after resolution) count as unresolved.
        if (!empty($comparison['unresolved_fields'])) {
            return [
                'success' => false,
                'error' => 'All discrepancies must be resolved before finalizing',
                'unresolved' => $comparison['unresolved_fields']
            ];
        }

        $targetInstance = $this->getMergeTargetInstance();
        $inPlace = $targetInstance === self::ROUND_1;
        $dd = $this->getInstrumentDictionary($project_id, $instrument);
        $saveRow = $this->buildSaveRowHeader($record, $instrument, $event_id, $targetInstance);

        $copiedMatching = 0;
        $copiedExcluded = 0;
        $reappliedResolutions = 0;
        $skippedFields = [];

        // In merge-to-Instance-1 mode the matching, excluded, and adjudicated
        // values are already in Round 1 (validity of each resolution checks
        // that); writing them again would only pollute the data audit log
        if (!$inPlace) {
            [$round1Data, $round2Data] = $this->getRoundRows($project_id, $record, $instrument, $event_id, array_keys($dd));
            $resolutions = $this->getResolvedFields($project_id, $record, $instrument, $event_id);

            foreach ($comparison['fields'] as $f) {
                if (!$f['merge_writable']) {
                    if ($f['match']) {
                        $skippedFields[] = ['field' => $f['field_name'], 'reason' => $f['merge_skip_reason']];
                    }
                    continue;
                }
                $meta = $dd[$f['field_name']];
                if ($f['match']) {
                    $saveRow = array_merge($saveRow, $this->fieldColumnsFrom($round1Data, $f['field_name'], $meta));
                    $copiedMatching++;
                    continue;
                }

                // Re-apply each adjudication instead of trusting that Instance 3
                // still holds it (it may have been deleted or hand-edited).
                // compareRounds verified these resolutions are current.
                $entry = $resolutions[$f['field_name']];
                $source = (int)$entry['source_round'];
                if ($source === self::ROUND_1 || $source === self::ROUND_2) {
                    $sourceRow = $source === self::ROUND_1 ? $round1Data : $round2Data;
                    $saveRow = array_merge($saveRow, $this->fieldColumnsFrom($sourceRow, $f['field_name'], $meta));
                } elseif ($entry['value_complete']) {
                    $saveRow[$f['field_name']] = $entry['value'];
                } else {
                    // Legacy custom resolution logged truncated: leave the
                    // value mergeField already wrote to Instance 3
                    continue;
                }
                $reappliedResolutions++;
            }

            if ($this->shouldCopyExcludedOnFinalize()) {
                foreach ($this->getExcludedFields($instrument) as $ef) {
                    if (!isset($dd[$ef])) continue;
                    $meta = $dd[$ef];
                    $type = $meta['field_type'] ?? 'text';
                    if ($type === 'descriptive') continue;
                    [$writable, ] = $this->getFieldWritability($meta);
                    if (!$writable) continue;
                    $saveRow = array_merge($saveRow, $this->fieldColumnsFrom($round1Data, $ef, $meta));
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
            'reapplied_resolutions' => $reappliedResolutions,
            'mode' => $inPlace ? 'in_place' : 'copy'
        ]);

        $this->dashboardCache = null;

        return [
            'success' => true,
            'mode' => $inPlace ? 'in_place' : 'copy',
            'copied_matching' => $copiedMatching,
            'copied_excluded' => $copiedExcluded,
            'reapplied_resolutions' => $reappliedResolutions,
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

        // Dashboard, stats, and task list only expose instruments the current
        // user has form-level read access to
        $username = (string)$user->getUsername();
        $ddeInstruments = array_values(array_filter(
            $ddeInstruments,
            fn($f) => $this->userCanReadInstrument($username, $f)
        ));
        if (empty($ddeInstruments)) return [];

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

        // Build event name => event_id map for resolving numeric IDs. Classic
        // projects export no event name and have exactly one event.
        $longitudinal = REDCap::isLongitudinal();
        $eventNameToId = $this->getEventNameToIdMap();
        $classicEventId = $longitudinal ? 0 : $this->getFirstEventId();

        // Per-record instance map: record => instrument => event_name =>
        // [instance => form_complete value], plus the round rows themselves
        // so "merged" can be re-verified without refetching per record
        $instanceMap = [];
        $roundRows = [];
        foreach ($ddeData as $row) {
            $rid = $row[$recordIdField];
            $inst = $row['redcap_repeat_instrument'] ?? '';
            $instNum = $this->normalizeRoundInstance($row['redcap_repeat_instance'] ?? null);
            $eventName = $row['redcap_event_name'] ?? '';
            if ($inst !== '' && in_array($inst, $ddeInstruments)) {
                $instanceMap[$rid][$inst][$eventName][$instNum] = (string)($row[$inst . '_complete'] ?? '');
                if ($instNum === self::ROUND_1 || $instNum === self::ROUND_2) {
                    $roundRows[$rid][$inst][$eventName][$instNum] = $row;
                }
            }
        }

        $targetInstance = $this->getMergeTargetInstance();
        $finalizedMap = $this->getFinalizedMap($project_id);
        $allResolutions = null; // fetched on first "merged" cell

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
                    // An event name that does not resolve cannot be compared or
                    // linked; never attribute it to some other event
                    $eventId = $longitudinal ? ($eventNameToId[$eventName] ?? 0) : $classicEventId;
                    if ($eventId <= 0) continue;
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

                    // Post-finalize edits to R1/R2 invalidate resolutions; demote
                    // "merged" so dashboard/tasks match Compare & Merge
                    if ($status === 'merged' && $hasR1 && $hasR2) {
                        $allResolutions ??= $this->getAllResolutions($project_id);
                        $cellKey = $rid . '|' . $instName . '|' . $eventId;
                        $cmp = $this->buildComparison(
                            (string)$rid, $instName, $eventId,
                            $this->getInstrumentDictionary($project_id, $instName),
                            $roundRows[$rid][$instName][$eventName][self::ROUND_1] ?? [],
                            $roundRows[$rid][$instName][$eventName][self::ROUND_2] ?? [],
                            static fn() => $allResolutions[$cellKey] ?? [],
                            static fn() => true
                        );
                        if (($cmp['unresolved_count'] ?? 0) > 0) {
                            $status = 'merge_in_progress';
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
     * Whether an event_id resolves to a real event in this project.
     */
    private function isValidEventId(int $event_id): bool
    {
        if ($event_id <= 0) return false;
        $name = REDCap::getEventNames(true, false, $event_id);
        return is_string($name) && $name !== '';
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

    /**
     * Common identifying keys for a saveData row targeting the merge instance.
     */
    private function buildSaveRowHeader(string $record, string $instrument, int $event_id, int $targetInstance): array
    {
        $saveRow = [REDCap::getRecordIdField() => $record];
        $eventName = $this->buildEventNameForSave($event_id);
        if ($eventName !== null) $saveRow['redcap_event_name'] = $eventName;
        $saveRow['redcap_repeat_instrument'] = $instrument;
        $saveRow['redcap_repeat_instance'] = $targetInstance;
        return $saveRow;
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

    /**
     * log_id of the most recent log entry with this message for a cell, or null.
     */
    private function latestLogId(string $message, int $project_id, string $record, string $instrument, int $event_id): ?int
    {
        $result = $this->queryLogs(
            "SELECT log_id WHERE message = ? AND project_id = ? AND record = ? AND instrument = ? AND event_id = ? ORDER BY log_id DESC LIMIT 1",
            [$message, $project_id, $record, $instrument, $event_id]
        );
        $row = $result->fetch_assoc();
        return $row ? (int)$row['log_id'] : null;
    }

    private function sendBothRoundsCompleteNotification(string $email, int $project_id, string $record, string $instrument, bool $reReview = false): void
    {
        $label = $this->getInstrumentLabel($instrument);
        $safeRecord = htmlspecialchars($record, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($reReview) {
            $subject = "DDE Needs Re-review — Record $safeRecord ($safeLabel)";
            $body = "A round was edited after the merge was finalized, creating discrepancies that need to be resolved again for:<br><br>";
        } else {
            $subject = "DDE Ready for Review — Record $safeRecord ($safeLabel)";
            $body = "Both Round 1 and Round 2 are complete for:<br><br>";
        }
        $body .= "<b>Record:</b> $safeRecord<br>";
        $body .= "<b>Instrument:</b> $safeLabel<br><br>";
        $body .= "Please open the DDE Comparison & Merge page to review and adjudicate.";

        $fromEmail = $GLOBALS['homepage_contact_email'] ?? 'noreply@redcap.local';
        REDCap::email($email, $fromEmail, $subject, $body);
    }
}
