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
        $connection['IPAddress']['caption']='IP-Adresse der Wärmepumpe';
        $connection['UpdateInterval']['caption']='Aktualisierung (Sekunden)';
        $source=$bulk['items'][0];$source['caption']='Messwerte bevorzugt lesen über';
        $source['onChange']='WPLUX_PreviewMeasurementSource($id, $MeasurementBulkSource, is_string($IDListe) ? $IDListe : json_encode($IDListe));';
        $advanced=['type'=>'ExpansionPanel','caption'=>'Verbindungsdetails','items'=>[
            $connection['Port'],$find($web['items'],'WebSocketTimezone'),$find($modbus['items'],'ModbusTimeout')]];
        $connectionPanel=['type'=>'ExpansionPanel','caption'=>'Verbindung und Lesen','expanded'=>true,'items'=>[
            $connection['IPAddress'],$connection['UpdateInterval'],$find($web['items'],'WebSocketPassword'),
            ['type'=>'Label','name'=>'WebSocketStatus','caption'=>$this->WebSocketStatusCaption()],
            ['type'=>'Label','caption'=>'Gewünschte Istwerte anhaken und „Übernehmen“ klicken. Das Modul ergänzt fehlende Messwerte automatisch aus verfügbaren Quellen.'],
            ['type'=>'Label','name'=>'MeasurementBulkResult','caption'=>''],
            ['type'=>'CheckBox','name'=>'AdvancedSourceSelection','caption'=>'Quellen je Istwert einstellen',
                'onChange'=>'WPLUX_ShowMeasurementSources($id, $AdvancedSourceSelection);'],
            ['type'=>'RowLayout','name'=>'MeasurementSourceChoice','visible'=>$this->ReadPropertyBoolean('AdvancedSourceSelection'),'items'=>[$source]],$advanced]];
        $advancedSources=$this->ReadPropertyBoolean('AdvancedSourceSelection');
        $measurements['caption']='Istwerte – gewünschte Variablen auswählen';
        foreach($measurements['columns'] as &$column) {
            $name=$column['name'];
            if($name==='id') {$column['caption']='ID';unset($column['edit']);}
            if(in_array($name,['preferredSource','interface'],true)) $column['visible']=$advancedSources;
            if($name==='source') $column['caption']='Aktuelle Quelle';
            if($name==='name') $column['quickFilter']=true;
        }
        unset($column);
        $write=$find($modbus['items'],'CommunicationMode');unset($write['value']);
        $write['caption']='Zusätzlicher SHI-Zugriff für Skripte';
        $write['options']=[['caption'=>'Ausgewählte Steuervariablen verwenden','value'=>'java'],['caption'=>'Auch direkte SHI-API ohne Auswahl zulassen','value'=>'modbus']];
        $control['caption']='Steuerung, Zeitprogramme und COP/JAZ';
        array_unshift($control['items'],['type'=>'Label','caption'=>'Diese bisherigen Regler-Einstellungen und Zeitprogramme werden über Java bedient. Die zusätzlichen SHI-Vorgaben stehen im eigenen Bereich.']);
        $gatewayID=$this->ModbusGatewayID();
        $shi=['type'=>'ExpansionPanel','caption'=>'Modbus / SHI – externe Regelung','items'=>[
            $find($modbus['items'],'ModbusPort'),$find($modbus['items'],'ModbusUnitID'),
            ['type'=>'Label','caption'=>'Gleiche IP-Adresse und gleiches Aktualisierungsintervall wie oben. Gewünschte Steuervariablen anhaken und „Übernehmen“ klicken. Erst eine Bedienaktion schreibt eine Vorgabe.'],
            $find($modbus['items'],'SHIWarmWaterStatus'),$this->SHIControlForm(),
            ['type'=>'ExpansionPanel','caption'=>'Hinweise zu SHI','items'=>[
                ['type'=>'Label','caption'=>'SHI-Vorgaben sind externe Eingriffe zusätzlich zur normalen Java-Reglerkonfiguration. Ohne Modbus-Anfragen setzt der Regler sie laut Anleitung nach 15 Minuten zurück. Eine aktive Vorgabe wird über den zugehörigen SHI-Modus beendet, nicht durch Löschen ihrer Symcon-Variable.']]]]];
        if($gatewayID>0) {
            // Native parent settings belong to the gateway/socket, as in the Goodwe module.
            array_splice($shi['items'],0,2,[['type'=>'Label','caption'=>'Symcon-Modbus-Gateway '.$gatewayID.' verbunden. IP-Adresse und Port im Client Socket, Geräteadresse im Gateway einstellen.']]);
            $advancedIndex=count($connectionPanel['items'])-1;
            foreach($connectionPanel['items'][$advancedIndex]['items'] as &$field) if(($field['name']??'')==='ModbusTimeout') $field['visible']=false;
            unset($field);
        }
        $download=null;
        foreach($web['items'] as $item) foreach($item['items']??[] as $field) if(isset($field['download'])) $download=$field;
        $webError=$this->WebEEVCache()['error']??$this->WebInventory()['error']??'';
        $diagnostics=['type'=>'ExpansionPanel','caption'=>'Diagnose und erweiterte Einstellungen','items'=>[
            $find($web['items'],'WebInventoryStatus'),$download,
            ['type'=>'Label','caption'=>'WebSocket ab Firmware 3.81 (Port 8214). Der Bericht enthält alle erreichbaren Informationsseiten einschließlich Untermenüs; Java und WebSocket müssen nicht dieselben Werte anbieten.'],
            ['type'=>'Label','caption'=>$webError!==''?'Letzter WebSocket-Fehler: '.$webError:'Keine gemeldeten WebSocket-Verbindungsfehler.'],
            ['type'=>'Button','caption'=>'Modbus-Statusbericht herunterladen','download'=>'luxtronik-modbus-diagnose.json',
                'onClick'=>'echo "data:application/json;base64," . base64_encode(WPLUX_GetSHIData($id));'],
            $write,$bulk['items'][1]]];
        // Existing stable controls and the visualization form are kept intact, merely grouped.
        $active=array_shift($rest);
        $form['elements']=[$active,$connectionPanel,$measurements,$control,$shi,...$rest,$diagnostics];
        return $form;
    }
}
