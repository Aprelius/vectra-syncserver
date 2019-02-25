<?php
# Runtime Specifics
define('PID', posix_getpid()) ;
define('MEM_START', memory_get_usage(true)) ;

# Directory Variables
define('INCLUDES_DIR', '/home/Vectra/PHPincludes/');
define('CLASS_DIR', $RootPath . 'Classes/') ;
define('SERVERS_DIR', CLASS_DIR . 'Servers/') ;
define('CLIENTS_DIR', CLASS_DIR . 'Clients/') ;
define('LOG_DIR', $RootPath . 'Logs/') ;
define('FILE_DIR', $RootPath . 'Files/') ;

# Unix System details
define('UNIX_UID', 502) ;
define('UNIX_GUID', 502) ;

# Server Information
define('SERVER', 'sync.vectra-bot.net') ;
define('SELF', 'Sync!Services@Vectra-Bot.net') ;
define('VERSION', 'VectraSyncServer.v2.0') ;
define('BINDIP', '') ;
define('PING_INTERVAL', 90) ;

# Database tables
define('DATABASE', '`SyncServer`') ;
define('BLACKLIST_TABLE', DATABASE.'.`BlackList`') ;
define('BOT_TABLE', DATABASE.'.`BotList`') ;
define('CACHE_TABLE', DATABASE.'.`Cache`') ;
define('CHANNEL_TABLE', DATABASE.'.`ChannelData`') ;
define('IGNORE_TABLE', DATABASE.'.`IgnoreList`') ;
define('TRACKER_TABLE', DATABASE.'.`StatTracker`') ;
define('LOG_TABLE', DATABASE.'.`SyncLog`') ;
define('USER_TABLE', DATABASE.'.`UserData`') ;

# Socket errors & codes
define('S_NOTSOCK', 88) ;
define('S_DESTADDRREQ', 89) ;
define('S_MSGSIZE', 90) ;
define('S_PROTOTYPE', 91) ;
define('S_NOPROTOOPT', 92) ;
define('S_PROTONOSUPPORT', 93) ;
define('S_SOCKTNOSUPPORT', 94) ;
define('S_OPNOTSUPP', 95) ;
define('S_PFNOSUPPORT', 96) ;
define('S_AFNOSUPPORT', 97) ;
define('S_ADDRINUSE', 98) ;
define('S_ADDRNOTAVAIL', 99) ;
define('S_NETDOWN', 100) ;
define('S_NETUNREACH', 101) ;
define('S_NETRESET', 102) ;
define('S_CONNABORTED', 103) ;
define('S_CONNRESET', 104) ;
define('S_NOBUFS', 105) ;
define('S_ISCONN', 106) ;
define('S_NOTCONN', 107) ;
define('S_SHUTDOWN', 108) ;
define('S_TOOMANYREFS', 109) ;
define('S_TIMEDOUT', 110) ;
define('S_CONNREFUSED', 111) ;
define('S_HOSTDOWN', 112) ;
define('S_HOSTUNREACH', 113) ;
define('S_ALREADY', 114) ;
define('S_INPROGRESS', 115) ;
define('S_REMOTEIO', 121) ;
define('S_CANCELED', 125) ;

?>