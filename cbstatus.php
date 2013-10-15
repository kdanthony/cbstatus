#!/usr/bin/php
<?php

/*

ConfBridge Status

Connects to the AMI and polls for active confbridge conferences and then
listens to all events to keep the database table up to date in near
real time.

Necessary due to the fact that confbridge does not report the same info
in the list command as meetme did so only way to see talker and other
specifics such as callerid name is to listen to AMI events.

Depends on PAMI (https://github.com/marcelog/PAMI)

Copyright (c) 2013, Kevin Anthony (kevin@anthonynet.org
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met: 

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer. 
2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution. 

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

require_once '/opt/cbstatus/config.php';

// Dependencies
require_once 'log4php/Logger.php';
require_once 'PAMI/Autoloader/Autoloader.php';  

PAMI\Autoloader\Autoloader::register();  
use PAMI\Client\Impl\ClientImpl as PamiClient;  
use PAMI\Message\Event\EventMessage;  
use PAMI\Listener\IEventListener;  
use PAMI\Message\Action\ConfbridgeListAction;
use PAMI\Message\Action\ConfbridgeListRoomsAction;
use PAMI\Message\Event\ConfbridgeStartEvent;
use PAMI\Message\Event\ConfbridgeEndEvent;
use PAMI\Message\Event\ConfbridgeJoinEvent;  
use PAMI\Message\Event\ConfbridgeLeaveEvent;
use PAMI\Message\Event\ConfbridgeTalkingEvent;

// Setup logging
Logger::configure('/opt/cbstatus/log4php.properties');
$log = Logger::getLogger('cbstatus');

// Daemonize
$pid = pcntl_fork();
if($pid){
	// If we are the parent exit.
	$log->info("Spawning daemon");
	exit();
}

// Handle signals so we can exit nicely
declare(ticks = 1);
function sig_handler($signo){
	global $pids,$pidFileWritten,$log;
	if ($signo == SIGTERM || $signo == SIGHUP || $signo == SIGINT) {
		if ($pids) {
			// Pass on signals, not really needed here as the parent exits but being a good pid anyway.
			foreach($pids as $p){ posix_kill($p,$signo); } 

			foreach($pids as $p){ pcntl_waitpid($p,$status); }
		}
		$log->info("Received signal to exit, shutting down");
		exit();
	}else if($signo == SIGUSR1){
		print "I currently have " . count($pids) . " children\n";
	}
}

// Signal handlers
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");
pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGUSR1, "sig_handler");

$pamiClientOptions = array(  
    'log4php.properties' => __DIR__ . '/log4php.properties', 
    'host' => $ami_host,  
    'scheme' => 'tcp://',  
    'port' => $ami_port,  
    'username' => $ami_user,  
    'secret' => $ami_secret,  
    'connect_timeout' => 10000,  
    'read_timeout' => 10000  
);  

$log->info("Starting..");

$db = new mysqli( $db_host, $db_user, $db_pass, $db_name );

if ( $db->connect_errno > 0 ) {
	$log->error("Unable to connect to database: $db->connect_error");
	die('Unable to connect to database [' . $db->connect_error . ']');
}

$pamiClient = new PamiClient($pamiClientOptions);  
$pamiClient->open(); 

// Since we have no idea what the current state is, purge it all
$log->info("Purging existing data...");
purgeallconferences();

// Send the action to get a listing of all rooms so we can build a current
// state of bridges to work from.
$log->info("Collecting all active conference...");
$response = $pamiClient->send(new ConfbridgeListRoomsAction());

if ($response->isSuccess()) {
	$events = $response->getEvents();
	foreach ( $events as $event ) {
		if ( $event->getName() != 'ConfbridgeListRoomsComplete' ) {
			$event_conference = $event->getConference();
			$log->info("Found conference $event_conference");

			$response2 = $pamiClient->send(new ConfbridgeListAction($event_conference));		
			if ($response2->isSuccess()) {
				$part_events = $response2->getEvents();
				foreach ( $part_events as $part_event ) {
					if ( $part_event->getName() != 'ConfbridgeListComplete' ) {
						$participant_cnum  = $part_event->getCallerIDNum();
						$participant_cname = $part_event->getCallerIDName();
						$participant_chan  = $part_event->getChannel();
						$participant_admin = $part_event->getAdmin();

						$log->info("Found participant $participant_cname ($participant_cnum)");
						addcaller( $event_conference, '', $participant_chan, $participant_cname, $participant_cnum, $participant_admin );
					}
				}
			}
		}
	}
} else {
	// Something went wrong, though it's possible there were just no bridges
	// TODO: Handle no bridges better
	$log->warn("There was a problem connecting the active conferences");
}

$log->info("Starting the event listeners");

$pamiClient->registerEventListener(
    function (EventMessage $event) {
	global $log;
        $conference   = $event->GetConference();
	$log->info("Conference $conference started");
    },
    function (EventMessage $event) {
        return
            $event instanceof ConfbridgeStartEvent
        ;
    }
);

$pamiClient->registerEventListener(
    function (EventMessage $event) {
	global $log;
        $conference   = $event->GetConference();
	$log->info("Conference $conference ended");
	purgeconference( $conference );
    },
    function (EventMessage $event) {
        return
            $event instanceof ConfbridgeEndEvent
        ;
    }
);

$pamiClient->registerEventListener(  
    function (EventMessage $event) {  
	global $pamiClient;
	global $log;
	$conference   = $event->GetConference();
	$channel      = $event->GetChannel();
        $uniqueid     = $event->GetUniqueid();
	$calleridname = $event->GetCallerIDname();
	$calleridnum  = $event->GetCallerIDnum();
	$log->info("$calleridname ($calleridnum) ($uniqueid) joined $conference via $channel");
	addcaller( $conference, $uniqueid, $channel, $calleridname, $calleridnum, 'No' );

	$followup_response = $pamiClient->send(new ConfbridgeListAction($conference));
	if ($followup_response->isSuccess()) {
		$followup_part_events = $followup_response->getEvents();
		foreach ( $followup_part_events as $followup_part_event ) {
			if ( $followup_part_event->getName() != 'ConfbridgeListComplete' ) {

				if ( $followup_part_event->getChannel() == $channel ) {
					$participant_cnum  = $followup_part_event->getCallerIDNum();
					$participant_cname = $followup_part_event->getCallerIDName();
					$participant_chan  = $followup_part_event->getChannel();
					$participant_admin = $followup_part_event->getAdmin();
					$log->info("Updating admin status of $participant_chan in $conference");
					updatecalleradmin( $conference, $participant_chan, $participant_admin );
				}

			}
		}
	}

    },  
    function (EventMessage $event) {  
        return  
            $event instanceof ConfbridgeJoinEvent  
        ;  
    }  
);  

$pamiClient->registerEventListener(
    function (EventMessage $event) {
	global $log;
        $conference   = $event->GetConference();
        $channel      = $event->GetChannel();
        $uniqueid     = $event->GetUniqueid();
        $calleridname = $event->GetCallerIDname();
        $calleridnum  = $event->GetCallerIDnum();
        $log->info("$calleridname ($calleridnum) ($uniqueid) left $conference via $channel");
	removecaller( $conference, $uniqueid, $channel );
    },
    function (EventMessage $event) {
        return
            $event instanceof ConfbridgeLeaveEvent
        ;
    }
);

$pamiClient->registerEventListener(
    function (EventMessage $event) {
	global $log;
        $conference   = $event->GetConference();
        $channel      = $event->GetChannel();
        $uniqueid     = $event->GetUniqueid();
	$talking      = $event->GetTalkingStatus();
	if ($talking == 'on') {
		$log->debug("$channel is talking on $conference via $channel");
	} elseif ($talking == 'off') {
		$log->debug("$channel stopped talking on $conference via $channel");
	}
	updatetalker( $conference, $uniqueid, $channel, $talking );
    },
    function (EventMessage $event) {
        return
            $event instanceof ConfbridgeTalkingEvent
        ;
    }
);

$running = true;  
$loopcount = 0;

// Main execution loop  
while($running) {  
	$pamiClient->process();  
	// Every 5 seconds or so make sure we are still connected to MySQL
	if ($loopcount >= 5000) {
		$log->debug("Checking our db connectivity");
		$loopcount = 0;
		if ($db->ping()) {
			$log->debug("Connectivity to db is ok");
		} else {
			$log->error("Lost connectivity to database");
		}
	}
	$loopcount++;
	usleep(1000); 
}  

// Close the connection  
$pamiClient->close();  

// Change the talker status
function updatetalker( $conference, $uniqueid, $channel, $talkingstatus ) {
	global $db;
	$query = "UPDATE confbridge_status SET talking = \"$talkingstatus\" WHERE conference = \"$conference\" AND channel = \"$channel\"";
	$result = $db->query( $query );
}

// Add a caller to the bridge in the database
function addcaller( $conference, $uniqueid, $channel, $calleridname, $calleridnum, $admin ) {
	global $db;
	$timestamp = time();
	$query = "INSERT INTO confbridge_status ( conference, uniqueid, channel, calleridname, calleridnum, timestamp, talking, admin ) VALUES ( $conference, \"$uniqueid\", \"$channel\", \"$calleridname\", \"$calleridnum\", $timestamp, \"No\", \"$admin\" )";
	$result = $db->query( $query );
}

// Remove a caller from the bridge in the database
function removecaller( $conference, $uniqueid, $channel ) {
	global $db;
	$timestamp = time();
	$query = "DELETE FROM confbridge_status WHERE conference = \"$conference\" AND channel = \"$channel\"";
	$result = $db->query( $query );
}

// Clear a single conference bridge state from the database
function purgeconference( $conference ) {
	global $db;
	$query = "DELETE FROM confbridge_status WHERE conference = \"$conference\"";
	$result = $db->query( $query );
}

// Clear all of the conference bridge states from the database
function purgeallconferences( ){
        global $db;
        $query = "DELETE FROM confbridge_status";
        $result = $db->query( $query );
}

// Mark callers as admin or not for display purposes
function updatecalleradmin( $conference, $channel, $admin ) {
	global $db;
	$query = "UPDATE confbridge_status SET admin = \"$admin\" WHERE conference = $conference AND channel = \"$channel\"";
	$result = $db->query( $query );
}


?>
