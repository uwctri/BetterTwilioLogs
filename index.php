<?php

use ExternalModules\ExternalModules;
use UWMadison\BetterTwilioLogs\BetterTwilioLogs;

/** @var BetterTwilioLogs $module */

$project_id = (int)($_GET['pid'] ?? $module->getProjectId());
$user_id = USERID;

$module->initializeJavascriptModuleObject();

$twilioConfig = $module->query("
    SELECT twilio_enabled,
           twilio_account_sid,
           twilio_from_number
    FROM   redcap_projects
    WHERE  project_id = ?
", [$project_id]);

$isTwilioConfigured = false;
$twilioFromNumber = '';
if ($twilioConfig && $twilioConfig->num_rows > 0) {
    $tRow = $twilioConfig->fetch_assoc();
    $isTwilioConfigured = ($tRow['twilio_enabled'] == 1 
        && !empty($tRow['twilio_account_sid']) 
        && !empty($tRow['twilio_from_number']));
    $twilioFromNumber = $tRow['twilio_from_number'] ?? '';
}

$hasAcknowledgedPhi = $module->hasUserAcknowledgedPhi($project_id, $user_id);
$lastFetchDatetime = $module->getProjectSetting('last_fetch_datetime', $project_id) ?? 'Never';
$phoneMap = $module->getPhoneToRecordMap($project_id);
$resolutions = $module->getTaskResolutions($project_id);
$logs = $module->getProjectLogs($project_id);
$initialPerPage = $module->getUserPerPage($project_id, $user_id);

// Friendly Twilio error codes
$knownErrors = [
    '30003' => 'Handset unreachable',
    '30004' => 'Message blocked by carrier',
    '30005' => 'Unknown destination handset',
    '30006' => 'Landline or unreachable carrier',
    '30007' => 'Carrier violation / spam filter',
    '30008' => 'Unknown carrier error',
    '21211' => 'Invalid phone number',
    '21610' => 'Recipient previously unsubscribed',
    '21614' => 'Number cannot receive SMS',
];
?>

<!-- Stylesheets -->
<link rel="stylesheet" href="<?= htmlspecialchars($module->getUrl('style.css')) ?>">

<div class="twilio-logs-container py-3 me-3 pe-2" style="margin-right: 20px;">

    <!-- PHI Warning Modal Overlay (First-time user consent) -->
    <?php if (!$hasAcknowledgedPhi): ?>
    <div id="phiWarningModal" class="phi-warning-overlay">
        <div class="phi-warning-card">
            <div class="p-4 bg-danger text-white d-flex align-items-center">
                <i class="fas fa-shield-alt fa-2x me-3"></i>
                <div>
                    <h5 class="mb-0 fw-bold">PHI Notice</h5>
                    <small>User Access Acknowledgment Required</small>
                </div>
            </div>
            <div class="p-4">
                <p class="text-secondary mb-3">
                    This page displays Twilio communication logs for this project, including 
                    <strong>participant phone numbers</strong> and the <strong>text content of inbound SMS messages</strong>.
                </p>
                <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                    <i class="fas fa-exclamation-triangle fa-2x me-3 flex-shrink-0"></i>
                    <div>
                        Inbound messages sent by participants may contain sensitive personal or health information. 
                        By proceeding, you acknowledge that you are authorized to access participant data for this project.
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                    <a href="<?= APP_PATH_WEBROOT ?>index.php?pid=<?= $project_id ?>" class="btn btn-outline-secondary text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Return to Project
                    </a>
                    <button type="button" id="btnAckPhi" class="btn btn-danger">
                        <i class="fas fa-check me-1"></i> I Understand & Acknowledge
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="d-flex flex-wrap align-items-center justify-content-between pb-3 mb-4 border-bottom gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 shadow-sm d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <i class="fas fa-sms fa-lg"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0 text-dark">Better Twilio Logs</h3>
                <span class="text-muted small">
                    Monitor inbound participant replies, work failed outbound deliveries, and handle opt-outs.
                </span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <div class="text-end d-none d-md-block me-2">
                <small class="text-muted d-block">Last Twilio Sync</small>
                <span class="badge bg-light text-dark border fw-normal" id="lastSyncBadge">
                    <?= htmlspecialchars($lastFetchDatetime) ?>
                </span>
            </div>
            <?php if ($isTwilioConfigured): ?>
            <button type="button" id="btnSyncTwilio" class="btn btn-primary shadow-sm">
                <i class="fas fa-sync-alt me-1"></i> Sync Now
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isTwilioConfigured): ?>
    <!-- Twilio Not Configured Alert -->
    <div class="card border-warning mb-4 shadow-sm" style="border-radius: 10px;">
        <div class="card-body p-4 d-flex align-items-start gap-3">
            <div class="text-warning fs-1"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <h5 class="fw-bold text-dark mb-1">Twilio Integration Not Configured</h5>
                <p class="text-secondary mb-2">
                    Twilio is either not enabled or missing credentials for this project. To fetch SMS logs, configure your Twilio Account SID, Auth Token, and From Number in project settings.
                </p>
                <a href="<?= APP_PATH_WEBROOT ?>ProjectSetup/index.php?pid=<?= $project_id ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-cog me-1"></i> Go to Project Setup
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Metric Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="twilio-metric-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Open Tasks</span>
                    <h3 class="fw-bold mb-0 text-danger" id="metricOpenTasks">0</h3>
                </div>
                <div class="twilio-metric-icon metric-open">
                    <i class="fas fa-tasks"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="twilio-metric-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">STOP Requests</span>
                    <h3 class="fw-bold mb-0 text-warning" id="metricStopCount">0</h3>
                </div>
                <div class="twilio-metric-icon metric-stop">
                    <i class="fas fa-user-slash"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="twilio-metric-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Failed Deliveries</span>
                    <h3 class="fw-bold mb-0 text-danger" id="metricFailedCount">0</h3>
                </div>
                <div class="twilio-metric-icon metric-failed">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="twilio-metric-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Inbound Replies</span>
                    <h3 class="fw-bold mb-0 text-primary" id="metricInboundCount">0</h3>
                </div>
                <div class="twilio-metric-icon metric-inbound">
                    <i class="fas fa-inbox"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 10px;">
        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- Status Filter -->
                <div class="btn-group btn-group-sm filter-btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary filter-status-btn active" data-status="all">All Status</button>
                    <button type="button" class="btn btn-outline-danger filter-status-btn" data-status="open">Open</button>
                    <button type="button" class="btn btn-outline-success filter-status-btn" data-status="resolved">Resolved</button>
                </div>

                <!-- Type Filter -->
                <div class="btn-group btn-group-sm filter-btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary filter-type-btn active" data-type="all">All Types</button>
                    <button type="button" class="btn btn-outline-primary filter-type-btn" data-type="inbound">Inbound</button>
                    <button type="button" class="btn btn-outline-warning filter-type-btn" data-type="stop">STOP</button>
                    <button type="button" class="btn btn-outline-danger filter-type-btn" data-type="failed">Failed Outbound</button>
                </div>

                <!-- Matched Record Filter -->
                <div class="btn-group btn-group-sm filter-btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary filter-match-btn active" data-match="all">All Records</button>
                    <button type="button" class="btn btn-outline-primary filter-match-btn" data-match="matched">
                        <i class="fas fa-link me-1"></i>Matched Only
                    </button>
                </div>
            </div>

            <!-- Search box -->
            <div class="d-flex align-items-center gap-2" style="min-width: 280px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="twilioSearchInput" class="form-control border-start-0" placeholder="Search issues...">
                </div>
                <span class="badge bg-secondary" id="visibleCountBadge"><?= count($logs) ?></span>
            </div>
        </div>
    </div>

    <!-- Logs & Tasks Table -->
    <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden;">
        <div class="table-responsive">
            <table class="table table-twilio-logs table-hover align-middle mb-0" id="twilioLogsTable">
                <thead>
                    <tr>
                        <th class="sortable-header" data-sort="status" style="width: 110px;">
                            Status <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="timestamp" style="width: 160px;">
                            Date & Time <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="type" style="width: 140px;">
                            Type <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="phone" style="width: 170px;">
                            Participant Phone <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="record" style="width: 140px;">
                            Matched Record <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="message">
                            Message Content / Error Detail <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th class="sortable-header" data-sort="notes" style="width: 180px;">
                            Notes <i class="fas fa-sort sort-icon ms-1"></i>
                        </th>
                        <th style="width: 80px;" class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr id="initialEmptyRow">
                        <td colspan="8" class="empty-state-cell p-0">
                            <div class="empty-state-wrapper">
                                <i class="fas fa-comments fa-3x mb-3 text-secondary opacity-50"></i>
                                <h6 class="fw-semibold text-secondary mb-1">No Twilio logs found for this project.</h6>
                                <small class="text-muted">Click "Sync Twilio Now" to check for recent messages.</small>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): 
                        $logId = (string)($log['log_id'] ?? '');
                        $task = $resolutions[$logId] ?? ['status' => 'open', 'notes' => '', 'updated_by' => '', 'updated_at' => ''];
                        $taskStatus = ($task['status'] === 'resolved') ? 'resolved' : 'open';
                        $notes = $task['notes'] ?? '';

                        // Determine message type & participant phone
                        $isStop = !empty($log['is_stop']);
                        $direction = $log['direction'] ?? '';
                        $statusRaw = strtoupper($log['status'] ?? '');
                        $isFailed = ($direction === 'outbound' || in_array($statusRaw, ['FAILED', 'UNDELIVERED']));

                        if ($isStop) {
                            $typeKey = 'stop';
                            $typeBadge = '<span class="badge badge-type-stop"><i class="fas fa-user-slash me-1"></i>STOP</span>';
                            $participantPhone = $log['from'] ?? '';
                        } elseif ($isFailed) {
                            $typeKey = 'failed';
                            $typeBadge = '<span class="badge badge-type-failed"><i class="fas fa-times-circle me-1"></i>Failed Out</span>';
                            $participantPhone = $log['to'] ?? '';
                        } else {
                            $typeKey = 'inbound';
                            $typeBadge = '<span class="badge badge-type-inbound"><i class="fas fa-reply me-1"></i>Inbound</span>';
                            $participantPhone = $log['from'] ?? '';
                        }

                        $cleanedPhone = BetterTwilioLogs::cleanPhoneNumber($participantPhone);
                        $formattedPhone = BetterTwilioLogs::formatPhoneNumber($participantPhone);

                        // Record matching
                        $matchedRecord = $phoneMap[$cleanedPhone] ?? null;
                        $recordId = $matchedRecord['record_id'] ?? null;

                        // Sorting metadata values
                        $rawDateSent = $log['date_sent'] ?? $log['timestamp'] ?? '';
                        $timestampVal = !empty($rawDateSent) ? strtotime($rawDateSent) : 0;
                        $messageSortVal = strtolower(trim(($isFailed ? ($log['error_code'] ?? '') . ' ' : '') . ($log['body'] ?? '')));

                        // Build searchable text
                        $searchableText = strtolower(implode(' ', [
                            $participantPhone,
                            $cleanedPhone,
                            $recordId ?: '',
                            $log['body'] ?? '',
                            $log['error_code'] ?? '',
                            $log['error_message'] ?? '',
                            $notes,
                            $taskStatus,
                            $typeKey
                        ]));
                    ?>
                    <tr class="log-row" 
                        data-log-id="<?= htmlspecialchars($logId) ?>"
                        data-status="<?= htmlspecialchars($taskStatus) ?>"
                        data-timestamp="<?= $timestampVal ?>"
                        data-type="<?= htmlspecialchars($typeKey) ?>"
                        data-phone="<?= htmlspecialchars($cleanedPhone) ?>"
                        data-record="<?= htmlspecialchars($recordId ?? '') ?>"
                        data-message="<?= htmlspecialchars($messageSortVal) ?>"
                        data-notes="<?= htmlspecialchars($notes) ?>"
                        data-searchable="<?= htmlspecialchars($searchableText) ?>">
                        
                        <!-- Status Badge -->
                        <td class="status-badge-cell">
                            <?php if ($taskStatus === 'resolved'): ?>
                                <span class="badge rounded-pill badge-task-resolved">
                                    <i class="fas fa-check-circle me-1"></i>Resolved
                                </span>
                            <?php else: ?>
                                <span class="badge rounded-pill badge-task-open">
                                    <i class="fas fa-exclamation-circle me-1"></i>Open
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Date & Time -->
                        <td>
                            <div class="fw-semibold text-dark" style="font-size: 0.88rem;">
                                <?= htmlspecialchars(date('M d, Y', strtotime($log['date_sent'] ?? $log['timestamp']))) ?>
                            </div>
                            <small class="text-muted">
                                <?= htmlspecialchars(date('g:i A', strtotime($log['date_sent'] ?? $log['timestamp']))) ?>
                            </small>
                        </td>

                        <!-- Type Badge -->
                        <td><?= $typeBadge ?></td>

                        <!-- Participant Phone -->
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-semibold font-monospace" style="font-size: 0.9rem;">
                                    <?= htmlspecialchars($formattedPhone) ?>
                                </span>
                                <?php if (!empty($participantPhone)): ?>
                                <button type="button" class="btn btn-link copy-phone-btn p-0" 
                                        onclick="ExternalModules.UWMadison.BetterTwilioLogs.copyToClipboard('<?= htmlspecialchars($participantPhone) ?>', this)" 
                                        title="Copy phone number">
                                    <i class="far fa-copy"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Matched Record ID -->
                        <td>
                            <?php if ($recordId !== null): ?>
                                <?php $matchField = $matchedRecord['field_name'] ?? ''; ?>
                                <a href="<?= APP_PATH_WEBROOT ?>DataEntry/record_home.php?pid=<?= $project_id ?>&arm=1&id=<?= urlencode($recordId) ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" 
                                   title="Record <?= htmlspecialchars($recordId) ?><?= !empty($matchField) ? ' (matched on ' . htmlspecialchars($matchField) . ')' : '' ?>">
                                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($recordId) ?>
                                    <i class="fas fa-external-link-alt ms-1 text-muted" style="font-size: 0.72rem;"></i>
                                </a>
                                <?php if (!empty($matchField)): ?>
                                    <small class="text-muted d-block font-monospace" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($matchField) ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border fw-normal">No Match</span>
                            <?php endif; ?>
                        </td>

                        <!-- Message Content / Error Detail -->
                        <td>
                            <?php if ($isFailed): ?>
                                <?php 
                                    $errCode = $log['error_code'] ?? '';
                                    $errMsg = $log['error_message'] ?? '';
                                    $errDesc = $knownErrors[$errCode] ?? $errMsg;
                                ?>
                                <div class="text-danger fw-semibold d-flex align-items-center gap-1 mb-1">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Error <?= htmlspecialchars($errCode ?: 'Failed') ?></span>
                                    <?php if (!empty($errDesc)): ?>
                                        <span class="text-muted fw-normal">&mdash; <?= htmlspecialchars($errDesc) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($log['body'])): ?>
                                <div class="message-body-box text-muted small bg-light p-2 rounded border">
                                    <?= nl2br(htmlspecialchars($log['body'])) ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="message-body-box">
                                    <?= nl2br(htmlspecialchars($log['body'] ?? '')) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Resolution Notes -->
                        <td class="notes-cell">
                            <div class="notes-preview" title="<?= htmlspecialchars($notes) ?>">
                                <?= !empty($notes) ? htmlspecialchars($notes) : '<span class="text-muted opacity-75">No notes</span>' ?>
                            </div>
                            <small class="text-muted d-block task-audit-trail" style="font-size: 0.75rem; <?= empty($task['updated_by']) ? 'display: none;' : '' ?>">
                                <?= !empty($task['updated_by']) ? htmlspecialchars($task['updated_by']) . ' &bull; ' . htmlspecialchars(date('m/d/y', strtotime($task['updated_at']))) : '' ?>
                            </small>
                        </td>

                        <!-- Action Buttons -->
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <?php if ($taskStatus === 'resolved'): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-toggle-status py-1 px-2"
                                        onclick="ExternalModules.UWMadison.BetterTwilioLogs.toggleStatus('<?= htmlspecialchars($logId) ?>', 'open')"
                                        title="Reopen this task">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-success btn-toggle-status py-1 px-2"
                                        onclick="ExternalModules.UWMadison.BetterTwilioLogs.toggleStatus('<?= htmlspecialchars($logId) ?>', 'resolved')"
                                        title="Mark task as resolved">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php endif; ?>

                                <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2"
                                        onclick="ExternalModules.UWMadison.BetterTwilioLogs.openNotesModal('<?= htmlspecialchars($logId) ?>')"
                                        title="Add or edit notes">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <tr id="noLogsRow" style="display: none;">
                        <td colspan="8" class="empty-state-cell p-0">
                            <div class="empty-state-wrapper">
                                <i class="fas fa-filter fa-3x mb-3 text-secondary opacity-50"></i>
                                <h6 class="fw-semibold text-secondary mb-1">No logs match your filter criteria</h6>
                                <small class="text-muted">Try adjusting your status, type, or search filters.</small>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Card Footer with Pagination Controls -->
        <div class="card-footer bg-white border-top py-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <span class="text-muted small">Show</span>
                <input type="number" id="recordsPerPageInput" class="form-control form-control-sm text-center" 
                       style="width: 70px;" min="1" value="<?= $initialPerPage ?>" title="Entries per page">
                <span class="text-muted small">per page</span>
                <span class="text-muted small ms-2 border-start ps-3" id="paginationInfo">
                    Showing 0 of 0 entries
                </span>
            </div>
            <nav aria-label="Logs pagination" id="paginationNav">
                <ul class="pagination pagination-sm mb-0" id="paginationList">
                </ul>
            </nav>
        </div>
    </div>
</div>

<!-- Task Notes & Status Modal -->
<div class="modal fade" id="taskNotesModal" tabindex="-1" aria-labelledby="taskNotesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold" id="taskNotesModalLabel">
                    <i class="fas fa-clipboard-list text-primary me-2"></i>Task Resolution & Notes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="modalLogId" value="">
                
                <div class="mb-3">
                    <label for="modalTaskStatus" class="form-label fw-semibold text-dark">Task Status</label>
                    <select id="modalTaskStatus" class="form-select">
                        <option value="open">Open (Action Needed)</option>
                        <option value="resolved">Resolved (Completed / Dismissed)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="modalTaskNotes" class="form-label fw-semibold text-dark">Resolution Notes / Action Taken</label>
                    <textarea id="modalTaskNotes" class="form-control" rows="4" 
                              placeholder="e.g. Spoke with participant on alternate number; opted back in; corrected digit in record..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light border-top">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="ExternalModules.UWMadison.BetterTwilioLogs.saveNotes()">
                    <i class="fas fa-save me-1"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Javascript -->
<script>
    ExternalModules.UWMadison.BetterTwilioLogs.currentUsername = <?= json_encode($user_id) ?>;
</script>
<script src="<?= htmlspecialchars($module->getUrl('main.js')) ?>"></script>