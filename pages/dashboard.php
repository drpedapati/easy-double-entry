<?php
/** @var \CCHMC\EasyDoubleEntry\EasyDoubleEntry $module */

$module->initializeJavascriptModuleObject();
$jsModuleObj = $module->getJavascriptModuleObjectName();
?>

<style>
.ede-dashboard { max-width: 1200px; margin: 0 auto; }
.ede-status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.ede-status-pending { background: #e2e3e5; color: #383d41; }
.ede-status-partial { background: #fff3cd; color: #856404; }
.ede-status-ready_to_compare { background: #f8d7da; color: #721c24; }
.ede-status-merged { background: #d4edda; color: #155724; }
.ede-summary-cards { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
.ede-summary-card { flex: 1; min-width: 120px; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #dee2e6; }
.ede-summary-card .number { font-size: 1.8em; font-weight: 700; }
.ede-instrument-tag { display: inline-block; padding: 2px 8px; margin: 2px; border-radius: 4px; font-size: 11px; cursor: pointer; }
.ede-instrument-tag:hover { opacity: 0.8; }
</style>

<div class="ede-dashboard">
    <h4 class="my-3"><i class="fas fa-columns mr-2"></i>DDE Dashboard</h4>

    <!-- Summary Stats -->
    <div id="ede-summary" class="ede-summary-cards">
        <div class="ede-summary-card bg-light">
            <div class="number text-primary" id="ede-total-records">-</div>
            <div class="text-muted">Records</div>
        </div>
        <div class="ede-summary-card bg-light">
            <div class="number text-secondary" id="ede-total-pending">-</div>
            <div class="text-muted">Pending</div>
        </div>
        <div class="ede-summary-card bg-light">
            <div class="number text-warning" id="ede-total-partial">-</div>
            <div class="text-muted">Partial</div>
        </div>
        <div class="ede-summary-card bg-light">
            <div class="number text-danger" id="ede-total-ready">-</div>
            <div class="text-muted">Ready to Compare</div>
        </div>
        <div class="ede-summary-card bg-light">
            <div class="number text-success" id="ede-total-merged">-</div>
            <div class="text-muted">Merged</div>
        </div>
    </div>

    <!-- Filter -->
    <div class="mb-3">
        <div class="input-group input-group-sm" style="max-width: 400px;">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
            </div>
            <input type="text" id="ede-filter-record" class="form-control" placeholder="Filter by Record ID...">
            <div class="input-group-append">
                <select id="ede-filter-status" class="form-control">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="partial">Partial</option>
                    <option value="ready_to_compare">Ready to Compare</option>
                    <option value="merged">Merged</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Loading -->
    <div id="ede-dash-loading" class="text-center py-5">
        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
        <p class="text-muted mt-2">Loading dashboard...</p>
    </div>

    <!-- Dashboard Table -->
    <div id="ede-dash-table" style="display:none;"></div>
</div>

<script>
(function() {
    const module = <?= $jsModuleObj ?>;
    let dashboardData = [];
    const compareUrl = <?= json_encode($module->getUrl('pages/compare.php')) ?>;

    loadDashboard();

    function loadDashboard() {
        module.ajax('get-dashboard-data', {}).then(function(data) {
            dashboardData = data;
            document.getElementById('ede-dash-loading').style.display = 'none';
            document.getElementById('ede-dash-table').style.display = '';
            updateStats();
            renderTable();
        }).catch(function(err) {
            document.getElementById('ede-dash-loading').innerHTML =
                '<div class="alert alert-danger">Error loading dashboard: ' + escapeHtml(err.message || String(err)) + '</div>';
        });
    }

    function updateStats() {
        let pending = 0, partial = 0, ready = 0, merged = 0;
        dashboardData.forEach(row => {
            row.instruments.forEach(inst => {
                if (inst.status === 'pending') pending++;
                else if (inst.status === 'partial') partial++;
                else if (inst.status === 'ready_to_compare') ready++;
                else if (inst.status === 'merged') merged++;
            });
        });
        document.getElementById('ede-total-records').textContent = dashboardData.length;
        document.getElementById('ede-total-pending').textContent = pending;
        document.getElementById('ede-total-partial').textContent = partial;
        document.getElementById('ede-total-ready').textContent = ready;
        document.getElementById('ede-total-merged').textContent = merged;
    }

    function renderTable() {
        const filterRecord = document.getElementById('ede-filter-record').value.trim().toLowerCase();
        const filterStatus = document.getElementById('ede-filter-status').value;

        let filtered = dashboardData;

        if (filterRecord) {
            filtered = filtered.filter(row => row.record.toLowerCase().includes(filterRecord));
        }

        if (filterStatus) {
            filtered = filtered.filter(row => row.instruments.some(inst => inst.status === filterStatus));
        }

        let html = '<table class="table table-sm table-hover table-bordered">';
        html += '<thead class="thead-light"><tr>';
        html += '<th>Record</th>';
        html += '<th>Instruments</th>';
        html += '<th>Overall Status</th>';
        html += '</tr></thead><tbody>';

        if (filtered.length === 0) {
            html += '<tr><td colspan="3" class="text-center text-muted py-3">No records found.</td></tr>';
        }

        filtered.forEach(function(row) {
            let instruments = row.instruments;
            if (filterStatus) {
                instruments = instruments.filter(inst => inst.status === filterStatus);
            }

            // Overall status = worst status
            const statuses = instruments.map(i => i.status);
            let overall = 'merged';
            if (statuses.includes('pending')) overall = 'pending';
            else if (statuses.includes('partial')) overall = 'partial';
            else if (statuses.includes('ready_to_compare')) overall = 'ready_to_compare';

            html += '<tr>';
            html += '<td><b>' + escapeHtml(row.record) + '</b></td>';
            html += '<td>';

            instruments.forEach(function(inst) {
                const colors = {
                    pending: '#6c757d', partial: '#ffc107', ready_to_compare: '#dc3545', merged: '#28a745'
                };
                const labels = {
                    pending: 'Not started', partial: '1 round done', ready_to_compare: 'Compare!', merged: 'Done'
                };
                const color = colors[inst.status] || '#6c757d';
                const tooltip = inst.instrument_label + ': ' + (labels[inst.status] || inst.status);
                const isClickable = inst.status === 'ready_to_compare';

                html += '<span class="ede-instrument-tag" style="background:' + color + '20; color:' + color + '; border:1px solid ' + color + ';"';
                html += ' title="' + escapeHtml(tooltip) + '"';
                if (isClickable) {
                    html += ' onclick="window.location.href=\'' + compareUrl + '&record=' + encodeURIComponent(row.record) + '&instrument=' + encodeURIComponent(inst.instrument) + '\'"';
                    html += ' style="background:' + color + '20; color:' + color + '; border:1px solid ' + color + '; cursor:pointer; font-weight:bold;"';
                }
                html += '>';
                html += escapeHtml(inst.instrument_label);
                if (inst.has_round1) html += ' <i class="fas fa-circle" style="font-size:6px;vertical-align:middle;" title="R1"></i>';
                if (inst.has_round2) html += ' <i class="fas fa-circle" style="font-size:6px;vertical-align:middle;" title="R2"></i>';
                if (inst.has_final) html += ' <i class="fas fa-check" style="font-size:10px;" title="Final"></i>';
                html += '</span> ';
            });

            html += '</td>';
            html += '<td><span class="ede-status-badge ede-status-' + overall + '">' + formatStatus(overall) + '</span></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        document.getElementById('ede-dash-table').innerHTML = html;
    }

    function formatStatus(s) {
        return {
            pending: 'Pending',
            partial: 'Partial',
            ready_to_compare: 'Ready to Compare',
            merged: 'Merged'
        }[s] || s;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Filter handlers
    document.getElementById('ede-filter-record').addEventListener('input', renderTable);
    document.getElementById('ede-filter-status').addEventListener('change', renderTable);
})();
</script>
