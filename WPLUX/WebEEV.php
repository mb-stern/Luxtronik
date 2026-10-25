<?php

/** Bounded, read-only Lux_WS session discovering the current informational pages and fields. */
final class LuxtronikWebEEVClient
{
    private mixed $socket = null;
    private string $buffer = '';
    private float $deadline;
    private array $snapshot = [];
    private const LIMIT = 262144;

    public function __construct(private string $host, private int $port = 8214, private int $timeoutMs = 2000) {}

    public function snapshot(): array { return $this->snapshot; }

    public function read(string $password, ?array $requestedPages = null): array
    {
        $this->snapshot=[];
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP-XML-Erweiterung DOM fehlt.');
        if ((!filter_var($this->host,FILTER_VALIDATE_IP) && !filter_var($this->host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))
            || in_array($this->host,['','0.0.0.0','::'],true) || $this->port < 1 || $this->port > 65535
            || $this->timeoutMs < 100 || $this->timeoutMs > 5000) throw new InvalidArgumentException('Ungültige EEV-Verbindungseinstellungen.');
        if (strlen($password)>256 || preg_match('/[;\r\n\x00]/',$password)) throw new InvalidArgumentException('Ungültiges Webinterface-Passwort.');
        $this->deadline = microtime(true) + $this->timeoutMs / 1000;
        try {
            $host = str_contains($this->host,':') ? '['.$this->host.']' : $this->host;
            $this->socket = @stream_socket_client('tcp://'.$host.':'.$this->port,$error,$message,$this->timeoutMs / 1000);
            if (!is_resource($this->socket)) throw new RuntimeException('WebSocket auf Port '.$this->port.' nicht erreichbar. Webinterface ab Firmware 3.81 und Netzwerkzugriff prüfen.');
            stream_set_blocking($this->socket,false);
            $nonce = base64_encode(random_bytes(16));
            $this->write("GET / HTTP/1.1\r\nHost: {$host}:{$this->port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$nonce}\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: Lux_WS\r\n\r\n");
            $header = '';
            while (!str_ends_with($header,"\r\n\r\n")) {
                if (strlen($header)>=16384) throw new RuntimeException('EEV-WebSocket-Handshake zu groß.');
                $header .= $this->bytes(1);
            }
            $lines = explode("\r\n",$header);
            if (!preg_match('/^HTTP\/1\.[01] 101(?: |$)/',array_shift($lines))) throw new RuntimeException('EEV-WebSocket-Handshake abgelehnt.');
            $fields = [];
            foreach ($lines as $line) {
                if (!str_contains($line,':')) continue;
                [$name,$value] = explode(':',$line,2);
                $fields[strtolower(trim($name))] = trim($value);
            }
            if (($fields['sec-websocket-accept']??'') !== base64_encode(sha1($nonce.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true))
                || strtolower($fields['upgrade']??'') !== 'websocket'
                || !in_array('upgrade',array_map('trim',explode(',',strtolower($fields['connection']??''))),true)
                || (isset($fields['sec-websocket-protocol']) && $fields['sec-websocket-protocol']!=='Lux_WS')) {
                throw new RuntimeException('Ungültige EEV-WebSocket-Handshake-Antwort.');
            }
            $this->frame(1,'LOGIN;'.($password!==''?$password:'0'));
            $this->frame(1,'REFRESH');
            $navigation = $this->document('Navigation');
            $information = $this->item($navigation->documentElement,['informationen','information']);
            if ($information===null) throw new RuntimeException('EEV: Informationen fehlt; Webinterface-Anmeldung prüfen.');
            $info = $this->resolve($information);
            $groups=[];
            $errors=[];
            $menus=[];
            foreach ($info->childNodes as $menu) if ($menu instanceof DOMElement && $menu->localName==='item'
                && $this->child($menu,'name')!==null && $this->child($menu,'value')===null
                && ($requestedPages===null || in_array($this->key($this->child($menu,'name')->textContent),array_map($this->key(...),$requestedPages),true))) $menus[]=$menu;
            usort($menus,fn($a,$b)=> (int)!in_array($this->key($this->child($a,'name')->textContent),['ausgänge','ausgaenge','outputs'],true)
                <=> (int)!in_array($this->key($this->child($b,'name')->textContent),['ausgänge','ausgaenge','outputs'],true));
            $outputs=null;
            foreach ($menus as $menu) {
                $name=trim($this->child($menu,'name')->textContent);
                try {
                    $root=$this->resolve($menu);
                    $fields=[];
                    foreach ($root->getElementsByTagName('item') as $field) if ($this->child($field,'value')!==null) {
                        $fields[]=['id'=>$field->getAttribute('id'),'name'=>trim($this->child($field,'name')?->textContent??''),
                            'raw'=>trim($this->child($field,'value')->textContent)];
                    }
                    $groups[]=['name'=>$name,'id'=>$menu->getAttribute('id'),'count'=>count($fields),'fields'=>$fields];
                    if (in_array($this->key($name),['ausgänge','ausgaenge','outputs'],true)) $outputs=$root;
                } catch(RuntimeException $error) { $errors[]=['name'=>$name,'error'=>$error->getMessage()]; }
            }
            foreach($requestedPages??[] as $page) if(!array_filter($groups,fn($group)=>$this->key($group['name'])===$this->key($page)))
                $errors[]=['name'=>$page,'error'=>'Benötigte Informationsseite nicht geliefert.'];
            $this->snapshot=['count'=>array_sum(array_column($groups,'count')),'groups'=>$groups,'errors'=>$errors,'complete'=>$errors===[],
                'fullScan'=>$requestedPages===null,'requestedPages'=>$requestedPages];
            if ($outputs===null) {
                if ($groups!==[]) return [];
                throw new RuntimeException('Keine Informationsseite konnte gelesen werden.');
            }
            $result = [];
            foreach ($outputs->getElementsByTagName('item') as $item) {
                $name = $this->key($this->child($item,'name')?->textContent??'');
                $ident = match ($name) {
                    'eevheizen','eevheating' => 'EEV_Heizen',
                    'eevkühlen','eevkuehlen','eevcooling' => 'EEV_Kuehlen',
                    default => null
                };
                if ($ident===null) continue;
                if (array_key_exists($ident,$result)) throw new RuntimeException('EEV-Bezeichnung mehrfach vorhanden.');
                $raw = trim($this->child($item,'value')?->textContent??'');
                $text = str_replace([',',"\u{00a0}","\u{202f}"],['.',' ',' '],$raw);
                $value = preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%?$/D',$text,$match) && (float)$match[1]<=100 ? (float)$match[1] : null;
                $result[$ident] = $value===null ? null : ['value'=>$value,'webId'=>$item->getAttribute('id')];
            }
            return array_filter($result,static fn($row)=>$row!==null);
        } finally {
            if (is_resource($this->socket)) fclose($this->socket);
            $this->socket = null;
            $this->buffer = '';
        }
    }

    private function child(DOMElement $node,string $name): ?DOMElement
    {
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName===$name) return $child;
        return null;
    }

    private function key(string $name): string
    {
        return preg_replace('/[\s_-]+/u','',strtolower(strtr(trim($name),['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü'])));
    }

    private function item(DOMElement $node,array $names): ?DOMElement
    {
        foreach ($node->getElementsByTagName('item') as $item) {
            if (in_array($this->key($this->child($item,'name')?->textContent??''),$names,true)) return $item;
        }
        return null;
    }

    private function resolve(DOMElement $item): DOMElement
    {
        if ($this->child($item,'item')!==null) return $item;
        $id = $item->getAttribute('id');
        if (!preg_match('/^[^\x00-\x20;]{1,128}$/D',$id)) throw new RuntimeException('Ungültige EEV-Menükennung.');
        $this->frame(1,'GET;'.$id);
        $root = $this->document('Content')->documentElement;
        if ($root->hasAttribute('id') && $root->getAttribute('id')!==$id) throw new RuntimeException('EEV-Antwort gehört zu einem anderen Menü.');
        return $root;
    }

    private function document(string $expected): DOMDocument
    {
        for ($i=0;$i<32;++$i) {
            $text = $this->message();
            if(trim($text)==='') continue;
            if (stripos($text,'<!DOCTYPE')!==false || stripos($text,'<!ENTITY')!==false) throw new RuntimeException('Unzulässiger XML-Dokumenttyp.');
            $previous = libxml_use_internal_errors(true);
            try {
                $document = new DOMDocument();
                $valid = $document->loadXML($text,LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (!$valid || $document->documentElement===null) throw new RuntimeException('Ungültige EEV-XML-Antwort.');
            if (strcasecmp($document->documentElement->localName,$expected)===0) return $document;
        }
        throw new RuntimeException('EEV-Menüantwort fehlt.');
    }

    private function frame(int $opcode,string $payload): void
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $header = chr(0x80|$opcode).($length<126?chr(0x80|$length):chr(0xfe).pack('n',$length));
        $this->write($header.$mask.($payload ^ substr(str_repeat($mask,(int)ceil($length/4)),0,$length)));
    }

    private function message(): string
    {
        $message = '';
        $started = false;
        for ($i=0;$i<128;++$i) {
            [$first,$second] = array_values(unpack('C2',$this->bytes(2)));
            $fin = ($first&0x80)!==0;
            $opcode = $first&15;
            $length = $second&127;
            if (($first&0x70)!==0 || ($second&0x80)!==0) throw new RuntimeException('Ungültiger EEV-WebSocket-Frame.');
            if ($length===126) $length=unpack('n',$this->bytes(2))[1];
            elseif ($length===127) {
                $words=array_values(unpack('N2',$this->bytes(8)));
                if ($words[0]!==0) throw new RuntimeException('EEV-WebSocket-Antwort zu groß.');
                $length=$words[1];
            }
            if ($length>self::LIMIT || ($opcode>=8 && (!$fin || $length>125))) throw new RuntimeException('Ungültige EEV-Framegröße.');
            $payload=$this->bytes($length);
            if ($opcode===8) throw new RuntimeException('EEV-WebSocket vom Regler beendet.');
            if ($opcode===9) { $this->frame(10,$payload); continue; }
            if ($opcode===10) continue;
            if (($opcode!==1 || $started) && ($opcode!==0 || !$started)) throw new RuntimeException('Unerwarteter EEV-Nachrichtentyp.');
            $started=true;
            $message.=$payload;
            if (strlen($message)>self::LIMIT) throw new RuntimeException('EEV-Nachricht zu groß.');
            if ($fin) {
                if (preg_match('//u',$message)!==1) throw new RuntimeException('Ungültige EEV-Textkodierung.');
                return $message;
            }
        }
        throw new RuntimeException('EEV-Textantwort unvollständig.');
    }

    private function wait(bool $read): void
    {
        $remaining=$this->deadline-microtime(true);
        if ($remaining<=0) throw new RuntimeException('EEV-WebSocket-Zeitüberschreitung.');
        $readers=$read?[$this->socket]:[];
        $writers=$read?[]:[$this->socket];
        $except=[];
        $ready=@stream_select($readers,$writers,$except,(int)$remaining,(int)(($remaining-(int)$remaining)*1000000));
        if (!$ready) throw new RuntimeException('EEV-WebSocket-Zeitüberschreitung.');
    }

    private function bytes(int $length): string
    {
        while (strlen($this->buffer)<$length) {
            $this->wait(true);
            $part=@fread($this->socket,max(8192,$length-strlen($this->buffer)));
            if ($part===false || $part==='') throw new RuntimeException('EEV-Verbindung unterbrochen.');
            $this->buffer.=$part;
        }
        $value=substr($this->buffer,0,$length);
        $this->buffer=substr($this->buffer,$length);
        return $value;
    }

    private function write(string $data): void
    {
        while ($data!=='') {
            $this->wait(false);
            $written=@fwrite($this->socket,$data);
            if ($written===false || $written===0) throw new RuntimeException('EEV-Leseanfrage konnte nicht gesendet werden.');
            $data=substr($data,$written);
        }
    }
}

trait LuxtronikWebEEV
{
    // Selection IDs refer to existing native Idents, not to WebSocket memory addresses.
    private const WEB_NATIVE_FIELDS = [
        10=>['Temperaturen','Vorlauf','°C'],11=>['Temperaturen','Rücklauf','°C'],12=>['Temperaturen','Rückl.-Soll','°C'],
        14=>['Temperaturen','Heissgas','°C'],15=>['Temperaturen','Außentemperatur','°C'],16=>['Temperaturen','Mitteltemperatur','°C'],
        17=>['Temperaturen','Warmwasser-Ist','°C'],18=>['Temperaturen','Warmwasser-Soll','°C'],19=>['Temperaturen','Wärmequelle-Ein','°C'],
        21=>['Temperaturen','Mischkreis1-Vorlauf','°C'],22=>['Temperaturen','Mischkreis1 VL-Soll','°C'],
        24=>['Temperaturen','Mischkreis2-Vorlauf','°C'],25=>['Temperaturen','Mischkreis2 VL-Soll','°C'],
        26=>['Temperaturen','Solarkollektor','°C'],27=>['Temperaturen','Solarspeicher','°C'],28=>['Temperaturen','Externe Energ.Quelle','°C'],
        29=>['Eingänge','ASD','bool'],31=>['Eingänge','EVU','bool'],32=>['Eingänge','HD','bool'],33=>['Eingänge','MOT','bool'],
        37=>['Ausgänge','AV-Abtauventil','bool'],38=>['Ausgänge','BUP','bool'],39=>['Ausgänge','HUP','bool'],
        40=>['Ausgänge','Mischer 1 Auf','bool'],41=>['Ausgänge','Mischer 1 Zu','bool'],43=>['Ausgänge','Ventil.-BOSUP','bool'],44=>['Ausgänge','Verdichter 1','bool'],
        46=>['Ausgänge','ZIP','bool'],47=>['Ausgänge','ZUP','bool'],48=>['Ausgänge','ZWE 1','bool'],50=>['Ausgänge','ZWE 3','bool'],
        51=>['Ausgänge','FUP 2','bool'],52=>['Ausgänge','SLP','bool'],140=>['Ausgänge','FUP 3','bool'],
        56=>['Betriebsstunden',['Betriebsstd. VD1','Betriebstund. VD1'],'h'],57=>['Betriebsstunden','Impulse Verdichter 1','count'],
        60=>['Betriebsstunden','Betriebsstunden ZWE1','h'],63=>['Betriebsstunden','Betriebsstunden WP','h'],
        64=>['Betriebsstunden','Betriebsstunden Heiz.','h'],65=>['Betriebsstunden','Betriebsstunden WW','h'],66=>['Betriebsstunden','Betriebsstunden Kuehl.','h'],
        67=>['Ablaufzeiten','WP Seit','duration'],68=>['Ablaufzeiten','ZWE1 seit','duration'],70=>['Ablaufzeiten','Netzeinschaltv.','duration'],
        71=>['Ablaufzeiten','SSP-Zeit','duration'],73=>['Ablaufzeiten','VD-Stand','duration'],74=>['Ablaufzeiten','HRM-Zeit','duration'],
        75=>['Ablaufzeiten','HRW-Zeit','duration'],76=>['Ablaufzeiten','TDI seit','duration'],77=>['Ablaufzeiten','Sperre WW','duration'],
        151=>['Wärmemenge','Heizung','kWh'],152=>['Wärmemenge','Warmwasser','kWh'],154=>['Wärmemenge','Gesamt','kWh'],
        156=>['Ausgänge','AO 1','V'],157=>['Ausgänge','AO 2','V'],
        176=>['Temperaturen','Ansaug VD','°C'],178=>['Temperaturen','Überhitzung','K'],179=>['Temperaturen','Überhitzung Soll','K'],
        180=>['Eingänge','HD','bar'],181=>['Eingänge','ND','bar'],183=>['Ausgänge','HUP','%'],184=>['Ausgänge','Ventilatordrehzahl','rpm'],
        185=>['Eingänge','EVU 2','bool'],186=>['Eingänge','STB E-Stab','bool'],
        231=>['Ausgänge','Freq. aktuell','rpm'],236=>['Ausgänge','Freq. Sollwert','rpm'],244=>['Temperaturen','Vorlauf max.','°C'],254=>['Eingänge','Durchfluss','l/h']
    ];
    private const WEB_ONLY_POINTS = [
        400001 => ['ident'=>'EEV_Heizen','label'=>'EEV Heizen','field'=>['Ausgänge','EEV Heizen','%'],'type'=>'float','min'=>0,'max'=>100],
        400002 => ['ident'=>'EEV_Kuehlen','label'=>'EEV Kühlen','field'=>['Ausgänge','EEV Kühlen','%'],'type'=>'float','min'=>0,'max'=>100],
        400003 => ['ident'=>'Ventil_BOSUP','label'=>'Ventil.-BOSUP','field'=>['Ausgänge','Ventil.-BOSUP','bool'],'type'=>'bool','profile'=>'~Switch','aliasOf'=>43],
        400004 => ['ident'=>'Analog_Ausgang_21','label'=>'AO 21','field'=>['Ausgänge','AO 21','V'],'type'=>'float','profile'=>'~Volt'],
        400005 => ['ident'=>'Analog_Ausgang_22','label'=>'AO 22','field'=>['Ausgänge','AO 22','V'],'type'=>'float','profile'=>'~Volt'],
        400006 => ['ident'=>'Freigabe_ZWE_Restzeit','label'=>'Freigabe ZWE Restzeit','field'=>['Ablaufzeiten','Freig. ZWE','duration'],'type'=>'string','profile'=>''],
        400007 => ['ident'=>'Freigabe_Kuehlung_Restzeit','label'=>'Freigabe Kühlung Restzeit','field'=>['Ablaufzeiten','Freigabe Kühlung','duration'],'type'=>'string','profile'=>''],
        400008 => ['ident'=>'Durchschnittliche_Laufzeit_Verdichter_1','label'=>'Laufzeit Ø Verdichter 1','field'=>['Betriebsstunden','Laufzeit Ø VD1','durationHM'],'type'=>'string','profile'=>''],
        400009 => ['ident'=>'Softwarestand','label'=>'Softwarestand vollständig','field'=>['Anlagenstatus','Softwarestand','text'],'type'=>'string','profile'=>''],
        400010 => ['ident'=>'Softwarestand_HZ_IO','label'=>'Softwarestand HZ/IO','field'=>['Anlagenstatus','HZ/IO','text'],'type'=>'string','profile'=>''],
        400011 => ['ident'=>'Softwarestand_ASB','label'=>'Softwarestand ASB','field'=>['Anlagenstatus','ASB','text'],'type'=>'string','profile'=>''],
        400012 => ['ident'=>'Hardware_Revision_ASB','label'=>'ASB Hardware-Revision','field'=>['Anlagenstatus','ASB HW Revision','text'],'type'=>'string','profile'=>''],
        400013 => ['ident'=>'Softwarestand_Inverter','label'=>'Inverter Softwarestand','field'=>['Anlagenstatus','Inverter SW Version','text'],'type'=>'string','profile'=>''],
        400014 => ['ident'=>'Abtaubedarf','label'=>'Abtaubedarf','field'=>['Anlagenstatus','Abtaubedarf','%'],'type'=>'float','min'=>0,'max'=>100],
        400015 => ['ident'=>'Letzte_Abtauung','label'=>'Letzte Abtauung','field'=>['Anlagenstatus','Letzte Abt.','text'],'type'=>'string','profile'=>''],
        400016 => ['ident'=>'Waermemengenzaehler_Zusatzwaermeerzeuger','label'=>'Wärmemenge Zusatzwärmeerzeuger','field'=>['Wärmemenge','Zus. Wärmeerz','kWh'],'type'=>'float','profile'=>'~Electricity'],
        400017 => ['ident'=>'Waermemengenzaehler_Seit_Datum','label'=>'Wärmemenge seit Regler-Startdatum','field'=>['Wärmemenge','seit:','kWh'],'namePattern'=>'/^seit\s*:/iu','type'=>'float','profile'=>'~Electricity'],
        400018 => ['ident'=>'Waermemengenzaehler_Seit_Reset','label'=>'Wärmemenge seit Regler-Reset','field'=>['Wärmemenge','seit Reset:','kWh'],'type'=>'float','profile'=>'~Electricity'],
        400019 => ['ident'=>'GLT_Art','label'=>'GLT Art','field'=>['GLT','Art','text'],'type'=>'string','profile'=>''],
        400020 => ['ident'=>'Fehlerspeicher_Meldungen','label'=>'Fehlerspeicher Meldungen','field'=>['Fehlerspeicher','Meldungen','fault'],'type'=>'string','profile'=>'']
    ];

    private function WebFieldKey(string $value): string
    {
        $key=preg_replace('/[\s_.-]+/u','',strtolower(strtr(trim($value),['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü','ß'=>'ss'])));
        return str_replace('betriebstunden','betriebsstunden',$key);
    }

    private function WebDefinitions(): array
    {
        $result=self::WEB_ONLY_POINTS;
        $result[110351]=['ident'=>'SHI_Pumpe_Mischkreis_1','label'=>'Pumpe Mischkreis 1','field'=>['Ausgänge','FUP 1','bool'],'type'=>'bool','profile'=>'~Switch','sharedSHI'=>true];
        foreach(self::WEB_NATIVE_FIELDS as $id=>$field) $result[$id]=['ident'=>self::DATA_POINT_CONFIG[$id]['name'],
            'label'=>self::DATA_POINT_CONFIG[$id]['name'],'field'=>$field,'native'=>$id];
        foreach(range(95,105) as $id) $result[$id]=['ident'=>self::DATA_POINT_CONFIG[$id]['name'],
            'label'=>self::DATA_POINT_CONFIG[$id]['name'],'field'=>['Fehlerspeicher','','fault'],'native'=>$id];
        return $result;
    }

    private function MapWebSnapshot(array $snapshot): array
    {
        $values=[];
        foreach($this->WebDefinitions() as $id=>$definition) {
            if(!isset($definition['field'])) continue;
            [$menu,$name,$unit]=$definition['field'];
            $matches=[];
            foreach($snapshot['groups']??[] as $group) {
                if($this->WebFieldKey($group['name'])!==$this->WebFieldKey($menu)) continue;
                foreach($group['fields'] as $field) {
                    if(isset($definition['namePattern']) ? !preg_match($definition['namePattern'],$field['name'])
                        : !in_array($this->WebFieldKey($field['name']),array_map($this->WebFieldKey(...),(array)$name),true)) continue;
                    $text=str_replace([',',"\u{00a0}","\u{202f}"],['.',' ',' '],trim($field['raw']));
                    $value=null;
                    if($unit==='bool') $value=match(strtolower($text)) {'ein','on'=>true,'aus','off'=>false,default=>null};
                    elseif($unit==='text') $value=trim($field['raw'])!==''?trim($field['raw']):null;
                    elseif($unit==='duration' && preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/D',$text,$m)) {
                        $value=isset($definition['native'])?$this->convertValueBasedOnID((int)$m[1]*3600+(int)$m[2]*60+(int)$m[3],$id):((int)$m[1].'h '.(int)$m[2].'m');
                    } elseif($unit==='durationHM' && preg_match('/^(\d+):([0-5]\d)$/D',$text,$m)) {
                        $value=(int)$m[1].'h '.(int)$m[2].'m';
                    } elseif(!in_array($unit,['duration','durationHM','text'],true) && preg_match('/^(-?\d+(?:\.\d+)?)\s*'.($unit==='count'?'':preg_quote($unit,'/')).'$/iD',$text,$m)) {
                        $value=(float)$m[1];
                        if(($definition['type']??self::DATA_POINT_CONFIG[$id]['type'])==='int') $value=(int)$value;
                        if((isset($definition['min']) && $value<$definition['min']) || (isset($definition['max']) && $value>$definition['max'])) $value=null;
                    }
                    if($value!==null) {
                        if($unit==='bool' && isset($definition['native']) && self::DATA_POINT_CONFIG[$id]['type']==='string') $value=(string)(int)$value;
                        $matches[]=['value'=>$value,'webId'=>$field['id'],'formatted'=>str_starts_with($unit,'duration')?(string)$value:$field['raw'],
                            'unit'=>in_array($unit,['bool','duration','durationHM','count','text'],true)?'':$unit];
                    }
                }
            }
            if(count($matches)===1) $values[$definition['ident']]=$matches[0];
        }
        return $values+$this->MapWebFaults($snapshot);
    }

    private function WebFaultRows(array $snapshot): ?array
    {
        foreach($snapshot['groups']??[] as $group) {
            if($this->WebFieldKey($group['name'])!==$this->WebFieldKey('Fehlerspeicher')) continue;
            $rows=[];
            foreach($group['fields'] as $field) {
                if(!preg_match('/^\d{2}\.\d{2}\.\d{2}(?:\d{2})? \d{2}:\d{2}:\d{2}$/D',$field['name'])) continue;
                $code=preg_match('/\((\d{3,5})\)\s*$/u',$field['raw'],$match)?(int)$match[1]:null;
                $rows[]=['date'=>$field['name'],'code'=>$code,'text'=>$field['raw'],'webId'=>$field['id']];
            }
            return $group['fields']!==[] && $rows===[]?null:array_slice($rows,0,5);
        }
        return null;
    }

    private function MapWebFaults(array $snapshot): array
    {
        $rows=$this->WebFaultRows($snapshot);
        if($rows===null) return [];
        try {$timezone=new DateTimeZone($this->ReadPropertyString('WebSocketTimezone')?:date_default_timezone_get());}
        catch(Throwable) {$timezone=null;}
        $values=[];
        foreach(range(0,4) as $i) {
            $row=$rows[$i]??null;
            if($row===null || $row['code']!==null) $values[self::DATA_POINT_CONFIG[100+$i]['name']]=[
                'value'=>(string)($row['code']??0),'formatted'=>(string)($row['code']??0),'unit'=>'','webId'=>$row['webId']??''];
            $timestamp=$row===null?0:null;
            if($row!==null && $timezone!==null) {
                $format=strlen($row['date'])===17?'!d.m.y H:i:s':'!d.m.Y H:i:s';
                $date=DateTimeImmutable::createFromFormat($format,$row['date'],$timezone);
                $errors=DateTimeImmutable::getLastErrors();
                if($date!==false && ($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0))) $timestamp=$date->getTimestamp();
            }
            if($timestamp!==null) $values[self::DATA_POINT_CONFIG[95+$i]['name']]=[
                'value'=>$timestamp,'formatted'=>$row['date']??'','unit'=>'','webId'=>$row['webId']??''];
        }
        $values['Anzahl_der_Fehler']=['value'=>(string)count($rows),'formatted'=>(string)count($rows),'unit'=>'','webId'=>''];
        $text=implode("\n",array_map(static fn($row)=>$row['date'].' · '.$row['text'],$rows));
        $values['Fehlerspeicher_Meldungen']=['value'=>$text,'formatted'=>$text,'unit'=>'','webId'=>''];
        return $values;
    }

    private function GetFaultHistoryData(): array
    {
        if($this->GetWebEEVPoint('Fehlerspeicher_Meldungen')!==null) return ['available'=>true,'source'=>'WebSocket',
            'entries'=>$this->WebFaultRows($this->WebEEVCache()['discovery']??[])??[]];
        $rows=[];
        $available=$this->GetCachedVisualizationPoint('Anzahl_der_Fehler')!==null;
        foreach(range(0,4) as $i) {
            $code=$this->GetCachedVisualizationPoint(self::DATA_POINT_CONFIG[100+$i]['name']);
            $time=$this->GetCachedVisualizationPoint(self::DATA_POINT_CONFIG[95+$i]['name']);
            if($code===null || (int)$code['value']===0) continue;
            $rows[]=['date'=>$time!==null && (int)$time['value']>0?date('d.m.y H:i:s',(int)$time['value']):'',
                'code'=>(int)$code['value'],'text'=>'Fehlercode '.(int)$code['value']];
        }
        return ['available'=>$available,'source'=>$available?'Java':null,'entries'=>$rows];
    }

    private function WebEEVSourceKey(): string
    {
        return hash('sha256',json_encode([$this->VisualizationSourceKey(),$this->ReadPropertyString('WebSocketPassword'),$this->ReadPropertyString('WebSocketTimezone')],JSON_THROW_ON_ERROR));
    }

    protected function NewWebEEVClient(int $TimeoutMs = 2000): LuxtronikWebEEVClient
    {
        return new LuxtronikWebEEVClient($this->ReadPropertyString('IPAddress'),8214,$TimeoutMs);
    }

    private function WebEEVCache(): array
    {
        $cache=json_decode($this->GetBuffer('WebEEVCache'),true);
        return is_array($cache) && ($cache['source']??'')===$this->WebEEVSourceKey() ? $cache : [];
    }

    private function WebInventory(): array
    {
        $inventory=json_decode($this->GetBuffer('WebInventory'),true)??[];
        return ($inventory['source']??'')===$this->WebEEVSourceKey()?$inventory:[];
    }

    /** Availability is based on the complete Java frame, not only values retained for the graphic. */
    private function WebPrimaryHasValue(array $definition): bool
    {
        $id=$this->MeasurementIDForIdent($definition['ident']);
        return $id!==null && !$this->ShouldReadMeasurement($id,'websocket');
    }

    /** Only requested objects and graphic readings that the preferred source cannot supply. */
    private function WebRequestedDefinitions(): array
    {
        $needed=$this->HybridMeasurementIDs()+$this->GetVisualizationExtraPointIDs();
        $needed[400001]=true;
        $needed[400002]=true;
        $needed[400020]=true;
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row)
            if(!empty($row['enabled'])) $needed[(int)$row['id']]=true;
        $requested=[];
        foreach($this->WebDefinitions() as $id=>$definition)
            if(isset($needed[$id]) && !$this->WebPrimaryHasValue($definition)) $requested[$definition['ident']]=$definition;
        return $requested;
    }

    private function UpdateWebEEV(): void
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus')
            || in_array($this->ReadPropertyString('IPAddress'),['','0.0.0.0','::'],true)) return;
        $source=$this->WebEEVSourceKey();
        $requested=$this->WebRequestedDefinitions();
        $idents=array_keys($requested);
        $pages=array_values(array_unique(array_map(static fn($point)=>$point['field'][0],$requested)));
        $cache=$this->WebEEVCache();
        $inventory=$this->WebInventory();
        $discover=time()>=($inventory['nextAttempt']??0);
        if (!$discover && microtime(true)<($cache['nextAttempt']??0) && (!empty($cache['error']) || ($cache['requestedIdents']??null)===$idents)) {
            $cache['values']=array_intersect_key($cache['values']??[],$requested);
            $this->SetBuffer('WebEEVCache',json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            $this->PublishWebEEV();return;
        }
        $lock='WPLUX.WebEEV.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1)) return;
        try {
            $cache=['source'=>$source,'updatedAt'=>time(),'values'=>[],'error'=>null,'requestedIdents'=>$idents];
            try {
                $values=[];
                if($requested!==[] || $discover) {
                    $client=$this->NewWebEEVClient();
                    $values=$client->read($this->ReadPropertyString('WebSocketPassword'),$discover?null:$pages);
                    $cache['discovery']=$client->snapshot();
                    $values+=$this->MapWebSnapshot($cache['discovery']);
                    if($discover && $source===$this->WebEEVSourceKey()) {
                        $inventory=['source'=>$source,'updatedAt'=>time(),'discovery'=>$cache['discovery'],
                            'mappedCount'=>count($values),'nextAttempt'=>time()+(!empty($cache['discovery']['complete'])?86400:300)];
                        $this->SetBuffer('WebInventory',json_encode($inventory,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
                    }
                } else $cache['discovery']=['count'=>0,'groups'=>[],'errors'=>[],'complete'=>true,'fullScan'=>false,'requestedPages'=>[]];
                $values=array_intersect_key($values,$requested);
                $cache['discovery']['mappedCount']=count($values);
                if ($source!==$this->WebEEVSourceKey()) return;
                $cache['values']=$values;
                if ($values===[] && $requested!==[]) $cache['error']='Keine angeforderten WebSocket-Messwerte geliefert.';
            } catch (Throwable $error) {
                if($error instanceof LuxtronikInstanceUnavailable) throw $error;
                $cache['error']=$error->getMessage();
                if($discover && $source===$this->WebEEVSourceKey()) {
                    $inventory['source']=$source;$inventory['nextAttempt']=time()+300;$inventory['error']=$cache['error'];
                    $this->SetBuffer('WebInventory',json_encode($inventory,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
                }
            }
            $cache['nextAttempt']=microtime(true)+($cache['error']!==null?120:5);
            if ($source!==$this->WebEEVSourceKey()) return;
            $this->SetBuffer('WebEEVCache',json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
            if ($cache['error']!==null) $this->SendDebug('WebSocket',$cache['error'],0);
            foreach ($cache['values'] as $ident=>$row) {
                $this->SendDebug('Datenquelle',$ident.': WebSocket ('.$row['webId'].') = '.json_encode($row['value']),0);
            }
            $this->PublishWebEEV();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function GetWebEEVPoint(string $ident): ?array
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus')) return null;
        $cache=$this->WebEEVCache();
        $row=$cache['values'][$ident]??null;
        if ($row===null || time()-($cache['updatedAt']??0)>max(30,2*$this->ReadPropertyInteger('UpdateInterval')+5)) return null;
        return ['variableId'=>0,'ident'=>$ident,'value'=>$row['value'],'formatted'=>$row['formatted']??(number_format($row['value'],1,'.','').' %'),
            'unit'=>$row['unit']??'%','binary'=>is_bool($row['value']),'source'=>'WebSocket','webId'=>$row['webId']];
    }

    private function PublishWebEEV(): void
    {
        $this->PublishMeasurements();
    }

    private function EnsureWebVariable(int $id,array $point): void
    {
        if(@$this->GetIDForIdent($point['ident'])===false) {
            if(isset($point['native'])) {
                $native=self::DATA_POINT_CONFIG[$point['native']];
                $method=match($native['type']) {'bool'=>'RegisterVariableBoolean','int'=>'RegisterVariableInteger','float'=>'RegisterVariableFloat',default=>'RegisterVariableString'};
                $this->$method($point['ident'],$point['label'],$native['profile'],$point['native']);
            } elseif(isset($point['profile'])) {
                $method=match($point['type']) {'bool'=>'RegisterVariableBoolean','int'=>'RegisterVariableInteger','string'=>'RegisterVariableString',default=>'RegisterVariableFloat'};
                $this->$method($point['ident'],$point['label'],$point['profile'],$id);
            } else {
                if(!IPS_VariableProfileExists('WPLUX.EEV.Percent')) {
                    IPS_CreateVariableProfile('WPLUX.EEV.Percent',2);
                    IPS_SetVariableProfileValues('WPLUX.EEV.Percent',0,100,0.1);
                    IPS_SetVariableProfileDigits('WPLUX.EEV.Percent',1);
                    IPS_SetVariableProfileText('WPLUX.EEV.Percent','',' %');
                }
                $this->RegisterVariableFloat($point['ident'],$point['label'],'WPLUX.EEV.Percent',$id);
            }
        }
        if(isset(self::WEB_ONLY_POINTS[$id])) IPS_SetPosition($this->GetIDForIdent($point['ident']),$id);
    }

    private function SyncWebVariableSelection(): void
    {
        $selected=[];
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) if(!empty($row['enabled'])) $selected[(int)$row['id']]=true;
        foreach(self::WEB_ONLY_POINTS as $id=>$point) if(!isset($selected[$id])) $this->DeleteVariableIfExists($point['ident']);
    }

    public function GetWebEEVData(): string
    {
        $cache=$this->WebEEVCache();
        unset($cache['source']);
        $inventory=$this->WebInventory();unset($inventory['source']);
        $cache['inventory']=$inventory;
        return json_encode($cache,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    }

    public function GetWebSocketData(): string
    {
        return $this->GetWebEEVData();
    }

    /** Explicit full server-side read, independent of the visualization and selected measurement objects. */
    public function RunWebSocketDiagnostic(): string
    {
        $report=['source'=>'WebSocket / Lux_WS','capturedAt'=>gmdate('c'),'ok'=>false];
        $lock='WPLUX.WebEEV.'.$this->InstanceID;
        $entered=false;
        try {
            if(!$this->ReadPropertyBoolean('InstanceStatus')) throw new RuntimeException('Die Instanz ist deaktiviert.');
            $entered=IPS_SemaphoreEnter($lock,1);
            if(!$entered) throw new RuntimeException('Eine WebSocket-Abfrage läuft bereits.');
            $source=$this->WebEEVSourceKey();
            $client=$this->NewWebEEVClient(5000);
            $eev=$client->read($this->ReadPropertyString('WebSocketPassword'));
            $snapshot=$client->snapshot();
            $values=$eev+$this->MapWebSnapshot($snapshot);
            $report+=['count'=>$snapshot['count'],'groups'=>$snapshot['groups'],'errors'=>$snapshot['errors'],
                'mappedCount'=>count($values),'eev'=>$eev,'deviceTimezone'=>$this->ReadPropertyString('WebSocketTimezone')?:date_default_timezone_get()];
            $report['ok']=!empty($snapshot['complete']);
            if($source===$this->WebEEVSourceKey()) $this->SetBuffer('WebInventory',json_encode([
                'source'=>$source,'updatedAt'=>time(),'discovery'=>$snapshot,'mappedCount'=>count($values),
                'nextAttempt'=>time()+($report['ok']?86400:300)],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        } catch(Throwable $error) {
            if($error instanceof LuxtronikInstanceUnavailable) throw $error;
            $report['error']=$error->getMessage();
        } finally {
            if($entered) IPS_SemaphoreLeave($lock);
        }
        $this->UpdateFormField('WebInventoryStatus','caption',isset($report['count'])?'Bestandsabfrage am '.date('d.m.Y H:i:s').($report['ok']?' abgeschlossen.':' teilweise verfügbar; Details im Bericht.'):'Bestandsabfrage: '.($report['error']??'fehlgeschlagen'));
        return json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Current source, not protocol capability or an old object value. */
    private function CurrentPointSource(int $id): string
    {
        if(!$this->ReadPropertyBoolean('InstanceStatus')) return 'Instanz deaktiviert';
        $reading=$this->MeasurementReading($id);
        if($reading!==null) return $reading['source']==='Modbus'?'Modbus/SHI':$reading['source'];
        // The complete Java frame can include unselected fields that are not retained in the live cache.
        $ident=$this->MeasurementIdent($id);
        if($ident!==null && $this->JavaHasMeasurement($ident)) return 'Java';
        return 'Keine aktuellen Daten';
    }

    private function AddWebEEVForm(array $form): array
    {
        $panel=['type'=>'ExpansionPanel','caption'=>'WebSocket-Ergänzung','items'=>[
            ['type'=>'Label','caption'=>'Ab Firmware 3.81, Port 8214 (Lux_WS). Fehlende benötigte Werte werden automatisch ergänzt, auch bei einem Java-Ausfall.'],
            ['type'=>'PasswordTextBox','name'=>'WebSocketPassword','caption'=>'Webinterface-Passwort'],
            ['type'=>'Label','caption'=>'Vollständige Bestandserfassung beim ersten Kontakt und täglich; im normalen Zyklus nur benötigte fehlende Werte. Zusatzvariablen werden nach Auswahl zyklisch gelesen.']
        ]];
        $cache=$this->WebEEVCache();
        $interval=$this->ReadPropertyInteger('UpdateInterval');
        $panel['items'][]=['type'=>'Label','caption'=>$interval>0?'Abfrage im normalen Aktualisierungszyklus (alle '.$interval.' Sekunden).':'Abfrage bei jeder manuellen Aktualisierung; der Aktualisierungstimer ist ausgeschaltet.'];
        $panel['items'][]=['type'=>'Label','caption'=>isset($cache['updatedAt'])?'Letzte WebSocket-Abfrage: '.date('d.m.Y H:i:s',$cache['updatedAt']):'Noch keine WebSocket-Abfrage durchgeführt.'];
        $inventory=$this->WebInventory();
        $panel['items'][]=['type'=>'Label','name'=>'WebInventoryStatus','caption'=>isset($inventory['updatedAt'])?'Letzte Bestandsabfrage: '.date('d.m.Y H:i:s',$inventory['updatedAt']).(empty($inventory['discovery']['complete'])?' (noch unvollständig)':''):'Die vollständige Bestandserfassung erfolgt beim ersten Kontakt und danach täglich.'];
        $panel['items'][]=['type'=>'ExpansionPanel','caption'=>'Diagnose','items'=>[
            ['type'=>'Button','caption'=>'Alle WebSocket-Werte prüfen und Bericht herunterladen','download'=>'luxtronik-websocket-diagnose.json',
                'onClick'=>'echo "data:application/json;base64," . base64_encode(WPLUX_RunWebSocketDiagnostic($id));'],
            ['type'=>'ValidationTextBox','name'=>'WebSocketTimezone','caption'=>'Zeitzone des Reglers (leer = Symcon)'],
            ['type'=>'Label','caption'=>'Die Diagnose liest alle Informationsseiten mit den gespeicherten Zugangsdaten. Fehlerdatum und Zeitstempel verwenden die angegebene Zeitzone, sonst die Symcon-Zeitzone.']]];
        if(!empty($cache['error'])) $panel['items'][]=['type'=>'Label','caption'=>'WebSocket: '.$cache['error']];
        $index=count($form['elements']);
        $saved=[];
        foreach(json_decode($this->ReadPropertyString('IDListe'),true)??[] as $row) $saved[(int)($row['id']??0)]=!empty($row['enabled']);
        foreach($form['elements'] as $i=>&$element) {
            if(($element['name']??'')!=='IDListe') continue;
            $index=$i;
            foreach($element['columns'] as &$column) if(($column['name']??'')==='interface') {
                $column['caption']='Verfügbar über';$column['width']='230px';
            }
            unset($column);
            $element['columns'][]=['name'=>'preferredSource','caption'=>'Bevorzugte Quelle','width'=>'180px','save'=>true,
                'edit'=>['type'=>'Select','options'=>[
                    ['caption'=>'Java (Standard)','value'=>'java'],
                    ['caption'=>'WebSocket','value'=>'websocket'],
                    ['caption'=>'Modbus/SHI','value'=>'modbus']]]];
            $element['columns'][]=['name'=>'source','caption'=>'Aktuelle Quelle','width'=>'160px','save'=>false];
            foreach($element['columns'] as &$column) if(isset($column['width']) && ctype_digit((string)$column['width'])) $column['width'].='px';
            unset($column);
            $definitions=$this->WebDefinitions();
            foreach($element['values'] as &$row) if(isset($definitions[(int)$row['id']])) $row['interface'].=' + WebSocket';
            unset($row);
            foreach(self::WEB_ONLY_POINTS as $id=>$point) if(empty($point['aliasOf']) || !empty($saved[$id])) $element['values'][]=[
                'enabled'=>$saved[$id]??false,'id'=>$id,'name'=>$point['label'],'interface'=>'WebSocket'];
            foreach($element['values'] as &$row) {
                $row['preferredSource']=$this->MeasurementSourceOrder((int)$row['id'])[0];
                $row['source']=$this->CurrentPointSource((int)$row['id']);
            }
            unset($row);
        }
        unset($element);
        array_splice($form['elements'],$index,0,[$panel,
            ['type'=>'Label','caption'=>'Bevorzugte Quelle gilt für die unter Verfügbar über zugeordneten Schnittstellen. Ohne passende Zuordnung wird die Auswahl übersprungen. Bei fehlenden Werten ergänzen die übrigen Quellen automatisch; 0 und Aus sind gültige Werte. Die Auswahl einer Lesequelle sendet keine Schreibbefehle.']]);
        return $form;
    }
}
