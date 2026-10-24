<?php

// Includes the unchanged Java regression cases as well as the SHI cases below.
require __DIR__ . '/regression.php';

function modbusFrame(int $transaction, int $unit, array $words): string
{
    return pack('nnnCCCa*', $transaction, 0, 3 + count($words) * 2, $unit, 4, count($words) * 2,
        $words === [] ? '' : pack('n*', ...$words));
}

/** One persistent TCP connection per SHI scan; every captured request must be FC04. */
function withModbus(array $exchanges, callable $test, ?Luxtronik $existing = null, int $unit = 1): void
{
    // A successful scan now also reads the graphic's core points without IDListe.
    // Keep explicit protocol/error cases first; append deterministic FC04 fixtures.
    if ($exchanges === [] || is_array($exchanges[array_key_last($exchanges)][2])) {
        $fixture = $existing ?? new Luxtronik();
        $mappings = (new ReflectionClass($fixture))->getConstant('SHI_DATA_POINTS');
        $covered = array_column($exchanges, 0);
        foreach (invoke($fixture, 'GetVisualizationRequiredPointIDs') as $id => $_) {
            if (!isset($mappings[$id])) continue;
            [$address, $type] = $mappings[$id];
            if (!in_array($address, $covered, true)) {
                $exchanges[] = [$address, $type === 'int32' ? 2 : 1, $type === 'int32' ? [0, 0] : [0]];
            }
        }
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    $port = (int)substr(strrchr($address, ':'), 1);
    $pid = pcntl_fork();
    check($pid !== -1, 'Cannot fork Modbus simulator');
    if ($pid === 0) {
        try {
            $connection = stream_socket_accept($listener, 8);
            check($connection !== false, 'SHI connection missing');
            stream_set_timeout($connection, 8);
            foreach ($exchanges as $i => [$register, $count, $reply]) {
                $request = '';
                while (strlen($request) < 12) {
                    $part = fread($connection, 12 - strlen($request));
                    check($part !== false && $part !== '', 'SHI request incomplete');
                    $request .= $part;
                }
                $expected = pack('nnnCCnn', $i + 1, 0, 6, $unit, 4, $register, $count);
                check($request === $expected, 'SHI must only send the expected FC04 read: ' . bin2hex($request));
                if (is_array($reply)) {
                    $frame = modbusFrame($i + 1, $unit, $reply);
                    // Split both the MBAP header and the payload to exercise TCP framing.
                    foreach ([substr($frame, 0, 3), substr($frame, 3, 5), substr($frame, 8)] as $chunk) {
                        fwrite($connection, $chunk);
                        usleep(3000);
                    }
                } elseif ($reply === null) {
                    usleep(650000);
                } else {
                    fwrite($connection, $reply);
                }
            }
            fclose($connection);
            fclose($listener);
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, 'Modbus simulator: ' . $error->getMessage() . "\n");
            exit(1);
        }
    }
    fclose($listener);
    $module = $existing ?? new Luxtronik();
    $module->properties['CommunicationMode'] = 'modbus';
    $module->properties['IPAddress'] = '127.0.0.1';
    $module->properties['ModbusPort'] = $port;
    $module->properties['ModbusUnitID'] = $unit;
    try {
        $test($module);
    } finally {
        pcntl_waitpid($pid, $status);
    }
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Modbus simulator failed');
}

$tests['Java remains the default with separate SHI connection settings'] = static function (): void {
    $module = new Luxtronik();
    check($module->properties['CommunicationMode'] === 'java' && $module->properties['Port'] === 8889, 'Java default changed');
    check($module->properties['ModbusPort'] === 502 && $module->properties['ModbusUnitID'] === 1
        && $module->properties['ModbusTimeout'] === 500, 'SHI defaults wrong');
    $form = fields(json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR));
    check($form['CommunicationMode']['options'][0]['value'] === 'java', 'Java not first option');
    $module->properties['CommunicationMode'] = 'modbus';
    $form = fields(json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR));
    $rows = array_column($form['IDListe']['values'], null, 'id');
    check($rows[15]['name'] === 'Aussentemperatur' && $rows[15]['shi'] === 'Input-Register 10108', 'Ident or mapping changed in form');
    check(str_contains($rows[14]['shi'], 'Nicht unterstützt'), 'Unsupported Java ID not marked');
    check($form['Port']['visible'] === false, 'Java port exposed in SHI mode');
};
$tests['SHI rejects all actions and deactivated updates without a Java connection'] = static function (): void {
    withoutConnection(static function (Luxtronik $module): void {
        $module->properties['CommunicationMode'] = 'modbus';
        // If Java is ever used, the listener in withoutConnection catches it.
        foreach (['Mode_WW', 'Anpassung_WW', 'set_223', 'unknown'] as $ident) {
            try {
                $module->RequestAction($ident, 50);
            } catch (RuntimeException $expected) {
                check(str_contains($expected->getMessage(), 'lesend'), 'Wrong rejection');
                continue;
            }
            throw new RuntimeException('SHI allowed an action');
        }
        $module->properties['InstanceStatus'] = false;
        $module->Update();
        $module->ApplyChanges();
        check($module->status === 104 && $module->timers['UpdateTimer'] === 0, 'Disabled SHI timer active');
    });
};
$tests['identities survive Java to SHI to Java including unavailable Java-only data'] = static function (): void {
    $values = array_fill(0, 269, 0);
    $values[14] = 650;
    $values[15] = 4294967246;
    $values[268] = 1500;
    $selected = '[{"id":14,"enabled":true},{"id":15,"enabled":true},{"id":268,"enabled":true}]';
    $existing = null;
    withServer([[pack('N*', 3004, 0), frame(3004, $values)]], static function (Luxtronik $module) use (&$existing, $selected): void {
        $module->properties['IDListe'] = $selected;
        $module->Update();
        $existing = $module;
    });
    $names = ['Heisgastemperatur', 'Aussentemperatur', 'Leistungsaufnahme'];
    $ids = array_map(fn($name) => $existing->GetIDForIdent($name), $names);
    $GLOBALS['testVariables'][$ids[1]]['name'] = 'Meine Außentemperatur';
    $GLOBALS['testVariables'][$ids[1]]['profile'] = 'Custom.Temperature';
    $javaPort = $existing->properties['Port'];
    withModbus([[10108, 1, [65511]], [10301, 1, [18]]], static function (Luxtronik $module) use ($ids, $names, $javaPort): void {
        $module->ApplyChanges();
        check($module->status === 102, 'SHI scan failed');
        check(GetValue($ids[0]) === 65.0, 'Java-only value overwritten');
        check(GetValue($ids[1]) === -2.5 && GetValue($ids[2]) === 1800.0, 'SHI scaling wrong');
        foreach ($names as $i => $name) {
            check($module->GetIDForIdent($name) === $ids[$i] && IPS_GetVariable($ids[$i])['VariableType'] === VARIABLETYPE_FLOAT, 'Variable recreated');
        }
        check($module->properties['Port'] === $javaPort, 'Java port overwritten');
        check($GLOBALS['testVariables'][$ids[1]]['name'] === 'Meine Außentemperatur'
            && $GLOBALS['testVariables'][$ids[1]]['profile'] === 'Custom.Temperature', 'Name or profile overwritten');
    }, $existing);
    $values[15] = 100;
    withServer([[pack('N*', 3004, 0), frame(3004, $values)]], static function (Luxtronik $listenerModule) use ($existing, $values, $names, $ids): void {
        $existing->properties['CommunicationMode'] = 'java';
        $existing->properties['Port'] = $listenerModule->properties['Port'];
        $existing->Update();
        check(GetValue($ids[1]) === 10.0, 'Java did not resume');
        foreach ($names as $i => $name) {
            check($existing->GetIDForIdent($name) === $ids[$i], 'Switching back changed an ID');
        }
    });
};
$tests['SHI ApplyChanges disables old actions without deleting controls or timers'] = static function (): void {
    withModbus([[10108, 1, [0]]], static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":15,"enabled":true}]';
        $module->properties['WarmwasserVisible'] = true;
        $module->properties['BW_TimerWeekVisible'] = 1;
        foreach (['Mode_WW', 'set_406'] as $ident) {
            $module->RegisterVariableInteger($ident, 'Existing control');
            $module->EnableAction($ident);
        }
        $modeID = $module->GetIDForIdent('Mode_WW');
        $timerID = $module->GetIDForIdent('set_406');
        $module->ApplyChanges();
        check($module->GetIDForIdent('Mode_WW') === $modeID && $module->GetIDForIdent('set_406') === $timerID, 'Control deleted');
        check(!$module->actions['Mode_WW'] && !$module->actions['set_406'], 'SHI controls still active');
        check($module->properties['WarmwasserVisible'] && $module->properties['BW_TimerWeekVisible'] === 1, 'Java control settings changed');
    });
};
$tests['all mapped values use existing names types and SHI engineering units'] = static function (): void {
    $module = new Luxtronik();
    $mappings = (new ReflectionClass($module))->getConstant('SHI_DATA_POINTS');
    $config = (new ReflectionClass($module))->getConstant('DATA_POINT_CONFIG');
    check(count($mappings) === 22, 'Unexpected SHI coverage');
    $exchanges = [];
    foreach ($mappings as $id => [$address, $type, $factor]) {
        $exchanges[] = [$address, $type === 'int32' ? 2 : 1, $type === 'int32' ? [1, 100] : [100]];
    }
    withModbus($exchanges, static function (Luxtronik $module) use ($mappings, $config): void {
        $module->properties['IDListe'] = json_encode(array_map(fn($id) => ['id' => $id, 'enabled' => true], array_keys($mappings)));
        $module->Update();
        check($module->status === 102 && $module->errors === [], 'Full SHI scan failed');
        foreach ($mappings as $id => [$_, $type, $factor]) {
            $variableID = $module->GetIDForIdent($config[$id]['name']);
            check($variableID !== false, "Missing Java ident for ID $id");
            $raw = $type === 'int32' ? 65636 : 100;
            check(GetValue($variableID) === round($raw * $factor, $config[$id]['decimals']), "Wrong units for ID $id");
            check(IPS_GetVariable($variableID)['VariableType'] === VARIABLETYPE_FLOAT, 'Wrong variable type');
        }
    });
};
$tests['SHI sentinel and unsupported register exceptions preserve last values'] = static function (): void {
    withModbus([
        [10108, 1, [32767]],
        [10120, 1, pack('nnnCCC', 2, 0, 3, 1, 0x84, 2)],
        [10300, 1, [25]]
    ], static function (Luxtronik $module): void {
        foreach (['Aussentemperatur' => -5.0, 'Warmwasser_Ist_Temperatur' => 45.0] as $name => $value) {
            $module->RegisterVariableFloat($name, $name);
            $GLOBALS['testVariables'][$module->GetIDForIdent($name)]['value'] = $value;
        }
        $module->properties['IDListe'] = '[{"id":15,"enabled":true},{"id":17,"enabled":true},{"id":257,"enabled":true}]';
        $module->Update();
        check(GetValue($module->GetIDForIdent('Aussentemperatur')) === -5.0, 'Sentinel overwritten');
        check(GetValue($module->GetIDForIdent('Warmwasser_Ist_Temperatur')) === 45.0, 'Unsupported register overwritten');
        check(GetValue($module->GetIDForIdent('Waermeleistung')) === 2.5 && $module->status === 204, 'Partial scan lost');
    });
};
$tests['SHI keeps existing COP and JAZ IDs and external-meter semantics'] = static function (): void {
    withModbus([[10300, 1, [100]], [10322, 2, [0, 8000]], [10324, 2, [0, 4000]]], static function (Luxtronik $module): void {
        foreach (['externalKW' => 2.0, 'externalKWH' => 200.0, 'copfaktor' => 1.0, 'jazfaktor' => 1.0] as $ident => $value) {
            $module->RegisterVariableFloat($ident, $ident);
            $GLOBALS['testVariables'][$module->GetIDForIdent($ident)]['value'] = $value;
        }
        $copID = $module->GetIDForIdent('copfaktor');
        $jazID = $module->GetIDForIdent('jazfaktor');
        $module->properties['kwin'] = $module->GetIDForIdent('externalKW');
        $module->properties['kwhin'] = $module->GetIDForIdent('externalKWH');
        $module->attributes['start_kwh_in'] = 100.0;
        $module->attributes['start_value_out'] = 1000.0;
        $module->ApplyChanges();
        check($module->GetIDForIdent('copfaktor') === $copID && GetValue($copID) === 5.0, 'COP changed identity or semantics');
        check($module->GetIDForIdent('jazfaktor') === $jazID && GetValue($jazID) === 2.0, 'JAZ changed identity or semantics');
        check(GetValue($module->GetIDForIdent('Waermeleistung')) === 10.0, 'Graphic heat output missing without ID selection');
    });
};
$tests['SHI rejects malformed MBAP and PDU frames atomically'] = static function (): void {
    $bad = [
        modbusFrame(99, 1, [100]), modbusFrame(2, 2, [100]),
        pack('nnnCCn', 2, 1, 5, 1, 4, 100), // wrong protocol
        pack('nnnC', 2, 0, 65535, 1), // excessive MBAP length
        pack('nnnCCCn', 2, 0, 5, 1, 3, 2, 100), // wrong function
        pack('nnnCCCn', 2, 0, 5, 1, 4, 4, 100), // wrong byte count
        substr(modbusFrame(2, 1, [100]), 0, -1), // EOF in value
        pack('nnnCCC', 2, 0, 3, 1, 0x84, 1) // function not supported
    ];
    foreach ($bad as $reply) {
        withModbus([[10108, 1, [100]], [10120, 1, $reply]], static function (Luxtronik $module): void {
            $module->properties['IDListe'] = '[{"id":15,"enabled":true},{"id":17,"enabled":true}]';
            $module->RegisterVariableFloat('Aussentemperatur', 'Existing name');
            $id = $module->GetIDForIdent('Aussentemperatur');
            $GLOBALS['testVariables'][$id]['value'] = -1.5;
            $module->Update();
            check(GetValue($id) === -1.5 && $module->writes === 0 && $module->status === 201, 'Incomplete scan changed data');
        });
    }
};
$tests['SHI receive timeout is bounded and no Java fallback runs'] = static function (): void {
    withModbus([[10108, 1, null]], static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":15,"enabled":true}]';
        $start = microtime(true);
        $module->Update();
        $elapsed = microtime(true) - $start;
        check($elapsed >= 0.4 && $elapsed < 0.65, 'SHI timeout wrong');
        check($module->status === 201 && $module->writes === 0, 'SHI timeout not reported');
    });
};
$tests['SHI custom unit and signed 32-bit big-endian decoding work'] = static function (): void {
    withModbus([[10322, 2, [0xffff, 0xffe7]]], static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":151,"enabled":true}]';
        $module->Update();
        check(GetValue($module->GetIDForIdent('Waermemengenzaehler_Heizung')) === -2.5, 'INT32 signed decoding wrong');
    }, null, 17);
    $module = new Luxtronik();
    check(invoke($module, 'DecodeModbusValue', [0, 32767], 'int32', 0.1) === null, 'INT32 sentinel not handled');
    check(invoke($module, 'DecodeModbusValue', [32767, 65535], 'int32', 0.1) === null, 'INT32 maximum not handled');
};
$tests['SHI unsupported selections survive while independent graphic points are read'] = static function (): void {
    withModbus([], static function (Luxtronik $module): void {
        $module->properties['CommunicationMode'] = 'modbus';
        $module->properties['IDListe'] = '[{"id":14,"enabled":true},{"id":300,"enabled":true}]';
        $module->RegisterVariableFloat('Heisgastemperatur', 'Keep me');
        $id = $module->GetIDForIdent('Heisgastemperatur');
        $module->Update();
        check($module->GetIDForIdent('Heisgastemperatur') === $id && $module->status === 102, 'Unsupported value lost or graphic scan failed');
        check($module->GetIDForIdent('Aussentemperatur') !== false, 'Graphic values depend on ID selection');
        $form = fields(json_decode($module->GetConfigurationForm(), true));
        $rows = array_column($form['IDListe']['values'], null, 'id');
        check($rows[14]['enabled'] && $rows[300]['enabled'], 'Selections lost');
    });
};
$tests['invalid SHI settings fail before network activity'] = static function (): void {
    foreach (['ModbusPort' => 0, 'ModbusUnitID' => 0, 'ModbusTimeout' => 99] as $property => $value) {
        withoutConnection(static function (Luxtronik $module) use ($property, $value): void {
            $module->properties['CommunicationMode'] = 'modbus';
            $module->properties['ModbusPort'] = $module->properties['Port'];
            $module->properties[$property] = $value;
            $module->properties['IDListe'] = '[{"id":15,"enabled":true}]';
            $module->Update();
            check($module->status === 202 && count($module->errors) === 1, 'Configuration error not reported');
        });
    }
};

$tests['footer selection enables a supported SHI point without any write'] = static function (): void {
    withModbus([[10108, 1, [65511]]], static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":15,"enabled":false,"visualizationFooter":true}]';
        $module->Update();
        $id = $module->GetIDForIdent('Aussentemperatur');
        check($id !== false && GetValue($id) === -2.5, 'SHI footer selection not read');
        $footer = invoke($module, 'GetVisualizationFooter');
        check(count($footer) === 1 && $footer[0]['variableId'] === $id, 'SHI footer Ident not reused');
    });
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL $name: " . $error->getMessage() . "\n");
    }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed === 0 ? 0 : 1);
