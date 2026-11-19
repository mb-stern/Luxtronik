<?php
// Local lifecycle smoke test. Native Symcon creation is checked separately with native-create.php.
require __DIR__.'/regression.php';
$tests=[];
$tests['fresh module and first ApplyChanges work without a device address or parent'] = static function():void {
    foreach(['0.0.0.0','','::'] as $address) {
        $m=new Luxtronik();$m->properties['IPAddress']=$address;$m->properties['UpdateInterval']=5;
        json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
        $m->ApplyChanges();$m->Update();
        check($m->errors===[] && $m->writes===0,'Fresh unconfigured instance logs errors or changes values');
        check($m->status===104 && $m->timers['UpdateTimer']===0,'Unconfigured instance schedules network polling');
        $cop=$m->GetIDForIdent('copfaktor');$m->ApplyChanges();
        check($cop!==false && $m->GetIDForIdent('copfaktor')===$cop && $m->errors===[],'Repeated first save replaces variables or logs errors');
        $html=$m->GetVisualizationTile();
        check(strlen($html)>1000 && !str_contains($html,'Wärmepumpen-Ressource fehlt'),'New visualization cannot be opened');
        $metadata=json_decode(file_get_contents(__DIR__.'/../WPLUX/module.json'),true,512,JSON_THROW_ON_ERROR);
        check($metadata['parentRequirements']===[],'Fresh installation needs a gateway');
    }
};
$tests['newly configured Java instance starts the shared timer and reads without creation errors'] = static function():void {
    withServer(controllerCycles([[pack('N*',3004,0),frame(3004,array_fill(0,276,0))]]),static function(Luxtronik $m):void {
        $m->properties['UpdateInterval']=5;$m->ApplyChanges();
        check($m->errors===[] && $m->status===102,'Configured first save logs errors');
        check($m->timers['UpdateTimer']===5000 && $m->properties['MeasurementBulkSource']==='java','New instance loses Java default or shared polling');
        json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
    });
};
$tests['Apply upgrades variable defaults in place and builds native presentations without global profiles'] = static function():void {
    $module = new Luxtronik();
    $module->properties['IDListe'] = json_encode(array_map(static fn($id) => ['id'=>$id, 'enabled'=>true], [15, 80, 203, 400001]));
    $module->properties['SHIControlList'] = '[{"id":310000,"enabled":true},{"id":310002,"enabled":true}]';
    $module->properties['HeizungVisible'] = true;
    $module->properties['TempsetVisible'] = true;
    $module->properties['HZ_TimerWeekVisible'] = 1;

    $module->RegisterVariableFloat('Aussentemperatur', 'Mein Sensor', '~Temperature', 22);
    $module->RegisterVariableInteger('Betriebszustand', 'Mein Betriebszustand', 'WPLUX.BZ', 23);
    $oldTemperature = invoke($module, 'GetDataPointConfig', 203)['name'];
    $module->RegisterVariableString($oldTemperature, 'Mein alter Wert', '', 24);
    $module->RegisterVariableFloat('EEV_Heizen', 'Mein EEV', 'WPLUX.EEV.Percent', 400001);
    $module->RegisterVariableInteger('Mode_Heizung', 'Meine Betriebsart', 'WPLUX.Wwhe', 26);
    $module->RegisterVariableFloat('Anpassung_Temp', 'Meine Korrektur', 'WPLUX.Tset', 27);
    $module->RegisterVariableInteger('set_223', 'Meine Zeit', '~UnixTimestampTime', 28);

    $outside = $module->GetIDForIdent('Aussentemperatur');
    $custom = ['PRESENTATION'=>VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX'=>' Grad', 'DIGITS'=>2];
    IPS_SetVariableCustomPresentation($outside, $custom);
    $GLOBALS['testVariables'][$outside]['value'] = 11.75;
    $status = $module->GetIDForIdent('Betriebszustand');
    $GLOBALS['testVariables'][$status]['value'] = 4;
    $oldId = $module->GetIDForIdent($oldTemperature);
    $mode = $module->GetIDForIdent('Mode_Heizung');
    $correction = $module->GetIDForIdent('Anpassung_Temp');
    $timer = $module->GetIDForIdent('set_223');
    $GLOBALS['testVariables'][$timer]['value'] = 32400;
    $GLOBALS['testVariables'][$mode]['custom_action'] = 90001;
    $eev = $module->GetIDForIdent('EEV_Heizen');
    IPS_CreateVariableProfile('WPLUX.Tset', VARIABLETYPE_FLOAT);
    IPS_SetVariableProfileValues('WPLUX.Tset', -5, 5, 1);
    $profilesBefore = $GLOBALS['testProfiles'];

    $module->ApplyChanges();
    $module->ApplyChanges();

    check($GLOBALS['testProfiles'] === $profilesBefore, 'Saving created a global variable profile');
    check($module->GetIDForIdent('Aussentemperatur') === $outside && IPS_GetName($outside) === 'Mein Sensor'
        && IPS_GetObject($outside)['ObjectPosition'] === 22 && GetValue($outside) === 11.75
        && IPS_GetVariablePresentation($outside) === $custom, 'Migration changed object metadata, value or custom presentation');
    check(IPS_GetVariable($outside)['VariablePresentation']['SUFFIX'] === ' °C', 'Temperature unit lost in new default');
    check($module->GetIDForIdent($oldTemperature) === $oldId && IPS_GetVariable($oldId)['VariableType'] === VARIABLETYPE_STRING
        && IPS_GetVariablePresentation($oldId)['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Old string measurement was not converted in place');
    check(GetValueFormatted($status) === 'Abtauen' && IPS_GetVariablePresentation($status)['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Read-only status lost its caption');
    check($module->GetIDForIdent('Mode_Heizung') === $mode && IPS_GetName($mode) === 'Meine Betriebsart'
        && IPS_GetVariable($mode)['VariableCustomAction'] === 90001
        && IPS_GetVariablePresentation($mode)['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION, 'Mode lost its object or action');
    check(IPS_GetVariablePresentation($correction)['STEP_SIZE'] === 0.5 && IPS_GetName($correction) === 'Meine Korrektur', 'Native correction step or name changed');
    check(IPS_GetVariablePresentation($timer) === ['PRESENTATION'=>VARIABLE_PRESENTATION_DATE_TIME, 'DATE'=>0, 'TIME'=>1]
        && GetValue($timer) === 32400 && IPS_GetName($timer) === 'Meine Zeit', 'Schedule changed its time format or stored value');
    check(IPS_GetVariablePresentation($eev)['SUFFIX'] === ' %' && IPS_GetName($eev) === 'Mein EEV', 'WebSocket measurement was not preserved');
    check(IPS_GetVariablePresentation($module->GetIDForIdent('SHI_Modus_Heizen'))['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION
        && IPS_GetVariablePresentation($module->GetIDForIdent('SHI_Offset_Heizen'))['STEP_SIZE'] === 0.1, 'SHI modes or step lost');
};
$tests['retained Betriebszustand variables lose duplicate legacy profile overrides without losing their captions'] = static function():void {
    ensureLegacyLuxtronikProfiles();
    $profile = IPS_GetVariableProfile('WPLUX.BZ');
    foreach ([false, true] as $adjusted) {
        $module = new Luxtronik();
        $module->properties['InstanceStatus'] = false;
        $module->RegisterVariableInteger('Betriebszustand', 'Mein Zustand', 'WPLUX.BZ', 22);
        $id = $module->GetIDForIdent('Betriebszustand');
        $GLOBALS['testVariables'][$id]['value'] = 4;
        if ($adjusted) $module->ApplyChanges(); // The default has already migrated, but an old override remains.
        IPS_SetVariableCustomPresentation($id, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => 'WPLUX.BZ']);
        if ($adjusted) $GLOBALS['testProfiles']['WPLUX.BZ']['Associations'][4]['Name'] = 'Mein Abtau-Status';
        $module->ApplyChanges();
        $variable = IPS_GetVariable($id);
        check($module->GetIDForIdent('Betriebszustand') === $id && IPS_GetName($id) === 'Mein Zustand'
            && IPS_GetObject($id)['ObjectPosition'] === 22 && GetValue($id) === 4, 'Status object or data changed');
        check($variable['VariableCustomProfile'] === '' && IPS_GetVariablePresentation($id)['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Old profile still overrides the new status display');
        check(GetValueFormatted($id) === ($adjusted ? 'Mein Abtau-Status' : 'Abtauen'), 'Status caption was lost');
        check(($variable['VariableCustomPresentation'] !== []) === $adjusted, 'Exact duplicate was retained or an edited display was discarded');
    }
    $GLOBALS['testProfiles']['WPLUX.BZ'] = $profile;
    $module = new Luxtronik();
    $module->RegisterVariableInteger('Betriebszustand', 'Mein Zustand', 'WPLUX.BZ');
    $id = $module->GetIDForIdent('Betriebszustand');
    $custom = ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => 'Mein.Profil'];
    IPS_SetVariableCustomPresentation($id, $custom);
    $module->ApplyChanges();
    check(IPS_GetVariablePresentation($id) === $custom, 'An unrelated custom profile was changed');
};
$tests['every catalog entry registers a native variable presentation'] = static function():void {
    $java = (new ReflectionClass(Luxtronik::class))->getConstant('DATA_POINT_CONFIG');
    $module = new Luxtronik();
    $catalogs = ['java' => $java, 'modbus' => invoke($module, 'SHISelections'), 'websocket' => invoke($module, 'WebDefinitions')];
    $types = [VARIABLE_PRESENTATION_VALUE_PRESENTATION, VARIABLE_PRESENTATION_ENUMERATION,
        VARIABLE_PRESENTATION_SLIDER, VARIABLE_PRESENTATION_DATE_TIME, VARIABLE_PRESENTATION_SWITCH];
    $profilesBefore = $GLOBALS['testProfiles'];
    foreach ($catalogs as $source => $catalog) {
        $module = new Luxtronik();
        foreach ($catalog as $id => $point) {
            $ident = $point['ident'] ?? $point['name'];
            match ($source) {
                'java' => invoke($module, 'EnsureJavaMeasurementVariable', $ident, $id, $point),
                'modbus' => invoke($module, 'EnsureSHIVariable', $point),
                'websocket' => invoke($module, 'EnsureWebVariable', $id, $point)
            };
            $variableId = $module->GetIDForIdent($ident);
            check($variableId !== false, "Variable missing for $source $ident");
            $variable = IPS_GetVariable($variableId);
            check($variable['VariableProfile'] === '' && $variable['VariableCustomProfile'] === ''
                && in_array($variable['VariablePresentation']['PRESENTATION'] ?? '', $types, true), "Legacy presentation for $source $ident");
        }
    }
    check($GLOBALS['testProfiles'] === $profilesBefore, 'Catalog registration changed global profiles');
};
$tests['inactive instances migrate every retained Java control and schedule and alternate WebSocket profiles'] = static function():void {
    $module = new Luxtronik();
    $module->properties['InstanceStatus'] = false;
    $controls = [
        'Mode_Heizung' => 'WPLUX.Wwhe', 'Mode_WW' => 'WPLUX.Wwhe', 'Mode_Schwimmbad' => 'WPLUX.Wwhe',
        'Mode_Kuehlung' => 'WPLUX.Kue', 'Anpassung_Temp' => 'WPLUX.SHI.H10002', 'Anpassung_WW' => 'WPLUX.SHI.H10006',
        'Anpassung_RBE' => 'WPLUX.Wset', 'copfaktor' => 'WPLUX.Cop', 'jazfaktor' => 'WPLUX.Cop'
    ];
    $expected = [];
    foreach ($controls as $ident => $profile) {
        $mode = str_starts_with($ident, 'Mode_');
        $method = $mode ? 'RegisterVariableInteger' : 'RegisterVariableFloat';
        $module->$method($ident, 'Mein '.$ident, $profile, 22);
        $presentation = $mode ? VARIABLE_PRESENTATION_ENUMERATION
            : (str_starts_with($ident, 'Anpassung_') ? VARIABLE_PRESENTATION_SLIDER : VARIABLE_PRESENTATION_VALUE_PRESENTATION);
        $expected[$ident] = [$module->GetIDForIdent($ident), $presentation];
    }
    foreach (array_merge(range(223, 282), range(406, 505)) as $index) {
        $ident = 'set_'.$index;
        $module->RegisterVariableInteger($ident, 'Mein '.$ident, '~UnixTimestampTime', 22);
        $expected[$ident] = [$module->GetIDForIdent($ident), VARIABLE_PRESENTATION_DATE_TIME];
    }
    foreach (invoke($module, 'WebDefinitions') as $point) {
        if (empty($point['sharedSHI'])) continue;
        $ident = $point['ident'];
        $method = $point['type'] === 'bool' ? 'RegisterVariableBoolean' : 'RegisterVariableFloat';
        invoke($module, $method, $ident, 'Mein '.$ident, $point['profile'], 22);
        $id = $module->GetIDForIdent($ident);
        IPS_SetVariableCustomPresentation($id, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => $point['profile']]);
        $expected[$ident] = [$id, VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }
    $module->RegisterVariableInteger('set_300', 'Eigene Variable', 'Mein.Profil', 23);
    $module->ApplyChanges();
    foreach ($expected as $ident => [$id, $presentation]) {
        check($module->GetIDForIdent($ident) === $id && IPS_GetName($id) === 'Mein '.$ident
            && IPS_GetObject($id)['ObjectPosition'] === 22, 'Migration changed object metadata for '.$ident);
        $variable = IPS_GetVariable($id);
        check($variable['VariableProfile'] === '' && $variable['VariableCustomProfile'] === ''
            && IPS_GetVariablePresentation($id)['PRESENTATION'] === $presentation, 'Inactive instance retained a legacy presentation for '.$ident);
    }
    check(IPS_GetVariable($module->GetIDForIdent('set_300'))['VariableProfile'] === 'Mein.Profil', 'Unrelated variable was migrated');
};
$failed=0;foreach($tests as $name=>$test) {
    try {$test();echo "PASS $name\n";} catch(Throwable $error) {++$failed;fwrite(STDERR,"FAIL $name: ".$error->getMessage()."\n");}
}
echo count($tests)." tests, $failed failures\n";exit($failed?1:0);
