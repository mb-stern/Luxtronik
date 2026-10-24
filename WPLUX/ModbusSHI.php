<?php

require_once __DIR__ . '/ModbusTCP.php';

/** Optional SHI implementation; the existing Java implementation is independent. */
trait LuxtronikModbusSHI
{
    // SHI manual 83026900aDE, pp. 22–28. Key = existing Java ID, never a new ident.
    // Address, datatype, engineering-unit factor. 32-bit values occupy two words.
    private const SHI_DATA_POINTS = [
        10 => [10105, 'uint16', 0.1],
        11 => [10100, 'uint16', 0.1],
        12 => [10101, 'uint16', 0.1],
        13 => [10102, 'uint16', 0.1],
        15 => [10108, 'int16', 0.1],
        16 => [10109, 'int16', 0.1],
        17 => [10120, 'int16', 0.1],
        18 => [10121, 'uint16', 0.1],
        19 => [10110, 'int16', 0.1],
        20 => [10111, 'int16', 0.1],
        21 => [10140, 'int16', 0.1],
        22 => [10141, 'int16', 0.1],
        24 => [10150, 'int16', 0.1],
        25 => [10151, 'int16', 0.1],
        151 => [10322, 'int32', 0.1],
        152 => [10324, 'int32', 0.1],
        153 => [10328, 'int32', 0.1],
        154 => [10320, 'int32', 0.1],
        189 => [10113, 'int16', 0.1],
        227 => [10106, 'int16', 0.1],
        257 => [10300, 'int16', 0.1],
        268 => [10301, 'uint16', 100.0] // kW/10 -> existing ident Leistungsaufnahme in watts
    ];

    private function ApplyChangesModbus(): void
    {
        // Preserve control variables, timer variables and their configuration/IDs.
        $controls = ['Mode_Heizung', 'Mode_WW', 'Mode_Kuehlung', 'Mode_Schwimmbad',
            'Anpassung_Temp', 'Anpassung_WW', 'Anpassung_RBE'];
        for ($id = 223; $id <= 505; ++$id) {
            $controls[] = 'set_' . $id;
        }
        foreach ($controls as $ident) {
            if (@$this->GetIDForIdent($ident) !== false) {
                $this->DisableAction($ident);
            }
        }
        if (!$this->ReadPropertyBoolean('InstanceStatus')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $this->CreateVariableProfiles();
        // The configured external meters retain exactly the Java COP/JAZ semantics.
        foreach (['kwin' => 'copfaktor', 'kwhin' => 'jazfaktor'] as $property => $ident) {
            $meter = $this->ReadPropertyInteger($property);
            if ($meter !== 0 && IPS_VariableExists($meter) && @$this->GetIDForIdent($ident) === false) {
                $this->RegisterVariableFloat($ident, $ident === 'copfaktor' ? 'COP-Faktor' : 'JAZ-Faktor', 'WPLUX.Cop', $ident === 'copfaktor' ? 7 : 8);
            }
        }
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        $this->UpdateModbus();
    }

    private function UpdateModbus(): void
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus')) {
            return;
        }
        $rows = json_decode($this->ReadPropertyString('IDListe'), true);
        $selected = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && (!empty($row['enabled']) || !empty($row['visualizationFooter']))) {
                $selected[(int)($row['id'] ?? 0)] = true;
            }
        }
        $requested = array_intersect_key(self::SHI_DATA_POINTS, $selected);
        if ($this->ReadPropertyInteger('kwin') !== 0) {
            $requested[257] = self::SHI_DATA_POINTS[257];
        }
        if ($this->ReadPropertyInteger('kwhin') !== 0) {
            $requested[151] = self::SHI_DATA_POINTS[151];
            $requested[152] = self::SHI_DATA_POINTS[152];
        }
        // Use only confirmed SHI mappings for the graphic; never fall back to Java.
        $graphicPoints = array_intersect_key($this->GetVisualizationRequiredPointIDs(), self::SHI_DATA_POINTS);
        foreach ($graphicPoints as $id => $_) {
            $requested[$id] = self::SHI_DATA_POINTS[$id];
        }
        $unsupported = array_keys(array_diff_key($selected, self::SHI_DATA_POINTS));
        if ($unsupported !== []) {
            $this->SendDebug('SHI', 'Ohne SHI-Zuordnung; bestehende Werte bleiben unverändert: ' . implode(', ', $unsupported), 0);
        }
        if ($requested === []) {
            $this->SetStatus(203);
            return;
        }
        $values = [];
        $unavailable = [];
        $client = null;
        try {
            $client = new LuxtronikModbusTCP(
                $this->ReadPropertyString('IPAddress'),
                $this->ReadPropertyInteger('ModbusPort'),
                $this->ReadPropertyInteger('ModbusUnitID'),
                $this->ReadPropertyInteger('ModbusTimeout')
            );
            foreach ($requested as $id => [$address, $type, $factor]) {
                try {
                    $words = $client->readInputRegisters($address, $type === 'int32' ? 2 : 1);
                } catch (LuxtronikModbusException $error) {
                    // Some controllers do not expose every optional SHI register.
                    if ($error->getCode() !== 2 && $error->getCode() !== 3) {
                        throw $error;
                    }
                    $unavailable[] = $id;
                    $this->SendDebug('SHI', "ID $id, Input-Register $address: " . $error->getMessage(), 0);
                    continue;
                }
                $value = $this->DecodeModbusValue($words, $type, $factor);
                if ($value === null) {
                    $unavailable[] = $id;
                    $this->SendDebug('SHI', "ID $id, Input-Register $address: nicht verfügbar (Ersatzwert)", 0);
                    continue;
                }
                $values[$id] = $value;
                $this->SendDebug('SHI', "ID $id, Input-Register $address: $value", 0);
            }
        } catch (InvalidArgumentException $error) {
            $this->LogMessage($error->getMessage(), KL_ERROR);
            $this->SetStatus(202);
            return;
        } catch (RuntimeException $error) {
            $this->LogMessage('SHI: ' . $error->getMessage(), KL_ERROR);
            $this->SendDebug('SHI', $error->getMessage(), 0);
            $this->SetStatus(201);
            return; // Incomplete scans do not overwrite any existing readings.
        } finally {
            if ($client !== null) {
                $client->close();
            }
        }
        $this->StoreVisualizationPoints($values, true);
        foreach ($values as $id => $value) {
            if (!isset($selected[$id])) {
                continue;
            }
            $ident = self::DATA_POINT_CONFIG[$id]['name'];
            if (@$this->GetIDForIdent($ident) === false) {
                $this->CreateOrUpdateVariable($ident, $value, $id);
            } else {
                // Do not re-register: preserve object ID, type, name, profile and links.
                $this->SetValueIfChanged($ident, $value);
            }
        }
        if (isset($values[257])) {
            $this->calc_cop('cop', $values[257]);
        }
        if (isset($values[151], $values[152])) {
            $this->calc_jaz('jaz', $values[151] + $values[152]);
        }
        $this->SetStatus($values === [] ? 203 : ($unavailable === [] ? 102 : 204));
        $this->RefreshVisualization();
    }

    private function DecodeModbusValue(array $words, string $type, float $factor): ?float
    {
        $raw = $type === 'int32' ? ($words[0] << 16) | $words[1] : $words[0];
        // The SHI manual specifies 32767 for unavailable optional data, even INT32.
        if ($raw === 32767 || ($type === 'int32' && $raw === 2147483647)) {
            return null;
        }
        if ($type === 'int16' && $raw >= 32768) {
            $raw -= 65536;
        } elseif ($type === 'int32' && $raw >= 2147483648) {
            $raw -= 4294967296;
        }
        return $raw * $factor;
    }

    private function AddModbusForm(array $form): array
    {
        $modbus = $this->ReadPropertyString('CommunicationMode') === 'modbus';
        array_splice($form['elements'], 1, 0, [[
            'type' => 'Select', 'name' => 'CommunicationMode', 'caption' => 'Kommunikation',
            'options' => [
                ['caption' => 'Java (Standard)', 'value' => 'java'],
                ['caption' => 'Modbus TCP / SHI (Testversion, nur lesen)', 'value' => 'modbus']
            ]
        ]]);
        $form['elements'][] = [
            'type' => 'ExpansionPanel', 'caption' => 'Modbus TCP / SHI',
            'items' => [
                ['type' => 'Label', 'caption' => 'Nur bei Modbus: Luxtronik 2.1 ab V3.90.1 mit aktivem SHI. IP-Adresse wie oben. Java-Einstellungen bleiben erhalten.'],
                ['type' => 'NumberSpinner', 'name' => 'ModbusPort', 'caption' => 'Modbus-Port'],
                ['type' => 'NumberSpinner', 'name' => 'ModbusUnitID', 'caption' => 'Modbus-Geräteadresse (Unit-ID)'],
                ['type' => 'NumberSpinner', 'name' => 'ModbusTimeout', 'caption' => 'Modbus-Timeout (Millisekunden, 100–5000)']
            ]
        ];
        if ($modbus) {
            foreach ($form['elements'] as &$element) {
                if (($element['name'] ?? '') === 'Port') {
                    $element['visible'] = false;
                }
                if (($element['caption'] ?? '') === 'Timer / Zusatzfunktionen') {
                    $element['visible'] = false;
                }
                if (($element['name'] ?? '') === 'IDListe') {
                    $element['columns'][] = ['name' => 'shi', 'caption' => 'SHI-Unterstützung', 'width' => '300', 'save' => false];
                    foreach ($element['values'] as &$row) {
                        $mapping = self::SHI_DATA_POINTS[$row['id']] ?? null;
                        $row['shi'] = $mapping === null ? 'Nicht unterstützt; letzter Wert bleibt erhalten' : 'Input-Register ' . $mapping[0];
                    }
                    unset($row);
                }
            }
            unset($element);
            array_unshift($form['elements'], [
                'type' => 'Label',
                'caption' => 'SHI-Testbetrieb: ausschließlich lesend. Idents und vorhandene Variablen bleiben erhalten. Nicht unterstützte oder nicht verfügbare Werte werden nicht aktualisiert. Steuerung und Timer sind deaktiviert.'
            ]);
        }
        $form['status'] = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Instanz aktiv'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Instanz deaktiviert'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'SHI-Verbindung oder Antwort fehlerhaft'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'SHI-Verbindungseinstellungen ungültig'],
            ['code' => 203, 'icon' => 'error', 'caption' => 'Keine verfügbaren SHI-Messwerte ausgewählt oder geliefert'],
            ['code' => 204, 'icon' => 'error', 'caption' => 'SHI teilweise verfügbar; einzelne Werte bleiben unverändert']
        ];
        return $form;
    }
}
