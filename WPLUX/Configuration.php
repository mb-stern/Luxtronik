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
            $sources=$this->MeasurementSources($id);
            if(in_array($Source,$sources,true)) $row['preferredSource']=$Source;
            elseif($sources!==[] && !in_array($row['preferredSource']??'',$sources,true)) $row['preferredSource']=$sources[0];
        }
        unset($row);
        $this->UpdateFormField('IDListe','values',json_encode($rows,JSON_THROW_ON_ERROR));
        $this->UpdateFormField('MeasurementBulkResult','caption','Lesequelle gewählt. Mit „Übernehmen“ speichern; einzelne Ausnahmen unter „Quellen je Messwert“ einstellen.');
    }

    private function MeasurementEditorSources(int $id): array
    {
        return array_values(array_filter($this->MeasurementSources($id),
            fn($source)=>$source==='java' || $this->InterfaceSupport($source)===true));
    }

    /** The List editor is built per row so unsupported protocols cannot be selected. */
    public function GetMeasurementRowForm(int $MeasurementID,bool $ShowSources): string
    {
        $items=[['type'=>'CheckBox','name'=>'enabled','caption'=>'Variable anlegen']];
        if($ShowSources) {
            $captions=['java'=>'Java','websocket'=>'WebSocket','modbus'=>'Modbus/SHI'];
            $sources=$this->MeasurementEditorSources($MeasurementID);
            if($sources!==[]) $items[]=['type'=>'Select','name'=>'preferredSource','caption'=>'Bevorzugte Quelle',
                'options'=>array_map(static fn($source)=>['caption'=>$captions[$source],'value'=>$source],$sources)];
            else $items[]=['type'=>'Label','caption'=>'Für diesen Messwert ist derzeit keine passende Lesequelle verfügbar.'];
        }
        return json_encode($items,JSON_THROW_ON_ERROR);
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
        $availableSources=[];
        foreach($measurements['values'] as $row) foreach($this->MeasurementEditorSources((int)$row['id']) as $supported) $availableSources[$supported]=true;
        $sourceAllowed=static fn($option)=>isset($availableSources[$option['value']]);
        $source=$bulk['items'][0];$source['caption']='Messwerte bevorzugt lesen über';
        $source['options']=array_values(array_filter($source['options'],$sourceAllowed));
        $bulkButton=$bulk['items'][1];$bulkButton['enabled']=$source['options']!==[];
        if($source['options']===[]) {
            $source['options']=[['caption'=>'Keine passende Quelle','value'=>'']];$source['enabled']=false;
        }
        $source['onChange']='WPLUX_PreviewMeasurementSource($id, $MeasurementBulkSource, is_string($IDListe) ? $IDListe : json_encode($IDListe));';
        $connection['Port']['caption']='Java-Port';
        $timeout=$find($modbus['items'],'ModbusTimeout');$timeout['caption']='Modbus-Antwortwartezeit (Millisekunden)';
        $connectionPanel=['type'=>'ColumnLayout','name'=>'ConnectionAndReading','items'=>[
            ['type'=>'Label','caption'=>'Verbindung und Lesen','bold'=>true],
            ['type'=>'RowLayout','items'=>[$connection['IPAddress'],$connection['UpdateInterval'],$connection['Port']]],
            ['type'=>'Label','name'=>'FirmwareStatus','caption'=>$this->FirmwareStatusCaption()],
            $password,
            ['type'=>'RowLayout','visible'=>$modbusSupport!==false,'items'=>[
                $find($modbus['items'],'ModbusPort'),$find($modbus['items'],'ModbusUnitID'),$timeout]],
            ['type'=>'Label','name'=>'WebSocketStatus','caption'=>$this->WebSocketStatusCaption(),'visible'=>$webSupport!==false],
            ['type'=>'Label','caption'=>'Gewünschte Istwerte anhaken und „Übernehmen“ klicken. Das Modul ergänzt fehlende Messwerte automatisch aus verfügbaren Quellen.'],
            ['type'=>'Label','name'=>'MeasurementBulkResult','caption'=>''],
            ['type'=>'CheckBox','name'=>'AdvancedSourceSelection','caption'=>'Quellen je Istwert einstellen',
                'onChange'=>'WPLUX_ShowMeasurementSources($id, $AdvancedSourceSelection);'],
            ['type'=>'RowLayout','name'=>'MeasurementSourceChoice','visible'=>$this->ReadPropertyBoolean('AdvancedSourceSelection'),'items'=>[$source,$bulkButton]]]];
        $advancedSources=$this->ReadPropertyBoolean('AdvancedSourceSelection');
        $measurements['caption']='Istwerte – gewünschte Variablen auswählen';
        $measurements['values']=array_values(array_filter($measurements['values'],function($row) use($saved,$webSupport,$modbusSupport):bool {
            $id=(int)$row['id'];
            if(!empty($saved[$id]['enabled']) || $id<100000) return true;
            if(isset($this->SHISelections()[$id])) return $modbusSupport!==false || ($webSupport!==false && isset($this->WebDefinitions()[$id]));
            return $webSupport!==false;
        }));
        foreach($measurements['values'] as &$row) {
            $available=$this->MeasurementEditorSources((int)$row['id']);
            $sources=array_values(array_intersect(['java','modbus','websocket'],$available));
            $preferred=$saved[(int)$row['id']]['preferredSource']??$row['preferredSource'];
            $row['preferredSource']=in_array($preferred,$available,true)||$available===[]?$preferred:$available[0];
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
        $measurements['form']='return json_decode(WPLUX_GetMeasurementRowForm($id, (int)($IDListe[\'id\'] ?? 0), (bool)$AdvancedSourceSelection), true);';
        $write=$find($modbus['items'],'CommunicationMode');unset($write['value']);
        $write['caption']='Zusätzlicher SHI-Zugriff für Skripte';$write['visible']=$modbusSupport!==false;
        $write['options']=[['caption'=>'Ausgewählte Steuervariablen verwenden','value'=>'java'],['caption'=>'Auch direkte SHI-API ohne Auswahl zulassen','value'=>'modbus']];
        $control['caption']='Steuerung, Zeitprogramme und COP/JAZ';
        array_unshift($control['items'],['type'=>'Label','caption'=>'Diese bisherigen Regler-Einstellungen und Zeitprogramme werden über Java bedient. Die zusätzlichen SHI-Vorgaben stehen im eigenen Bereich.']);
        $shi=['type'=>'ExpansionPanel','caption'=>'Modbus / SHI – externe Regelung','visible'=>$modbusSupport!==false,'items'=>[
            $this->SHIControlForm(),
            ['type'=>'ExpansionPanel','caption'=>'Hinweise zu SHI','items'=>[
                ['type'=>'Label','caption'=>'Am Beispiel 310000 bis 310003 erklärt'],
                ['type'=>'Label','caption'=>'Die vier Heizvariablen gehören zusammen: „Modus Heizen“ bestimmt, welche der drei anderen Vorgaben wirkt.'],
                ['type'=>'Label','caption'=>'Modus Heizen: 0 = kein Einfluss, 1 = Sollwert verwenden, 2 = Offset verwenden, 3 = Level verwenden.'],
                ['type'=>'Label','caption'=>'Sollwert Heizen: Gibt die Rücklauf-Solltemperatur direkt vor, beispielsweise 30 °C. Wirkt bei Modus 1.'],
                ['type'=>'Label','caption'=>'Offset Heizen: Verschiebt die vom Regler berechnete Rücklauf-Solltemperatur. Beispielsweise werden aus 28 °C mit +1,0 K dann 29 °C. Wirkt bei Modus 2.'],
                ['type'=>'Label','caption'=>'Level Heizen: 0 = kein Einfluss, 1 = überhöht mit Zeitprogramm, 2 = überhöht ohne Zeitprogramm, 3 = reduziert. Wirkt bei Modus 3.'],
                ['type'=>'Label','caption'=>'Sollwert und Offset werden nicht zusammengerechnet. Der Modus wählt die gewünschte Art der Beeinflussung aus.'],
                ['type'=>'Label','caption'=>'Beispiel: Für deine Temperaturkorrektur reicht es, „Offset Heizen“ anzuhaken und zu übernehmen. Sendest du darüber per Schalten beziehungsweise RequestAction() eine Korrektur, setzt das Modul „Modus Heizen“ automatisch auf 2. Die Modusvariable musst du dafür nicht zusätzlich anlegen.'],
                ['type'=>'Label','caption'=>'Sendest du später über „Sollwert Heizen“ einen direkten Sollwert, schaltet das Modul automatisch auf Modus 1 um. Der gespeicherte Offset wirkt dann nicht mehr.'],
                ['type'=>'Label','caption'=>'SHI-Vorgaben sind externe Eingriffe zusätzlich zur normalen Java-Reglerkonfiguration. Eine aktive Vorgabe wird über den zugehörigen SHI-Modus beendet, nicht durch Löschen ihrer Symcon-Variable.'],
                ['type'=>'Label','caption'=>'Die SHI-Anleitung nennt einen Rückfall nach 15 Minuten ohne Modbus-Anfragen. Diesen Rückfall steuert der Regler. Das Modul liest Modbus bedarfsgesteuert und schreibt Vorgaben nur beim Schalten; Java- und WebSocket-Abfragen sind davon unabhängig. Zum gezielten Beenden der Heizvorgabe „Modus Heizen“ auf 0 – Kein Einfluss schalten.']]]]];
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
            $write]];
        // Existing stable controls and the visualization form are kept intact, merely grouped.
        $active=array_shift($rest);
        $form['elements']=[$active,$connectionPanel,$measurements,$control,$shi,...$rest,$diagnostics];
        return $form;
    }
}
