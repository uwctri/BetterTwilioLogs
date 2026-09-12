<?php

/** @var \UWMadison\TwilioLogs\TwilioLogs $module */

// TODO 
// We need a nice reporting page here. We should pull the logs for the EM on this project, neatly organize them, and make sure to
// not pull too many logs at once. We should also make sure to only. We will also need to match the phone number to the record_id so we
// can easily link the user to the record with the issue. 
// The first time a user navigates to this page we need to warn them that the page MAY containe PHI in the form of phone numbers and the contents of messages
// received from participants. 

// Grab the logs for this module and project. We will need to make sure to only pull a reasonable number of logs at once.
// We auto filter to the current project_id and external_module_id so we don't have to worry about that in the query.
$pseudoSql = "
    SELECT message,
        date_sent,
        from,
        to,
        body,
        status,
        is_stop,
        error_code,
        error_message
    ORDER  BY log_id DESC
";
$result = $module->queryLogs($pseudoSql, []);
while ($row = $result->fetch_assoc()) {
    // TODO
}

// Build the report