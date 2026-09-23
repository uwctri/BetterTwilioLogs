<?php

namespace UWMadison\BetterTwilioLogs;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Generator;
use REDCap;
use RuntimeException;
use Throwable;

class BetterTwilioLogs extends AbstractExternalModule
{
    private $stopKeywords = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    private $errorStatus = ['FAILED', 'UNDELIVERED'];

    private $sql = "
            SELECT project_id,
                twilio_account_sid,
                twilio_auth_token,
                twilio_from_number
            FROM   redcap_projects
            WHERE  twilio_enabled = 1
                AND twilio_account_sid != ''
                AND twilio_auth_token != ''
                AND twilio_from_number != ''
                AND inactive_time IS NULL
                AND completed_time IS NULL
                AND Length(twilio_account_sid) = 34
                AND Length(twilio_auth_token) = 32 
                AND project_id = ?
    ";

    // System enable hook
    public function redcap_module_system_enable($version)
    {
        $maxProjects = $this->getSystemSetting('max-projects-per-cron');
        if ($maxProjects === null || $maxProjects === '') {
            $this->setSystemSetting('max-projects-per-cron', '10');
        }

        $maxBatch = $this->getSystemSetting('max-messages-per-fetch');
        if ($maxBatch === null || $maxBatch === '') {
            $this->setSystemSetting('max-messages-per-fetch', '100');
        }
    }

    // Project enable hook
    public function redcap_module_project_enable($version, $project_id)
    {
        $pid = (int)$project_id;
        $lookback = $this->getProjectSetting('default-lookback-days', $pid);
        if ($lookback === null || $lookback === '') {
            $this->setProjectSetting('default-lookback-days', '1', $pid);
        }
    }

    // Handle AJAX actions
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        $projectId = (int)$project_id;
        $payload = is_array($payload) ? $payload : [];
        $username = (string)($user_id ?: ExternalModules::getUsername() ?: ($this->getUser() ? $this->getUser()->getUsername() : (defined('USERID') ? USERID : ($payload['username'] ?? ''))));

        switch ($action) {
            case 'acknowledgePhiWarning':
                $this->setUserAcknowledgedPhi($projectId, $username);
                return [
                    'success'   => true,
                    'timestamp' => date('Y-m-d H:i:s')
                ];

            case 'updateTaskStatus':
                $logId = $payload['log_id'] ?? null;
                $status = $payload['status'] ?? 'open';
                $notes = $payload['notes'] ?? '';
                if (empty($logId)) {
                    return ['success' => false, 'message' => 'Missing log_id'];
                }
                $task = $this->setTaskResolution($projectId, $logId, $status, $notes, $username);
                return [
                    'success' => true,
                    'task'    => $task
                ];

            case 'syncNow':
                $customLookback = !empty($payload['lookback_days']) ? (int)$payload['lookback_days'] : null;
                $phase = !empty($payload['phase']) ? (string)$payload['phase'] : 'inbound';
                $nextPageUrl = !empty($payload['next_page_url']) ? (string)$payload['next_page_url'] : null;
                return $this->syncProjectLogsChunk($projectId, $customLookback, $phase, $nextPageUrl);

            case 'getLogs':
                return [
                    'success'     => true,
                    'logs'        => $this->getProjectLogs($projectId),
                    'resolutions' => $this->getTaskResolutions($projectId),
                    'phone_map'   => $this->getPhoneToRecordMap($projectId)
                ];

            case 'savePerPage':
                $perPage = (int)($payload['per_page'] ?? 20);
                if ($perPage > 0) {
                    $this->setUserPerPage($projectId, $username, $perPage);
                    return ['success' => true, 'per_page' => $perPage];
                }
                return ['success' => false, 'message' => 'Invalid per_page value'];

            default:
                return [
                    'success' => false,
                    'message' => "Unknown AJAX action: {$action}"
                ];
        }
    }

    // Cron job: sync Twilio logs across enabled projects
    public function fetchLogs(array $config = []): string
    {
        $enabledProjects = $this->getProjectsWithModuleEnabled();
        if (empty($enabledProjects)) {
            return "No projects have Twilio Logs enabled.";
        }

        $candidates = [];
        foreach ($enabledProjects as $pid) {
            $pid = (int)$pid;
            $res = $this->query($this->sql, [$pid]);
            if ($res && $res->num_rows > 0) {
                $lastFetch = $this->getProjectSetting('last_fetch_time', $pid);
                $candidates[] = [
                    'project_id' => $pid,
                    'last_fetch' => !empty($lastFetch) ? (int)$lastFetch : 0
                ];
            }
        }

        if (empty($candidates)) {
            return "No active projects with valid Twilio credentials found.";
        }

        usort($candidates, function ($a, $b) {
            return $a['last_fetch'] <=> $b['last_fetch'];
        });

        $maxProjects = (int)$this->getSystemSetting('max-projects-per-cron');
        if ($maxProjects <= 0) {
            $maxProjects = 10;
        }
        $toProcess = array_slice($candidates, 0, $maxProjects);

        $processedCount = 0;
        $totalInbound = 0;
        $totalOutbound = 0;

        foreach ($toProcess as $item) {
            $pid = $item['project_id'];
            $result = $this->syncProjectLogs($pid);
            if ($result['success']) {
                $processedCount++;
                $totalInbound += $result['inbound_count'] ?? 0;
                $totalOutbound += $result['outbound_count'] ?? 0;
            }
        }

        $cronName = $config['cron_name'] ?? 'FetchTwilioLogs';
        return "The \"{$cronName}\" cron job processed {$processedCount} project(s): {$totalInbound} inbound and {$totalOutbound} outbound message(s) logged.";
    }

    // Sync all messages within lookback window for a project
    public function syncProjectLogs(int $projectId, ?int $customLookbackDays = null): array
    {
        $this->setProjectId($projectId);
        $results = $this->query($this->sql, [$projectId]);
        if (!$results || $results->num_rows === 0) {
            return [
                'success' => false,
                'message' => "Project #{$projectId} does not have valid Twilio credentials or Twilio is not enabled."
            ];
        }

        $row = $results->fetch_assoc();
        $twilio_account_sid = $row['twilio_account_sid'];
        $twilio_auth_token = $row['twilio_auth_token'];
        $twilio_from_number = $row['twilio_from_number'];

        if ($customLookbackDays !== null && $customLookbackDays > 0) {
            $startTimestamp = strtotime("-{$customLookbackDays} days");
        } else {
            $lastFetch = $this->getProjectSetting('last_fetch_time', $projectId);
            if (!empty($lastFetch) && is_numeric($lastFetch)) {
                $startTimestamp = (int)$lastFetch;
            } else {
                $defaultDays = (int)$this->getProjectSetting('default-lookback-days', $projectId);
                if ($defaultDays <= 0) {
                    $defaultDays = 1;
                }
                $startTimestamp = strtotime("-{$defaultDays} days");
            }
        }

        $dateFilter = gmdate('Y-m-d', $startTimestamp);
        $maxBatch = (int)$this->getSystemSetting('max-messages-per-fetch');
        if ($maxBatch <= 0) {
            $maxBatch = 100;
        }

        $existingSids = $this->getExistingLoggedSids($projectId);
        $inboundCount = 0;
        $outboundCount = 0;

        try {
            $inboundParams = [
                'To'         => $twilio_from_number,
                'DateSent>=' => $dateFilter,
                'PageSize'   => min($maxBatch, 100)
            ];

            foreach ($this->fetchTwilioMessages($twilio_account_sid, $twilio_auth_token, $inboundParams) as $msg) {
                $sid = $msg['sid'] ?? '';
                if (empty($sid) || isset($existingSids[$sid])) {
                    continue;
                }

                $cleanBody = trim($msg['body'] ?? '');
                $isStop = in_array(strtoupper($cleanBody), $this->stopKeywords, true);
                $logTitle = $isStop ? "Received STOP request" : "Received message from participant";

                $this->log($logTitle, [
                    'project_id'    => $projectId,
                    'message_sid'   => $sid,
                    'direction'     => 'inbound',
                    'from'          => $msg['from'] ?? 'Unknown',
                    'to'            => $msg['to'] ?? $twilio_from_number,
                    'body'          => $cleanBody,
                    'status'        => $msg['status'] ?? 'received',
                    'is_stop'       => $isStop ? 1 : 0,
                    'date_sent'     => $msg['date_sent'] ?? date('r'),
                    'error_code'    => '',
                    'error_message' => ''
                ]);

                $existingSids[$sid] = true;
                $inboundCount++;
                if ($inboundCount >= $maxBatch) {
                    break;
                }
            }

            $outboundParams = [
                'From'       => $twilio_from_number,
                'DateSent>=' => $dateFilter,
                'PageSize'   => min($maxBatch, 100)
            ];

            foreach ($this->fetchTwilioMessages($twilio_account_sid, $twilio_auth_token, $outboundParams) as $msg) {
                $status = strtoupper($msg['status'] ?? '');
                if (!in_array($status, $this->errorStatus, true)) {
                    continue;
                }

                $sid = $msg['sid'] ?? '';
                if (empty($sid) || isset($existingSids[$sid])) {
                    continue;
                }

                $this->log("Failed outbound message", [
                    'project_id'    => $projectId,
                    'message_sid'   => $sid,
                    'direction'     => 'outbound',
                    'from'          => $msg['from'] ?? $twilio_from_number,
                    'to'            => $msg['to'] ?? 'Unknown',
                    'body'          => trim($msg['body'] ?? ''),
                    'status'        => $msg['status'] ?? 'failed',
                    'is_stop'       => 0,
                    'date_sent'     => $msg['date_sent'] ?? date('r'),
                    'error_code'    => $msg['error_code'] ?? 'None',
                    'error_message' => $msg['error_message'] ?? 'None'
                ]);

                $existingSids[$sid] = true;
                $outboundCount++;
                if ($outboundCount >= $maxBatch) {
                    break;
                }
            }

            $now = time();
            $this->setProjectSetting('last_fetch_time', $now, $projectId);
            $this->setProjectSetting('last_fetch_datetime', date('Y-m-d H:i:s', $now), $projectId);

            return [
                'success'        => true,
                'inbound_count'  => $inboundCount,
                'outbound_count' => $outboundCount,
                'last_fetch'     => date('Y-m-d H:i:s', $now)
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Sync a single page chunk of Twilio messages
    public function syncProjectLogsChunk(int $projectId, ?int $customLookbackDays = null, string $phase = 'inbound', ?string $nextPageUrl = null): array
    {
        $this->setProjectId($projectId);
        $results = $this->query($this->sql, [$projectId]);
        if (!$results || $results->num_rows === 0) {
            return [
                'success' => false,
                'message' => "Project #{$projectId} does not have valid Twilio credentials or Twilio is not enabled."
            ];
        }

        $row = $results->fetch_assoc();
        $twilio_account_sid = $row['twilio_account_sid'];
        $twilio_auth_token = $row['twilio_auth_token'];
        $twilio_from_number = $row['twilio_from_number'];

        if ($customLookbackDays !== null && $customLookbackDays > 0) {
            $startTimestamp = strtotime("-{$customLookbackDays} days");
        } else {
            $defaultDays = (int)$this->getProjectSetting('default-lookback-days', $projectId);
            if ($defaultDays <= 0) {
                $defaultDays = 1;
            }
            $startTimestamp = strtotime("-{$defaultDays} days");
        }

        $dateFilter = gmdate('Y-m-d', $startTimestamp);

        try {
            if ($phase === 'inbound') {
                if (!empty($nextPageUrl)) {
                    $url = $nextPageUrl;
                } else {
                    $params = [
                        'To'         => $twilio_from_number,
                        'DateSent>=' => $dateFilter,
                        'PageSize'   => 100
                    ];
                    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio_account_sid}/Messages.json?" . http_build_query($params);
                }

                $pageData = $this->fetchTwilioPage($twilio_account_sid, $twilio_auth_token, $url);
                $messages = $pageData['messages'] ?? [];
                $loggedCount = 0;

                $batchSids = array_filter(array_column($messages, 'sid'));
                $existingSids = $this->getExistingSidsInBatch($projectId, $batchSids);

                foreach ($messages as $msg) {
                    $sid = $msg['sid'] ?? '';
                    if (empty($sid) || isset($existingSids[$sid])) {
                        continue;
                    }

                    $cleanBody = trim($msg['body'] ?? '');
                    $isStop = in_array(strtoupper($cleanBody), $this->stopKeywords, true);
                    $logTitle = $isStop ? "Received STOP request" : "Received message from participant";

                    $this->log($logTitle, [
                        'project_id'    => $projectId,
                        'message_sid'   => $sid,
                        'direction'     => 'inbound',
                        'from'          => $msg['from'] ?? 'Unknown',
                        'to'            => $msg['to'] ?? $twilio_from_number,
                        'body'          => $cleanBody,
                        'status'        => $msg['status'] ?? 'received',
                        'is_stop'       => $isStop ? 1 : 0,
                        'date_sent'     => $msg['date_sent'] ?? date('r'),
                        'error_code'    => '',
                        'error_message' => ''
                    ]);

                    $existingSids[$sid] = true;
                    $loggedCount++;
                }

                $nextPageUri = $pageData['next_page_uri'] ?? null;
                if (!empty($nextPageUri)) {
                    return [
                        'success'       => true,
                        'has_more'      => true,
                        'phase'         => 'inbound',
                        'next_page_url' => 'https://api.twilio.com' . $nextPageUri,
                        'batch_logged'  => $loggedCount
                    ];
                } else {
                    return [
                        'success'       => true,
                        'has_more'      => true,
                        'phase'         => 'outbound',
                        'next_page_url' => null,
                        'batch_logged'  => $loggedCount
                    ];
                }
            } else {
                if (!empty($nextPageUrl)) {
                    $url = $nextPageUrl;
                } else {
                    $params = [
                        'From'       => $twilio_from_number,
                        'DateSent>=' => $dateFilter,
                        'PageSize'   => 100
                    ];
                    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio_account_sid}/Messages.json?" . http_build_query($params);
                }

                $pageData = $this->fetchTwilioPage($twilio_account_sid, $twilio_auth_token, $url);
                $messages = $pageData['messages'] ?? [];
                $loggedCount = 0;

                $batchSids = array_filter(array_column($messages, 'sid'));
                $existingSids = $this->getExistingSidsInBatch($projectId, $batchSids);

                foreach ($messages as $msg) {
                    $status = strtoupper($msg['status'] ?? '');
                    if (!in_array($status, $this->errorStatus, true)) {
                        continue;
                    }

                    $sid = $msg['sid'] ?? '';
                    if (empty($sid) || isset($existingSids[$sid])) {
                        continue;
                    }

                    $this->log("Failed outbound message", [
                        'project_id'    => $projectId,
                        'message_sid'   => $sid,
                        'direction'     => 'outbound',
                        'from'          => $msg['from'] ?? $twilio_from_number,
                        'to'            => $msg['to'] ?? 'Unknown',
                        'body'          => trim($msg['body'] ?? ''),
                        'status'        => $msg['status'] ?? 'failed',
                        'is_stop'       => 0,
                        'date_sent'     => $msg['date_sent'] ?? date('r'),
                        'error_code'    => $msg['error_code'] ?? 'None',
                        'error_message' => $msg['error_message'] ?? 'None'
                    ]);

                    $existingSids[$sid] = true;
                    $loggedCount++;
                }

                $nextPageUri = $pageData['next_page_uri'] ?? null;
                if (!empty($nextPageUri)) {
                    return [
                        'success'       => true,
                        'has_more'      => true,
                        'phase'         => 'outbound',
                        'next_page_url' => 'https://api.twilio.com' . $nextPageUri,
                        'batch_logged'  => $loggedCount
                    ];
                } else {
                    $now = time();
                    $this->setProjectSetting('last_fetch_time', $now, $projectId);
                    $this->setProjectSetting('last_fetch_datetime', date('Y-m-d H:i:s', $now), $projectId);

                    return [
                        'success'       => true,
                        'has_more'      => false,
                        'phase'         => 'done',
                        'batch_logged'  => $loggedCount,
                        'last_fetch'    => date('Y-m-d H:i:s', $now)
                    ];
                }
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Retrieve all message SIDs already logged for this project
    public function getExistingLoggedSids(int $projectId): array
    {
        $this->setProjectId($projectId);
        $sids = [];
        $result = $this->queryLogs("
            SELECT message_sid
            WHERE message_sid IS NOT NULL
        ", []);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['message_sid'])) {
                    $sids[$row['message_sid']] = true;
                }
            }
        }

        return $sids;
    }

    // Retrieve subset of message SIDs that already exist in project logs
    public function getExistingSidsInBatch(int $projectId, array $sids): array
    {
        $sids = array_values(array_filter(array_unique($sids)));
        if (empty($sids)) {
            return [];
        }

        $this->setProjectId($projectId);
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $pseudoSql = "
            SELECT message_sid
            WHERE message_sid IN ({$placeholders})
        ";

        $existing = [];
        try {
            $result = $this->queryLogs($pseudoSql, $sids);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (!empty($row['message_sid'])) {
                        $existing[$row['message_sid']] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            $all = $this->getExistingLoggedSids($projectId);
            foreach ($sids as $s) {
                if (isset($all[$s])) {
                    $existing[$s] = true;
                }
            }
        }

        return $existing;
    }

    // Retrieve all Twilio logs stored for the current project
    public function getProjectLogs(int $projectId, ?int $limit = null): array
    {
        $this->setProjectId($projectId);
        $limitClause = '';
        if ($limit !== null && $limit > 0) {
            $limitInt = (int)$limit;
            $limitClause = "LIMIT {$limitInt}";
        }

        $pseudoSql = "
            SELECT log_id,
                   timestamp,
                   message,
                   message_sid,
                   direction,
                   `from`,
                   `to`,
                   body,
                   status,
                   is_stop,
                   date_sent,
                   error_code,
                   error_message
            ORDER BY log_id DESC
            {$limitClause}
        ";

        $logs = [];
        $result = $this->queryLogs($pseudoSql, []);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }

        return $logs;
    }

    // Clean phone number to digits
    public static function cleanPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return (string)$digits;
    }

    // Format 10-digit number for display
    public static function formatPhoneNumber(string $phone): string
    {
        $cleaned = self::cleanPhoneNumber($phone);
        if (strlen($cleaned) === 10) {
            return sprintf("(%s) %s-%s", substr($cleaned, 0, 3), substr($cleaned, 3, 3), substr($cleaned, 6));
        }
        return $phone;
    }

    // Get designated SMS field from project setup
    public function getDesignatedSmsField(int $projectId): string
    {
        $res = $this->query("
            SELECT survey_phone_participant_field
            FROM redcap_projects
            WHERE project_id = ?
        ", [$projectId]);

        if ($res && $row = $res->fetch_assoc()) {
            return trim((string)($row['survey_phone_participant_field'] ?? ''));
        }

        return '';
    }

    // Map phone numbers to record IDs
    public function getPhoneToRecordMap(int $projectId): array
    {
        static $cache = [];
        if (isset($cache[$projectId])) {
            return $cache[$projectId];
        }

        $recordIdField = $this->getRecordIdField($projectId);
        $designatedField = $this->getDesignatedSmsField($projectId);
        $additionalFields = $this->getProjectSetting('phone-fields', $projectId);

        $fields = [];
        if (!empty($designatedField)) {
            $fields[] = $designatedField;
        }

        if (is_array($additionalFields)) {
            foreach ($additionalFields as $f) {
                $f = trim((string)$f);
                if (!empty($f) && !in_array($f, $fields, true)) {
                    $fields[] = $f;
                }
            }
        }

        if (empty($fields)) {
            $res = $this->query("
                SELECT field_name 
                FROM redcap_metadata 
                WHERE project_id = ? 
                  AND element_type = 'text'
                  AND (
                      element_validation_type LIKE 'phone%' 
                      OR field_name LIKE '%phone%' 
                      OR field_name LIKE '%mobile%' 
                      OR field_name LIKE '%cell%'
                  )
            ", [$projectId]);

            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $fields[] = $r['field_name'];
                }
            }
        }

        $fields = array_values(array_unique(array_filter($fields)));
        if (empty($fields)) {
            $cache[$projectId] = [];
            return [];
        }

        $dataTable = REDCap::getDataTable($projectId);
        $map = [];
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $params = array_merge([$projectId], $fields);

        try {
            $result = $this->query("
                SELECT record, field_name, value
                FROM {$dataTable}
                WHERE project_id = ?
                  AND field_name IN ({$placeholders})
                  AND value IS NOT NULL
                  AND value != ''
            ", $params);

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $clean = self::cleanPhoneNumber((string)($row['value'] ?? ''));
                    if (strlen($clean) >= 7) {
                        $fieldName = $row['field_name'];
                        $recordId = (string)$row['record'];
                        $isDesignated = (!empty($designatedField) && $fieldName === $designatedField);

                        if (!isset($map[$clean]) || $isDesignated) {
                            $map[$clean] = [
                                'record_id'     => $recordId,
                                'field_name'    => $fieldName,
                                'is_designated' => $isDesignated
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $fieldsToPull = array_unique(array_merge([$recordIdField], $fields));
            $data = REDCap::getData($projectId, 'array', null, $fieldsToPull);
            if (is_array($data)) {
                foreach ($data as $recordId => $recordEvents) {
                    foreach ($recordEvents as $eventId => $eventData) {
                        $instances = isset($eventData['repeat_instances']) 
                            ? $eventData['repeat_instances'] 
                            : [0 => [0 => $eventData]];

                        foreach ($instances as $instRows) {
                            foreach ($instRows as $row) {
                                foreach ($fields as $field) {
                                    if (!empty($row[$field])) {
                                        $clean = self::cleanPhoneNumber((string)$row[$field]);
                                        if (strlen($clean) >= 7) {
                                            $isDesignated = (!empty($designatedField) && $field === $designatedField);
                                            if (!isset($map[$clean]) || $isDesignated) {
                                                $map[$clean] = [
                                                    'record_id'     => (string)$recordId,
                                                    'field_name'    => $field,
                                                    'is_designated' => $isDesignated
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $cache[$projectId] = $map;
        return $map;
    }

    // Retrieve stored task resolutions for a project
    public function getTaskResolutions(int $projectId): array
    {
        $resolutions = [];
        $legacy = $this->getProjectSetting('task_resolutions', $projectId);
        if (is_array($legacy)) {
            $resolutions = $legacy;
        }

        $allSettings = $this->getProjectSettings($projectId);
        if (is_array($allSettings)) {
            foreach ($allSettings as $key => $val) {
                if (strpos($key, 'task_res_') === 0 && is_array($val)) {
                    $logId = substr($key, 9);
                    $resolutions[$logId] = $val;
                }
            }
        }

        return $resolutions;
    }

    // Update resolution status and notes for a specific log item
    public function setTaskResolution(int $projectId, $logId, string $status, string $notes, string $username = ''): array
    {
        $logIdKey = (string)$logId;
        $resolvedUser = trim($username);
        if (empty($resolvedUser) || $resolvedUser === 'unknown') {
            $resolvedUser = (string)(ExternalModules::getUsername() ?: ($this->getUser() ? $this->getUser()->getUsername() : (defined('USERID') ? USERID : 'unknown')));
        }

        $task = [
            'status'     => ($status === 'resolved') ? 'resolved' : 'open',
            'notes'      => trim($notes),
            'updated_by' => $resolvedUser,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->setProjectSetting('task_res_' . $logIdKey, $task, $projectId);

        $legacy = $this->getProjectSetting('task_resolutions', $projectId);
        if (is_array($legacy) && isset($legacy[$logIdKey])) {
            unset($legacy[$logIdKey]);
            $this->setProjectSetting('task_resolutions', $legacy, $projectId);
        }

        return $task;
    }

    // Check PHI acknowledgement
    public function hasUserAcknowledgedPhi(int $projectId, string $username): bool
    {
        if (empty($username)) {
            return false;
        }
        $ack = $this->getProjectSetting('phi_ack_' . $username, $projectId);
        return !empty($ack);
    }

    // Record PHI acknowledgement
    public function setUserAcknowledgedPhi(int $projectId, string $username): void
    {
        if (!empty($username)) {
            $this->setProjectSetting('phi_ack_' . $username, date('Y-m-d H:i:s'), $projectId);
        }
    }

    // Get user per-page preference
    public function getUserPerPage(int $projectId, string $username): int
    {
        if (empty($username)) {
            return 20;
        }
        $val = (int)$this->getProjectSetting('per_page_' . $username, $projectId);
        return ($val > 0) ? $val : 20;
    }

    // Save user per-page preference
    public function setUserPerPage(int $projectId, string $username, int $perPage): void
    {
        if (!empty($username) && $perPage > 0) {
            $this->setProjectSetting('per_page_' . $username, $perPage, $projectId);
        }
    }

    // Stream messages from Twilio API
    private function fetchTwilioMessages(string $sid, string $token, array $params = []): Generator
    {
        $baseUrl = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $url = $baseUrl . (!empty($params) ? '?' . http_build_query($params) : '');

        while ($url !== null) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "{$sid}:{$token}",
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $rawResponse = curl_exec($ch);
            $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError   = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                throw new RuntimeException("cURL Network Error: {$curlError}");
            }

            $data = json_decode((string)$rawResponse, true);

            if ($httpCode >= 400) {
                $msg = $data['message'] ?? 'Unknown error';
                $code = $data['code'] ?? $httpCode;
                throw new RuntimeException("Twilio API Error [{$code}]: {$msg}");
            }

            foreach ($data['messages'] ?? [] as $message) {
                yield $message;
            }

            $url = !empty($data['next_page_uri']) ? 'https://api.twilio.com' . $data['next_page_uri'] : null;
        }
    }

    // Fetch single page from Twilio API
    private function fetchTwilioPage(string $sid, string $token, string $url): array
    {
        $expectedPrefix = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages";
        if (strpos($url, $expectedPrefix) !== 0) {
            throw new RuntimeException("Invalid Twilio API URL target.");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$sid}:{$token}",
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new RuntimeException("cURL Network Error: {$curlError}");
        }

        $data = json_decode((string)$rawResponse, true);

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? 'Unknown error';
            $code = $data['code'] ?? $httpCode;
            throw new RuntimeException("Twilio API Error [{$code}]: {$msg}");
        }

        return is_array($data) ? $data : [];
    }
}
