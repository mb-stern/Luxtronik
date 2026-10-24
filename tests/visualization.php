<?php

// Full graphic integration tests plus existing Java/SHI regressions.
require __DIR__ . '/regression.php';

function visualizationVariable(Luxtronik $module, string $ident, mixed $value, string $profile = ''): int
{
    $method = is_bool($value) ? 'RegisterVariableBoolean' : (is_int($value) ? 'RegisterVariableInteger' : 'RegisterVariableFloat');
    invoke($module, $method, $ident, $ident, $profile);
    $id = $module->GetIDForIdent($ident);
    $GLOBALS['testVariables'][$id]['value'] = $value;
    return $id;
}

function visualizationPayload(Luxtronik $module): array
{
    return ['config' => invoke($module, 'BuildCardConfig'), 'data' => invoke($module, 'BuildVisualizationData'),
        'controls' => invoke($module, 'BuildControlData')];
}

function configuredVisualization(): Luxtronik
{
    $module = new Luxtronik();
    invoke($module, 'CreateVariableProfiles');
    $reflection = new ReflectionClass($module);
    $map = $reflection->getConstant('LUXTRONIK_IDENT_MAP');
    $points = array_column($reflection->getConstant('DATA_POINT_CONFIG'), null, 'name');
    foreach ($map as $property => $idents) {
        $ident = $idents[0];
        if ($module->GetIDForIdent($ident) !== false) continue;
        $type = $points[$ident]['type'] ?? 'float';
        $value = match ($type) { 'bool' => true, 'int' => 25, default => 25.5 };
        if ($property === 'OperatingStatusVariable') $value = 7;
        if (in_array($property, ['HeatingControlVariable', 'HotWaterControlVariable', 'CoolingControlVariable'], true)) $value = 0;
        if ($property === 'WarmWaterSetpointVariable') $value = 50.0;
        if ($property === 'HeatingTemperatureCorrectionVariable') $value = 0.0;
        $profile = $points[$ident]['profile'] ?? match ($property) {
            'HeatingControlVariable', 'HotWaterControlVariable' => 'WPLUX.Wwhe',
            'CoolingControlVariable' => 'WPLUX.Kue', 'WarmWaterSetpointVariable' => 'WPLUX.Wset',
            'HeatingTemperatureCorrectionVariable' => 'WPLUX.Tset', default => ''
        };
        visualizationVariable($module, $ident, $value, $profile);
        if (str_contains($property, 'ControlVariable') || in_array($property, ['WarmWaterSetpointVariable', 'HeatingTemperatureCorrectionVariable'], true)) $module->EnableAction($ident);
    }
    // Populate optional fields without a separate instance picker or import.
    $base = json_decode(file_get_contents(__DIR__ . '/fixtures/pre-visualization-properties.json'), true);
    $external = new Luxtronik();
    foreach ($module->properties as $property => $default) {
        if (!is_int($default) || $default !== 0 || isset($base[$property]) || isset($map[$property])
            || str_starts_with($property, 'VisualizationRoomVariable') || str_contains($property, 'Threshold') || str_contains($property, 'Count')) continue;
        $id = visualizationVariable($external, $property, 25.5);
        $module->properties[$property] = $id;
    }
    $module->properties['VisualizationHeatingCircuit2'] = true;
    $module->properties['VisualizationHeatingCircuit3'] = true;
    $module->properties['HeaterRodCount'] = 3;
    $module->properties['ThermalSolarAvailable'] = true;
    $module->properties['HeatingCircuitType2'] = 'radiator';
    $module->properties['HeatingCircuitType3'] = 'underfloor';
    $module->properties['VisualizationMixedCircuit1'] = true;
    $module->properties['VisualizationMixedCircuit2'] = true;
    visualizationVariable($module, 'Ruecklauftemperatur_Heizkreis', 28.9, '~Temperature');
    visualizationVariable($module, 'Ruecklauf_Soll_Heizkreis', 30.0, '~Temperature');
    $GLOBALS['testVariables'][$module->GetIDForIdent('Ruecklauftemperatur_Heizkreis')]['formatted'] = '28.9 °C';
    $GLOBALS['testVariables'][$module->GetIDForIdent('Ruecklauf_Soll_Heizkreis')]['formatted'] = '30.0 °C';
    return $module;
}

$tests['visualization retains all existing property defaults and the complete source Ident map'] = static function (): void {
    $module = new Luxtronik();
    $base = json_decode(file_get_contents(__DIR__ . '/fixtures/pre-visualization-properties.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($base as $property => $value) check($module->properties[$property] === $value, 'Changed native default: ' . $property);
    $expected = json_decode(file_get_contents(__DIR__ . '/fixtures/visualization-ident-map.json'), true, 512, JSON_THROW_ON_ERROR);
    check((new ReflectionClass($module))->getConstant('LUXTRONIK_IDENT_MAP') === $expected, 'Incomplete source Ident map');
    check($module->visualizationType === 1, 'HTML-SDK not activated');
    $module->properties['HeatingCircuitName1'] = 'Mischkreis nur als Name';
    check(invoke($module, 'BuildCardConfig')['heatingCircuitMixed1'] === false, 'Circuit name incorrectly enables a mixer');
    foreach (['LuxtronikInstanceID', 'ManualConfiguration'] as $removed) check(!array_key_exists($removed, $module->properties), 'Separate source/manual mode retained');
};
$tests['visualization settings expose only external additions and layout; footer lives in the native list'] = static function (): void {
    $module = new Luxtronik();
    $module->properties['IDListe'] = '[{"id":15,"enabled":true,"visualizationFooter":true},{"id":300,"enabled":false,"visualizationFooter":true}]';
    $form = json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    $fields = fields($form);
    $panel = array_values(array_filter($form['elements'], fn($e) => ($e['caption'] ?? '') === 'HTML-SDK / Visualisierung'));
    check(count($panel) === 1, 'HTML-SDK settings missing');
    $sdkFields = fields($panel[0]);
    foreach (['VisualizationMixedCircuit1', 'VisualizationMixedCircuit2', 'VisualizationMixedCircuit3', 'VisualizationDirectCircuit'] as $removed) {
        check(!isset($sdkFields[$removed]), 'Obsolete mixer configuration retained');
    }
    $pickers = array_filter($sdkFields, fn($e) => ($e['type'] ?? '') === 'SelectVariable');
    check(array_keys($pickers) === ['VisualizationRoomVariable1', 'VisualizationRoomVariable2', 'VisualizationRoomVariable3', 'AdditionalValue000', 'AdditionalValue001', 'AdditionalValue002'], 'Unexpected assignment controls beyond three rooms and three free external values');
    foreach (['LuxtronikInstanceID', 'ManualConfiguration', 'OperatingStatusVariable', 'OutdoorTemperature', 'CompressorValue', 'ExpansionValveOpening'] as $property) {
        check(!isset($fields[$property]), 'Unwanted assignment UI: ' . $property);
    }
    $rows = array_column($fields['IDListe']['values'], null, 'id');
    check($rows[15]['enabled'] && $rows[15]['visualizationFooter'] && $rows[300]['visualizationFooter'], 'Footer flags lost');
    check(count(array_filter($fields['IDListe']['columns'], fn($c) => $c['name'] === 'visualizationFooter')) === 1, 'Footer checkbox missing');
    $module->properties['CommunicationMode'] = 'modbus';
    $fields = fields(json_decode($module->GetConfigurationForm(), true));
    check(isset(array_column($fields['IDListe']['columns'], null, 'name')['shi']), 'SHI support column lost');
    check(isset(array_column($fields['IDListe']['columns'], null, 'name')['visualizationFooter']), 'Footer lost in SHI');
};
$tests['own instance wins, fallbacks survive and converted values are never rescaled'] = static function (): void {
    $module = new Luxtronik();
    $foreign = new Luxtronik();
    $foreignID = visualizationVariable($foreign, 'Aussentemperatur', 99.0);
    $module->properties['OutdoorTemperature'] = $foreignID;
    check(invoke($module, 'BuildVisualizationData')['outdoorTemperature']['value'] === 99.0, 'Explicit fallback lost');
    $ownID = visualizationVariable($module, 'Aussentemperatur', -3.5);
    $hpID = visualizationVariable($module, 'Waermeleistung', 6.75);
    $data = invoke($module, 'BuildVisualizationData');
    check($data['outdoorTemperature']['variableId'] === $ownID && $data['outdoorTemperature']['value'] === -3.5, 'Own Ident not preferred');
    check($data['heatingPumpPower']['variableId'] === $hpID && $data['heatingPumpPower']['value'] === 6.75, 'kW rescaled');
    $alias = visualizationVariable($module, 'Verdichterfrequenz', 50.0);
    check(invoke($module, 'BuildVisualizationData')['compressorValue']['variableId'] === $alias, 'Frequency alias missing');
    $primary = visualizationVariable($module, 'Verdichterdrehzahl', 3000.0);
    check(invoke($module, 'BuildVisualizationData')['compressorValue']['variableId'] === $primary, 'Primary Ident lost');
    unset($GLOBALS['testVariables'][$primary]);
    check(invoke($module, 'BuildVisualizationData')['compressorValue']['variableId'] === $alias, 'Alias not restored after removal');
};
$tests['DHW actual is middle; duplicate sensors and missing fields retain their meaning'] = static function (): void {
    $module = new Luxtronik();
    $actual = visualizationVariable($module, 'Warmwasser_Ist_Temperatur', 48.5);
    $module->properties['TankTempWWUp'] = $actual;
    $module->properties['TankTempWWDown'] = $actual;
    $payload = visualizationPayload($module);
    foreach (['tankTempWWUp', 'tankTempWWMiddle', 'tankTempWWDown'] as $key) check($payload['data'][$key]['variableId'] === $actual, 'Duplicate sensor suppressed: ' . $key);
    check($payload['data']['tankTempWWMiddle']['value'] === 48.5 && !$payload['config']['singleCircuitHotWaterTemperatureSwitch'], 'Boiler mapping wrong');
    $module->properties['TankTempWWUp'] = 0;
    $module->properties['TankTempWWDown'] = 0;
    $config = invoke($module, 'BuildCardConfig');
    check($config['tankTempWWUp'] === '' && $config['tankTempWWDown'] === '' && $config['tankTempWWMiddle'] !== '', 'Missing sensor not hidden');
    visualizationVariable($module, 'SEC_EEV', 247.0);
    check(invoke($module, 'BuildCardConfig')['expansionValveOpening'] === '', 'Unconfirmed EEV mapping introduced');
};
$tests['footer checkbox activates the existing Java value path without an extra device query'] = static function (): void {
    $values = array_fill(0, 16, 0); $values[15] = 4294967261;
    withServer([[pack('N*', 3004, 0), frame(3004, $values)]], static function (Luxtronik $module): void {
        $module->properties['IDListe'] = '[{"id":15,"enabled":false,"visualizationFooter":true}]';
        $module->Update();
        $id = $module->GetIDForIdent('Aussentemperatur');
        check($id !== false && GetValue($id) === -3.5, 'Footer selection did not enable native data');
        $payload = visualizationPayload($module);
        check($payload['config']['instanceFooter'][0]['variableId'] === $id, 'Footer uses wrong object');
        check($payload['data']['instanceFooter15']['value'] === -3.5, 'Footer scaled value twice');
        $module->GetVisualizationTile();
        check(isset($module->messages[$id][VM_UPDATE]), 'Footer value not subscribed');
        $GLOBALS['testVariables'][$id]['value'] = -2.5;
        $module->MessageSink(1, $id, VM_UPDATE, []);
        check(end($module->updates)['data']['instanceFooter15']['value'] === -2.5, 'Footer live update missing');
    });
};
$tests['live messages refresh only registered variables and update removed/recreated Idents'] = static function (): void {
    $module = new Luxtronik();
    $id = visualizationVariable($module, 'Aussentemperatur', 10.0);
    $module->GetVisualizationTile();
    $count = count($module->updates);
    $module->MessageSink(1, 9999999, VM_UPDATE, []);
    check(count($module->updates) === $count, 'Unrelated sender caused update');
    $GLOBALS['testVariables'][$id]['value'] = 11.5;
    $module->MessageSink(2, $id, VM_UPDATE, []);
    check(end($module->updates)['data']['outdoorTemperature']['value'] === 11.5, 'VM_UPDATE ignored');
    unset($GLOBALS['testVariables'][$id]);
    $module->MessageSink(3, $id, VM_DELETE, []);
    check(!isset($module->messages[$id][VM_UPDATE]) && !isset(end($module->updates)['data']['outdoorTemperature']), 'Deleted object retained');
    $new = visualizationVariable($module, 'Aussentemperatur', 12.5);
    $module->MessageSink(4, $module->InstanceID, OM_CHILDADDED, [$new]);
    check(isset($module->messages[$new][VM_UPDATE]) && end($module->updates)['data']['outdoorTemperature']['variableId'] === $new, 'New object not rebound');
};
$tests['graphic actions use native Luxtronik write/read-back and preserve variable IDs'] = static function (): void {
    $values = array_fill(0, 106, 0); $values[105] = 505;
    withServer([[pack('N*', 3002, 105, 505), pack('N*', 3002, 105)], [pack('N*', 3003, 0), frame(3003, $values)]], static function (Luxtronik $module): void {
        invoke($module, 'CreateVariableProfiles');
        $id = visualizationVariable($module, 'Anpassung_WW', 45.0, 'WPLUX.Wset');
        $module->EnableAction('Anpassung_WW');
        $module->RequestAction('SetNumericControl', json_encode(['function' => 'warmWaterSetpoint', 'value' => 50.5]));
        check($module->GetIDForIdent('Anpassung_WW') === $id && GetValue($id) === 50.5, 'Native action not used');
    });
};
$tests['existing mode actions remain the only native path for heating hotwater and cooling'] = static function (): void {
    $cases = [['heating', 'Mode_Heizung', 3, 4], ['hotwater', 'Mode_WW', 4, 2], ['cooling', 'Mode_Kuehlung', 108, 1]];
    $exchanges = [];
    foreach ($cases as [$function, $ident, $parameter, $value]) {
        $values = array_fill(0, 109, 0); $values[$parameter] = $value;
        $exchanges[] = [pack('N*', 3002, $parameter, $value), pack('N*', 3002, $parameter)];
        $exchanges[] = [pack('N*', 3003, 0), frame(3003, $values)];
    }
    withServer($exchanges, static function (Luxtronik $module) use ($cases): void {
        invoke($module, 'CreateVariableProfiles');
        foreach ($cases as [$function, $ident, $parameter, $value]) {
            $id = visualizationVariable($module, $ident, 0, $ident === 'Mode_Kuehlung' ? 'WPLUX.Kue' : 'WPLUX.Wwhe');
            $module->EnableAction($ident);
            $module->RequestAction('SetControlMode', ['function' => $function, 'value' => $value]);
            check($module->GetIDForIdent($ident) === $id && GetValue($id) === $value, 'Mode did not use native write/read-back');
        }
    });
};
$tests['enabling an existing native action refreshes controls without reopening the tile'] = static function (): void {
    $module = new Luxtronik();
    invoke($module, 'CreateVariableProfiles');
    $id = visualizationVariable($module, 'Mode_WW', 0, 'WPLUX.Wwhe');
    $module->GetVisualizationTile();
    check(!invoke($module, 'BuildControlData')['hotwater']['configured'], 'Inactive action exposed');
    check(isset($module->messages[$id][VM_CHANGEPROFILEACTION]), 'Inactive control not watched');
    $module->EnableAction('Mode_WW');
    $module->MessageSink(1, $id, VM_CHANGEPROFILEACTION, []);
    check(end($module->updates)['controls']['hotwater']['configured'], 'Newly enabled action not updated');
    $module->properties['CommunicationMode'] = 'modbus';
    $module->MessageSink(2, $module->InstanceID, IM_CHANGESTATUS, []);
    check(!end($module->updates)['controls']['hotwater']['configured'], 'SHI mode did not revoke control');
};
$tests['graphic rejects forged, foreign, disabled and SHI write actions'] = static function (): void {
    withoutConnection(static function (Luxtronik $module): void {
        invoke($module, 'CreateVariableProfiles');
        visualizationVariable($module, 'Anpassung_WW', 45.0, 'WPLUX.Wset');
        $module->EnableAction('Anpassung_WW');
        foreach ([['function'=>'unknown','value'=>1], ['function'=>['invalid'],'value'=>1], ['function'=>'warmWaterSetpoint','value'=>66], ['function'=>'warmWaterSetpoint','value'=>'NaN']] as $payload) {
            try { $module->RequestAction('SetNumericControl', $payload); } catch (InvalidArgumentException $expected) { continue; }
            throw new RuntimeException('Invalid graphic request accepted');
        }
        foreach (['modbus', 'disabled'] as $mode) {
            $module->properties['CommunicationMode'] = $mode === 'modbus' ? 'modbus' : 'java';
            $module->properties['InstanceStatus'] = $mode !== 'disabled';
            check(!invoke($module, 'BuildControlData')['warmWaterSetpoint']['configured'], 'Read-only action offered');
            check(invoke($module, 'GetLuxtronikWebInterfaceURL') === '', 'Device panel bypasses read-only mode');
            try { $module->RequestAction('SetNumericControl', ['function'=>'warmWaterSetpoint','value'=>50.5]); } catch (RuntimeException $expected) { continue; }
            throw new RuntimeException('Read-only graphic wrote data');
        }
    });
};
$tests['integer legacy setpoints reject fractions instead of silently truncating them'] = static function (): void {
    withoutConnection(static function (Luxtronik $module): void {
        invoke($module, 'CreateVariableProfiles');
        visualizationVariable($module, 'Anpassung_WW', 45, 'WPLUX.Wset');
        $module->EnableAction('Anpassung_WW');
        try {
            $module->RequestAction('SetNumericControl', ['function' => 'warmWaterSetpoint', 'value' => 50.5]);
        } catch (InvalidArgumentException $expected) {
            return;
        }
        throw new RuntimeException('Legacy integer setpoint truncated');
    });
};
$tests['graphic is full-view only and labels and payload are safely embedded'] = static function (): void {
    $module = new Luxtronik();
    $module->properties['AdditionalLabel000'] = '</script><script>bad()</script>';
    $html = $module->GetVisualizationTile();
    check(str_contains($html, 'SymconHeatPump.init(') && !str_contains($html, 'id="wp-compact-view"') && !str_contains($html, 'wp-view-toggle'), 'Incorrect view controls');
    check(!str_contains($html, '</script><script>bad()'), 'Label escaped script context');
    check(!str_contains($html, '.innerHTML = config.additionalLabel'), 'Label rendered as unsafe HTML');
    $form = json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    check(isset(fields($form)['IDListe']), 'Native configuration lost');
};
$tests['three heating places and mixer readings remain independent and use existing Idents'] = static function (): void {
    check(count(invoke(new Luxtronik(), 'BuildCardConfig')['mixers']) === 1, 'Mixer 1 not shown by default');
    $module = mixedCircuitVisualization();
    $payload = visualizationPayload($module);
    for ($i = 1; $i <= 3; ++$i) {
        check($payload['config']['heatingCircuitName' . $i] === 'HK' . $i, 'Heating place replaced by mixer');
        check($payload['config']['heatingCircuitMixed' . $i] === false, 'Mixer inserted at heating place');
        check($payload['data']['mixerActual' . $i]['value'] === 25.0 + $i * 5, 'Wrong mixed-circuit actual Ident');
        check($payload['data']['mixerTarget' . $i]['value'] === 15.0 + $i * 5, 'Wrong mixed-circuit target Ident');
    }
    check(count($payload['config']['mixers']) === 3, 'Mixer readings incomplete');
    check($payload['data']['supplyTemperatureHeating']['value'] === 50.0, 'HK1 no longer uses original map');
    check($payload['data']['heatPumpReturnTemperature']['value'] === 40.0, 'Heat pump return lost');
    check(!isset($payload['data']['supplyTemperatureHeating2']), 'Invented HK2 temperature');
    check(!isset($payload['data']['refluxTemperatureHeating3']), 'Invented HK3 return');
    check($payload['data']['mixerOpen1']['value'] === true && $payload['data']['mixerClose1']['value'] === false, 'Relay signals conflated');
    check($payload['data']['mixerOpen2']['value'] === false && $payload['data']['mixerClose2']['value'] === false, 'Idle signals changed');
    $module->properties['VisualizationHeatingCircuit1'] = false;
    check(invoke($module, 'BuildCardConfig')['heatingCircuitType1'] === 'off', 'Disabled HK1 shown');
    check(count(invoke($module, 'BuildCardConfig')['mixers']) === 3, 'HK toggle removes independent mixers');
};

$tests['old circuit settings migrate once while preserving configuration and attributes'] = static function (): void {
    $module = new Luxtronik();
    $old = ['configuration' => ['VisualizationDirectCircuit' => false, 'VisualizationMixedCircuit1' => true,
        'VisualizationMixedCircuit3' => true, 'IDListe' => '[{"id":15,"enabled":true,"visualizationFooter":true}]',
        'AdditionalValue000' => 12345], 'attributes' => ['start_value_out' => 99]];
    $migrated = json_decode($module->Migrate(json_encode($old)), true);
    check($migrated['configuration']['VisualizationHeatingCircuit1'] && $migrated['configuration']['VisualizationHeatingCircuit2']
        && !$migrated['configuration']['VisualizationHeatingCircuit3'], 'Visible place count lost');
    foreach ($old['configuration'] as $key => $value) check($migrated['configuration'][$key] === $value, 'Existing setting lost');
    check($migrated['attributes'] === $old['attributes'], 'Attributes changed');
    check($module->Migrate(json_encode($migrated)) === '', 'Migration not idempotent');
};

$tests['three named room sources keep duplicate IDs zero values and safe variable icons'] = static function (): void {
    $module = roomVisualization(3);
    $payload = visualizationPayload($module);
    check(count($payload['config']['roomTemperatures']) === 3, 'Missing room sources');
    check($payload['config']['roomTemperatures'][0]['label'] === 'Wohnzimmer', 'Variable name fallback missing');
    check($payload['config']['roomTemperatures'][1]['label'] === 'Bad', 'Explicit room name ignored');
    check($payload['config']['roomTemperatures'][0]['icon'] === 'fa-light fa-temperature-half', 'Variable icon ignored');
    check($payload['config']['roomTemperatures'][1]['icon'] === 'fa-light fa-bath', 'Profile icon ignored');
    check($payload['config']['roomTemperatures'][2]['icon'] === '', 'Unsafe icon accepted');
    $module->properties['VisualizationRoomVariable3'] = $module->properties['VisualizationRoomVariable1'];
    $GLOBALS['testVariables'][$module->properties['VisualizationRoomVariable1']]['value'] = 0.0;
    check(count(invoke($module, 'BuildCardConfig')['roomTemperatures']) === 3, 'Repeated source suppressed');
    check(invoke($module, 'BuildVisualizationData')['roomTemperature3']['value'] === 0.0, 'Zero reading dropped');
    $module->GetVisualizationTile();
    $id = $module->properties['VisualizationRoomVariable1'];
    check(isset($module->messages[$id][VM_UPDATE]), 'Room source not subscribed');
    $GLOBALS['testVariables'][$id]['value'] = 21.5;
    $module->MessageSink(1, $id, VM_UPDATE, []);
    check(end($module->updates)['data']['roomTemperature1']['value'] === 21.5, 'Room live update missing');
    unset($GLOBALS['testVariables'][$id]);
    $module->MessageSink(2, $id, VM_DELETE, []);
    check(count(end($module->updates)['config']['roomTemperatures']) === 1, 'Deleted rooms retained');
};

$tests['COP and JAZ are read directly from their existing own Idents and stay live'] = static function (): void {
    $module = new Luxtronik();
    $foreign = new Luxtronik();
    visualizationVariable($foreign, 'copfaktor', 99.0);
    check(invoke($module, 'BuildCardConfig')['computedCOP'] === '', 'Foreign COP adopted');
    $cop = visualizationVariable($module, 'copfaktor', 4.25);
    $jaz = visualizationVariable($module, 'jazfaktor', 3.75);
    $module->GetVisualizationTile();
    check(isset($module->messages[$cop][VM_UPDATE], $module->messages[$jaz][VM_UPDATE]), 'Efficiency values not subscribed');
    $GLOBALS['testVariables'][$cop]['value'] = 0.0;
    $module->MessageSink(1, $cop, VM_UPDATE, []);
    $payload = end($module->updates);
    check($payload['data']['computedCOP']['variableId'] === $cop && (float)$payload['data']['computedCOP']['value'] === 0.0, 'COP recomputed or zero dropped');
    check($payload['data']['computedJAZ']['variableId'] === $jaz && $payload['data']['computedJAZ']['value'] === 3.75, 'JAZ changed');
};

$tests['saving existing meter selections immediately publishes newly created COP and JAZ'] = static function (): void {
    $module = new Luxtronik();
    $external = new Luxtronik();
    $module->properties['kwin'] = visualizationVariable($external, 'Power', 2.0);
    $module->properties['kwhin'] = visualizationVariable($external, 'Energy', 100.0);
    $module->ApplyChanges(); // Unconfigured device IP: local configuration only.
    $cop = $module->GetIDForIdent('copfaktor');
    $jaz = $module->GetIDForIdent('jazfaktor');
    $payload = end($module->updates);
    check($payload['data']['computedCOP']['variableId'] === $cop && $payload['data']['computedJAZ']['variableId'] === $jaz, 'ApplyChanges published before efficiency variables existed');
    check($payload['config']['computedCOP'] !== '' && $payload['config']['computedJAZ'] !== '', 'Efficiency display not enabled');
    invoke($module, 'calc_cop', 'cop', 8.0);
    $module->MessageSink(1, $cop, VM_UPDATE, []);
    check(end($module->updates)['data']['computedCOP']['value'] == 4.0, 'Native COP calculation not displayed');
    $module->properties['kwin'] = 0;
    $module->ApplyChanges();
    check(end($module->updates)['config']['computedCOP'] === '' && end($module->updates)['config']['computedJAZ'] !== '', 'Removed COP selection retained or JAZ removed');
};

function roomVisualization(int $count): Luxtronik
{
    $module = mixedCircuitVisualization();
    $external = new Luxtronik();
    for ($i = 1; $i <= $count; ++$i) {
        $id = visualizationVariable($external, 'Room' . $i, 20.0 + $i, $i === 2 ? 'RoomTemperature' : '~Temperature');
        $GLOBALS['testVariables'][$id]['name'] = ['Wohnzimmer', 'Badezimmer', 'Schlafzimmer'][$i - 1];
        $GLOBALS['testVariables'][$id]['formatted'] = (20 + $i) . '.0 °C';
        $GLOBALS['testVariables'][$id]['icon'] = ['Temperature', '', '</script><script>bad()</script>'][$i - 1];
        $module->properties['VisualizationRoomVariable' . $i] = $id;
        if ($i === 2) $module->properties['VisualizationRoomLabel2'] = 'Bad';
    }
    $GLOBALS['testProfiles']['RoomTemperature'] = ['Icon' => 'Bath', 'Suffix' => ' °C'];
    return $module;
}

function mixedCircuitVisualization(): Luxtronik
{
    $module = new Luxtronik();
    for ($i = 1; $i <= 3; ++$i) {
        $module->properties['VisualizationHeatingCircuit' . $i] = true;
        $id = visualizationVariable($module, 'Mischkreis_' . $i . '_Vorlauftemperatur', 25.0 + $i * 5, '~Temperature');
        $GLOBALS['testVariables'][$id]['formatted'] = (25 + $i * 5) . '.0 °C';
        $id = visualizationVariable($module, 'Mischkreis_' . $i . '_Vorlauf_Soll_Temperatur', 15.0 + $i * 5, '~Temperature');
        $GLOBALS['testVariables'][$id]['formatted'] = (15 + $i * 5) . '.0 °C';
        visualizationVariable($module, 'Ausgang_Mischkreis_' . $i . '_Auf', $i === 1);
        visualizationVariable($module, 'Ausgang_Mischkreis_' . $i . '_Zu', $i === 3);
    }
    visualizationVariable($module, 'Ruecklauftemperatur_Heizkreis', 40.0, '~Temperature');
    visualizationVariable($module, 'Vorlauftemperatur_Heizkreis', 50.0, '~Temperature');
    visualizationVariable($module, 'copfaktor', 4.25);
    visualizationVariable($module, 'jazfaktor', 3.75);
    return $module;
}

$failed=0;
foreach ($tests as $name=>$test) {
    try { $test(); echo "PASS $name\n"; }
    catch(Throwable $e) { ++$failed; fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n"); }
}
echo count($tests)." tests, $failed failures\n";
if ($failed) exit(1);

$dir=getenv('WP_TEST_ARTIFACT_DIR');
if ($dir!==false && $dir!=='') {
    if(!is_dir($dir)) mkdir($dir,0777,true);
    $module=configuredVisualization();
    file_put_contents($dir.'/configured.html','<meta charset="utf-8">'.$module->GetVisualizationTile());
    file_put_contents($dir.'/visualization.html','<meta charset="utf-8">'.(new Luxtronik())->GetVisualizationTile());
    file_put_contents($dir.'/mixed-circuits.html','<meta charset="utf-8">'.mixedCircuitVisualization()->GetVisualizationTile());
    for ($count = 1; $count <= 3; ++$count) file_put_contents($dir.'/rooms-'.$count.'.html', '<meta charset="utf-8">'.roomVisualization($count)->GetVisualizationTile());
    $id=$module->GetIDForIdent('Verdichterdrehzahl');
    $GLOBALS['testVariables'][$id]['value']=3000;
    $GLOBALS['testVariables'][$id]['formatted']='3000 rpm';
    $cond=$module->GetIDForIdent('Kondensationstemperatur');
    $GLOBALS['testVariables'][$cond]['value']=42.5;
    $GLOBALS['testVariables'][$cond]['formatted']='42.5 °C';
    file_put_contents($dir.'/live-ident.html','<meta charset="utf-8">'.$module->GetVisualizationTile());
    $GLOBALS['testVariables'][$id]['value']=1500;
    $GLOBALS['testVariables'][$id]['formatted']='1500 rpm';
    $module->MessageSink(1,$id,VM_UPDATE,[]);
    file_put_contents($dir.'/live-ident-update.json',json_encode(end($module->updates)));
    unset($GLOBALS['testVariables'][$id],$GLOBALS['testVariables'][$cond]);
    $alias=visualizationVariable($module,'Verdichterfrequenz',50.0);
    $cond=visualizationVariable($module,'Liquefaction_Temperature',42.5);
    $GLOBALS['testVariables'][$alias]['formatted']='50 Hz';
    $GLOBALS['testVariables'][$cond]['formatted']='42.5 °C';
    file_put_contents($dir.'/alias-ident.html','<meta charset="utf-8">'.$module->GetVisualizationTile());
    $GLOBALS['testVariables'][$alias]['value']=80.0;
    $GLOBALS['testVariables'][$alias]['formatted']='80 Hz';
    $module->MessageSink(1,$alias,VM_UPDATE,[]);
    file_put_contents($dir.'/alias-ident-update.json',json_encode(end($module->updates)));
    preg_match_all('~<script[^>]*>(.*?)</script>~s',$module->GetVisualizationTile(),$scripts);
    foreach($scripts[1] as $i=>$js) file_put_contents($dir.'/script-'.$i.'.js',$js);
}
