ConfBridge Status
=================

Connects to the AMI and polls for active ConfBridge conferences and then
listens to all events to keep the database table up to date in near
real time.

Necessary due to the fact that ConfBridge does not report the same info
in the list command as meetme did so only way to see talker and other
specifics such as callerid name is to listen to AMI events.

Depends on PAMI (https://github.com/marcelog/PAMI)

Included Confbridge AMI Event files need to go in:
$PEAR/PAMI/Message/Event

Included Confbridge AMI Action files need to go in:
$PEAR/PAMI/Message/Action

ConfBridge Mute/UnMute events in Asterisk 10/11 require patch at
https://issues.asterisk.org/jira/browse/ASTERISK-20827

Use schema.sql to create required table in MySQL.
