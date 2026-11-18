<?php

require __DIR__.'/regression.php';

function WPLUX_RefreshWriteSourceLabels(int $id, bool $enabled): void
{
    $GLOBALS['testWriteSourceRefreshes'][] = [$id, $enabled];
}

function WPLUX_GetMeasurementRowForm(int $id,int $MeasurementID,bool $ShowSources): string
{
    return $GLOBALS['testRowFormTargets'][$id]->GetMeasurementRowForm($MeasurementID,$ShowSources);
}

function IPS_SetProperty(int $id,string $name,mixed $value): bool
{
    $GLOBALS['testPropertyChanges'][]=[$id,$name,$value];
    if(isset($GLOBALS['testPropertyTargets'][$id])) $GLOBALS['testPropertyTargets'][$id]->properties[$name]=$value;
    return true;
}

function IPS_ApplyChanges(int $id): bool
{
    $GLOBALS['testApplyCalls'][]=$id;
    if(isset($GLOBALS['testPropertyTargets'][$id])) $GLOBALS['testPropertyTargets'][$id]->ApplyChanges();
    return true;
}

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
            $requestCount=0;$pendingWrites=[];
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
                    // Simulate an independent controller change, including watchdog fallback.
                    if (file_exists($folder.'/external-registers.json')) {
                        $updates=json_decode(file_get_contents($folder.'/external-registers.json'),true,512,JSON_THROW_ON_ERROR);
                        foreach($updates as $updateBank=>$values) foreach($values as $address=>$value) $model[$updateBank][$address]=$value;
                        unlink($folder.'/external-registers.json');
                    }
                    foreach($pendingWrites as $pendingAddress=>$pending) if(microtime(true)>=$pending['due']) {
                        $model['holding'][$pendingAddress]=$pending['word'];unset($pendingWrites[$pendingAddress]);
                    }
                    $raw=$model[$bank][$a]??null;
                    if ($raw===null || (is_array($raw) && isset($raw['exception']))) $reply=pack('CC',$f|0x80,is_array($raw)?$raw['exception']:2);
                    elseif ($f===16) {
                        check(strlen($pdu)===6+2*$count && ord($pdu[5])===2*$count,'Bad write frame');
                        // Only fixture-documented holdings may be written.
                        check($count===1 && isset($model['holding'][$a]),'Write outside documented register set');
                        $ready=true;
                        if(!empty($options['enforceDependencies'])) foreach($catalog as $definition) {
                            if($definition['bank']!=='holding' || $definition['address']!==$a) continue;
                            foreach($definition['deps'] as $dependency=>$required)
                                if(($model['holding'][$dependency]??null)!==$required) $ready=false;
                        }
                        if (empty($options['ignoreWrites']) && !in_array($a,$options['ignoreWritesAt']??[],true) && $ready) {
                            $delay=$options['writeDelay'][$a]??0;
                            if($delay>0) $pendingWrites[$a]=['word'=>$words[0],'due'=>microtime(true)+$delay];
                            else $model['holding'][$a]=$words[0];
                        }
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
    try {$test($m,$folder.'/external-registers.json');} finally {touch($folder.'/done');pcntl_waitpid($pid,$status);}
    $lines=file_exists($folder.'/requests')?file($folder.'/requests',FILE_IGNORE_NEW_LINES):[];
    foreach (glob($folder.'/*') as $file) unlink($file);rmdir($folder);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'SHI peer failed');
    return array_map(static fn(string $l):array=>json_decode($l,true,512,JSON_THROW_ON_ERROR),$lines);
}

// The complete API scan is tested separately from the Java-first production update.
function shiRawUpdate(Luxtronik $m):void
{
    if($m->properties['CommunicationMode']==='modbus') invoke($m,'UpdateModbus');else $m->Update();
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
    $m=new Luxtronik();check($m->properties['CommunicationMode']==='java','Java default changed');invoke($m,'RememberFirmwareString','V3.92.3');
    foreach (['java','modbus'] as $mode) {
        $m->properties['CommunicationMode']=$mode;$m->properties['ShowAdvancedModbusPoints']=true;$form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
        check($rows[15]['interface']==='Java + Modbus + WebSocket' && $rows[14]['interface']==='Java + WebSocket' && !isset($rows[310041],$rows[310006]) && count($form['SHIControlList']['values'])===41
            && $rows[400001]['interface']==='WebSocket','Protocol overview missing');
        check($rows[15]['name']==='Aussentemperatur' && array_column($form['SHIControlList']['values'],null,'id')[310006]['name']==='Sollwert Warmwasser','Name changed or MB suffix survives');
    }
};
$tests['full fragmented scan reads all 129 points without writes or measurement objects'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        shiRawUpdate($m);check($m->status===102 && $m->errors===[],'Full scan failed');
        $snapshot=json_decode($m->GetSHIData(),true);check(count($snapshot['values'])===129 && !$snapshot['stale'],'Incomplete cache');
        check(count(array_filter($snapshot['values'],fn($p)=>$p['available']))===129,'Values unavailable');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$m->InstanceID)===[],'Unselected objects created');
    },['fragment'=>true]);
    check(count($requests)===129 && array_unique(array_column($requests,'function'))===[0=>4,76=>2,88=>3],'Wrong polling functions or count');
};
$tests['signed temperatures energy counters unsigned hours and native units are decoded once'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $m->properties['IDListe']=json_encode(array_map(fn($id)=>['id'=>$id,'enabled'=>true],[15,268,257,63,161,136,137,110310,110326]));
        shiRawUpdate($m);
        foreach (['Aussentemperatur'=>-2.5,'Leistungsaufnahme'=>1800.0,'Waermeleistung'=>5.7,'Betriebsstunden_Waermepumpe'=>3000000000,'Betriebstundenzaehler_Solar'=>'12h 0m','Mischkreis_3_Vorlauftemperatur'=>31.2,'Mischkreis_3_Vorlauf_Soll_Temperatur'=>33.0,'SHI_Elektrische_Energie_Gesamt'=>800.0,'SHI_Thermische_Energie_Kuehlung'=>-2.5] as $ident=>$expected) check(GetValue($m->GetIDForIdent($ident))===$expected,$ident.' unit/type wrong');
    },['input'=>[10108=>65511,10301=>18,10300=>57,10404=>3000000000,10416=>12,10160=>312,10161=>330,10310=>8000,10326=>0xffffffe7]]);
};
$tests['Java to SHI to Java preserves existing object IDs names profiles types and settings'] = static function ():void {
    $m=new Luxtronik();$a=shiVariable($m,'Aussentemperatur',7.5,'Mein Außenfühler','~Temperature');$g=shiVariable($m,'Heisgastemperatur',45.0,'Heißgas');
    $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":14,"enabled":true}]';$javaPort=$m->properties['Port'];
    withSHI(static function(Luxtronik $m) use($a,$g,$javaPort):void {
        shiRawUpdate($m);check($m->GetIDForIdent('Aussentemperatur')===$a && GetValue($a)===-2.5,'Shared object replaced');
        check($m->GetIDForIdent('Heisgastemperatur')===$g && GetValue($g)===45.0,'Java-only value removed');
        check(IPS_GetName($a)==='Mein Außenfühler' && IPS_GetVariable($a)['VariableProfile']==='~Temperature','Object metadata changed');
        check($m->properties['Port']===$javaPort,'Java connection changed');
    },['input'=>[10108=>65511]],$m);
    $m->properties['CommunicationMode']='java';$m->properties['IPAddress']='0.0.0.0';$m->ApplyChanges();
    check($m->GetIDForIdent('Aussentemperatur')===$a && IPS_GetName($a)==='Mein Außenfühler','Switch-back replaces measurement');
};
$tests['SHI states and three mixed circuits feed the graphic independently of IDListe'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $m->properties['VisualizationHeatingCircuit3']=true;shiRawUpdate($m);$p=shiPayload($m);
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
        $id=shiVariable($m,'Aussentemperatur',9.0);$m->properties['IDListe']='[{"id":15,"enabled":true}]';shiRawUpdate($m);
        check(GetValue($id)===9.0 && $m->status===204,'Unavailable value overwrote existing object');
        check(!isset(shiPayload($m)['data']['outdoorTemperature']),'Old Java object passed off as current SHI');
    },['input'=>[10108=>32767,10160=>['exception'=>2]],'holding'=>[10071=>['exception'=>1]]]);
};
$tests['invalid and incomplete transport replies cannot publish a partial scan'] = static function ():void {
    foreach (['identity','truncate','silent'] as $kind) {
        withSHI(static function(Luxtronik $m):void {
            $id=shiVariable($m,'Aussentemperatur',8.0);$m->properties['IDListe']='[{"id":15,"enabled":true}]';$start=microtime(true);shiRawUpdate($m);
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
        $options=['input'=>$input,'discrete'=>$di,'holding'=>[10040=>1],'enforceDependencies'=>true];
        $requests=withSHI(static function(Luxtronik $m) use($point,$value):void {
            check($m->SetSHIValue($point['ident'],$value),'Write not confirmed');
            $snapshot=json_decode($m->GetSHIData(),true);
            $read=array_values(array_filter($snapshot['values'],fn($p)=>$p['bank']==='holding' && $p['address']===$point['address']))[0];
            check(abs((float)$read['value']-(float)$value)<0.000001,'Readback value not retained');
        },$options);
        $writes=array_values(array_filter($requests,fn($r)=>$r['function']===16));
        $expected=((int)round((float)$value/$point['factor'])) & 0xffff;
        $target=array_values(array_filter($writes,fn($write)=>$write['address']===$point['address']));
        check(count($target)===1 && $target[0]['words']===[$expected],'Wrong write encoding or repeated target');
        check(end($writes)['address']===$point['address'],'Target sent before a required mode');
        foreach ($writes as $write) check($write['address']===$point['address'] || array_key_exists($write['address'],$point['deps']),'Undocumented collateral write');
    }
};
$tests['all selected SHI controls read actual holding values and keep effective setpoints separate'] = static function():void {
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/shi-registers.json'),true);
    $holdings=[];$wanted=[];$selections=[];
    foreach($fixture as $point) {
        if($point['bank']!=='holding') continue;
        $value=$point['options']!==[]?(int)array_key_last($point['options']):($point['min']<0?-1.5:$point['min']+$point['factor']);
        if($point['datatype']==='bool') $value=true;
        if($point['address']===10006) $value=50.0;
        $holdings[$point['address']]=((int)round((float)$value/$point['factor'])) & 0xffff;
        $wanted[$point['ident']]=$value;
        $selections[]=['id'=>$point['selection'],'enabled'=>true];
    }
    $requests=withSHI(static function(Luxtronik $m,string $externalRegisters) use($wanted,$selections):void {
        $m->properties['CommunicationMode']='java';
        $m->properties['SHIControlList']=json_encode($selections);
        $m->properties['IDListe']='[{"id":12,"enabled":true,"preferredSource":"modbus"},{"id":18,"enabled":true,"preferredSource":"modbus"}]';
        invoke($m,'UpdateModbusSupplement');
        foreach($wanted as $ident=>$value) {
            $id=$m->GetIDForIdent($ident);
            check($id!==false && abs((float)GetValue($id)-(float)$value)<0.000001,'Holding value not read: '.$ident);
        }
        check(GetValue($m->GetIDForIdent('Ruecklauf_Soll_Heizkreis'))===28.0,'Effective return target replaced with the SHI demand');
        check(GetValue($m->GetIDForIdent('Warmwasser_Soll_Temperatur'))===55.0,'Effective DHW target replaced with the SHI demand');
        $id=$m->GetIDForIdent('SHI_Sollwert_Warmwasser');
        file_put_contents($externalRegisters,json_encode(['holding'=>[10006=>450]]));
        invoke($m,'UpdateModbusSupplement');
        check($m->GetIDForIdent('SHI_Sollwert_Warmwasser')===$id && GetValue($id)===45.0,'Changed holding value does not refresh the existing control');
    },['holding'=>$holdings,'input'=>[10101=>280,10121=>550],'discrete'=>array_fill_keys(range(10000,10011),1)]);
    check(array_filter($requests,fn($r)=>$r['function']===16)===[],'Reading control values writes back defaults');
};
$tests['DHW mode precedes the setpoint and delayed commits are confirmed without repeated writes'] = static function():void {
    $module=new class extends Luxtronik {
        public mixed $onValue=null;
        protected function SetValue(string $ident,mixed $value): bool {
            $result=parent::SetValue($ident,$value);
            if($this->onValue!==null) ($this->onValue)($this,$ident,$value);
            return $result;
        }
    };
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['SHIControlList']='[{"id":310005,"enabled":true},{"id":310006,"enabled":true}]';
        invoke($m,'UpdateModbusSupplement');
        $target=$m->GetIDForIdent('SHI_Sollwert_Warmwasser');
        check(GetValue($target)===40.0,'Initial SHI default not read');
        $shownAt=null;$started=microtime(true);
        $m->onValue=static function(Luxtronik $instance,string $ident,mixed $value) use (&$shownAt,$target):void {
            if($ident!=='SHI_Sollwert_Warmwasser' || $value!==50.0) return;
            $shownAt=microtime(true);
            $data=json_decode($instance->GetSHIData(),true);
            $row=array_values(array_filter($data['values'],fn($p)=>$p['bank']==='holding' && $p['address']===10006))[0];
            check($row['pending'] && $row['requestedValue']===50.0 && $row['value']===40.0,'Desired value masquerades as a confirmed reading');
            invoke($instance,'SyncSHIControlVariables');
            check(GetValue($target)===50.0,'Old feedback overwrites the pending display');
        };
        $m->RequestAction('SHI_Sollwert_Warmwasser',50.0);
        $m->onValue=null;
        check($shownAt!==null && $shownAt-$started<0.5,'SHI variable waits for delayed readback before displaying the desired value');
        check(invoke($m,'PendingSHIWrite')===null,'Confirmed write stays pending');
        check(GetValue($target)===50.0 && GetValue($m->GetIDForIdent('SHI_Modus_Warmwasser'))===1,'Delayed target or mode not published');
        invoke($m,'UpdateModbusSupplement');
        check(GetValue($target)===50.0,'Confirmed target lost on next poll');
    },['holding'=>[10005=>0,10006=>400],'input'=>[10122=>300,10123=>750],
        'discrete'=>[10001=>1],'enforceDependencies'=>true,'writeDelay'=>[10005=>2.0,10006=>2.0]],$module);
    $writes=array_values(array_filter($requests,fn($r)=>$r['function']===16));
    check(array_column($writes,'address')===[10005,10006] && array_column($writes,'words')===[[1],[500]],'DHW write repeated or sent before its mode');
};
$tests['DHW mode rejection stops the target write and mismatched target reports both temperatures'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        shiThrows(fn()=>$m->SetSHIValue('SHI_Sollwert_Warmwasser',45.0));
    },['holding'=>[10005=>0,10006=>400],'input'=>[10122=>300,10123=>750],'discrete'=>[10001=>1],
        'fault'=>['function'=>16,'address'=>10005,'kind'=>'ack']]);
    check(array_diff(array_column(array_values(array_filter($requests,fn($r)=>$r['function']===16)),'address'),[10005])===[],'Rejected DHW mode still sends a target');
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['SHIControlList']='[{"id":310006,"enabled":true}]';
        invoke($m,'UpdateModbusSupplement');
        $id=$m->GetIDForIdent('SHI_Sollwert_Warmwasser');
        check(GetValue($id)===40.0,'Failure test did not read its starting value');
        try {$m->SetSHIValue('SHI_Sollwert_Warmwasser',45.0);throw new LogicException('Ignored target reported success');}
        catch(RuntimeException $error) {
            check(str_contains($error->getMessage(),'10006') && str_contains($error->getMessage(),'45.0 °C')
                && str_contains($error->getMessage(),'40.0 °C'),'Readback error omits sent or actual temperature');
        }
        check($m->status===201 && json_decode($m->GetSHIData(),true)['stale'],'Failed target is published as confirmed');
        check(GetValue($id)===40.0 && invoke($m,'PendingSHIWrite')===null,'Failed target leaves the desired value displayed');
    },['holding'=>[10005=>1,10006=>400],'input'=>[10122=>300,10123=>750],'discrete'=>[10001=>1],'ignoreWrites'=>true]);
    check(count(array_filter($requests,fn($r)=>$r['function']===16))===1,'Failed DHW target was resent');
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
$tests['failed acknowledgements and mismatched readback restore changed modes without reporting success'] = static function ():void {
    foreach ([['fault'=>['function'=>16,'address'=>10002,'kind'=>'ack']],['ignoreWrites'=>true]] as $option) {
        $requests=withSHI(static function(Luxtronik $m):void {shiThrows(fn()=>$m->SetSHIValue('Anpassung_Temp',-2.5));check($m->status===201,'Write failure not reported');},$option+['discrete'=>[10000=>1]]);
        $modeWrites=array_values(array_filter($requests,fn($r)=>$r['function']===16 && $r['address']===10000));
        check($modeWrites!==[] && (isset($option['ignoreWrites']) || end($modeWrites)['words']===[0]),'Failed target leaves the newly activated mode enabled');
    }
};
$tests['a rejected target restores only changed prerequisite modes in reverse order'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        shiThrows(fn()=>$m->SetSHIValue('SHI_Offset_Heizen',1.0));
        $client=invoke($m,'NewSHIClient');
        try {
            check($client->readHoldingRegisters(10000,1)===[1],'Failed target leaves a changed heating mode active');
            check($client->readHoldingRegisters(10065,1)===[3],'Failed target loses the prior overall mode');
        } finally {$client->close();}
        check($m->status===201 && json_decode($m->GetSHIData(),true)['stale'],'Unconfirmed target reported as successful');
    },['discrete'=>[10000=>1],'holding'=>[10000=>1,10065=>3,10002=>0],
        'enforceDependencies'=>true,'ignoreWritesAt'=>[10002]]);
    $writes=array_values(array_filter($requests,fn($r)=>$r['function']===16));
    check(array_column($writes,'address')===[10065,10000,10002,10000,10065],'Prerequisites restored in the wrong order or target resent');
    check(array_column($writes,'words')===[[0],[2],[10],[1],[3]],'Rollback did not preserve the old mode values');
};
$tests['selected holding controls preserve existing IDs names types and never write on ApplyChanges'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $id=shiVariable($m,'SHI_Offset_Heizen',0.0,'Eigener Name');
        $m->properties['IDListe']='[{"id":310002,"enabled":true}]';$m->ApplyChanges();
        check($m->GetIDForIdent('SHI_Offset_Heizen')===$id && IPS_GetName($id)==='Eigener Name','Control renamed/replaced');
        check($m->actions['SHI_Offset_Heizen']===true,'Selected holding not actionable');
        $m->properties['CommunicationMode']='java';$m->properties['SHIControlList']='[{"id":310002,"enabled":false}]';invoke($m,'DisableSHIActions');
        check(!$m->actions['SHI_Offset_Heizen'],'SHI action survives switch to Java');
        shiThrows(fn()=>$m->SetSHIValue('SHI_Offset_Heizen',1.0));
    },['discrete'=>[10000=>1]]);check(array_filter($requests,fn($r)=>$r['function']===16)===[],'Configuration wrote default values');
};
$tests['Java targets and temporary SHI requests remain separate variables and write protocols'] = static function():void {
    $values=array_fill(0,106,0);$values[105]=505;$values[18]=505;
    withServer([[pack('N*',3002,105,505),pack('N*',3002,105)],[pack('N*',3004,0),frame(3004,$values)]],static function(Luxtronik $m):void {
        $requests=withSHI(static function(Luxtronik $m):void {
            $m->properties['WWsetVisible']=true;
            $m->properties['SHIControlList']='[{"id":310006,"enabled":true},{"id":310002,"enabled":true}]';
            $native=shiVariable($m,'Anpassung_WW',45.0,'Mein dauerhaftes WW-Soll');
            $m->EnableAction('Anpassung_WW');
            invoke($m,'SyncSHIControlVariables');
            $external=$m->GetIDForIdent('SHI_Sollwert_Warmwasser');
            check($external!==false && $external!==$native,'SHI target reuses the native Java object');
            invoke($m,'UpdateModbusSupplement');$prior=GetValue($external);
            $m->RequestAction('Anpassung_WW',50.5);
            check(GetValue($native)===50.5 && GetValue($external)===$prior,'Java write changed the external SHI target');
            $m->RequestAction('SHI_Sollwert_Warmwasser',55.0);
            check(GetValue($native)===50.5 && GetValue($external)===55.0,'SHI write changed the native Java target');
            check($m->GetIDForIdent('Anpassung_WW')===$native && IPS_GetName($native)==='Mein dauerhaftes WW-Soll','Native Java variable identity changed');
        },['input'=>[10122=>300,10123=>650],'discrete'=>[10000=>1,10001=>1]],$m);
        check(array_column(array_values(array_filter($requests,fn($r)=>$r['function']===16)),'address')===[10005,10006],'Native Java action was translated into SHI or DHW mode was set too late');
    });
};

$tests['disabled instances invalid settings and overlapping scans never perform SHI I/O'] = static function ():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['InstanceStatus']=false;shiRawUpdate($m);$m->ApplyChanges();shiThrows(fn()=>$m->SetSHIValue('SHI_LPC_Modus',1));check($m->timers['UpdateTimer']===0,'Disabled poll timer active');
        $m->properties['InstanceStatus']=true;
        $key='WPLUX.SHI.'.$m->InstanceID;$GLOBALS['testSemaphores'][$key]=true;
        shiRawUpdate($m);shiThrows(fn()=>$m->SetSHIValue('SHI_LPC_Modus',1));unset($GLOBALS['testSemaphores'][$key]);
        $m->properties['ModbusUnitID']=0;shiRawUpdate($m);check($m->status===202,'Invalid config not rejected');
    });check($requests===[],'Disabled/overlapping request connected');
};
$tests['COP JAZ meters retain their semantics while retired SHI footer selections are ignored'] = static function ():void {
    withSHI(static function(Luxtronik $m):void {
        $external=new Luxtronik();$in=shiVariable($external,'input',2.0);$kwh=shiVariable($external,'energy',10.0);
        $m->properties['kwin']=$in;$m->properties['kwhin']=$kwh;$m->properties['IDListe']='[{"id":110326,"visualizationFooter":true}]';
        $m->ApplyChanges();check(abs(GetValue($m->GetIDForIdent('copfaktor'))-5.0)<0.001,'COP source changed');
        check($m->properties['kwin']===$in && $m->properties['kwhin']===$kwh,'Meter settings changed');
        check($m->GetIDForIdent('SHI_Thermische_Energie_Kuehlung')===false,'Retired SHI footer flag still creates objects');
    },['input'=>[10300=>100,10322=>8000,10324=>4000]]);
};
$tests['uint32 hours do not sign-wrap and optional sentinels remain unavailable'] = static function ():void {
    $m=new Luxtronik();check(invoke($m,'DecodeModbusValue',[0xffff,0xffe7],'int32',0.1)===-2.5,'Signed INT32 wrong');
    check(invoke($m,'DecodeModbusValue',[0xb2d0,0x5e00],'uint32',1.0)===3000000000,'Unsigned INT32 wrapped');
    check(invoke($m,'DecodeModbusValue',[0,32767],'uint32',1.0)===null && invoke($m,'DecodeModbusValue',[32767,65535],'int32',0.1)===null,'Sentinel accepted');
};

$tests['selecting SHI controls does not activate or overwrite persistent Java controls'] = static function():void {
    $values=array_fill(0,1149,0);$values[1]=4294967281;$values[105]=505;$values[18]=505;$values[1148]=225;
    withServer([[pack('N*',3003,0),frame(3003,$values)]],static function(Luxtronik $m):void {
        $m->properties['SHIControlList']='[{"id":310002,"enabled":true},{"id":310006,"enabled":true},{"id":310060,"enabled":true}]';
        foreach(['Anpassung_Temp'=>'TempsetVisible','Anpassung_WW'=>'WWsetVisible','Anpassung_RBE'=>'RBEsetVisible'] as $ident=>$property) {
            check(!invoke($m,'SharedNumericControlEnabled',$ident),'SHI selection enabled a persistent Java control');
            $m->properties[$property]=true;shiVariable($m,$ident,0.0);
        }
        invoke($m,'SyncSHIControlVariables');
        invoke($m,'StoreVisualizationPoints',[18=>50.5]);
        invoke($m,'RefreshControlValues');
        foreach(['Anpassung_Temp'=>-1.5,'Anpassung_WW'=>50.5,'Anpassung_RBE'=>22.5] as $ident=>$expected) check(GetValue($m->GetIDForIdent($ident))===$expected,'Persistent Java readback changed');
        check(GetValue($m->GetIDForIdent('SHI_Offset_Heizen'))===0.0 && GetValue($m->GetIDForIdent('SHI_Sollwert_Warmwasser'))===0.0,'Java readback overwrote SHI requests');
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
        $m->properties['IDListe']='[{"id":14,"enabled":true},{"id":300,"enabled":true}]';
        $requests=withSHI(static function(Luxtronik $m) use($id):void {
            shiRawUpdate($m);$p=shiPayload($m);
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
        $m->properties['VisualizationHeatingCircuit2']=true;
        $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":80,"enabled":true}]';
        withSHI(static function(Luxtronik $m):void {
            shiRawUpdate($m);$p=shiPayload($m);
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
            shiRawUpdate($m);$id=$m->GetIDForIdent('Heisgastemperatur');check(GetValue($id)===65.4,'Initial Java value missing');
            shiRawUpdate($m);$p=shiPayload($m);
            check($m->status===204 && $p['data']['outdoorTemperature']['value']===2.0,'Java failure invalidates healthy Modbus values');
            check(!isset($p['data']['heatingFlow'],$p['data']['compressorValue']),'Old Java snapshot still current');
            check(GetValue($id)===65.4 && $m->GetIDForIdent('Heisgastemperatur')===$id,'Failure overwrites existing supplementary object');
            check(json_decode($m->GetSHIData(),true)['javaSupplement']===[],'Failed Java frame published partially');
            check($m->actions['SHI_Offset_Heizen']===true,'Java read failure disables valid Modbus action');
            $m->RequestAction('SHI_Offset_Heizen',-1.5);
            check(GetValue($m->GetIDForIdent('SHI_Offset_Heizen'))===-1.5,'Healthy Modbus write not confirmed');
        },['javaSupplement'=>true,'input'=>[10108=>20],'discrete'=>[10000=>1]],$m);
    });
};
$tests['a failed Modbus scan discards staged readings and uses Java measurements without reviving SHI controls'] = static function ():void {
    $request=pack('N*',3004,0);$v=hybridFrameValues();$v[15]=4294967271;
    withServer([[$request,frame(3004,hybridFrameValues())],[$request,frame(3004,$v)]],static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":310006,"enabled":true}]';
        withSHI(static function(Luxtronik $m):void {
            shiRawUpdate($m);$id=$m->GetIDForIdent('Aussentemperatur');
            check(GetValue($id)===2.0 && $m->actions['SHI_Sollwert_Warmwasser']===true,'Initial Modbus scan failed');
            shiRawUpdate($m);$p=shiPayload($m);
            check($m->status===201 && $p['data']['outdoorTemperature']['source']==='Java' && GetValue($id)===-2.5,'Failed Modbus scan blocks Java supplementation');
            check(!$m->actions['SHI_Sollwert_Warmwasser'] && !$p['controls']['warmWaterSetpoint']['configured'],'Stale SHI control revived by Java measurement');
            check(json_decode($m->GetSHIData(),true)['stale'],'Modbus error hidden');
            // Java-only modes remain routed through Java; malformed modes are still rejected.
            shiThrows(fn()=>$m->RequestAction('Mode_WW',99));
        },['javaSupplement'=>true,'input'=>[10108=>20],'discrete'=>[10001=>1],'fault'=>['function'=>4,'address'=>10120,'kind'=>'truncate','after'=>130]],$m);
    });
};
$tests['Java supplementation is optional source-bound and does not introduce Java writes or control reads'] = static function ():void {
    $m=new Luxtronik();check($m->properties['ModbusJavaSupplement']===true,'Supplement not enabled by default');
    $m->properties['CommunicationMode']='modbus';$form=fields(json_decode($m->GetConfigurationForm(),true));
    check(!isset($form['ModbusJavaSupplement']) && !isset($form['ModbusReadSupplement']) && !isset($form['CommunicationMode']['value']),'Legacy read switches retained');
    $m->properties['ModbusJavaSupplement']=false;$form=fields(json_decode($m->GetConfigurationForm(),true));check(isset($form['Port']),'Java read port was hidden by write configuration');
    withServer([[pack('N*',3004,0),frame(3004,hybridFrameValues())]],static function(Luxtronik $m):void {
        withSHI(static function(Luxtronik $m):void {
            shiRawUpdate($m);check(shiPayload($m)['data']['heatingFlow']['value']===617,'Initial supplement failed');
            ++$m->properties['Port'];check(!isset(shiPayload($m)['data']['heatingFlow']),'Changing Java port retains old supplementary cache');
            --$m->properties['Port'];$m->properties['ModbusJavaSupplement']=false;
            check(shiPayload($m)['data']['heatingFlow']['source']==='Java','Retired read switch changed the preferred source');
            shiRawUpdate($m);check(json_decode($m->GetSHIData(),true)['javaSupplement']===[],'Disabled cache not cleared');
        },['javaSupplement'=>true],$m);
    });
    withoutConnection(static function(Luxtronik $m):void {
        withSHI(static function(Luxtronik $m):void {check($m->SetSHIValue('SHI_LPC_Modus',1),'Explicit Modbus write failed');shiThrows(fn()=>$m->RequestAction('set_223',90000));},['javaSupplement'=>true],$m);
    });
};

$tests['optional Modbus reads in Java mode fetch only missing selected fields and never enable SHI writes'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['CommunicationMode']='java';$m->properties['ModbusReadSupplement']=true;$m->properties['VisualizationHeatingCircuit1']=false;
        $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":110310,"enabled":true}]';
        $outdoor=shiVariable($m,'Aussentemperatur',0.0,'Mein Außenfühler','~Temperature');
        invoke($m,'StoreVisualizationPoints',[15=>0.0]);
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>276]));
        invoke($m,'UpdateModbusSupplement');
        check($m->GetIDForIdent('Aussentemperatur')===$outdoor && GetValue($outdoor)===0.0 && IPS_GetName($outdoor)==='Mein Außenfühler','Supplement replaced Java or object identity');
        check(GetValue($m->GetIDForIdent('SHI_Elektrische_Energie_Gesamt'))===800.0,'Missing energy was not read');
        $limit=$m->GetIDForIdent('SHI_PC_Limit');
        check($limit===false && !invoke($m,'SHIWritingEnabled'),'Read supplement enabled Modbus writes');
        check(count(array_filter(json_decode($m->GetSHIData(),true)['values'],static fn($point)=>$point['available']))===1,'Unneeded registers fetched');
        $before=$m->status;invoke($m,'UpdateModbusSupplement');check($m->status===$before,'Supplement changed primary status');
    },['input'=>[10310=>8000,10108=>650],'holding'=>[10041=>100]]);
    check(count($requests)===2 && array_column($requests,'address')===[10310,10310],'Independent polls reused the previous cycle');
    check(array_unique(array_column($requests,'function'))===[0=>4],'Supplement sent a write function');
};

$tests['Modbus fallback in Java mode yields to recovered Java without changing Idents'] = static function():void {
    $m=new Luxtronik();$m->properties['ModbusReadSupplement']=true;
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['CommunicationMode']='java';$m->properties['IDListe']='[{"id":15,"enabled":true}]';
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>15]));
        invoke($m,'UpdateModbusSupplement');
        $point=invoke($m,'GetVisualizationPointData','OutdoorTemperature');
        check($point['value']===-2.5 && $point['source']==='Modbus','Missing Java reading not supplemented');
        $id=$m->GetIDForIdent('Aussentemperatur');
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>276]));
        invoke($m,'StoreVisualizationPoints',[15=>0.0]);invoke($m,'SetValueIfChanged','Aussentemperatur',0.0);
        invoke($m,'UpdateModbusSupplement');
        check(invoke($m,'GetVisualizationPointData','OutdoorTemperature')['source']==='Java' && GetValue($id)===0.0,'Java recovery did not win');
        check($m->GetIDForIdent('Aussentemperatur')===$id,'Recovery changed the object ID');
        $m->properties['ModbusReadSupplement']=false;
        check(invoke($m,'GetVisualizationPointData','OutdoorTemperature')['source']==='Java','Retired switch changed the preferred source');
    },['input'=>[10108=>65511]],$m);
    check(!array_filter($requests,static fn($r)=>!in_array($r['function'],[3,4,2],true)),'Read fallback sent writes');
};

$tests['a failed optional Modbus scan clears partial values backs off and preserves Java status and object identity'] = static function():void {
    withSHI(static function(Luxtronik $m):void {
        $m->properties['CommunicationMode']='java';$m->properties['ModbusReadSupplement']=true;
        $m->properties['IDListe']='[{"id":15,"enabled":true}]';
        $id=shiVariable($m,'Aussentemperatur',9.0,'Mein Außenfühler','~Temperature');
        invoke($m,'SetStatus',102);
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>0,'failed'=>true]));
        invoke($m,'UpdateModbusSupplement');
        $cache=json_decode($m->buffers['SHICache'],true);
        check($cache['stale'] && $cache['points']===[] && $cache['nextAttempt']>time()+100,'Failed supplement exposed a partial scan or immediately retried');
        check($m->status===102 && $m->GetIDForIdent('Aussentemperatur')===$id && GetValue($id)===9.0,'Failure changed primary status object identity or stored value');
        check(invoke($m,'GetSHIVisualizationPoint','Aussentemperatur')===null,'Failure revived a stale reading');
        invoke($m,'UpdateModbusSupplement');
    },['fault'=>['function'=>4,'address'=>10120,'kind'=>'identity']]);
};

$tests['normal update reads Java first while the configured write route sends only explicit Modbus commands'] = static function():void {
    $values=array_fill(0,276,0);$values[15]=123;
    withServer(controllerCycles([[pack('N*',3004,0),frame(3004,$values)]]),static function(Luxtronik $m):void {
        $requests=withSHI(static function(Luxtronik $m):void {
            $m->properties['IDListe']='[{"id":15,"enabled":true},{"id":310041,"enabled":true}]';
            $m->Update();
            $point=invoke($m,'GetVisualizationPointData','OutdoorTemperature');
            check($point['source']==='Java' && $point['value']===12.3,'Write route changed Java-first measurement priority');
            $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
            check($rows[15]['source']==='Java' && !isset($rows[310041]) && array_column(fields(json_decode($m->GetConfigurationForm(),true))['SHIControlList']['values'],null,'id')[310041]['value']==='10.0 kW','Source column shows capabilities instead of actual origin');
            $m->RequestAction('SHI_PC_Limit',5.0);
            check(GetValue($m->GetIDForIdent('SHI_PC_Limit'))===5.0,'Configured Modbus write did not read back');
        },['input'=>[10108=>65511],'holding'=>[10040=>1,10041=>100]],$m);
        check(count(array_filter($requests,static fn($r)=>$r['address']===10108))===0,'Modbus polled Java-covered outdoor readings');
        $writes=array_values(array_filter($requests,static fn($r)=>$r['function']===16));
        check(count($writes)===1 && $writes[0]['address']===10041 && $writes[0]['words']===[50],'Poll wrote values or explicit write used the wrong route');
    });
};

$tests['default list contains only additional Modbus measurements and retains selected advanced and legacy rows'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');$form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
    check($form['CommunicationMode']['type']==='Select' && !isset($form['CommunicationMode']['value']),'Write selection is not bound to the existing Java-default property');
    check(!isset($form['ModbusWrites']) && !isset($form['ModbusReadSupplement']) && !isset($form['ModbusJavaSupplement']) && !isset($form['WebSocketSupplement']),'Obsolete reading switches remain');
    check(!isset($rows[310041],$rows[110350],$rows[400003]) && isset($rows[210000],$rows[110103]),'Read-only registers are not in the unified list or aliases leak into it');
    check($rows[43]['interface']==='Java + Modbus + WebSocket' && isset($rows[110310],$rows[400020]),'Proven VBO mapping or actual extra measurements missing');
    check(count(array_filter($rows,static fn($row)=>$row['id']>=100000 && $row['id']<400000))>10,'Extra read-only registers remain hidden');
    $m->properties['IDListe']='[{"id":310041,"enabled":true},{"id":400003,"enabled":true},{"id":110350,"enabled":true}]';
    $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
    check(!isset($rows[310041]) && array_column(fields(json_decode($m->GetConfigurationForm(),true))['SHIControlList']['values'],null,'id')[310041]['enabled'] && $rows[400003]['enabled'] && $rows[110350]['enabled'],'Saved advanced selection or Ident was retired');
    $m->properties['ShowAdvancedModbusPoints']=true;
    $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
    check(isset($rows[210000],$rows[110103]),'Documented advanced API points became inaccessible');
};

$tests['write selection edits the registered property as a draft and measurement bindings remain independent'] = static function():void {
    $m=new Luxtronik();$form=fields(json_decode($m->GetConfigurationForm(),true));
    $id=$m->InstanceID;$changes=count($GLOBALS['testPropertyChanges']??[]);
    $CommunicationMode='modbus';eval($form['CommunicationMode']['onChange']);
    $CommunicationMode='java';eval($form['CommunicationMode']['onChange']);
    check(count($GLOBALS['testPropertyChanges']??[])===$changes && $m->properties['CommunicationMode']==='java','Changing the draft already changes the active write route');
    check(array_column($form['CommunicationMode']['options'],'value')===['java','modbus'],'Existing write mode values were changed');
    check(array_slice($GLOBALS['testWriteSourceRefreshes'],-2)===[[$id,true],[$id,false]],'Write switch did not refresh its source labels in both directions');
    $java=invoke($m,'GetVisualizationIdents','MixerPump1');
    $m->properties['CommunicationMode']='modbus';
    check(invoke($m,'GetVisualizationIdents','MixerPump1')===$java && $java===['SHI_Pumpe_Mischkreis_1'],'Write transport changes the physical pump measurement');
    check(!isset(fields(json_decode($m->GetConfigurationForm(),true))['CommunicationMode']['value']),'Existing Modbus configuration not reflected in selection');
};

$tests['timer control source labels match actual write routing and refresh without sending commands'] = static function():void {
    $m=new Luxtronik();
    $controls=['HeizungVisible'=>'Mode_Heizung','WarmwasserVisible'=>'Mode_WW','SchwimmbadVisible'=>'Mode_Schwimmbad',
        'KuehlungVisible'=>'Mode_Kuehlung','TempsetVisible'=>'Anpassung_Temp','WWsetVisible'=>'Anpassung_WW','RBEsetVisible'=>'Anpassung_RBE'];
    foreach (['java','modbus'] as $mode) {
        $m->properties['CommunicationMode']=$mode;
        $form=fields(json_decode($m->GetConfigurationForm(),true));
        foreach ($controls as $property=>$ident) {
            $source=invoke($m,'UsesModbusWrite',$ident)?'Modbus/SHI':'Java';
            check(str_ends_with($form[$property]['caption'],' ('.$source.')'),'Source caption disagrees with RequestAction routing: '.$ident);
        }
    }
    $before=[$m->writes,$m->properties,$m->buffers,$m->actions];
    foreach ([true,false] as $enabled) {
        $m->formUpdates=[];
        $m->RefreshWriteSourceLabels($enabled);
        check(count($m->formUpdates)===7,'Not all seven controls refreshed');
        foreach ($m->formUpdates as [$property,$parameter,$caption]) {
            $modbus=false;
            check($parameter==='caption' && str_ends_with($caption,$modbus?' (Modbus/SHI)':' (Java)'),'Incorrect immediate write-source label');
        }
    }
    check([$m->writes,$m->properties,$m->buffers,$m->actions]===$before,'Refreshing captions changed configuration, variables or sent commands');
};

$tests['preferred Modbus measurement overrides Java zeroes remain valid and writing stays disabled'] = static function():void {
    $values=array_fill(0,276,0);$values[15]=123;
    withServer(controllerCycles([[pack('N*',3004,0),frame(3004,$values)]]),static function(Luxtronik $m):void {
        $requests=withSHI(static function(Luxtronik $m):void {
            $m->properties['CommunicationMode']='java';$m->properties['VisualizationHeatingCircuit1']=false;
            $m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"modbus"}]';
            $id=shiVariable($m,'Aussentemperatur',99.0,'Mein Außenfühler','~Temperature');
            $m->Update();$point=invoke($m,'GetVisualizationPointData','OutdoorTemperature');
            check($point['source']==='Modbus' && $point['value']===0.0 && GetValue($id)===0.0,'Selected Modbus source lost to Java or rejected zero');
            check(invoke($m,'CurrentPointSource',15)==='Modbus/SHI','Actual source does not match the chosen measurement');
            $cache=json_decode($m->buffers['SHICache'],true);$cache['stale']=true;invoke($m,'SetBuffer','SHICache',json_encode($cache));
            invoke($m,'PublishMeasurements');check(GetValue($id)===12.3 && invoke($m,'CurrentPointSource',15)==='Java','Failed preferred Modbus source did not fall back');
            $cache['stale']=false;invoke($m,'SetBuffer','SHICache',json_encode($cache));invoke($m,'PublishMeasurements');
            check(GetValue($id)===0.0 && $m->GetIDForIdent('Aussentemperatur')===$id && IPS_GetName($id)==='Mein Außenfühler','Recovered source changed the existing variable');
            check(!invoke($m,'UsesModbusWrite','Anpassung_WW'),'Read source enabled Modbus writing');
        },['input'=>[10108=>0]],$m);
        check(count($requests)===1 && $requests[0]['function']===4 && $requests[0]['address']===10108,'Preference fetched unneeded registers or wrote a value');
    });
};

$tests['Modbus writes coexist with Java-only mode and timer actions'] = static function():void {
    $values=array_fill(0,224,0);$values[4]=2;$values[223]=7200;
    withServer([[pack('N*',3002,4,2),pack('N*',3002,4)],[pack('N*',3003,0),frame(3003,$values)],
        [pack('N*',3002,223,7200),pack('N*',3002,223)],[pack('N*',3003,0),frame(3003,$values)]],static function(Luxtronik $m):void {
        $requests=withSHI(static function(Luxtronik $m):void {
            shiVariable($m,'SHI_Offset_Heizen',0.0);
            shiVariable($m,'Mode_WW',0);shiVariable($m,'set_223',0);
            $m->RequestAction('SHI_Offset_Heizen',-1.5);
            $m->RequestAction('Mode_WW',2);$m->RequestAction('set_223',3600);
            check(GetValue($m->GetIDForIdent('Mode_WW'))===2 && GetValue($m->GetIDForIdent('set_223'))===3600,'Java-only mode or timer did not retain Java write and readback');
            $m->properties['CommunicationMode']='java';shiThrows(fn()=>$m->RequestAction('SHI_PC_Limit',5.0));
        },['discrete'=>[10000=>1]],$m);
        $writes=array_values(array_filter($requests,static fn($r)=>$r['function']===16));
        check(array_column($writes,'address')===[10000,10002],'Java-only actions were reinterpreted as Modbus writes');
    });
};

$tests['separate control selection creates variables while disabled and migrates legacy selections without duplicates'] = static function():void {
    $m=new Luxtronik();$m->properties['IDListe']='[{"id":310041,"enabled":true}]';
    $m->properties['SHIControlList']='[{"id":310006,"enabled":true},{"id":310070,"enabled":true}]';
    $old=shiVariable($m,'Anpassung_WW',50,'Mein WW-Soll');
    $m->properties['WWsetVisible']=true;
    $m->ApplyChanges();
    check($m->GetIDForIdent('Anpassung_WW')===$old && IPS_GetName($old)==='Mein WW-Soll' && IPS_GetVariable($old)['VariableType']===1,'Shared control was replaced or renamed');
    foreach(['SHI_PC_Limit','SHI_Zirkulation_Start'] as $ident) {
        $id=$m->GetIDForIdent($ident);check($id!==false && IPS_GetVariable($id)['VariableAction']===0,'Pure Modbus control missing or active with writes disabled');
    }
    $form=fields(json_decode($m->GetConfigurationForm(),true));$reads=array_column($form['IDListe']['values'],null,'id');$controls=array_column($form['SHIControlList']['values'],null,'id');
    check(!isset($reads[310006],$reads[310041],$reads[310070]) && $controls[310006]['enabled'] && $controls[310041]['enabled'] && $controls[310070]['enabled'],'Old controls were lost or leaked into the read list');
    $m->properties['SHIControlList']='[{"id":310041,"enabled":false},{"id":310006,"enabled":true}]';
    $m->ApplyChanges();check($m->GetIDForIdent('SHI_PC_Limit')===false,'Explicit new deselection lost to a legacy selection');
    check($m->properties['CommunicationMode']==='java','Selecting a control enabled Modbus writing');
};

$tests['preferred Modbus prefetch shares fresh readings with control polling and does not keep ageing values alive'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"modbus"},{"id":110310,"enabled":true}]';
        $m->properties['SHIControlList']='[{"id":310041,"enabled":true}]';
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>276]));
        invoke($m,'StoreVisualizationPoints',[15=>12.3]);
        invoke($m,'SetBuffer','WebEEVCache',json_encode(['source'=>invoke($m,'WebEEVSourceKey'),'updatedAt'=>time(),
            'values'=>['SHI_Pumpe_Mischkreis_1'=>['value'=>false,'webId'=>'fup1','formatted'=>'Aus','unit'=>'']]]));
            $cycle=microtime(true);
        for($i=0;$i<2;++$i) {invoke($m,'UpdateModbusSupplement',true,$cycle);invoke($m,'UpdateModbusSupplement',false,$cycle);}
        check(invoke($m,'CurrentPointSource',110351)==='WebSocket','Modbus prefetch displaced a preferred WebSocket pump state');
        $cache=json_decode($m->buffers['SHICache'],true);$cache['points']['input:10108']['readAt']=microtime(true)-6;
        invoke($m,'SetBuffer','SHICache',json_encode($cache));
        invoke($m,'UpdateModbusSupplement',true,$cycle);invoke($m,'UpdateModbusSupplement',false,$cycle);
    },['input'=>[10108=>0,10310=>8000],'holding'=>[10041=>100]]);
    check(array_column($requests,'address')===[10108,10310,10041,10108],'Prefetch duplicated fresh reads, polled an unneeded source, or refreshed cache timestamps without reading');
    check(array_filter($requests,static fn($r)=>$r['function']===16)===[],'Selecting controls wrote default values');
};

$tests['tester offset selection creates or reuses the shared control and signed writes stay one register'] = static function():void {
    foreach(['IDListe','SHIControlList'] as $selectionProperty) {
        $requests=withSHI(static function(Luxtronik $m) use($selectionProperty):void {
            $m->properties[$selectionProperty]='[{"id":310000,"enabled":true},{"id":310002,"enabled":true}]';
            $m->ApplyChanges();
            $offset=$m->GetIDForIdent('SHI_Offset_Heizen');
            check($offset!==false && GetValue($offset)===-1.0,'Selected offset missing or negative raw word decoded unsigned');
            check(GetValue($m->GetIDForIdent('SHI_Modus_Heizen'))===2,'Selected heating mode was not read from HR 10000');
            $m->ApplyChanges();
            check($m->GetIDForIdent('SHI_Offset_Heizen')===$offset,'Applying the selected offset replaced its existing object');
            $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['SHIControlList']['values'],null,'id');
            check(str_contains($rows[310002]['activation'],'ID '.$offset) && str_contains($rows[310002]['activation'],'Vorhandene Variable'),'Control list hides shared-object reuse');
            $m->RequestAction('SHI_Offset_Heizen',-1.5);
            check(GetValue($offset)===-1.5 && $m->GetIDForIdent('SHI_Offset_Heizen')===$offset,'Signed write/readback replaced or misdecoded the control');
            foreach([-6400.0,6400.0,-64000.0,65526.0] as $invalid) shiThrows(fn()=>$m->RequestAction('SHI_Offset_Heizen',$invalid),'Out-of-range raw/error value accepted');
        },['discrete'=>[10000=>1],'holding'=>[10000=>2,10002=>0xFFF6,10065=>0]]);
        $writes=array_values(array_filter($requests,static fn($r)=>$r['function']===16));
        check($writes===[['function'=>16,'address'=>10002,'count'=>1,'words'=>[0xFFF1]]],'Selection/apply or invalid values wrote registers, or negative offset used wrong word order/width');
    }
};

$tests['unknown selection 320002 neither aliases the heating offset nor sends a write'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['SHIControlList']='[{"id":320002,"enabled":true}]';
        $m->ApplyChanges();
        check($m->GetIDForIdent('Anpassung_Temp')===false,'Unknown selection silently aliases a supported offset');
        shiThrows(fn()=>$m->SetSHIValue('320002',-1.0));
    });
    check(array_filter($requests,static fn($r)=>$r['function']===16)===[],'Unknown selection wrote a controller register');
};

$tests['bulk measurement source applies compatible read points and preserves write routes and object IDs'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['CommunicationMode']='java';
        $m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"java","custom":"keep"},{"id":14,"enabled":true,"preferredSource":"java"},{"id":310002,"enabled":false}]';
        $outdoor=shiVariable($m,'Aussentemperatur',10.5,'Mein Außenfühler','~Temperature');
        $gas=shiVariable($m,'Heisgastemperatur',60.0,'Mein Heißgas');
        $GLOBALS['testPropertyTargets'][$m->InstanceID]=$m;
        $values=json_encode([['id'=>15,'enabled'=>true,'preferredSource'=>'java'],['id'=>14,'enabled'=>true,'preferredSource'=>'java']]);
        try {$m->ApplyMeasurementSource('modbus',$values);} finally {unset($GLOBALS['testPropertyTargets'][$m->InstanceID]);}
        $rows=array_column(json_decode($m->properties['IDListe'],true),null,'id');
        check($m->properties['CommunicationMode']==='java' && !$rows[310002]['enabled'],'Bulk read selection changed a write control');
        check($rows[15]['preferredSource']==='modbus' && $rows[14]['preferredSource']==='java' && $rows[15]['custom']==='keep','Unsupported source or saved metadata overwritten');
        check($m->GetIDForIdent('Aussentemperatur')===$outdoor && $m->GetIDForIdent('Heisgastemperatur')===$gas
            && IPS_GetName($outdoor)==='Mein Außenfühler' && GetValue($outdoor)===0.0,'Bulk source lost identity or a valid Modbus zero');
        foreach($rows as $row) if(!in_array($row['id'],[14,15,310002],true)) check(!$row['enabled'],'Bulk source enabled an unselected variable');
        check($m->properties['MeasurementBulkSource']==='modbus' && in_array($m->InstanceID,$GLOBALS['testApplyCalls'],true),'Bulk choice did not persist through ApplyChanges');
        $before=$GLOBALS['testPropertyChanges'];
        shiThrows(fn()=>$m->ApplyMeasurementSource('invalid',$values));
        shiThrows(fn()=>$m->ApplyMeasurementSource('java','{}'));
        check($GLOBALS['testPropertyChanges']===$before,'Invalid bulk input changed the configuration');
    },['input'=>[10108=>0]]);
    check(array_filter($requests,static fn($r)=>$r['function']===16)===[],'Bulk read source sent a write command');
};

$tests['five second timer polls read each new cycle while prefetch is reused within that cycle'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $m->properties['CommunicationMode']='java';$m->properties['UpdateInterval']=5;
        $m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"modbus"}]';
        invoke($m,'StoreVisualizationPoints',[15=>10.0]);
        invoke($m,'SetBuffer','JavaAvailability',json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'count'=>276]));
        invoke($m,'SetBuffer','WebInventory',json_encode(['source'=>invoke($m,'WebEEVSourceKey'),'nextAttempt'=>time()+86400]));
        for($i=0;$i<2;++$i) {
            $cycle=microtime(true);
            invoke($m,'UpdateModbusSupplement',true,$cycle);
            invoke($m,'UpdateModbusSupplement',false,$cycle);
            $cache=json_decode($m->buffers['SHICache'],true);
            check($cache['nextAttempt']===0 && $cache['points']['input:10108']['readAt']>=$cycle,'Successful scan carries a cross-cycle cooldown');
        }
    },['input'=>[10108=>0]]);
    check(array_column($requests,'address')===[10108,10108],'A new timer cycle was skipped or prefetch was duplicated');
};

$tests['SHI warm water permission is shown and blocks unavailable controls without altering the registers'] = static function():void {
    foreach([false,true] as $allowed) {
        $requests=withSHI(static function(Luxtronik $m) use($allowed):void {
            $m->properties['SHIControlList']='[{"id":310006,"enabled":true}]';
            $m->ApplyChanges();
            $id=$m->GetIDForIdent('SHI_Sollwert_Warmwasser');
            check($id!==false && GetValue($id)===40.0,'Available external setpoint was not read');
            check($m->actions['SHI_Sollwert_Warmwasser']===$allowed,'Warm-water action ignores the SHI permission');
            $status=fields(json_decode($m->GetConfigurationForm(),true))['SHIWarmWaterStatus']['caption'];
            check(str_contains($status,'10001') && str_contains($status,$allowed?'freigegeben':'nicht freigegeben'),'Warm-water permission is unclear');
            check(shiPayload($m)['controls']['warmWaterSetpoint']['configured']===false,'Tile allows a blocked SHI action');
            if(!$allowed) {
                try {$m->RequestAction('SHI_Sollwert_Warmwasser',45.0);throw new LogicException('Disabled SHI operation accepted');}
                catch(RuntimeException $error) {check(str_contains($error->getMessage(),'Warmwasser') && str_contains($error->getMessage(),'10001'),'Permission failure lacks the function and register');}
            }
        },['discrete'=>[10001=>$allowed],'holding'=>[10006=>400]]);
        check(array_filter($requests,static fn($r)=>$r['function']===16)===[],'Permission check or ApplyChanges changed a holding register');
    }
};

$tests['simple form preserves stable properties and uses one interval and one measurement list'] = static function():void {
    $m=new Luxtronik();$json=$m->GetConfigurationForm();$form=json_decode($json,true);$all=fields($form);
    foreach(array_keys(json_decode(file_get_contents(__DIR__.'/fixtures/pre-visualization-properties.json'),true)) as $property)
        check(array_key_exists($property,$m->properties),'Stable property removed: '.$property);
    foreach(['IPAddress','UpdateInterval','Port','WebSocketPassword','ModbusPort','ModbusUnitID','ModbusTimeout','SHIControlList','IDListe'] as $property)
        check(isset($all[$property]),'Configuration field removed: '.$property);
    check(!str_contains($json,'WebSocket-Ergänzung') && !str_contains($json,'Weitere lesbare Modbus-Datenpunkte'),'Redundant protocol panels remain');
    $columns=array_column($all['IDListe']['columns'],null,'name');
    check(!$columns['preferredSource']['visible'] && !$columns['interface']['visible'] && isset($columns['source']),'Default list is still overloaded or actual source hidden');
    check($all['WebSocketPassword']['type']==='PasswordTextBox' && isset($all['WebSocketStatus']),'WebSocket auto-detection status or password missing');
    check(array_keys($m->timers)===['UpdateTimer'],'Another polling timer was introduced');
    $before=[$m->properties,$m->buffers,$m->writes];
    $m->PreviewMeasurementSource('websocket','[{"id":15,"enabled":true,"preferredSource":"java"},{"id":257,"enabled":false,"preferredSource":"java"}]');
    $updates=array_values(array_filter($m->formUpdates,fn($u)=>$u[0]==='IDListe' && $u[1]==='values'));
    $rows=json_decode(end($updates)[2],true);
    check($rows[0]['preferredSource']==='websocket' && $rows[1]['preferredSource']==='java' && !$rows[1]['enabled'],'Draft changed unsupported or unselected points');
    check([$m->properties,$m->buffers,$m->writes]===$before,'Draft made live configuration changes or wrote commands');
    $m->ShowMeasurementSources(true);
    check(array_filter($m->formUpdates,fn($u)=>$u[0]==='IDListe' && str_starts_with($u[1],'columns.') && $u[2]===true)!==[],'Advanced columns do not expand');
};

$tests['measurement row editor always offers mapped alternatives and preserves the draft'] = static function():void {
    $m=new Luxtronik();
    check(array_column(fields(json_decode($m->GetMeasurementRowForm(15,true),true))['preferredSource']['options'],'value')===['java','websocket','modbus'],
        'Missing live confirmation hides mapped alternatives');
    invoke($m,'RememberFirmwareString','V3.92.3');
    $before=[$m->properties,$m->buffers,$m->writes];
    foreach([400009=>['websocket'],110103=>['modbus'],110310=>['websocket','modbus'],15=>['java','websocket','modbus']] as $id=>$expected) {
        $editor=fields(json_decode($m->GetMeasurementRowForm($id,true),true));
        check(array_column($editor['preferredSource']['options'],'value')===$expected,'Unsupported protocol in editor for '.$id);
    }
    $form=fields(json_decode($m->GetConfigurationForm(),true));
    $id=$m->InstanceID;$IDListe=['id'=>400009];$AdvancedSourceSelection=true;
    $GLOBALS['testRowFormTargets'][$id]=$m;
    try {$editor=fields(eval($form['IDListe']['form']));} finally {unset($GLOBALS['testRowFormTargets'][$id]);}
    check(array_column($editor['preferredSource']['options'],'value')===['websocket'],'List form script does not filter the actual selected row');
    check(array_column(fields(json_decode($m->GetMeasurementRowForm(15,false),true))['preferredSource']['options'],'value')===['java','websocket','modbus'],'Normal row editor lost its source selector');
    check([$m->properties,$m->buffers,$m->writes]===$before,'Opening an editor changes configuration or sends commands');
    invoke($m,'RememberFirmwareString','V3.79');
    $editor=fields(json_decode($m->GetMeasurementRowForm(15,true),true));
    check(array_column($editor['preferredSource']['options'],'value')===['java'],'Old firmware still offers Modbus or WebSocket');
    check(!isset(fields(json_decode($m->GetMeasurementRowForm(400009,true),true))['preferredSource']),'A value without a supported protocol has a selectable source');
};

$tests['saved WebSocket choices survive loading editing and bulk changes to unrelated values'] = static function():void {
    $m=new Luxtronik();
    $m->properties['IDListe']='[{"id":14,"enabled":true,"preferredSource":"websocket"},{"id":15,"enabled":true,"preferredSource":"java"}]';
    $before=$m->properties;
    $form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
    check($rows[14]['preferredSource']==='websocket' && $m->properties===$before,'Opening the form replaces a saved WebSocket choice');
    $editor=fields(json_decode($m->GetMeasurementRowForm(14,false),true));
    check(array_column($editor['preferredSource']['options'],'value')===['java','websocket'],'WebSocket alternative disappears from normal row editor');
    $m->PreviewMeasurementSource('modbus',$m->properties['IDListe']);
    $updates=array_values(array_filter($m->formUpdates,fn($u)=>$u[0]==='IDListe' && $u[1]==='values'));
    $preview=array_column(json_decode(end($updates)[2],true),null,'id');
    check($preview[14]['preferredSource']==='websocket' && $preview[15]['preferredSource']==='modbus','Bulk preview overwrites an unrelated saved source');
    $GLOBALS['testPropertyTargets'][$m->InstanceID]=$m;
    try {$m->ApplyMeasurementSource('modbus',$m->properties['IDListe']);} finally {unset($GLOBALS['testPropertyTargets'][$m->InstanceID]);}
    $saved=array_column(json_decode($m->properties['IDListe'],true),null,'id');
    check($saved[14]['preferredSource']==='websocket' && $saved[15]['preferredSource']==='modbus','Applying bulk source destroys an unrelated source');
    invoke($m,'RememberFirmwareString','V3.79');
    $form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
    check($rows[14]['preferredSource']==='websocket','Unavailability overwrites a stored selection');
    $editor=fields(json_decode($m->GetMeasurementRowForm(14,false),true));
    check(in_array('websocket',array_column($editor['preferredSource']['options'],'value'),true),'An unavailable saved choice cannot be retained in the editor');
};

$tests['Java source availability respects real frame length and survives temporary connection failure'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');
    $source=invoke($m,'VisualizationSourceKey');
    $m->buffers['JavaAvailability']=json_encode(['source'=>$source,'count'=>20]);
    check(invoke($m,'MeasurementSources',15)===['java','websocket','modbus'] && invoke($m,'MeasurementSources',21)===['websocket','modbus'],
        'A missing Java value is advertised as supported');
    $m->buffers['JavaAvailability']=json_encode(['source'=>$source,'count'=>0,'failed'=>true,'lastSuccessfulCount'=>20]);
    check(!in_array('java',invoke($m,'MeasurementSources',21),true) && in_array('java',invoke($m,'MeasurementSources',15),true),
        'A connection failure forgets known Java capabilities');
    check(invoke($m,'MeasurementSourceOrder',300)===[],'An unsupported value invents a Java fallback');
    $m->properties['IPAddress']='192.0.2.44';
    check(in_array('java',invoke($m,'MeasurementSources',21),true),'Frame capabilities leaked to another controller');
};

$tests['failed Java polls retain the last confirmed frame length for source selection'] = static function():void {
    withServer(controllerCycles([[pack('N*',3004,0),frame(3004,array_fill(0,50,0))],
        [pack('N*',3004,0),pack('N*',3003,0,0)]]),static function(Luxtronik $m):void {
        $m->Update();
        check(in_array('java',invoke($m,'MeasurementSources',15),true) && !in_array('java',invoke($m,'MeasurementSources',50),true),
            'Successful Java frame length not used by source selection');
        $m->Update();
        $availability=json_decode($m->buffers['JavaAvailability'],true);
        check($availability['failed'] && $availability['lastSuccessfulCount']===50,'Failed poll erased confirmed Java capabilities');
        check(in_array('java',invoke($m,'MeasurementSources',15),true) && !in_array('java',invoke($m,'MeasurementSources',50),true),
            'Failed poll reopened unsupported Java sources');
    });
};

$tests['connection settings are flat below the active switch and the bulk button accompanies its selector'] = static function():void {
    $m=new Luxtronik();$m->properties['AdvancedSourceSelection']=true;invoke($m,'RememberFirmwareString','V3.92.3');
    $form=json_decode($m->GetConfigurationForm(),true);$all=fields($form);
    check(($form['elements'][1]['name']??'')==='ConnectionAndReading' && $form['elements'][1]['type']==='ColumnLayout',
        'Connection settings are not directly below the active switch');
    foreach(['IPAddress','UpdateInterval','Port','WebSocketPassword','ModbusPort','ModbusUnitID','ModbusTimeout'] as $name)
        check(isset(fields($form['elements'][1])[$name]),'Connection setting is elsewhere: '.$name);
    check(!isset($all['WebSocketTimezone']),'Unnecessary timezone control retained');
    check(!str_contains(json_encode($form),'Verbindungsdetails'),'Extra connection expansion remains');
    $choice=$all['MeasurementSourceChoice'];
    check($choice['items'][0]['name']==='MeasurementBulkSource' && $choice['items'][1]['type']==='Button','Bulk button is separated from the selector');
    foreach($form['elements'] as $element) if(($element['caption']??'')==='Diagnose und erweiterte Einstellungen')
        check(!str_contains(json_encode($element),'Für alle passenden Messwerte'),'Bulk button is duplicated in diagnostics');
    invoke($m,'RememberFirmwareString','V3.79');
    $all=fields(json_decode($m->GetConfigurationForm(),true));
    check(array_column($all['MeasurementBulkSource']['options'],'value')===['java'],'Global selector offers unsupported protocols');
};

$tests['bulk Java preview never assigns Java to WebSocket-only or Modbus-only values'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');
    $m->PreviewMeasurementSource('java','[{"id":400009,"enabled":true,"preferredSource":"websocket"},{"id":110103,"enabled":true,"preferredSource":"modbus"},{"id":15,"enabled":true,"preferredSource":"modbus"}]');
    $updates=array_values(array_filter($m->formUpdates,fn($u)=>$u[0]==='IDListe' && $u[1]==='values'));
    $rows=json_decode(end($updates)[2],true);
    check(array_column($rows,'preferredSource')===['websocket','modbus','java'],'Bulk preview assigned a protocol unsupported by the value');
    $m->properties['IDListe']='[{"id":110310,"enabled":true,"preferredSource":"java"}]';
    $list=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
    check($list[110310]['preferredSource']==='java','Loading the form silently rewrites the stored preference');
};

$tests['known SHI source is displayed separately from unread missing and valid zero data'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');
    $before=[$m->properties,$m->buffers,$m->writes];
    $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
    check($rows[110003]['source']==='Modbus/SHI' && $rows[110003]['readStatus']==='Nicht abgefragt',
        'Known SHI route is replaced by a missing-data message');
    check(invoke($m,'CurrentPointSource',110003)==='Keine aktuellen Daten','Display route fabricates an actual reading');
    check([$m->properties,$m->buffers,$m->writes]===$before,'Displaying the source changes polling or configuration');
    $m->properties['IDListe']='[{"id":110003,"enabled":true}]';
    $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
    check($rows[110003]['source']==='Modbus/SHI' && $rows[110003]['readStatus']==='Keine aktuellen Daten',
        'Selected missing data is labelled as unqueried');
    withSHI(static function(Luxtronik $m):void {
        $m->properties['IDListe']='[{"id":110003,"enabled":true}]';invoke($m,'UpdateModbusSupplement');
        $rows=array_column(fields(json_decode($m->GetConfigurationForm(),true))['IDListe']['values'],null,'id');
        check($rows[110003]['source']==='Modbus/SHI' && $rows[110003]['readStatus']==='Gelesen',
            'A read SHI zero is labelled as missing');
    },['input'=>[10003=>0]]);
};

$tests['bulk source selection affects only eligible measurements and leaves Java controls unchanged'] = static function():void {
    foreach(['modbus'=>[15,14],'websocket'=>[15,257]] as $source=>[$supported,$javaOnly]) {
        $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');
        $m->properties['IDListe']=json_encode([['id'=>$supported,'enabled'=>true,'preferredSource'=>'java'],['id'=>$javaOnly,'enabled'=>true,'preferredSource'=>'java']]);
        $originalControls=[$m->properties['CommunicationMode'],$m->properties['SHIControlList'],$m->properties['HeizungVisible']];
        $GLOBALS['testPropertyTargets'][$m->InstanceID]=$m;
        try {$m->ApplyMeasurementSource($source,$m->properties['IDListe']);} finally {unset($GLOBALS['testPropertyTargets'][$m->InstanceID]);}
        $rows=array_column(json_decode($m->properties['IDListe'],true),null,'id');
        check($rows[$supported]['preferredSource']===$source && $rows[$javaOnly]['preferredSource']==='java',
            'Bulk choice changes a value without that protocol');
        check([$m->properties['CommunicationMode'],$m->properties['SHIControlList'],$m->properties['HeizungVisible']]===$originalControls && $m->writes===0,
            'Read-source selection changed controls or wrote to the controller');
    }
};

$tests['internal COP uses heat ID 257 in kW and electrical ID 268 in W without measurement objects'] = static function():void {
    $m=new Luxtronik();
    $m->properties['InternalCOP']=false; // Previously saved switch must not prevent automatic calculation.
    invoke($m,'StoreVisualizationPoints',[257=>8.0,268=>2000.0]);
    $m->ApplyChanges();$id=$m->GetIDForIdent('copfaktor');
    check($id!==false && GetValue($id)===4.0,'Internal COP missing or watts treated as kilowatts');
    check($m->GetIDForIdent('Waermeleistung')===false && $m->GetIDForIdent('Leistungsaufnahme')===false,'Internal COP created unwanted measurement variables');
    invoke($m,'StoreVisualizationPoints',[257=>8.0,268=>0.0]);invoke($m,'RefreshDerivedMeasurements');
    check(GetValue($id)===0.0,'Zero input divided by zero or revived a previous COP');
    invoke($m,'StoreVisualizationPoints',[257=>8.0,268=>2000.0]);invoke($m,'RefreshDerivedMeasurements');
    check(GetValue($id)===4.0 && $m->GetIDForIdent('copfaktor')===$id,'Internal COP did not recover on the same variable');
    $external=new Luxtronik();$meter=shiVariable($external,'power',1.0);
    $m->properties['kwin']=$meter;invoke($m,'RefreshDerivedMeasurements');
    check(GetValue($id)===8.0,'External COP input lost priority');
    $GLOBALS['testVariables'][$meter]['value']=0.0;invoke($m,'RefreshDerivedMeasurements');
    check(GetValue($id)===0.0,'Valid external zero fell back to the internal input');
    $m->properties['kwin']=0;invoke($m,'RefreshDerivedMeasurements');
    check(GetValue($id)===4.0 && $m->GetIDForIdent('copfaktor')===$id,'Clearing the external source did not restore automatic internal COP on the same variable');
};

$tests['selected SHI controls create independent variables even while offline and write no defaults'] = static function():void {
    $m=new Luxtronik();$m->properties['SHIControlList']='[{"id":310002,"enabled":true},{"id":310006,"enabled":true}]';
    $m->properties['IDListe']='[{"id":110310,"enabled":true}]';
    $m->ApplyChanges();
    foreach(['SHI_Offset_Heizen','SHI_Sollwert_Warmwasser','SHI_Elektrische_Energie_Gesamt'] as $ident) check($m->GetIDForIdent($ident)!==false,'Offline selection created no variable: '.$ident);
    check($m->GetIDForIdent('Anpassung_Temp')===false && $m->GetIDForIdent('Anpassung_WW')===false,'SHI selection created persistent Java settings');
    check(!$m->actions['SHI_Offset_Heizen'] && !$m->actions['SHI_Sollwert_Warmwasser'],'Offline controls are actionable');
    check($m->writes===0,'Configuration applied default control values');
};

$tests['polling never replays SHI demands and controller fallback updates existing control variables'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m,string $externalRegisters):void {
        $m->properties['SHIControlList']='[{"id":310000,"enabled":true},{"id":310002,"enabled":true}]';
        invoke($m,'UpdateModbusSupplement');
        $mode=$m->GetIDForIdent('SHI_Modus_Heizen');
        $offset=$m->GetIDForIdent('SHI_Offset_Heizen');
        $m->RequestAction('SHI_Offset_Heizen',1.5);
        for($i=0;$i<3;++$i) invoke($m,'UpdateModbusSupplement');
        check(GetValue($mode)===2 && GetValue($offset)===1.5,'Active demand changed while polling');
        // A mode reset alone ends influence; a retained numeric value is not an active demand.
        file_put_contents($externalRegisters,json_encode(['holding'=>[10000=>0]]));
        invoke($m,'UpdateModbusSupplement');
        check(GetValue($mode)===0 && GetValue($offset)===1.5,'Controller mode reset ignored or unsent offset invented');
        file_put_contents($externalRegisters,json_encode(['holding'=>[10002=>0]]));
        invoke($m,'UpdateModbusSupplement');
        check(GetValue($offset)===0.0,'Controller offset reset not reflected in existing variable');
        check($m->GetIDForIdent('SHI_Modus_Heizen')===$mode && $m->GetIDForIdent('SHI_Offset_Heizen')===$offset,'Fallback replaced variable objects');
    },['discrete'=>[10000=>1],'holding'=>[10000=>0,10002=>0,10065=>0]]);
    $writes=array_values(array_filter($requests,static fn($r)=>$r['function']===16));
    check(array_column($writes,'address')===[10000,10002],'Polling resent or reset SHI commands');
};

$tests['direct TCP reads and writes ignore an old attached gateway and preserve measurement objects'] = static function():void {
    $requests=withSHI(static function(Luxtronik $m):void {
        $gateway=900001;
        $GLOBALS['testInstanceInfo'][$m->InstanceID]=['ConnectionID'=>$gateway];
        $GLOBALS['testInstanceInfo'][$gateway]=['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>'{A5F663AB-C400-4FE5-B207-4D67CC030564}']];
        $m->testParentCallback=static function():never {throw new LogicException('Direct connection used the old gateway');};
        try {
            $m->properties['SHIControlList']='[{"id":310002,"enabled":true}]';
            $m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"modbus"}]';
            $old=shiVariable($m,'Aussentemperatur',9.0,'Mein Außenfühler');
            invoke($m,'SyncSHIControlVariables');invoke($m,'UpdateModbusSupplement');
            check(GetValue($old)===-2.5 && $m->GetIDForIdent('Aussentemperatur')===$old && IPS_GetName($old)==='Mein Außenfühler','Direct reading replaced the existing variable');
            $m->RequestAction('SHI_Offset_Heizen',-1.5);
            check(GetValue($m->GetIDForIdent('SHI_Offset_Heizen'))===-1.5,'Direct signed write or readback failed');
            $report=json_decode($m->GetSHIData(),true);
            check($report['transport']==='Modbus TCP (Direktverbindung)' && $report['gatewayID']===0,'Direct transport not reported');
            check($report['nativeControlSource']==='Java' && $report['shiControlsEnabled'] && $report['writeSource']==='Modbus/SHI','Native Java settings and SHI controls conflated');
            $prior=invoke($m,'VisualizationSourceKey');$GLOBALS['testInstanceConfiguration'][$gateway]='{"DeviceID":2}';
            check(invoke($m,'VisualizationSourceKey')===$prior,'Unused gateway configuration changes current direct readings');
            check(array_keys($m->timers)===['UpdateTimer'],'Direct connection added another polling timer');
            $metadata=json_decode(file_get_contents(__DIR__.'/../WPLUX/module.json'),true);
            check($metadata['parentRequirements']===[] && $metadata['implemented']===[],'Setup still requests a native gateway');
        } finally {unset($GLOBALS['testInstanceInfo'][$m->InstanceID],$GLOBALS['testInstanceInfo'][$gateway],$GLOBALS['testInstanceConfiguration'][$gateway]);}
    },['input'=>[10108=>65511],'discrete'=>[10000=>1],'holding'=>[10002=>0,10000=>0,10065=>0]]);
    check(array_filter($requests,fn($r)=>$r['function']===16 && $r['address']===10002 && $r['words']===[65521])!==[], 'Signed offset not sent over the real TCP connection');
    check(array_filter($requests,fn($r)=>$r['function']===16 && $r['address']===10000 && $r['words']===[2])!==[], 'Required mode not sent over the real TCP connection');
};

$tests['upgrading a previously shared beta control retains its object history and never redirects it to Java'] = static function():void {
    $m=new Luxtronik();$m->properties['SHIControlList']='[{"id":310002,"enabled":true}]';
    $old=shiVariable($m,'Anpassung_Temp',-1.5,'Meine bisherige Korrektur');$m->EnableAction('Anpassung_Temp');
    $m->ApplyChanges();
    check($m->GetIDForIdent('Anpassung_Temp')===$old && GetValue($old)===-1.5 && IPS_GetName($old)==='Meine bisherige Korrektur','Beta control object or history replaced');
    check(!$m->actions['Anpassung_Temp'] && $m->GetIDForIdent('SHI_Offset_Heizen')!==false,'Legacy and independent SHI controls are not distinguished');
    shiThrows(fn()=>$m->RequestAction('Anpassung_Temp',1.0),'Old SHI action was silently redirected to a persistent Java write');
};


$tests['firmware from Java ASCII gates protocol options without changing stable configuration'] = static function():void {
    $m=new Luxtronik();$original=$m->properties;
    foreach(['V3.79'=>[false,false],'V3.81'=>[true,false],'V3.90.0'=>[true,false],
        'V3.90'=>[true,null],'V3.90.1'=>[true,true],'V3.92.3'=>[true,true]] as $version=>$expected) {
        $m=new Luxtronik();$original=$m->properties;
        $raw=array_fill(0,100,0);
        foreach(str_split($version) as $i=>$char) $raw[81+$i]=ord($char);
        invoke($m,'RememberJavaFirmware',$raw);
        check([invoke($m,'InterfaceSupport','websocket'),invoke($m,'InterfaceSupport','modbus')]===$expected,'Incorrect capabilities for '.$version);
        $form=json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);$all=fields($form);
        $panels=array_column(array_filter($form['elements'],fn($e)=>isset($e['caption'])),null,'caption');
        check($all['WebSocketPassword']['visible']===($expected[0]!==false)
            && $panels['Modbus / SHI – externe Regelung']['visible']===($expected[1]!==false),'Unsupported protocol form remains visible');
        check(str_contains($all['FirmwareStatus']['caption'],$version),'Recognized firmware missing from status');
        $rows=array_column($all['IDListe']['values'],null,'id');
        check(isset($rows[15]) && (isset($rows[400009])===($expected[0]!==false))
            && (isset($rows[110103])===($expected[1]!==false)),'Native values or unsupported extra values incorrectly filtered');
    }
    check($m->properties===$original && $m->writes===0,'Firmware detection changed configuration or sent a command');
    $reloads=$m->formReloads;invoke($m,'RememberJavaFirmware',$raw);
    check($m->formReloads===$reloads,'Unchanged version repeatedly reloads the form');
};

$tests['short Java firmware text does not invalidate complete WebSocket discovery each cycle'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3 (r12345)');
    $m->buffers['WebInventory']='{"keep":true}';$reloads=$m->formReloads;
    foreach(['V3.92.3','V3.92'] as $short) invoke($m,'RememberFirmwareString',$short);
    check($m->buffers['WebInventory']==='{"keep":true}' && $m->formReloads===$reloads,'Short version text repeatedly invalidates discovery');
    check(str_contains(invoke($m,'FirmwareStatusCaption'),'V3.92.3 (r12345)'),'Complete software version discarded');
};

$tests['unknown firmware stays unknown and WebSocket revision text is recognized'] = static function():void {
    $m=new Luxtronik();
    foreach(['','0','V3.','3.90.garbage','junk V3.92.1','3.92.1 extra'] as $invalid) {
        invoke($m,'RememberFirmwareString',$invalid);
        check(invoke($m,'InterfaceSupport','modbus')===null,'Invalid version is reported as supported or unsupported');
    }
    invoke($m,'RememberWebFirmware',['groups'=>[['name'=>'Anlagenstatus','fields'=>[['name'=>'Softwarestand','raw'=>'V3.86.1 (r7762)']]]]]);
    check(invoke($m,'InterfaceSupport','websocket')===true && invoke($m,'InterfaceSupport','modbus')===false,'Full web software text not parsed');
    $m->properties['IPAddress']='192.0.2.42';
    check(invoke($m,'InterfaceSupport','websocket')===null && invoke($m,'InterfaceSupport','modbus')===null,'Firmware evidence leaked to another controller');
};

$tests['firmware change preserves selected variables names values and source preferences'] = static function():void {
    $m=new Luxtronik();$m->properties['IDListe']='[{"id":15,"enabled":true,"preferredSource":"modbus"},{"id":110350,"enabled":true},{"id":400009,"enabled":true}]';
    $m->properties['SHIControlList']='[{"id":310002,"enabled":true}]';
    $old=shiVariable($m,'SHI_Offset_Heizen',-1.5,'Meine externe Korrektur');
    invoke($m,'RememberFirmwareString','V3.92.3');
    foreach(['WebInventory','WebEEVCache','SHICache'] as $key) $m->buffers[$key]='{"old":true}';
    $m->buffers['unrelated']='keep';$properties=$m->properties;
    invoke($m,'RememberFirmwareString','V3.79');
    foreach(['WebInventory','WebEEVCache','SHICache'] as $key) check($m->buffers[$key]==='','Previous firmware cache survived: '.$key);
    $form=fields(json_decode($m->GetConfigurationForm(),true));$rows=array_column($form['IDListe']['values'],null,'id');
    check($rows[15]['preferredSource']==='modbus' && $rows[15]['interface']==='Java' && $rows[110350]['enabled'] && $rows[400009]['enabled'],'Saved preference was overwritten or saved rows lost on older firmware');
    invoke($m,'SyncSHIControlVariables');
    check($m->properties===$properties && $m->buffers['unrelated']==='keep'
        && $m->GetIDForIdent('SHI_Offset_Heizen')===$old && GetValue($old)===-1.5 && IPS_GetName($old)==='Meine externe Korrektur','Firmware detection replaced user state');
    check(!$m->actions['SHI_Offset_Heizen'],'Known unsupported controller has an enabled action');
    shiThrows(fn()=>$m->SetSHIValue('SHI_Offset_Heizen',1.0),'Known unsupported firmware can write');
};

$tests['other firmware families use a bounded read-only capability probe with retry backoff'] = static function():void {
    $m=new class extends Luxtronik {
        public array $probeRequests=[];
        public bool $failProbe=true;
        protected function NewFirmwareProbeClient(): LuxtronikModbusClient {
            return new class($this) implements LuxtronikModbusClient {
                public function __construct(private Luxtronik $owner) {}
                public function readInputRegisters(int $address,int $count):array {
                    $this->owner->probeRequests[]=['Function'=>4,'Address'=>$address,'Quantity'=>$count];
                    if($this->owner->failProbe) throw new RuntimeException('Test device unreachable');
                    return [4];
                }
                public function readHoldingRegisters(int $address,int $count):array {throw new LogicException('Unexpected holding probe');}
                public function readDiscreteInputs(int $address,int $count):array {throw new LogicException('Unexpected permission probe');}
                public function writeHoldingRegisters(int $address,array $words):void {throw new LogicException('Probe attempted a write');}
                public function close():void {}
            };
        }
    };
    $m->properties['IPAddress']='127.0.0.1';invoke($m,'RememberFirmwareString','V4.92.3');
    invoke($m,'ProbeUncertainSHIFirmware');invoke($m,'ProbeUncertainSHIFirmware');
    check(count($m->probeRequests)===1 && invoke($m,'InterfaceSupport','modbus')===null,'Failed probe marks unsupported or ignores retry backoff');
    $m->failProbe=false;$info=json_decode($m->buffers['FirmwareInfo'],true);$info['probeAfter']=0;
    $m->buffers['FirmwareInfo']=json_encode($info);
    invoke($m,'ProbeUncertainSHIFirmware');
    check(invoke($m,'InterfaceSupport','modbus')===true && invoke($m,'InterfaceSupport','websocket')===null,'Protocol result incorrectly gates a different interface');
    foreach($m->probeRequests as $request) check($request['Function']===4 && $request['Address']===10701 && $request['Quantity']===1,'Capability probe writes or reads undocumented data');
    $before=count($m->probeRequests);$m->GetConfigurationForm();invoke($m,'ProbeUncertainSHIFirmware');
    check(count($m->probeRequests)===$before && $m->writes===0,'Form/getter polls hardware or repeats a confirmed probe');
};

$tests['successful protocol evidence and Java failure do not become unsupported firmware'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.79');
    $m->buffers['JavaAvailability']=json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'failed'=>true]);
    check(invoke($m,'InterfaceSupport','websocket')===null && invoke($m,'InterfaceSupport','modbus')===null,'An unreachable controller was reported as unsupported');
    unset($m->buffers['JavaAvailability']);invoke($m,'MarkInterfaceSupported','websocket');
    invoke($m,'MarkInterfaceSupported','modbus');
    check(invoke($m,'InterfaceSupport','websocket')===true && invoke($m,'InterfaceSupport','modbus')===true,'Working backported interfaces ignored');
    $m->buffers['SHICache']=json_encode(['source'=>invoke($m,'VisualizationSourceKey'),'stale'=>true]);
    check(invoke($m,'InterfaceSupport','modbus')===true,'A later connection failure revoked positive firmware evidence');
};

$tests['SHI table uses web names and a single short example instead of prerequisite instructions'] = static function():void {
    $m=new Luxtronik();$form=fields(json_decode($m->GetConfigurationForm(),true));
    $table=$form['SHIControlList'];$columns=array_column($table['columns'],null,'name');
    foreach($table['values'] as $row) check(!str_starts_with($row['name'],'SHI') && $row['status']==='','Table still has inconsistent prefixes or unselected activation hints');
    check(!$columns['activation']['visible'] && $columns['value']['caption']==='Aktueller Wert','Verbose activation details still displayed');
    check(str_contains($m->GetConfigurationForm(),'Beispiel:') && str_contains($m->GetConfigurationForm(),'+1,0 K'),'Short control example missing');
};

$failed=0;foreach ($tests as $name=>$test) {try {$test();echo 'PASS '.$name."\n";}catch(Throwable $e){++$failed;fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage()."\n");}}
echo count($tests).' tests, '.$failed." failures\n";if ($failed) exit(1);
