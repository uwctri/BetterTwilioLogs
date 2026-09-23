<?php

namespace UWMadison\BetterTwilioLogs;

use ExternalModules\AbstractExternalModule;
use Generator;
use REDCap;
use RuntimeException;
use Throwable;

class BetterTwilioLogs extends AbstractExternalModule
{
    // Keywords and status filters
    public const STOP_KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    public const ERROR_STATUS = ['FAILED', 'UNDELIVERED'];

    // Log titles
    public const LOG_TITLE_STOP_REQUEST = 'Received STOP request';
    public const LOG_TITLE_INBOUND = 'Received message from participant';
    public const LOG_TITLE_OUTBOUND_FAILED = 'Failed outbound message';

    // Default configuration values
    public const DEFAULT_MAX_PROJECTS_PER_CRON = 10;
    public const DEFAULT_MAX_MESSAGES_PER_FETCH = 100;
    public const DEFAULT_LOOKBACK_DAYS = 1;
    public const DEFAULT_PER_PAGE = 20;

    // Twilio API endpoints
    public const TWILIO_API_BASE_URL = 'https://api.twilio.com';
    public const TWILIO_API_VERSION_PATH = '/2010-04-01/Accounts';

    // SQL queries
    private const SQL_PROJECT_TWILIO_CREDS = "
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

    private const SQL_DESIGNATED_SMS_FIELD = "
            SELECT survey_phone_participant_field
            FROM redcap_projects
            WHERE project_id = ?
    ";

    private const SQL_METADATA_PHONE_FIELDS = "
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
    ";

    private const PSEUDO_SQL_EXISTING_SIDS = "
            SELECT message_sid
            WHERE message_sid IS NOT NULL
    ";

    private const PSEUDO_SQL_PROJECT_LOGS = "
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
    ";

    // System enable hook
    public function redcap_module_system_enable($version)
    {
        $maxProjects = $this->getSystemSetting('max-projects-per-cron');
        if (empty($maxProjects)) {
            $this->setSystemSetting('max-projects-per-cron', self::DEFAULT_MAX_PROJECTS_PER_CRON);
        }

        $maxBatch = $this->getSystemSetting('max-messages-per-fetch');
        if (empty($maxBatch)) {
            $this->setSystemSetting('max-messages-per-fetch', self::DEFAULT_MAX_MESSAGES_PER_FETCH);
        }
    }

    // Project enable hook
    public function redcap_module_project_enable($version, $project_id)
    {
        $project_id = (int)$project_id;
        $lookback = $this->getProjectSetting('default-lookback-days', $project_id);
        if (empty($lookback)) {
            $this->setProjectSetting('default-lookback-days', self::DEFAULT_LOOKBACK_DAYS, $project_id);
        }
    }

    // Handle AJAX actions
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        $project_id = (int)$project_id;
        $payload = is_array($payload) ? $payload : [];
        $user_id = (string)$user_id;

        switch ($action) {
            case 'acknowledgePhiWarning':
                $this->setUserAcknowledgedPhi($project_id, $user_id);
                return [
                    'success'   => true,
                    'timestamp' => date('Y-m-d H:i:s')
                ];

            case 'updateTaskStatus':
                $logId = $payload['log_id'];
                $status = $payload['status'] ?: 'open';
                $notes = (string)$payload['notes'];
                if (empty($logId)) {
                    return ['success' => false, 'message' => 'Missing log_id'];
                }
                $task = $this->setTaskResolution($project_id, $logId, $status, $notes, $user_id);
                return [
                    'success' => true,
                    'task'    => $task
                ];

            case 'syncNow':
                $customLookback = !empty($payload['lookback_days']) ? (int)$payload['lookback_days'] : null;
                $phase = !empty($payload['phase']) ? (string)$payload['phase'] : 'inbound';
                $nextPageUrl = !empty($payload['next_page_url']) ? (string)$payload['next_page_url'] : null;
                return $this->syncProjectLogsChunk($project_id, $customLookback, $phase, $nextPageUrl);

            case 'getLogs':
                return [
                    'success'     => true,
                    'logs'        => $this->getProjectLogs($project_id),
                    'resolutions' => $this->getTaskResolutions($project_id),
                    'phone_map'   => $this->getPhoneToRecordMap($project_id)
                ];

            case 'savePerPage':
                $perPage = (int)$payload['per_page'];
                if ($perPage > 0) {
                    $this->setUserPerPage($project_id, $user_id, $perPage);
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

    // Validate and filter Twilio Account SID format
    public static function filterAccountSid(string $sid): string
    {
        if (!preg_match('/^AC[a-zA-Z0-9]{32}$/', $sid)) {
            return '';
        }

        $clean = 'AC';
        for ($i = 2; $i < 34; $i++) {
            $clean .= chr(ord($sid[$i]));
        }

        return $clean;
    }

    // Cron job: sync Twilio logs across enabled projects
    public function fetchLogs(array $config = []): string
    {
        $enabledProjects = $this->getProjectsWithModuleEnabled();
        if (empty($enabledProjects)) {
            return "No projects have Twilio Logs enabled.";
        }

        $candidates = [];
        foreach ($enabledProjects as $project_id) {
            $project_id = (int)$project_id;
            $res = $this->query(self::SQL_PROJECT_TWILIO_CREDS, [$project_id]);
            if ($res && $res->num_rows > 0) {
                $lastFetch = $this->getProjectSetting('last_fetch_time', $project_id);
                $candidates[] = [
                    'project_id' => $project_id,
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
            $maxProjects = self::DEFAULT_MAX_PROJECTS_PER_CRON;
        }
        $toProcess = array_slice($candidates, 0, $maxProjects);

        $processedCount = 0;
        $totalInbound = 0;
        $totalOutbound = 0;

        foreach ($toProcess as $item) {
            $project_id = (int)$item['project_id'];
            $result = $this->syncProjectLogs($project_id);
            if ($result['success']) {
                $processedCount++;
                $totalInbound += $result['inbound_count'];
                $totalOutbound += $result['outbound_count'];
            }
        }

        $cronName = $config['cron_name'];
        return "The \"{$cronName}\" cron job processed {$processedCount} project(s): {$totalInbound} inbound and {$totalOutbound} outbound message(s) logged.";
    }

    // Sync all messages within lookback window for a project
    public function syncProjectLogs(int $project_id, ?int $customLookbackDays = null): array
    {
        $this->setProjectId($project_id);
        $results = $this->query(self::SQL_PROJECT_TWILIO_CREDS, [$project_id]);
        if (!$results || $results->num_rows === 0) {
            return [
                'success' => false,
                'message' => "Project {$project_id} does not have valid Twilio credentials or Twilio is not enabled."
            ];
        }

        $row = $results->fetch_assoc();
        $twilio_account_sid = self::filterAccountSid((string)$row['twilio_account_sid']);
        $twilio_auth_token = (string)$row['twilio_auth_token'];
        $twilio_from_number = (string)$row['twilio_from_number'];

        if ($twilio_account_sid === '') {
            return [
                'success' => false,
                'message' => "Project {$project_id} has an invalid Twilio Account SID format."
            ];
        }

        if ($customLookbackDays !== null && $customLookbackDays > 0) {
            $startTimestamp = strtotime("-{$customLookbackDays} days");
        } else {
            $lastFetch = $this->getProjectSetting('last_fetch_time', $project_id);
            if (!empty($lastFetch) && is_numeric($lastFetch)) {
                $startTimestamp = (int)$lastFetch;
            } else {
                $defaultDays = (int)$this->getProjectSetting('default-lookback-days', $project_id);
                if ($defaultDays <= 0) {
                    $defaultDays = self::DEFAULT_LOOKBACK_DAYS;
                }
                $startTimestamp = strtotime("-{$defaultDays} days");
            }
        }

        $dateFilter = gmdate('Y-m-d', $startTimestamp);
        $maxBatch = (int)$this->getSystemSetting('max-messages-per-fetch');
        if ($maxBatch <= 0) {
            $maxBatch = self::DEFAULT_MAX_MESSAGES_PER_FETCH;
        }

        $existingSids = $this->getExistingLoggedSids($project_id);
        $inboundCount = 0;
        $outboundCount = 0;

        try {
            $inboundParams = [
                'To'         => $twilio_from_number,
                'DateSent>=' => $dateFilter,
                'PageSize'   => min($maxBatch, 100)
            ];

            foreach ($this->fetchTwilioMessages($twilio_account_sid, $twilio_auth_token, $inboundParams) as $msg) {
                $sid = $msg['sid'];
                if (empty($sid) || isset($existingSids[$sid])) {
                    continue;
                }

                $cleanBody = trim((string)$msg['body']);
                $isStop = in_array(strtoupper($cleanBody), self::STOP_KEYWORDS, true);
                $logTitle = $isStop ? self::LOG_TITLE_STOP_REQUEST : self::LOG_TITLE_INBOUND;

                $this->log($logTitle, [
                    'project_id'    => $project_id,
                    'message_sid'   => $sid,
                    'direction'     => 'inbound',
                    'from'          => $msg['from'],
                    'to'            => $msg['to'],
                    'body'          => $cleanBody,
                    'status'        => $msg['status'],
                    'is_stop'       => $isStop ? 1 : 0,
                    'date_sent'     => $msg['date_sent'] ?: date('r'),
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
                $status = strtoupper((string)$msg['status']);
                if (!in_array($status, self::ERROR_STATUS, true)) {
                    continue;
                }

                $sid = $msg['sid'];
                if (empty($sid) || isset($existingSids[$sid])) {
                    continue;
                }

                $this->log(self::LOG_TITLE_OUTBOUND_FAILED, [
                    'project_id'    => $project_id,
                    'message_sid'   => $sid,
                    'direction'     => 'outbound',
                    'from'          => $msg['from'],
                    'to'            => $msg['to'],
                    'body'          => trim((string)$msg['body']),
                    'status'        => $msg['status'],
                    'is_stop'       => 0,
                    'date_sent'     => $msg['date_sent'] ?: date('r'),
                    'error_code'    => (string)($msg['error_code'] ?: 'None'),
                    'error_message' => (string)($msg['error_message'] ?: 'None')
                ]);

                $existingSids[$sid] = true;
                $outboundCount++;
                if ($outboundCount >= $maxBatch) {
                    break;
                }
            }

            $now = time();
            $this->setProjectSetting('last_fetch_time', $now, $project_id);
            $this->setProjectSetting('last_fetch_datetime', date('Y-m-d H:i:s', $now), $project_id);

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
    public function syncProjectLogsChunk(int $project_id, ?int $customLookbackDays = null, string $phase = 'inbound', ?string $nextPageUrl = null): array
    {
        $this->setProjectId($project_id);
        $results = $this->query(self::SQL_PROJECT_TWILIO_CREDS, [$project_id]);
        if (!$results || $results->num_rows === 0) {
            return [
                'success' => false,
                'message' => "Project #{$project_id} does not have valid Twilio credentials or Twilio is not enabled."
            ];
        }

        $row = $results->fetch_assoc();
        $twilio_account_sid = self::filterAccountSid((string)$row['twilio_account_sid']);
        $twilio_auth_token = (string)$row['twilio_auth_token'];
        $twilio_from_number = (string)$row['twilio_from_number'];

        if ($twilio_account_sid === '') {
            return [
                'success' => false,
                'message' => "Project #{$project_id} has an invalid Twilio Account SID format."
            ];
        }

        if ($customLookbackDays !== null && $customLookbackDays > 0) {
            $startTimestamp = strtotime("-{$customLookbackDays} days");
        } else {
            $defaultDays = (int)$this->getProjectSetting('default-lookback-days', $project_id);
            if ($defaultDays <= 0) {
                $defaultDays = self::DEFAULT_LOOKBACK_DAYS;
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
                    $url = self::TWILIO_API_BASE_URL . self::TWILIO_API_VERSION_PATH . "/{$twilio_account_sid}/Messages.json?" . http_build_query($params);
                }

                $pageData = $this->fetchTwilioPage($twilio_account_sid, $twilio_auth_token, $url);
                $messages = $pageData['messages'];
                $loggedCount = 0;

                $batchSids = array_filter(array_column($messages, 'sid'));
                $existingSids = $this->getExistingSidsInBatch($project_id, $batchSids);

                foreach ($messages as $msg) {
                    $sid = $msg['sid'];
                    if (empty($sid) || isset($existingSids[$sid])) {
                        continue;
                    }

                    $cleanBody = trim((string)$msg['body']);
                    $isStop = in_array(strtoupper($cleanBody), self::STOP_KEYWORDS, true);
                    $logTitle = $isStop ? self::LOG_TITLE_STOP_REQUEST : self::LOG_TITLE_INBOUND;

                    $this->log($logTitle, [
                        'project_id'    => $project_id,
                        'message_sid'   => $sid,
                        'direction'     => 'inbound',
                        'from'          => $msg['from'],
                        'to'            => $msg['to'],
                        'body'          => $cleanBody,
                        'status'        => $msg['status'],
                        'is_stop'       => $isStop ? 1 : 0,
                        'date_sent'     => $msg['date_sent'] ?: date('r'),
                        'error_code'    => '',
                        'error_message' => ''
                    ]);

                    $existingSids[$sid] = true;
                    $loggedCount++;
                }

                $nextPageUri = $pageData['next_page_uri'];
                if (!empty($nextPageUri)) {
                    return [
                        'success'       => true,
                        'has_more'      => true,
                        'phase'         => 'inbound',
                        'next_page_url' => self::TWILIO_API_BASE_URL . $nextPageUri,
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
                    $url = self::TWILIO_API_BASE_URL . self::TWILIO_API_VERSION_PATH . "/{$twilio_account_sid}/Messages.json?" . http_build_query($params);
                }

                $pageData = $this->fetchTwilioPage($twilio_account_sid, $twilio_auth_token, $url);
                $messages = $pageData['messages'];
                $loggedCount = 0;

                $batchSids = array_filter(array_column($messages, 'sid'));
                $existingSids = $this->getExistingSidsInBatch($project_id, $batchSids);

                foreach ($messages as $msg) {
                    $status = strtoupper((string)$msg['status']);
                    if (!in_array($status, self::ERROR_STATUS, true)) {
                        continue;
                    }

                    $sid = $msg['sid'];
                    if (empty($sid) || isset($existingSids[$sid])) {
                        continue;
                    }

                    $this->log(self::LOG_TITLE_OUTBOUND_FAILED, [
                        'project_id'    => $project_id,
                        'message_sid'   => $sid,
                        'direction'     => 'outbound',
                        'from'          => $msg['from'],
                        'to'            => $msg['to'],
                        'body'          => trim((string)$msg['body']),
                        'status'        => $msg['status'],
                        'is_stop'       => 0,
                        'date_sent'     => $msg['date_sent'] ?: date('r'),
                        'error_code'    => (string)($msg['error_code'] ?: 'None'),
                        'error_message' => (string)($msg['error_message'] ?: 'None')
                    ]);

                    $existingSids[$sid] = true;
                    $loggedCount++;
                }

                $nextPageUri = $pageData['next_page_uri'];
                if (!empty($nextPageUri)) {
                    return [
                        'success'       => true,
                        'has_more'      => true,
                        'phase'         => 'outbound',
                        'next_page_url' => self::TWILIO_API_BASE_URL . $nextPageUri,
                        'batch_logged'  => $loggedCount
                    ];
                } else {
                    $now = time();
                    $this->setProjectSetting('last_fetch_time', $now, $project_id);
                    $this->setProjectSetting('last_fetch_datetime', date('Y-m-d H:i:s', $now), $project_id);

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
    public function getExistingLoggedSids(int $project_id): array
    {
        $this->setProjectId($project_id);
        $sids = [];
        $result = $this->queryLogs(self::PSEUDO_SQL_EXISTING_SIDS, []);

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
    public function getExistingSidsInBatch(int $project_id, array $sids): array
    {
        $sids = array_values(array_filter(array_unique($sids)));
        if (empty($sids)) {
            return [];
        }

        $this->setProjectId($project_id);
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
            $all = $this->getExistingLoggedSids($project_id);
            foreach ($sids as $s) {
                if (isset($all[$s])) {
                    $existing[$s] = true;
                }
            }
        }

        return $existing;
    }

    // Retrieve all Twilio logs stored for the current project
    public function getProjectLogs(int $project_id, ?int $limit = null): array
    {
        $this->setProjectId($project_id);
        $pseudoSql = self::PSEUDO_SQL_PROJECT_LOGS;
        if ($limit !== null && $limit > 0) {
            $limitInt = (int)$limit;
            $pseudoSql .= " LIMIT {$limitInt}";
        }

        $logs = [];
        $result = $this->queryLogs($pseudoSql, []);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }

        return $logs;
    }

    // Get designated SMS field from project setup
    public function getDesignatedSmsField(int $project_id): string
    {
        $res = $this->query(self::SQL_DESIGNATED_SMS_FIELD, [$project_id]);

        if ($res && $row = $res->fetch_assoc()) {
            return trim((string)$row['survey_phone_participant_field']);
        }

        return '';
    }

    // Map phone numbers to record IDs
    public function getPhoneToRecordMap(int $project_id): array
    {
        static $cache = [];
        if (isset($cache[$project_id])) {
            return $cache[$project_id];
        }

        $recordIdField = $this->getRecordIdField($project_id);
        $designatedField = $this->getDesignatedSmsField($project_id);
        $additionalFields = $this->getProjectSetting('phone-fields', $project_id);

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
            $res = $this->query(self::SQL_METADATA_PHONE_FIELDS, [$project_id]);

            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $fields[] = $r['field_name'];
                }
            }
        }

        $fields = array_values(array_unique(array_filter($fields)));
        if (empty($fields)) {
            $cache[$project_id] = [];
            return [];
        }

        $dataTable = REDCap::getDataTable($project_id);
        $map = [];
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $params = array_merge([$project_id], $fields);

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
                    $clean = self::cleanPhoneNumber((string)$row['value']);
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
            $data = REDCap::getData($project_id, 'array', null, $fieldsToPull);
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

        $cache[$project_id] = $map;
        return $map;
    }

    // Retrieve stored task resolutions for a project
    public function getTaskResolutions(int $project_id): array
    {
        $resolutions = [];
        $legacy = $this->getProjectSetting('task_resolutions', $project_id);
        if (is_array($legacy)) {
            $resolutions = $legacy;
        }

        $allSettings = $this->getProjectSettings($project_id);
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
    public function setTaskResolution(int $project_id, $logId, string $status, string $notes, string $user_id): array
    {
        $logIdKey = (string)$logId;

        $task = [
            'status'     => ($status === 'resolved') ? 'resolved' : 'open',
            'notes'      => trim($notes),
            'updated_by' => trim($user_id),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->setProjectSetting('task_res_' . $logIdKey, $task, $project_id);

        $legacy = $this->getProjectSetting('task_resolutions', $project_id);
        if (is_array($legacy) && isset($legacy[$logIdKey])) {
            unset($legacy[$logIdKey]);
            $this->setProjectSetting('task_resolutions', $legacy, $project_id);
        }

        return $task;
    }

    // Check PHI acknowledgement
    public function hasUserAcknowledgedPhi(int $project_id, string $user_id): bool
    {
        $ack = $this->getProjectSetting('phi_ack_' . $user_id, $project_id);
        return !empty($ack);
    }

    // Record PHI acknowledgement
    public function setUserAcknowledgedPhi(int $project_id, string $user_id): void
    {
        $this->setProjectSetting('phi_ack_' . $user_id, date('Y-m-d H:i:s'), $project_id);
    }

    // Get user per-page preference
    public function getUserPerPage(int $project_id, string $user_id): int
    {
        $val = (int)$this->getProjectSetting('per_page_' . $user_id, $project_id);
        return ($val > 0) ? $val : self::DEFAULT_PER_PAGE;
    }

    // Save user per-page preference
    public function setUserPerPage(int $project_id, string $user_id, int $per_page): void
    {
        if ($per_page > 0) {
            $this->setProjectSetting('per_page_' . $user_id, $per_page, $project_id);
        }
    }

    private function fetchTwilioMessages(string $sid, string $token, array $params = []): Generator
    {
        $sid = self::filterAccountSid($sid);
        if ($sid === '') {
            throw new RuntimeException("Invalid Twilio Account SID format.");
        }

        $baseUrl = self::TWILIO_API_BASE_URL . self::TWILIO_API_VERSION_PATH . "/{$sid}/Messages.json";
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

            if ($curlError !== '') {
                throw new RuntimeException("cURL Network Error: {$curlError}");
            }

            $data = json_decode((string)$rawResponse, true);

            if ($httpCode >= 400) {
                $msg = $data['message'] ?? 'Unknown error';
                $code = $data['code'] ?? $httpCode;
                throw new RuntimeException("Twilio API Error [{$code}]: {$msg}");
            }

            foreach ($data['messages'] as $message) {
                yield $message;
            }

            $url = !empty($data['next_page_uri']) ? self::TWILIO_API_BASE_URL . $data['next_page_uri'] : null;
        }
    }

    private function fetchTwilioPage(string $sid, string $token, string $url): array
    {
        $sid = self::filterAccountSid($sid);
        if ($sid === '') {
            throw new RuntimeException("Invalid Twilio Account SID format.");
        }

        $expectedPrefix = self::TWILIO_API_BASE_URL . self::TWILIO_API_VERSION_PATH . "/{$sid}/Messages";
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
