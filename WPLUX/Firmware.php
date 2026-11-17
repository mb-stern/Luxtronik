<?php

/** Capability evidence belongs to the controller, independently of variable selections. */
trait LuxtronikFirmware
{
    private function FirmwareSourceKey(): string
    {
        return json_encode([$this->ReadPropertyString('IPAddress'),$this->ReadPropertyInteger('Port')],JSON_THROW_ON_ERROR);
    }

    private function FirmwareInfo(): array
    {
        $info=json_decode($this->GetBuffer('FirmwareInfo'),true)??[];
        return ($info['source']??'')===$this->FirmwareSourceKey()?$info:['source'=>$this->FirmwareSourceKey()];
    }

    private function ParseFirmware(string $text): ?array
    {
        $text=trim($text," \t\r\n\x00");
        if(!preg_match('/^[vV]?([1-9][0-9]*)\.([0-9]{1,2})(?:\.([0-9]{1,3}))?(?:[a-zA-Z_-][a-zA-Z0-9_.-]*)?(?: \(r[0-9]+\))?$/D',$text,$m)) return null;
        return ['text'=>$text,'major'=>(int)$m[1],'minor'=>(int)$m[2],'patch'=>(int)($m[3]??0),'patchKnown'=>isset($m[3])];
    }

    private function RememberJavaFirmware(array $raw): void
    {
        $text='';
        foreach(range(81,90) as $id) {
            if(!isset($raw[$id]) || !is_int($raw[$id]) || $raw[$id]<0 || $raw[$id]>255) return;
            $text.=chr($raw[$id]);
        }
        $this->RememberFirmwareString($text);
    }

    private function RememberFirmwareString(string $text): void
    {
        $version=$this->ParseFirmware($text);
        if($version===null) return;
        $before=[$this->InterfaceSupport('websocket'),$this->InterfaceSupport('modbus')];
        $info=$this->FirmwareInfo();
        $previous=$info['firmware']??null;
        $changed=$previous!==null && ($previous['major']!==$version['major'] || $previous['minor']!==$version['minor']
            || ($previous['patchKnown'] && $version['patchKnown'] && $previous['patch']!==$version['patch']));
        if($changed) {
            unset($info['websocket'],$info['modbus'],$info['probeAfter'],$info['probeError']);
            // A firmware change invalidates discovery, never user objects or selections.
            foreach(['WebInventory','WebEEVCache','SHICache'] as $buffer) $this->SetBuffer($buffer,'');
        } elseif($previous!==null && ((!$version['patchKnown'] && $previous['patchKnown'])
            || ($version['patchKnown']===$previous['patchKnown'] && strlen($previous['text'])>strlen($version['text'])))) {
            // Java has only ten characters; retain a fuller web version of the same release.
            $version=$previous;
        }
        $info['firmware']=$version;$info['updatedAt']=time();
        $this->SetBuffer('FirmwareInfo',json_encode($info,JSON_THROW_ON_ERROR));
        $this->RefreshFirmwareForm($before);
    }

    private function RememberWebFirmware(array $snapshot): void
    {
        foreach($snapshot['groups']??[] as $group) if($this->WebFieldKey($group['name'])===$this->WebFieldKey('Anlagenstatus'))
            foreach($group['fields'] as $field) if($this->WebFieldKey($field['name'])===$this->WebFieldKey('Softwarestand')) $this->RememberFirmwareString($field['raw']);
    }

    /** True = supported, false = outside documented V3 requirements, null = not determined. */
    private function InterfaceSupport(string $interface): ?bool
    {
        $info=$this->FirmwareInfo();
        if(!empty($info[$interface])) return true;
        // Keep successful evidence from installations predating the firmware detector.
        if($interface==='modbus') {
            $cache=$this->SHICache();
            if(empty($cache['stale']) && !empty($cache['points'])) return true;
        } elseif(!empty($this->WebInventory()['discovery']['groups']) || !empty($this->WebEEVCache()['discovery']['groups'])) return true;
        $v=$info['firmware']??null;
        // Version families have different numbering. Do not invent a cutoff for other series.
        if($v===null || $v['major']!==3) return null;
        if($interface==='modbus' && $v['minor']===90 && !$v['patchKnown']) return null;
        $minimum=$interface==='modbus'?'3.90.1':'3.81.0';
        $supported=version_compare($v['major'].'.'.$v['minor'].'.'.$v['patch'],$minimum,'>=');
        $java=json_decode($this->GetBuffer('JavaAvailability'),true)??[];
        // A failed Java read can accompany an update: allow rediscovery instead of locking on an old cutoff.
        if(!$supported && !empty($java['failed']) && ($java['source']??'')===$this->VisualizationSourceKey()) return null;
        return $supported;
    }

    private function MarkInterfaceSupported(string $interface): void
    {
        $info=$this->FirmwareInfo();
        if(!empty($info[$interface])) return;
        $before=[$this->InterfaceSupport('websocket'),$this->InterfaceSupport('modbus')];
        $info[$interface]=true;
        $this->SetBuffer('FirmwareInfo',json_encode($info,JSON_THROW_ON_ERROR));
        $this->RefreshFirmwareForm($before);
    }

    private function FirmwareStatusCaption(): string
    {
        $info=$this->FirmwareInfo();$version=$info['firmware']['text']??'noch nicht ermittelt';
        $parts=['Firmware: '.$version];
        foreach(['websocket'=>'WebSocket','modbus'=>'Modbus/SHI'] as $key=>$name) {
            $support=$this->InterfaceSupport($key);
            $parts[]=$name.': '.($support===true?'unterstützt':($support===false?'nicht unterstützt':'noch nicht bestätigt'));
        }
        return implode(' · ',$parts);
    }

    private function RefreshFirmwareForm(array $before): void
    {
        $this->UpdateFormField('FirmwareStatus','caption',$this->FirmwareStatusCaption());
        $after=[$this->InterfaceSupport('websocket'),$this->InterfaceSupport('modbus')];
        if($before!==$after) $this->ReloadForm();
    }

    /** One bounded read, no instance creation or writes, for version families without a documented cutoff. */
    protected function NewFirmwareProbeClient(): LuxtronikModbusClient
    {
        return new LuxtronikModbusTCP($this->ReadPropertyString('IPAddress'),$this->ReadPropertyInteger('ModbusPort'),
            $this->ReadPropertyInteger('ModbusUnitID'),min(500,$this->ReadPropertyInteger('ModbusTimeout')));
    }

    private function ProbeUncertainSHIFirmware(): void
    {
        $info=$this->FirmwareInfo();
        if(!isset($info['firmware']) || $this->InterfaceSupport('modbus')!==null || time()<($info['probeAfter']??0)
            || in_array($this->ReadPropertyString('IPAddress'),['','0.0.0.0','::'],true)) return;
        $info['probeAfter']=time()+300;$this->SetBuffer('FirmwareInfo',json_encode($info,JSON_THROW_ON_ERROR));
        $client=null;
        try {
            $client=$this->NewFirmwareProbeClient();
            $words=$client->readInputRegisters(10701,1); // Documented SHI software revision major.
            if(($words[0]??0)===$info['firmware']['major']) $this->MarkInterfaceSupported('modbus');
        } catch(Throwable $error) {
            if($error instanceof LuxtronikInstanceUnavailable) throw $error;
            $info=$this->FirmwareInfo();$info['probeError']=$error->getMessage();
            $this->SetBuffer('FirmwareInfo',json_encode($info,JSON_THROW_ON_ERROR));
        } finally {$client?->close();}
    }
}
