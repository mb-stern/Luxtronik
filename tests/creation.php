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
    withServer([[pack('N*',3004,0),frame(3004,array_fill(0,276,0))]],static function(Luxtronik $m):void {
        $m->properties['UpdateInterval']=5;$m->ApplyChanges();
        check($m->errors===[] && $m->status===102,'Configured first save logs errors');
        check($m->timers['UpdateTimer']===5000 && $m->properties['MeasurementBulkSource']==='java','New instance loses Java default or shared polling');
        json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
    });
};
$failed=0;foreach($tests as $name=>$test) {
    try {$test();echo "PASS $name\n";} catch(Throwable $error) {++$failed;fwrite(STDERR,"FAIL $name: ".$error->getMessage()."\n");}
}
echo count($tests)." tests, $failed failures\n";exit($failed?1:0);
