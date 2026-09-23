# Better-Twilio-Logs - REDCap External Module

## What does it do?

A REDCap external module that monitors Twilio SMS communication, captures delivery failures and unsolicited participant replies, matches participant phone numbers to project records, and provides an actionable task list for study coordinators. The module queries the Twilio REST API on a scheduled cron and supports on-demand manual syncing. Incoming and outgoing phone numbers are mapped directly to project records with direct links to record home pages. Coordinators can review delivery errors with plain English explanations, identify STOP/opt-out keywords, track task resolution status, record resolution notes with audit trails, filter and sort logs, and paginate records with customizable per-user page sizes. A first-time consent modal ensures users acknowledge PHI handling before viewing message contents.

## Installing

You can install the module by dropping it directly in your modules folder (i.e. `/modules/better_twilio_logs_v1.0.0`).