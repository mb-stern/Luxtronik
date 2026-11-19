<?php

trait LuxtronikVariablePresentation
{
    // The former WPLUX profile settings now define the presentation directly on each variable.
    private const PROFILE_DEFAULTS = [
        'WPLUX.Imp' => ['Digits' => 0, 'Suffix' => ' impulse'],
        'WPLUX.Typ' => ['Digits' => 0],
        'WPLUX.Biv' => ['MinValue' => 1, 'MaxValue' => 3, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            1 => 'ein Verdichter darf laufen', 2 => 'zwei Verdichter dürfen laufen', 3 => 'zusätzlicher Wärmeerzeuger darf mitlaufen'
        ]],
        'WPLUX.BZ' => ['MinValue' => 0, 'MaxValue' => 7, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Heizen', 1 => 'Warmwasser', 2 => 'Schwimmbad / Photovoltaik',
            3 => 'EVU', 4 => 'Abtauen', 5 => 'Keine Anforderung',
            6 => 'Heizen ext. Energiequelle', 7 => 'Kühlbetrieb '
        ]],
        'WPLUX.Off' => ['MinValue' => 1, 'MaxValue' => 9, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            1 => 'Wärmepumpe Störung', 2 => 'Anlagen Störung', 3 => 'Betriebsart Zweiter Wärmeerzeuger',
            4 => 'EVU-Sperre', 5 => 'Lauftabtau (nur LW-Geräte)', 6 => 'Temperatur Einsatzgrenze maximal',
            7 => 'Temperatur Einsatzgrenze minimal', 8 => 'Untere Einsatzgrenze', 9 => 'Keine Anforderung '
        ]],
        'WPLUX.Comf' => ['MinValue' => 0, 'MaxValue' => 1, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'nicht verbaut', 1 => 'verbaut'
        ]],
        'WPLUX.Men1' => ['MinValue' => 0, 'MaxValue' => 7, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Wärmepumpe läuft', 1 => 'Wärmepumpe steht', 2 => 'Wärmepumpe kommt',
            3 => 'Fehlercode Speicherplatz 0', 4 => 'Abtauen', 5 => 'Warte auf LIN-Verbindung',
            6 => 'Verdichter heizt auf', 7 => 'Pumpenvorlauf '
        ]],
        'WPLUX.Men2' => ['MinValue' => 0, 'MaxValue' => 1, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'seit :', 1 => 'in : '
        ]],
        'WPLUX.Men3' => ['MinValue' => 0, 'MaxValue' => 17, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Heizbetrieb', 1 => 'Keine Anforderung', 2 => 'Netz-Einschaltverzögerung',
            3 => 'Schaltspielsperre', 4 => 'Sperrzeit', 5 => 'Brauchwasser',
            6 => 'Info Ausheizprogramm', 7 => 'Abtauen', 8 => 'Pumpenvorlauf',
            9 => 'Thermische Desinfektion', 11 => 'Heizbetrieb', 12 => 'Schwimmbad / Photovoltaik',
            13 => 'Heizen ext. Energiequelle', 14 => 'Brauchwasser ext. Energiequelle', 16 => 'Durchflussüberachung',
            17 => 'Zweiter Wärmeerzeuger 1 Betrieb '
        ]],
        'WPLUX.Akt' => ['MinValue' => 0, 'MaxValue' => 1, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'inaktiv', 1 => 'aktiv'
        ]],
        'WPLUX.Pres' => ['Digits' => 1, 'Suffix' => ' bar'],
        'WPLUX.Fan' => ['Digits' => 0, 'Suffix' => ' rpm'],
        'WPLUX.Ver' => ['Digits' => 0, 'Suffix' => ' rpm'],
        'WPLUX.HzState' => ['MinValue' => 0, 'MaxValue' => 4, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Aus', 1 => 'Normal', 2 => 'Abgesenkt',
            3 => 'Heizgrenze', 4 => 'Frostschutz'
        ]],
        'WPLUX.Bet' => ['MinValue' => 0, 'MaxValue' => 12, 'StepSize' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Aus', 1 => 'Kühlung', 2 => 'Heizung',
            3 => 'Störung', 4 => 'Übergang', 5 => 'Abtauen',
            6 => 'Warte', 7 => 'Warte', 8 => 'Übergang',
            9 => 'Stop', 10 => 'Manuell ', 11 => 'Simulation Start',
            12 => 'EVU Sperre'
        ]],
        'WPLUX.lh' => ['Digits' => 0, 'Suffix' => ' l/h'],
        'WPLUX.Wwhe' => ['MinValue' => 0, 'MaxValue' => 4, 'Digits' => 0, 'Labels' => [
            0 => 'Automatik', 1 => 'Zus. Wärmeerzeugun', 2 => 'Party',
            3 => 'Ferien', 4 => 'Aus'
        ]],
        'WPLUX.Kue' => ['MinValue' => 0, 'MaxValue' => 1, 'Digits' => 0, 'Labels' => [
            0 => 'Aus', 1 => 'Automatik'
        ]],
        'WPLUX.Tset' => ['MinValue' => -5, 'MaxValue' => 5, 'StepSize' => 0.5, 'Digits' => 1, 'Suffix' => ' °C'],
        'WPLUX.Wset' => ['MinValue' => 30, 'MaxValue' => 65, 'StepSize' => 0.5, 'Digits' => 1, 'Suffix' => ' °C'],
        'WPLUX.Rset' => ['MinValue' => 0, 'MaxValue' => 35, 'StepSize' => 0.5, 'Digits' => 1, 'Suffix' => ' °C'],
        'WPLUX.Std' => ['Digits' => 0, 'Suffix' => ' Std.'],
        'WPLUX.kW' => ['Digits' => 2, 'Suffix' => ' kW'],
        'WPLUX.Cop' => ['Digits' => 1],
        'WPLUX.EEV.Percent' => ['MinValue' => 0, 'MaxValue' => 100, 'Digits' => 1, 'Suffix' => ' %'],
    ];

    private function RegisterLuxtronikVariable(
        string $type,
        string $ident,
        string $name,
        string|array $definition,
        int $position,
        bool $adjustable = false,
        int $digits = 0,
        string $formerProfile = ''
    ): bool {
        [$variableType, $register] = match ($type) {
            'bool' => [VARIABLETYPE_BOOLEAN, 'RegisterVariableBoolean'],
            'int' => [VARIABLETYPE_INTEGER, 'RegisterVariableInteger'],
            'float' => [VARIABLETYPE_FLOAT, 'RegisterVariableFloat'],
            default => [VARIABLETYPE_STRING, 'RegisterVariableString']
        };
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false) {
            $variable = IPS_GetVariable($id);
            // Re-registering with another type would delete the existing variable.
            if ($variable['VariableType'] !== $variableType) return false;
            $current = $variable['VariablePresentation'] ?? [];
            if (is_string($definition)) {
                $legacy = $current['PROFILE'] ?? $variable['VariableProfile'] ?? '';
                if ($legacy !== '' && $legacy !== $definition && !($ident === 'Anpassung_RBE' && $legacy === 'WPLUX.Wset')) {
                    // Leave an independently assigned default profile in place.
                    return false;
                }
            }
        }

        $presentation = is_string($definition)
            ? $this->PresentationFromProfile($type, $definition, $adjustable, $digits)
            : $definition;
        if ($id !== false) {
            if (array_intersect_key($current, $presentation) != $presentation) {
                $this->$register($ident, IPS_GetName($id), $presentation, IPS_GetObject($id)['ObjectPosition']);
            }
            $this->MigrateFormerProfileOverride($id, $variable, $type,
                is_string($definition) ? $definition : $formerProfile, $adjustable, $presentation);
            return false;
        }
        return $this->$register($ident, $name, $presentation, $position);
    }

    private function MigrateFormerProfileOverride(int $id, array $variable, string $type, string $formerProfile,
        bool $adjustable, array $presentation): void
    {
        $custom = $variable['VariableCustomPresentation'] ?? [];
        if ($custom !== [] && ($custom['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_LEGACY) return;
        $profile = $custom['PROFILE'] ?? $variable['VariableCustomProfile'] ?? '';
        if ($profile === '' || $profile !== $formerProfile) return;

        $replacement = $presentation;
        if (!in_array($profile, ['~UnixTimestamp', '~UnixTimestampTime', '~Switch'], true) && IPS_VariableProfileExists($profile)) {
            $replacement = $this->BuildVariablePresentation($type, IPS_GetVariableProfile($profile), $adjustable);
        }
        // A duplicate of the module default can inherit future changes. Preserve any distinct options or formatting.
        $default = IPS_GetVariable($id)['VariablePresentation'] ?? [];
        $inheritsDefault = $replacement == $presentation && array_intersect_key($default, $presentation) == $presentation;
        IPS_SetVariableCustomPresentation($id, $inheritsDefault ? [] : $replacement);
    }

    private function PresentationFromProfile(string $type, string $profile, bool $adjustable, int $digits): array
    {
        // Timestamp values use the same Unix time representation, including for time programs.
        if ($profile === '~UnixTimestamp' || $profile === '~UnixTimestampTime') {
            $presentation = ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 0, 'TIME' => 1];
            if ($profile === '~UnixTimestamp') {
                $presentation['DATE'] = 1;
                $presentation['MONTH_TEXT'] = true;
            }
            return $presentation;
        }
        if ($profile === '~Switch' && $adjustable) {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
        }
        $definition = $this->VariablePresentationDefinition($profile);
        $definition['Digits'] ??= $digits;
        return $this->BuildVariablePresentation($type, $definition, $adjustable);
    }

    private function VariablePresentationDefinition(string $profile): array
    {
        $definition = self::PROFILE_DEFAULTS[$profile] ?? null;
        if ($profile === '~Switch') $definition = ['Labels' => [0 => 'Aus', 1 => 'Ein']];
        if ($definition === null) {
            return $profile !== '' && IPS_VariableProfileExists($profile) ? IPS_GetVariableProfile($profile) : [];
        }
        foreach ($definition['Labels'] ?? [] as $value => $label) {
            $definition['Associations'][] = ['Value' => $value, 'Name' => $label];
        }
        return $definition;
    }

    private function BuildVariablePresentation(string $type, array $definition, bool $adjustable): array
    {
        $valuePresentation = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
        $associations = $definition['Associations'] ?? [];
        $numeric = $type === 'int' || $type === 'float';
        if ($numeric && !$adjustable && $associations !== []) {
            // Read-only numeric variables use value displays. A single-value interval preserves each status caption.
            $intervals = [];
            foreach ($associations as $association) {
                $value = $type === 'int' ? (int) $association['Value'] : (float) $association['Value'];
                $interval = ['IntervalMinValue' => $value, 'IntervalMaxValue' => $value,
                    'ConstantActive' => true, 'ConstantValue' => $association['Name']];
                if (!empty($association['Icon'])) {
                    $interval['IconActive'] = true;
                    $interval['IconValue'] = $association['Icon'];
                }
                if (($association['Color'] ?? -1) >= 0) {
                    $interval['ColorActive'] = true;
                    $interval['ColorValue'] = $association['Color'];
                }
                $intervals[] = $interval;
            }
            return ['PRESENTATION' => $valuePresentation, 'ICON' => $definition['Icon'] ?? '',
                'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervals, JSON_THROW_ON_ERROR)];
        }
        if ($associations !== []) {
            $presentation = $adjustable ? VARIABLE_PRESENTATION_ENUMERATION : $valuePresentation;
            $options = [];
            foreach ($associations as $association) {
                $icon = $association['Icon'] ?? '';
                $color = $association['Color'] ?? -1;
                $option = [
                    'Value' => $this->CastValueForVariableType($association['Value'], match ($type) {
                        'bool' => VARIABLETYPE_BOOLEAN, 'int' => VARIABLETYPE_INTEGER,
                        'float' => VARIABLETYPE_FLOAT, default => VARIABLETYPE_STRING
                    }),
                    'Caption' => $association['Name'],
                    'IconActive' => $icon !== '',
                    'IconValue' => $icon
                ];
                if ($color >= 0 && $presentation === $valuePresentation) {
                    $option['ColorActive'] = true;
                    $option['ColorValue'] = $color;
                } elseif ($color >= 0) {
                    $option['Color'] = $color;
                }
                $options[] = $option;
            }
            return ['PRESENTATION' => $presentation, 'ICON' => $definition['Icon'] ?? '',
                'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }

        $presentation = ['PRESENTATION' => $valuePresentation, 'PREFIX' => $definition['Prefix'] ?? '',
            'SUFFIX' => $definition['Suffix'] ?? '', 'ICON' => $definition['Icon'] ?? ''];
        if (!$numeric) return $presentation;

        $presentation['PERCENTAGE'] = false; // Engineering values already use their real units.
        if ($type === 'float') $presentation['DIGITS'] = (int) ($definition['Digits'] ?? 0);
        $min = (float) ($definition['MinValue'] ?? 0);
        $max = (float) ($definition['MaxValue'] ?? 0);
        if ($max > $min) {
            $presentation['MIN'] = $min;
            $presentation['MAX'] = $max;
            if ($adjustable) {
                $presentation['PRESENTATION'] = VARIABLE_PRESENTATION_SLIDER;
                $presentation['STEP_SIZE'] = (float) ($definition['StepSize'] ?? 0);
            }
        }
        return $presentation;
    }

    private function UpdateStoredMeasurementPresentations(): void
    {
        $web = $this->WebDefinitions();
        $shi = $this->SHISelections();
        $javaByIdent = [];
        foreach (self::DATA_POINT_CONFIG as $id => $point) $javaByIdent[$point['name']] = $id;
        $shiByIdent = array_column($shi, null, 'ident');
        $webByIdent = [];
        foreach ($web as $id => $point) $webByIdent[$point['ident']] = $id;

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $objectId) {
            if (!IPS_VariableExists($objectId)) continue;
            $ident = IPS_GetObject($objectId)['ObjectIdent'];
            if (isset($javaByIdent[$ident])) {
                $id = $javaByIdent[$ident];
                $this->EnsureJavaMeasurementVariable($ident, $id, $this->GetDataPointConfig($id));
            } elseif (isset($shiByIdent[$ident])) {
                $this->EnsureSHIVariable($shiByIdent[$ident]);
            } elseif (isset($webByIdent[$ident])) {
                $id = $webByIdent[$ident];
                $this->EnsureWebVariable($id, $web[$id], setPosition: false);
            } elseif (preg_match('/^ID_(\d+)$/D', $ident, $match) && (int) $match[1] < self::MAX_RESPONSE_VALUES) {
                $id = (int) $match[1];
                $point = $this->GetDataPointConfig($id);
                if ($point['name'] === $ident) $this->EnsureJavaMeasurementVariable($ident, $id, $point);
            }
        }
    }

    private function EnsureJavaMeasurementVariable(string $ident, int $id, array $point): string
    {
        $type = $point['type'];
        $profile = $point['profile'];
        if ($id >= 203 && $id <= 207) {
            // Early releases stored these temperatures in string variables.
            $existing = @$this->GetIDForIdent($ident);
            if ($existing !== false && IPS_GetVariable($existing)['VariableType'] === VARIABLETYPE_STRING) {
                $type = 'string';
                $profile = '';
            }
        }
        $this->RegisterLuxtronikVariable($type, $ident, $ident, $profile, $id, digits: $point['decimals']);
        return $type;
    }
}
