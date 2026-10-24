<?php

// Minimal Symcon model for regression tests; never loaded by the module itself.
const KL_ERROR = 1;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KR_READY = 10103;
const VM_DELETE = 10602;
const VM_UPDATE = 10603;
const VM_CHANGEPROFILEACTION = 10605;
const OM_CHILDADDED = 10412;
const OM_CHILDREMOVED = 10413;
const IM_CHANGESTATUS = 10505;

$GLOBALS['testVariables'] = [];
$GLOBALS['testNextID'] = 10000;
$GLOBALS['testProfiles'] = [];

function IPS_VariableProfileExists(string $name): bool { return isset($GLOBALS['testProfiles'][$name]); }
function IPS_CreateVariableProfile(string $name, int $type): void { $GLOBALS['testProfiles'][$name] = ['Type' => $type, 'Associations' => []]; }
function IPS_SetVariableProfileValues(string $name, float $min, float $max, float $step): void { $GLOBALS['testProfiles'][$name] += ['MinValue' => $min, 'MaxValue' => $max, 'StepSize' => $step]; }
function IPS_SetVariableProfileDigits(string $name, int $digits): void { $GLOBALS['testProfiles'][$name]['Digits'] = $digits; }
function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void { $GLOBALS['testProfiles'][$name]['Prefix'] = $prefix; $GLOBALS['testProfiles'][$name]['Suffix'] = $suffix; }
function IPS_SetVariableProfileAssociation(string $name, float $value, string $text, string $icon, int $color): void { $GLOBALS['testProfiles'][$name]['Associations'][] = ['Value' => $value, 'Name' => $text]; }
function IPS_GetVariableProfile(string $name): array { return $GLOBALS['testProfiles'][$name]; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetName(int $id): string { return $GLOBALS['testVariables'][$id]['name'] ?? ''; }
function IPS_GetObject(int $id): array { return ['ObjectIdent' => $GLOBALS['testVariables'][$id]['ident'] ?? '']; }
function IPS_GetParent(int $id): int { return $GLOBALS['testVariables'][$id]['instance'] ?? 0; }
function GetValueFormatted(int $id): string {
    $variable = $GLOBALS['testVariables'][$id];
    $profile = $GLOBALS['testProfiles'][$variable['profile']] ?? [];
    return $variable['formatted'] ?? (string)$variable['value'] . ($profile['Suffix'] ?? '');
}

function IPS_VariableExists(int $id): bool
{
    return isset($GLOBALS['testVariables'][$id]);
}

function IPS_GetVariable(int $id): array
{
    $v = $GLOBALS['testVariables'][$id];
    return ['VariableType' => $v['type'], 'VariableProfile' => $v['profile'],
        'VariableCustomProfile' => '', 'VariableAction' => $v['action'] ?? 0, 'VariableCustomAction' => 0];
}

function GetValue(int $id): mixed
{
    return $GLOBALS['testVariables'][$id]['value'];
}

function IPS_GetObjectIDByIdent(string $ident, int $instanceID): int|false
{
    foreach ($GLOBALS['testVariables'] as $id => $variable) {
        if ($variable['ident'] === $ident && $variable['instance'] === $instanceID) {
            return $id;
        }
    }
    return false;
}

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $errors = [];
    public int $writes = 0;
    public int $status = 0;
    public array $timers = [];
    public array $actions = [];
    public array $buffers = [];
    public array $messages = [];
    public array $updates = [];
    public int $visualizationType = 0;

    public function __construct()
    {
        $this->InstanceID = ++$GLOBALS['testNextID'];
        $this->Create();
    }

    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Migrate(string $data): string { return ''; }
    protected function RegisterPropertyString(string $name, string $value): void { $this->properties[$name] ??= $value; }
    protected function RegisterPropertyInteger(string $name, int $value): void { $this->properties[$name] ??= $value; }
    protected function RegisterPropertyBoolean(string $name, bool $value): void { $this->properties[$name] ??= $value; }
    protected function RegisterAttributeFloat(string $name, float $value): void { $this->attributes[$name] = $value; }
    protected function ReadPropertyString(string $name): string { return $this->properties[$name]; }
    protected function ReadPropertyInteger(string $name): int { return $this->properties[$name]; }
    protected function ReadPropertyBoolean(string $name): bool { return $this->properties[$name]; }
    protected function ReadAttributeFloat(string $name): float { return $this->attributes[$name]; }
    protected function WriteAttributeFloat(string $name, float $value): void { $this->attributes[$name] = $value; }
    protected function RegisterTimer(string $name, int $interval, string $script): void {}
    protected function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    protected function SetStatus(int $status): void { $this->status = $status; }
    public function EnableAction(string $ident): bool { $this->actions[$ident] = true; $GLOBALS['testVariables'][$this->GetIDForIdent($ident)]['action'] = $this->InstanceID; return true; }
    protected function DisableAction(string $ident): bool { $this->actions[$ident] = false; $GLOBALS['testVariables'][$this->GetIDForIdent($ident)]['action'] = 0; return true; }
    protected function SetVisualizationType(int $type): void { $this->visualizationType = $type; }
    protected function SetBuffer(string $name, string $value): void { $this->buffers[$name] = $value; }
    protected function GetBuffer(string $name): string { return $this->buffers[$name] ?? ''; }
    protected function RegisterMessage(int $sender, int $message): bool { $this->messages[$sender][$message] = true; return true; }
    protected function UnregisterMessage(int $sender, int $message): bool { unset($this->messages[$sender][$message]); return true; }
    protected function UpdateVisualizationValue(mixed $data): bool { $this->updates[] = json_decode($data, true, 512, JSON_THROW_ON_ERROR); return true; }
    protected function SendDebug(string $name, mixed $value, int $format): void {}
    protected function LogMessage(string $message, int $severity): void { $this->errors[] = $message; }
    public function GetIDForIdent(string $ident): int|false { return IPS_GetObjectIDByIdent($ident, $this->InstanceID); }

    protected function RegisterVariableBoolean(string $ident, string $name, string $profile = '', int $position = 0): bool
    {
        return $this->register($ident, VARIABLETYPE_BOOLEAN, false, $name, $profile);
    }
    public function RegisterVariableInteger(string $ident, string $name, string $profile = '', int $position = 0): bool
    {
        return $this->register($ident, VARIABLETYPE_INTEGER, 0, $name, $profile);
    }
    public function RegisterVariableFloat(string $ident, string $name, string $profile = '', int $position = 0): bool
    {
        return $this->register($ident, VARIABLETYPE_FLOAT, 0.0, $name, $profile);
    }
    public function RegisterVariableString(string $ident, string $name, string $profile = '', int $position = 0): bool
    {
        return $this->register($ident, VARIABLETYPE_STRING, '', $name, $profile);
    }
    private function register(string $ident, int $type, mixed $initial, string $name, string $profile): bool
    {
        $id = $this->GetIDForIdent($ident);
        if ($id !== false && IPS_GetVariable($id)['VariableType'] === $type) {
            $GLOBALS['testVariables'][$id]['profile'] = $profile;
            return false;
        }
        if ($id !== false) {
            unset($GLOBALS['testVariables'][$id]);
        }
        $GLOBALS['testVariables'][++$GLOBALS['testNextID']] = [
            'ident' => $ident, 'instance' => $this->InstanceID, 'type' => $type, 'value' => $initial,
            'name' => $name, 'profile' => $profile
        ];
        return true;
    }
    protected function UnregisterVariable(string $ident): void
    {
        $id = $this->GetIDForIdent($ident);
        if ($id !== false) {
            unset($GLOBALS['testVariables'][$id]);
        }
    }
    protected function SetValue(string $ident, mixed $value): bool
    {
        $id = $this->GetIDForIdent($ident);
        if ($id === false) {
            throw new RuntimeException('Variable missing: ' . $ident);
        }
        $GLOBALS['testVariables'][$id]['value'] = $value;
        ++$this->writes;
        return true;
    }
}
