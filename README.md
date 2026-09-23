# Better-Twilio-Logs - REDCap External Module

## What does it do?

A REDCap external module that monitors Twilio SMS communication, captures delivery failures and unsolicited participant replies, matches participant phone numbers to project records, and provides an actionable task list for study coordinators. The module queries the Twilio REST API on a scheduled cron and supports manual syncing. Coordinators can review delivery errors, identify STOP/opt-out keywords, track task resolution status, and record resolution notes with audit trails.

## Installing

You can install the module by dropping it directly in your modules folder (i.e. `/modules/better_twilio_logs_v1.0.0`) or install it from the Vanderbilt Repo.