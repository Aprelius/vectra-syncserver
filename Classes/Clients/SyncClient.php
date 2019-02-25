<?php
class SyncClient extends SocketServerClient
{
    public $accepted ;
    public $last_action ;
    public $index ;
    public $pinged ;
    public $ponged ;
    public $reverse ;
    public $age ;
    
    public function on_read()
    {
        while (($pos = strpos($this->read_buffer, chr(10))) !== false)
        {
            $string = trim(substr($this->read_buffer, 0, $pos + 1)) ;
            $this->read_buffer = substr($this->read_buffer, $pos + 1) ;
            $this->parse_request($string) ;
        }
        $this->read_buffer = '' ;
        return ;
    }

    public function on_connect()
    {
        global $Dbc, $Daemon ;
        $this->reverse = Daemon::ipreverse($this->remote_address) ;
        if ($Daemon->validHost($this->remote_address, $this->reverse) === false)
        {
            $this->log("Unable to authorize access for incomming connection from " . $this->reverse . '.') ;
            return $this->close() ;
        }
        $this->log("Connection confirmed from " . $this->reverse . " there are now " . (count($Daemon->clients) + 1) . " clients connected.") ;
        $this->Sockwrite("Connection to Vectra Sync Server Established") ;
        $this->Sockwrite('Your host is ' . SERVER . ' running version ' . VERSION) ;
        $this->index = (int)$this->socket ;
        $this->pinged = time() ;
        $this->ponged = time() ;
        $this->sockwrite('PING ' . $this->pinged) ;        
        return ;
    }

    public function on_disconnect()
    {
        global $Dbc, $Daemon ;
        $Daemon->log("Connection disconnected from " . $this->reverse . " there are now " . (count($Daemon->clients) - 1) . " clients connected.", $this->socket) ;     
        
        $Query = "
            SELECT name, session 
            FROM `SyncServer`.`BotList`
            WHERE `index` = '".$this->index."'
        " ;
        #$this->log('[SQL]: '.$Query) ;
        $Result = $Dbc->sql_query($Query) ;
        if ($Dbc->sql_num_rows($Result) > 0)
        {
            while (($Obj = $Dbc->sql_fetch($Result)) !== null)
            {
                $Network = trim(substr($Obj->name, 0, strpos($Obj->name, ':'))) ;
                $Sql = "
                    UPDATE `SyncServer`.`SessionList`
                    SET `session` = session - 1
                    WHERE `network` = '".$Network."' AND `ipaddress` = '".$Obj->session."'
                " ;
                $Dbc->sql_query($Sql) ;
                #$this->log('[SQL]: '.$Sql) ;
            }
            $Dbc->sql_freeresult($Result) ;
        }
        
        # Clean bot list
        $Query = "
           DELETE FROM `SyncServer`.`BotList` 
           WHERE `index` = '".$this->index."'
        " ;
        
        #$this->log('[SQL]: '.$Query) ;
        $Dbc->sql_query($Query) ;
        
        $Query = "
           DELETE FROM `SyncServer`.`SessionList` 
           WHERE `session` <= '0'
        " ;
        
        #$this->log('[SQL]: '.$Query) ;
        $Dbc->sql_query($Query) ;
        
        $Daemon->log('[Sessions]: Cleared '.$Dbc->sql_affected_rows().' sessions from the table.') ;
        return ;
    }
    
    public function log ($message)
    {
        // do not send log messages when the client
        // is less than 20 minutes old
        // probably a bad idea
        if ((time() - $this->age) <= 1200)
        {
            return ;
        }
        $message = str_replace(array("\r", "\n", "\t"), '', $message);
        return $this->Sockwrite('LOG '.$message) ;
    }

    public function on_write()
    {
        $this->last_action = time() ;
        return ;
    }

    public function on_timer()
    {
        global $Daemon ;
        if ($this->disconnected || !is_resource($this->socket))
        {
            return ;
        }
        if ((time() - $this->ponged) >= 90)
        {
            $this->log('Possible ping timeout on Socket id ' . (int)$this->socket . '. Desync of: ' . (time() - $this->ponged) . ' seconds.') ;
            $this->on_disconnect() ;
            $this->close() ;
        }
        if ((time() - $this->pinged) >= 60)
        {
            $this->pinged = time() ;
            $this->sockwrite('PING ' . $this->pinged) ;
        }
        return ;
    }

    # SyncServer Functions
    public function Sockwrite($Message)
    {
        return $this->write($Message . "\r\n", strlen($Message) + 2) ;
    }

    private function parse_request($Buffer)
    {

        global $Dbc, $Daemon, $Sync ;
        $Mode = trim(substr($Buffer, 0, strpos($Buffer, ':'))) ;
        $Data = (strpos($Buffer, ':') > 0) ? explode(':', substr($Buffer, strpos($Buffer, ':') + 1)) : null ;

        if (empty($Data))
        {
            return ;
        }

        if ($Mode == 'PONG')
        {
            $Pong = (int)trim($Data[0]) ;
            if ($Pong == $this->pinged)
            {
                $this->ponged = time() ;
            }
            return ;
        }
        
        if ($Mode == 'PING')
        {            
            if (count($Data) != 1)
            {
                return ;
            }
            
            $this->Sockwrite('PONG:'.$Data[0]) ;
            return ;
        }

        for ($i = 0; $i < count($Data); $i++)
        {
            $Data[$i] = self::vssdecode($Data[$i]) ;
        }

        try
        {
            if ($Mode == 'SESSIONSYNC')
            {
                if (count($Data) != 1)
                {
                    return ;
                }
                
                if ($Data[0] == 'now')
                {
                    $Sync->cleanSessions() ;
                    $this->log('Manually synced the session list based on the BotList.') ;
                }
                return ;
            }
            elseif ($Mode == 'GLOBAL')
            {
                # Parameters: type:message
                if (count($Data) != 2)
                {
                    return ;
                }
                
                $Network = trim($Data[0]) ;
                $Message = self::vssdecode(trim($Data[1])) ;
                
                $this->log('[GLOBAL] A global message was sent to '.$Network.(($Network == 'all')?' networks':null).': '.$Message.'.') ;
                
                $this->log('Global has been successfully routed to all clients.');
                return $Sync->SyncToAll(null, 'GLOBAL ' . $Network . ' ' . $Message) ;
            }
            elseif ($Mode == 'newNick')
            {
                # Paramaters: newNick:$network:$me:$cid
                if (count($Data) != 3)
                {
                   return ;
                }
                $Network = self::vssdecode($Data[0]) ;
                $Me      = self::vssdecode($Data[1]) ;
                $Cid     = self::vssdecode($Data[2]) ; 
                
                $Newnick = (stristr($Me, '[Dev]')) ? 'Vectra[Dev]' : 'Vectra' ;              
                
                if ($Network == 'random')
                {
                    return $this->Sockwrite('NEWNICK ' . $Cid . ' ' . $Newnick . '['.rand(10,900).']') ;
                }
                
                $Daemon->cleanGhosts() ;
                
                $Query = "
                    SELECT `name`,`index` FROM `SyncServer`.`BotList` 
                    WHERE `name` LIKE '" . $Dbc->sql_escape($Network) . ":%'
                    ORDER BY `name` ASC
                " ;
                
                #$this->log('[SQL]: '.$Query) ;
                
                $Result = $Dbc->sql_query($Query) ;
                $Results = $Dbc->sql_num_rows($Result) ;
                
                if ($Results > 0)
                {
                    $Found = false ;
                    while (($Obj = $Dbc->sql_fetch($Result)) !== null) 
                    {
                        if (!isset($Daemon->clients[$Obj->index]))
                        {
                            $Found = true ;
                            $Daemon->cleanGhosts() ;
                            $NewTag = trim(str_replace('Vectra', null, trim(substr($Obj->name, strpos($Obj->name, ':'))))) ;
                            $this->Sockwrite('NEWNICK ' . $Cid . ' ' . $Newnick . $NewTag) ;
                            break ;
                        }
                    }
                    if (!$Found)
                    {
                        $Tag = (int)(trim(str_replace(array('Vectra', '[', ']', ':'), null,$Me))) ;
                        $Tag = $Tag + 1 ;
                        $Newnick = $Newnick.'['.((strlen($Tag) == 1) ? '0'.$Tag : $Tag).']' ;
                        $this->Sockwrite('NEWNICK ' . $Cid . ' ' . $Newnick) ;
                    }
                    return $Dbc->sql_freeresult($Result) ;
                }
                else
                {
                    return $this->Sockwrite('NEWNICK ' . $Cid . ' Vectra') ;    
                }
                
                return ;
            }
            elseif ($Mode == 'SESSION')
            {
                # SESSION:$network:$me:$ipaddress
                if (count($Data) != 3)
                {
                    return ;
                }
                
                $Network = trim($Data[0]) ;
                $Me      = trim($Data[1]) ;
                $IP      = self::vssdecode(trim($Data[2])) ;
                
                $Query = "
                    UPDATE `SyncServer`.`BotList` 
                    SET `session` = '".$Dbc->sql_escape($IP)."'
                    WHERE `name` = '".$Dbc->sql_escape($Network).":".$Dbc->sql_escape($Me)."'
                    LIMIT 1
                " ;
                $Dbc->sql_query($Query) ;
                #$this->log('[SQL]: ' . $Query) ;
                
                $Query = "
                    INSERT INTO `SyncServer`.`SessionList` (`ipaddress`, `network`, `session`)
                    VALUES ('".$Dbc->sql_escape($IP)."', '".$Dbc->sql_escape($Network)."', '1')
                    ON DUPLICATE KEY UPDATE `session` = `session` + 1
                " ;
                #$this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;
                                
                $Query = "
                    SELECT session, ipaddress, network
                    FROM `SyncServer`.`SessionList`
                    WHERE `ipaddress` = '".$Dbc->sql_escape($IP)."' AND `network` = '".$Dbc->sql_escape($Network)."'
                " ;                    
                $Result = $Dbc->sql_query($Query) ;
                if ($Dbc->sql_num_rows($Result) > 0)
                {
                    $Object = $Dbc->sql_fetch($Result) ;
                    $this->log('[Session]: The session limit for the IP address '.$Object->ipaddress.' is now at '.$Object->session.' on '.$Object->network.'.') ;                        
                    $Dbc->sql_freeresult($Result) ;
                }                   
            }
            elseif ($Mode == 'SYNC')
            {

                if ($Data[0] == 'data')
                {
                    # $this->log("[SYNC] " . implode(' ', $Data)) ;
                    # var_dump($Data);
                    if (count($Data) != 5)
                    {
                        return ;
                    }
                    
                    $Table   = self::vssdecode($Data[1]) ;
                    $Expiry  = self::vssdecode($Data[2]) ;                    
                    $Hash    = self::vssdecode($Data[3]) ;
                    $Hash    = explode(':', $Hash) ;
                    $Network = trim($Hash[0]) ;
                    $Address = trim($Hash[1]) ;
                    $Hash    = self::vssdecode($Data[3]) ;
                    $String  = (empty($Data[4])) ? 0 : self::vssdecode(trim($Data[4])) ;
                    
                    # -1- (delete hash table - no save)
                    $SyncSave = (substr($Expiry, -1) == '-') ? true : false ;
                    if ($SyncSave)
                    {
                        $Expiry = trim(substr($Expiry, 0, -1)) ;
                    }
                    $Delete = ($Expiry == '-1' || $SyncSave) ? true : false ;

                    if (empty($Hash))
                    {
                        continue ;
                    }

                    # Databased tables (User Data)
                    # Mycolor,Defname,Goal,Mylist,Privacy,Saves,Start
                    
                    # Databased tables (Channel Data)
                    # event,site,auto_stats,auto_cmb,auto_clan,auto_voice,public,voicelock,global_ge,global_rsnews,default_ml,commands
                    
                    # Databased tables (Other)
                    # Ignore,Blacklist  

                    
                    # Format
                    # SYNC:data:<table>:<expiry>:<[$network:address3]>:<data string>
                    # $this->SyncToAll($this,$Output) ;
                    
                    #$Sync->cleanCache() ;
                    #if ($Table != 'Ignore' || $Table != 'Blacklist')
                    #{
                    #   $Query = "
                    #        REPLACE INTO `SyncServer`.`Cache` (`time`, `table`, `expiry`, `hash`, `data`)
                    #        VALUES (unix_timestamp(NOW()), '".$Dbc->sql_escape($Table)."', '".$Dbc->sql_escape($Expiry)."', '".$Dbc->sql_escape($Hash)."', '".$Dbc->sql_escape($String)."')
                    #    " ; 
                    #}                    
                    
                    #$Dbc->sql_query($Query) ;
                    #$this->log('[SQL]: '.$Query) ;
                    #if ($Dbc->sql_affected_rows() == 0)
                    #{
                    #   $this->log('[CACHE] Failed to update the cache (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                    #}
                    
                    switch ($Table)
                    {
                        case 'Mycolor':
                        case 'Defname':
                        case 'Goal':
                        case 'Mylist':
                        case 'Privacy':
                        case 'Saves':
                        case 'Start':  
                        case 'Whatpulse': 
                        case 'Shortlink': 
                        case 'Skillgoal':
                        case 'Skillcheck':                        
                        case 'Showgoal':
                        case 'Tripexp': 
                        case 'Weather': 
                        case 'Youtube':
                        case 'Xboxlive':                  
                            if ($Delete)
                            {
                                if ($SyncSave)
                                {
                                    $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':' . self::vsssafe($String) ;
                                    $Sync->SyncToAll($this->socket, $Output) ;
                                }
                                else
                                {
                                    $Query = "
                                        UPDATE UserData SET " . strtolower($Table) . " = DEFAULT(".strtolower($Table).") 
                                        WHERE hostmask = '" . $Dbc->sql_escape($Hash) . "' 
                                    " ;
                                    # $this->log('[SQL]: '.$Query) ;
                                    $Dbc->sql_query($Query) ;
                                    if ($Dbc->sql_affected_rows() == 0)
                                    {
                                       $this->log('[USER] Failed to update ' . $Address . ' on ' . $Network . ' for updating ' . $Table . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                                    }
                                
                                    $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':0' ;
                                    $Sync->SyncToAll($this->socket, $Output) ;
                                
                                    ###
                                    # TODO: Add a check to remove the empty rows
                                    ###
                                }
                            }
                            else
                            {
                                # Call an insert just incase
                                $Query = "
                                    INSERT INTO UserData (id, hostmask) VALUES
                                    ('NULL', '" . $Dbc->sql_escape($Hash) . "')
                                " ;
                                $Dbc->sql_query($Query) ;
                                if ($Dbc->sql_affected_rows() > 0)
                                {
                                    $this->log("[USER] Successfully added a new user with hostmask " . chr(2) . $Address .
                                    chr(2) . " on " . chr(2) . $Network . chr(2) . ".") ;
                                }                                
                                # Process the actual sync request
                                
                                $Query = "
                                    UPDATE UserData SET " . strtolower($Table) . " = '" . $Dbc->sql_escape($String) . "' 
                                    WHERE hostmask = '" . $Dbc->sql_escape($Hash) . "' 
                                " ;
                                # $this->log('[SQL]: '.$Query) ;
                                $Dbc->sql_query($Query) ;
                                if ($Dbc->sql_affected_rows() == 0)
                                {
                                    $this->log('[USER] Failed to update ' . $Address . ' on ' . $Network . ' for updating ' . $Table . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                                } 
                                else
                                {
                                    $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':' . self::vsssafe($String) ;
                                    $Sync->SyncToAll($this->socket, $Output) ;
                                }                                
                            }
                            break;
                        
                        case 'event':
                        case 'site':
                        case 'auto_stats':
                        case 'auto_cmb':
                        case 'auto_clan':
                        case 'auto_clanrank':
                        case 'auto_voice':
                        case 'public':
                        case 'voicelock':
                        case 'ge_graphs':
                        case 'global_ge':
                        case 'global_rsnews':
                        case 'default_ml':
                        case 'requirements':
                        case 'commands': 
                            if ($Delete)
                            {
                                $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':0' ;
                                $Sync->SyncToAll($this->socket, $Output) ;
                            }
                            else
                            {
                                $Info = (is_numeric($String)) ? (int)$String : $Dbc->sql_escape(trim($String)) ;
                                $Query = "
                                    UPDATE `SyncServer`.`ChannelData` SET `" . $Table . "` = '" . $Info . "'
                                    WHERE name = '".$Dbc->sql_escape($Hash)."'
                                " ;
                                #$this->log('[SQL]: '.$Query) ;
                                $Dbc->sql_query($Query) ;
                                
                                if ($Dbc->sql_affected_rows() == 0)
                                {
                                    $this->log('Failed to update the ChannelData for ' . $Hash . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;                                    
                                }
                                else
                                {
                                    $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':' . self::vsssafe($Info) ;
                                    $Sync->SyncToAll($this->socket, $Output) ;
                                }
                            }
                            break;
                        
                        case 'Ignore':
                        case 'Blacklist':
                                $Query = "
                                    DELETE FROM `SyncServer`.`BlackList`
                                    WHERE (unix_timestamp(NOW()) - `time`) > 0 AND (`time`  + `duration`) < unix_timestamp(NOW()) AND `duration` != 0
                                " ;
                                #$this->log('[SQL]: '.$Query) ;
                                $Dbc->sql_query($Query) ;
                                
                        
                                # hadd -smu604800 Blacklist SwiftIRC:#something Arconiaprime:SwiftIRC:1299730878:Not set.
                                $Hash = explode(':', $Hash) ;
                                $Channel = trim($Hash[1]) ;                                
                                $Network = trim($Hash[0]) ;                                                            
                                $Ignore = ($Table == 'Ignore') ? 1 : 0 ;
                                if ($Delete)
                                {
                                    $Query = "
                                        DELETE FROM `SyncServer`.`BlackList`
                                        WHERE `network` = '".$Dbc->sql_escape($Network)."' AND `channel` = '".$Dbc->sql_escape($Channel)."'
                                        LIMIT 1
                                    " ;
                                }
                                else
                                {                                    
                                    $String = explode(':', $String);
                                    $SetBy  = trim($String[0]) ;
                                    $Reason = (!empty($String[3])) ? trim($String[3]) : 'None.' ; 
                                    $Query = "
                                        INSERT INTO `SyncServer`.`BlackList` (id, network, channel, `time`, duration, who, reason, `ignore`)
                                        VALUES (NULL, '".$Dbc->sql_escape($Network)."', '".$Dbc->sql_escape($Channel)."', unix_timestamp(NOW()), ".(int)$Expiry.", '".$Dbc->sql_escape($SetBy)."', '".$Dbc->sql_escape($Reason)."', ".$Ignore.")
                                        ON DUPLICATE KEY UPDATE `reason` = '".$Dbc->sql_escape($Reason)."', `duration` = ".$Expiry.", `time` = unix_timestamp(NOW())    
                                    " ;
                                }
                                
                                #$this->log('[SQL]: '.$Query) ;
                                
                                $Dbc->sql_query($Query) ;
                                if ($Dbc->sql_affected_rows() == 0)
                                {
                                    $this->log('Failed to update the '.(($Table == 'Ignore') ? 'IgnoreList' : 'BlackList').' for ' . $Channel . ' on ' . $Network . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;                                    
                                }
                                else
                                {
                                    if ($Delete)
                                    {
                                        $this->log('['.(($Table == 'Ignore') ? 'IgnoreList' : 'BlackList').'] The '.(($Table == 'Ignore') ? 'IgnoreList' : 'BlackList').' for '."\x02".$Channel."\x02".' on '."\x02".$Network."\x02".' has been successfully deleted.') ;
                                        $Output = strtoupper($Table) . ' ' . $Expiry . ':' . time() . ':' . self::vsssafe(implode(':', $Hash)) . ':0';
                                        $Sync->SyncToAll($this->socket, $Output) ;
                                    }
                                    else
                                    {
                                        $this->log('['.(($Table == 'Ignore') ? 'IgnoreList' : 'BlackList').'] A '.(($Table == 'Ignore') ? 'IgnoreList' : 'BlackList').' for '."\x02".$Channel."\x02".' on '."\x02".$Network."\x02".' ('.$Reason.') has been added by '."\x02".$SetBy."\x02".'. It will expire in '.$Expiry.' seconds.') ;
                                        $Output = strtoupper($Table) . ' ' . $Expiry . ':' . time() . ':' . self::vsssafe(implode(':', $Hash)) . ':' . self::vsssafe(implode(':', $String)) ;
                                        $Sync->SyncToAll($this->socket, $Output) ;
                                    }                                                                        
                                }                               
                            break;
                            
                        case 'Accounts':
                        case 'Bots':
                        case 'Key': 
                            # Non-Databased tables
                            # Accounts,Bots,Key
                            if ($Table == 'Accounts' && !$Delete)
                            {
                                # Forum id stuff maybe ?
                                $Query = "
                                    SELECT `forum_name` 
                                    FROM `SyncServer`.`UserData` 
                                    WHERE `hostmask` = '" . $Dbc->sql_escape($Hash) . "'
                                " ;
                                #$this->log('[SQL]: '.$Query) ;
                                $Result = $Dbc->sql_query($Query) ;
                                
                                if ($Dbc->sql_num_rows($Result) == 0)
                                {
                                    $Query = "
                                        INSERT INTO `SyncServer`.`UserData` (`id`, `hostmask`, `forum_name`) VALUES
                                        ('NULL', '" . $Dbc->sql_escape($Hash) . "', '" . $Dbc->sql_escape($String) . "')
                                    " ;
                                    #$this->log('[SQL]: '.$Query) ;
                                    $Dbc->sql_query($Query) ;
                                    if ($Dbc->sql_affected_rows() > 0)
                                    {
                                        $this->log("[USER] Successfully added a new user with hostmask " . chr(2) . $Address .
                                        chr(2) . " on " . chr(2) . $Network . chr(2) . ".") ;
                                    } 
                                    else
                                    {
                                        $this->log('[USER] Failed to update ' . $Address . ' on ' . $Network . ' for updating forum_name (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                                    }
                                }
                                else
                                {
                                    $Obj = $Dbc->sql_fetch($Result) ;                                    
                                    if ($Obj->forum_name != trim($String))
                                    {
                                        $Query = "
                                            UPDATE UserData SET `forum_name` = '" . $Dbc->sql_escape($String) . "' 
                                            WHERE hostmask = '" . $Dbc->sql_escape($Hash) . "' 
                                        " ;
                                        #$this->log('[SQL]: '.$Query) ;
                                        $Dbc->sql_query($Query) ;
                                        if ($Dbc->sql_affected_rows() == 0)
                                        {
                                            $this->log('[USER] Failed to update ' . $Address . ' on ' . $Network . ' for updating forum_name (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                                        } 
                                    }
                                    $Dbc->sql_freeresult($Result) ;
                                }                                                      
                            }
                            
                            $Output = 'SYNC ' . $Table . ':' . $Expiry . ':' . self::vsssafe($Hash) . ':' . self::vsssafe($String) ;
                            $Sync->SyncToAll($this->socket, $Output) ;
                            break;
                    }
                    return ;
                }

                if ($Data[0] == 'stats')
                {
                    $Value = (!empty($Data[2]) && is_numeric($Data[2])) ? $Data[2] : 10 ;

                    $Query = "
                        INSERT INTO `SyncServer`.`StatTracker` (`stat_id`, `stat_name`, `stat_numeric`) 
                        VALUES('NULL', '" . $Dbc->sql_escape($Data[1]) . "', '" . $Dbc->sql_escape($Value) . "')
                        ON DUPLICATE KEY UPDATE `stat_numeric` = stat_numeric + " . $Value ;
                    #$this->log('[SQL] ' . $Query) ;
                    $Dbc->sql_query($Query) ;
                    return ;
                }

                if ($Data[0] == 'client')
                {
                    //$Query = "
                    //    SELECT `channels` FROM `SyncServer`.`BotList`
                    //    WHERE `name` = '" . $Data[1] . ':' . $Data[2] . "'
                    //" ;
                    
                    #  SYNC:client:SwiftIRC:Vectra[11]:7:49
                    if (count($Data) != 5)
                    {
                        return ; 
                    }
                    
                    $Network = trim($Data[1]) ;
                    $Me      = trim($Data[2]) ;
                    $Count   = (int) trim($Data[3]) ;
                    $Users   = (int) trim($Data[4]) ;
                    
                    $Query = "
                        SELECT `channels` FROM `SyncServer`.`BotList`
                        WHERE `index` = '" . $this->index . "'
                    " ;

                    # $this->log('[SQL] '.$Query) ;
                    $Result = $Dbc->sql_query($Query) ;
                    # check num rows here

                    if ($Dbc->sql_num_rows($Result) == 0)
                    {
                        $this->log('[ERROR]: Desync in client on socket id ' . $this->index . '. Now resyncing.') ;
                        $Dbc->sql_freeresult($Result) ;
                        $this->on_disconnect() ;
                        return $this->close() ;
                    }

                    $Info = $Dbc->sql_fetch($Result) ;
                    $Dbc->sql_freeresult($Result) ;

                    $Query = "
                        UPDATE `SyncServer`.`BotList` SET `channels` = ".$Count.", `users` = ".$Users.", `seen` = unix_timestamp(NOW())
                        WHERE `name` = '" . $Dbc->sql_escape($Network) . ':' . $Dbc->sql_escape($Me) . "'
                    " ;

                    # $this->log("[SQL] {$Query}") ;

                    $Dbc->sql_query($Query) ;

                    if ($Dbc->sql_affected_rows() == 0)
                    {
                        $this->log('Failed to update the BotList for client syncing ' . $Data[2] .
                            ' on ' . $Data[1] . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                    }
                    return ;
                }

                return ;
            }

            if ($Mode == 'CONNECT')
            {
                # CONNECT:$me:$network:$cid
                if (count($Data) != 3)
                {
                    return ;
                }
                
                $Me      = trim($Data[0]) ;
                $Network = trim($Data[1]) ;
                $Cid     = trim($Data[2]) ;
                if (empty($Me) || empty($Network) || empty($Cid))
                {
                    return ;
                }
                $Hash    = $Network . ':' . $Me ;              
                $Host    = explode(' ', $this->reverse) ;
                
                $Noinvite = (stristr($Me, '[Dev]')) ? 1 : 0 ;
                
                $Query = "
                    REPLACE INTO `SyncServer`.`BotList` (`name`, `index`, `cid`, `channels`, `seen`, `ip`, `hostname`, `noinvite`) 
                    VALUES ('" . $Dbc->sql_escape($Hash) . "', '" . $this->index . "', '" . $Cid . "', '0', '" . time() . "', 
                    '".$Dbc->sql_escape($this->remote_address)."', '".$Dbc->sql_escape(trim($Host[1]))."', '".$Noinvite."')
                " ;

                #$this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;

                if ($Dbc->sql_affected_rows() == 0)
                {
                    $this->log('Failed to update the BotList for ' . $Data[0] . ' (CID: ' . $Data[2] .
                        ') connecting to ' . $Data[1] . '(INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->
                        sql_error(1)) ;
                }                
                
                return ;
            }
            if ($Mode == 'DISCONNECT')
            {
                # DISCONNECT:$me:$network
                if (count($Data) != 2)
                {
                    return ;
                }
                
                $Me      = $Dbc->sql_escape($Data[0]) ;
                $Network = $Dbc->sql_escape($Data[1]) ;
               
                // Get the proper session from the table
                $Query = "
                    SELECT `ip`, `session` FROM `SyncServer`.`BotList`  
                    WHERE `name` = '" . $Network . ':' . $Me . "'
                " ;
                $Result = $Dbc->sql_query($Query) ;
                $Obj = $Dbc->sql_fetch($Result) ;
                $Dbc->sql_freeresult($Result) ;
                // Delete the record
                $Query = "
                    DELETE FROM `SyncServer`.`BotList`  
                    WHERE `name` = '" . $Network . ':' . $Me . "'
                    LIMIT 1
                " ;
                $Dbc->sql_query($Query) ;
                if ($Dbc->sql_affected_rows() == 0)
                {
                    $this->log('Failed to update the BotList for ' . $Data[0] . 
                        ' disconnecting from ' . $Data[1] . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' .
                        $Dbc->sql_error(1)) ;
                }     
                //Update the session table to reflect the new count
                $Query = "
                    UPDATE `SyncServer`.`SessionList` 
                    SET `ipaddress` = '".$Dbc->sql_escape($Obj->session)."', `network` = '".$Dbc->sql_escape($Network)."', `session` = `session` - 1
                    WHERE `ipaddress` = '".$Dbc->sql_escape($Obj->session)."' AND `network` = '".$Dbc->sql_escape($Network)."'
                " ;
                #$this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;   
                
                $Query = "
                    SELECT `session`, `ipaddress`, `network`
                    FROM `SyncServer`.`SessionList`
                    WHERE `ipaddress` = '".$Dbc->sql_escape($Obj->ip)."' AND `network` = '".$Dbc->sql_escape($Network)."'
                " ;                    
                $Result = $Dbc->sql_query($Query) ;
                if ($Dbc->sql_num_rows($Result) > 0)
                {
                    $Object = $Dbc->sql_fetch($Result) ;
                    $this->log('[Session]: The session limit for the IP address '.$Object->ipaddress.' is now at '.$Object->session.' on '.$Object->network.'.') ;                        
                    $Dbc->sql_freeresult($Result) ;
                }
                
                $Query = "
                    DELETE FROM `SyncServer`.`SessionList` 
                    WHERE `session` <= '0'
                " ;
        
                #$this->log('[SQL]: '.$Query) ;
                $Dbc->sql_query($Query) ;                    
                                
                return ;
            }

            if ($Mode == 'INVITE')
            {
                //INVITE:$network:$chan:$nick
                if (count($Data) != 3)
                {
                    return ;
                }
                
                $Daemon->cleanGhosts() ;
                
                $Network = trim($Data[0]) ;
                $Nick    = trim($Data[2]) ;
                $Chan    = trim($Data[1]) ;

                $Query = "
                    SELECT `name`, `channels`, `cid`, `index` FROM `SyncServer`.`BotList`
                    WHERE `name` LIKE '" . $Dbc->sql_escape($Network) . ":%' AND `noinvite` = '0'
                    ORDER BY `channels` ASC
                " ;
                #$this->log('[SQL]: ' . $Query) ;

                $Result = $Dbc->sql_query($Query) ;
                
                if ($Dbc->sql_num_rows($Result) > 0)
                {
                    $Bot = $Dbc->sql_fetch($Result) ;
                    $Dbc->sql_freeresult($Result) ;         
                    
                    $Bot->name = trim(substr($Bot->name, strpos($Bot->name, ':') + 1)) ;
                    
                    if (isset($Daemon->clients[$Bot->index]))
                    {
                        $this->log('['.$Network.'] Sending ' . $Bot->name . ' to join ' . $Chan . ', Invite received by ' . $Nick . '.') ;
                        $Daemon->clients[$Bot->index]->Sockwrite('BOTSEND ' . $Bot->cid . ' ' . $Bot->name . ' ' . $Chan . ' ' . $Nick . ' ' . $Network) ;    
                    }                                     
                    else
                    {
                        $this->log('['.$Network.'] Ghost connection found on invite to ' . $Chan . '.') ;
                        $this->Sockwrite('BOTSENDFAIL ' . $Bot->name . ' ' . $Chan . ' ' . $Nick . ' ' . $Network) ;
                    }
                }                
                else 
                {
                    $this->log('['.$Network.'] Invite to ' . $Chan . ' recieved however no bot is available for invites on ' . $Network . '.') ;
                    $this->Sockwrite('BOTSENDFAIL ' . $Chan . ' ' . $Nick . ' ' . $Network . ' Invite') ;
                }
                
                # Invite Logger
                $Query = "
                    INSERT INTO `SyncServer`.`InviteLog` (`id`, `channel`, `network`, `time`, `invite_by`)
                    VALUES ('NULL', '".$Dbc->sql_escape($Chan)."', '".$Dbc->sql_escape($Network)."', '".time()."', '".$Dbc->sql_escape($Nick)."')
                " ; 
                #$this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;
                return ;
            }

            if ($Mode == 'NICK')
            {
                //NICK:$me(new):$nick(old):$network
                if (count($Data) != 3)
                {
                    return ;
                }
                
                $Network = trim($Data[2]) ;
                $Nick    = trim($Data[1]) ;
                $Newnick = trim($Data[0]) ;
                
                $Noinvite = (strpos($Me, '[Dev]') === false) ? 0 : 1 ;

                $Query = "
                    UPDATE `SyncServer`.`BotList` SET `name` = '" . $Dbc->sql_escape($Network) . ':' . $Dbc->sql_escape($Newnick) . "', `noinvite` = '".$Noinvite."'
                    WHERE `name` = '" . $Dbc->sql_escape($Network) . ':' . $Dbc->sql_escape($Nick) . "'
                " ;
                # $this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;

                if ($Dbc->sql_affected_rows() == 0)
                {
                    return $this->log('Failed to update the BotList for ' . $Data[1] .
                        ' changing nickname from ' . $Data[1] . ' to ' . $Data[0] . '(INFO: ' . $Dbc->
                        sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                }
                elseif ($Noivite == 1)
                {
                    $this->log('['.$Network.'] ' . $Newnick . ' has entered '.chr(2).'Development Mode'.chr(2).' and will no longer be in the invite queue.') ;
                }
                $this->log($Data[0] . ' changed nickname from ' . $Data[1] . ' on ' . $Data[2]) ;
                return ;
            }

            if ($Mode == 'JOIN')
            {
                //JOIN:Vectra:VectraIRC:#arconiaprime:3
                //JOIN:Vectra:VectraIRC:#arconiaprime:#arconia:#etc
                if (count($Data) < 3)
                {
                    return ;
                }
                $Me = trim($Data[0]) ;
                $Network = trim($Data[1]) ;
                $Users = (!empty($Data[3])) ? trim($Data[3]) : null ;

                # $this->log('[DEBUG]: ' . implode(':', $Data)) ;

                $ChanList = explode(':', $Data[2]) ;
                # $this->log('[DEBUG]: ' . implode(':', $ChanList)) ;
                for ($i = 0; $i < count($ChanList); $i++)
                {
                    $Chan = $ChanList[$i] ;
                    # Update the count in the Bot record
                    $Query = "
                        UPDATE `SyncServer`.`BotList` SET `channels` = channels + 1 " . ((!
                        empty($Users) && is_numeric($Users)) ? ', `users` = users + ' . $Users : '') .
                        " WHERE `name` = '" . $Dbc->sql_escape($Network) . ':' . $Dbc->sql_escape($Me) . "'
                    " ;

                    # $this->log('[SQL]: ' . $Query) ;
                    $Dbc->sql_query($Query) ;

                    if ($Dbc->sql_affected_rows() == 0)
                    {
                        $this->log('Failed to update the BotList for ' . $Me . ' joining ' . $Chan .
                            ' on ' . $Network . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->
                            sql_error(1)) ;
                    }
                    
                    $Hash = $Network . ':' . $Chan ;
                    # We have to check if the channel record exists. If not create one
                    $Query = "
                        SELECT * FROM `SyncServer`.`ChannelData`
                        WHERE `name` = '" . $Dbc->sql_escape($Network) . ':' . $Dbc->sql_escape($Chan) . "'
                    " ;
                    # $this->log('[SQL]: ' . $Query) ;
                    $Info = $Dbc->sql_query($Query) ;

                    if ($Dbc->sql_num_rows($Info) == 0)
                    {
                        # Create a new channel record
                        $Sql = "
                            INSERT INTO `SyncServer`.`ChannelData` (`id`, `name`) 
                            VALUES ('', '" . $Dbc->sql_escape($Hash) . "')
                        " ;
                        # $this->log('[SQL]: ' . $Sql) ;
                        $Dbc->sql_query($Sql) ;

                        if ($Dbc->sql_affected_rows() == 0)
                        {
                            $this->log('Failed to create a new channel in the Channel list for ' . $Chan .
                                ' on ' . $Network . '(INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->
                                sql_error(1)) ;
                            continue ;
                        }
                        $Query = "
                            SELECT * FROM `SyncServer`.`ChannelData`
                            WHERE `name` = '" . $Dbc->sql_escape($Hash) . "'
                        " ;
                        # $this->log('[SQL]: ' . $Query) ;
                        $Info = $Dbc->sql_query($Query) ;
                    }
                    
                    if ($Dbc->sql_num_rows($Info) == 0)
                    {
                        $this->log('Error getting channel data') ;
                        return ;
                    }
                    # We've joined a channel. Send the data
                    # Load the Channel Info here
                    $Channel = $Dbc->sql_fetch($Info, MYSQL_ASSOC) ;
                    $ChannelInfo = '' ;
                    foreach ($Channel as $Key => $Data)
                    {
                        $ChannelInfo .= ':' . strtolower($Key) . ':' . ((empty($Data) && $Data != '0') ? '-' : self::vsssafe($Data)) ;
                    }
                    $Sync->SyncToAll(null, 'CHANNEL ' . substr($ChannelInfo, 1)) ;
                    
                    // DO not sync any channel info except
                    // when the bot client has long since fully
                    // synced and is active for 20 minutes                    
                    if ((time() - $this->age) <= 1200)
                    {
                        continue ;
                    }
                    
                    # Send channel information to relay
                    $Query = "
                        SELECT COUNT(*), SUM(channels), SUM(users) FROM `SyncServer`.`BotList` 
                        WHERE `name` LIKE '" . $Dbc->sql_escape($Network) . ":%'
                    " ;

                    $Result = $Dbc->sql_query($Query) ;
                    if ($Dbc->sql_num_rows($Result) == 0)
                    {
                        return ;
                    }
                    
                    $Info = $Dbc->sql_fetch($Result, MYSQL_NUM) ;                    
                    $this->Sockwrite('NETWORKINFO ' . $Network . ' ' . $Info[1] . ' ' . $Info[0] . ' ' . $Info[2]) ;
                    $Dbc->sql_freeresult($Result) ;
                    
                    $Query = "SELECT COUNT(*), SUM(channels), SUM(users) FROM `SyncServer`.`BotList`" ;
                    $Result = $Dbc->sql_query($Query) ;
                    if ($Dbc->sql_num_rows($Result) == 0)
                    {
                        return ;
                    }
                    $Info = $Dbc->sql_fetch($Result, MYSQL_NUM) ;                    
                    $this->Sockwrite('CHANINFO ' . $Info[1] . ' ' . $Info[0] . ' ' . $Info[2]) ;
                    $Dbc->sql_freeresult($Result) ;
                } // for
            }

            if ($Mode == 'PART' || $Mode == 'KICK')
            {
                //PART:$me:$network:$chan:$nick($chan,0)
                if (count($Data) != 4)
                {
                    return ;
                }
                
                $Me   = trim($Data[0]) ;
                $Network = trim($Data[1]) ;
                $Chan = trim($Data[2]) ;
                $Users = (int)trim($Data[3]) ;
                $Hash = $Network . ':' . $Me ;
                
                $Query = "
                    UPDATE `SyncServer`.`BotList` SET `channels` = channels - 1, `users` = users - " . $Users . "
                    WHERE `name` = '" . $Dbc->sql_escape($Hash) . "'
                " ;

                #$this->log('[SQL]: ' . $Query) ;
                $Dbc->sql_query($Query) ;
                if ($Dbc->sql_affected_rows() == 0)
                {
                    $this->log('Failed to update the BotList for parting bot ' . $Me . ' from ' . $Chan . 
                        ' on ' . $Network . ' (INFO: ' . $Dbc->sql_info() . ') reason: ' . $Dbc->sql_error(1)) ;
                    return ;
                }
                
                return ;
            }
        }
        catch (exception $e)
        {
            return $this->log('[ERROR]: ' . $e->getMessage()) ;
        }
        return ;
    }

    # Sync Server Static functions
    static function vsssafe($String)
    {
        return str_replace(array(':', '*'), array(chr(1), chr(4)), $String) ;
    }
    static function vssdecode($String)
    {
        return str_replace(array(chr(1), chr(4)), array(':', '*'), $String) ;
    }
}

?>