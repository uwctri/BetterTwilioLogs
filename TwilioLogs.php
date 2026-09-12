<?php

namespace UWMadison\TwilioLogs;

use ExternalModules\AbstractExternalModule;
use Generator;
use RuntimeException;

// TODO - Twilio's API requires that we pull for a whole day, but we want to run several times a day, this means we need to check for duplicates and not log the same message twice. We can do this by checking the message SID against the log table before logging it.
// TODO - Crons always loop over all projects, we need to find the one that hasn't been fetched in the longest time and fetch that one first. We can do this by storing the last fetch time in the project settings table and then sorting by that value. Anytime we check we can update the last fetch time to the current time. This will ensure that we always fetch the project that hasn't been fetched in the longest time. We can also add a setting to allow the user to specify how many projects to fetch at once, and then we can loop over that many projects at once. This will allow us to fetch more than one project at a time, but still not overload the system. We can also add a setting to allow the user to specify how many logs to fetch at once, and then we can loop over that many logs at once.

class TwilioLogs extends AbstractExternalModule
{
    private $stopKeywords = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    private $errorStatus = ['FAILED', 'UNDELIVERED'];
    private $defaultLookback = "1 day ago";
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


    private function fetchLogs(array $config)
    {
        // Loop through all projects that have this EM enabled and pull logs for some amount of past time.
        foreach ($this->getProjectsWithModuleEnabled() as $project_id) {
            $this->setProjectId($project_id);
            $results = $this->query($this->sql, [$project_id]);
            if ($results->num_rows == 0)
                continue;

            $row = $results->fetch_assoc();
            $twilio_account_sid = $row['twilio_account_sid'];
            $twilio_auth_token = $row['twilio_auth_token'];
            $twilio_from_number = $row['twilio_from_number'];
            $last_fetch_time = $this->getProjectSetting('last_fetch_time', $project_id)
                ?? date('Y-m-d', strtotime($this->defaultLookback));

            // Grab messages sent to our Twilio number since the last fetch time
            $params = [
                'To'          => $twilio_from_number,
                'DateSent>='  => $last_fetch_time, // Greater than or equal to date
                'PageSize'    => 100,              // Fetch in batches of 100 (max is 1000)
            ];

            foreach ($this->fetchTwilioMessages($twilio_account_sid, $twilio_auth_token, $params) as $msg) {
                $cleanBody = strtoupper(trim($msg['body'] ?? ''));
                $stop = in_array(strtoupper($cleanBody), $this->stopKeywords, true);
                $msg = $stop ? "Received STOP request" : "Received message from participant";
                $this->log($msg, [
                    "body" => $cleanBody,
                    "date_sent" => $msg['date_sent'] ?? 'N/A',
                    "from" => $msg['from'] ?? 'Unknown',
                    "is_stop" => $stop
                ]);
            }

            // Grab failed messages sent from our Twilio number since the last fetch time
            $outboundParams = [
                'From'       => $twilio_from_number,
                'DateSent>=' => $last_fetch_time,
                'PageSize'   => 100,
            ];

            foreach ($this->fetchTwilioMessages($twilio_account_sid, $twilio_auth_token, $outboundParams) as $msg) {
                $status = $msg['status'] ?? '';

                // Check if the message failed locally or was dropped by carrier
                if (!in_array(strtoupper($status), $this->errorStatus, true))
                    continue;

                $this->log("Failed outbound message", [
                    "status" => $status,
                    "date_sent" => $msg['date_sent'] ?? 'N/A',
                    "to" => $msg['to'] ?? 'Unknown',
                    "error_code" => $msg['error_code'] ?? 'None',
                    "error_message" => $msg['error_message'] ?? 'None',
                ]);
            }

            // Set time of last fetch for this project
            $this->setLastFetchTime($project_id, time());
        }

        return "The \"{$config['cron_name']}\" cron job completed successfully.";
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
                CURLOPT_USERPWD        => "{$sid}:{$token}", // HTTP Basic Auth
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $rawResponse = curl_exec($ch);
            $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError   = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '')
                throw new RuntimeException("cURL Network Error: {$curlError}");

            $data = json_decode((string)$rawResponse, true);

            if ($httpCode >= 400) {
                $msg = $data['message'] ?? 'Unknown error';
                $code = $data['code'] ?? $httpCode;
                throw new RuntimeException("Twilio API Error [{$code}]: {$msg}");
            }

            // Stream individual messages from current page
            foreach ($data['messages'] ?? [] as $message)
                yield $message;

            // Handle pagination: Twilio returns a relative URI for the next page, or null
            if (!empty($data['next_page_uri'])) {
                $url = 'https://api.twilio.com' . $data['next_page_uri'];
            } else {
                $url = null;
            }
        }
    }
}
