<?php

namespace UWMadison\BetterTwilioLogs;

use ExternalModules\AbstractExternalModule;
use Generator;
use RuntimeException;
use REDCap;

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
                AND ( inactive_time = ''
                        OR inactive_time IS NULL )
                AND ( completed_time = ''
                        OR completed_time IS NULL )
                AND Length(twilio_account_sid) = 34
                AND Length(twilio_auth_token) = 32 
                AND project_id = ?
    ";

    /**
     * Hook triggered when module is enabled or updated at the system level.
     * Sets default system settings if not already defined.
     *
     * @param string $version
     * @return void
     */
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

    /**
     * Hook triggered when module is enabled on a specific project.
     * Sets default project settings if not already defined.
     *
     * @param string $version
     * @param int|string $project_id
     * @return void
     */
    public function redcap_module_project_enable($version, $project_id)
    {
        $pid = (int)$project_id;
        $lookback = $this->getProjectSetting('default-lookback-days', $pid);
        if ($lookback === null || $lookback === '') {
            $this->setProjectSetting('default-lookback-days', '1', $pid);
        }
    }

    /**
     * Handle REDCap External Module AJAX requests.
     * Actions are registered in config.json under auth-ajax-actions.
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        $projectId = (int)$project_id;
        $username = defined('USERID') ? USERID : '';
        $payload = is_array($payload) ? $payload : [];

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
                $syncResult = $this->syncProjectLogs($projectId, $customLookback);
                return $syncResult;

            case 'getLogs':
                $logs = $this->getProjectLogs($projectId);
                $resolutions = $this->getTaskResolutions($projectId);
                $phoneMap = $this->getPhoneToRecordMap($projectId);

                return [
                    'success'     => true,
                    'logs'        => $logs,
                    'resolutions' => $resolutions,
                    'phone_map'   => $phoneMap
                ];

            default:
                return [
                    'success' => false,
                    'message' => "Unknown AJAX action: {$action}"
                ];
        }
    }

    /**
     * Cron entry point to fetch Twilio logs across enabled projects.
     * Fair scheduling: prioritizes projects that haven't been fetched in the longest time.
     *
     * @param array $config Cron configuration from config.json
     * @return string Status message
     */
    public function fetchLogs(array $config = []): string
    {
        $enabledProjects = $this->getProjectsWithModuleEnabled();
        if (empty($enabledProjects)) {
            return "No projects have Twilio Logs enabled.";
        }

        // Build list of valid candidate projects with their last_fetch_time
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

        // Sort ascending by last_fetch (projects never fetched or fetched longest ago come first)
        usort($candidates, function ($a, $b) {
            return $a['last_fetch'] <=> $b['last_fetch'];
        });

        // Limit to max projects per cron run
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

    /**
     * Synchronize Twilio messages for a specific project.
     * Can be invoked from cron or on-demand via AJAX.
     *
     * @param int $projectId
     * @param int|null $customLookbackDays Optional override for lookback window
     * @return array Result summary with counts and timestamps
     */
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

        // Determine lookback start timestamp
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

        // Twilio DateSent filter expects YYYY-MM-DD
        $dateFilter = gmdate('Y-m-d', $startTimestamp);

        $maxBatch = (int)$this->getSystemSetting('max-messages-per-fetch');
        if ($maxBatch <= 0) {
            $maxBatch = 100;
        }

        // Preload existing SIDs for this project to eliminate duplicates with O(1) checks
        $existingSids = $this->getExistingLoggedSids($projectId);

        $inboundCount = 0;
        $outboundCount = 0;

        try {
            // 1. Fetch inbound messages sent TO the project's Twilio number
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

            // 2. Fetch outbound failed/undelivered messages sent FROM the project's Twilio number
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

            // Update last fetch timestamp for this project
            $now = time();
            $this->setProjectSetting('last_fetch_time', $now, $projectId);
            $this->setProjectSetting('last_fetch_datetime', date('Y-m-d H:i:s', $now), $projectId);

            return [
                'success'        => true,
                'inbound_count'  => $inboundCount,
                'outbound_count' => $outboundCount,
                'last_fetch'     => date('Y-m-d H:i:s', $now)
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Retrieve all message SIDs already logged for this project.
     *
     * @param int $projectId
     * @return array<string, bool> Map of SID => true
     */
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

    /**
     * Retrieve all Twilio logs stored for the current project.
     *
     * @param int $projectId
     * @param int $limit
     * @return array
     */
    public function getProjectLogs(int $projectId, int $limit = 500): array
    {
        $this->setProjectId($projectId);
        $limitInt = max(1, (int)$limit);
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
            LIMIT {$limitInt}
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

    /**
     * Clean and normalize a phone number to standard digit string.
     * Strips non-digits, and removes leading 1 for 11-digit US numbers.
     *
     * @param string $phone
     * @return string
     */
    public static function cleanPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return (string)$digits;
    }

    /**
     * Format a cleaned 10-digit number for human display.
     *
     * @param string $phone
     * @return string
     */
    public static function formatPhoneNumber(string $phone): string
    {
        $cleaned = self::cleanPhoneNumber($phone);
        if (strlen($cleaned) === 10) {
            return sprintf("(%s) %s-%s", substr($cleaned, 0, 3), substr($cleaned, 3, 3), substr($cleaned, 6));
        }
        return $phone;
    }

    /**
     * Get the default field configured for SMS texting in REDCap project setup.
     *
     * @param int $projectId
     * @return string
     */
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

    /**
     * Build an in-memory map of normalized phone numbers to REDCap record IDs.
     * Uses the designated SMS texting field by default, combined with any
     * additional phone fields configured in module settings.
     *
     * @param int $projectId
     * @return array<string, array> Map of cleaned phone => ['record_id' => string, 'field_name' => string, 'is_designated' => bool]
     */
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

        // If neither designated SMS field nor additional fields are configured, auto-detect phone-validated fields
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

        // Pull data for the record ID and phone fields
        $fieldsToPull = array_unique(array_merge([$recordIdField], $fields));
        $data = REDCap::getData($projectId, 'array', null, $fieldsToPull);

        $map = [];
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
                                        // Store if not yet present, or overwrite non-designated match with designated SMS field match
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

        $cache[$projectId] = $map;
        return $map;
    }

    /**
     * Retrieve stored task resolutions for a project.
     *
     * @param int $projectId
     * @return array<int|string, array> Map of log_id => resolution metadata
     */
    public function getTaskResolutions(int $projectId): array
    {
        $res = $this->getProjectSetting('task_resolutions', $projectId);
        return is_array($res) ? $res : [];
    }

    /**
     * Update resolution status and notes for a specific log item.
     *
     * @param int $projectId
     * @param string|int $logId
     * @param string $status 'open' or 'resolved'
     * @param string $notes
     * @param string $username
     * @return array The updated task entry
     */
    public function setTaskResolution(int $projectId, $logId, string $status, string $notes, string $username): array
    {
        $resolutions = $this->getTaskResolutions($projectId);
        $logIdKey = (string)$logId;

        $task = [
            'status'     => ($status === 'resolved') ? 'resolved' : 'open',
            'notes'      => trim($notes),
            'updated_by' => $username ?: 'unknown',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $resolutions[$logIdKey] = $task;
        $this->setProjectSetting('task_resolutions', $resolutions, $projectId);
        return $task;
    }

    /**
     * Check if user has acknowledged the PHI warning for this project.
     *
     * @param int $projectId
     * @param string $username
     * @return bool
     */
    public function hasUserAcknowledgedPhi(int $projectId, string $username): bool
    {
        if (empty($username)) {
            return false;
        }
        $ack = $this->getProjectSetting('phi_ack_' . $username, $projectId);
        return !empty($ack);
    }

    /**
     * Record user acknowledgment of the PHI warning.
     *
     * @param int $projectId
     * @param string $username
     * @return void
     */
    public function setUserAcknowledgedPhi(int $projectId, string $username): void
    {
        if (!empty($username)) {
            $this->setProjectSetting('phi_ack_' . $username, date('Y-m-d H:i:s'), $projectId);
        }
    }

    /**
     * Generator that yields message records page-by-page from Twilio's REST API.
     *
     * @param string $sid Account SID
     * @param string $token Auth Token
     * @param array<string, mixed> $params Query parameters (e.g. To, From, DateSent>=, PageSize)
     * @return Generator<array<string, mixed>>
     */
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

            // Stream individual messages from current page
            foreach ($data['messages'] ?? [] as $message) {
                yield $message;
            }

            // Handle pagination: Twilio returns a relative URI for the next page, or null
            if (!empty($data['next_page_uri'])) {
                $url = 'https://api.twilio.com' . $data['next_page_uri'];
            } else {
                $url = null;
            }
        }
    }
}
