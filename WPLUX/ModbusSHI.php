<?php

require_once __DIR__ . '/ModbusTCP.php';
require_once __DIR__ . '/SHIRegisters.php';

/** Optional SHI transport. Java indices and the Java control protocol stay independent. */
trait LuxtronikModbusSHI
{
    use LuxtronikSHIRegisters;

    private function SHIKey(array $point): string
    {
        return $point['bank'] . ':' . $point['address'];
    }

    private function SHICatalog(): array
    {
        $result = [];
        foreach (self::SHI_REGISTERS as $point) $result[$this->SHIKey($point)] = $point;
        return $result;
    }

    /** Same physical values retain their native Ident; derived states have no invented register. */
    private function SHIDerivedPoints(): array
    {
        return [
            23 => ['input:10106', null], 267 => ['holding:10060', null],
            44 => ['input:10000', 1], 45 => ['input:10000', 2],
            48 => ['input:10000', 4], 50 => ['input:10000', 16]
        ];
    }

    private function SHISelections(): array
    {
        $result = [];
        foreach ($this->SHICatalog() as $key => $point) $result[$point['selection']] = $point + ['key' => $key];
        $catalog = $this->SHICatalog();
        foreach ($this->SHIDerivedPoints() as $id => [$key, $mask]) {
            $native = self::DATA_POINT_CONFIG[$id];
            $result[$id] = array_replace($catalog[$key], ['key'=>$key, 'selection'=>$id, 'legacy'=>$id,
                'ident'=>$native['name'], 'label'=>$native['name'], 'mask'=>$mask, 'bank'=>'derived']);
        }
        return $result;
    }

    private function SharedNumericControlEnabled(string $ident): bool
    {
        $property=['Anpassung_WW'=>'WWsetVisible','Anpassung_Temp'=>'TempsetVisible','Anpassung_RBE'=>'RBEsetVisible'][$ident]??null;
        if ($property===null) return false;
        if ($this->ReadPropertyBoolean($property)) return true;
        $selections=$this->SHISelections();
        foreach (json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) {
            $point=$selections[(int)($row['id']??0)]??null;
            if (($point['ident']??'')===$ident && !empty($row['enabled'])) return true;
        }
        return false;
    }

    private function HandleSHIAction(string $ident,mixed $value): void
    {
        if (in_array($ident,['Anpassung_WW','Anpassung_Temp','Anpassung_RBE'],true) && !$this->SharedNumericControlEnabled($ident)) {
            throw new RuntimeException('Diese gemeinsame Steuervariable ist nicht aktiviert.');
        }
        $this->SetSHIValue($ident,$value);
    }

    private function SHICache(): array
    {
        $cache = json_decode($this->GetBuffer('SHICache'), true);
        return is_array($cache) && ($cache['source'] ?? '') === $this->VisualizationSourceKey() ? $cache : [];
    }

    private function NewSHIClient(): LuxtronikModbusTCP
    {
        return new LuxtronikModbusTCP($this->ReadPropertyString('IPAddress'), $this->ReadPropertyInteger('ModbusPort'),
            $this->ReadPropertyInteger('ModbusUnitID'), $this->ReadPropertyInteger('ModbusTimeout'));
    }

    private function DisableSHIActions(): void
    {
        foreach (self::SHI_REGISTERS as $point) {
            if ($point['bank'] === 'holding' && @$this->GetIDForIdent($point['ident']) !== false) $this->DisableAction($point['ident']);
        }
    }

    private function ApplyChangesModbus(): void
    {
        // Existing Java modes have different semantics. No reinterpretation of their enum values.
        foreach (['Mode_Heizung','Mode_WW','Mode_Kuehlung','Mode_Schwimmbad','Anpassung_Temp','Anpassung_WW','Anpassung_RBE',
            ...array_map(static fn(int $id): string => 'set_' . $id, range(223,505))] as $ident) {
            if (@$this->GetIDForIdent($ident) !== false) $this->DisableAction($ident);
        }
        $this->DisableSHIActions();
        if (!$this->ReadPropertyBoolean('InstanceStatus')) {
            $this->SetTimerInterval('UpdateTimer',0);
            $this->SetStatus(104);
            return;
        }
        $this->CreateVariableProfiles();
        foreach (['kwin'=>'copfaktor','kwhin'=>'jazfaktor'] as $property=>$ident) {
            $meter=$this->ReadPropertyInteger($property);
            if ($meter>0 && IPS_VariableExists($meter) && @$this->GetIDForIdent($ident)===false) {
                $this->RegisterVariableFloat($ident,$ident==='copfaktor'?'COP-Faktor':'JAZ-Faktor','WPLUX.Cop');
            }
        }
        // These three setpoints have the same meaning and units in both transports.
        foreach (['WWsetVisible'=>['Anpassung_WW','Warmwasser Soll','WPLUX.Wset'],
            'TempsetVisible'=>['Anpassung_Temp','Temperaturkorrektur','WPLUX.Tset'],
            'RBEsetVisible'=>['Anpassung_RBE','Raumtemperatur Soll','~Temperature']] as $property=>[$ident,$label,$profile]) {
            if ($this->SharedNumericControlEnabled($ident) && @$this->GetIDForIdent($ident)===false) $this->RegisterVariableFloat($ident,$label,$profile);
        }
        $this->SetTimerInterval('UpdateTimer',$this->ReadPropertyInteger('UpdateInterval')*1000);
        $this->UpdateModbus();
    }

    private function ReadSHIPoint(LuxtronikModbusTCP $client, array $point): int|float|bool|null
    {
        $words = match ($point['bank']) {
            'discrete'=>$client->readDiscreteInputs($point['address'],1),
            'holding'=>$client->readHoldingRegisters($point['address'],1),
            default=>$client->readInputRegisters($point['address'],str_ends_with($point['datatype'],'32')?2:1)
        };
        return $this->DecodeModbusValue($words,$point['datatype'],(float)$point['factor']);
    }

    private function UpdateModbus(): void
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus')) return;
        $lock='WPLUX.SHI.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1)) return;
        $client=null;
        $source=$this->VisualizationSourceKey();
        $status=201;
        try {
            try {
                $client=$this->NewSHIClient();
                $previous=$this->SHICache()['points']??[];
                $points=[];
                $unavailable=[];
                foreach ($this->SHICatalog() as $key=>$point) {
                    try {
                        $value=$this->ReadSHIPoint($client,$point);
                    } catch (LuxtronikModbusException $error) {
                        if (!in_array($error->getCode(),[1,2,3],true)) throw $error;
                        $value=null;
                        $this->SendDebug('SHI',$key.': '.$error->getMessage(),0);
                    }
                    if ($value===null) {
                        $unavailable[]=$key;
                        $this->SendDebug('SHI',$key.': nicht verfügbar',0);
                        continue;
                    }
                    $old=$previous[$key]??null;
                    $points[$key]=['value'=>$value,'changedAt'=>$old!==null && $old['value']!==$value?microtime(true):($old['changedAt']??0)];
                }
                if ($source!==$this->VisualizationSourceKey()) return;
                $this->SetBuffer('SHICache',json_encode(['source'=>$source,'updatedAt'=>time(),
                    'stale'=>false,'points'=>$points,'unavailable'=>$unavailable],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
                $status=$points===[]?203:($unavailable===[]?102:204);
            } catch (InvalidArgumentException $error) {
                $status=202;
                $this->LogMessage($error->getMessage(),KL_ERROR);
            } catch (RuntimeException $error) {
                $cache=$this->SHICache();
                if ($cache!==[]) {
                    $cache['stale']=true;
                    $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR));
                }
                $this->LogMessage('SHI: '.$error->getMessage(),KL_ERROR);
            } finally {
                $client?->close();
            }
            if ($source!==$this->VisualizationSourceKey() || !$this->ReadPropertyBoolean('InstanceStatus')) return;
            $complete=$this->ReadSHIJavaSupplement();
            if ($source!==$this->VisualizationSourceKey() || !$this->ReadPropertyBoolean('InstanceStatus')) return;
            $this->PublishSHIValues();
            $this->TraceHybridSources();
            $this->SetStatus(!$complete && $status===102?204:$status);
        } finally {
            $client?->close();
            IPS_SemaphoreLeave($lock);
            $this->UpdateWebEEV();
            $this->RefreshVisualization();
        }
    }

    /** Only native measurements needed by the graphic, selected objects or configured meters. */
    private function HybridMeasurementIDs(): array
    {
        $ids=$this->GetVisualizationRequiredPointIDs();
        foreach (json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) {
            $id=(int)($row['id']??0);
            if ($id>0 && $id<self::MAX_RESPONSE_VALUES && !empty($row['enabled'])) $ids[$id]=true;
        }
        if ($this->ReadPropertyInteger('kwin')>0) $ids[257]=true;
        if ($this->ReadPropertyInteger('kwhin')>0) {$ids[151]=true;$ids[152]=true;}
        return $ids;
    }

    /** One read-only 3004 frame. Never invokes Update(), 3003, a write, or object deletion. */
    private function ReadSHIJavaSupplement(): bool
    {
        if (!$this->ReadPropertyBoolean('ModbusJavaSupplement')) {
            $this->StoreVisualizationPoints([]);
            return true;
        }
        $missing=[];
        foreach ($this->HybridMeasurementIDs() as $id=>$_) {
            $ident=$this->GetDataPointConfig((int)$id)['name'];
            if ($this->GetSHIVisualizationPoint($ident)===null) $missing[$id]=$ident;
        }
        if ($missing===[]) {
            $this->StoreVisualizationPoints([]);
            return true;
        }
        $source=$this->VisualizationSourceKey();
        $raw=$this->ReadData(3004);
        if ($source!==$this->VisualizationSourceKey()) return false;
        $values=[];
        foreach ($missing as $id=>$ident) {
            if ($raw!==null && array_key_exists($id,$raw)) $values[$id]=$this->convertValueBasedOnID($raw[$id],$id);
        }
        // A failed/truncated Java reply clears current supplementary data, not existing objects.
        $this->StoreVisualizationPoints($values);
        $selected=[];
        foreach (json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) {
            if (!empty($row['enabled'])) $selected[(int)($row['id']??0)]=true;
        }
        foreach ($values as $id=>$value) {
            if (!isset($selected[$id])) continue;
            $ident=$missing[$id];
            if (@$this->GetIDForIdent($ident)===false) $this->CreateOrUpdateVariable($ident,$value,$id);
            else $this->SetValueIfChanged($ident,$value);
        }
        $complete=count($values)===count($missing);
        if (!$complete) $this->SendDebug('Java-Ergänzung','Nicht alle benötigten Messwerte verfügbar; aktuelle Modbus-Werte bleiben erhalten.',0);
        return $complete;
    }

    private function GetHybridVisualizationPoint(string $ident): ?array
    {
        $point=$this->GetSHIVisualizationPoint($ident);
        if ($point!==null) return $point;
        return $this->ReadPropertyBoolean('ModbusJavaSupplement')?$this->GetCachedVisualizationPoint($ident):null;
    }

    private function TraceHybridSources(): void
    {
        $idents=[];
        foreach (self::SHI_REGISTERS as $definition) $idents[$definition['ident']]=true;
        foreach ($this->HybridMeasurementIDs() as $id=>$_) $idents[$this->GetDataPointConfig((int)$id)['name']]=true;
        foreach ($idents as $ident=>$_) {
            $point=$this->GetHybridVisualizationPoint($ident);
            $description=$point===null?'keine aktuelle Quelle; vorhandener Objektwert bleibt unverändert'
                :$point['source'].' ('.($point['source']==='Modbus'?$point['protocolRegister']:'3004, ID '.$point['protocolId']).'): '.$point['formatted'];
            $this->SendDebug('Datenquelle',$ident.': '.$description,0);
        }
    }

    private function DecodeModbusValue(array $words,string $type,float $factor): int|float|bool|null
    {
        $raw=str_ends_with($type,'32')?($words[0]<<16)|$words[1]:$words[0];
        if ($raw===32767 || ($type==='int32' && $raw===2147483647)) return null;
        if ($type==='bool') return in_array($raw,[0,1],true)?(bool)$raw:null;
        if ($type==='int16' && $raw>=32768) $raw-=65536;
        if ($type==='int32' && $raw>=2147483648) $raw-=4294967296;
        return $factor===1.0?$raw:round($raw*$factor,6);
    }

    private function SHIEngineeringValue(array $point,mixed $value): mixed
    {
        if (($point['mask']??null)!==null) return ((int)$value & $point['mask'])!==0;
        if (($point['format']??'')==='hours_duration') return (string)(int)$value.'h 0m';
        if ($point['legacy']!==null) return match (self::DATA_POINT_CONFIG[$point['legacy']]['type']) {
            'bool'=>(bool)$value,'int'=>(int)$value,'float'=>(float)$value,default=>(string)$value
        };
        return $value;
    }

    private function SHIFormatted(array $point,mixed $value): string
    {
        if (isset($point['options'][(int)$value])) return $point['options'][(int)$value];
        if (is_bool($value)) return $value?'Ein':'Aus';
        if (!empty($point['bitmask'])) {
            $labels=[];
            foreach ([1=>'VD1',2=>'VD2',4=>'ZWE1',8=>'ZWE2',16=>'ZWE3'] as $bit=>$label) if ((int)$value & $bit) $labels[]=$label;
            return $labels===[]?'Aus':implode(', ',$labels);
        }
        if (is_string($value)) return $value;
        return ($point['factor']<1?number_format((float)$value,1,'.',''):(string)$value).($point['unit']!==''?' '.$point['unit']:'');
    }

    private function GetSHIVisualizationPoint(string $ident): ?array
    {
        $cache=$this->SHICache();
        $points=$cache['points']??[];
        if (!empty($cache['stale'])) return null;
        foreach ($this->SHISelections() as $point) {
            if ($point['ident']!==$ident) continue;
            $entry=$points[$point['key']]??null;
            if ($entry===null || ($ident==='Betriebszustand' && $entry['value']===6)) return null;
            $value=$this->SHIEngineeringValue($point,$entry['value']);
            $id=@$this->GetIDForIdent($ident);
            return ['variableId'=>$id!==false && IPS_VariableExists($id)?$id:0,'ident'=>$ident,'value'=>$value,
                'formatted'=>$this->SHIFormatted($point,$value),'unit'=>$point['unit'],'binary'=>is_bool($value),'changedAt'=>$entry['changedAt']??0,
                'source'=>'Modbus','protocolRegister'=>$point['key']];
        }
        return null;
    }

    private function EnsureSHIVariable(array $point): void
    {
        $ident=$point['ident'];
        if (@$this->GetIDForIdent($ident)!==false) return;
        if ($point['legacy']!==null) {
            $native=self::DATA_POINT_CONFIG[$point['legacy']];
            $method=match ($native['type']) {'bool'=>'RegisterVariableBoolean','int'=>'RegisterVariableInteger','float'=>'RegisterVariableFloat',default=>'RegisterVariableString'};
            $this->$method($ident,$ident,$native['profile'],$point['legacy']);
            return;
        }
        $type=$point['datatype']==='bool'?0:($point['factor']<1?2:1);
        $profile='WPLUX.SHI.'.strtoupper(substr($point['bank'],0,1)).$point['address'];
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile,$type);
            IPS_SetVariableProfileDigits($profile,$type===2?1:0);
            IPS_SetVariableProfileText($profile,'',$point['unit']!==''?' '.$point['unit']:'');
            IPS_SetVariableProfileValues($profile,(float)($point['min']??0),(float)($point['max']??0),$type===2?0.1:1);
            foreach ($point['options']??[] as $value=>$label) IPS_SetVariableProfileAssociation($profile,(float)$value,$label,'',-1);
        }
        $method=match ($type) {0=>'RegisterVariableBoolean',1=>'RegisterVariableInteger',default=>'RegisterVariableFloat'};
        $this->$method($ident,$point['label'],$profile);
    }

    private function PublishSHIValues(): void
    {
        $selected=[];
        foreach (json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) {
            if (!empty($row['enabled'])) $selected[(int)$row['id']]=true;
        }
        $cache=$this->SHICache();
        if (!empty($cache['stale'])) $cache['points']=[];
        foreach ($this->SHISelections() as $id=>$point) {
            $entry=$cache['points'][$point['key']]??null;
            $ident=$point['ident'];
            if ($point['bank']==='holding' && @$this->GetIDForIdent($ident)!==false) $this->DisableAction($ident);
            if ($entry===null || ($ident==='Betriebszustand' && $entry['value']===6)) continue;
            if (!isset($selected[$id])) continue;
            $this->EnsureSHIVariable($point);
            $this->SetValueIfChanged($ident,$this->SHIEngineeringValue($point,$entry['value']));
            if ($point['bank']==='holding') $this->EnableAction($ident);
        }
        foreach (['Anpassung_WW'=>['WWsetVisible',10006], 'Anpassung_Temp'=>['TempsetVisible',10002], 'Anpassung_RBE'=>['RBEsetVisible',10060]] as $ident=>[$property,$address]) {
            $entry=$cache['points']['holding:'.$address]??null;
            if (@$this->GetIDForIdent($ident)!==false) {
                $this->DisableAction($ident);
                if ($entry!==null && $this->SharedNumericControlEnabled($ident)) {
                    $this->SetValueIfChanged($ident,$entry['value']);
                    $this->EnableAction($ident);
                }
            }
        }
        $power=$this->GetHybridVisualizationPoint('Waermeleistung');
        if ($power!==null) $this->calc_cop('cop',(float)$power['value']);
        $heat=$this->GetHybridVisualizationPoint('Waermemengenzaehler_Heizung');
        $water=$this->GetHybridVisualizationPoint('Waermemengenzaehler_Brauchwasser');
        if ($heat!==null && $water!==null) {
            $this->calc_jaz('jaz',(float)$heat['value']+(float)$water['value']);
        }
    }

    /** Snapshot only: no network activity or object creation. */
    public function GetSHIData(): string
    {
        $cache=$this->SHICache();
        $values=[];
        foreach ($this->SHICatalog() as $key=>$point) {
            $entry=$cache['points'][$key]??null;
            $current=$entry!==null && empty($cache['stale']);
            $values[]=['bank'=>$point['bank'],'address'=>$point['address'],'ident'=>$point['ident'],'name'=>$point['label'],
                'value'=>$entry['value']??null,'unit'=>$point['unit'],'available'=>$current,'writable'=>$point['bank']==='holding',
                'source'=>$current?'Modbus':null];
        }
        $java=[];
        if ($this->ReadPropertyBoolean('ModbusJavaSupplement')) foreach ($this->VisualizationCachedPoints() as $ident=>$entry) {
            $java[]=['ident'=>$ident,'id'=>$entry['id'],'value'=>$entry['value'],'source'=>'Java'];
        }
        return json_encode(['updatedAt'=>$cache['updatedAt']??null,'stale'=>$cache['stale']??true,'values'=>$values,
            'javaSupplementEnabled'=>$this->ReadPropertyBoolean('ModbusJavaSupplement'),'javaSupplement'=>$java],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }

    private function SHIWriteDefinition(string $ident): array
    {
        foreach (self::SHI_REGISTERS as $point) if ($point['bank']==='holding' && $point['ident']===$ident) return $point;
        throw new InvalidArgumentException('Keine dokumentierte SHI-Schreibfunktion für '.$ident.'. Java-Betriebsarten und Zeitprogramme werden nicht umgedeutet.');
    }

    private function EncodeSHIValue(array $point,mixed $value): int
    {
        if (is_bool($value) && $point['datatype']==='bool') $value=(int)$value;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new InvalidArgumentException('Der SHI-Steuerwert muss eine endliche Zahl sein.');
        if ($value<$point['min'] || $value>$point['max'] || ($point['options']!==[] && !array_key_exists((int)$value,$point['options']))) throw new InvalidArgumentException('SHI-Wert außerhalb des dokumentierten Bereichs.');
        $raw=$value/$point['factor'];
        if (abs($raw-round($raw))>0.000001) throw new InvalidArgumentException('Der SHI-Wert passt nicht zur Registerauflösung.');
        return ((int)round($raw)) & 0xffff;
    }

    private function ConfirmSHIWrite(LuxtronikModbusTCP $client,int $address,int $word): void
    {
        $client->writeHoldingRegisters($address,[$word]);
        $deadline=microtime(true)+1.5;
        do {
            if ($client->readHoldingRegisters($address,1)===[$word]) return;
            if (microtime(true)>=$deadline) break;
            usleep(100000);
        } while (true);
        throw new RuntimeException('SHI-Schreibwert '.$address.' wurde beim Rücklesen nicht bestätigt.');
    }

    /** Only documented holding registers, validated before connecting; never replayed by polling. */
    public function SetSHIValue(string $Ident,mixed $Value): bool
    {
        if ($this->ReadPropertyString('CommunicationMode')!=='modbus' || !$this->ReadPropertyBoolean('InstanceStatus')) throw new RuntimeException('SHI-Schreiben benötigt eine aktive Instanz im Modbus-Modus.');
        $point=$this->SHIWriteDefinition($Ident);
        $word=$this->EncodeSHIValue($point,$Value);
        $id=@$this->GetIDForIdent($Ident);
        if ($id!==false && IPS_VariableExists($id) && IPS_GetVariable($id)['VariableType']===1 && (float)$Value!==floor((float)$Value)) throw new InvalidArgumentException('Der Wert passt nicht zum bestehenden ganzzahligen Variablentyp.');
        $lock='WPLUX.SHI.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) throw new RuntimeException('Eine SHI-Abfrage oder Bedienaktion läuft bereits.');
        $client=null;
        $attempted=false;
        try {
            $client=$this->NewSHIClient();
            $catalog=$this->SHICatalog();
            $deps=$point['deps'];
            if ($word===0 && str_starts_with($point['ident'],'SHI_Modus_')) $deps=[];
            if ($point['di']!==null && $this->ReadSHIPoint($client,$catalog['discrete:'.$point['di']])!==true) throw new RuntimeException('Die zugehörige Funktion ist am Regler nicht aktiviert.');
            $current=$this->ReadSHIPoint($client,$point);
            if ($current===null) throw new RuntimeException('Dieses SHI-Register ist am Regler nicht verfügbar.');
            $before=[];
            foreach ($deps as $address=>$expected) {
                $before[$address]=$this->ReadSHIPoint($client,$catalog['holding:'.$address]);
                if ($before[$address]===null) throw new RuntimeException('Erforderliches SHI-Modusregister '.$address.' ist nicht verfügbar.');
            }
            if ($point['address']===10041 && !in_array($this->ReadSHIPoint($client,$catalog['holding:10040']),[1,2],true)) throw new RuntimeException('Zuerst LPC-Modus Soft Limit oder Hard Limit auswählen.');
            // Respect controller-specific DHW limits in addition to the handbook range.
            if ($point['address']===10006) {
                foreach ([10122=>'min',10123=>'max'] as $address=>$bound) {
                    $limit=$this->ReadSHIPoint($client,$catalog['input:'.$address]);
                    if ($limit!==null && (($bound==='min' && $Value<$limit) || ($bound==='max' && $Value>$limit))) throw new InvalidArgumentException('Warmwasser-Sollwert außerhalb der aktuellen Reglergrenzen.');
                }
            }
            if ($point['address']===10060) {
                foreach ([10000,10010,10020,10030,10065] as $address) {
                    $mode=$this->ReadSHIPoint($client,$catalog['holding:'.$address]);
                    if ($mode!==null && $mode!==0) throw new RuntimeException('Vor Raumtemperaturvorgabe die SHI-Heizungs-/Mischkreisvorgaben auf keinen Einfluss setzen.');
                }
            }
            // Stage the requested value first, then activate its documented mode.
            $attempted=true;
            $this->ConfirmSHIWrite($client,$point['address'],$word);
            foreach ($deps as $address=>$expected) {
                if ($before[$address]!==$expected) $this->ConfirmSHIWrite($client,$address,$expected);
            }
            $confirmed=[];
            foreach ([$point['address'],...array_keys($deps)] as $address) {
                $value=$this->ReadSHIPoint($client,$catalog['holding:'.$address]);
                $expected=$address===$point['address']?$this->DecodeModbusValue([$word],$point['datatype'],(float)$point['factor']):$deps[$address];
                if ($value!==$expected) throw new RuntimeException('SHI-Gesamtvorgabe beim abschließenden Rücklesen nicht bestätigt.');
                $confirmed['holding:'.$address]=['value'=>$value,'changedAt'=>microtime(true)];
            }
            $cache=$this->SHICache();
            $cache['source']=$this->VisualizationSourceKey();
            // Writing cannot make an earlier failed/full scan current again.
            $cache['stale']=$cache['stale']??true;
            $cache['points']=array_replace($cache['points']??[],$confirmed);
            $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            $this->PublishSHIValues();
            $this->SendDebug('Datenquelle',$point['ident'].': Schreiben über Modbus (holding:'.$point['address'].') bestätigt: '.$this->SHIFormatted($point,$Value),0);
            return true;
        } catch (Throwable $error) {
            if ($attempted) {
                $cache=$this->SHICache();$cache['stale']=true;
                $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR));
                $this->DisableSHIActions();
                $this->SetStatus(201);
                $this->LogMessage('SHI: Vorgabe möglicherweise teilweise übernommen; Zustand erneut abfragen. '.$error->getMessage(),KL_ERROR);
            }
            throw $error;
        } finally {
            $client?->close();
            IPS_SemaphoreLeave($lock);
            $this->RefreshVisualization();
        }
    }

    private function AddModbusForm(array $form): array
    {
        $modbus=$this->ReadPropertyString('CommunicationMode')==='modbus';
        array_splice($form['elements'],1,0,[['type'=>'Select','name'=>'CommunicationMode','caption'=>'Kommunikation',
            'options'=>[['caption'=>'Java (Standard)','value'=>'java'],['caption'=>'Modbus TCP / SHI (Testversion)','value'=>'modbus']]]]);
        $panel=['type'=>'ExpansionPanel','caption'=>'Modbus TCP / SHI','items'=>[
            ['type'=>'Label','caption'=>'Luxtronik 2.1 ab V3.90.1 mit aktivem SHI. Gleiche IP-Adresse; Java-Einstellungen bleiben erhalten.'],
            ['type'=>'NumberSpinner','name'=>'ModbusPort','caption'=>'Modbus-Port'],
            ['type'=>'NumberSpinner','name'=>'ModbusUnitID','caption'=>'Modbus-Geräteadresse (Unit-ID)'],
            ['type'=>'NumberSpinner','name'=>'ModbusTimeout','caption'=>'Modbus-Timeout (Millisekunden, 100–5000)'],
            ['type'=>'CheckBox','name'=>'ModbusJavaSupplement','caption'=>'Fehlende Messwerte ergänzend über Java lesen'],
            ['type'=>'Label','caption'=>'Alle dokumentierten Werte werden intern gelesen. Zusätzliche Objekte und Bedienpunkte über die ID-Liste auswählen. Die Spalte Schnittstelle zeigt Java, Modbus oder beide Protokolle. Keine automatischen Schreibvorgaben.']]];
        $index=count($form['elements']);
        foreach ($form['elements'] as $i=>$element) if (($element['name']??'')==='IDListe') {$index=$i;break;}
        array_splice($form['elements'],$index,0,[$panel]);
        $selections=$this->SHISelections();
        $saved=[];
        foreach (json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) if (isset($row['id'])) $saved[(int)$row['id']]=$row;
        foreach ($form['elements'] as &$element) {
            if (($element['name']??'')==='IDListe') {
                // Synthetic keys are selection IDs only. Java scans never send them as indices.
                $element['values']=array_values(array_filter($element['values'],static fn(array $r):bool=>(int)$r['id']<100000));
                $element['columns'][]=['name'=>'interface','caption'=>'Schnittstelle','width'=>'150','save'=>false];
                foreach ($element['values'] as &$row) {
                    $mapping=$selections[(int)$row['id']]??null;
                    $row['interface']=$mapping===null?'Java':'Java + Modbus';
                }
                unset($row);
                foreach ($selections as $id=>$point) if ($id>=100000) $element['values'][]=[
                    'enabled'=>(bool)($saved[$id]['enabled']??false),'id'=>$id,'name'=>$point['label'],'interface'=>!empty($point['shared'])?'Java + Modbus':'Modbus'];
            }
            if (!$modbus) continue;
            if (($element['name']??'')==='Port') {
                $element['visible']=$this->ReadPropertyBoolean('ModbusJavaSupplement');
                $element['caption']='Java-Port für ergänzende Messwerte (8888 oder 8889)';
            }
            if (($element['caption']??'')==='Timer / Zusatzfunktionen') {
                foreach ($element['items'] as &$field) {
                    if (!in_array($field['name']??'',['WWsetVisible','TempsetVisible','RBEsetVisible','kwin','kwhin','kwhout'],true)) $field['visible']=false;
                }
                unset($field);
                $element['caption']='Gemeinsame Sollwerte / COP / JAZ';
            }
        }
        unset($element);
        if ($modbus) array_unshift($form['elements'],['type'=>'Label','caption'=>'SHI-Testversion: Modbus liefert seine verfügbaren Werte; bei aktivierter Java-Ergänzung kommen fehlende Messwerte lesend über Java. Schreibbefehle bleiben bei Modbus. Java-Betriebsarten und Zeitprogramme sind hier nicht bedienbar.']);
        $form['status']=[
            ['code'=>102,'icon'=>'active','caption'=>'Instanz aktiv'],['code'=>104,'icon'=>'inactive','caption'=>'Instanz deaktiviert'],
            ['code'=>201,'icon'=>'error','caption'=>'SHI-Verbindung, Antwort oder Schreibbestätigung fehlerhaft'],
            ['code'=>202,'icon'=>'error','caption'=>'SHI-Verbindungseinstellungen ungültig'],
            ['code'=>203,'icon'=>'error','caption'=>'Keine verfügbaren SHI-Messwerte geliefert'],
            ['code'=>204,'icon'=>'error','caption'=>'Messwerte teilweise verfügbar (Modbus/Java)']];
        return $form;
    }
}
