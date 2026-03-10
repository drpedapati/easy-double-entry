<?php
/** @var \CCHMC\EasyDoubleEntry\EasyDoubleEntry $module */

$module->initializeJavascriptModuleObject();
$jsModuleObj = $module->getJavascriptModuleObjectName();
?>

<style>
.ede-tasklist { max-width: 900px; margin: 0 auto; }
.ede-priority-high { border-left: 4px solid #dc3545 !important; }
.ede-priority-normal { border-left: 4px solid #ffc107 !important; }
.ede-action-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.ede-action-enter { background: #fff3cd; color: #856404; }
.ede-action-compare { background: #f8d7da; color: #721c24; }
</style>

<div class="ede-tasklist">
    <h4 class="my-3"><i class="fas fa-tasks mr-2"></i>DDE Task List</h4>
    <p class="text-muted mb-3">Outstanding items that need attention, sorted by priority.</p>

    <div id="ede-task-loading" class="text-center py-5">
        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
    </div>

    <div id="ede-task-content" style="display:none;"></div>
</div>

<script>
(function() {
    const module = <?= $jsModuleObj ?>;
    const compareUrl = <?= json_encode($module->getUrl('pages/compare.php')) ?>;
    const pid = <?= json_encode((string)$module->getProjectId()) ?>;
    const event_id = <?= json_encode((string)$module->getFirstEventId()) ?>;
    const app_path_webroot = <?= json_encode(APP_PATH_WEBROOT) ?>;

    module.ajax('get-task-list', {}).then(function(tasks) {
        document.getElementById('ede-task-loading').style.display = 'none';
        document.getElementById('ede-task-content').style.display = '';

        if (tasks.length === 0) {
            document.getElementById('ede-task-content').innerHTML =
                '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>All caught up! No outstanding DDE tasks.</div>';
            return;
        }

        let html = '<div class="list-group">';
        tasks.forEach(function(task) {
            const isCompare = task.action === 'Compare & Merge';
            const priorityClass = task.priority === 'high' ? 'ede-priority-high' : 'ede-priority-normal';
            const actionClass = isCompare ? 'ede-action-compare' : 'ede-action-enter';
            const icon = isCompare ? 'fa-not-equal' : 'fa-edit';

            let href = '#';
            if (isCompare) {
                href = compareUrl + '&record=' + encodeURIComponent(task.record) + '&instrument=' + encodeURIComponent(task.instrument);
            } else if (task.round_instance) {
                href = app_path_webroot + 'DataEntry/index.php?pid=' + pid + '&page=' + encodeURIComponent(task.instrument) + '&id=' + encodeURIComponent(task.record) + '&event_id=' + event_id + '&instance=' + task.round_instance;
            }

            html += '<a href="' + href + '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ' + priorityClass + '">';
            html += '<div>';
            html += '<i class="fas ' + icon + ' mr-2 text-muted"></i>';
            html += '<b>' + escapeHtml(task.record) + '</b>';
            html += ' &mdash; ' + escapeHtml(task.instrument_label);
            html += '</div>';
            html += '<span class="ede-action-badge ' + actionClass + '">' + escapeHtml(task.action) + '</span>';
            html += '</a>';
        });
        html += '</div>';

        document.getElementById('ede-task-content').innerHTML = html;
    }).catch(function(err) {
        document.getElementById('ede-task-loading').innerHTML =
            '<div class="alert alert-danger">Error: ' + (err.message || err) + '</div>';
    });

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
