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
            || str_starts_with($property, 'VisualizationRoomVariable') || str_starts_with($property, 'VisualizationHeaterSwitch') || str_contains($property, 'Threshold') || str_contains($property, 'Count')) continue;
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
    visualizationVariable($module, 'Waermemengenzaehler_Durchfluss', 607.0);
    $module->properties['kwin']=visualizationVariable($external,'InputPowerForCOP',0.0255);
    for ($i = 1; $i <= 3; ++$i) {
        $id = visualizationVariable($external, 'HeaterSwitch' . $i, false);
        $external->EnableAction('HeaterSwitch' . $i);
        $module->properties['VisualizationHeaterSwitch' . $i] = $id;
    }
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
    check(array_keys($pickers) === ['HeaterRod1', 'VisualizationHeaterSwitch1', 'HeaterRod2', 'VisualizationHeaterSwitch2', 'HeaterRod3', 'VisualizationHeaterSwitch3', 'VisualizationRoomVariable1', 'VisualizationRoomVariable2', 'VisualizationRoomVariable3', 'AdditionalValue000', 'AdditionalValue001', 'AdditionalValue002'], 'Removed power or pump pickers retained');
    check(!str_contains(json_encode($sdkFields),'Mischerdaten stehen beim Ventil'),'Obsolete hint retained');
    $solarRow=array_values(array_filter($panel[0]['items'],fn($item)=>($item['type']??'')==='RowLayout' && isset(fields($item)['ThermalSolarAvailable'])));
    check(count($solarRow)===1 && isset(fields($solarRow[0])['VisualizationSolarTarget']),'Solar destination separated from its switch');
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
    $module->properties['VisualizationRoomDisplay1'] = 'name';
    $module->properties['VisualizationRoomDisplay2'] = 'icon';
    $module->properties['VisualizationRoomDisplay3'] = 'both';
    check(array_column(invoke($module, 'BuildCardConfig')['roomTemperatures'], 'display') === ['name', 'icon', 'both'], 'Per-room display preferences lost');
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

$tests['empty ID list supplies the whole graphic internally without creating measurement variables'] = static function (): void {
    $values = array_fill(0, 269, 0);
    foreach ([10=>250, 11=>245, 12=>270, 15=>4294967261, 17=>485, 18=>500, 21=>249, 22=>200, 40=>1, 41=>0, 80=>5, 155=>607, 257=>6500, 268=>1500] as $id=>$value) $values[$id]=$value;
    $updated=$values; $updated[21]=251;
    withServer([[pack('N*',3004,0),frame(3004,$values)], [pack('N*',3004,0),frame(3004,$updated)]], static function (Luxtronik $module): void {
        invoke($module,'CreateVariableProfiles');
        $module->Update();
        $payload=visualizationPayload($module);
        check($module->properties['IDListe']==='[]','Automatic readings changed saved selections');
        check($payload['data']['mixerActual1']['value']===24.9 && $payload['data']['mixerTarget1']['value']===20.0,'Native mixer temperatures missing or rescaled');
        check($payload['data']['mixerOpen1']['value']===true && $payload['data']['mixerClose1']['value']===false,'Native motor signals missing');
        check($payload['data']['inputPower']['watts']==1500 && $payload['data']['dHWSetpoint']['value']===50.0,'Input power or DHW setpoint missing');
        check($payload['data']['heatingFlow']['value']==607,'Heat-meter flow missing');
        check($payload['data']['operatingStatus']['formatted']==='Keine Anforderung','Status requires a variable');
        check($payload['data']['outdoorTemperature']['value']===-3.5 && $payload['data']['heatingPumpPower']['value']===6.5,'Cache rescaled converted values');
        foreach ($payload['data'] as $reading) check(($reading['variableId'] ?? 0)===0,'Internal measurement depends on an IPS object');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Graphic created measurement variables');
        $GLOBALS['testInternalVisualizationHtml']=$module->GetVisualizationTile();
        // A variable left by the earlier build is subject to the original deselection rule.
        visualizationVariable($module,'Mischkreis_1_Vorlauftemperatur',99.0);
        $module->Update();
        check($module->GetIDForIdent('Mischkreis_1_Vorlauftemperatur')===false,'Unselected measurement object retained for the graphic');
        check(end($module->updates)['data']['mixerActual1']['value']===25.1,'Internal live update requires VM_UPDATE');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Later scan created objects');
        $GLOBALS['testInternalVisualizationUpdate']=end($module->updates);
        check($payload['config']['instanceFooter']===[],'Automatic points added to footer');
    });
};

$tests['input power reuses the COP source and exposes its origin without a second picker'] = static function (): void {
    $module = new Luxtronik(); $external = new Luxtronik();
    $kw = visualizationVariable($external,'COPPower',2.5);
    $watts = visualizationVariable($external,'Power',1800.0);
    $module->properties['kwin']=$kw;
    check(invoke($module,'BuildVisualizationData')['inputPower']['watts']===2500.0,'COP source kW interpreted as W');
    $module->properties['VisualizationInputPower']=$watts;
    check(invoke($module,'BuildVisualizationData')['inputPower']['watts']===2500.0,'Obsolete SDK source overrides COP source');
    visualizationVariable($module,'Leistungsaufnahme',1250.0,'~Watt');
    $data=invoke($module,'BuildVisualizationData');
    check($data['inputPower']['watts']===2500.0 && $data['inputPower']['source']==='cop','Native value overrides configured COP input');
    $GLOBALS['testVariables'][$kw]['value']=0.0;
    check(invoke($module,'BuildVisualizationData')['inputPower']['watts']===0.0,'Zero COP power incorrectly falls back to a native estimate');
    $module->properties['kwin']=0;
    $data=invoke($module,'BuildVisualizationData');
    check($data['inputPower']['watts']===1250.0 && $data['inputPower']['source']==='luxtronik','Native fallback or its provenance missing');
};

$tests['heater power sums W and kW but never invents power from booleans or duplicate IDs'] = static function (): void {
    $module = new Luxtronik(); $external = new Luxtronik();
    $GLOBALS['testProfiles']['PowerKW']=['Suffix'=>' kW'];
    $a=visualizationVariable($external,'RodA',1500.0);
    $b=visualizationVariable($external,'RodB',2.0,'PowerKW');
    // Selecting power sources is sufficient; no separate count needs to be set.
    $module->properties['HeaterRodCount']=0;
    $module->properties['HeaterRod1']=$a; $module->properties['HeaterRod2']=$b; $module->properties['HeaterRod3']=$a;
    check(invoke($module,'BuildVisualizationData')['heaterRodTotalPower']['value']===3500.0,'Mixed units or duplicates mishandled');
    $module->GetVisualizationTile();
    $GLOBALS['testVariables'][$a]['value']=0.0;
    $module->MessageSink(1,$a,VM_UPDATE,[]);
    check(end($module->updates)['data']['heaterRodTotalPower']['value']==2000.0,'Power sum not live');
    $module->properties['HeaterRod2']=visualizationVariable($external,'Relay',true);
    check(invoke($module,'BuildVisualizationData')['heaterRodTotalPower']['value']===null,'Relay treated as watts');
};

$tests['heater switching calls only configured external actions and waits for reported state'] = static function (): void {
    $module = new Luxtronik(); $external = new Luxtronik();
    $switch = visualizationVariable($external, 'HeaterRelay', false);
    $external->EnableAction('HeaterRelay');
    $module->properties['VisualizationHeaterSwitch1'] = $switch;
    $module->GetVisualizationTile();
    check(isset($module->messages[$switch][VM_UPDATE]), 'Heater switch not subscribed');
    $GLOBALS['testRequestedActions'] = [];
    $module->RequestAction('SetHeaterSwitch', json_encode(['number'=>1,'value'=>true,'variableId'=>999999]));
    check($GLOBALS['testRequestedActions'] === [[$switch,true]], 'Action target came from browser or wrong dispatch');
    check(GetValue($switch) === false, 'Switch state set directly before confirmation');
    $GLOBALS['testVariables'][$switch]['value'] = true;
    $module->MessageSink(1,$switch,VM_UPDATE,[]);
    check(end($module->updates)['controls']['heaterSwitches'][0]['currentValue'] === true, 'Reported heater state not live');
    $module->properties['CommunicationMode'] = 'modbus';
    $module->RequestAction('SetHeaterSwitch', ['number'=>1,'value'=>false]);
    check(end($GLOBALS['testRequestedActions']) === [$switch,false], 'External heater was confused with native SHI writes');
    $GLOBALS['testRequestActionResult'] = false;
    try {
        try { $module->RequestAction('SetHeaterSwitch', ['number'=>1,'value'=>false]); throw new LogicException('Failed action accepted'); }
        catch (RuntimeException $expected) {}
        check(GetValue($switch) === true, 'Failed action changed the displayed state');
    } finally { unset($GLOBALS['testRequestActionResult']); }
};
$tests['heater switching rejects malformed unconfigured disabled own and non-action variables'] = static function (): void {
    $module = new Luxtronik(); $external = new Luxtronik();
    $switch = visualizationVariable($external,'Relay',false); $external->EnableAction('Relay');
    $module->properties['VisualizationHeaterSwitch1']=$switch;
    $GLOBALS['testRequestedActions']=[];
    foreach ([[],['number'=>0,'value'=>true],['number'=>4,'value'=>true],['number'=>'1','value'=>true],['number'=>1,'value'=>1],['number'=>2,'value'=>true]] as $payload) {
        try { $module->RequestAction('SetHeaterSwitch',$payload); }
        catch (InvalidArgumentException|RuntimeException $expected) { continue; }
        throw new RuntimeException('Invalid heater action accepted');
    }
    $module->properties['InstanceStatus']=false;
    try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>true]); throw new LogicException('Disabled instance switched'); } catch (RuntimeException $expected) {}
    $module->properties['InstanceStatus']=true;
    $GLOBALS['testVariables'][$switch]['custom_action']=1;
    try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>true]); throw new LogicException('Disabled custom action switched'); } catch (RuntimeException $expected) {}
    $own = visualizationVariable($module,'OwnRelay',false); $module->EnableAction('OwnRelay');
    $module->properties['VisualizationHeaterSwitch1']=$own;
    try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>true]); throw new LogicException('Own variable recursively switched'); } catch (RuntimeException $expected) {}
    $module->properties['VisualizationHeaterSwitch1']=visualizationVariable($external,'IntegerRelay',0);
    try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>true]); throw new LogicException('Nonboolean switched'); } catch (RuntimeException $expected) {}
    check($GLOBALS['testRequestedActions']===[], 'Rejected action reached external handler');
};

$tests['integer heater associations preserve arbitrary raw values in legacy and modern presentations'] = static function (): void {
    foreach ([false,true] as $modern) {
        $module=new Luxtronik(); $external=new Luxtronik();
        $profile='HeaterModes';
        $GLOBALS['testProfiles'][$profile]=['Associations'=>[['Value'=>10.0,'Name'=>'Aus'],['Value'=>20.0,'Name'=>'Automatik'],['Value'=>30.0,'Name'=>'Ein']]];
        $id=visualizationVariable($external,'Mode',20,$modern ? '' : $profile); $external->EnableAction('Mode');
        if ($modern) $GLOBALS['testVariables'][$id]['presentation']=['PRESENTATION'=>'{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}',
            'OPTIONS'=>json_encode([['Value'=>10,'Caption'=>'Aus'],['Value'=>20,'Caption'=>'Automatik'],['Value'=>30,'Caption'=>'Ein']])];
        $module->properties['VisualizationHeaterSwitch1']=$id;
        $module->properties['AdditionalValue000']=$id;
        $control=invoke($module,'BuildControlData')['heaterSwitches'][0];
        check($control['configured'] && $control['currentValue']===20 && $control['stateLabel']==='Automatik','Integer mode not recognized');
        check(array_column($control['options'],'value')===[10,20,30],'Association values changed or collapsed to Boolean');
        check(invoke($module,'BuildVisualizationData')['additionalValue000']['formatted']==='Automatik','Read-only picker lost association label');
        $GLOBALS['testRequestedActions']=[];
        $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>30]);
        check($GLOBALS['testRequestedActions']===[[$id,30]],'Integer action did not use the actual association value');
        foreach ([true,1,25,'30'] as $invalid) {
            try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>$invalid]); }
            catch (InvalidArgumentException $expected) { continue; }
            throw new RuntimeException('Unlisted or incorrectly typed association accepted');
        }
        check(count($GLOBALS['testRequestedActions'])===1,'Rejected value reached action');
    }
};
$tests['modern presentation units and Float and String enumerations remain typed'] = static function (): void {
    $module=new Luxtronik(); $external=new Luxtronik();
    $power=visualizationVariable($external,'Power',2.5);
    $GLOBALS['testVariables'][$power]['presentation']=['SUFFIX'=>' kW'];
    $module->properties['HeaterRod1']=$power;
    check(invoke($module,'BuildVisualizationData')['heaterRodTotalPower']['value']===2500.0,'Modern power unit ignored');
    foreach ([[2,0.5,1.5],[3,'off','auto']] as [$type,$initial,$target]) {
        if ($type===3) { $external->RegisterVariableString('StringMode','String mode'); $id=$external->GetIDForIdent('StringMode'); $GLOBALS['testVariables'][$id]['value']=$initial; }
        else $id=visualizationVariable($external,'FloatMode',$initial);
        $GLOBALS['testVariables'][$id]['action']=$external->InstanceID;
        $GLOBALS['testVariables'][$id]['presentation']=['OPTIONS'=>json_encode([['Value'=>$initial,'Caption'=>'Aus'],['Value'=>$target,'Caption'=>'Automatik']])];
        $module->properties['VisualizationHeaterSwitch1']=$id;
        $GLOBALS['testRequestedActions']=[];
        $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>$target]);
        check($GLOBALS['testRequestedActions']===[[$id,$target]],'Enum scalar type changed');
    }
};
$tests['buffer solar circulation and pump data are internal and component-controlled'] = static function (): void {
    $module=new Luxtronik();
    $module->properties['TankHP']=false; $module->properties['ThermalSolarAvailable']=false; $module->properties['VisualizationCirculation']=false;
    $points=invoke($module,'GetVisualizationRequiredPointIDs');
    foreach ([13,26,27,46,52] as $id) check(!isset($points[$id]),'Disabled component requests data');
    $module->properties['TankHP']=true; $module->properties['ThermalSolarAvailable']=true; $module->properties['VisualizationCirculation']=true;
    $points=invoke($module,'GetVisualizationRequiredPointIDs');
    foreach ([13,26,27,46,52] as $id) check(isset($points[$id]),'Enabled component data not requested');
    invoke($module,'StoreVisualizationPoints',[13=>28.5,26=>65.0,27=>42.0,46=>true,52=>true,183=>31.7]);
    $data=invoke($module,'BuildVisualizationData');
    check($data['bufferReturnTemperature']['value']===28.5 && $data['solarStorageTemperature']['value']===42.0,'Storage data rescaled');
    check($data['thermalSolarPump']['value']===true && $data['circulatingPumpRunning']['value']===true,'Pump state missing');
    check($data['heatingCircuitPumpValue']['value']===31.7,'HUP control missing');
    check(!isset($data['heatingPumpSpeed']),'Pump rpm invented from percentage');
    check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Component values created objects');
};
$tests['internal cache is source-bound supports aliases and survives a failed Java scan atomically'] = static function (): void {
    $module=new Luxtronik();
    invoke($module,'StoreVisualizationPoints',[231=>50,268=>1500.0]);
    $data=invoke($module,'BuildVisualizationData');
    check($data['compressorValue']['ident']==='Verdichterfrequenz' && $data['compressorValue']['variableId']===0,'Internal alias requires a variable');
    $module->properties['IPAddress']='192.0.2.10';
    check(!isset(invoke($module,'BuildVisualizationData')['inputPower']),'Readings leaked across devices');
    $values=array_fill(0,269,0); $values[21]=249;
    withServer([[pack('N*',3004,0),frame(3004,$values)],[pack('N*',3004,0),pack('N*',3003,0,0)]],static function (Luxtronik $module):void {
        $module->Update(); $buffer=$module->buffers['VisualizationPointCache'];
        $module->Update();
        check($module->buffers['VisualizationPointCache']===$buffer,'Failed scan changed internal values');
        check(invoke($module,'BuildVisualizationData')['mixerActual1']['value']===24.9,'Last complete measurement lost');
        check(array_filter($GLOBALS['testVariables'],fn($v)=>$v['instance']===$module->InstanceID)===[],'Cache needs objects');
    });
};

$tests['renderer changes request one HTML reload and solar destination stays beside its switch'] = static function (): void {
    $module=new Luxtronik();
    invoke($module,'RefreshVisualization',true);
    invoke($module,'RefreshVisualization',true);
    check(count(array_filter($module->updates,fn($u)=>($u['command']??'')==='reloadHtml'))===1,'Renderer reload repeats or is missing');
    $module->properties['TankHP']=false;
    $module->properties['ThermalSolarAvailable']=true;
    $module->properties['VisualizationSolarTarget']='buffer';
    $form=json_decode($module->GetConfigurationForm(),true);
    $labels=[];
    $collect=static function($node) use (&$collect,&$labels):void {
        if(!is_array($node)) return;
        if(($node['type']??'')==='Label' && str_contains($node['caption']??'','Solarziel Pufferspeicher')) $labels[]=$node;
        foreach($node as $v) if(is_array($v)) $collect($v);
    };
    $collect($form);
    check(count($labels)===1 && $labels[0]['visible']===true,'Inactive buffer does not explain an unavailable solar destination');
};

$tests['heater configuration selects exact Ein and Aus associations and refreshes unsaved choices'] = static function (): void {
    foreach ([false,true] as $modern) {
        $module=new Luxtronik(); $external=new Luxtronik();
        $GLOBALS['testProfiles']['ThreeStates']=['Associations'=>[['Value'=>0.0,'Name'=>'Aus'],['Value'=>1.0,'Name'=>'Automatik'],['Value'=>2.0,'Name'=>'Ein']]];
        $id=visualizationVariable($external,'RodMode',1,$modern ? '' : 'ThreeStates');
        $external->EnableAction('RodMode');
        if ($modern) $GLOBALS['testVariables'][$id]['presentation']=['OPTIONS'=>json_encode([['Value'=>0,'Caption'=>'Aus'],['Value'=>1,'Caption'=>'Automatik'],['Value'=>2,'Caption'=>'Ein']])];
        $module->properties['VisualizationHeaterSwitch1']=$id;
        $fields=invoke($module,'HeaterConfigurationFields',1);
        $choices=$fields['items'][1]['items'][0]['options'];
        check(array_column($choices,'value')===['','0','1','2'],'Config does not offer the actual integer positions');
        check(str_contains($fields['items'][0]['items'][1]['onChange'],'$VisualizationHeaterSwitch1'),'Choices cannot follow an unsaved variable selection');
        $module->properties['VisualizationHeaterOnValue1']='2';
        $module->properties['VisualizationHeaterOffValue1']='0';
        $control=invoke($module,'BuildControlData')['heaterSwitches'][0];
        check(array_column($control['options'],'value')===[0,2] && array_column($control['options'],'role')===['off','on'],'Configured states not applied');
        check($control['enabled']===null && $control['stateLabel']==='Automatik','Intermediate state falsely reported as on/off');
        $GLOBALS['testRequestedActions']=[];
        $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>2]);
        check($GLOBALS['testRequestedActions']===[[$id,2]],'Configured Ein value changed');
        try { $module->RequestAction('SetHeaterSwitch',['number'=>1,'value'=>1]); throw new LogicException('Non-selected mode accepted'); }
        catch (InvalidArgumentException $expected) {}
        foreach ([0=>false,2=>true] as $value=>$enabled) {
            $GLOBALS['testVariables'][$id]['value']=$value;
            check(invoke($module,'BuildControlData')['heaterSwitches'][0]['enabled']===$enabled,'Integer state not recognized');
        }
        foreach (['2','99'] as $invalid) {
            $module->properties['VisualizationHeaterOffValue1']=$invalid;
            check(!invoke($module,'BuildControlData')['heaterSwitches'][0]['configured'],'Ambiguous or missing association enabled');
        }
        $before=$module->properties; $actions=$GLOBALS['testRequestedActions'];
        $module->UpdateHeaterConfiguration(1,$id);
        check(count($module->formUpdates)===4,'Unsaved picker options not refreshed');
        foreach ([0,2] as $index) {
            check($module->formUpdates[$index][1]==='options' && is_string($module->formUpdates[$index][2]),'Complex form options were not JSON-encoded');
            check(json_decode($module->formUpdates[$index][2],true,512,JSON_THROW_ON_ERROR)===$choices,'Serialized options lost labels or typed association tokens');
            check($module->formUpdates[$index+1][1]==='value' && $module->formUpdates[$index+1][2]==='','Old association selection not cleared');
        }
        check($module->properties===$before && $GLOBALS['testRequestedActions']===$actions,'Form selection actuated or persisted a device');
    }
};

$tests['valve change times distinguish first observation stable commands and source changes'] = static function (): void {
    $module=new Luxtronik();
    invoke($module,'StoreVisualizationPoints',[40=>false,41=>true,21=>24.9]);
    $data=invoke($module,'BuildVisualizationData');
    check($data['mixerClose1']['changedAt']===0,'First active command falsely marked as changed');
    invoke($module,'StoreVisualizationPoints',[40=>false,41=>true,21=>25.0]);
    check(invoke($module,'BuildVisualizationData')['mixerClose1']['changedAt']===0,'Temperature update marked an unchanged valve');
    invoke($module,'StoreVisualizationPoints',[40=>true,41=>false]);
    $data=invoke($module,'BuildVisualizationData');
    $stamp=$data['mixerOpen1']['changedAt'];
    check($stamp>microtime(true)-1 && $data['mixerClose1']['changedAt']>0,'Observed output change has no timestamp');
    invoke($module,'StoreVisualizationPoints',[40=>true,41=>false]);
    check(invoke($module,'BuildVisualizationData')['mixerOpen1']['changedAt']===$stamp,'Stable command restarted the blink window');
    invoke($module,'StoreVisualizationPoints',[41=>true],true);
    check(invoke($module,'BuildVisualizationData')['mixerOpen1']['changedAt']===$stamp,'Merged scan lost another output timestamp');
    $module->properties['IPAddress']='192.0.2.19';
    invoke($module,'StoreVisualizationPoints',[40=>true,41=>true]);
    check(invoke($module,'BuildVisualizationData')['mixerOpen1']['changedAt']===0,'Change times leaked across devices');
};

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
    file_put_contents($dir.'/internal-only.html','<meta charset="utf-8">'.$GLOBALS['testInternalVisualizationHtml']);
    file_put_contents($dir.'/internal-only-update.json',json_encode($GLOBALS['testInternalVisualizationUpdate']));
    $components = new Luxtronik();
    $components->properties['ThermalSolarAvailable']=true;
    $components->properties['TankHP']=true;
    $components->properties['VisualizationCirculation']=true;
    invoke($components,'StoreVisualizationPoints',[10=>35.0,11=>30.0,12=>32.0,13=>28.5,17=>48.5,18=>50.0,21=>30.0,22=>32.0,26=>65.0,27=>42.0,39=>true,40=>true,41=>false,46=>true,52=>true,80=>0,183=>31.7]);
    file_put_contents($dir.'/components.html','<meta charset="utf-8">'.$components->GetVisualizationTile());
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
