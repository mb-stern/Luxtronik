<?php
require __DIR__.'/regression.php';
$tests=[];

// Deliberately disagree on new modules and existing instances, as the report must preserve both answers.
function IPS_GetKernelVersion(): string {return '9.1 test fixture';}
function IPS_GetKernelRevision(): string {return 'fixture';}
function IPS_GetModule(string $id): array {
    if(!empty($GLOBALS['setupFailMetadata'])) throw new RuntimeException('Metadata unavailable');
    return ['ModuleID'=>$id,'Implemented'=>['fixture-interface']];
}
function IPS_GetCompatibleModules(string $id): array {return [];}
function IPS_GetCompatibleParents(int $id): string {
    return json_encode(['type'=>'connect','moduleIDs'=>[]]);
}
function IPS_GetCompatibleInstances(int $id): array {return [900001];}
function IPS_GetInstanceListByModuleID(string $id): array {return [900001];}
function IPS_IsModuleCompatible(string $child,string $parent): bool {return false;}
// IPS_IsInstanceCompatible is intentionally absent to test older or incomplete kernels.

$tests['report captures kernel and module disagreement without repairing or reconfiguring anything'] = static function():void {
    $m=new Luxtronik();invoke($m,'RememberFirmwareString','V3.92.3');
    $GLOBALS['testInstanceInfo'][900001]=['ConnectionID'=>900002];
    $GLOBALS['testInstanceConfiguration'][900001]='{"DeviceID":1,"GatewayMode":0,"Password":"do-not-export","Host":"do-not-export"}';
    $before=[$m->properties,$m->writes,$GLOBALS['testInstanceConfiguration']];
    $json=$m->GetSHIData();$report=json_decode($json,true,512,JSON_THROW_ON_ERROR);$setup=$report['connectionSetup'];
    check($setup['symconVersion']==='9.1 test fixture','Running kernel version missing');
    check($setup['moduleParentSelection']['moduleIDs']===['{A5F663AB-C400-4FE5-B207-4D67CC030564}']
        && $setup['kernelParentSelection']['moduleIDs']===[],'Diagnostic normalizes away the disagreement');
    check($setup['modules']['luxtronik']['compatibleParentModules']===[] && $setup['compatibleParentInstances']===[900001],
        'New-module and existing-instance answers are not kept separate');
    check($setup['moduleCompatibility']['luxtronikToGateway']===false && $setup['moduleCompatibility']['gatewayToClientSocket']===false,
        'Actual kernel compatibility answers replaced by expected values');
    check($setup['gateways'][0]['configuration']===['DeviceID'=>1,'GatewayMode'=>0]
        && $setup['gateways'][0]['parentInstanceID']===900002 && !str_contains($json,'do-not-export'),'Gateway config includes unrelated fields');
    check($setup['gateways'][0]['compatibleWithLuxtronik']===['unavailable'=>'IPS_IsInstanceCompatible'],'Missing native API is not reported');
    check([$m->properties,$m->writes,$GLOBALS['testInstanceConfiguration']]===$before,'Read-only report changed configuration or wrote commands');
    check(count($report['values'])===129,'Existing Modbus status information removed');
};
$tests['native diagnostic API failure preserves the rest of the Modbus report'] = static function():void {
    $GLOBALS['setupFailMetadata']=true;
    try {
        $report=json_decode((new Luxtronik())->GetSHIData(),true,512,JSON_THROW_ON_ERROR);
        check($report['connectionSetup']['modules']['luxtronik']['metadata']===['error'=>'Metadata unavailable'],'Native API error lost');
        check(count($report['values'])===129 && $report['stale'],'Native API error breaks status report');
    } finally {unset($GLOBALS['setupFailMetadata']);}
};

$failed=0;
foreach($tests as $name=>$test) {
    try {$test();echo "PASS $name\n";}
    catch(Throwable $error) {++$failed;fwrite(STDERR,"FAIL $name: ".$error->getMessage()."\n");}
}
echo count($tests)." tests, $failed failures\n";
exit($failed?1:0);
