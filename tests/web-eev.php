<?php

// Run: php -d extension=dom tests/web-eev.php
require __DIR__.'/regression.php';

/** Independent WebSocket peer: only LOGIN, REFRESH, GET and protocol pong are accepted. */
function withEEVPeer(callable $test,array $options=[]): void
{
    $listener=stream_socket_server('tcp://127.0.0.1:0');
    $port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    $pid=pcntl_fork();
    check($pid!==-1,'Cannot fork WebSocket peer');
    if ($pid===0) {
        try {
            $connection=stream_socket_accept($listener,5);
            check($connection!==false,'WebSocket connection missing');
            stream_set_timeout($connection,3);
            $read=static function(int $length) use ($connection): string {
                $data='';
                while(strlen($data)<$length) {
                    $part=fread($connection,$length-strlen($data));
                    check($part!==false && $part!=='','Incomplete client frame');
                    $data.=$part;
                }
                return $data;
            };
            $header='';
            while(!str_ends_with($header,"\r\n\r\n")) $header.=$read(1);
            check(str_contains($header,'Sec-WebSocket-Protocol: Lux_WS'),'Missing Lux_WS');
            preg_match('/Sec-WebSocket-Key: ([^\r\n]+)/',$header,$match);
            $accept=base64_encode(sha1($match[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
            if (!empty($options['badAccept'])) $accept='wrong';
            fwrite($connection,"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\nSec-WebSocket-Protocol: Lux_WS\r\n\r\n");
            if (!empty($options['badAccept'])) {fclose($connection);exit(0);}
            $receive=static function() use ($read,$connection): ?array {
                $start=fread($connection,1);
                if($start===false || $start==='') return null;
                [$first,$second]=array_values(unpack('C2',$start.$read(1)));
                check(($second&128)!==0,'Client frame is not masked');
                $length=$second&127;
                if($length===126) $length=unpack('n',$read(2))[1];
                check($length<4096,'Oversized client frame');
                $mask=$read(4);
                $payload=$read($length);
                $payload^=substr(str_repeat($mask,(int)ceil($length/4)),0,$length);
                return [$first&15,$payload];
            };
            $send=static function(string $text,int $opcode=1,bool $fin=true) use ($connection): void {
                $n=strlen($text);
                $header=chr(($fin?128:0)|$opcode).($n<126?chr($n):($n<=65535?chr(126).pack('n',$n):chr(127).pack('NN',0,$n)));
                $data=$header.$text;
                while($data!=='') {
                    $written=fwrite($connection,$data);
                    if($written===false || $written===0) return;
                    $data=substr($data,$written);
                }
            };
            check($receive()===[1,'LOGIN;'.($options['password']??'999999')],'Unexpected login command');
            check($receive()===[1,'REFRESH'],'Expected read-only refresh');
            if(!empty($options['silent'])) {usleep(350000);fclose($connection);exit(0);}
            if(!empty($options['empty'])) $send('');
            $id=$options['outputID']??'0x53e3f0';
            $extra='';
            foreach($options['extra']??[] as $menu) $extra.='<item id="'.$menu['id'].'"><name>'.$menu['name'].'</name></item>';
            $info='<item id="0x53b038"><name>Informationen</name><item id="'.$id.'"><name>Ausgänge</name></item>'.$extra.'</item>';
            if(!empty($options['separateInfo'])) $info='<item id="0x53b038"><name>Informationen</name></item>';
            $send('<Navigation id="0x53c698">'.$info.'</Navigation>');
            if(!empty($options['separateInfo'])) {
                check($receive()===[1,'GET;0x53b038'],'Incorrect information ID');
                $send('<Navigation/>'); // A queued navigation refresh is not a content reply.
                $send('<Content id="0x53b038"><item id="'.$id.'"><name>Ausgänge</name></item>'.$extra.'</Content>');
            }
            $xml=$options['xml']??('<Content><item id="'.($options['heatingID']??'0x53fcd4').'"><name>EEV Heizen</name><value>'.($options['heat']??'42.9 %').'</value></item><item id="0x53fd14"><name>EEV Kühlen</name><value>'.($options['cool']??'100.0 %').'</value></item><name>Ausgänge</name></Content>');
            if(!empty($options['large'])) $xml=str_replace('</Content>','<!--'.str_repeat('x',70000).'--></Content>',$xml);
            $pages=[$id=>['name'=>'Ausgänge','xml'=>$xml]];
            foreach($options['extra']??[] as $menu) $pages[$menu['id']]=$menu;
            $seen=[];
            while(($request=$receive())!==null) {
                check($request[0]===1 && str_starts_with($request[1],'GET;'),'Unexpected non-reading command');
                $pageID=substr($request[1],4);
                check(isset($pages[$pageID]) && !isset($seen[$pageID]),'Unknown or duplicate page requested');
                $seen[$pageID]=$pages[$pageID]['name'];
                $reply=$pages[$pageID]['xml'];
                if(!empty($options['fragment']) && $pageID===$id) {
                    $split=intdiv(strlen($reply),2);
                    $send(substr($reply,0,$split),1,false);
                    $send('heartbeat',9);
                    check($receive()===[10,'heartbeat'],'Ping was not answered with pong');
                    $send(substr($reply,$split),0,true);
                } else $send($reply);
            }
            if(isset($options['expectPages'])) {
                $actual=array_values($seen);$expected=$options['expectPages'];sort($actual);sort($expected);
                check($actual===$expected,'Requested pages differ: '.json_encode($actual));
            }
            fclose($connection);
            exit(0);
        } catch(Throwable $error) {
            fwrite(STDERR,'EEV peer: '.$error->getMessage()."\n");
            exit(1);
        }
    }
    try {$test($port);} finally {fclose($listener);pcntl_waitpid($pid,$status);}
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'EEV peer failed');
}

class WebEEVTestModule extends Luxtronik
{
    public int $webTestPort=8214;
    protected function NewWebEEVClient(int $TimeoutMs=2000): LuxtronikWebEEVClient {return new LuxtronikWebEEVClient('127.0.0.1',$this->webTestPort,min(1000,$TimeoutMs));}
}

$tests=[];
$tests['reported firmware XML maps named EEV values without a Java index'] = static function(): void {
    withEEVPeer(static function(int $port):void {
        $values=(new LuxtronikWebEEVClient('127.0.0.1',$port,1000))->read('999999');
        check($values['EEV_Heizen']===['value'=>42.9,'webId'=>'0x53fcd4'],'Wrong heating field');
        check($values['EEV_Kuehlen']['value']===100.0,'Wrong cooling field');
    });
};
$tests['zero comma decimals and newly discovered field IDs stay valid'] = static function(): void {
    withEEVPeer(static function(int $port):void {
        $values=(new LuxtronikWebEEVClient('127.0.0.1',$port,1000))->read('999999');
        check($values['EEV_Heizen']===['value'=>0.0,'webId'=>'changed-heat'],'Zero or field identity lost');
        check($values['EEV_Kuehlen']['value']===17.3,'Comma decimal failed');
    },['heat'=>'0,0 %','cool'=>'17,3 %','outputID'=>'changed-output','heatingID'=>'changed-heat','separateInfo'=>true]);
};
$tests['fragmented and 64-bit length frames handle ping without adding control commands'] = static function():void {
    foreach([['fragment'=>true],['large'=>true],['empty'=>true]] as $options) withEEVPeer(static function(int $port):void {
        $values=(new LuxtronikWebEEVClient('127.0.0.1',$port,1000))->read('999999');
        check($values['EEV_Heizen']['value']===42.9,'Frame reassembly failed');
    },$options);
};
$tests['missing and invalid openings are omitted instead of inferred or reset to zero'] = static function():void {
    withEEVPeer(static function(int $port):void {
        check((new LuxtronikWebEEVClient('127.0.0.1',$port,1000))->read('999999')===[],'Invalid opening accepted');
    },['heat'=>'101 %','cool'=>'—']);
    withEEVPeer(static function(int $port):void {
        check((new LuxtronikWebEEVClient('127.0.0.1',$port,1000))->read('999999')===[],'Unrelated HUP became EEV');
    },['xml'=>'<Content><item><name>HUP</name><value>42.9 %</value></item></Content>']);
};
$tests['invalid XML duplicate names and incorrect handshake are rejected'] = static function():void {
    foreach([
        ['badAccept'=>true],['xml'=>'<Content>'],
        ['xml'=>'<!DOCTYPE Content [<!ENTITY x SYSTEM "file:///etc/passwd">]><Content/>'],
        ['xml'=>'<Content><item><name>EEV Heizen</name><value>1 %</value></item><item><name>EEV Heizen</name><value>2 %</value></item></Content>'],
        ['silent'=>true]
    ] as $options) withEEVPeer(static function(int $port):void {
        try {(new LuxtronikWebEEVClient('127.0.0.1',$port,150))->read('999999');}
        catch(RuntimeException $expected) {return;}
        throw new RuntimeException('Invalid response accepted');
    },$options);
};
$tests['live graphics use internal WebSocket percentages with no selected measurement objects'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        invoke($module,'UpdateWebEEV');
        $config=invoke($module,'BuildCardConfig');$data=invoke($module,'BuildVisualizationData');
        check($data[$config['eevHeatingOpening']]['value']===42.9 && $data[$config['eevCoolingOpening']]['value']===100.0,'Graphic missed internal web values');
        check($data[$config['eevHeatingOpening']]['source']==='WebSocket','Source missing');
        check($module->GetIDForIdent('EEV_Heizen')===false,'Graphic creates measurement objects');
        invoke($module,'UpdateWebEEV'); // Throttled: no second connection.
        $module->properties['IDListe']='[{"id":400001,"enabled":true}]';
        invoke($module,'PublishWebEEV');
        check(GetValue($module->GetIDForIdent('EEV_Heizen'))===42.9 && $module->GetIDForIdent('EEV_Kuehlen')===false,'Object selection ignored');
        $module->properties['IDListe']='[]';
        invoke($module,'SyncWebVariableSelection');
        check($module->GetIDForIdent('EEV_Heizen')===false && invoke($module,'GetWebEEVPoint','EEV_Heizen')['value']===42.9,'Deselection did not remove its object independently of internal measurements');
        invoke($module,'StoreVisualizationPoints',[80=>0]);
        $GLOBALS['testWebEEVHtml']=$module->GetVisualizationTile();
        invoke($module,'StoreVisualizationPoints',[80=>7]);
        $GLOBALS['testWebEEVCooling']=['type'=>'update','config'=>invoke($module,'BuildCardConfig'),
            'data'=>invoke($module,'BuildVisualizationData'),'controls'=>invoke($module,'BuildControlData')];
        $module->properties['WebSocketPassword']='another-password';
        check(invoke($module,'GetWebEEVPoint','EEV_Heizen')===null,'Credentials reused stale values');
        check(!str_contains($module->GetVisualizationTile(),'another-password'),'WebSocket password exposed to browser');
    });
};
$tests['failed web reads clear current openings and back off without changing primary status'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $source=invoke($module,'WebEEVSourceKey');
        invoke($module,'SetBuffer','WebEEVCache',json_encode(['source'=>$source,'updatedAt'=>time(),'values'=>['EEV_Heizen'=>['value'=>42.9,'webId'=>'old']]]));
        invoke($module,'SetStatus',102);
        invoke($module,'UpdateWebEEV');
        check(invoke($module,'GetWebEEVPoint','EEV_Heizen')===null && $module->status===102,'Failed supplement revived a stale value or changed status');
        invoke($module,'UpdateWebEEV'); // Failure backoff also does not connect again.
        $state=json_decode($module->GetWebEEVData(),true);
        check($state['error']!==null && $state['nextAttempt']>time()+100,'Missing backoff or error detail');
    },['badAccept'=>true]);
};
$tests['expired or disabled WebSocket readings disappear and the list identifies WebSocket-only selections'] = static function():void {
    $module=new Luxtronik();$module->properties['IPAddress']='127.0.0.1';
    $source=invoke($module,'WebEEVSourceKey');
    invoke($module,'SetBuffer','WebEEVCache',json_encode(['source'=>$source,'updatedAt'=>time()-100,'values'=>['EEV_Heizen'=>['value'=>42.9,'webId'=>'old']]]));
    check(invoke($module,'GetWebEEVPoint','EEV_Heizen')===null,'Expired web value returned');
    $form=json_decode($module->GetConfigurationForm(),true);
    $rows=fields($form)['IDListe']['values'];
    $row=array_values(array_filter($rows,static fn($row)=>$row['id']===400001));
    check(count($row)===1 && $row[0]['interface']==='WebSocket','Source missing from list');
    check($row[0]['source']==='Keine aktuellen Daten','An unmeasured device was labelled available');
    check(str_contains(json_encode($form),'3.81'),'Firmware requirement missing from form');
};

$tests['device availability distinguishes incomplete discovery from a failed port connection'] = static function():void {
    $module=new Luxtronik();$module->properties['IPAddress']='127.0.0.1';
    $source=invoke($module,'WebEEVSourceKey');
    invoke($module,'SetBuffer','WebEEVCache',json_encode(['source'=>$source,'updatedAt'=>time(),'values'=>[],
        'discovery'=>['count'=>0,'complete'=>false,'errors'=>[['name'=>'Ausgänge','error'=>'Zeitgrenze erreicht.']]]]));
    $form=json_decode($module->GetConfigurationForm(),true);
    $rows=fields($form)['IDListe']['values'];
    $row=array_values(array_filter($rows,static fn($row)=>$row['id']===400001))[0];
    check($row['source']==='Keine aktuellen Daten','Partial scan claimed a field was unavailable');
    $listener=stream_socket_server('tcp://127.0.0.1:0');
    $port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    fclose($listener);
    $message='';
    try {(new LuxtronikWebEEVClient('127.0.0.1',$port,150))->read('999999');}
    catch(RuntimeException $error) {$message=$error->getMessage();}
    check(str_contains($message,(string)$port) && str_contains($message,'3.81'),'Closed port did not produce an actionable error');
    invoke($module,'SetBuffer','WebEEVCache',json_encode(['source'=>$source,'updatedAt'=>time(),'values'=>[],'error'=>$message]));
    $form=json_decode($module->GetConfigurationForm(),true);
    $rows=fields($form)['IDListe']['values'];
    $row=array_values(array_filter($rows,static fn($row)=>$row['id']===400001))[0];
    check($row['source']==='Keine aktuellen Daten' && str_contains(json_encode($form,JSON_UNESCAPED_UNICODE),$message),'Connection error hidden from the configuration');
};

$tests['targeted pages supplement a missing Java point and retain other Java readings'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $module->properties['CommunicationMode']='java';
        $module->properties['IDListe']='[{"id":10,"enabled":true},{"id":11,"enabled":true},{"id":39,"enabled":true}]';
        $module->RegisterVariableFloat('Vorlauftemperatur_Heizkreis','Existing supply','~Temperature');
        $supplyID=$module->GetIDForIdent('Vorlauftemperatur_Heizkreis');
        invoke($module,'StoreVisualizationPoints',[11=>28.3]);
        invoke($module,'UpdateWebEEV');
        $supply=invoke($module,'GetVisualizationPointData','SupplyTemperature');
        $return=invoke($module,'GetVisualizationPointData','HeatPumpReturnTemperature');
        check($supply['value']===0.0 && $supply['source']==='WebSocket','Zero WebSocket value lost to Java fallback');
        check($return['value']===28.3,'Missing WebSocket return did not use Java');
        check($module->GetIDForIdent('Vorlauftemperatur_Heizkreis')===$supplyID && GetValue($supplyID)===0.0,'Native supply object replaced or not updated');
        $snapshot=json_decode($module->GetWebEEVData(),true);
        check($snapshot['discovery']['count']===6 && count($snapshot['discovery']['groups'])===2,'Discovery count wrong');
        check($snapshot['values']['Steuersignal_Umwaelzpumpe']['value']===0.0
            && $snapshot['values']['Ausgang_Heizungsumwaelzpumpe']['value']===false,'Duplicate HUP labels mixed Boolean and percent');
        $rows=fields(json_decode($module->GetConfigurationForm(),true))['IDListe']['values'];
        $row=array_values(array_filter($rows,static fn($row)=>$row['id']===10))[0];
        check(str_contains($row['interface'],'WebSocket') && str_contains($row['interface'],'Java'),'Shared source listing lost');
        check($row['source']==='WebSocket','Valid zero was not shown as actually supplied');
        $returnRow=array_values(array_filter($rows,static fn($row)=>$row['id']===11))[0];
        check($returnRow['source']==='Java','Invalid reading was shown as an available measurement');
        $module->properties['IPAddress']='127.0.0.2';
        check(invoke($module,'GetWebEEVPoint','Vorlauftemperatur_Heizkreis')===null,'Device change kept previous WebSocket values');
    },['xml'=>'<Content><item id="heat"><name>EEV Heizen</name><value>42.9 %</value></item><item id="cool"><name>EEV Kühlen</name><value>100.0 %</value></item><item id="hup-state"><name>HUP</name><value>Aus</value></item><item id="hup-percent"><name>HUP</name><value>0.0 %</value></item></Content>',
        'extra'=>[['name'=>'Temperaturen','id'=>'changed-temperatures','xml'=>'<Content><item id="vl"><name>Vorlauf</name><value>0.0°C</value></item><item id="rl"><name>Rücklauf</name><value>—</value></item></Content>']]]);
};
$tests['Java remains the preferred measurement source and objects retain valid zero values'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        invoke($module,'StoreVisualizationPoints',[10=>0.0]);
        invoke($module,'UpdateWebEEV');
        check(invoke($module,'GetVisualizationPointData','SupplyTemperature')['value']===0.0,'Supplement replaced valid Java zero');
    },['extra'=>[['name'=>'Temperaturen','id'=>'temperatures','xml'=>'<Content><item><name>Vorlauf</name><value>35.0 °C</value></item></Content>']]]);
};

$tests['automatic WebSocket supplement still runs after a failed Java exchange'] = static function():void {
    withEEVPeer(static function(int $port):void {
        withServer([[pack('N*',3004,0),pack('N*',3003,0,0)]],static function(Luxtronik $java) use($port):void {
            $module=new WebEEVTestModule();$module->webTestPort=$port;
            $module->properties['IPAddress']='127.0.0.1';$module->properties['Port']=$java->properties['Port'];
            $module->properties['CommunicationMode']='java';
            $module->Update();
            $data=invoke($module,'BuildVisualizationData');
            check($data['eEVHeatingOpening']['value']===42.9 && $data['eEVHeatingOpening']['source']==='WebSocket','Java failure prevented independent WebSocket readings');
        });
    });
};
$tests['Java with WebSocket supplementation retains the confirmed Java control write and readback'] = static function():void {
    $values=array_fill(0,106,0);$values[105]=505;
    withServer([[pack('N*',3002,105,505),pack('N*',3002,105)],[pack('N*',3003,0),frame(3003,$values)]],static function(Luxtronik $module):void {
        $module->properties['CommunicationMode']='java';$module->properties['WWsetVisible']=true;
        invoke($module,'CreateVariableProfiles');
        $module->RegisterVariableFloat('Anpassung_WW','Existing target','WPLUX.Wset');$module->EnableAction('Anpassung_WW');
        $id=$module->GetIDForIdent('Anpassung_WW');
        $module->RequestAction('SetNumericControl',['function'=>'warmWaterSetpoint','value'=>50.5]);
        check($module->GetIDForIdent('Anpassung_WW')===$id && GetValue($id)===50.5,'Existing Java action changed when selecting WebSocket measurements');
    });
};

$tests['all 25 outputs of the real firmware response have separate typed mappings'] = static function():void {
    $clientSnapshot=[];
    withEEVPeer(static function(int $port) use(&$clientSnapshot):void {
        $client=new LuxtronikWebEEVClient('127.0.0.1',$port,1000);
        $client->read('999999');$clientSnapshot=$client->snapshot();
    },['xml'=>file_get_contents(__DIR__.'/fixtures/web-outputs.xml')]);
    $module=new Luxtronik();
    $values=invoke($module,'MapWebSnapshot',$clientSnapshot);
    check($clientSnapshot['count']===25 && count(array_unique(array_column($values,'webId')))===25,'Real output coverage incomplete');
    check($values['SHI_Pumpe_Mischkreis_1']['value']===true,'FUP 1 was confused with HUP');
    check($values['Ausgang_Heizungsumwaelzpumpe']['value']===true && $values['Steuersignal_Umwaelzpumpe']['value']===44.5,'HUP relay and command confused');
    check($values['Analog_Ausgang_21']['value']===0.0 && $values['Ventil_BOSUP']['value']===true,'Additional output missing');
    check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Unselected real outputs created objects');
};

$tests['WebSocket measurements remain preferred when Modbus is selected only for writing'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $module->properties['CommunicationMode']='modbus';$module->properties['IDListe']='[{"id":39,"enabled":true}]';
        invoke($module,'RegisterVariableBoolean','Ausgang_Heizungsumwaelzpumpe','HUP','~Switch');
        $id=$module->GetIDForIdent('Ausgang_Heizungsumwaelzpumpe');
        invoke($module,'SetBuffer','SHICache',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'stale'=>false,
            'points'=>['input:10354'=>['value'=>false,'changedAt'=>0]]]));
        invoke($module,'UpdateWebEEV');
        $reading=invoke($module,'GetVisualizationPointData','HeatingCircuitPumpRunning1');
        check($reading['value']===true && $reading['source']==='WebSocket' && GetValue($id)===true,'Write selection incorrectly changed measurement priority');
    },['xml'=>file_get_contents(__DIR__.'/fixtures/web-outputs.xml')]);
};

$tests['engineering units negative temperatures pressure duplicates durations and energy keep native semantics'] = static function():void {
    $module=new Luxtronik();
    $snapshot=['groups'=>[
        ['name'=>'Temperaturen','fields'=>[['id'=>'outdoor','name'=>'Außentemperatur','raw'=>'-2,5°C'],['id'=>'superheat','name'=>'Überhitzung','raw'=>'0.0 K']]],
        ['name'=>'Eingänge','fields'=>[['id'=>'hp-switch','name'=>'HD','raw'=>'Aus'],['id'=>'hp-pressure','name'=>'HD','raw'=>'8.88 bar'],['id'=>'evu2','name'=>'EVU 2','raw'=>'Aus']]],
        ['name'=>'Ablaufzeiten','fields'=>[['id'=>'runtime','name'=>'WP Seit','raw'=>'01:02:03'],['id'=>'zwe-release','name'=>'Freig. ZWE','raw'=>'01:00:00']]],
        ['name'=>'Wärmemenge','fields'=>[['id'=>'energy','name'=>'Heizung','raw'=>'1234.5 kWh']]],
    ]];
    $values=invoke($module,'MapWebSnapshot',$snapshot);
    check($values['Aussentemperatur']['value']===-2.5 && $values['Ueberhitzung']['value']===0.0,'Temperature units or zero converted twice');
    check($values['Eingang_Hochdruck_Kaeltekreis']['value']===false && $values['Hochdruck']['value']===8.88,'HD switch and pressure confused');
    check($values['EVU_2']['value']==='0','Legacy String state became an empty string instead of zero');
    check($values['Waermepumpe_laeuft_seit']['value']==='1h 2m','Duration does not match native representation');
    check(!isset($values['Waermepumpentyp']),'ZWE release timer incorrectly reused the heat pump type Ident');
    check($values['Waermemengenzaehler_Heizung']['value']===1234.5,'Energy scaled twice');
};

$tests['Java stays default and successful Java values request only the EEV page'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        check($module->properties['CommunicationMode']==='java' && !$module->properties['ModbusReadSupplement'],'Defaults changed');
        invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>276]));
        invoke($module,'StoreVisualizationPoints',[10=>0.0]);
        invoke($module,'SetBuffer','WebInventory',json_encode(['source'=>invoke($module,'WebEEVSourceKey'),'nextAttempt'=>time()+86400]));
        invoke($module,'UpdateWebEEV');
        $cache=json_decode($module->GetWebSocketData(),true);
        check(array_keys($cache['values'])===['EEV_Heizen','EEV_Kuehlen'],'Unneeded page fields retained as fallback values');
        check(invoke($module,'GetVisualizationPointData','SupplyTemperature')['value']===0.0,'Java zero was replaced');
        $form=fields(json_decode($module->GetConfigurationForm(),true));
        check(!isset($form['CommunicationMode']) && $form['ModbusWrites']['type']==='CheckBox' && !$form['ModbusWrites']['value'],'Write selection is not simplified');
        $rows=array_column($form['IDListe']['values'],null,'id');
        check($rows[10]['source']==='Java' && $rows[400019]['source']==='Keine aktuellen Daten','Coverage labels imply unrequested reads');
    },['expectPages'=>['Ausgänge'],'extra'=>[['name'=>'Temperaturen','id'=>'unused-temperatures','xml'=>'<Content><item><name>Vorlauf</name><value>99.0°C</value></item></Content>']]]);
};

$tests['fourteen additional fields have stable selectable IDs correct types and ID positions without renaming objects'] = static function():void {
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/web-additional.json'),true);
    $extra=[];
    foreach($fixture['groups'] as $i=>$group) {
        $xml='<Content>';
        foreach($group['fields'] as $field) $xml.='<item id="'.htmlspecialchars($field['id']).'"><name>'.htmlspecialchars($field['name']).'</name><value>'.htmlspecialchars($field['raw']).'</value></item>';
        $extra[]=['name'=>$group['name'],'id'=>'additional-'.$i,'xml'=>$xml.'</Content>'];
    }
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $module->properties['IDListe']=json_encode(array_map(static fn($id)=>['id'=>$id,'enabled'=>true],range(400001,400019)));
        $module->RegisterVariableFloat('EEV_Heizen','Mein bestehendes EEV','~Custom');
        $oldID=$module->GetIDForIdent('EEV_Heizen');
        invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>276]));
        invoke($module,'UpdateWebEEV');
        $values=json_decode($module->GetWebSocketData(),true)['values'];
        check(count($values)===20,'Additional values or required FUP1 reading incomplete');
        $definitions=invoke($module,'WebDefinitions');
        foreach(range(400001,400019) as $selection) {
            $id=$module->GetIDForIdent($definitions[$selection]['ident']);
            check($id!==false && IPS_GetObject($id)['ObjectPosition']===$selection,'Wrong ID position for '.$selection);
        }
        check($module->GetIDForIdent('EEV_Heizen')===$oldID && IPS_GetName($oldID)==='Mein bestehendes EEV'
            && IPS_GetVariable($oldID)['VariableProfile']==='~Custom','Existing object ID Ident name or profile changed');
        check(GetValue($module->GetIDForIdent('Freigabe_ZWE_Restzeit'))==='1h 0m','ZWE timer unit incorrect');
        check(GetValue($module->GetIDForIdent('Freigabe_Kuehlung_Restzeit'))==='11h 59m','Cooling timer became boolean');
        check(GetValue($module->GetIDForIdent('Durchschnittliche_Laufzeit_Verdichter_1'))==='2h 11m','Mean runtime interpreted as minutes/seconds');
        check(GetValue($module->GetIDForIdent('Softwarestand'))==='V3.86.1 (r7762)','Full software text truncated');
        check(GetValue($module->GetIDForIdent('Abtaubedarf'))===29.8 && GetValue($module->GetIDForIdent('GLT_Art'))==='Aus','Percentage or text state incorrect');
        check(GetValue($module->GetIDForIdent('Waermemengenzaehler_Seit_Datum'))===54307.8
            && GetValue($module->GetIDForIdent('Waermemengenzaehler_Seit_Reset'))===54307.8,'Distinct counters collapsed or rescaled');
        $module->properties['IDListe']='[{"id":400001,"enabled":true}]';invoke($module,'SyncWebVariableSelection');
        check($module->GetIDForIdent('Softwarestand')===false && $module->GetIDForIdent('EEV_Heizen')===$oldID,'Deselection touched the retained object');
    },['xml'=>file_get_contents(__DIR__.'/fixtures/web-outputs.xml'),'extra'=>$extra,
        'expectPages'=>array_merge(['Ausgänge'],array_column($extra,'name'))]);
};

$tests['failed Java automatically requests replacements and recovery returns to the same Java Ident'] = static function():void {
    $module=new WebEEVTestModule();$module->properties['IPAddress']='127.0.0.1';
    $module->properties['IDListe']='[{"id":10,"enabled":true}]';
    $module->RegisterVariableFloat('Vorlauftemperatur_Heizkreis','Mein Vorlauf','~Temperature');
    $id=$module->GetIDForIdent('Vorlauftemperatur_Heizkreis');
    invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>0,'failed'=>true]));
    withEEVPeer(static function(int $port) use($module,$id):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        check(GetValue($id)===35.5 && invoke($module,'GetVisualizationPointData','SupplyTemperature')['source']==='WebSocket','Missing Java value was not replaced');
    },['extra'=>[['name'=>'Temperaturen','id'=>'reserve-temperature','xml'=>'<Content><item id="vl"><name>Vorlauf</name><value>35.5°C</value></item></Content>']],
        'expectPages'=>['Ausgänge','Temperaturen']]);
    invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>276]));
    invoke($module,'StoreVisualizationPoints',[10=>0.0]);invoke($module,'SetValueIfChanged','Vorlauftemperatur_Heizkreis',0.0);
    withEEVPeer(static function(int $port) use($module,$id):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        $point=invoke($module,'GetVisualizationPointData','SupplyTemperature');
        check($point['value']===0.0 && $point['source']==='Java' && GetValue($id)===0.0,'Recovery did not restore Java zero');
        check($module->GetIDForIdent('Vorlauftemperatur_Heizkreis')===$id && IPS_GetName($id)==='Mein Vorlauf','Reserve switched object identity');
        check(!isset(json_decode($module->GetWebSocketData(),true)['values']['Vorlauftemperatur_Heizkreis']),'Recovered Java value still fetched over WebSocket');
    },['expectPages'=>['Ausgänge']]);
};

$tests['first and daily inventory discover other pages while normal polling remains targeted'] = static function():void {
    $module=new WebEEVTestModule();$module->properties['IPAddress']='127.0.0.1';
    invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>276]));
    $extra=[['name'=>'Temperaturen','id'=>'unused-page','xml'=>'<Content><item><name>Vorlauf</name><value>99.0°C</value></item></Content>'],
        ['name'=>'Anlagenstatus','id'=>'software','xml'=>'<Content><item id="version"><name>Softwarestand</name><value>V3.86.1 (r7762)</value></item></Content>']];
    withEEVPeer(static function(int $port) use($module):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        $cache=json_decode($module->GetWebSocketData(),true);
        check($cache['inventory']['discovery']['fullScan'] && count($cache['inventory']['discovery']['groups'])===3,'First contact did not inventory all pages');
        check(!isset($cache['values']['Softwarestand']) && $module->GetIDForIdent('Softwarestand')===false,'Discovery promoted an unrequested object or value');
    },['extra'=>$extra,'expectPages'=>['Ausgänge','Temperaturen','Anlagenstatus']]);
    $module->properties['IDListe']='[{"id":400009,"enabled":true}]';
    withEEVPeer(static function(int $port) use($module):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        check(GetValue($module->GetIDForIdent('Softwarestand'))==='V3.86.1 (r7762)','Previously discovered extra was unavailable for selection');
        check(!json_decode($module->GetWebSocketData(),true)['discovery']['fullScan'],'Regular poll unexpectedly read every page');
    },['extra'=>$extra,'expectPages'=>['Ausgänge','Anlagenstatus']]);
    $inventory=json_decode($module->buffers['WebInventory'],true);$inventory['nextAttempt']=time()-1;
    invoke($module,'SetBuffer','WebInventory',json_encode($inventory));
    withEEVPeer(static function(int $port) use($module):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        check(json_decode($module->GetWebSocketData(),true)['inventory']['nextAttempt']>time()+86000,'Daily inventory not renewed');
    },['extra'=>$extra,'expectPages'=>['Ausgänge','Temperaturen','Anlagenstatus']]);
};

$tests['configuration diagnostic downloads the full read-only report without promoting values or exposing the password'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';$module->properties['WebSocketPassword']='test-secret';
        $json=$module->RunWebSocketDiagnostic();$report=json_decode($json,true);
        check($report['ok'] && $report['count']===3 && count($report['groups'])===2,'Manual configuration diagnostic missed pages');
        check(!str_contains($json,'test-secret'),'Diagnostic exposed authentication');
        check($module->GetIDForIdent('EEV_Heizen')===false && ($module->buffers['WebEEVCache']??'')==='','Manual diagnostic changed selected live values or created objects');
        $form=fields(json_decode($module->GetConfigurationForm(),true));
        check(isset($form['WebInventoryStatus']) && !isset($form['WebSocketSupplement']),'Diagnostic still requires a visualization switch');
        check(array_filter($module->formUpdates,static fn($u)=>$u[0]==='WebInventoryStatus')!==[],'Configuration status was not updated');
        check(!str_contains($module->GetVisualizationTile(),'wp-eev-test') && !str_contains($module->GetVisualizationTile(),'wp-web-test'),'Diagnostics retained in visualization');
    },['password'=>'test-secret','extra'=>[['name'=>'Temperaturen','id'=>'temp','xml'=>'<Content><item><name>Vorlauf</name><value>24.4°C</value></item></Content>']],
        'expectPages'=>['Ausgänge','Temperaturen']]);
};

$tests['WebSocket fault history reuses existing code and timestamp Idents with local dates and clears an empty log'] = static function():void {
    $module=new WebEEVTestModule();$module->properties['IPAddress']='127.0.0.1';$module->properties['WebSocketTimezone']='Europe/Zurich';
    $module->properties['IDListe']='[{"id":95,"enabled":true},{"id":100,"enabled":true},{"id":400020,"enabled":true}]';
    $module->RegisterVariableString('Fehlercode_Fehler_0','Mein Fehlercode','');$old=$module->GetIDForIdent('Fehlercode_Fehler_0');
    invoke($module,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'count'=>276]));
    invoke($module,'StoreVisualizationPoints',[95=>1,100=>'999',105=>'1']);
    withEEVPeer(static function(int $port) use($module,$old):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        $history=invoke($module,'GetFaultHistoryData');
        check($history['source']==='WebSocket' && count($history['entries'])===5 && $history['entries'][0]['code']===718,'Fault history did not prefer WebSocket');
        check(str_contains($history['entries'][0]['text'],'Max. Aussentemp.'),'Full fault text missing');
        check(GetValue($old)==='718' && $module->GetIDForIdent('Fehlercode_Fehler_0')===$old && IPS_GetName($old)==='Mein Fehlercode','Existing fault Ident or object changed');
        check(GetValue($module->GetIDForIdent('Zeitstempel_Fehler_0'))===1708090519,'Fault timestamp does not respect device timezone');
        $values=json_decode($module->GetWebSocketData(),true)['values'];
        check($values['Zeitstempel_Fehler_2']['value']===1665921109,'Historic DST was ignored');
        check(IPS_GetObject($module->GetIDForIdent('Fehlerspeicher_Meldungen'))['ObjectPosition']===400020,'Fault aggregate position incorrect');
        $rows=array_column(fields(json_decode($module->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
        check($rows[100]['source']==='WebSocket' && $rows[95]['source']==='WebSocket','Fault source column incorrectly claims Java');
    },['extra'=>[['name'=>'Fehlerspeicher','id'=>'faults','xml'=>file_get_contents(__DIR__.'/fixtures/web-faults.xml')]],'expectPages'=>['Ausgänge','Fehlerspeicher']]);
    $cache=json_decode($module->buffers['WebEEVCache'],true);$cache['nextAttempt']=0;invoke($module,'SetBuffer','WebEEVCache',json_encode($cache));
    withEEVPeer(static function(int $port) use($module,$old):void {
        $module->webTestPort=$port;invoke($module,'UpdateWebEEV');
        $history=invoke($module,'GetFaultHistoryData');
        check($history['available'] && $history['entries']===[] && GetValue($old)==='0','Cleared fault log remained stale or became unknown');
        check(GetValue($module->GetIDForIdent('Fehlerspeicher_Meldungen'))==='','Cleared aggregate retained old messages');
    },['extra'=>[['name'=>'Fehlerspeicher','id'=>'faults','xml'=>'<Content/>']],'expectPages'=>['Ausgänge','Fehlerspeicher']]);
};

$failed=0;
foreach($tests as $name=>$test) {
    try {$test();echo 'PASS '.$name."\n";} catch(Throwable $error) {++$failed;fwrite(STDERR,'FAIL '.$name.': '.$error->getMessage()."\n");}
}
echo count($tests).' tests, '.$failed." failures\n";
if ($failed) exit(1);
$dir=getenv('WP_TEST_ARTIFACT_DIR');
if($dir!==false && $dir!=='') {
    if(!is_dir($dir)) mkdir($dir,0777,true);
    file_put_contents($dir.'/web-eev.html','<meta charset="utf-8">'.$GLOBALS['testWebEEVHtml']);
    file_put_contents($dir.'/web-eev-cooling.json',json_encode($GLOBALS['testWebEEVCooling']));
}
