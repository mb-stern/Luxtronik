<?php

require_once __DIR__ . '/ModbusTCP.php';
require_once __DIR__ . '/SHIRegisters.php';

/** Optional SHI transport. Java indices and the Java control protocol stay independent. */
trait LuxtronikModbusSHI
{
    use LuxtronikSHIRegisters;

    private function TimerControlWriteCaptions(?bool $modbusWrites = null): array
    {
        $modbusWrites ??= $this->ReadPropertyString('CommunicationMode') === 'modbus';
        $controls = [
            'HeizungVisible'=>['Mode_Heizung','Steuervariable für Heizungs-Modus aktivieren'],
            'WarmwasserVisible'=>['Mode_WW','Steuervariable für Warmwasser-Modus aktivieren'],
            'SchwimmbadVisible'=>['Mode_Schwimmbad','Steuervariable für Schwimmbad-Modus aktivieren'],
            'KuehlungVisible'=>['Mode_Kuehlung','Steuervariable für Kühlungs-Modus aktivieren'],
            'TempsetVisible'=>['Anpassung_Temp','Steuervariable für Temperaturkorrektur aktivieren'],
            'WWsetVisible'=>['Anpassung_WW','Steuervariable für Warmwasser-Soll aktivieren'],
            'RBEsetVisible'=>['Anpassung_RBE','Steuervariable für Raumbedieneinheit aktivieren']
        ];
        $captions = [];
        foreach ($controls as $property=>[$ident,$label]) {
            $source = $modbusWrites && $this->SupportsModbusWrite($ident) ? 'Modbus/SHI' : 'Java';
            $captions[$property] = $label . ' (' . $source . ')';
        }
        return $captions;
    }

    public function RefreshWriteSourceLabels(bool $ModbusWrites): void
    {
        foreach ($this->TimerControlWriteCaptions($ModbusWrites) as $property=>$caption) {
            $this->UpdateFormField($property, 'caption', $caption);
        }
    }

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
            23 => ['input:10106', null],
            43 => ['input:10350', null],
            44 => ['input:10000', 1], 45 => ['input:10000', 2],
            48 => ['input:10000', 4], 50 => ['input:10000', 16]
        ];
    }

    private function SHISelections(): array
    {
        $result = [];
        foreach ($this->SHICatalog() as $key => $point) $result[$point['selection']] = $point + ['key' => $key];
        $catalog = $this->SHICatalog();
        $result[110350]['aliasOf']=43;
        foreach ($this->SHIDerivedPoints() as $id => [$key, $mask]) {
            $native = self::DATA_POINT_CONFIG[$id];
            $result[$id] = array_replace($catalog[$key], ['key'=>$key, 'selection'=>$id, 'legacy'=>$id,
                'ident'=>$native['name'], 'label'=>$native['name'], 'mask'=>$mask, 'bank'=>'derived', 'shared'=>false]);
        }
        return $result;
    }

    /** Persistent Java controls are independent of the temporary SHI request variables. */
    private function SharedNumericControlEnabled(string $ident): bool
    {
        $property=['Anpassung_WW'=>'WWsetVisible','Anpassung_Temp'=>'TempsetVisible','Anpassung_RBE'=>'RBEsetVisible'][$ident]??null;
        return $property!==null && $this->ReadPropertyBoolean($property);
    }

    private function HandleSHIAction(string $ident,mixed $value): void
    {
        $this->SetSHIValue($ident,$value);
    }

    private function SHICache(): array
    {
        $cache = json_decode($this->GetBuffer('SHICache'), true);
        return is_array($cache) && ($cache['source'] ?? '') === $this->VisualizationSourceKey() ? $cache : [];
    }

    private function PendingSHIWrite(): ?array
    {
        $pending=json_decode($this->GetBuffer('PendingSHIWrite'),true)??[];
        if(($pending['source']??'')!==$this->VisualizationSourceKey() || time()-($pending['startedAt']??0)>60) return null;
        return $pending;
    }

    private function NewSHIClient(): LuxtronikModbusClient
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

    private function ReadSHIPoint(LuxtronikModbusClient $client, array $point): int|float|bool|null
    {
        $words = match ($point['bank']) {
            'discrete'=>$client->readDiscreteInputs($point['address'],1),
            'holding'=>$client->readHoldingRegisters($point['address'],1),
            default=>$client->readInputRegisters($point['address'],str_ends_with($point['datatype'],'32')?2:1)
        };
        $this->MarkInterfaceSupported('modbus');
        return $this->DecodeModbusValue($words,$point['datatype'],(float)$point['factor']);
    }

    private function JavaHasMeasurement(string $ident): bool
    {
        $availability=json_decode($this->GetBuffer('JavaAvailability'),true)??[];
        if(($availability['source']??'')===$this->VisualizationSourceKey()) {
            if(!empty($availability['failed'])) return false;
            foreach(self::DATA_POINT_CONFIG as $id=>$point) if($point['name']===$ident) return $id<($availability['count']??0);
            if(preg_match('/^ID_(\d+)$/D',$ident,$match)) return (int)$match[1]<($availability['count']??0);
            return false;
        }
        return $this->GetCachedVisualizationPoint($ident)!==null;
    }

    /** Java and WebSocket remain preferred; Modbus supplies additional needed values. */
    private function UpdateModbusSupplement(bool $preferredOnly=false, ?float $cycleStarted=null): void
    {
        if(!$this->ReadPropertyBoolean('InstanceStatus') || $this->InterfaceSupport('modbus')===false) return;
        $cycleStarted??=microtime(true);
        $writes=$this->SHIWritingEnabled();
        $needed=$this->HybridMeasurementIDs()+$this->GetVisualizationExtraPointIDs();
        foreach($this->MeasurementRows() as $row) if(!empty($row['enabled'])) $needed[(int)$row['id']]=true;
        if($writes) foreach($this->SHICatalog() as $point) if($point['bank']==='holding' && $this->SHIControlEnabled($point)) $needed[$point['selection']]=true;
        $requested=[];
        $catalog=$this->SHICatalog();
        foreach($this->SHISelections() as $id=>$point) {
            if(!isset($needed[$id])) continue;
            if($preferredOnly && ($point['bank']==='holding' || ($this->MeasurementSourceOrder($id)[0]??'')!=='modbus')) continue;
            if($point['bank']==='holding') {
                if(!$writes || !$this->SHIControlEnabled($point)) continue;
            } elseif(!$this->ShouldReadMeasurement($id,'modbus')) continue;
            $requested[$point['key']]=$catalog[$point['key']];
            if($point['bank']==='holding' && $point['di']!==null) $requested['discrete:'.$point['di']]=$catalog['discrete:'.$point['di']];
        }
        $written=json_decode($this->GetBuffer('SHIWrittenKeys'),true)??[];
        if(!$preferredOnly && $writes && ($written['source']??'')===$this->VisualizationSourceKey()) foreach($written['keys'] as $key)
            if(isset($catalog[$key])) $requested[$key]=$catalog[$key];
        if($preferredOnly && $requested===[]) return;
        $keys=array_keys($requested);
        $source=$this->VisualizationSourceKey();
        $previous=$this->SHICache();
        if(!empty($previous['error']) && microtime(true)<($previous['nextAttempt']??0)) {
            $previous['points']=array_intersect_key($previous['points']??[],$requested);
            $this->SetBuffer('SHICache',json_encode($previous,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            if(!$preferredOnly) $this->PublishModbusSupplement();
            return;
        }
        $lock='WPLUX.SHI.'.$this->InstanceID;
        if(!IPS_SemaphoreEnter($lock,1)) return;
        $client=null;
        $queried=false;
        $cache=['source'=>$source,'updatedAt'=>time(),'stale'=>false,'points'=>[],'requestedKeys'=>$keys,'supplement'=>true,'error'=>null];
        if($preferredOnly && empty($previous['stale'])) $cache['points']=$previous['points']??[];
        try {
            try {
                foreach($requested as $key=>$point) {
                    $old=$previous['points'][$key]??null;
                    if(empty($previous['stale']) && $old!==null && ($old['readAt']??0)>=$cycleStarted) {
                        $cache['points'][$key]=$old;continue;
                    }
                    $queried=true;$client??=$this->NewSHIClient();
                    try {$value=$this->ReadSHIPoint($client,$point);}
                    catch(LuxtronikModbusException $error) {
                        if(!in_array($error->getCode(),[1,2,3],true)) throw $error;
                        $value=null;
                    }
                    if($value===null) continue;
                    $cache['points'][$key]=['value'=>$value,'readAt'=>microtime(true),
                        'changedAt'=>$old!==null && $old['value']!==$value?microtime(true):($old['changedAt']??0)];
                }
            } catch(Throwable $error) {
                if($error instanceof LuxtronikInstanceUnavailable) throw $error;
                $cache['points']=[];
                $cache['stale']=true;
                $cache['error']=$error->getMessage();
                $this->SendDebug('Modbus-Ergänzung',$cache['error'],0);
            }
            if(!$queried && $previous!==[]) $cache['updatedAt']=$previous['updatedAt'];
            $cache['nextAttempt']=$cache['error']!==null?microtime(true)+120:0;
            if($source!==$this->VisualizationSourceKey()) return;
            $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            if(!$preferredOnly) $this->PublishModbusSupplement();
        } finally {
            $client?->close();
            IPS_SemaphoreLeave($lock);
        }
    }

    private function PublishModbusSupplement(): void
    {
        $this->PublishMeasurements();
        $this->SyncSHIControlVariables();
        $this->UpdateFormField('SHIWarmWaterStatus','caption',$this->SHIWarmWaterStatus());
        $this->RefreshDerivedMeasurements();
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
        $ids[18]=true; // Effective DHW target is always needed for native control feedback.
        $ids[257]=true;$ids[268]=true;
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
        $id=$this->MeasurementIDForIdent($ident);
        if($id!==null) return $this->MeasurementReading($id);
        return $this->UsesModbusWrite($ident)?$this->GetSHIVisualizationPoint($ident):null;
    }

    private function TraceHybridSources(): void
    {
        $idents=[];
        foreach (self::SHI_REGISTERS as $definition) $idents[$definition['ident']]=true;
        foreach ($this->HybridMeasurementIDs() as $id=>$_) $idents[$this->GetDataPointConfig((int)$id)['name']]=true;
        foreach ($idents as $ident=>$_) {
            $point=$this->GetHybridVisualizationPoint($ident);
            $description=$point===null?'keine aktuelle Quelle; vorhandener Objektwert bleibt unverändert'
                :$point['source'].' ('.match($point['source']) {'Modbus'=>$point['protocolRegister'],'WebSocket'=>$point['webId'],default=>'3004, ID '.$point['protocolId']}.'): '.$point['formatted'];
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
        if(!empty($cache['supplement']) && time()-($cache['updatedAt']??0)>max(30,2*$this->ReadPropertyInteger('UpdateInterval')+5)) return null;
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

    private function SHIVariableLabel(array $point): string
    {
        return $point['bank']==='holding'?'SHI: '.preg_replace('/^SHI[- :]+/u','',$point['label']):$point['label'];
    }

    private function EnsureSHIVariable(array $point): void
    {
        $ident=$point['ident'];
        $label=$this->SHIVariableLabel($point);
        $existing=@$this->GetIDForIdent($ident);
        if ($existing!==false) {
            $name=IPS_GetName($existing);
            if($point['bank']==='holding' && $name!==$label && preg_match('/^SHI[- :]+/u',$name)
                && preg_replace('/^SHI[- :]+/u','',$name)===substr($label,5)) IPS_SetName($existing,$label);
            if($point['legacy']===null && $point['factor']<1 && IPS_GetVariable($existing)['VariableType']===VARIABLETYPE_FLOAT)
                $this->EnsureSHIFloatPresentation($point,$existing);
            return;
        }
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
            foreach ($point['options']??[] as $value=>$optionLabel) IPS_SetVariableProfileAssociation($profile,(float)$value,$optionLabel,'',-1);
        }
        $method=match ($type) {0=>'RegisterVariableBoolean',1=>'RegisterVariableInteger',default=>'RegisterVariableFloat'};
        $this->$method($ident,$label,$profile,$point['selection']);
        if($type===VARIABLETYPE_FLOAT) $this->EnsureSHIFloatPresentation($point,$this->GetIDForIdent($ident));
    }

    /** Keep fractional SHI values editable on existing objects and modern Symcon sliders. */
    private function EnsureSHIFloatPresentation(array $point,int $id): void
    {
        $profile='WPLUX.SHI.'.strtoupper(substr($point['bank'],0,1)).$point['address'];
        $step=(float)$point['factor'];
        $digits=(int)ceil(-log10($step));
        if(IPS_VariableProfileExists($profile)) {
            $definition=IPS_GetVariableProfile($profile);
            if(($definition['ProfileType']??null)===VARIABLETYPE_FLOAT) {
                if((float)($definition['StepSize']??1)!==$step)
                    IPS_SetVariableProfileValues($profile,(float)($definition['MinValue']??$point['min']??0),
                        (float)($definition['MaxValue']??$point['max']??0),$step);
                if((int)($definition['Digits']??0)<$digits) IPS_SetVariableProfileDigits($profile,$digits);
            }
        }
        if($point['bank']!=='holding' || !defined('VARIABLE_PRESENTATION_SLIDER')) return;
        $variable=IPS_GetVariable($id);
        $default=$variable['VariablePresentation']??[];
        $slider=constant('VARIABLE_PRESENTATION_SLIDER');
        // Update the module's default only; keep names, IDs, actions and custom profiles.
        if(($variable['VariableProfile']??'')===$profile || ($default['PROFILE']??'')===$profile
            || ($default['PRESENTATION']??'')===$slider) {
            $presentation=['PRESENTATION'=>$slider,'MIN'=>(float)$point['min'],'MAX'=>(float)$point['max'],
                'STEP_SIZE'=>$step,'DIGITS'=>$digits,'SUFFIX'=>' '.$point['unit'],'PERCENTAGE'=>false];
            if(array_intersect_key($default,$presentation)!=$presentation)
                $this->RegisterVariableFloat($point['ident'],$this->SHIVariableLabel($point),$presentation,$point['selection']);
        }
        // A converted slider can override its default with the old whole-number step.
        $custom=$variable['VariableCustomPresentation']??[];
        if(($custom['PRESENTATION']??'')===$slider && function_exists('IPS_SetVariableCustomPresentation')) {
            $effective=IPS_GetVariablePresentation($id);
            $updated=$custom;
            if((float)($effective['STEP_SIZE']??1)>=1) $updated['STEP_SIZE']=$step;
            if((int)($effective['DIGITS']??0)<$digits) $updated['DIGITS']=$digits;
            if($updated!==$custom) {
                // Explicit parameters replace templates; retain their resolved bounds/style.
                if(isset($custom['TEMPLATE'])) {$updated=array_replace($effective,$updated);unset($updated['TEMPLATE']);}
                IPS_SetVariableCustomPresentation($id,$updated);
            }
        }
    }

    private function PublishSHIValues(): void
    {
        $this->PublishMeasurements();
        $this->SyncSHIControlVariables();
        $this->UpdateFormField('SHIWarmWaterStatus','caption',$this->SHIWarmWaterStatus());
        $this->RefreshDerivedMeasurements();
    }

    /** Snapshot only: no network activity or object creation. */
    public function GetSHIData(): string
    {
        $cache=$this->SHICache();
        $pending=$this->PendingSHIWrite();
        $values=[];
        foreach ($this->SHICatalog() as $key=>$point) {
            $entry=$cache['points'][$key]??null;
            $current=$entry!==null && empty($cache['stale']);
            $values[]=['bank'=>$point['bank'],'address'=>$point['address'],'ident'=>$point['ident'],'name'=>$point['label'],
                'value'=>$entry['value']??null,'unit'=>$point['unit'],'available'=>$current,'writable'=>$point['bank']==='holding',
                'source'=>$current?'Modbus':null,'pending'=>($pending['ident']??null)===$point['ident'],
                'requestedValue'=>($pending['ident']??null)===$point['ident']?$pending['value']:null];
        }
        $java=[];
        foreach ($this->VisualizationCachedPoints() as $ident=>$entry) {
            $java[]=['ident'=>$ident,'id'=>$entry['id'],'value'=>$entry['value'],'source'=>'Java'];
        }
        $preferences=[];
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) if(isset($row['preferredSource']))
            $preferences[(int)$row['id']]=$this->MeasurementSourceOrder((int)$row['id']);
        return json_encode(['transport'=>'Modbus TCP (Direktverbindung)','gatewayID'=>0,'updatedAt'=>$cache['updatedAt']??null,'stale'=>$cache['stale']??true,'values'=>$values,
            'javaSupplementEnabled'=>true,'javaSupplement'=>$java,'measurementPriority'=>['Java','WebSocket','Modbus/SHI'],
            'measurementPreferences'=>$preferences,'javaOnlyWritesRemainAvailable'=>true,
            'nativeControlSource'=>'Java','shiControlsEnabled'=>$this->SHIWritingEnabled(),
            'writeSource'=>$this->SHIWritingEnabled()?'Modbus/SHI':'SHI-Bedienung aus'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION);
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

    private function ConfirmSHIWrite(LuxtronikModbusClient $client,int $address,int $word,?float $waitSeconds=null): void
    {
        $client->writeHoldingRegisters($address,[$word]);
        // Some controllers commit the DHW group on a delayed control cycle.
        // Only read while waiting: repeating the write can cancel a pending value.
        $deadline=microtime(true)+($waitSeconds??(in_array($address,[10005,10006,10007,10008],true)?15.0:1.5));
        $actual=[];
        do {
            $actual=$client->readHoldingRegisters($address,1);
            if ($actual===[$word]) return;
            if (microtime(true)>=$deadline) break;
            usleep(100000);
        } while (true);
        $point=$this->SHICatalog()['holding:'.$address];
        $wanted=$this->SHIFormatted($point,$this->DecodeModbusValue([$word],$point['datatype'],(float)$point['factor']));
        $value=$this->DecodeModbusValue($actual,$point['datatype'],(float)$point['factor']);
        $received=$value===null?'nicht verfügbar':$this->SHIFormatted($point,$value);
        throw new RuntimeException('SHI-Schreibwert '.$address.' wurde beim Rücklesen nicht bestätigt. Gesendet: '.$wanted
            .'; zurückgelesen: '.$received.'.');
    }

    /** Only documented holding registers, validated before connecting; never replayed by polling. */
    public function SetSHIValue(string $Ident,mixed $Value): bool
    {
        $Ident=['Anpassung_Temp'=>'SHI_Offset_Heizen','Anpassung_WW'=>'SHI_Sollwert_Warmwasser','Anpassung_RBE'=>'SHI_Raumtemperatur_Soll'][$Ident]??$Ident;
        if (!$this->SHIWritingEnabled() || !$this->ReadPropertyBoolean('InstanceStatus')) throw new RuntimeException('SHI-Schreiben benötigt eine aktive Instanz und eine ausgewählte SHI-Steuervariable.');
        if($this->InterfaceSupport('modbus')===false) throw new RuntimeException('Die erkannte Firmware unterstützt Modbus/SHI laut Versionsvoraussetzung nicht.');
        $point=$this->SHIWriteDefinition($Ident);
        $word=$this->EncodeSHIValue($point,$Value);
        $id=@$this->GetIDForIdent($Ident);
        if ($id!==false && IPS_VariableExists($id) && IPS_GetVariable($id)['VariableType']===1 && (float)$Value!==floor((float)$Value)) throw new InvalidArgumentException('Der Wert passt nicht zum bestehenden ganzzahligen Variablentyp.');
        $lock='WPLUX.SHI.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) throw new RuntimeException('Eine SHI-Abfrage oder Bedienaktion läuft bereits.');
        $client=null;
        $attempted=false;
        $changedModes=[];
        $source=null;
        $optimistic=null;
        try {
            $source=$this->VisualizationSourceKey();
            $client=$this->NewSHIClient();
            $catalog=$this->SHICatalog();
            $deps=$point['deps'];
            if ($word===0 && str_starts_with($point['ident'],'SHI_Modus_')) $deps=[];
            if ($point['di']!==null && $this->ReadSHIPoint($client,$catalog['discrete:'.$point['di']])!==true) {
                $function=$point['di']===10001?'Warmwasser':$catalog['discrete:'.$point['di']]['label'];
                throw new RuntimeException('SHI-Funktion '.$function.' ist am Regler nicht freigegeben (Discrete Input '.$point['di'].'). Bitte die SHI-Freigabe am Regler prüfen. Es wurde kein Wert geschrieben.');
            }
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
            if($id!==false && IPS_VariableExists($id)) {
                $optimistic=['source'=>$source,'ident'=>$Ident,'variableId'=>$id,'startedAt'=>time(),
                    'value'=>$this->DecodeModbusValue([$word],$point['datatype'],(float)$point['factor']),'before'=>$current];
                $this->SetBuffer('PendingSHIWrite',json_encode($optimistic,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
                $this->SetValueIfChanged($Ident,$optimistic['value']);
            }
            // Required modes must already be confirmed when the target is sent.
            $attempted=true;
            foreach ($deps as $address=>$expected) {
                if ($before[$address]!==$expected) {
                    $changedModes[$address]=['before'=>$before[$address],'expected'=>$expected];
                    $this->ConfirmSHIWrite($client,$address,$expected);
                }
            }
            $this->ConfirmSHIWrite($client,$point['address'],$word);
            $confirmed=[];
            foreach ([$point['address'],...array_keys($deps)] as $address) {
                $value=$this->ReadSHIPoint($client,$catalog['holding:'.$address]);
                $expected=$address===$point['address']?$this->DecodeModbusValue([$word],$point['datatype'],(float)$point['factor']):$deps[$address];
                if ($value!==$expected) {
                    $definition=$catalog['holding:'.$address];
                    throw new RuntimeException('SHI-Gesamtvorgabe für Register '.$address.' nicht bestätigt. Gesendet: '
                        .$this->SHIFormatted($definition,$expected).'; zurückgelesen: '
                        .($value===null?'nicht verfügbar':$this->SHIFormatted($definition,$value)).'.');
                }
                $confirmed['holding:'.$address]=['value'=>$value,'changedAt'=>microtime(true)];
            }
            if($source!==$this->VisualizationSourceKey()) throw new RuntimeException('Die SHI-Geräteverbindung wurde während des Schreibens geändert.');
            $cache=$this->SHICache();
            $cache['source']=$this->VisualizationSourceKey();
            // Writing cannot make an earlier failed/full scan current again.
            $cache['stale']=$cache['stale']??true;
            $cache['points']=array_replace($cache['points']??[],$confirmed);
            $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            $writtenKeys=json_decode($this->GetBuffer('SHIWrittenKeys'),true)??[];
            $keys=($writtenKeys['source']??'')===$this->VisualizationSourceKey()?($writtenKeys['keys']??[]):[];
            $this->SetBuffer('SHIWrittenKeys',json_encode(['source'=>$this->VisualizationSourceKey(),
                'keys'=>array_values(array_unique([...$keys,...array_keys($confirmed)]))],JSON_THROW_ON_ERROR));
            $this->SetBuffer('PendingSHIWrite','');
            $this->PublishSHIValues();
            $this->SendDebug('Datenquelle',$point['ident'].': Schreiben über Modbus (holding:'.$point['address'].') bestätigt: '.$this->SHIFormatted($point,$Value),0);
            return true;
        } catch (Throwable $error) {
            if ($attempted) {
                // Restore only modes changed by this call, while they still match our write.
                // Use a fresh connection after a possibly malformed or interrupted reply.
                $client?->close();$client=null;
                if($changedModes!==[] && $source===$this->VisualizationSourceKey()) {
                    $recovery=null;
                    try {
                        $recovery=$this->NewSHIClient();
                        foreach(array_reverse($changedModes,true) as $address=>$mode) {
                            $current=$this->ReadSHIPoint($recovery,$catalog['holding:'.$address]);
                            // A DHW mode may still be pending while the old value is read.
                            // Writing that old value also cancels/replaces the pending mode.
                            if($current===$mode['before'] && $address!==10005) continue;
                            if($current!==$mode['expected'] && $current!==$mode['before']) throw new RuntimeException('Modus '.$address.' wurde zwischenzeitlich geändert; keine Rücknahme.');
                            $restore=$this->EncodeSHIValue($catalog['holding:'.$address],$mode['before']);
                            $this->ConfirmSHIWrite($recovery,$address,$restore,1.5);
                        }
                    } catch(Throwable $recoveryError) {
                        if($recoveryError instanceof LuxtronikInstanceUnavailable) throw $recoveryError;
                        $this->LogMessage('SHI: Vorherige Modi konnten nicht vollständig wiederhergestellt werden. '.$recoveryError->getMessage(),KL_ERROR);
                    } finally {$recovery?->close();}
                }
                $cache=$this->SHICache();$cache['stale']=true;
                $this->SetBuffer('SHICache',json_encode($cache,JSON_THROW_ON_ERROR));
                $this->DisableSHIActions();
                $this->SetStatus(201);
                $this->LogMessage('SHI: Vorgabe möglicherweise teilweise übernommen; Zustand erneut abfragen. '.$error->getMessage(),KL_ERROR);
            }
            if($optimistic!==null && $source===$this->VisualizationSourceKey()
                && @$this->GetIDForIdent($Ident)===$optimistic['variableId'] && IPS_VariableExists($optimistic['variableId']))
                $this->SetValueIfChanged($Ident,$optimistic['before']);
            throw $error;
        } finally {
            $client?->close();
            $this->SetBuffer('PendingSHIWrite','');
            IPS_SemaphoreLeave($lock);
            $this->RefreshVisualization();
        }
    }

    private function SHIControlPermission(array $point): ?bool
    {
        if($point['di']===null) return true;
        return $this->GetSHIVisualizationPoint($this->SHICatalog()['discrete:'.$point['di']]['ident'])['value']??null;
    }

    private function SHIWarmWaterStatus(): string
    {
        $permission=$this->GetSHIVisualizationPoint('SHI_Konfig_Warmwasser');
        return 'SHI-Warmwasser (Discrete Input 10001): '.($permission===null?'keine aktuelle Freigaberückmeldung'
            : ($permission['value']===true?'freigegeben':'nicht freigegeben – SHI-Einstellung am Regler prüfen'));
    }

    private function SHIControlForm(): array
    {
        $selected=$this->SHIControlSelection();$rows=[];
        $writes=$this->SHIWritingEnabled();
        $pending=$this->PendingSHIWrite();
        foreach($this->SHICatalog() as $point) {
            if($point['bank']!=='holding') continue;
            $reading=$writes?$this->GetSHIVisualizationPoint($point['ident']):null;
            $activation=[];
            $variableID=@$this->GetIDForIdent($point['ident']);
            if($variableID!==false && IPS_VariableExists($variableID)) {
                $activation[]='Vorhandene Variable: '.IPS_GetName($variableID).' (ID '.$variableID.')';
            }
            $permission=$this->SHIControlPermission($point);
            $activation[]=$permission===false?'Am Regler nicht freigegeben':($permission===null?'Freigabe noch nicht gelesen':'Am Regler freigegeben');
            if(!$writes) $activation[]='SHI-Bedienung ausgeschaltet';
            if($point['deps']!==[]) $activation[]='Benötigt '.implode(', ',array_map(static fn($address,$value)=>'HR '.$address.' = '.$value,array_keys($point['deps']),array_values($point['deps'])));
            $status='';
            if(!empty($selected[$point['selection']])) {
                $status=!$this->ReadPropertyBoolean('InstanceStatus')?'Modul ausgeschaltet':($reading===null?'Keine aktuellen Daten':($permission===false?'Am Regler nicht freigegeben':($permission===null?'Freigabe noch ungeprüft':'Bereit')));
            }
            $waiting=($pending['ident']??null)===$point['ident'];
            if($waiting) $status='Bestätigung läuft';
            $rows[]=['enabled'=>$selected[$point['selection']]??false,'id'=>$point['selection'],
                'name'=>preg_replace('/^SHI[- :]+/u','',$point['label']),'register'=>$point['address'],
                'range'=>$point['min'].' … '.$point['max'].($point['unit']!==''?' '.$point['unit']:''),
                'activation'=>implode('; ',$activation),'status'=>$status,
                'value'=>$waiting?$this->SHIFormatted($point,$pending['value']):($reading['formatted']??'Keine aktuellen Modbus-Daten')];
        }
        return ['type'=>'List','name'=>'SHIControlList','caption'=>'Modbus-Steuervariablen','rowCount'=>10,
            'add'=>false,'delete'=>false,'loadValuesFromConfiguration'=>false,
            'sort'=>['column'=>'id','direction'=>'ascending'],
            'columns'=>[
                ['name'=>'enabled','caption'=>'Variable anlegen','width'=>'130px','save'=>true,'edit'=>['type'=>'CheckBox']],
                ['name'=>'id','caption'=>'ID','width'=>'110px','save'=>true],
                ['name'=>'name','caption'=>'Funktion','width'=>'auto','save'=>false,'quickFilter'=>true],
                ['name'=>'register','caption'=>'SHI-Register','width'=>'110px','save'=>false],
                ['name'=>'range','caption'=>'Bereich / Einheit','width'=>'150px','save'=>false],
                ['name'=>'activation','caption'=>'Details','width'=>'240px','save'=>false,'visible'=>false],
                ['name'=>'value','caption'=>'Aktueller Wert','width'=>'150px','save'=>false],
                ['name'=>'status','caption'=>'Status','width'=>'190px','save'=>false]
            ],'values'=>$rows];
    }

    private function AddModbusForm(array $form): array
    {
        $panel=['type'=>'ExpansionPanel','caption'=>'Modbus TCP / SHI','items'=>[
            ['type'=>'Label','caption'=>'Luxtronik 2.1 ab V3.90.1 mit aktivem SHI. Die Lesequelle wird je Messwert gewählt; Java ist Standard, andere Quellen ergänzen fehlende Werte.'],
            ['type'=>'Select','name'=>'CommunicationMode','caption'=>'Schreibweg für unterstützte Befehle',
                'value'=>$this->ReadPropertyString('CommunicationMode'),
                'options'=>[['caption'=>'Java','value'=>'java'],['caption'=>'Modbus/SHI','value'=>'modbus']],
                'onChange'=>'WPLUX_RefreshWriteSourceLabels($id, $CommunicationMode === "modbus");'],
            ['type'=>'Label','caption'=>'Gemeinsame Sollwerte verwenden dieselben Variablen. Betriebsarten und Zeitprogramme ohne gleichbedeutenden Modbus-Befehl bleiben über Java bedienbar. Reine Modbus-Steuervariablen sind bei gewähltem Java-Schreibweg nicht bedienbar.'],
            ['type'=>'NumberSpinner','name'=>'ModbusPort','caption'=>'Modbus-Port'],
            ['type'=>'NumberSpinner','name'=>'ModbusUnitID','caption'=>'Modbus-Geräteadresse (Unit-ID)'],
            ['type'=>'NumberSpinner','name'=>'ModbusTimeout','caption'=>'Modbus-Timeout (Millisekunden, 100–5000)'],
            ['type'=>'Label','name'=>'SHIWarmWaterStatus','visible'=>false,'caption'=>$this->SHIWarmWaterStatus()],
            ['type'=>'ExpansionPanel','caption'=>'Modbus-Steuervariablen','items'=>[
                ['type'=>'Label','caption'=>'Auswahl legt Variablen an. Erst eine Bedienaktion sendet einen Wert; Auswahl und Aktivierung senden keine Sollwerte. WW-Soll, Heizkorrektur und Raumtemperatur-Soll können auch unter Timer / Zusatzfunktionen aktiviert sein und werden nicht doppelt angelegt.'],
                $this->SHIControlForm()]],
            ['type'=>'ExpansionPanel','caption'=>'Weitere lesbare Modbus-Datenpunkte','items'=>[
                ['type'=>'CheckBox','name'=>'ShowAdvancedModbusPoints','caption'=>'Weitere lesbare Status- und Konfigurationswerte in der ID-Liste anzeigen'],
                ['type'=>'Label','caption'=>'Bereits ausgewählte lesbare Einträge bleiben sichtbar. Schreibfunktionen stehen ausschließlich in der separaten Liste Modbus-Steuervariablen.']]]
        ]];
        $index=count($form['elements']);
        foreach($form['elements'] as $i=>$element) if(($element['name']??'')==='IDListe') {$index=$i;break;}
        array_splice($form['elements'],$index,0,[$panel]);
        $selections=$this->SHISelections();$saved=[];
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) if(isset($row['id'])) $saved[(int)$row['id']]=$row;
        foreach($form['elements'] as &$element) {
            if(($element['name']??'')!=='IDListe') continue;
            $element['caption']='Mess- und Statuswerte (nur lesend)';
            $element['values']=array_values(array_filter($element['values'],static fn(array $r):bool=>(int)$r['id']<100000));
            foreach($element['columns'] as &$column) if(($column['name']??'')==='id') unset($column['edit']);
            unset($column);
            $element['columns'][]=['name'=>'interface','caption'=>'Verfügbar über','width'=>'230px','save'=>false];
            foreach($element['values'] as &$row) $row['interface']=isset($selections[(int)$row['id']])?'Java + Modbus':'Java';
            unset($row);
            foreach($selections as $id=>$point) if($id>=100000 && $point['bank']!=='holding'
                && (empty($point['aliasOf']) || !empty($saved[$id]['enabled'])))
                $element['values'][]=['enabled'=>(bool)($saved[$id]['enabled']??false),'id'=>$id,'name'=>$point['label'],'interface'=>'Modbus'];
        }
        unset($element);
        $form['status']=[
            ['code'=>102,'icon'=>'active','caption'=>'Instanz aktiv'],['code'=>104,'icon'=>'inactive','caption'=>'Instanz deaktiviert'],
            ['code'=>201,'icon'=>'error','caption'=>'SHI-Verbindung, Antwort oder Schreibbestätigung fehlerhaft'],
            ['code'=>202,'icon'=>'error','caption'=>'SHI-Verbindungseinstellungen ungültig'],
            ['code'=>203,'icon'=>'error','caption'=>'Keine verfügbaren SHI-Messwerte geliefert'],
            ['code'=>204,'icon'=>'error','caption'=>'Messwerte teilweise verfügbar (Modbus/Java)']];
        return $form;
    }
}
