<?php
/**
 * All code below is required to run the System/Daemon.php class
 */

// Allowed arguments & their defaults 
$runmode = array
(
    'no-daemon' => false,
    'help' => false,
);

// Scan command line attributes for allowed arguments
foreach ($argv as $k => $arg) 
{
    if (substr($arg, 0, 2) == '--' && isset($runmode[substr($arg, 2)])) 
    {
        $runmode[substr($arg, 2)] = true;
    }
}

// Help mode. Shows allowed argumentents and quit directly
if ($runmode['help'] == true) 
{
    echo 'Usage: '.$argv[0].' [runmode]' . "\n";
    echo 'Available runmodes:' . "\n";
    foreach ($runmode as $runmod=>$val) 
    {
        echo ' --'.$runmod . "\n";
    }
    die();
}

require_once (FILE_DIR . 'Config.php') ;
require_once (INCLUDES_DIR . 'Daemon/Daemon.php') ;

 /**
  * Set the Daemon Options
  */
error_reporting(E_ALL) ;
$options = array(
    'appName' => 'SyncServer',
    'appDir' => dirname(__FILE__),
    'appDescription' => 'Vectra SyncServer',
    'authorName' => 'Arconiaprime',
    'authorEmail' => 'Arconiaprime@Phantomnet.net',
    'sysMaxExecutionTime' => '0',
    'sysMaxInputTime' => '0',
    'sysMemoryLimit' => '32M',
    'appRunAsGID' => UNIX_GUID,
    'appRunAsUID' => UNIX_UID,
    'logLocation' => LOG_DIR . 'Server.'.date('m-d-y').'.log',
    'appPidLocation' => LOG_DIR . 'SyncServer/SyncServer.pid',
    'logPhpErrors' => true,
    'logFilePosition' => true,
    'logLinePosition' => true
);
System_Daemon::setOptions($options);
?>