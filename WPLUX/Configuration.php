<?php

/** One measurement list and one source choice; historical selection keys and object Idents are retained. */
trait LuxtronikConfiguration
{
    private function MeasurementRows(): array
    {
        return json_decode($this->ReadPropertyString('IDListe'),true)??[];
    }

    private function SyncSHIReadVariables(): void
    {
        $selections=$this->SHISelections();
        foreach($this->MeasurementRows() as $row) {
            $point=$selections[(int)($row['id']??0)]??null;
            if(!empty($row['enabled']) && $point!==null && $point['selection']>=100000 && $point['bank']!=='holding') $this->EnsureSHIVariable($point);
        }
    }

    /** Preview only; the normal Symcon Apply button saves the changed source and rows. */
    public function PreviewMeasurementSource(string $Source,string $Values): void
    {
        if(!in_array($Source,['java','websocket','modbus'],true)) throw new InvalidArgumentException('Unbekannte Lesequelle.');
        $rows=json_decode($Values,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($rows) || !array_is_list($rows)) throw new InvalidArgumentException('Ungültige Messwertliste.');
        foreach($rows as &$row) {
            $id=(int)($row['id']??0);
            if(in_array($Source,$this->MeasurementSources($id),true)) $row['preferredSource']=$Source;
        }
        unset($row);
        $this->UpdateFormField('IDListe','values',json_encode($rows,JSON_THROW_ON_ERROR));
        $this->UpdateFormField('MeasurementBulkResult','caption','Lesequelle gewählt. Mit „Übernehmen“ speichern; einzelne Ausnahmen unter „Quellen je Messwert“ einstellen.');
    }

    public function ShowMeasurementSources(bool $Show): void
    {
        $this->UpdateFormField('MeasurementSourceChoice','visible',$Show);
        $form=json_decode($this->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
        foreach($form['elements'] as $element) if(($element['name']??'')==='IDListe') {
            foreach($element['columns'] as $index=>$column) if(in_array($column['name'],['preferredSource','interface'],true))
                $this->UpdateFormField('IDListe','columns.'.$index.'.visible',$Show);
        }
    }

    private function SimplifyConfigurationForm(array $form): array
    {
        $connection=[];$rest=[];$control=null;$measurements=null;$modbus=null;$web=null;$bulk=null;
        foreach($form['elements'] as $element) {
            $name=$element['name']??'';$caption=$element['caption']??'';
            if(in_array($name,['IPAddress','Port','UpdateInterval'],true)) {$connection[$name]=$element;continue;}
            if($caption==='Modbus TCP / SHI') {$modbus=$element;continue;}
            if($caption==='WebSocket-Ergänzung') {$web=$element;continue;}
            if($name==='IDListe') {$measurements=$element;continue;}
            if($caption==='Timer / Zusatzfunktionen') {$control=$element;continue;}
            if(($element['type']??'')==='RowLayout' && ($element['items'][0]['name']??'')==='MeasurementBulkSource') {$bulk=$element;continue;}
            if($name==='MeasurementBulkResult') continue;
            $rest[]=$element;
        }
        $find=function(array $items,string $name) use (&$find):?array {
            foreach($items as $item) {
                if(($item['name']??'')===$name) return $item;
                if(isset($item['items']) && ($found=$find($item['items'],$name))!==null) return $found;
            }
            return null;
        };
        $webSupport=$this->InterfaceSupport('websocket');$modbusSupport=$this->InterfaceSupport('modbus');
        $password=$find($web['items'],'WebSocketPassword');$password['visible']=$webSupport!==false;
        $connection['IPAddress']['caption']='IP-Adresse der Wärmepumpe';
        $connection['UpdateInterval']['caption']='Aktualisierung (Sekunden)';
        $saved=array_column($this->MeasurementRows(),null,'id');
        $retainedSources=array_column($saved,'preferredSource');
        $retainedSources[]=$this->ReadPropertyString('MeasurementBulkSource');
        $sourceAllowed=static fn($option)=>($option['value']!=='websocket' || $webSupport!==false || in_array('websocket',$retainedSources,true))
            && ($option['value']!=='modbus' || $modbusSupport!==false || in_array('modbus',$retainedSources,true));
        $source=$bulk['items'][0];$source['caption']='Messwerte bevorzugt lesen über';
        $source['options']=array_values(array_filter($source['options'],$sourceAllowed));
        $source['onChange']='WPLUX_PreviewMeasurementSource($id, $MeasurementBulkSource, is_string($IDListe) ? $IDListe : json_encode($IDListe));';
        $advanced=['type'=>'ExpansionPanel','caption'=>'Verbindungsdetails','items'=>[
            $connection['Port'],array_replace($find($web['items'],'WebSocketTimezone'),['visible'=>$webSupport!==false])]];
        $connectionPanel=['type'=>'ExpansionPanel','caption'=>'Verbindung und Lesen','expanded'=>true,'items'=>[
            $connection['IPAddress'],$connection['UpdateInterval'],
            ['type'=>'Label','name'=>'FirmwareStatus','caption'=>$this->FirmwareStatusCaption()],$password,
            ['type'=>'Label','name'=>'WebSocketStatus','caption'=>$this->WebSocketStatusCaption(),'visible'=>$webSupport!==false],
            ['type'=>'Label','caption'=>'Gewünschte Istwerte anhaken und „Übernehmen“ klicken. Das Modul ergänzt fehlende Messwerte automatisch aus verfügbaren Quellen.'],
            ['type'=>'Label','name'=>'MeasurementBulkResult','caption'=>''],
            ['type'=>'CheckBox','name'=>'AdvancedSourceSelection','caption'=>'Quellen je Istwert einstellen',
                'onChange'=>'WPLUX_ShowMeasurementSources($id, $AdvancedSourceSelection);'],
            ['type'=>'RowLayout','name'=>'MeasurementSourceChoice','visible'=>$this->ReadPropertyBoolean('AdvancedSourceSelection'),'items'=>[$source]],$advanced]];
        $advancedSources=$this->ReadPropertyBoolean('AdvancedSourceSelection');
        $measurements['caption']='Istwerte – gewünschte Variablen auswählen';
        $measurements['values']=array_values(array_filter($measurements['values'],function($row) use($saved,$webSupport,$modbusSupport):bool {
            $id=(int)$row['id'];
            if(!empty($saved[$id]['enabled']) || $id<100000) return true;
            if(isset($this->SHISelections()[$id])) return $modbusSupport!==false || ($webSupport!==false && isset($this->WebDefinitions()[$id]));
            return $webSupport!==false;
        }));
        foreach($measurements['values'] as &$row) {
            $sources=array_values(array_intersect(['java','modbus','websocket'],$this->MeasurementSources((int)$row['id'])));
            $row['preferredSource']=$saved[(int)$row['id']]['preferredSource']??$row['preferredSource'];
            $row['interface']=implode(' + ',array_map(static fn($source)=>['java'=>'Java','websocket'=>'WebSocket','modbus'=>'Modbus'][$source],$sources));
        }
        unset($row);

        foreach($measurements['columns'] as &$column) {
            $name=$column['name'];
            if($name==='id') {$column['caption']='ID';unset($column['edit']);}
            if(in_array($name,['preferredSource','interface'],true)) $column['visible']=$advancedSources;
            if($name==='preferredSource') $column['edit']['options']=array_values(array_filter($column['edit']['options'],$sourceAllowed));
            if($name==='source') $column['caption']='Aktuelle Quelle';
            if($name==='name') $column['quickFilter']=true;
        }
        unset($column);
        $write=$find($modbus['items'],'CommunicationMode');unset($write['value']);
        $write['caption']='Zusätzlicher SHI-Zugriff für Skripte';$write['visible']=$modbusSupport!==false;
        $write['options']=[['caption'=>'Ausgewählte Steuervariablen verwenden','value'=>'java'],['caption'=>'Auch direkte SHI-API ohne Auswahl zulassen','value'=>'modbus']];
        $control['caption']='Steuerung, Zeitprogramme und COP/JAZ';
        array_unshift($control['items'],['type'=>'Label','caption'=>'Diese bisherigen Regler-Einstellungen und Zeitprogramme werden über Java bedient. Die zusätzlichen SHI-Vorgaben stehen im eigenen Bereich.']);
        $gatewayID=$this->ModbusGatewayID();
        $shi=['type'=>'ExpansionPanel','caption'=>'Modbus / SHI – externe Regelung','visible'=>$modbusSupport!==false,'items'=>[
            ['type'=>'Label','name'=>'ModbusConnectionStatus','caption'=>$gatewayID>0
                ?'Symcon-Modbus-Gateway '.$gatewayID.' verbunden. IP-Adresse und Port im Client Socket, Geräteadresse im Gateway einstellen.'
                :'Kein Symcon-Modbus-Gateway verbunden. Über „Gateway ändern“ verbinden; IP-Adresse und Port im Client Socket, Geräteadresse im Gateway einstellen.'],
            ['type'=>'Label','caption'=>'Beispiel: „Offset Heizen“ anhaken und übernehmen. Schreibst du danach +1,0 K in die Variable, erhöht der Regler die Rücklauf-Solltemperatur um 1 K. Den benötigten Modus stellt das Modul automatisch ein.'],
            $this->SHIControlForm(),
            ['type'=>'ExpansionPanel','caption'=>'Hinweise zu SHI','items'=>[
                ['type'=>'Label','caption'=>'SHI-Vorgaben sind externe Eingriffe zusätzlich zur normalen Java-Reglerkonfiguration. Ohne Modbus-Anfragen setzt der Regler sie laut Anleitung nach 15 Minuten zurück. Eine aktive Vorgabe wird über den zugehörigen SHI-Modus beendet, nicht durch Löschen ihrer Symcon-Variable.']]]]];
        $download=null;
        foreach($web['items'] as $item) foreach($item['items']??[] as $field) if(isset($field['download'])) $download=$field;
        if($download!==null) $download['visible']=$webSupport!==false;
        $inventoryStatus=$find($web['items'],'WebInventoryStatus');$inventoryStatus['visible']=$webSupport!==false;
        $warmStatus=$find($modbus['items'],'SHIWarmWaterStatus');$warmStatus['visible']=$modbusSupport!==false;
        $webError=$this->WebEEVCache()['error']??$this->WebInventory()['error']??'';
        $diagnostics=['type'=>'ExpansionPanel','caption'=>'Diagnose und erweiterte Einstellungen','items'=>[
            $inventoryStatus,$download,$warmStatus,
            ['type'=>'Label','caption'=>'Versionsreihe V3: WebSocket ab 3.81, Modbus/SHI laut Handbuch ab 3.90.1. Andere Versionsreihen werden über die Schnittstellen geprüft. Fehlende Erreichbarkeit bedeutet nicht fehlende Firmware-Unterstützung.'],
            ['type'=>'Label','visible'=>$webSupport!==false,'caption'=>'WebSocket (Port 8214). Der Bericht enthält alle erreichbaren Informationsseiten einschließlich Untermenüs; Java und WebSocket müssen nicht dieselben Werte anbieten.'],
            ['type'=>'Label','visible'=>$webSupport!==false,'caption'=>$webError!==''?'Letzter WebSocket-Fehler: '.$webError:'Keine gemeldeten WebSocket-Verbindungsfehler.'],
            ['type'=>'Button','visible'=>$modbusSupport!==false,'caption'=>'Modbus-Statusbericht herunterladen','download'=>'luxtronik-modbus-diagnose.json',
                'onClick'=>'echo "data:application/json;base64," . base64_encode(WPLUX_GetSHIData($id));'],
            $write,$bulk['items'][1],
            ['type'=>'ExpansionPanel','caption'=>'Bisherige Direktverbindung','visible'=>$gatewayID===0 && $modbusSupport!==false,'items'=>[
                ['type'=>'Label','caption'=>'Nur für bestehende Installationen ohne Symcon-Modbus-Gateway. Diese Einstellungen gelten nicht für ein verbundenes Gateway.'],
                $find($modbus['items'],'ModbusPort'),$find($modbus['items'],'ModbusUnitID'),$find($modbus['items'],'ModbusTimeout')]]]];
        // Existing stable controls and the visualization form are kept intact, merely grouped.
        $active=array_shift($rest);
        $form['elements']=[$active,$connectionPanel,$measurements,$control,$shi,...$rest,$diagnostics];
        return $form;
    }
}
