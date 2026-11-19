<?php

// Run: php tests/regression.php (PHP CLI with sockets and pcntl).
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__ . '/symcon-stub.php';
require __DIR__ . '/../WPLUX/module.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invoke(Luxtronik $module, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($module, $method))->invoke($module, ...$arguments);
}

function ensureLegacyLuxtronikProfiles(): void
{
    $module = new Luxtronik();
    foreach ((new ReflectionClass(Luxtronik::class))->getConstant('PROFILE_DEFAULTS') as $name => $definition) {
        $GLOBALS['testProfiles'][$name] ??= invoke($module, 'VariablePresentationDefinition', $name);
    }
}

function frame(int $command, array $values): string
{
    return pack('N*', ...($command === 3004 ? [$command, 0, count($values)] : [$command, count($values)]))
        . ($values === [] ? '' : pack('N*', ...$values));
}

// Explicitly add the always-internal parameter read after a regular measurement cycle.
function controllerCycles(array $exchanges): array
{
    $cycles=[];
    foreach($exchanges as $i=>$exchange) {
        $cycles[]=$exchange;
        if($exchange[0]===pack('N*',3004,0) && ($exchanges[$i+1][0]??null)!==pack('N*',3003,0))
            $cycles[]=[pack('N*',3003,0),frame(3003,[])];
    }
    return $cycles;
}

// Real loopback connections exercise framing, fragmented reads, EOF and deadlines.
function withServer(array $exchanges, callable $test): void
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    $port = (int)substr(strrchr($address, ':'), 1);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork simulator');
    }
    if ($pid === 0) {
        try {
            foreach ($exchanges as [$request, $response]) {
                $connection = stream_socket_accept($listener, 8);
                check($connection !== false, 'Expected connection missing');
                stream_set_timeout($connection, 8);
                $data = '';
                while (strlen($data) < strlen($request)) {
                    $part = fread($connection, strlen($request) - strlen($data));
                    check($part !== false && $part !== '', 'Request incomplete');
                    $data .= $part;
                }
                check($data === $request, 'Wrong wire request: ' . bin2hex($data));
                if ($response === null) {
                    // Keep the connection open beyond the module's five-second deadline.
                    usleep(5500000);
                } else {
                    foreach ((array)$response as $chunk) {
                        $remaining = $chunk;
                        while ($remaining !== '') {
                            $written = fwrite($connection, $remaining);
                            check($written !== false && $written > 0, 'Response write failed');
                            $remaining = substr($remaining, $written);
                        }
                        if (is_array($response)) {
                            usleep(10000);
                        }
                    }
                }
                fclose($connection);
            }
            fclose($listener);
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, 'Simulator: ' . $error->getMessage() . "\n");
            exit(1);
        }
    }
    fclose($listener);
    $module = new Luxtronik();
    $module->properties['IPAddress'] = '127.0.0.1';
    $module->properties['Port'] = $port;
    try {
        $test($module);
    } finally {
        pcntl_waitpid($pid, $status);
    }
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Simulator failed');
}

function withoutConnection(callable $test): void
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    $module = new Luxtronik();
    $module->properties['IPAddress'] = '127.0.0.1';
    $module->properties['Port'] = (int)substr(strrchr($address, ':'), 1);
    try {
        $test($module);
        stream_set_blocking($listener, false);
        check(@stream_socket_accept($listener, 0) === false, 'Unexpected network connection');
    } finally {
        fclose($listener);
    }
}

function fields(array $node): array
{
    $result = [];
    if (isset($node['name'])) {
        $result[$node['name']] = $node;
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            $result = array_replace($result, fields($value));
        }
    }
    return $result;
}

$tests = [];
$tests['public SDK methods declare return types'] = static function (): void {
    foreach ((new ReflectionClass(Luxtronik::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === Luxtronik::class) {
            check($method->hasReturnType(), $method->getName() . ' lacks return type');
        }
    }
};
$tests['all six timer controls and saved custom IDs survive the form'] = static function (): void {
    $module = new Luxtronik();
    $module->properties['IDListe'] = '[{"id":300,"enabled":true},{"id":301,"enabled":false}]';
    $form = fields(json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR));
    foreach (['HZ' => 3, 'BW' => 5] as $prefix => $max) {
        foreach (['Week', 'Weekend', 'Day'] as $period) {
            $field = $form[$prefix . '_Timer' . $period . 'Visible'] ?? null;
            check($field !== null, 'Missing timer field');
            check(array_column($field['options'], 'value') === range(0, $max), 'Wrong timer options');
        }
    }
    check($form['BW_TimerWeekVisible']['caption'] === 'Timer Woche Warmwasser', 'Wrong caption');
    $rows = array_column($form['IDListe']['values'], null, 'id');
    check($rows[300]['enabled'] === true && $rows[301]['enabled'] === false, 'Custom IDs lost');
};
$tests['disabled manual updates never connect'] = static function (): void {
    withoutConnection(static function (Luxtronik $module): void {
        $module->properties['InstanceStatus'] = false;
        $module->Update();
    });
};
$tests['invalid setpoints, modes and timer values never connect'] = static function (): void {
    $invalid = [
        ['Anpassung_WW', 66], ['Anpassung_WW', 29.9], ['Anpassung_Temp', -5.1],
        ['Anpassung_Temp', 5.1], ['Anpassung_RBE', 35.1], ['Anpassung_RBE', -0.1],
        ['Mode_WW', 5], ['Mode_Heizung', 0.5], ['Mode_Kuehlung', 2],
        ['set_223', -3601], ['set_505', 82801], ['set_223', 0.5],
        ['set_223junk', 0], ['Anpassung_WW', NAN], ['Anpassung_WW', INF],
        ['Anpassung_WW', '50'], ['Anpassung_WW', []]
    ];
    withoutConnection(static function (Luxtronik $module) use ($invalid): void {
        foreach ($invalid as [$ident, $value]) {
            try {
                $module->RequestAction($ident, $value);
            } catch (InvalidArgumentException $expected) {
                continue;
            }
            throw new RuntimeException('Invalid value accepted: ' . $ident);
        }
    });
};
$tests['valid writes preserve signed scaling, boundaries and timer encoding'] = static function (): void {
    $cases = [
        ['Anpassung_WW', 30, 105, 300], ['Anpassung_WW', 65, 105, 650],
        ['Anpassung_WW', 50.1, 105, 501], ['Anpassung_Temp', -5, 1, 4294967246],
        ['Anpassung_Temp', 5, 1, 50], ['Anpassung_RBE', 0, 1148, 0],
        ['Anpassung_RBE', 35, 1148, 350], ['Mode_WW', 4, 4, 4],
        ['Mode_Kuehlung', 1, 108, 1], ['set_223', -3600, 223, 0], ['set_505', 82800, 505, 86400]
    ];
    $exchanges = [];
    foreach ($cases as [$_, $__, $parameter, $raw]) {
        $exchanges[] = [pack('N*', 3002, $parameter, $raw), pack('N*', 3002, $parameter)];
    }
    withServer($exchanges, static function (Luxtronik $module) use ($cases): void {
        foreach ($cases as [$ident, $value]) {
            check(invoke($module, 'setParameter', $ident, $value) === true, 'Valid write failed');
        }
    });
};
$tests['successful RequestAction reads back the confirmed value'] = static function (): void {
    $values = array_fill(0, 106, 0);
    $values[105] = 505; $values[18] = 505;
    withServer([
        [pack('N*', 3002, 105, 505), pack('N*', 3002, 105)],
        [pack('N*', 3004, 0), frame(3004, $values)]
    ], static function (Luxtronik $module): void {
        $module->RegisterVariableFloat('Anpassung_WW', 'Warmwasser');
        $module->RequestAction('Anpassung_WW', 50.5);
        check(GetValue($module->GetIDForIdent('Anpassung_WW')) === 50.5, 'Read-back failed');
    });
};
$tests['fragmented replies update selected custom IDs and remove them on deselection'] = static function (): void {
    $values = array_fill(0, 277, 0);
    $values[276] = 123;
    $response = frame(3004, $values);
    withServer(controllerCycles([
        [pack('N*', 3004, 0), [substr($response, 0, 3), substr($response, 3, 10), substr($response, 13)]],
        [pack('N*', 3004, 0), $response]
    ]), static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":276,"enabled":true}]';
        $module->Update();
        check(GetValue($module->GetIDForIdent('ID_276')) === '123', 'Custom value missing');
        $module->properties['IDListe'] = '[]';
        $module->Update();
        check($module->GetIDForIdent('ID_276') === false, 'Deselected custom variable survives');
    });
};
$tests['truncated frames, wrong commands and excessive counts preserve existing values'] = static function (): void {
    $badReplies = [
        pack('N*', 3004, 0, 277) . str_repeat("\0", 276 * 4) . "\0\x7b",
        substr(frame(3004, []), 0, 6), pack('N*', 3003, 0, 0), pack('N*', 3004, 0, 65537)
    ];
    foreach ($badReplies as $reply) {
        withServer(controllerCycles([[pack('N*', 3004, 0), $reply]]), static function (Luxtronik $module): void {
            $module->RegisterVariableString('ID_276', 'Existing');
            $id = $module->GetIDForIdent('ID_276');
            $GLOBALS['testVariables'][$id]['value'] = 'previous';
            $module->properties['IDListe'] = '[{"id":276,"enabled":true}]';
            $module->Update();
            check(GetValue($id) === 'previous' && $module->writes === 0, 'Corrupt reply changed value');
            check(count($module->errors) === 1, 'Failure not reported');
        });
    }
};
$tests['silent connections time out and leave existing values intact'] = static function (): void {
    withServer(controllerCycles([[pack('N*', 3004, 0), null]]), static function (Luxtronik $module): void {
        $module->RegisterVariableString('ID_276', 'Existing');
        $id = $module->GetIDForIdent('ID_276');
        $GLOBALS['testVariables'][$id]['value'] = 'previous';
        $module->properties['IDListe'] = '[{"id":276,"enabled":true}]';
        $start = microtime(true);
        $module->Update();
        $elapsed = microtime(true) - $start;
        // The independent parameter read follows the measurement timeout; this
        // single-threaded peer finishes its 5.5 s silence before answering it.
        check($elapsed >= 4.5 && $elapsed < 6.0, 'Measurement timeout plus independent control read exceeded its bound');
        check(count($module->errors) === 1 && str_contains($module->errors[0], 'Zeitüberschreitung'), 'Timeout missing');
        check($module->writes === 0 && GetValue($id) === 'previous', 'Timeout changed variables');
    });
};
$tests['failed write acknowledgements do not trigger read-back'] = static function (): void {
    foreach ([pack('N*', 3003, 105), pack('N', 3002)] as $reply) {
        withServer([[pack('N*', 3002, 105, 505), $reply]], static function (Luxtronik $module): void {
            $module->RegisterVariableFloat('Anpassung_WW', 'Warmwasser');
            $id = $module->GetIDForIdent('Anpassung_WW');
            $GLOBALS['testVariables'][$id]['value'] = 45.0;
            $module->RequestAction('Anpassung_WW', 50.5);
            check(GetValue($id) === 45.0 && $module->writes === 0, 'Failed write changed value');
            check(count($module->errors) === 1, 'Unexpected read-back after failed write');
        });
    }
};
$tests['partial control replies do not apply even fully received individual values'] = static function (): void {
    $values = array_fill(0, 106, 0);
    $values[1] = 25;
    $reply = substr(frame(3003, $values), 0, -2);
    withServer([[pack('N*', 3003, 0), $reply]], static function (Luxtronik $module): void {
        $module->properties['TempsetVisible'] = true;
        $module->RegisterVariableFloat('Anpassung_Temp', 'Correction');
        $id = $module->GetIDForIdent('Anpassung_Temp');
        $GLOBALS['testVariables'][$id]['value'] = -1.5;
        invoke($module, 'RefreshControlValues');
        check(GetValue($id) === -1.5 && $module->writes === 0, 'Partial control reply applied');
        check(count($module->errors) === 1, 'Partial control reply not reported');
    });
};
$tests['3003 control refresh and missing timer indices are safe'] = static function (): void {
    $values = array_fill(0, 106, 0);
    $values[1] = 4294967271; // -2.5 degrees
    $values[105] = 505; $values[18] = 505;
    withServer([
        [pack('N*', 3003, 0), frame(3003, $values)],
        [pack('N*', 3003, 0), frame(3003, [])]
    ], static function (Luxtronik $module): void {
        $module->properties['TempsetVisible'] = true;
        $module->properties['WWsetVisible'] = true;
        $module->RegisterVariableFloat('Anpassung_Temp', 'Correction');
        $module->RegisterVariableFloat('Anpassung_WW', 'Warmwasser');
        invoke($module, 'StoreVisualizationPoints', [18=>50.5]);
        invoke($module, 'RefreshControlValues');
        check(GetValue($module->GetIDForIdent('Anpassung_Temp')) === -2.5, 'Signed control value wrong');
        check(GetValue($module->GetIDForIdent('Anpassung_WW')) === 50.5, 'Control value wrong');
        $module->RegisterVariableInteger('set_223', 'Timer');
        $id = $module->GetIDForIdent('set_223');
        $GLOBALS['testVariables'][$id]['value'] = 3600;
        invoke($module, 'getParameter', 'set_223');
        check(GetValue($id) === 3600, 'Missing timer index changed value');
    });
};
$tests['native control feedback is always cached and external changes refresh optional variables'] = static function():void {
    $calc=array_fill(0,269,0);$calc[17]=430;$calc[18]=500;
    $params=array_fill(0,1149,0);$params[1]=4294967281;$params[105]=400;
    $later=$calc;$later[18]=520;$next=$params;$next[1]=20;
    $last=$params;$last[1]=10;
    withServer([
        [pack('N*',3004,0),frame(3004,$calc)],[pack('N*',3003,0),frame(3003,$params)],
        [pack('N*',3004,0),frame(3004,$later)],[pack('N*',3003,0),frame(3003,$next)],
        [pack('N*',3004,0),pack('N*',3003,0,0)],[pack('N*',3003,0),frame(3003,$last)]
    ],static function(Luxtronik $module):void {
        $module->properties['IDListe']='[]';
        $module->Update();
        check($module->GetIDForIdent('Anpassung_WW')===false && $module->GetIDForIdent('Anpassung_Temp')===false,'Internal reads create controls');
        check(invoke($module,'InternalControlReading','Anpassung_WW')['value']===50.0,'Internal effective WW target missing');
        check(invoke($module,'InternalControlReading','Anpassung_Temp')['value']===-1.5,'Internal correction missing without its variable');
        $module->RegisterVariableFloat('Anpassung_WW','Mein WW');$ww=$module->GetIDForIdent('Anpassung_WW');
        $module->RegisterVariableFloat('Anpassung_Temp','Meine Korrektur');$correction=$module->GetIDForIdent('Anpassung_Temp');
        $module->properties['WWsetVisible']=true;$module->properties['TempsetVisible']=true;
        $module->Update();
        check(GetValue($ww)===52.0 && GetValue($correction)===2.0,'External changes do not update the native controls');
        check(IPS_GetName($ww)==='Mein WW' && $module->GetIDForIdent('Anpassung_WW')===$ww,'Feedback changes replace objects or names');
        check($module->GetIDForIdent('Warmwasser_Soll_Temperatur')===false,'Effective feedback requires an extra measurement variable');
        $module->Update();
        check(GetValue($correction)===1.0 && GetValue($ww)===52.0,'Failed measurement blocks independent correction or fabricates WW feedback');
        $module->properties['IPAddress']='192.0.2.77';
        check(invoke($module,'InternalControlReading','Anpassung_Temp')===null,'Control cache leaks across devices');
    });
};
$tests['Java parameter 1 reaches the correction tile independently of optional measurement objects'] = static function():void {
    $calc=array_fill(0,269,0);$before=array_fill(0,1200,0);$after=$before;$after[1]=5;
    withServer([
        [pack('N*',3004,0),frame(3004,$calc)],[pack('N*',3003,0),frame(3003,$before)],
        [pack('N*',3004,0),frame(3004,$calc)],[pack('N*',3003,0),frame(3003,$after)]
    ],static function(Luxtronik $module):void {
        $module->properties['IDListe']='[]';$module->Update();$module->Update();
        check($module->GetIDForIdent('Anpassung_Temp')===false,'Reading parameter 1 creates a control');
        check(invoke($module,'InternalControlReading','Anpassung_Temp')['value']===0.5,'Raw parameter 1 value 5 not read as 0.5');
        ensureLegacyLuxtronikProfiles();$module->properties['TempsetVisible']=true;
        $module->RegisterVariableFloat('Anpassung_Temp','Meine Heizkorrektur','WPLUX.Tset');$module->EnableAction('Anpassung_Temp');
        $control=invoke($module,'BuildControlData')['heatingTemperatureCorrection'];
        check($control['configured'] && $control['currentValue']===0.5 && $control['step']===0.5,'Tile depends on the newly created variable default instead of internally read parameter 1');
        check(str_contains($control['formattedValue'],'0.5'),'Correction tile formats the wrong value');
        check($module->GetIDForIdent('SHI_Offset_Heizen')===false,'Java correction creates or mixes in a SHI offset');
    });
};
$tests['WW Java writes use the persistent command but display the effective target from ID 18'] = static function():void {
    $calc=array_fill(0,106,0);$calc[17]=505;$calc[18]=450;$calc[105]=600;
    withServer([[pack('N*',3002,105,505),pack('N*',3002,105)],[pack('N*',3004,0),frame(3004,$calc)]],static function(Luxtronik $module):void {
        $module->RegisterVariableFloat('Anpassung_WW','Warmwasser Soll');
        $module->RequestAction('Anpassung_WW',50.5);
        check(GetValue($module->GetIDForIdent('Anpassung_WW'))===45.0,'WW feedback uses the sent value, actual temperature or parameter 105');
    });
};

$tests['manual Java correction readback also refreshes its internal cache'] = static function():void {
    $params=array_fill(0,106,0);$params[1]=4294967281;
    withServer([[pack('N*',3002,1,4294967281),pack('N*',3002,1)],[pack('N*',3003,0),frame(3003,$params)]],static function(Luxtronik $module):void {
        $module->RegisterVariableFloat('Anpassung_Temp','Korrektur');
        invoke($module,'StoreInternalControlReading','Anpassung_Temp',1.0,invoke($module,'VisualizationSourceKey'));
        $module->RequestAction('Anpassung_Temp',-1.5);
        check(GetValue($module->GetIDForIdent('Anpassung_Temp'))===-1.5,'Correction variable not refreshed');
        check(invoke($module,'InternalControlReading','Anpassung_Temp')['value']===-1.5,'Internal correction cache still shows the old value');
    });
};

$tests['temperature scaling handles signed values and preserves legacy variable IDs'] = static function (): void {
    $module = new Luxtronik();
    for ($id = 203; $id <= 207; ++$id) {
        check(invoke($module, 'convertValueBasedOnID', 650, $id) === 65.0, 'Temperature scaling wrong');
        check(invoke($module, 'convertValueBasedOnID', 4294967271, $id) === -2.5, 'Signed temperature wrong');
    }
    $ident = 'Temperarturfuehler_Heissgas_2';
    invoke($module, 'CreateOrUpdateVariable', $ident, 65.0, 203);
    $id = $module->GetIDForIdent($ident);
    check(IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_FLOAT && GetValue($id) === 65.0, 'New variable not float');
    $writes = $module->writes;
    invoke($module, 'CreateOrUpdateVariable', $ident, 65.0, 203);
    check($module->writes === $writes, 'Unchanged value rewritten');
    $legacy = new Luxtronik();
    $legacy->RegisterVariableString($ident, 'Existing');
    $oldID = $legacy->GetIDForIdent($ident);
    invoke($legacy, 'CreateOrUpdateVariable', $ident, -2.5, 203);
    check($legacy->GetIDForIdent($ident) === $oldID && GetValue($oldID) === '-2.5', 'Legacy variable replaced');
};

// Other suites may reuse the fixtures and include these Java compatibility tests.
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

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
