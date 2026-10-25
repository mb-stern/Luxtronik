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
            $receive=static function() use ($read): array {
                [$first,$second]=array_values(unpack('C2',$read(2)));
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
            check($receive()===[1,'GET;'.$id],'Output ID was not discovered');
            $xml=$options['xml']??('<Content><item id="'.($options['heatingID']??'0x53fcd4').'"><name>EEV Heizen</name><value>'.($options['heat']??'42.9 %').'</value></item><item id="0x53fd14"><name>EEV Kühlen</name><value>'.($options['cool']??'100.0 %').'</value></item><name>Ausgänge</name></Content>');
            if(!empty($options['large'])) $xml=str_replace('</Content>','<!--'.str_repeat('x',70000).'--></Content>',$xml);
            if(!empty($options['fragment'])) {
                $split=intdiv(strlen($xml),2);
                $send(substr($xml,0,$split),1,false);
                $send('heartbeat',9);
                check($receive()===[10,'heartbeat'],'Ping was not answered with pong');
                $send(substr($xml,$split),0,true);
            } else $send($xml);
            foreach($options['extra']??[] as $menu) {
                check($receive()===[1,'GET;'.$menu['id']],'Additional information page was not read');
                $send($menu['xml']);
            }
            // Diagnostic must close; any further application command would be a failure.
            check(fread($connection,1)==='','Unexpected command after output read');
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
    protected function NewWebEEVClient(): LuxtronikWebEEVClient {return new LuxtronikWebEEVClient('127.0.0.1',$this->webTestPort,500);}
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
    check($row[0]['webAvailability']==='Noch nicht geprüft','An unmeasured device was labelled available');
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
    check($row['webAvailability']==='Teilabfrage: kein Messwert','Partial scan claimed a field was unavailable');
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
    check($row['webAvailability']==='Abfrage fehlgeschlagen' && str_contains(json_encode($form,JSON_UNESCAPED_UNICODE),$message),'Connection error hidden from the configuration');
};

$tests['all information pages are counted and WebSocket priority preserves native zero with Java fallback'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $module->properties['CommunicationMode']='websocket';
        $module->properties['IDListe']='[{"id":10,"enabled":true},{"id":11,"enabled":true}]';
        $module->RegisterVariableFloat('Vorlauftemperatur_Heizkreis','Existing supply','~Temperature');
        $supplyID=$module->GetIDForIdent('Vorlauftemperatur_Heizkreis');
        invoke($module,'StoreVisualizationPoints',[10=>55.0,11=>28.3]);
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
        check($row['webAvailability']==='Messwert geliefert','Valid zero was not shown as actually supplied');
        $returnRow=array_values(array_filter($rows,static fn($row)=>$row['id']===11))[0];
        check($returnRow['webAvailability']==='Kein Messwert geliefert','Invalid reading was shown as an available measurement');
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
            $module->properties['CommunicationMode']='websocket';
            $module->Update();
            $data=invoke($module,'BuildVisualizationData');
            check($data['eEVHeatingOpening']['value']===42.9 && $data['eEVHeatingOpening']['source']==='WebSocket','Java failure prevented independent WebSocket readings');
        });
    });
};
$tests['WebSocket measurement mode retains the confirmed Java control write and readback'] = static function():void {
    $values=array_fill(0,106,0);$values[105]=505;
    withServer([[pack('N*',3002,105,505),pack('N*',3002,105)],[pack('N*',3003,0),frame(3003,$values)]],static function(Luxtronik $module):void {
        $module->properties['CommunicationMode']='websocket';$module->properties['WWsetVisible']=true;
        invoke($module,'CreateVariableProfiles');
        $module->RegisterVariableFloat('Anpassung_WW','Existing target','WPLUX.Wset');$module->EnableAction('Anpassung_WW');
        $id=$module->GetIDForIdent('Anpassung_WW');
        $module->RequestAction('SetNumericControl',['function'=>'warmWaterSetpoint','value'=>50.5]);
        check($module->GetIDForIdent('Anpassung_WW')===$id && GetValue($id)===50.5,'Existing Java action changed when selecting WebSocket measurements');
    });
};

$tests['all 25 outputs of the real firmware response have separate typed mappings'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        invoke($module,'UpdateWebEEV');
        $snapshot=json_decode($module->GetWebSocketData(),true);
        check($snapshot['discovery']['count']===25 && $snapshot['discovery']['mappedCount']===25,'Real output coverage incomplete');
        check($snapshot['values']['SHI_Pumpe_Mischkreis_1']['value']===true,'FUP 1 was confused with HUP');
        check($snapshot['values']['Ausgang_Heizungsumwaelzpumpe']['value']===true
            && $snapshot['values']['Steuersignal_Umwaelzpumpe']['value']===44.5,'HUP relay and command confused');
        check($snapshot['values']['Analog_Ausgang_21']['value']===0.0 && $snapshot['values']['Ventil_BOSUP']['value']===true,'Additional output missing');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Unselected real outputs created objects');
    },['xml'=>file_get_contents(__DIR__.'/fixtures/web-outputs.xml')]);
};

$tests['Modbus primary keeps a valid off state instead of replacing it with WebSocket on'] = static function():void {
    withEEVPeer(static function(int $port):void {
        $module=new WebEEVTestModule();$module->webTestPort=$port;$module->properties['IPAddress']='127.0.0.1';
        $module->properties['CommunicationMode']='modbus';$module->properties['IDListe']='[{"id":39,"enabled":true}]';
        invoke($module,'RegisterVariableBoolean','Ausgang_Heizungsumwaelzpumpe','HUP','~Switch');
        $id=$module->GetIDForIdent('Ausgang_Heizungsumwaelzpumpe');
        invoke($module,'SetBuffer','SHICache',json_encode(['source'=>invoke($module,'VisualizationSourceKey'),'stale'=>false,
            'points'=>['input:10354'=>['value'=>false,'changedAt'=>0]]]));
        invoke($module,'UpdateWebEEV');
        $reading=invoke($module,'GetVisualizationPointData','HeatingCircuitPumpRunning1');
        check($reading['value']===false && $reading['source']==='Modbus' && GetValue($id)===false,'WebSocket overrode valid Modbus off');
    },['xml'=>file_get_contents(__DIR__.'/fixtures/web-outputs.xml')]);
};

$tests['engineering units negative temperatures pressure duplicates durations and energy keep native semantics'] = static function():void {
    $module=new Luxtronik();
    $snapshot=['groups'=>[
        ['name'=>'Temperaturen','fields'=>[['id'=>'outdoor','name'=>'Außentemperatur','raw'=>'-2,5°C'],['id'=>'superheat','name'=>'Überhitzung','raw'=>'0.0 K']]],
        ['name'=>'Eingänge','fields'=>[['id'=>'hp-switch','name'=>'HD','raw'=>'Aus'],['id'=>'hp-pressure','name'=>'HD','raw'=>'8.88 bar']]],
        ['name'=>'Ablaufzeiten','fields'=>[['id'=>'runtime','name'=>'WP Seit','raw'=>'01:02:03'],['id'=>'zwe-release','name'=>'Freig. ZWE','raw'=>'01:00:00']]],
        ['name'=>'Wärmemenge','fields'=>[['id'=>'energy','name'=>'Heizung','raw'=>'1234.5 kWh']]],
    ]];
    $values=invoke($module,'MapWebSnapshot',$snapshot);
    check($values['Aussentemperatur']['value']===-2.5 && $values['Ueberhitzung']['value']===0.0,'Temperature units or zero converted twice');
    check($values['Eingang_Hochdruck_Kaeltekreis']['value']===false && $values['Hochdruck']['value']===8.88,'HD switch and pressure confused');
    check($values['Waermepumpe_laeuft_seit']['value']==='1h 2m','Duration does not match native representation');
    check(!isset($values['Waermepumpentyp']),'ZWE release timer incorrectly reused the heat pump type Ident');
    check($values['Waermemengenzaehler_Heizung']['value']===1234.5,'Energy scaled twice');
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
