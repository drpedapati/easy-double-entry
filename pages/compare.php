<?php
/** @var \CCHMC\EasyDoubleEntry\EasyDoubleEntry $module */

$module->initializeJavascriptModuleObject();
$jsModuleObj = $module->getJavascriptModuleObjectName();

// Accept event_id from URL params; fall back to first event for classic projects
$urlEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($urlEventId === 0) {
    $urlEventId = $module->getFirstEventId();
}
?>

<style>
.ede-compare-container { max-width: 1200px; margin: 0 auto; }
.ede-field-row { border-bottom: 1px solid #dee2e6; padding: 10px 0; }
.ede-field-row:hover { background: #f8f9fa; }
.ede-match { background-color: #d4edda !important; }
.ede-mismatch { background-color: #f8d7da !important; }
.ede-merged { background-color: #cce5ff !important; opacity: 0.7; }
.ede-value-cell { padding: 8px 12px; font-family: monospace; font-size: 13px; word-break: break-word; }
.ede-merge-btn { min-width: 90px; }
.ede-field-label { font-weight: 600; font-size: 13px; color: #495057; }
.ede-field-name { font-size: 11px; color: #6c757d; font-family: monospace; }
.ede-stats-card { border-radius: 8px; padding: 20px; text-align: center; }
.ede-stats-card .ede-stat-number { font-size: 2em; font-weight: 700; }
.ede-legend { display: flex; gap: 20px; margin: 15px 0; font-size: 13px; }
.ede-legend-item { display: flex; align-items: center; gap: 6px; }
.ede-legend-swatch { width: 16px; height: 16px; border-radius: 3px; border: 1px solid #ccc; }
</style>

<div class="ede-compare-container">
    <h4 class="my-3">
        <i class="fas fa-not-equal mr-2"></i>DDE Comparison & Merge
    </h4>

    <!-- Record/Instrument Selector -->
    <div class="card mb-3">
        <div class="card-body">
            <form id="ede-compare-form" class="form-inline">
                <div class="form-group mr-3">
                    <label for="ede-record" class="mr-2"><b>Record:</b></label>
                    <input type="text" id="ede-record" class="form-control form-control-sm" placeholder="Record ID" style="width: 150px;"
                           value="<?= htmlspecialchars($_GET['record'] ?? '') ?>">
                </div>
                <div class="form-group mr-3">
                    <label for="ede-instrument" class="mr-2"><b>Instrument:</b></label>
                    <select id="ede-instrument" class="form-control form-control-sm" style="width: 250px;">
                        <option value="">-- Select --</option>
                        <?php
                        $ddeInstruments = $module->getDDEInstruments();
                        global $Proj;
                        foreach ($ddeInstruments as $formName) {
                            $label = $Proj?->forms[$formName]['menu'] ?? $formName;
                            $sel = (($_GET['instrument'] ?? '') === $formName) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($formName) . "\" $sel>" . htmlspecialchars($label) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-search mr-1"></i>Compare
                </button>
            </form>
        </div>
    </div>

    <!-- Stats Summary -->
    <div id="ede-stats-row" class="row mb-3" style="display:none;">
        <div class="col-md-3">
            <div class="ede-stats-card bg-light border">
                <div class="ede-stat-number text-primary" id="ede-stat-total">0</div>
                <div class="text-muted">Total Fields</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ede-stats-card bg-light border">
                <div class="ede-stat-number text-success" id="ede-stat-match">0</div>
                <div class="text-muted">Matching</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ede-stats-card bg-light border">
                <div class="ede-stat-number text-danger" id="ede-stat-discrepancy">0</div>
                <div class="text-muted">Discrepancies</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="ede-stats-card bg-light border">
                <div class="ede-stat-number text-info" id="ede-stat-agreement">0%</div>
                <div class="text-muted">Agreement</div>
            </div>
        </div>
    </div>

    <!-- Legend + Bulk Actions -->
    <div id="ede-actions-row" style="display:none;" class="d-flex justify-content-between align-items-center mb-2">
        <div class="ede-legend">
            <div class="ede-legend-item"><div class="ede-legend-swatch" style="background:#d4edda;"></div> Match</div>
            <div class="ede-legend-item"><div class="ede-legend-swatch" style="background:#f8d7da;"></div> Discrepancy</div>
            <div class="ede-legend-item"><div class="ede-legend-swatch" style="background:#cce5ff;"></div> Merged</div>
        </div>
        <div>
            <button id="ede-bulk-merge" class="btn btn-sm btn-success mr-2">
                <i class="fas fa-check-double mr-1"></i>Auto-Merge Matching Fields
            </button>
            <button id="ede-show-discrepancies-only" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-filter mr-1"></i>Show Discrepancies Only
            </button>
        </div>
    </div>

    <!-- Comparison Table -->
    <div id="ede-compare-results"></div>

    <!-- Loading -->
    <div id="ede-loading" style="display:none;" class="text-center py-5">
        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
        <p class="text-muted mt-2">Comparing rounds...</p>
    </div>
</div>

<!-- Merge Comment Modal -->
<div class="modal fade" id="ede-comment-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Resolve Discrepancy</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p id="ede-comment-field-info"></p>
                <div class="form-group">
                    <label for="ede-merge-comment"><b>Comment:</b></label>
                    <textarea id="ede-merge-comment" class="form-control" rows="3" placeholder="Reason for choosing this value..."></textarea>
                </div>
                <div class="form-group">
                    <label><b>Custom value (optional):</b></label>
                    <input type="text" id="ede-merge-custom-value" class="form-control form-control-sm" placeholder="Leave blank to use selected round's value">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="ede-confirm-merge">
                    <i class="fas fa-check mr-1"></i>Merge
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const module = <?= $jsModuleObj ?>;
    const requireComment = <?= json_encode((bool)$module->getProjectSetting('require-merge-comment')) ?>;
    // Use event_id from URL params (resolved server-side), falling back to first event
    const pageEventId = <?= json_encode($urlEventId) ?>;
    let currentComparison = null;
    let showDiscrepanciesOnly = false;
    let pendingMerge = null;

    // Form submit
    document.getElementById('ede-compare-form').addEventListener('submit', function(e) {
        e.preventDefault();
        loadComparison();
    });

    // Auto-load if URL params present
    if (document.getElementById('ede-record').value && document.getElementById('ede-instrument').value) {
        loadComparison();
    }

    function getEventId() {
        // Once a comparison is loaded, use its event_id (authoritative from server).
        // Otherwise use the URL-supplied or fallback event_id.
        if (currentComparison && currentComparison.event_id) {
            return currentComparison.event_id;
        }
        return pageEventId;
    }

    function loadComparison() {
        const record = document.getElementById('ede-record').value.trim();
        const instrument = document.getElementById('ede-instrument').value;
        if (!record || !instrument) {
            alert('Please enter a record ID and select an instrument.');
            return;
        }

        document.getElementById('ede-loading').style.display = '';
        document.getElementById('ede-compare-results').innerHTML = '';
        document.getElementById('ede-stats-row').style.display = 'none';
        document.getElementById('ede-actions-row').style.display = 'none';

        module.ajax('compare-rounds', {
            record: record,
            instrument: instrument,
            event_id: getEventId()
        }).then(function(result) {
            document.getElementById('ede-loading').style.display = 'none';
            currentComparison = result;

            if (result.status === 'no_data') {
                document.getElementById('ede-compare-results').innerHTML =
                    '<div class="alert alert-warning"><i class="fas fa-info-circle mr-2"></i>No data found for this record/instrument.</div>';
                return;
            }
            if (result.status === 'incomplete') {
                document.getElementById('ede-compare-results').innerHTML =
                    '<div class="alert alert-info"><i class="fas fa-clock mr-2"></i>Round ' + result.missing_round + ' has not been entered yet.</div>';
                return;
            }

            // Show stats
            document.getElementById('ede-stat-total').textContent = result.total_fields;
            document.getElementById('ede-stat-match').textContent = result.matching_fields;
            document.getElementById('ede-stat-discrepancy').textContent = result.discrepancy_count;
            document.getElementById('ede-stat-agreement').textContent = result.agreement_pct + '%';
            document.getElementById('ede-stats-row').style.display = '';
            document.getElementById('ede-actions-row').style.display = '';

            renderComparisonTable(result);
        }).catch(function(err) {
            document.getElementById('ede-loading').style.display = 'none';
            document.getElementById('ede-compare-results').innerHTML =
                '<div class="alert alert-danger">Error: ' + escapeHtml(err.message || String(err)) + '</div>';
        });
    }

    function renderComparisonTable(result) {
        let html = '<table class="table table-sm table-bordered">';
        html += '<thead class="thead-light"><tr>';
        html += '<th style="width:25%;">Field</th>';
        html += '<th style="width:25%;">Round 1</th>';
        html += '<th style="width:25%;">Round 2</th>';
        html += '<th style="width:25%;">Action</th>';
        html += '</tr></thead><tbody>';

        result.fields.forEach(function(field) {
            if (showDiscrepanciesOnly && field.match) return;

            const rowClass = field.match ? 'ede-match' : 'ede-mismatch';
            const v1 = escapeHtml(field.round1_value || '(empty)');
            const v2 = escapeHtml(field.round2_value || '(empty)');

            html += '<tr class="' + rowClass + '" data-field="' + escapeHtml(field.field_name) + '">';
            html += '<td><div class="ede-field-label">' + escapeHtml(field.field_label) + '</div>';
            html += '<div class="ede-field-name">' + escapeHtml(field.field_name) + '</div></td>';
            html += '<td class="ede-value-cell">' + v1 + '</td>';
            html += '<td class="ede-value-cell">' + v2 + '</td>';

            if (field.match) {
                html += '<td class="text-center text-success"><i class="fas fa-check"></i> Match</td>';
            } else {
                html += '<td class="text-center">';
                html += '<button class="btn btn-sm btn-outline-primary ede-merge-btn mr-1" onclick="edePickRound(\'' + escapeHtml(field.field_name) + '\', 1)">Keep R1</button>';
                html += '<button class="btn btn-sm btn-outline-success ede-merge-btn mr-1" onclick="edePickRound(\'' + escapeHtml(field.field_name) + '\', 2)">Keep R2</button>';
                html += '<button class="btn btn-sm btn-outline-secondary" onclick="edeEditValue(\'' + escapeHtml(field.field_name) + '\')"><i class="fas fa-pen"></i></button>';
                html += '</td>';
            }

            html += '</tr>';
        });

        html += '</tbody></table>';
        document.getElementById('ede-compare-results').innerHTML = html;
    }

    // Toggle discrepancies only
    document.getElementById('ede-show-discrepancies-only').addEventListener('click', function() {
        showDiscrepanciesOnly = !showDiscrepanciesOnly;
        this.classList.toggle('active');
        this.innerHTML = showDiscrepanciesOnly
            ? '<i class="fas fa-list mr-1"></i>Show All Fields'
            : '<i class="fas fa-filter mr-1"></i>Show Discrepancies Only';
        if (currentComparison) renderComparisonTable(currentComparison);
    });

    // Bulk merge matching
    document.getElementById('ede-bulk-merge').addEventListener('click', function() {
        if (!currentComparison) return;
        if (!confirm('Auto-merge all matching fields? This writes ' + currentComparison.matching_fields + ' field(s) to the final record.')) return;

        module.ajax('merge-bulk', {
            record: currentComparison.record,
            instrument: currentComparison.instrument,
            event_id: getEventId()
        }).then(function(result) {
            alert('Merged ' + result.merged + ' matching field(s). ' + result.skipped + ' discrepancies remaining.');
            loadComparison(); // Refresh
        }).catch(function(err) {
            alert('Error: ' + (err.message || err));
        });
    });

    // Pick round value for a field
    window.edePickRound = function(fieldName, roundNum) {
        const field = currentComparison.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        const value = roundNum === 1 ? field.round1_value : field.round2_value;

        if (requireComment) {
            pendingMerge = { fieldName, value, roundNum, field };
            document.getElementById('ede-comment-field-info').innerHTML =
                '<b>' + escapeHtml(field.field_label) + '</b>: keeping Round ' + roundNum + ' value "' + escapeHtml(value) + '"';
            document.getElementById('ede-merge-comment').value = '';
            document.getElementById('ede-merge-custom-value').value = '';
            $('#ede-comment-modal').modal('show');
        } else {
            doMerge(fieldName, value, roundNum, '');
        }
    };

    // Manual edit for a field
    window.edeEditValue = function(fieldName) {
        const field = currentComparison.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        pendingMerge = { fieldName, value: '', roundNum: 0, field };
        document.getElementById('ede-comment-field-info').innerHTML =
            '<b>' + escapeHtml(field.field_label) + '</b>: enter a custom merged value';
        document.getElementById('ede-merge-comment').value = '';
        document.getElementById('ede-merge-custom-value').value = '';
        document.getElementById('ede-merge-custom-value').placeholder = 'Enter the final value';
        $('#ede-comment-modal').modal('show');
    };

    // Confirm merge from modal
    document.getElementById('ede-confirm-merge').addEventListener('click', function() {
        if (!pendingMerge) return;
        const comment = document.getElementById('ede-merge-comment').value.trim();
        const customValue = document.getElementById('ede-merge-custom-value').value.trim();

        if (requireComment && !comment) {
            alert('A comment is required.');
            return;
        }

        const value = customValue || pendingMerge.value;
        const roundNum = customValue ? 0 : pendingMerge.roundNum;

        $('#ede-comment-modal').modal('hide');
        doMerge(pendingMerge.fieldName, value, roundNum, comment);
        pendingMerge = null;
    });

    function doMerge(fieldName, value, sourceRound, comment) {
        module.ajax('merge-field', {
            record: currentComparison.record,
            instrument: currentComparison.instrument,
            event_id: getEventId(),
            field_name: fieldName,
            value: value,
            source_round: sourceRound,
            comment: comment
        }).then(function(result) {
            if (result.success) {
                // Mark row as merged
                const row = document.querySelector('tr[data-field="' + fieldName + '"]');
                if (row) {
                    row.className = 'ede-merged';
                    row.querySelector('td:last-child').innerHTML = '<i class="fas fa-check-circle text-info"></i> Merged';
                }
            } else {
                alert('Merge failed: ' + (result.error || 'unknown error'));
            }
        }).catch(function(err) {
            alert('Error: ' + (err.message || err));
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
