# Better Twilio Logs - REDCap External Module

A REDCap External Module that monitors Twilio SMS communication, captures delivery failures and unsolicited participant replies, matches participant phone numbers to project records, and provides an actionable task list for study coordinators.

---

## Features

- **Automated Cron Ingestion**:
  - Periodically polls Twilio REST API for messages sent to/from your project's Twilio phone number.
  - Prioritizes projects using fair queueing (evaluates projects that haven't been fetched in the longest time first).
  - Batching limits prevent cron timeouts and overload on high-volume servers.
- **Deduplication**:
  - Checks unique Twilio Message SIDs against recorded logs before writing to ensure zero duplicate entries.
- **Participant Record Matching**:
  - Maps incoming and outgoing phone numbers to REDCap `record_id`s.
  - Automatically identifies phone-validated fields (`phone`, `phone_us`, or field names containing `phone`, `mobile`, `cell`), or allows explicit field selection in project configuration.
  - Direct links take study coordinators straight to the participant's record home page (`DataEntry/record_home.php`).
- **Interactive Task List & Workflow**:
  - Track the status of every issue (`Open` vs `Resolved`).
  - Add and update resolution notes with audit trails (tracking who resolved it and when).
  - One-click status toggling directly from the table.
- **STOP & Opt-Out Identification**:
  - Automatically highlights standard carrier opt-out keywords (`STOP`, `STOPALL`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT`).
- **Outbound Failure Explanations**:
  - Catches failed and undelivered messages.
  - Translates cryptic Twilio error codes (e.g. `30003`, `30005`, `30007`, `21610`) into plain English descriptions.
- **First-Time PHI Warning**:
  - Prompts users with an interactive consent modal on their first visit, acknowledging that message text and phone numbers may contain Protected Health Information (PHI).
  - Persists acknowledgment per user per project.
- **On-Demand Manual Sync**:
  - In addition to scheduled cron runs, project coordinators can click "Sync Twilio Now" to fetch recent messages immediately.

---

## Requirements

- REDCap >= 12.5.9 (External Modules Framework v17)
- Twilio integration enabled in project with valid credentials:
  - Account SID (34 characters)
  - Auth Token (32 characters)
  - Twilio Phone Number / From Number

---

## Configuration

### System Settings (Control Center)
- **Max Projects per Cron Run**: Maximum number of projects to evaluate per cron execution (default: 10).
- **Max Messages per Batch per Direction**: Number of messages to retrieve per API call batch (default: 100).

### Project Settings
- **Additional Phone Number Field(s)**: By default, the module automatically uses the designated SMS field configured in Project Setup (`survey_phone_participant_field`). You can optionally select additional secondary or alternate phone fields here (e.g., `alt_phone`, `secondary_phone`). If neither is configured, the module automatically detects fields with phone validation.
- **Initial Lookback Period**: Number of days in the past to pull messages on the first fetch (1, 3, 7, 14, or 30 days; default: 1 day).

---

## Usage

1. Enable the **Better Twilio Logs** module in your REDCap project.
2. Ensure Twilio credentials are configured in Project Setup.
3. Click the **Better Twilio Logs** link in the project navigation menu on the left.
4. On first access, read and accept the PHI acknowledgment prompt.
5. Use the dashboard metrics, search bar, and status filters to work your SMS task list:
   - Click **Resolve** to mark an item complete.
   - Click the **Pencil icon** to add resolution notes (e.g., "Updated phone number in record", "Followed up via email").
   - Click the **Participant Record link** to view the record in REDCap.
   - Click **Sync Twilio Now** whenever you need real-time updates.

---