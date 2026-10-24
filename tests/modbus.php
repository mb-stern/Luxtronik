<?php

require __DIR__.'/regression.php';

/** Independent peer implementing the documented wire address spaces, not the module decoder. */
function withSHI(callable $test,array $options=[],?Luxtronik $existing=null): array
{
    $catalog=json_decode(file_get_contents(__DIR__.'/fixtures/shi-registers.json'),true,512,JSON_THROW_ON_ERROR);
    $model=[];
    foreach ($catalog as $p) {
        $raw=$p['bank']==='holding'?(int)round(($p['min']??0)/$p['factor']):0;
        if ($p['bank']==='holding' && ($p['min']??0)<0) $raw=0;
        $model[$p['bank']][$p['address']]=$raw & 0xffff;
    }
    foreach (['input','discrete','holding'] as $bank) foreach ($options[$bank]??[] as $a=>$v) $model[$bank][$a]=$v;
    $folder=sys_get_temp_dir().'/shi-peer-'.bin2hex(random_bytes(6));mkdir($folder);
    $listener=stream_socket_server('tcp://127.0.0.1:0');
    $port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    $pid=pcntl_fork();check($pid!==-1,'Cannot fork SHI peer');
    if ($pid===0) {
        try {
            $read=static function ($connection,int $length): string {
                $result='';
                while (strlen($result)<$length) {
                    $part=fread($connection,$length-strlen($result));
                    if ($part===false || $part==='') return '';
                    $result.=$part;
                }
                return $result;
            };
            $requestCount=0;
            while (!file_exists($folder.'/done')) {
                $r=[$listener];$w=[];$e=[];
                if (!stream_select($r,$w,$e,0,100000)) continue;
                $connection=stream_socket_accept($listener,2);check($connection!==false,'Missing peer connection');
                stream_set_timeout($connection,2);
                $lastTransaction=0;
                while (($header=$read($connection,7))!=='') {
                    $h=unpack('nt/nprotocol/nlength/Cunit',$header);
                    check($h['protocol']===0 && $h['unit']===($options['unit']??1),'Bad request identity');
                    check($h['t']===$lastTransaction+1,'Bad transaction sequence');$lastTransaction=$h['t'];
                    $pdu=$read($connection,$h['length']-1);check(strlen($pdu)===$h['length']-1,'Truncated request');
                    $f=ord($pdu[0]);check(in_array($f,[2,3,4,16],true),'Unapproved Modbus function');
                    ++$requestCount;
                    $a=unpack('n',substr($pdu,1,2))[1];$count=unpack('n',substr($pdu,3,2))[1];
                    $words=$f===16?array_values(unpack('n*',substr($pdu,6))):[];
                    file_put_contents($folder.'/requests',json_encode(['function'=>$f,'address'=>$a,'count'=>$count,'words'=>$words])."\n",FILE_APPEND);
                    $bank=[2=>'discrete',3=>'holding',4=>'input',16=>'holding'][$f];
                    $fault=$options['fault']??[];
                    if (($fault['function']??null)===$f && ($fault['address']??null)===$a && $requestCount>=($fault['after']??0)) {
                        if (($fault['kind']??'')==='silent') {usleep(650000);break;}
                        if (($fault['kind']??'')==='truncate') {fwrite($connection,pack('nnnC',$h['t'],0,5,$h['unit'])."\4\2\0");break;}
                        if (($fault['kind']??'')==='identity') {fwrite($connection,pack('nnnCC',$h['t']+1,0,3,$h['unit'],$f|0x80)."\2");continue;}
                        if (($fault['kind']??'')==='ack') {fwrite($connection,pack('nnnCCnn',$h['t'],0,6,$h['unit'],16,$a+1,$count));continue;}
                    }
                    $raw=$model[$bank][$a]??null;
                    if ($raw===null || (is_array($raw) && isset($raw['exception']))) $reply=pack('CC',$f|0x80,is_array($raw)?$raw['exception']:2);
                    elseif ($f===16) {
                        check(strlen($pdu)===6+2*$count && ord($pdu[5])===2*$count,'Bad write frame');
                        // Only fixture-documented holdings may be written.
                        check($count===1 && isset($model['holding'][$a]),'Write outside documented register set');
                        if (empty($options['ignoreWrites'])) $model['holding'][$a]=$words[0];
                        $reply=pack('Cnn',16,$a,$count);
                    } elseif ($f===2) {
                        check($count===1,'Unexpected discrete range');$reply=pack('CCC',2,1,$raw?1:0);
                    } else {
                        $expectedCount=1;
                        foreach ($catalog as $p) if ($p['bank']===$bank && $p['address']===$a && str_ends_with($p['datatype'],'32')) $expectedCount=2;
                        check($count===$expectedCount,'Wrong register word count at '.$a);
                        $reply=pack('CC', $f,2*$count).($count===2?pack('nn',($raw>>16)&0xffff,$raw&0xffff):pack('n',$raw&0xffff));
                    }
                    $frame=pack('nnnC',$h['t'],0,strlen($reply)+1,$h['unit']).$reply;
                    if (!empty($options['fragment'])) {
                        foreach ([substr($frame,0,3),substr($frame,3,5),substr($frame,8)] as $part) {fwrite($connection,$part);usleep(1000);}
                    } else fwrite($connection,$frame);
                }
                fclose($connection);
            }
            fclose($listener);exit(0);
        } catch (Throwable $error) {fwrite(STDERR,'SHI peer: '.$error->getMessage()."\n");exit(1);}
    }
    fclose($listener);
    $m=$existing??new Luxtronik();$m->properties['CommunicationMode']='modbus';$m->properties['IPAddress']='127.0.0.1';
    $m->properties['ModbusJavaSupplement']=$options['javaSupplement']??false;
    $m->properties['ModbusPort']=$port;$m->properties['ModbusUnitID']=$options['unit']??1;
    try {$test($m);} finally {touch($folder.'/done');pcntl_waitpid($pid,$status);}
    $lines=file_exists($folder.'/requests')?file($folder.'/requests',FILE_IGNORE_NEW_LINES):[];
    foreach (glob($folder.'/*') as $file) unlink($file);rmdir($folder);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'SHI peer failed');
    return array_map(static fn(string $l):array=>json_decode($l,true,512,JSON_THROW_ON_ERROR),$lines);
}

function shiVariable(Luxtronik $m,string $ident,mixed $value,string $name='Existing',string $profile=''): int
{
    $method=is_bool($value)?'RegisterVariableBoolean':(is_int($value)?'RegisterVariableInteger':(is_float($value)?'RegisterVariableFloat':'RegisterVariableString'));
    invoke($m,$method,$ident,$name,$profile);$id=$m->GetIDForIdent($ident);$GLOBALS['testVariables'][$id]['value']=$value;return $id;
}
function shiThrows(callable $fn,string $message='Expected rejection'): void
{
    try {$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}
    throw new LogicException($message);
}
function shiPayload(Luxtronik $m): array {return ['config'=>invoke($m,'BuildCardConfig'),'data'=>invoke($m,'BuildVisualizationData'),'controls'=>invoke($m,'BuildControlData')];}

$tests['catalogue covers every documented bank exactly and retains the original 22 mappings'] = static function ():void {
    $m=new Luxtronik();$catalog=array_values(invoke($m,'SHICatalog'));
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/shi-registers.json'),true,512,JSON_THROW_ON_ERROR);
    check($catalog==$fixture,'Register catalogue differs from reviewed manual fixture');
    check(count(array_filter($catalog,fn($p)=>$p['bank']==='input'))===76,'Missing inputs');
    check(count(array_filter($catalog,fn($p)=>$p['bank']==='discrete'))===12,'Missing discrete points');
    check(count(array_filter($catalog,fn($p)=>$p['bank']==='holding'))===41,'Missing documented writable points');
    check(count(array_unique(array_column($catalog,'ident')))===129,'Ambiguous Idents');
    $legacy=[];foreach ($catalog as $p) if ($p['legacy']!==null) $legacy[$p['legacy']]=$p['address'];
    foreach ([10=>10105,11=>10100,12=>10101,13=>10102,15=>10108,16=>10109,17=>10120,18=>10121,19=>10110,20=>10111,21=>10140,22=>10141,24=>10150,25=>10151,151=>10322,152=>10324,153=>10328,154=>10320,189=>10113,227=>10106,257=>10300,268=>10301] as $id=>$address) check($legacy[$id]===$address,'Existing mapping changed');
};
$tests['interface column identifies Java Modbus and shared values without renaming objects'] = static function ():void {
    $m=new Luxtronik();check($m->properties['CommunicationMode']==='java','Java default changed');
    foreach (['java','modbus'] as $mode) {
        $m->properties['CommunicationMode']=$mode;$form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
        check($rows[15]['interface']==='Java + Modbus' && $rows[14]['interface']==='Java' && $rows[310041]['interface']==='Modbus' && $rows[310006]['interface']==='Java + Modbus','Protocol overview missing');
        check($rows[15]['name']==='Aussentemperatur' && $rows[310006]['name']==='Warmwasser Soll','Name changed or MB suffix survives');
    }
};
$tests['full fragmented scan reads all 129 points without writes or measurement objects'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->Update();check($m->status===102 && $m->errors===[],'Full scan failed');
        $snapshot=json_decode($m->GetSHIData(),true);check(count($snapshot['values'])===129 && !$snapshot['stale'],'Incomplete cache');
        check(count(array_filter($snapshot['values'],fn($p)=>$p['available']))===129,'Values unavailable');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$m->InstanceID)===[],'Unselected objects created');
    },['fragment'=>true]);
    check(count($requests)===129 && array_unique(array_column($requests,'function'))===[0=>4,76=>2,88=>3],'Wrong polling functions or count');
};
$tests['signed temperatures energy counters unsigned hours and native units are decoded once'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $m->properties['IDListe']=json_encode(array_map(fn($id)=>['id'=>$id,'enabled'=>true],[15,268,257,63,161,136,137,110310,110326]));
        $m->Update();
        foreach (['Aussentemperatur'=>-2.5,'Leistungsaufnahme'=>1800.0,'Waermeleistung'=>5.7,'Betriebsstunden_Waermepumpe'=>3000000000,'Betriebstundenzaehler_Solar'=>'12h 0m','Mischkreis_3_Vorlauftemperatur'=>31.2,'Mischkreis_3_Vorlauf_Soll_Temperatur'=>33.0,'SHI_Elektrische_Energie_Gesamt'=>800.0,'SHI_Thermische_Energie_Kuehlung'=>-2.5] as $ident=>$expected) check(GetValue($m->GetIDForIdent($ident))===$expected,$ident.' unit/type wrong');
    },['input'=>[10108=>65511,10301=>18,10300=>57,10404=>3000000000,10416=>12,10160=>312,10161=>330,10310=>8000,10326=>0xffffffe7]]);
};
$tests['Java to SHI to Java preserves existing object IDs names profiles types and settings'] = static function ():void {
    $m=new Luxtronik();$a=shiVariable($m,'Aussentemperatur',7.5,'Mein Außenfühler','~Temperature');$g=shiVariable($m,'Heisgastemperatur',45.0,'Heißgas');
    $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":14,"enabled":true}]';$javaPort=$m->properties['Port'];
    withSHI(static function(Luxtronik $m) use($a,$g,$javaPort):void {
        $m->Update();check($m->GetIDForIdent('Aussentemperatur')===$a && GetValue($a)===-2.5,'Shared object replaced');
        check($m->GetIDForIdent('Heisgastemperatur')===$g && GetValue($g)===45.0,'Java-only value removed');
        check(IPS_GetName($a)==='Mein Außenfühler' && IPS_GetVariable($a)['VariableProfile']==='~Temperature','Object metadata changed');
        check($m->properties['Port']===$javaPort,'Java connection changed');
    },['input'=>[10108=>65511]],$m);
    $m->properties['CommunicationMode']='java';$m->properties['IPAddress']='0.0.0.0';$m->ApplyChanges();
    check($m->GetIDForIdent('Aussentemperatur')===$a && IPS_GetName($a)==='Mein Außenfühler','Switch-back replaces measurement');
};
$tests['SHI states and three mixed circuits feed the graphic independently of IDListe'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $m->properties['VisualizationHeatingCircuit3']=true;$m->Update();$p=shiPayload($m);
        check($p['data']['operatingStatus']['value']===7 && $p['data']['heatingPumpCoolingMode']['value']===true,'SHI mode not connected');
        check($p['data']['compressorRunning']['value']===true,'Compressor bit not decoded');
        check($p['data']['mixerActual3']['value']===31.2 && $p['data']['mixerTarget3']['value']===33.0,'MK3 absent');
        check($p['data']['mixerPump1']['value']===false && $p['data']['heatingCircuitPumpRunning']['value']===true,'FUP1 confused with HUP');
        check($p['data']['compressorRunning']['variableId']===0,'Derived measurement object created');
        check($p['config']['eevHeatingOpening']==='' && $p['config']['compressorFrequencyActual']==='','Undocumented data invented');
    },['input'=>[10002=>7,10000=>1,10160=>312,10161=>330,10351=>0,10354=>1]]);
};
$tests['unavailable values preserve objects but disappear from current internal readings'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $id=shiVariable($m,'Aussentemperatur',9.0);$m->properties['IDListe']='[{"id":15,"enabled":true}]';$m->Update();
        check(GetValue($id)===9.0 && $m->status===204,'Unavailable value overwrote existing object');
        check(!isset(shiPayload($m)['data']['outdoorTemperature']),'Old Java object passed off as current SHI');
    },['input'=>[10108=>32767,10160=>['exception'=>2]],'holding'=>[10071=>['exception'=>1]]]);
};
$tests['invalid and incomplete transport replies cannot publish a partial scan'] = static function ():void {
    foreach (['identity','truncate','silent'] as $kind) {
        withSHI(static function(Luxtronik $m):void {
            $id=shiVariable($m,'Aussentemperatur',8.0);$m->properties['IDListe']='[{"id":15,"enabled":true}]';$start=microtime(true);$m->Update();
            check($m->status===201 && GetValue($id)===8.0,'Partial scan published');
            check(json_decode($m->GetSHIData(),true)['stale'],'Failed scan reported current');
            check(microtime(true)-$start<1.0,'Unbounded timeout');
        },['input'=>[10108=>-25],'fault'=>['function'=>4,'address'=>10120,'kind'=>$kind]]);
    }
};
$tests['all 41 holding writes validate encode confirm and activate only documented dependencies'] = static function ():void {
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/shi-registers.json'),true);
    foreach ($fixture as $point) {
        if ($point['bank']!=='holding') continue;
        $value=$point['options']!==[]?(int)array_key_last($point['options']):($point['min']<0?-1.5:$point['min']+$point['factor']);
        if ($point['datatype']==='bool') $value=true;
        $input=[10122=>300,10123=>750];$di=array_fill_keys(range(10000,10011),1);
        $options=['input'=>$input,'discrete'=>$di,'holding'=>[10040=>1]];
        $requests=withSHI(static function(Luxtronik $m) use($point,$value):void {
            check($m->SetSHIValue($point['ident'],$value),'Write not confirmed');
            $snapshot=json_decode($m->GetSHIData(),true);
            $read=array_values(array_filter($snapshot['values'],fn($p)=>$p['bank']==='holding' && $p['address']===$point['address']))[0];
            check(abs((float)$read['value']-(float)$value)<0.000001,'Readback value not retained');
        },$options);
        $writes=array_values(array_filter($requests,fn($r)=>$r['function']===16));
        $expected=((int)round((float)$value/$point['factor'])) & 0xffff;
        check($writes[0]['address']===$point['address'] && $writes[0]['words']===[$expected],'Wrong write encoding/order');
        foreach ($writes as $write) check($write['address']===$point['address'] || array_key_exists($write['address'],$point['deps']),'Undocumented collateral write');
    }
};
$tests['invalid types ranges enum gaps and fractional resolution are rejected before connecting'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        foreach ([['Anpassung_Temp',-20.1],['Anpassung_Temp',0.01],['Anpassung_Temp','1'],['Anpassung_Temp',INF],['Anpassung_Temp',NAN],['Anpassung_WW',76],['SHI_Overall_Modus',1],['SHI_Modus_Heizen',2.5],['SHI_LPC_Modus',3],['SHI_Sperre_Heizen',[]],['SHI_Extra_Warmwasser',2],['Mode_WW',0],['set_223',1],['unbekannt',1],['Aussentemperatur',5],['SHI_Extra_Warmwasser_Dauer',10]] as [$ident,$value]) shiThrows(fn()=>$m->SetSHIValue($ident,$value));
    });check($requests===[],'Invalid action connected');
};
$tests['unavailable or disabled functions and unmet prerequisites never write'] = static function ():void {
    foreach ([['discrete'=>[10000=>0]],['discrete'=>[10000=>1],'holding'=>[10002=>32767]],['discrete'=>[10000=>1],'holding'=>[10065=>['exception'=>2]]]] as $options) {
        $requests=withSHI(static function(Luxtronik $m):void {shiThrows(fn()=>$m->SetSHIValue('Anpassung_Temp',1.0));},$options);
        check(array_filter($requests,fn($r)=>$r['function']===16)===[],'Failed preflight wrote data');
    }
    $requests=withSHI(static function(Luxtronik $m):void {shiThrows(fn()=>$m->SetSHIValue('SHI_PC_Limit',1.0));});
    check(array_filter($requests,fn($r)=>$r['function']===16)===[],'PC limit implicitly selected limitation mode');
};
$tests['controller DHW bounds and conflicting room-control modes are respected before writing'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {shiThrows(fn()=>$m->SetSHIValue('Anpassung_WW',60.0));shiThrows(fn()=>$m->SetSHIValue('Anpassung_RBE',22.0));},['input'=>[10123=>550],'discrete'=>[10001=>1,10005=>1],'holding'=>[10010=>2]]);
    check(array_filter($requests,fn($r)=>$r['function']===16)===[],'Invalid combined control wrote data');
};
$tests['failed acknowledgements and mismatched readback do not activate a mode or report success'] = static function ():void {
    foreach ([['fault'=>['function'=>16,'address'=>10002,'kind'=>'ack']],['ignoreWrites'=>true]] as $option) {
        $requests=withSHI(static function(Luxtronik $m):void {shiThrows(fn()=>$m->SetSHIValue('Anpassung_Temp',-2.5));check($m->status===201,'Write failure not reported');},$option+['discrete'=>[10000=>1]]);
        check(array_filter($requests,fn($r)=>$r['function']===16 && $r['address']!==10002)===[],'Unconfirmed value activated a mode');
    }
};
$tests['selected holding controls preserve existing IDs names types and never write on ApplyChanges'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $id=shiVariable($m,'Anpassung_Temp',0.0,'Eigener Name');
        $m->properties['IDListe']='[{"id":310002,"enabled":true}]';$m->ApplyChanges();
        check($m->GetIDForIdent('Anpassung_Temp')===$id && IPS_GetName($id)==='Eigener Name','Control renamed/replaced');
        check($m->actions['Anpassung_Temp']===true,'Selected holding not actionable');
        $m->properties['CommunicationMode']='java';invoke($m,'DisableSHIActions');
        check(!$m->actions['Anpassung_Temp'],'SHI action survives switch to Java');
        shiThrows(fn()=>$m->SetSHIValue('Anpassung_Temp',1.0));
    });check(array_filter($requests,fn($r)=>$r['function']===16)===[],'Configuration wrote default values');
};
$tests['shared native numeric controls route through confirmed SHI writes without changing their names'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['WWsetVisible']=true;$m->properties['TempsetVisible']=true;
        $m->ApplyChanges();$id=$m->GetIDForIdent('Anpassung_WW');check($id!==false,'Shared control not created');
        $p=shiPayload($m);check($p['controls']['warmWaterSetpoint']['configured'] && !$p['controls']['hotwater']['configured'],'SHI control semantics conflated with Java modes');
        $m->RequestAction('SetNumericControl',['function'=>'warmWaterSetpoint','value'=>50.5]);
        check($m->GetIDForIdent('Anpassung_WW')===$id && GetValue($id)===50.5,'Shared control lost confirmed value/ID');
        $m->RequestAction('Anpassung_Temp',-1.5);check(GetValue($m->GetIDForIdent('Anpassung_Temp'))===-1.5,'Heating offset not updated');
    },['input'=>[10122=>300,10123=>650],'discrete'=>[10000=>1,10001=>1]]);
    check(count(array_filter($requests,fn($r)=>$r['function']===16))===4,'Unexpected native write path');
};
$tests['disabled instances invalid settings and overlapping scans never perform SHI I/O'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['InstanceStatus']=false;$m->Update();$m->ApplyChanges();shiThrows(fn()=>$m->SetSHIValue('SHI_LPC_Modus',1));check($m->timers['UpdateTimer']===0,'Disabled poll timer active');
        $m->properties['InstanceStatus']=true;
        $key='WPLUX.SHI.'.$m->InstanceID;$GLOBALS['testSemaphores'][$key]=true;
        $m->Update();shiThrows(fn()=>$m->SetSHIValue('SHI_LPC_Modus',1));unset($GLOBALS['testSemaphores'][$key]);
        $m->properties['ModbusUnitID']=0;$m->Update();check($m->status===202,'Invalid config not rejected');
    });check($requests===[],'Disabled/overlapping request connected');
};
$tests['COP JAZ existing meters and unrestricted footer selection retain their semantics'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $external=new Luxtronik();$in=shiVariable($external,'input',2.0);$kwh=shiVariable($external,'energy',10.0);
        $m->properties['kwin']=$in;$m->properties['kwhin']=$kwh;$m->properties['IDListe']='[{"id":110326,"visualizationFooter":true}]';
        $m->ApplyChanges();check(abs(GetValue($m->GetIDForIdent('copfaktor'))-5.0)<0.001,'COP source changed');
        $footer=invoke($m,'GetVisualizationFooter');check(count($footer)===1 && $footer[0]['variableId']===$m->GetIDForIdent('SHI_Thermische_Energie_Kuehlung'),'SHI footer not selected');
        check($m->properties['kwin']===$in && $m->properties['kwhin']===$kwh,'Meter settings changed');
    },['input'=>[10300=>100,10322=>8000,10324=>4000]]);
};
$tests['uint32 hours do not sign-wrap and optional sentinels remain unavailable'] = static function ():void {
    $m=new Luxtronik();check(invoke($m,'DecodeModbusValue',[0xffff,0xffe7],'int32',0.1)===-2.5,'Signed INT32 wrong');
    check(invoke($m,'DecodeModbusValue',[0xb2d0,0x5e00],'uint32',1.0)===3000000000,'Unsigned INT32 wrapped');
    check(invoke($m,'DecodeModbusValue',[0,32767],'uint32',1.0)===null && invoke($m,'DecodeModbusValue',[32767,65535],'int32',0.1)===null,'Sentinel accepted');
};

$tests['shared controls selected in the interface list use the original Java readback path too'] = static function ():void {
    $values=array_fill(0,1149,0);$values[1]=4294967281;$values[105]=505;$values[1148]=225;
    withServer([[pack('N*',3003,0),frame(3003,$values)]],static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":310002,"enabled":true},{"id":310006,"enabled":true},{"id":310060,"enabled":true}]';
        foreach (['Anpassung_Temp','Anpassung_WW','Anpassung_RBE'] as $ident) {
            check(invoke($m,'SharedNumericControlEnabled',$ident),'Shared selection ignored in Java');
            shiVariable($m,$ident,0.0);
        }
        invoke($m,'RefreshControlValues');
        foreach (['Anpassung_Temp'=>-1.5,'Anpassung_WW'=>50.5,'Anpassung_RBE'=>22.5] as $ident=>$expected) check(GetValue($m->GetIDForIdent($ident))===$expected,'Shared selection changed Java decoding');
    });
};

function hybridFrameValues(): array
{
    $v=array_fill(0,301,0);
    $v[14]=654;$v[15]=999;$v[21]=315;$v[39]=1;$v[44]=1;$v[67]=7380;$v[73]=4484;
    $v[80]=7;$v[231]=55;$v[233]=82;$v[236]=60;$v[254]=617;$v[257]=9999;$v[300]=123;
    return $v;
}

$tests['hybrid reads one Java frame for missing measurements while zero Modbus values keep priority'] = static function ():void {
    withServer([[pack('N*',3004,0),frame(3004,hybridFrameValues())]],static function(Luxtronik $m):void {
        $id=shiVariable($m,'Heisgastemperatur',42.0,'Mein Heißgas','~Temperature');
        $m->properties['IDListe']='[{"id":14,"enabled":true},{"id":300,"visualizationFooter":true}]';
        $requests=withSHI(static function(Luxtronik $m) use($id):void {
            $m->Update();$p=shiPayload($m);
            check($m->status===102,'Successful hybrid scan not active');
            check($p['data']['outdoorTemperature']['value']===0.0 && $p['data']['outdoorTemperature']['source']==='Modbus','Valid Modbus zero replaced');
            check($p['data']['heatingCircuitPumpRunning']['value']===false && $p['data']['heatingCircuitPumpRunning']['source']==='Modbus','Valid Modbus false replaced');
            check($p['data']['heatingPumpPower']['value']===0.0 && $p['data']['heatingPumpPower']['source']==='Modbus','Valid zero power replaced');
            check($p['data']['heatingFlow']['value']===617 && $p['data']['heatingFlow']['source']==='Java','Flow not supplemented');
            check($p['data']['compressorValue']['value']===55 && $p['data']['condenserTemperature']['value']===8.2,'Java values rescaled or wrong Idents');
            check($p['data']['heatPumpRuntime']['value']==='2h 3m' && $p['data']['compressorStandstill']['value']==='1h 14m','Java durations lost');
            check($m->GetIDForIdent('Heisgastemperatur')===$id && GetValue($id)===65.4 && IPS_GetName($id)==='Mein Heißgas','Existing supplementary object replaced');
            check(GetValue($m->GetIDForIdent('ID_300'))==='123','Selected custom Java ID not retained');
            check($m->GetIDForIdent('Flow_Rate')===false && $m->GetIDForIdent('Verdichterfrequenz')===false,'Graphic supplementation creates objects');
            $debug=json_encode($m->debugMessages,JSON_UNESCAPED_UNICODE);
            check(str_contains($debug,'Aussentemperatur: Modbus (input:10108)') && str_contains($debug,'Flow_Rate: Java (3004, ID 254)'),'Actual data sources absent from debug');
            $snapshot=json_decode($m->GetSHIData(),true);$java=array_column($snapshot['javaSupplement'],null,'ident');
            check($java['Flow_Rate']['source']==='Java' && !isset($java['Aussentemperatur']),'Supplement snapshot claims Modbus values');
        },['javaSupplement'=>true],$m);
        check(count($requests)===129 && array_filter($requests,fn($r)=>$r['function']===16)===[],'Hybrid scan wrote data');
    });
};
$tests['unsupported or unavailable Modbus measurements use their exact Java ID without reinterpreting SHI-only fields'] = static function ():void {
    $v=hybridFrameValues();$v[15]=4294967271;
    withServer([[pack('N*',3004,0),frame(3004,$v)]],static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":80,"enabled":true}]';
        withSHI(static function(Luxtronik $m):void {
            $m->Update();$p=shiPayload($m);
            check($p['data']['outdoorTemperature']['value']===-2.5 && $p['data']['outdoorTemperature']['source']==='Java','Unavailable Modbus temperature not supplemented');
            check($p['data']['operatingStatus']['value']===7 && $p['data']['operatingStatus']['source']==='Java','Reserved SHI status confused with native status');
            check($p['data']['mixerActual1']['value']===31.5 && $p['data']['mixerActual1']['source']==='Java','Optional register exception not supplemented');
            check($p['data']['heatingCircuitPumpRunning']['value']===true,'HUP lost');
            check(!isset($p['data']['mixerPump1']),'FUP1 invented from HUP');
            check($m->status===204,'Partial SHI availability hidden');
        },['javaSupplement'=>true,'input'=>[10108=>32767,10002=>6,10140=>['exception'=>2],10351=>32767,10354=>1]],$m);
    });
};
$tests['a failed supplementary Java frame clears current Java data while Modbus values and writes continue'] = static function ():void {
    $request=pack('N*',3004,0);
    withServer([[$request,frame(3004,hybridFrameValues())],[$request,pack('N*',3004,0,301,1)]],static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":14,"enabled":true},{"id":310002,"enabled":true}]';
        withSHI(static function(Luxtronik $m):void {
            $m->Update();$id=$m->GetIDForIdent('Heisgastemperatur');check(GetValue($id)===65.4,'Initial Java value missing');
            $m->Update();$p=shiPayload($m);
            check($m->status===204 && $p['data']['outdoorTemperature']['value']===2.0,'Java failure invalidates healthy Modbus values');
            check(!isset($p['data']['heatingFlow'],$p['data']['compressorValue']),'Old Java snapshot still current');
            check(GetValue($id)===65.4 && $m->GetIDForIdent('Heisgastemperatur')===$id,'Failure overwrites existing supplementary object');
            check(json_decode($m->GetSHIData(),true)['javaSupplement']===[],'Failed Java frame published partially');
            check($m->actions['Anpassung_Temp']===true,'Java read failure disables valid Modbus action');
            $m->RequestAction('Anpassung_Temp',-1.5);
            check(GetValue($m->GetIDForIdent('Anpassung_Temp'))===-1.5,'Healthy Modbus write not confirmed');
        },['javaSupplement'=>true,'input'=>[10108=>20],'discrete'=>[10000=>1]],$m);
    });
};
$tests['a failed Modbus scan discards staged readings and uses Java measurements without reviving SHI controls'] = static function ():void {
    $request=pack('N*',3004,0);$v=hybridFrameValues();$v[15]=4294967271;
    withServer([[$request,frame(3004,hybridFrameValues())],[$request,frame(3004,$v)]],static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":310006,"enabled":true}]';
        withSHI(static function(Luxtronik $m):void {
            $m->Update();$id=$m->GetIDForIdent('Aussentemperatur');
            check(GetValue($id)===2.0 && $m->actions['Anpassung_WW']===true,'Initial Modbus scan failed');
            $m->Update();$p=shiPayload($m);
            check($m->status===201 && $p['data']['outdoorTemperature']['source']==='Java' && GetValue($id)===-2.5,'Failed Modbus scan blocks Java supplementation');
            check(!$m->actions['Anpassung_WW'] && !$p['controls']['warmWaterSetpoint']['configured'],'Stale SHI control revived by Java measurement');
            check(json_decode($m->GetSHIData(),true)['stale'],'Modbus error hidden');
            shiThrows(fn()=>$m->RequestAction('Mode_WW',0));
        },['javaSupplement'=>true,'input'=>[10108=>20],'fault'=>['function'=>4,'address'=>10120,'kind'=>'truncate','after'=>130]],$m);
    });
};
$tests['Java supplementation is optional source-bound and does not introduce Java writes or control reads'] = static function ():void {
    $m=new Luxtronik();check($m->properties['ModbusJavaSupplement']===true,'Supplement not enabled by default');
    $m->properties['CommunicationMode']='modbus';$form=fields(json_decode($m->GetConfigurationForm(),true));
    check($form['Port']['visible'] && $form['ModbusJavaSupplement']['type']==='CheckBox','Saved Java port or supplement switch missing');
    $m->properties['ModbusJavaSupplement']=false;$form=fields(json_decode($m->GetConfigurationForm(),true));check(!$form['Port']['visible'],'Pure Modbus exposes unused port');
    withServer([[pack('N*',3004,0),frame(3004,hybridFrameValues())]],static function(Luxtronik $m):void {
        withSHI(static function(Luxtronik $m):void {
            $m->Update();check(shiPayload($m)['data']['heatingFlow']['value']===617,'Initial supplement failed');
            ++$m->properties['Port'];check(!isset(shiPayload($m)['data']['heatingFlow']),'Changing Java port retains old supplementary cache');
            --$m->properties['Port'];$m->properties['ModbusJavaSupplement']=false;
            check(!isset(shiPayload($m)['data']['heatingFlow']),'Disabled supplementation still supplies Java data');
            $m->Update();check(json_decode($m->GetSHIData(),true)['javaSupplement']===[],'Disabled cache not cleared');
        },['javaSupplement'=>true],$m);
    });
    withoutConnection(static function(Luxtronik $m):void {
        withSHI(static function(Luxtronik $m):void {check($m->SetSHIValue('SHI_LPC_Modus',1),'Explicit Modbus write failed');shiThrows(fn()=>$m->RequestAction('set_223',1));},['javaSupplement'=>true],$m);
    });
};

$failed=0;foreach ($tests as $name=>$test) {try {$test();echo 'PASS '.$name."\n";}catch(Throwable $e){++$failed;fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage()."\n");}}
echo count($tests).' tests, '.$failed." failures\n";if ($failed) exit(1);
