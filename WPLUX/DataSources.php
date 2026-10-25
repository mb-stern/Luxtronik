<?php

/** Reading preferences never choose a write transport or create a second measurement object. */
trait LuxtronikDataSources
{
    private function MeasurementSources(int $id): array
    {
        $sources=[];
        if($id>=0 && $id<self::MAX_RESPONSE_VALUES) $sources[]='java';
        if(isset($this->WebDefinitions()[$id])) $sources[]='websocket';
        $shi=$this->SHISelections()[$id]??null;
        if($shi!==null && $shi['bank']!=='holding') $sources[]='modbus';
        return $sources;
    }

    private function MeasurementSourceOrder(int $id): array
    {
        $available=$this->MeasurementSources($id);
        $preferred='java';
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row)
            if((int)($row['id']??0)===$id) {$preferred=$row['preferredSource']??'java';break;}
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
        // A preferred Modbus value must be attempted before deciding whether WebSocket is needed.
        $needed=$this->HybridMeasurementIDs()+$this->GetVisualizationExtraPointIDs();
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row)
            if(!empty($row['enabled'])) $needed[(int)$row['id']]=true;
        foreach($needed as $id=>$_) if(($this->MeasurementSourceOrder($id)[0]??'')==='modbus') {
            $this->UpdateModbusSupplement(true);break;
        }
        $this->UpdateWebEEV();
        $this->UpdateModbusSupplement();
        $this->PublishMeasurements();
        foreach($needed as $id=>$_) {
            $reading=$this->MeasurementReading($id);
            if($reading!==null) $this->SendDebug('Datenquelle',$reading['ident'].': '.$reading['source'].' = '.$reading['formatted'],0);
        }
    }

    private function PublishMeasurements(): void
    {
        if(!$this->ReadPropertyBoolean('InstanceStatus')) return;
        $shi=$this->SHISelections();$web=$this->WebDefinitions();
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) {
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

    private function SHIControlEnabled(array $point): bool
    {
        return !empty($point['shared']) ? $this->SharedNumericControlEnabled($point['ident'])
            : ($this->SHIControlSelection()[$point['selection']]??false);
    }

    private function SyncSHIControlVariables(bool $removeUnselected=false): void
    {
        $writes=$this->ReadPropertyString('CommunicationMode')==='modbus' && $this->ReadPropertyBoolean('InstanceStatus');
        foreach($this->SHICatalog() as $point) {
            if($point['bank']!=='holding') continue;
            if(!$this->SHIControlEnabled($point)) {
                if(empty($point['shared']) && $removeUnselected) $this->DeleteVariableIfExists($point['ident']);
                elseif(empty($point['shared']) && @$this->GetIDForIdent($point['ident'])!==false) $this->DisableAction($point['ident']);
                continue;
            }
            $this->EnsureSHIVariable($point);
            $reading=$writes?$this->GetSHIVisualizationPoint($point['ident']):null;
            if($reading!==null) {
                $this->SetValueIfChanged($point['ident'],$reading['value']);
                $this->EnableAction($point['ident']);
            } elseif($writes || empty($point['shared'])) $this->DisableAction($point['ident']);
        }
    }

    /** All Java-only actions keep their original transport when Modbus is preferred. */
    private function UsesModbusWrite(string $ident): bool
    {
        if($this->ReadPropertyString('CommunicationMode')!=='modbus') return false;
        foreach(self::SHI_REGISTERS as $point) if($point['bank']==='holding' && $point['ident']===$ident) return true;
        return false;
    }
}
