<?php

/** Reading preferences never choose a write transport or create a second measurement object. */
trait LuxtronikDataSources
{
    /** Apply the displayed measurement list explicitly; never changes a write setting. */
    public function ApplyMeasurementSource(string $Source, string $Values): void
    {
        if(!in_array($Source,['java','websocket','modbus'],true)) throw new InvalidArgumentException('Unbekannte Lesequelle.');
        $displayed=json_decode($Values,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($displayed) || !array_is_list($displayed) || !str_starts_with(ltrim($Values),'[')) throw new InvalidArgumentException('Ungültige Messwertliste.');
        $rows=[];$selections=$this->SHISelections();
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) $rows[(int)$row['id']]=$row;
        foreach($displayed as $row) {
            if(!is_array($row) || !isset($row['id']) || !is_numeric($row['id']) || (int)$row['id']<=0
                || (float)$row['id']!==(float)(int)$row['id'] || !isset($row['enabled']) || !is_bool($row['enabled'])) {
                throw new InvalidArgumentException('Ungültiger Messwerteintrag.');
            }
            $id=(int)$row['id'];
            if(($selections[$id]['bank']??'')==='holding') continue;
            $rows[$id]=array_replace($rows[$id]??[],array_intersect_key($row,array_flip(['id','enabled','preferredSource'])));
            $rows[$id]['id']=$id;
        }
        $ids=array_keys(self::DATA_POINT_CONFIG+$this->WebDefinitions()+$selections+$rows);
        $changed=0;
        foreach($ids as $id) {
            if($id<10 || !in_array($Source,$this->MeasurementSources($id),true)) continue;
            $row=$rows[$id]??['id'=>$id,'enabled'=>false];
            if(($row['preferredSource']??'java')!==$Source) ++$changed;
            $row['preferredSource']=$Source;$rows[$id]=$row;
        }
        ksort($rows,SORT_NUMERIC);
        IPS_SetProperty($this->InstanceID,'MeasurementBulkSource',$Source);
        IPS_SetProperty($this->InstanceID,'IDListe',json_encode(array_values($rows),JSON_THROW_ON_ERROR));
        IPS_ApplyChanges($this->InstanceID);
        foreach(json_decode($this->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR)['elements'] as $element) {
            if(($element['name']??'')==='IDListe') $this->UpdateFormField('IDListe','values',json_encode($element['values'],JSON_THROW_ON_ERROR));
        }
        $this->UpdateFormField('MeasurementBulkResult','caption',$changed.' Messwerte auf '.['java'=>'Java','websocket'=>'WebSocket','modbus'=>'Modbus/SHI'][$Source].' umgestellt.');
    }

    private function MeasurementSources(int $id): array
    {
        $sources=[];
        if($id>=0 && $id<self::MAX_RESPONSE_VALUES) $sources[]='java';
        if(isset($this->WebDefinitions()[$id]) && $this->InterfaceSupport('websocket')!==false) $sources[]='websocket';
        $shi=$this->SHISelections()[$id]??null;
        if($shi!==null && $shi['bank']!=='holding' && $this->InterfaceSupport('modbus')!==false) $sources[]='modbus';
        return $sources;
    }

    private function MeasurementSourceOrder(int $id): array
    {
        $available=$this->MeasurementSources($id);
        $preferred=$this->ReadPropertyString('MeasurementBulkSource');
        foreach($this->MeasurementRows() as $row)
            if((int)($row['id']??0)===$id) {$preferred=$row['preferredSource']??$preferred;break;}
        if(!in_array($preferred,$available,true)) $preferred=$available[0]??'java';
        return array_values(array_unique([$preferred,...$available]));
    }

    private function MeasurementIDForIdent(string $ident): ?int
    {
        foreach(self::DATA_POINT_CONFIG as $id=>$point) if($point['name']===$ident) return $id;
        foreach($this->WebDefinitions() as $id=>$point) if($point['ident']===$ident) return $id;
        foreach($this->SHISelections() as $id=>$point) if($point['ident']===$ident && $point['bank']!=='holding') return $id;
        if(preg_match('/^ID_(\d+)$/D',$ident,$match) && (int)$match[1]<self::MAX_RESPONSE_VALUES) return (int)$match[1];
        return null;
    }

    private function MeasurementIdent(int $id): ?string
    {
        if($id>=0 && $id<self::MAX_RESPONSE_VALUES) return $this->GetDataPointConfig($id)['name'];
        return $this->WebDefinitions()[$id]['ident']??$this->SHISelections()[$id]['ident']??null;
    }

    private function MeasurementFromSource(string $ident,string $source): ?array
    {
        return match($source) {
            'java'=>$this->GetCachedVisualizationPoint($ident),
            'websocket'=>$this->GetWebEEVPoint($ident),
            'modbus'=>$this->GetSHIVisualizationPoint($ident),
            default=>null
        };
    }

    private function MeasurementReading(int $id): ?array
    {
        $ident=$this->MeasurementIdent($id);
        if($ident===null) return null;
        foreach($this->MeasurementSourceOrder($id) as $source) {
            $reading=$this->MeasurementFromSource($ident,$source);
            if($reading!==null) return $reading;
        }
        return null;
    }

    /** Fetch a source only when no preferred source currently supplies this measurement. */
    private function ShouldReadMeasurement(int $id,string $source): bool
    {
        $ident=$this->MeasurementIdent($id);
        if($ident===null) return false;
        foreach($this->MeasurementSourceOrder($id) as $candidate) {
            if($candidate===$source) return true;
            if($candidate==='java' ? $this->JavaHasMeasurement($ident) : $this->MeasurementFromSource($ident,$candidate)!==null) return false;
        }
        return false;
    }

    private function UpdateMeasurementSupplements(): void
    {
        $cycleStarted=microtime(true);
        // A preferred Modbus value must be attempted before deciding whether WebSocket is needed.
        $needed=$this->HybridMeasurementIDs()+$this->GetVisualizationExtraPointIDs();
        foreach($this->MeasurementRows() as $row)
            if(!empty($row['enabled'])) $needed[(int)$row['id']]=true;
        foreach($needed as $id=>$_) if(($this->MeasurementSourceOrder($id)[0]??'')==='modbus') {
            $this->UpdateModbusSupplement(true,$cycleStarted);break;
        }
        $this->UpdateWebEEV($cycleStarted);
        $this->ProbeUncertainSHIFirmware();
        $this->UpdateModbusSupplement(false,$cycleStarted);
        $this->PublishMeasurements();
        $this->RefreshDerivedMeasurements();
        foreach($needed as $id=>$_) {
            $reading=$this->MeasurementReading($id);
            if($reading!==null) $this->SendDebug('Datenquelle',$reading['ident'].': '.$reading['source'].' = '.$reading['formatted'],0);
        }
    }

    private function PublishMeasurements(): void
    {
        if(!$this->ReadPropertyBoolean('InstanceStatus')) return;
        $shi=$this->SHISelections();$web=$this->WebDefinitions();
        foreach($this->MeasurementRows() as $row) {
            $id=(int)($row['id']??0);
            if(empty($row['enabled']) || ($shi[$id]['bank']??'')==='holding') continue;
            $reading=$this->MeasurementReading($id);
            if($reading===null) continue;
            $ident=$reading['ident'];
            if($id>=0 && $id<self::MAX_RESPONSE_VALUES) {
                if(@$this->GetIDForIdent($ident)===false) $this->CreateOrUpdateVariable($ident,$reading['value'],$id);
                else $this->SetValueIfChanged($ident,$reading['value']);
            } elseif(isset($shi[$id])) {
                $this->EnsureSHIVariable($shi[$id]);
                $this->SetValueIfChanged($ident,$reading['value']);
            } elseif(isset($web[$id])) {
                $this->EnsureWebVariable($id,$web[$id]);
                $this->SetValueIfChanged($ident,$reading['value']);
            }
            $this->SendDebug('Datenquelle',$ident.': '.$reading['source'].' = '.$reading['formatted'],0);
        }
    }

    private function RefreshDerivedMeasurements(): void
    {
        $power=$this->GetHybridVisualizationPoint('Waermeleistung');
        if($power!==null) $this->calc_cop('cop',(float)$power['value']);
        $heat=$this->GetHybridVisualizationPoint('Waermemengenzaehler_Heizung');
        $water=$this->GetHybridVisualizationPoint('Waermemengenzaehler_Brauchwasser');
        if($heat!==null && $water!==null) $this->calc_jaz('jaz',(float)$heat['value']+(float)$water['value']);
    }

    /** New control list wins; old selections remain usable until the next configuration save. */
    private function SHIControlSelection(): array
    {
        $result=[];$selections=$this->SHISelections();
        foreach(['IDListe','SHIControlList'] as $property)
            foreach(json_decode($this->ReadPropertyString($property),true)??[] as $row) {
                $id=(int)($row['id']??0);
                if(($selections[$id]['bank']??'')==='holding') $result[$id]=!empty($row['enabled']);
            }
        return $result;
    }

    /** Earlier beta versions used native Java Idents for SHI; retain those objects instead of deleting history. */
    private function IsRetainedLegacySHIControl(string $ident): bool
    {
        $selection=['Anpassung_Temp'=>310002,'Anpassung_WW'=>310006,'Anpassung_RBE'=>310060][$ident]??null;
        return $selection!==null && !$this->SharedNumericControlEnabled($ident)
            && ($this->SHIControlSelection()[$selection]??false) && @$this->GetIDForIdent($ident)!==false;
    }

    private function UnregisterNativeNumericControl(string $ident): void
    {
        if($this->IsRetainedLegacySHIControl($ident)) $this->DisableAction($ident);
        else $this->UnregisterVariable($ident);
    }

    private function SHIControlEnabled(array $point): bool
    {
        return $this->SHIControlSelection()[$point['selection']]??false;
    }

    private function SyncSHIControlVariables(bool $removeUnselected=false): void
    {
        $writes=$this->SHIWritingEnabled() && $this->ReadPropertyBoolean('InstanceStatus') && $this->InterfaceSupport('modbus')!==false;
        foreach($this->SHICatalog() as $point) {
            if($point['bank']!=='holding') continue;
            if(!$this->SHIControlEnabled($point)) {
                if($removeUnselected) $this->DeleteVariableIfExists($point['ident']);
                elseif(@$this->GetIDForIdent($point['ident'])!==false) $this->DisableAction($point['ident']);
                continue;
            }
            $this->EnsureSHIVariable($point);
            $reading=$writes?$this->GetSHIVisualizationPoint($point['ident']):null;
            if($reading!==null) {
                $this->SetValueIfChanged($point['ident'],$reading['value']);
                $permission=$this->SHIControlPermission($point);
                if($permission===true) $this->EnableAction($point['ident']);
                else $this->DisableAction($point['ident']);
            } else $this->DisableAction($point['ident']);
        }
    }

    /** Selecting an independent SHI control enables that control, never a native Java write route. */
    private function SHIWritingEnabled(): bool
    {
        return $this->ReadPropertyString('CommunicationMode')==='modbus' || in_array(true,$this->SHIControlSelection(),true);
    }

    private function UsesModbusWrite(string $ident): bool
    {
        if(!$this->SHIWritingEnabled()) return false;
        return $this->SupportsModbusWrite($ident);
    }

    private function SupportsModbusWrite(string $ident): bool
    {
        foreach(self::SHI_REGISTERS as $point) if($point['bank']==='holding' && $point['ident']===$ident) return true;
        return false;
    }
}
