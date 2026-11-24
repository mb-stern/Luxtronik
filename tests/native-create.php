<?php
// Run inside the Symcon script console. Creates one fresh test instance and leaves it for review.
if(!function_exists('IPS_CreateInstance')) {echo "SKIP: Run this creation test inside Symcon.\n";return;}
error_reporting(E_ALL);
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool {
    if(!(error_reporting() & $severity)) return false;
    throw new ErrorException($message,0,$severity,$file,$line);
});
$testInstance=null;
try {
    $testInstance=IPS_CreateInstance('{3884C1C3-F4FF-9A56-B982-B986E934A5E7}');
    IPS_SetName($testInstance,'Luxtronik – Neuanlageprüfung');
    IPS_ApplyChanges($testInstance);
    $form=json_decode(IPS_GetConfigurationForm($testInstance),true,512,JSON_THROW_ON_ERROR);
    $instance=IPS_GetInstance($testInstance);
    if(($instance['ConnectionID']??0)!==0 || !in_array($instance['InstanceStatus']??0,[102,104],true))
        throw new RuntimeException('Unexpected parent or instance status: '.json_encode($instance));
    if(empty($form['elements'])) throw new RuntimeException('Configuration form is empty');
    echo 'PASS: Native creation, ApplyChanges and configuration form; instance '.$testInstance.'.'.PHP_EOL;
    echo 'The test instance remains available for checking the wizard and system log.'.PHP_EOL;
} catch(Throwable $error) {
    echo 'FAIL: '.$error->getMessage().PHP_EOL;
    if($testInstance!==null) echo 'Test instance: '.$testInstance.PHP_EOL;
} finally {restore_error_handler();}
