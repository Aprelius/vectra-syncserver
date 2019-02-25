<?php
ini_set ('date.timezone', 'Europe/Berlin') ;

$RootPath = dirname(__file__) . '/' ;

require_once ($RootPath . 'Files/Definitions.php') ;
require_once ($RootPath . 'Files/System.php') ;

require_once (INCLUDES_DIR . 'Classes/SQLdrivers/MySQLi_Driver.php') ;  

require_once (CLASS_DIR . 'Daemon/Socket.php') ;
require_once (CLASS_DIR . 'Daemon/Client.php') ;
require_once (CLASS_DIR . 'Daemon/SocketServer.php') ;
require_once (CLASS_DIR . 'Daemon/SocketServerClient.php') ;

require_once (CLIENTS_DIR . 'SyncClient.php') ;
# require_once (CLIENTS_DIR . 'LogClient.php') ;

require_once (SERVERS_DIR . 'SyncServer.php') ;
# require_once (SERVERS_DIR . 'LogServer.php') ;

require_once (CLASS_DIR . 'Daemon.php') ;  

if (!$runmode['no-daemon']) {
    // Spawn Daemon 
    System_Daemon::start();
    System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".System_Daemon::getOption("appName")."' spawned! This will be written to ".System_Daemon::getOption("logLocation"));
    System_Daemon::log(System_Daemon::LOG_INFO, "Daemon is running under PID " . posix_getpid());
}

###########################################
$Dbc = new SQLi_Driver ;

if ($Dbc->sql_connect($dbhost, $dbuser, $dbpass, $dbname, $dbport) == false)
{    
    System_Daemon::log(System_Daemon::LOG_ERR, 
        'Error connecting to the SQL Server. Reason: ' . $Dbc->sql_error(1)
    ) ;
    unset($Dbc) ;
    if (System_Daemon::isInBackground())
    {
        System_Daemon::stop();
    }
    die() ;
}

error_reporting(E_ALL) ;
set_time_limit(0) ;
###########################################
if (!$runmode['no-daemon']) 
{
    ob_start() ;
}

$Daemon = new Daemon() ;

$Sync = $Daemon->create_server('SyncServer', 'SyncClient', '212.71.20.4', 34887) ;

while (!System_Daemon::isDying() && !$Daemon->Shutdown) 
{
    // What mode are we in?
    $Daemon->logFile = LOG_DIR . 'Sync.'.date('m-d-y').'.log' ;
    if ($Daemon->logFile != System_Daemon::getOption('logLocation'))
    {
        System_Daemon::setOption('logLocation', $Daemon->logFile) ;
    }
    $Daemon->process() ;
    break ;
    System_Daemon::iterate(1);
}
/**
 * Garbage clean up
 */
System_Daemon::stop();
unset($Dbc) ;
die('Server Shutdown' . chr(10)) ;

?>