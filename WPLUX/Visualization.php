<?php

/**
 * Heat-pump visualization adapted from mb-stern/Waermepumpe, development,
 * aae3f06c0b8e148b5671386797368771bcd3e8e1. Source license: heat-pump/LICENSE.
 * The graphic reads converted protocol values from an internal buffer and optional
 * external variables. It does not create measurement objects or query devices.
 */
trait LuxtronikVisualization
{
    private const LUXTRONIK_IDENT_MAP = [
        'OperatingStatusVariable' => ['Betriebszustand'],
        'HeatingControlVariable' => ['Mode_Heizung'],
        'HotWaterControlVariable' => ['Mode_WW'],
        'CoolingControlVariable' => ['Mode_Kuehlung'],
        'WarmWaterSetpointVariable' => ['Anpassung_WW'],
        'HeatingTemperatureCorrectionVariable' => ['Anpassung_Temp'],
        'OutdoorTemperature' => ['Aussentemperatur'],
        'AmbientTemperatureNormal' => ['Desired_Room_Temperature'],
        'AmbientTemperatureActual' => ['Raumtemperatur_Raumstation_1'],
        'SupplyTemperature' => ['Vorlauftemperatur_Heizkreis'],
        'ReturnTemperatureSetpointVariable' => ['Ruecklauf_Soll_Heizkreis'],
        'FanSpeed' => ['Ventilator_Drehzahl'],
        'HpRunning' => ['Ausgang_Solepumpe_Ventilator'],
        'CompressorRunning' => ['Ausgang_Verdichter_1'],
        'CompressorValue' => ['Verdichterfrequenz'],
        'HeatingPumpPower' => ['Waermeleistung'],
        'EvaporatorPressure' => ['Niederdruck'],
        'EvaporatorTemperature' => ['Vapourisation_Temperature'],
        'CondenserPressure' => ['Hochdruck'],
        'CondenserTemperature' => ['Liquefaction_Temperature'],
        'SupplyTemperatureHeating1' => ['Mischkreis_1_Vorlauftemperatur'],
        'HeatingCircuitPumpRunning1' => ['Ausgang_Heizungsumwaelzpumpe'],
        'HeatingCircuitPumpValue1' => ['Steuersignal_Umwaelzpumpe'],
        'TankTempWWUp' => ['Temperatur_Brauchwasser_Oben'],
        'TankTempWWMiddle' => ['Warmwasser_Ist_Temperatur'],
        'WWHeatingValve' => ['Ausgang_Brauchwasserpumpe_Umstellventil'],
        'StorageChargingPumpRunning' => ['Ausgang_Brauchwasserpumpe_Umstellventil'],
        'CirculatingPumpRunning' => ['Ausgang_Zirkulationspumpe'],
        'ThermalSolarPanelTemp' => ['Fuehler_Solarkollektor']
    ];

    private const VISUALIZATION_EXTRA_IDENT_MAP = [
        'PoolControlVariable' => ['Mode_Schwimmbad'],
        'RoomTemperatureSetpointVariable' => ['Anpassung_RBE'],
        'HeatPumpReturnTemperature' => ['Ruecklauftemperatur_Heizkreis'],
        'SupplyTemperatureHeating2' => ['Mischkreis_2_Vorlauftemperatur'],
        'SupplyTemperatureHeating3' => ['Mischkreis_3_Vorlauftemperatur'],
        'MixerActual1' => ['Mischkreis_1_Vorlauftemperatur'], 'MixerTarget1' => ['Mischkreis_1_Vorlauf_Soll_Temperatur'],
        'MixerOpen1' => ['Ausgang_Mischkreis_1_Auf'], 'MixerClose1' => ['Ausgang_Mischkreis_1_Zu'],
        'MixerActual2' => ['Mischkreis_2_Vorlauftemperatur'], 'MixerTarget2' => ['Mischkreis_2_Vorlauf_Soll_Temperatur'],
        'MixerOpen2' => ['Ausgang_Mischkreis_2_Auf'], 'MixerClose2' => ['Ausgang_Mischkreis_2_Zu'],
        'MixerActual3' => ['Mischkreis_3_Vorlauftemperatur'], 'MixerTarget3' => ['Mischkreis_3_Vorlauf_Soll_Temperatur'],
        'MixerOpen3' => ['Ausgang_Mischkreis_3_Auf'], 'MixerClose3' => ['Ausgang_Mischkreis_3_Zu'],
        'MixerPump1' => ['Ausgang_Heizungsumwaelzpumpe'],
        'MixerPump2' => ['Ausgang_Pumpe_Mischkreis_2'], 'MixerPump3' => ['Pumpe_Mischkreis_3'],
        'ComputedCOP' => ['copfaktor'], 'ComputedJAZ' => ['jazfaktor'],
        'InputPower' => ['Leistungsaufnahme'],
        'HeatingFlow' => ['Flow_Rate'],
        'CompressorFrequencyTarget' => ['Verdichterfrequenz_Soll'],
        'HeatPumpRuntime' => ['Waermepumpe_laeuft_seit'],
        'CompressorStandstill' => ['Verdichter_Standzeit'],
        // Confirmed WebSocket engineering values; no numeric Java indices are invented.
        'EEVHeatingOpening' => ['EEV_Heizen'], 'EEVCoolingOpening' => ['EEV_Kuehlen'],
        'HotGasTemperature' => ['Heisgastemperatur'],
        'TimeUntilDefrost' => ['Zeit_bis_Abtauen'],
        'SolarStorageTemperature' => ['Fuehler_Solarspeicher'],
        'ThermalSolarPump' => ['Ausgang_Solarladepumpe']
    ];

    private function CreateVisualization(): void
    {

        // Optional browser endpoint override; the normal endpoint uses this instance IPAddress.
        $this->RegisterPropertyString('LuxtronikWebInterfaceURL', '');
        $this->RegisterPropertyBoolean('WebSocketSupplement', true);
        $this->RegisterPropertyString('WebSocketPassword', '999999');
        $this->RegisterPropertyBoolean('VisualizationDirectCircuit', true);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit1', false);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit2', false);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit3', false);
        $this->RegisterPropertyInteger('HeatPumpReturnTemperature', 0);
        // Retain stored settings from earlier development builds; no longer exposed or used.
        $this->RegisterPropertyInteger('VisualizationInputPower', 0);
        $this->RegisterPropertyInteger('VisualizationHeatingPumpSpeed', 0);
        for ($i = 1; $i <= 3; ++$i) {
            $this->RegisterPropertyBoolean('VisualizationHeatingCircuit' . $i, $i === 1);
            $this->RegisterPropertyInteger('VisualizationHeatingCircuitMixer' . $i, $i);
            $this->RegisterPropertyInteger('VisualizationRoomVariable' . $i, 0);
            $this->RegisterPropertyString('VisualizationRoomLabel' . $i, '');
            $this->RegisterPropertyString('VisualizationRoomDisplay' . $i, 'both');
            $this->RegisterPropertyInteger('VisualizationHeaterSwitch' . $i, 0);
            $this->RegisterPropertyString('VisualizationHeaterOnValue' . $i, '');
            $this->RegisterPropertyString('VisualizationHeaterOffValue' . $i, '');
        }
        $this->RegisterPropertyBoolean('VisualizationRoomIcons', true);
        $this->RegisterPropertyInteger('VisualizationWeatherCode', 0);
        $this->RegisterPropertyBoolean('VisualizationCirculation', false);
        $this->RegisterPropertyString('VisualizationSolarTarget', 'hotwater');

        // Zentrale Status- und Steuerungszuordnung
        $this->RegisterPropertyInteger('OperatingStatusVariable', 0);
        $this->RegisterPropertyString('OperatingStatusHeatingValues', '0,6');
        $this->RegisterPropertyString('OperatingStatusHotWaterValues', '1');
        $this->RegisterPropertyString('OperatingStatusCoolingValues', '7');
        $this->RegisterPropertyString('OperatingStatusDefrostValues', '4');

        $this->RegisterPropertyInteger('HeatingControlVariable', 0);
        $this->RegisterPropertyInteger('HotWaterControlVariable', 0);
        $this->RegisterPropertyInteger('CoolingControlVariable', 0);

        // Direkt bedienbare Sollwerte in der oberen Icon-Leiste
        $this->RegisterPropertyInteger('WarmWaterSetpointVariable', 0);
        $this->RegisterPropertyInteger('HeatingTemperatureCorrectionVariable', 0);

        // Luft/Wasser: Lüfterdrehzahl > 0 hat Vorrang vor dem Status-Fallback.
        $this->RegisterPropertyInteger('FanSpeed', 0);

        // Primärquelle / Wärmepumpe
        $this->RegisterPropertyInteger('HeatingPumpStatusOnOff', 0);
        $this->RegisterPropertyInteger('HeatingPumpHotWaterMode', 0);
        $this->RegisterPropertyInteger('HeatingPumpHeatingMode', 0);
        $this->RegisterPropertyInteger('HeatingPumpCoolingMode', 0);
        $this->RegisterPropertyInteger('DefrostMode', 0);

        $this->RegisterPropertyInteger('OutdoorTemperature', 0);
        $this->RegisterPropertyInteger('AmbientTemperatureNormal', 0);
        $this->RegisterPropertyInteger('AmbientTemperatureActual', 0);
        $this->RegisterPropertyInteger('SupplyTemperature', 0);
        $this->RegisterPropertyInteger('ReturnTemperatureSetpointVariable', 0);

        $this->RegisterPropertyInteger('HpRunning', 0);
        $this->RegisterPropertyInteger('CompressorRunning', 0);
        $this->RegisterPropertyInteger('CirculatingPumpRunning', 0);
        $this->RegisterPropertyInteger('StorageChargingPumpRunning', 0);

        // Pufferspeicher
        $this->RegisterPropertyBoolean('TankHP', true);
        $this->RegisterPropertyInteger('TankTempHPUp', 0);
        $this->RegisterPropertyInteger('TankTempHPMiddle', 0);
        $this->RegisterPropertyInteger('TankTempHPDown', 0);

        // Warmwasserspeicher
        $this->RegisterPropertyBoolean('TankWW', true);
        $this->RegisterPropertyInteger('TankTempWWUp', 0);
        $this->RegisterPropertyInteger('TankTempWWMiddle', 0);
        $this->RegisterPropertyInteger('TankTempWWDown', 0);

        // Heizkreis 1
        // Sonderdarstellung bei genau einem Heizkreis:
        // Heizkreiswerte bei Warmwasserbereitung einfrieren und aktuelle
        // Vor-/Rücklauftemperatur am Warmwasserspeicher anzeigen.
        $this->RegisterPropertyBoolean('SingleCircuitHotWaterTemperatureSwitch', true);
        $this->RegisterPropertyString('HeatingCircuitType1', 'underfloor');
        $this->RegisterPropertyString('HeatingCircuitName1', 'HK1');
        $this->RegisterPropertyInteger('HeatingCircuitPumpRunning1', 0);
        $this->RegisterPropertyInteger('HeatingCircuitPumpValue1', 0);
        $this->RegisterPropertyInteger('SupplyTemperatureHeating1', 0);
        $this->RegisterPropertyInteger('RefluxTemperatureHeating1', 0);

        // Heizkreis 2
        $this->RegisterPropertyString('HeatingCircuitType2', 'off');
        $this->RegisterPropertyString('HeatingCircuitName2', 'HK2');
        $this->RegisterPropertyInteger('HeatingCircuitPumpRunning2', 0);
        $this->RegisterPropertyInteger('HeatingCircuitPumpValue2', 0);
        $this->RegisterPropertyInteger('SupplyTemperatureHeating2', 0);
        $this->RegisterPropertyInteger('RefluxTemperatureHeating2', 0);

        // Heizkreis 3
        $this->RegisterPropertyString('HeatingCircuitType3', 'off');
        $this->RegisterPropertyString('HeatingCircuitName3', 'HK3');
        $this->RegisterPropertyInteger('HeatingCircuitPumpRunning3', 0);
        $this->RegisterPropertyInteger('HeatingCircuitPumpValue3', 0);
        $this->RegisterPropertyInteger('SupplyTemperatureHeating3', 0);
        $this->RegisterPropertyInteger('RefluxTemperatureHeating3', 0);

        // Kältekreis
        $this->RegisterPropertyInteger('EvaporatorPressure', 0);
        $this->RegisterPropertyInteger('EvaporatorTemperature', 0);
        $this->RegisterPropertyInteger('CondenserPressure', 0);
        $this->RegisterPropertyInteger('CondenserTemperature', 0);
        $this->RegisterPropertyInteger('ExpansionValveOpening', 0);
        $this->RegisterPropertyInteger('CompressorValue', 0);
        $this->RegisterPropertyInteger('HeatingPumpPower', 0);

        // Ventil / Heizstab
        $this->RegisterPropertyInteger('WWHeatingValve', 0);
        $this->RegisterPropertyInteger('HeaterRodWW', 0);
        $this->RegisterPropertyInteger('HeaterRodLevel1', 0);
        $this->RegisterPropertyInteger('HeaterRodLevel2', 0);

        // Erweiterte Symcon-Darstellung: drei echte Heizstäbe im Warmwasserspeicher
        $this->RegisterPropertyInteger('HeaterRodCount', 0);
        $this->RegisterPropertyInteger('HeaterRod1', 0);
        $this->RegisterPropertyInteger('HeaterRod1Threshold', 1);
        $this->RegisterPropertyInteger('HeaterRod2', 0);
        $this->RegisterPropertyInteger('HeaterRod2Threshold', 1);
        $this->RegisterPropertyInteger('HeaterRod3', 0);
        $this->RegisterPropertyInteger('HeaterRod3Threshold', 1);

        // Solarthermie
        $this->RegisterPropertyBoolean('ThermalSolarAvailable', false);
        $this->RegisterPropertyBoolean('FullConfigurationPreview', false);
        $this->RegisterPropertyInteger('ThermalSolarPump', 0);
        $this->RegisterPropertyInteger('ThermalSolarPumpSpeed', 0);
        $this->RegisterPropertyInteger('ThermalSolarPanelTemp', 0);
        $this->RegisterPropertyInteger('ThermalSolarFluxTemp', 0);
        $this->RegisterPropertyInteger('ThermalSolarReturnTemp', 0);

        // HTML-SDK Visualisierung
        $this->SetVisualizationType(1);

        $this->SetBuffer('VisualizationRegisteredVariables', '[]');
    }

    public function GetVisualizationTile(): string
    {
        $this->RegisterVariableMessages();
        if (!$this->ResourcesAvailable()) {
            return '<div style="padding:16px;font-family:sans-serif;color:#c62828;">'
                . 'Wärmepumpen-Ressource fehlt. Erwartet wird eine lesbare .svg-Datei im Verzeichnis heat-pump/heat-pump-card/.'
                . '</div>';
        }

        return $this->BuildVisualizationHtml();
    }

    private function ResourcesAvailable(): bool
    {
        foreach ($this->GetResourceFiles() as $fileName) {
            if (!is_file($fileName) || !is_readable($fileName)) {
                return false;
            }
        }

        return true;
    }

    private function GetResourceFiles(): array
    {
        $svgDirectory = __DIR__
            . DIRECTORY_SEPARATOR
            . 'heat-pump'
            . DIRECTORY_SEPARATOR
            . 'heat-pump-card';

        $svgFiles = glob($svgDirectory . DIRECTORY_SEPARATOR . '*.svg') ?: [];
        $svgFiles = array_values(array_filter(
            $svgFiles,
            static fn(string $fileName): bool => is_file($fileName) && is_readable($fileName)
        ));

        // Der Dateiname der SVG ist absichtlich egal.
        // Liegen mehrere SVG-Dateien im Verzeichnis, wird die zuletzt
        // geänderte Datei verwendet. Bei gleichem Änderungszeitpunkt
        // entscheidet der Dateiname eindeutig.
        usort(
            $svgFiles,
            static function (string $a, string $b): int {
                $timeCompare = filemtime($b) <=> filemtime($a);
                return $timeCompare !== 0 ? $timeCompare : strnatcasecmp(basename($b), basename($a));
            }
        );

        return [
            'svg' => $svgFiles[0] ?? ($svgDirectory . DIRECTORY_SEPARATOR . '__keine_svg_vorhanden__.svg')
        ];
    }

    private function BuildVisualizationHtml(): string
    {
        $files = $this->GetResourceFiles();

        $appJs = $this->GetEmbeddedJavaScript();
        $svg = file_get_contents($files['svg']);

        if ($svg === false) {
            return '<div style="padding:16px;font-family:sans-serif;color:#c62828;">'
                . 'Die Wärmepumpen-SVG konnte nicht gelesen werden.'
                . '</div>';
        }

        /*
         * Die wenigen benötigten Texte sind Bestandteil unseres Moduls.
         * Eine separate de.json wird nicht mehr benötigt.
         */
        $localization = [
            'svgTexts' => [
                'tankWWName'             => 'Warmwasser',
                'tankHPName'             => 'Pufferspeicher',
                'evaporator'             => 'Verdampfer',
                'condenser'              => 'Kondensator',
                'compressor'             => 'Verdichter',
                'expansionValve'         => 'Expansionsventil',
                'circulatingPump'        => 'Zirkulationspumpe',
                'supplyTemperatureLabel' => 'Vorlauftemperatur'
            ]
        ];

        $payloadJson = json_encode(
            [
                'config'       => $this->BuildCardConfig(),
                'data'         => $this->BuildVisualizationData(),
                'controls'     => $this->BuildControlData(),
                'svg'          => $svg,
                'localization' => $localization
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
        );

        $appJs = str_replace('</script>', '<\/script>', $appJs);

        return <<<HTML
<style>
    html,
    body {
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
        background: transparent !important;
        color: inherit;
        font-family: Arial, sans-serif;
    }

    #wp-root {
        position: relative;
        width: 100%;
        height: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }

    #wp-error {
        display: none;
        margin: 8px;
        padding: 10px;
        white-space: pre-wrap;
        border: 1px solid #c62828;
        border-radius: 6px;
        color: #c62828;
        font-size: 12px;
        line-height: 1.45;
    }

    heat-pump-card,
    heat-pump-card ha-card {
        display: block;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }

    heat-pump-card ha-card {
        background: transparent !important;
        box-shadow: none !important;
        border: 0 !important;
    }

    heat-pump-card svg {
        display: block;
        width: 100% !important;
        height: 100% !important;
        max-width: 100%;
        max-height: 100%;
    }

    #wp-mode-menu {
        display: none;
        position: fixed;
        z-index: 2147483647;
        box-sizing: border-box;
        width: min(360px, calc(100vw - 24px));
        max-height: min(70vh, 520px);
        overflow-y: auto;
        left: 50%;
        bottom: 12px;
        transform: translateX(-50%);
        padding: 8px;
        border-radius: 12px;
        border: 1px solid rgba(127,127,127,.35);
        background: Canvas;
        color: CanvasText;
        box-shadow: 0 8px 28px rgba(0,0,0,.28);
        font: 14px Arial, sans-serif;
        touch-action: manipulation;
        -webkit-tap-highlight-color: transparent;
    }

    /*
     * Auf genügend breiten Desktop-Ansichten darf das Menü kompakt
     * mittig erscheinen. Auf dem Handy bleibt es als Bottom-Sheet unten.
     */
    @media (min-width: 700px) {
        #wp-mode-menu {
            top: 50%;
            bottom: auto;
            transform: translate(-50%, -50%);
        }
    }


    #wp-mode-menu .wp-mode-title {
        padding: 6px 8px;
        font-weight: 600;
        opacity: .8;
    }

    #wp-mode-menu button {
        display: block;
        width: 100%;
        margin: 2px 0;
        padding: 8px 10px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: inherit;
        text-align: left;
        cursor: pointer;
    }

    #wp-mode-menu button:hover {
        background: rgba(127,127,127,.16);
    }

    #wp-mode-menu button.active {
        background: rgba(127,127,127,.34);
        font-weight: 600;
    }

    #wp-mode-menu .wp-numeric-row {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 4px 6px 8px;
    }

    #wp-mode-menu .wp-numeric-row button {
        width: 38px;
        min-width: 38px;
        text-align: center;
        font-size: 18px;
        padding: 6px;
    }

    #wp-mode-menu .wp-numeric-row input {
        width: 92px;
        box-sizing: border-box;
        padding: 7px 6px;
        border: 1px solid rgba(127,127,127,.45);
        border-radius: 5px;
        background: Canvas;
        color: CanvasText;
        text-align: right;
        font: inherit;
    }

    #wp-mode-menu .wp-numeric-unit {
        min-width: 28px;
        opacity: .8;
    }

    #wp-luxtronik-dialog {
        width: 760px;
        max-width: calc(100vw - 24px);
        max-height: calc(100vh - 24px);
        box-sizing: border-box;
        padding: 12px;
        border: 1px solid var(--divider-color, #888);
        border-radius: 10px;
        color-scheme: light dark;
        background: Canvas;
        color: CanvasText;
    }
    #wp-luxtronik-dialog[open] { display: flex; flex-direction: column; gap: 10px; }
    #wp-luxtronik-dialog::backdrop { background: rgba(0, 0, 0, .4); }
    #wp-luxtronik-dialog .wp-luxtronik-header { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
    #wp-luxtronik-dialog .wp-luxtronik-header strong { flex: 1; }
    #wp-luxtronik-dialog a { color: inherit; }
    #wp-luxtronik-help { margin: 0; font-size: 14px; }
    #wp-luxtronik-login { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    #wp-luxtronik-password { width: 110px; max-width: 100%; }
    #wp-eev-diagnostic summary { cursor: pointer; }
    #wp-eev-result { display: block; width: 100%; box-sizing: border-box; margin-top: 8px; font: 13px monospace; }
    #wp-luxtronik-device { padding: 24px; border-radius: 14px; background: linear-gradient(145deg, #59616a, #30363d); }
    #wp-luxtronik-display { display: block; width: 100%; height: auto; background: #0000ff; image-rendering: pixelated; border: 8px solid #252a30; border-radius: 5px; box-sizing: border-box; cursor: pointer; touch-action: manipulation; }
    #wp-luxtronik-controls { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 14px; border: 0; margin: 22px 0 0; padding: 0; }
    #wp-luxtronik-controls button { min-height: 48px; padding: 8px 14px; touch-action: manipulation; }
    #wp-luxtronik-press { min-width: 76px; }
    #wp-luxtronik-controls:disabled { opacity: .45; }
    #wp-luxtronik-dialog button, #wp-luxtronik-dialog input { font: inherit; }
    @media (max-width: 480px) {
        #wp-luxtronik-device { padding: 12px; }
        #wp-luxtronik-controls { gap: 6px; }
        #wp-luxtronik-controls button { padding: 6px 9px; }
    }
</style>

<div id="wp-root">
    <div id="wp-error"></div>
    <heat-pump-card id="wp-card"></heat-pump-card>
    <div id="wp-mode-menu"></div>
</div>
<dialog id="wp-luxtronik-dialog" aria-labelledby="wp-luxtronik-title">
    <div class="wp-luxtronik-header">
        <strong id="wp-luxtronik-title">LUX Bedienfeld</strong>
        <a id="wp-luxtronik-external" target="_blank" rel="noopener noreferrer">Originalinterface öffnen</a>
        <button id="wp-luxtronik-close" type="button">Schließen</button>
    </div>
    <p id="wp-luxtronik-help" role="status" aria-live="polite"></p>
    <form id="wp-luxtronik-login">
        <label for="wp-luxtronik-password">Webinterface-Passwort</label>
        <input id="wp-luxtronik-password" type="password" value="999999" autocomplete="off" />
        <button type="submit">Erneut verbinden</button>
        <button id="wp-eev-test" type="button">EEV-Diagnose starten</button>
        <button id="wp-web-test" type="button">Alle WebSocket-Werte testen</button>
    </form>
    <details id="wp-eev-diagnostic">
        <summary>Diagnoseergebnis</summary>
        <button id="wp-eev-copy" type="button" disabled>Ergebnis kopieren</button>
        <textarea id="wp-eev-result" rows="8" readonly aria-label="EEV-Diagnoseergebnis" spellcheck="false"></textarea>
    </details>
    <div id="wp-luxtronik-device">
        <canvas id="wp-luxtronik-display" width="160" height="64" tabindex="0" role="img" aria-label="Luxtronik-Gerätedisplay; Bedienung mit Pfeiltasten und Enter"></canvas>
        <fieldset id="wp-luxtronik-controls" disabled aria-label="Luxtronik bedienen">
            <button type="button" data-luxtronik-turn="1" aria-label="Pfeil links">←</button>
            <button type="button" id="wp-luxtronik-press">Enter</button>
            <button type="button" data-luxtronik-turn="0" aria-label="Pfeil rechts">→</button>
        </fieldset>
    </div>
</dialog>

<script src="/icons.js"></script>
<script>
{$appJs}
</script>

<script>
SymconHeatPump.init({$payloadJson});
</script>
HTML;
    }

    private function BuildCardConfig(): array
    {
        $config = [
            'instanceId'                 => $this->InstanceID,
            'title'                      => '',
            'fullConfigurationPreview'   => $this->ReadPropertyBoolean('FullConfigurationPreview'),
            'luxtronikWebInterfaceURL'    => $this->GetLuxtronikWebInterfaceURL(),
            'heatingPumpType'            => 'A2W',

            'operatingStatus'             => $this->DataKey('OperatingStatusVariable', 'operatingStatus'),
            'heatingPumpStatusOnOff'     => $this->DataKey('HeatingPumpStatusOnOff', 'heatingPumpStatusOnOff'),
            'heatingPumpHotWaterMode'    => $this->HasOperatingStatus() ? 'heatingPumpHotWaterMode' : $this->DataKey('HeatingPumpHotWaterMode', 'heatingPumpHotWaterMode'),
            'heatingPumpHeatingMode'     => $this->HasOperatingStatus() ? 'heatingPumpHeatingMode' : $this->DataKey('HeatingPumpHeatingMode', 'heatingPumpHeatingMode'),
            'heatingPumpCoolingMode'     => $this->HasOperatingStatus() ? 'heatingPumpCoolingMode' : $this->DataKey('HeatingPumpCoolingMode', 'heatingPumpCoolingMode'),
            'defrostMode'                => $this->HasOperatingStatus() ? 'defrostMode' : $this->DataKey('DefrostMode', 'defrostMode'),

            'outdoorTemperature'         => $this->DataKey('OutdoorTemperature', 'outdoorTemperature'),
            'ambientTemperatureNormal'   => $this->DataKeyWithFallback('AmbientTemperatureNormal', 'AmbientTemperatureActual', 'ambientTemperatureNormal'),
            'supplyTemperature'          => $this->DataKey('SupplyTemperature', 'supplyTemperature'),
            'returnTemperatureSetpoint'  => $this->DataKey('ReturnTemperatureSetpointVariable', 'returnTemperatureSetpoint'),
            'heatPumpReturnTemperature' => $this->DataKey('HeatPumpReturnTemperature', 'heatPumpReturnTemperature'),

            'hpRunning'                  => $this->DataKeyWithFallback('FanSpeed', 'HpRunning', 'hpRunning'),
            'fanSpeed'                   => $this->DataKey('FanSpeed', 'fanSpeed'),
            'compressorRunning'          => $this->DataKey('CompressorRunning', 'compressorRunning'),
            'circulatingPumpRunning'     => $this->DataKey('CirculatingPumpRunning', 'circulatingPumpRunning'),
            'storageChargingPumpRunning' => $this->DataKey('StorageChargingPumpRunning', 'storageChargingPumpRunning'),

            'tankHP'                     => $this->ReadPropertyBoolean('TankHP'),
            'tankTempHPUp'               => $this->DataKey('TankTempHPUp', 'tankTempHPUp'),
            'tankTempHPMiddle'           => $this->DataKey('TankTempHPMiddle', 'tankTempHPMiddle'),
            'tankTempHPDown'             => $this->DataKey('TankTempHPDown', 'tankTempHPDown'),

            'tankWW'                     => $this->ReadPropertyBoolean('TankWW'),
            'layeredChargeStorage'       => false,
            'tankTempWWUp'               => $this->DataKey('TankTempWWUp', 'tankTempWWUp'),
            'tankTempWWMiddle'           => $this->DataKey('TankTempWWMiddle', 'tankTempWWMiddle'),
            'tankTempWWDown'             => $this->DataKey('TankTempWWDown', 'tankTempWWDown'),

            'singleCircuitHotWaterTemperatureSwitch' => false,
            'heatingCircuitType1'        => $this->ReadPropertyString('HeatingCircuitType1'),
            'heatingCircuitName1'             => $this->ReadPropertyString('HeatingCircuitName1'),
            'heatingCircuitPumpRunning'  => $this->DataKey('HeatingCircuitPumpRunning1', 'heatingCircuitPumpRunning'),
            'heatingCircuitPumpValue' => $this->DataKey('HeatingCircuitPumpValue1', 'heatingCircuitPumpValue'),
            'supplyTemperatureHeating'   => $this->DataKey('SupplyTemperatureHeating1', 'supplyTemperatureHeating'),
            'refluxTemperatureHeating'   => $this->DataKey('RefluxTemperatureHeating1', 'refluxTemperatureHeating'),

            'heatingCircuitType2'        => $this->ReadPropertyString('HeatingCircuitType2'),
            'heatingCircuitName2'             => $this->ReadPropertyString('HeatingCircuitName2'),
            'heatingCircuitPumpRunning2' => $this->DataKey('HeatingCircuitPumpRunning2', 'heatingCircuitPumpRunning2'),
            'heatingCircuitPumpValue2' => $this->DataKey('HeatingCircuitPumpValue2', 'heatingCircuitPumpValue2'),
            'supplyTemperatureHeating2'  => $this->DataKey('SupplyTemperatureHeating2', 'supplyTemperatureHeating2'),
            'refluxTemperatureHeating2'  => $this->DataKey('RefluxTemperatureHeating2', 'refluxTemperatureHeating2'),

            'heatingCircuitType3'        => $this->ReadPropertyString('HeatingCircuitType3'),
            'heatingCircuitName3'             => $this->ReadPropertyString('HeatingCircuitName3'),
            'heatingCircuitPumpRunning3' => $this->DataKey('HeatingCircuitPumpRunning3', 'heatingCircuitPumpRunning3'),
            'heatingCircuitPumpValue3' => $this->DataKey('HeatingCircuitPumpValue3', 'heatingCircuitPumpValue3'),
            'supplyTemperatureHeating3'  => $this->DataKey('SupplyTemperatureHeating3', 'supplyTemperatureHeating3'),
            'refluxTemperatureHeating3'  => $this->DataKey('RefluxTemperatureHeating3', 'refluxTemperatureHeating3'),

            'evaporatorPressure'         => $this->DataKey('EvaporatorPressure', 'evaporatorPressure'),
            'evaporatorTemperature'      => $this->DataKey('EvaporatorTemperature', 'evaporatorTemperature'),
            'condenserPressure'          => $this->DataKey('CondenserPressure', 'condenserPressure'),
            'condenserTemperature'       => $this->DataKey('CondenserTemperature', 'condenserTemperature'),
            'expansionValveOpening'      => $this->DataKey('ExpansionValveOpening', 'expansionValveOpening'),
            'eevHeatingOpening'          => $this->DataKey('EEVHeatingOpening', 'eEVHeatingOpening'),
            'eevCoolingOpening'          => $this->DataKey('EEVCoolingOpening', 'eEVCoolingOpening'),
            'compressorValue'            => $this->DataKey('CompressorValue', 'compressorValue'),
            'compressorFrequencyActual'  => $this->DataKey('CompressorValue', 'compressorValue'),
            'compressorFrequencyTarget'  => $this->DataKey('CompressorFrequencyTarget', 'compressorFrequencyTarget'),
            'heatPumpRuntime'            => $this->DataKey('HeatPumpRuntime', 'heatPumpRuntime'),
            'compressorStandstill'       => $this->DataKey('CompressorStandstill', 'compressorStandstill'),
            'heatingPumpPower'           => $this->DataKey('HeatingPumpPower', 'heatingPumpPower'),

            'wwHeatingValve'             => $this->DataKey('WWHeatingValve', 'wwHeatingValve'),
            'heaterRodWW'                => $this->DataKey('HeaterRodWW', 'heaterRodWW'),
            'heaterRodLevel1'            => $this->DataKey('HeaterRodLevel1', 'heaterRodLevel1'),
            'heaterRodLevel2'            => $this->DataKey('HeaterRodLevel2', 'heaterRodLevel2'),

            'heaterRodCount'             => $this->ReadPropertyInteger('HeaterRodCount'),
            'heaterRod1'                 => $this->DataKey('HeaterRod1', 'heaterRod1'),
            'heaterRod1Threshold'        => $this->ReadPropertyInteger('HeaterRod1Threshold'),
            'heaterRod2'                 => $this->DataKey('HeaterRod2', 'heaterRod2'),
            'heaterRod2Threshold'        => $this->ReadPropertyInteger('HeaterRod2Threshold'),
            'heaterRod3'                 => $this->DataKey('HeaterRod3', 'heaterRod3'),
            'heaterRod3Threshold'        => $this->ReadPropertyInteger('HeaterRod3Threshold'),

            'thermalSolarAvailable'      => $this->ReadPropertyBoolean('ThermalSolarAvailable'),
            'thermalSolarPump'           => $this->DataKey('ThermalSolarPump', 'thermalSolarPump'),
            'thermalSolarPumpSpeed'      => $this->DataKey('ThermalSolarPumpSpeed', 'thermalSolarPumpSpeed'),
            'thermalSolarPanelTemp'      => $this->DataKey('ThermalSolarPanelTemp', 'thermalSolarPanelTemp'),
            'thermalSolarFluxTemp'       => $this->DataKey('ThermalSolarFluxTemp', 'thermalSolarFluxTemp'),
            'thermalSolarReturnTemp'     => $this->DataKey('ThermalSolarReturnTemp', 'thermalSolarReturnTemp'),


        ];
        for ($i = 1; $i <= 3; ++$i) {
            $present = $this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $i);
            $type = $this->ReadPropertyString('HeatingCircuitType' . $i);
            $config['heatingCircuitType' . $i] = !$present ? 'off' : ($type === 'radiator' ? 'radiator' : 'underfloor');
            $config['heatingCircuitName' . $i] = $this->ReadPropertyString('HeatingCircuitName' . $i);
            $config['heatingCircuitMixed' . $i] = false;
        }
        $config['mixers'] = [];
        for ($circuit = 1; $circuit <= 3; ++$circuit) {
            if (!$this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $circuit)) continue;
            $i = $this->ReadPropertyInteger('VisualizationHeatingCircuitMixer' . $circuit);
            if ($i < 1 || $i > 3) continue;
            $config['mixers'][] = ['number' => $i, 'circuit' => $circuit,
                'actual' => $this->DataKey('MixerActual' . $i, 'mixerActual' . $i),
                'target' => $this->DataKey('MixerTarget' . $i, 'mixerTarget' . $i),
                'open' => $this->DataKey('MixerOpen' . $i, 'mixerOpen' . $i),
                'close' => $this->DataKey('MixerClose' . $i, 'mixerClose' . $i),
                'pump' => $this->DataKey('MixerPump' . $i, 'mixerPump' . $i)];
        }
        $config['roomTemperatures'] = $this->GetVisualizationRoomSources();
        $config['inputPower'] = $this->DataKey('InputPower', 'inputPower');
        $config['circulation'] = $this->ReadPropertyBoolean('VisualizationCirculation');
        $config['heatingFlow'] = $this->DataKey('HeatingFlow', 'heatingFlow');
        $config['hotGasTemperature'] = $this->DataKey('HotGasTemperature', 'hotGasTemperature');
        $config['timeUntilDefrost'] = $this->DataKey('TimeUntilDefrost', 'timeUntilDefrost');
        $config['solarStorageTemperature'] = $this->DataKey('SolarStorageTemperature', 'solarStorageTemperature');
        $config['solarTarget'] = $this->ReadPropertyString('VisualizationSolarTarget') === 'buffer'
            && $this->ReadPropertyBoolean('TankHP') ? 'buffer' : 'hotwater';
        $config['computedCOP'] = $this->DataKey('ComputedCOP', 'computedCOP');
        $config['computedJAZ'] = $this->DataKey('ComputedJAZ', 'computedJAZ');
        $config['circuitWarning'] = '';
        return $config;
    }

    private function BuildVisualizationData(): array
    {
        $data = [];

        $map = [
            'operatingStatus' => ['OperatingStatusVariable', false],
            'heatingPumpStatusOnOff' => ['HeatingPumpStatusOnOff', true],
            'heatingPumpHotWaterMode' => ['HeatingPumpHotWaterMode', true],
            'heatingPumpHeatingMode' => ['HeatingPumpHeatingMode', true],
            'heatingPumpCoolingMode' => ['HeatingPumpCoolingMode', true],
            'defrostMode' => ['DefrostMode', true],
            'outdoorTemperature' => ['OutdoorTemperature', false],
            'ambientTemperatureNormal' => ['AmbientTemperatureNormal', false],
            'supplyTemperature' => ['SupplyTemperature', false],
            'returnTemperatureSetpoint' => ['ReturnTemperatureSetpointVariable', false],
            'heatPumpReturnTemperature' => ['HeatPumpReturnTemperature', false],
            'hpRunning' => ['HpRunning', true],
            'fanSpeed' => ['FanSpeed', false],
            'compressorRunning' => ['CompressorRunning', true],
            'heatingPumpPower' => ['HeatingPumpPower', false],
            'circulatingPumpRunning' => ['CirculatingPumpRunning', true],
            'storageChargingPumpRunning' => ['StorageChargingPumpRunning', true],
            'tankTempHPUp' => ['TankTempHPUp', false],
            'tankTempHPMiddle' => ['TankTempHPMiddle', false],
            'tankTempHPDown' => ['TankTempHPDown', false],
            'tankTempWWUp' => ['TankTempWWUp', false],
            'tankTempWWMiddle' => ['TankTempWWMiddle', false],
            'tankTempWWDown' => ['TankTempWWDown', false],
            'heatingCircuitPumpRunning' => ['HeatingCircuitPumpRunning1', true],
            'heatingCircuitPumpValue' => ['HeatingCircuitPumpValue1', false],
            'supplyTemperatureHeating' => ['SupplyTemperatureHeating1', false],
            'refluxTemperatureHeating' => ['RefluxTemperatureHeating1', false],
            'heatingCircuitPumpRunning2' => ['HeatingCircuitPumpRunning2', true],
            'heatingCircuitPumpValue2' => ['HeatingCircuitPumpValue2', false],
            'supplyTemperatureHeating2' => ['SupplyTemperatureHeating2', false],
            'refluxTemperatureHeating2' => ['RefluxTemperatureHeating2', false],
            'heatingCircuitPumpRunning3' => ['HeatingCircuitPumpRunning3', true],
            'heatingCircuitPumpValue3' => ['HeatingCircuitPumpValue3', false],
            'supplyTemperatureHeating3' => ['SupplyTemperatureHeating3', false],
            'refluxTemperatureHeating3' => ['RefluxTemperatureHeating3', false],
            'evaporatorPressure' => ['EvaporatorPressure', false],
            'evaporatorTemperature' => ['EvaporatorTemperature', false],
            'condenserPressure' => ['CondenserPressure', false],
            'condenserTemperature' => ['CondenserTemperature', false],
            'expansionValveOpening' => ['ExpansionValveOpening', false],
            'compressorValue' => ['CompressorValue', false],
            'heatingPumpPower' => ['HeatingPumpPower', false],
            'wwHeatingValve' => ['WWHeatingValve', true],
            'heaterRodWW' => ['HeaterRodWW', true],
            'heaterRodLevel1' => ['HeaterRodLevel1', true],
            'heaterRodLevel2' => ['HeaterRodLevel2', true],
            'heaterRod1' => ['HeaterRod1', false],
            'heaterRod2' => ['HeaterRod2', false],
            'heaterRod3' => ['HeaterRod3', false],
            'thermalSolarPump' => ['ThermalSolarPump', true],
            'thermalSolarPumpSpeed' => ['ThermalSolarPumpSpeed', false],
            'thermalSolarPanelTemp' => ['ThermalSolarPanelTemp', false],
            'thermalSolarFluxTemp' => ['ThermalSolarFluxTemp', false],
            'thermalSolarReturnTemp' => ['ThermalSolarReturnTemp', false],


        ];

        foreach ($map as $key => [$property, $binary]) {
            $this->AddVariableData($data, $key, $property, $binary);
        }

        if (!isset($data['ambientTemperatureNormal'])) {
            $this->AddVariableData(
                $data,
                'ambientTemperatureNormal',
                'AmbientTemperatureActual',
                false
            );
        }

        if ($this->HasOperatingStatus()) {
            $statusValue = $this->GetVisualizationPointData('OperatingStatusVariable')['value'];

            $data['heatingPumpHeatingMode'] = $this->BinaryData(
                $this->ValueMatchesCsv($statusValue, $this->ReadPropertyString('OperatingStatusHeatingValues'))
            );
            $data['heatingPumpHotWaterMode'] = $this->BinaryData(
                $this->ValueMatchesCsv($statusValue, $this->ReadPropertyString('OperatingStatusHotWaterValues'))
            );
            $data['heatingPumpCoolingMode'] = $this->BinaryData(
                $this->ValueMatchesCsv($statusValue, $this->ReadPropertyString('OperatingStatusCoolingValues'))
            );
            $data['defrostMode'] = $this->BinaryData(
                $this->ValueMatchesCsv($statusValue, $this->ReadPropertyString('OperatingStatusDefrostValues'))
            );
        }

        $fan = $this->GetVisualizationPointData('FanSpeed');
        if ($fan !== null) {
            $fanValue = $fan['value'];
            $data['hpRunning'] = $this->BinaryData(is_numeric($fanValue)
                ? (float)$fanValue > 0.0 : $this->NormalizeBinaryValue($fanValue));
        }

        foreach (self::VISUALIZATION_EXTRA_IDENT_MAP as $property => $idents) {
            $key = lcfirst($property);
            $this->AddVariableData($data, $key, $property, str_starts_with($property, 'MixerOpen') || str_starts_with($property, 'MixerClose') || str_starts_with($property, 'MixerPump') || $property === 'ThermalSolarPump');

        }
        $input = $this->GetVisualizationPointData('InputPower');
        if ($input !== null) {
            $power = $this->VisualizationPowerReading($input,
                $input['variableId'] > 0 && $input['variableId'] === $this->ReadPropertyInteger('kwin') ? 'kW' : 'W');
            if ($power !== null) {
                $copSource = $input['variableId'] > 0 && $input['variableId'] === $this->ReadPropertyInteger('kwin');
                $power['source'] = $copSource ? 'cop' : 'luxtronik';
                $power['sourceLabel'] = $copSource ? 'COP-Leistungsquelle: ' . IPS_GetName($input['variableId'])
                    : 'Luxtronik: Leistungsaufnahme (ID 268; übertragener Wert, keine bestätigte Strommessung)';
                $data['inputPower'] = $power;
            }
            else unset($data['inputPower']);
        }
        $total = 0.0;
        $sources = [];
        $complete = true;
        $selected = false;
        for ($i = 1; $i <= 3; ++$i) {
            $id = $this->ReadPropertyInteger('HeaterRod' . $i);
            if ($id <= 0 || isset($sources[$id])) continue;
            $selected = true;
            $sources[$id] = true;
            $power = $this->VisualizationHeaterPower($i);
            if ($power === null) $complete = false;
            else $total += $power['watts'];
        }
        if ($selected) $data['heaterRodTotalPower'] = ['value' => $complete ? $total : null, 'unit' => 'W', 'binary' => false];
        $weatherID = $this->ReadPropertyInteger('VisualizationWeatherCode');
        if ($weatherID > 0 && IPS_VariableExists($weatherID)) {
            $data['weatherIcon'] = ['variableId'=>$weatherID, 'value'=>GetValue($weatherID), 'formatted'=>GetValueFormatted($weatherID)];
        }
        foreach ($this->GetVisualizationRoomSources() as $entry) {
            $id = $entry['variableId'];
            $data[$entry['key']] = ['variableId' => $id, 'value' => GetValue($id), 'formatted' => GetValueFormatted($id), 'unit' => $this->GetVariableUnit($id), 'binary' => false];
        }
        return $data;
    }

    private function AddVariableData(
        array &$data,
        string $key,
        string $property,
        bool $binary
    ): void {
        $reading = $this->GetVisualizationPointData($property);
        if ($reading === null) return;
        $reading['binary'] = $binary;
        if ($binary) {
            $reading['value'] = $this->NormalizeBinaryValue($reading['value']);
            $reading['formatted'] = '';
            $reading['unit'] = '';
        }
        $data[$key] = $reading;
    }

    private function BinaryData(bool $value): array
    {
        return [
            'value'  => $value,
            'unit'   => '',
            'binary' => true
        ];
    }

    private function DataKey(string $property, string $key): string
    {
        return $this->GetVisualizationPointData($property) !== null ? $key : '';
    }

    private function DataKeyWithFallback(
        string $property,
        string $fallbackProperty,
        string $key
    ): string {
        return $this->DataKey($property, $key) !== ''
            || $this->DataKey($fallbackProperty, $key) !== ''
                ? $key
                : '';
    }

    private function HasOperatingStatus(): bool
    {
        return $this->GetVisualizationPointData('OperatingStatusVariable') !== null;
    }

    private function ValueMatchesCsv(mixed $value, string $csv): bool
    {
        $items = array_filter(
            array_map('trim', explode(',', $csv)),
            static fn(string $item): bool => $item !== ''
        );

        foreach ($items as $item) {
            if (is_numeric($value) && is_numeric($item)) {
                if ((float) $value === (float) $item) {
                    return true;
                }
            } elseif ((string) $value === $item) {
                return true;
            }
        }

        return false;
    }

    private function BuildControlData(): array
    {
        $hasOperatingStatus = $this->HasOperatingStatus();
        $operatingStatusValue = null;

        if ($hasOperatingStatus) {
            $operatingStatusValue = $this->GetVisualizationPointData('OperatingStatusVariable')['value'];
        }

        return [
            'hasOperatingStatus'           => $hasOperatingStatus,
            'heaterSwitches'               => $this->BuildVisualizationHeaters(),
            'operatingStatusValue'         => $operatingStatusValue,
            'operatingStatusCoolingValues' => $this->ReadPropertyString(
                'OperatingStatusCoolingValues'
            ),
            'heatingActive'                => $this->GetOperatingModeActive(
                'HeatingPumpHeatingMode',
                'OperatingStatusHeatingValues'
            ),
            'hotwaterActive'               => $this->GetOperatingModeActive(
                'HeatingPumpHotWaterMode',
                'OperatingStatusHotWaterValues'
            ),
            'coolingActive'                => $this->GetOperatingModeActive(
                'HeatingPumpCoolingMode',
                'OperatingStatusCoolingValues'
            ),
            'heating'                      => $this->BuildControlInfo('HeatingControlVariable'),
            'hotwater'                     => $this->BuildControlInfo('HotWaterControlVariable'),
            'cooling'                      => $this->BuildControlInfo('CoolingControlVariable'),
            'pool'                         => $this->BuildControlInfo('PoolControlVariable'),
            'power'                        => $this->BuildControlInfo('HeatingPumpStatusOnOff'),
            'warmWaterSetpoint'            => $this->GetNumericControlInfo('warmWaterSetpoint'),
            'heatingTemperatureCorrection' => $this->GetNumericControlInfo('heatingTemperatureCorrection'),
            'roomTemperatureSetpoint'      => $this->GetNumericControlInfo('roomTemperatureSetpoint')
        ];
    }

    private function GetOperatingModeActive(
        string $directProperty,
        string $statusValuesProperty
    ): bool {
        if ($this->HasOperatingStatus()) {
            $status = $this->GetVisualizationPointData('OperatingStatusVariable')['value'];

            return $this->ValueMatchesCsv(
                $status,
                $this->ReadPropertyString($statusValuesProperty)
            );
        }

        $reading = $this->GetVisualizationPointData($directProperty);
        return $reading !== null && $this->NormalizeBinaryValue($reading['value']);
    }

    private function BuildControlInfo(string $property): array
    {
        $variableId = $this->GetVisualizationVariableID($property);

        if ($variableId <= 0 || !IPS_VariableExists($variableId) || !$this->VisualizationControlAvailable($property, $variableId)) {
            return [
                'configured'   => false,
                'variableId'   => 0,
                'currentValue' => null,
                'enabled'      => false,
                'options'      => []
            ];
        }

        $currentValue = GetValue($variableId);
        $profileName = $this->GetVariableProfileName($variableId);
        $options = [];
        $offValues = [];

        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);

            foreach (($profile['Associations'] ?? []) as $association) {
                $associationValue = $association['Value'];
                $associationName = (string) $association['Name'];

                $options[] = [
                    'value' => $associationValue,
                    'name'  => $associationName
                ];

                if (preg_match('/(^|\s)(aus|off|disabled|deaktiviert)(\s|$)/iu', trim($associationName)) === 1) {
                    $offValues[] = $associationValue;
                }
            }
        }

        return [
            'configured'   => true,
            'variableId'   => $variableId,
            'currentValue' => $currentValue,
            'enabled'      => $offValues === [] ? true : !$this->ValueInList($currentValue, $offValues),
            'options'      => $options
        ];
    }

    private function GetNumericControlInfo(string $function): array
    {
        return match ($function) {
            'warmWaterSetpoint' => $this->BuildNumericControlInfo('WarmWaterSetpointVariable', 30.0, 65.0, 0.5),
            'heatingTemperatureCorrection' => $this->BuildNumericControlInfo('HeatingTemperatureCorrectionVariable', -5.0, 5.0, 0.5),
            'roomTemperatureSetpoint' => $this->BuildNumericControlInfo('RoomTemperatureSetpointVariable',
                $this->ReadPropertyString('CommunicationMode') === 'modbus' ? 15.0 : 0.0,
                $this->ReadPropertyString('CommunicationMode') === 'modbus' ? 30.0 : 35.0, 0.5),
            default => throw new InvalidArgumentException('Unbekannte numerische Steuerfunktion.')
        };
    }

    private function BuildNumericControlInfo(
        string $property,
        float $fallbackMin,
        float $fallbackMax,
        float $fallbackStep
    ): array {
        $variableId = $this->GetVisualizationVariableID($property);

        if ($variableId <= 0 || !IPS_VariableExists($variableId) || !$this->VisualizationControlAvailable($property, $variableId)) {
            return [
                'configured'   => false,
                'variableId'   => 0,
                'currentValue' => null,
                'min'          => $fallbackMin,
                'max'          => $fallbackMax,
                'step'         => $fallbackStep,
                'unit'         => ''
            ];
        }

        $min = $fallbackMin;
        $max = $fallbackMax;
        $step = $fallbackStep;
        $unit = $this->GetVariableUnit($variableId);

        $profileName = $this->GetVariableProfileName($variableId);
        // Older instances assigned the DHW range (30–65 °C) to the room setpoint.
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)
            && !($property === 'RoomTemperatureSetpointVariable' && $profileName === 'WPLUX.Wset')) {
            $profile = IPS_GetVariableProfile($profileName);

            // A 0/0 profile has no range; keep the protocol's limits in that case.
            if (isset($profile['MinValue'], $profile['MaxValue'])
                && is_numeric($profile['MinValue']) && is_numeric($profile['MaxValue'])
                && (float)$profile['MaxValue'] > (float)$profile['MinValue']) {
                $min = (float)$profile['MinValue'];
                $max = (float)$profile['MaxValue'];
            }
            if (isset($profile['StepSize']) && is_numeric($profile['StepSize'])
                && (float) $profile['StepSize'] > 0.0) {
                $step = (float) $profile['StepSize'];
            }
        }

        return [
            'configured'   => true,
            'variableId'   => $variableId,
            'currentValue'     => GetValue($variableId),
            'formattedValue'   => GetValueFormatted($variableId),
            'min'              => max($fallbackMin, $min),
            'max'          => min($fallbackMax, $max),
            'step'         => $step,
            'unit'         => $unit
        ];
    }

    private function GetProfileAssociationValues(int $variableId): array
    {
        $profileName = $this->GetVariableProfileName($variableId);
        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return [];
        }

        $profile = IPS_GetVariableProfile($profileName);

        return array_map(
            static fn(array $association): mixed => $association['Value'],
            $profile['Associations'] ?? []
        );
    }

    private function GetVariableProfileName(int $variableId): string
    {
        $presentation = $this->VisualizationPresentation($variableId);
        if (!empty($presentation['PROFILE'])) return (string)$presentation['PROFILE'];
        $variable = IPS_GetVariable($variableId);

        return $variable['VariableCustomProfile'] !== ''
            ? (string) $variable['VariableCustomProfile']
            : (string) $variable['VariableProfile'];
    }

    private function ValueInList(mixed $value, array $values): bool
    {
        foreach ($values as $candidate) {
            if (is_numeric($value) && is_numeric($candidate)) {
                if ((float) $value === (float) $candidate) {
                    return true;
                }
            } elseif ((string) $value === (string) $candidate) {
                return true;
            }
        }

        return false;
    }

    private function CastValueForVariableType(mixed $value, int $variableType): mixed
    {
        return match ($variableType) {
            0       => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int) $value !== 0),
            1       => (int) $value,
            2       => (float) $value,
            3       => (string) $value,
            default => $value
        };
    }

    private function NormalizeBinaryValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array(
            $normalized,
            ['1', 'true', 'on', 'yes', 'ja', 'ein', 'active', 'aktiv'],
            true
        );
    }

    private function GetVariableUnit(int $variableId): string
    {
        $presentation = $this->VisualizationPresentation($variableId);
        if (isset($presentation['SUFFIX'])) return trim((string)$presentation['SUFFIX']);
        $profileName = $this->GetVariableProfileName($variableId);

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return '';
        }

        $profile = IPS_GetVariableProfile($profileName);

        return trim((string) ($profile['Suffix'] ?? ''));
    }

    private function VariableSelect(string $caption, string $name): array
    {
        return [
            'type'     => 'SelectVariable',
            'name'     => $name,
            'caption'  => $caption
        ];
    }

    private function VariableRow(string $caption1, string $name1, string $caption2, string $name2): array
    {
        $entries = [];

        foreach ([[$caption1, $name1], [$caption2, $name2]] as [$caption, $name]) {
            if ($name === '') {
                continue;
            }

            $entries[] = [
                'caption' => $caption,
                'name'    => $name
            ];
        }

        return $this->VariableGrid($entries);
    }

    private function VariableGrid(array $entries): array
    {
        $rows = [];
        foreach (array_chunk($entries, 2) as $chunk) {
            $rows[] = [
                'type' => 'RowLayout',
                'items' => array_map(
                    fn(array $entry): array => $this->VariableSelect($entry['caption'], $entry['name']),
                    $chunk
                )
            ];
        }

        return ['type' => 'ColumnLayout', 'items' => $rows];
    }

    private function GetEmbeddedJavaScript(): string
    {
        return <<<'SYMC0N_HEATPUMP_JAVASCRIPT'
class HeatPumpCard extends HTMLElement {
  constructor() {
    super();
    this.config = {};
    this.data = {};
    this.content = null;
    this.localization = {};
  }

  mount(svg, localization = {}) {
    this.innerHTML =
      '<ha-card>\n'
      + String(svg || '')
          .replace(/<!\[CDATA\[/g, '')
          .replace(/\]\]>/g, '')
          .replace(/ class="rotate"/g, '')
          .replace(/display: inline;/g, 'display: none;')
      + '</ha-card>';

    this.content = this.querySelector('svg');
    this.localization = localization || {};

    if (!this.content) {
      throw new Error('SVG konnte nicht in die Card eingefügt werden.');
    }

    this.content.setAttribute('width', '100%');
    this.content.setAttribute('height', '100%');
    this.content.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    this.content.style.setProperty('background', 'transparent', 'important');
    this.content.style.setProperty('background-color', 'transparent', 'important');
    this.content.style.setProperty('--card-background-color', 'transparent');

    /*
     * Die Theme-Farbe wird später aus Symcons --content-color übernommen.
     * Hier keine eigene body-Farbe mehr festschreiben.
     */

    const texts = this.localization.svgTexts || {};
    this.setText('#textTankWWName', texts.tankWWName);
    this.setText('#textTankHPName', texts.tankHPName);
    this.setText('#textEvaporator', texts.evaporator);
    this.setText('#textCondenser', texts.condenser);
    this.setText('#textCompressor', texts.compressor);
    this.setText('#textExpansionValve', texts.expansionValve);
    this.setText('#textCirculatingPump', texts.circulatingPump);
    this.setText('#textSupplyTemperatureLabel', texts.supplyTemperatureLabel);

    this.setConfig(this.config || {});
  }

  item(key) {
    return key && this.data && this.data[key] ? this.data[key] : null;
  }

  value(key) {
    const item = this.item(key);
    return item ? item.value : null;
  }

  number(key) {
    const value = this.value(key);
    if (value === null || value === undefined || value === '') {
      return null;
    }
    const normalized = String(value).replace(',', '.').replace(/[^0-9+\-.]/g, '');
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
  }

  binary(key) {
    const item = this.item(key);
    if (!item) return false;
    if (item.binary) return !!item.value;

    const value = String(item.value ?? '').trim().toLowerCase();
    return ['1','true','on','yes','ja','ein','active','aktiv'].includes(value);
  }

  format(key) {
    const item = this.item(key);
    if (!item || item.value === null || item.value === undefined || item.value === '') {
      return '';
    }
    if (item.formatted !== undefined && item.formatted !== null && String(item.formatted) !== '') {
      return String(item.formatted);
    }

    const numeric = this.number(key);
    if (numeric !== null) {
      const value = new Intl.NumberFormat('de-CH', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1
      }).format(numeric);
      return item.unit ? value + ' ' + item.unit : value;
    }

    return String(item.value);
  }

  setText(selector, value) {
    const element = this.content ? this.content.querySelector(selector) : null;
    if (element) element.textContent = value || '';
  }

  rotate(selector, active) {
    const element = this.content ? this.content.querySelector(selector) : null;
    if (!element) return;
    element.classList.toggle('rotate', !!active);
  }

  setData(data) {
    this.data = data || {};
    if (!this.content) return;

    if (this.content.getAttribute('data-symcon-modern')) {
      return;
    }

    const c = this.config || {};

    this.changeHeatPumpRunning(c.heatingPumpType, c.hpRunning);
    this.setText('#textG2WWaterTempIn', this.format(c.temperatureGroundWaterIn));
    this.setText('#textG2WWaterTempOut', this.format(c.temperatureGroundWaterOut));

    const party = this.binary(c.heatingPumpPartyMode);
    const night = this.binary(c.heatingPumpNightMode);
    const hasExplicitDayState = !!c.heatingPumpDayMode;

    const show = (selector, visible) => {
      const element = this.content.querySelector(selector);
      if (element) element.style.display = visible ? 'inline' : 'none';
    };

    show('#gHPStatusOff', false);
    show('#gHPStatusWW', this.binary(c.heatingPumpHotWaterMode));
    show('#gHPStatusHeating', this.binary(c.heatingPumpHeatingMode));
    show('#gHPStatusCooling', this.binary(c.heatingPumpCoolingMode));
    show('#gHPStatusParty', false);
    show('#gHPStatusSave', false);
    show('#gTimeSymbolNight', false);
    show('#gTimeSymbolDay', false);
    show('#gWarning', false);
    show('#gError', false);
    show('#gDefrost', false);
    show('#gAdditionalHeating', false);

    this.setText('#textOutdoorTemperatureValue', this.format(c.outdoorTemperature));

    if (party && this.format(c.ambientTemperatureParty)) {
      this.setText('#textIndoorTemperatureValue', this.format(c.ambientTemperatureParty));
    } else if (night && this.format(c.ambientTemperatureReduced)) {
      this.setText('#textIndoorTemperatureValue', this.format(c.ambientTemperatureReduced));
    } else {
      this.setText('#textIndoorTemperatureValue', this.format(c.ambientTemperatureNormal));
    }

    this.setText('#textSupplyTemperatureValue', this.format(c.supplyTemperature));

    this.rotate('#pathCompressor', this.binary(c.compressorRunning));
    this.rotate('#gHeatingCircuitPump', this.binary(c.heatingCircuitPumpRunning));
    this.rotate('#gHeatingCircuitPump2', this.binary(c.heatingCircuitPumpRunning2));
    this.rotate('#gHeatingCircuitPump3', this.binary(c.heatingCircuitPumpRunning3));
    this.rotate('#gCirculatingPumpBladeWheel', this.binary(c.circulatingPumpRunning));
    this.rotate('#gStorageChargingPump', this.binary(c.storageChargingPumpRunning));

    if (c.tankHP) {
      const up = this.number(c.tankTempHPUp);
      const middle = this.number(c.tankTempHPMiddle);
      const down = this.number(c.tankTempHPDown);
      this.setText('#textTankTempHPUp', this.format(c.tankTempHPUp));
      this.setText('#textTankTempHPMiddle', this.format(c.tankTempHPMiddle));
      this.setText('#textTankTempHPDown', this.format(c.tankTempHPDown));
      this.tankColors(up, middle, down, '#stop3020', '#stop3040', '#stop3030');
    }

    if (c.tankWW) {
      const up = this.number(c.tankTempWWUp);
      const middle = this.number(c.tankTempWWMiddle);
      const down = this.number(c.tankTempWWDown);
      this.setText('#textTankTempWWUp', this.format(c.tankTempWWUp));
      this.setText('#textTankTempWWMiddle', this.format(c.tankTempWWMiddle));
      this.setText('#textTankTempWWDown', this.format(c.tankTempWWDown));
      this.tankColors(up, middle, down, '#stop3050', '#stop3070', '#stop3060');

      const valve = this.content.querySelector('#gWWHeatingValve');
      if (valve) {
        valve.setAttribute(
          'transform',
          'rotate(' + (this.binary(c.wwHeatingValve) ? '90' : '0') + ', 620, 450)'
        );
      }
    }

    this.setText('#textHeatingCircuitName1', c.heatingCircuitName1 || 'HK1');
    this.setText('#textHeatingCircuitName2', c.heatingCircuitName2 || 'HK2');
    this.setText('#textHeatingCircuitName3', c.heatingCircuitName3 || 'HK3');

    this.heatingCircuit(
      c.heatingCircuitType1,
      c.supplyTemperatureHeating,
      c.refluxTemperatureHeating,
      '#stopCircuit1', '#stopCircuit2',
      '#textSupplyTemperatureHeating', '#textRefluxTemperatureHeating'
    );
    this.heatingCircuit(
      c.heatingCircuitType2,
      c.supplyTemperatureHeating2,
      c.refluxTemperatureHeating2,
      '#stopCircuit3', '#stopCircuit4',
      '#textSupplyTemperatureHeating2', '#textRefluxTemperatureHeating2'
    );
    this.heatingCircuit(
      c.heatingCircuitType3,
      c.supplyTemperatureHeating3,
      c.refluxTemperatureHeating3,
      '#stopCircuit5', '#stopCircuit6',
      '#textSupplyTemperatureHeating3', '#textRefluxTemperatureHeating3'
    );

    this.setText('#textEvaporatorPressure', this.format(c.evaporatorPressure));
    this.setText('#textEvaporatorTemperature', this.format(c.evaporatorTemperature));
    this.setText('#textCondenserPressure', this.format(c.condenserPressure));
    this.setText('#textCondenserTemperature', this.format(c.condenserTemperature));
    this.setText('#textExpansionValveOpening', this.format(c.expansionValveOpening));
    this.setText('#textCompressorValue', this.format(c.compressorValue));

    if (c.thermalSolarAvailable) {
      this.rotate('#gThermalSolarPump', this.binary(c.thermalSolarPump));
      this.setText('#textThermalSolarPanelTemp', this.format(c.thermalSolarPanelTemp));
      this.setText('#textThermalSolarFluxTemp', this.format(c.thermalSolarFluxTemp));
      this.setText('#textThermalSolarPumpSpeed', this.format(c.thermalSolarPumpSpeed));
    }
  }

  changeHeatPumpRunning(selection, runningKey) {
    const running = this.binary(runningKey);
    this.rotate('#pathHPFan', (!selection || selection === 'A2W') && running);
    this.rotate('#gHPW2WPumpBladeWheel', selection === 'W2W' && running);
    this.rotate('#gHPG2WPumpBladeWheel', selection === 'G2W' && running);
  }

  heatingCircuit(type, supplyKey, refluxKey, supplyStop, refluxStop, supplyText, refluxText) {
    if (!type || type === 'off') return;

    const supply = this.number(supplyKey);
    const reflux = this.number(refluxKey);
    const effectiveSupply = supply === null ? 30 : supply;
    const effectiveReflux = reflux === null ? Math.max(0, effectiveSupply - 10) : reflux;

    const s1 = this.content.querySelector(supplyStop);
    const s2 = this.content.querySelector(refluxStop);
    if (s1) s1.setAttribute('style', 'stop-color:' + this.tempColor(effectiveSupply));
    if (s2) s2.setAttribute('style', 'stop-color:' + this.tempColor(effectiveReflux));

    this.setText(supplyText, this.format(supplyKey));
    this.setText(refluxText, this.format(refluxKey));
  }

  tempColor(temp) {
    if (!temp) return '#ffffff00';
    if (temp > 60) return '#ff0000';
    return '#'
      + ('0' + Math.round(255 * temp / 60).toString(16)).substr(-2)
      + '00'
      + ('0' + Math.round(255 * Math.abs(60 - temp) / 60).toString(16)).substr(-2);
  }

  tankColors(up, middle, down, idUp, idMiddle, idDown) {
    let tempUp = Number.isFinite(up) ? up : null;
    let tempMiddle = Number.isFinite(middle) ? middle : null;
    let tempDown = Number.isFinite(down) ? down : null;

    if (tempUp !== null) {
      if (tempMiddle === null) {
        tempMiddle = tempDown !== null ? (tempUp + tempDown) / 2 : tempUp - 5;
      }
    } else if (tempMiddle !== null) {
      tempUp = tempMiddle + 5;
    } else if (tempDown !== null) {
      tempMiddle = tempDown + 5;
      tempUp = tempDown + 10;
    }

    if (tempMiddle !== null && tempDown === null) {
      tempDown = tempMiddle - 5;
    }

    const set = (id, value) => {
      const stop = this.content.querySelector(id);
      if (stop) stop.setAttribute('style', 'stop-color:' + this.tempColor(value));
    };

    set(idUp, tempUp);
    set(idMiddle, tempMiddle);
    set(idDown, tempDown);
  }

  setConfig(config) {
    this.config = config;
    if (this.content) {
      if (this.content.getAttribute('data-symcon-modern')) {
        return;
      }
      this.querySelector("ha-card").setAttribute("header", config.title);
      this.content.querySelector('#gHPFan').style.display = (!config.heatingPumpType || config.heatingPumpType === 'A2W' ? 'inline' : 'none');
      this.content.querySelector('#gHPW2W').style.display = (config.heatingPumpType === 'W2W' ? 'inline' : 'none');
      this.content.querySelector('#gHPG2W').style.display = (config.heatingPumpType === 'G2W' ? 'inline' : 'none');
      this.content.querySelector("#gCirculatingPump").style.display = config.circulatingPumpRunning ? 'inline' : 'none';
      this.content.querySelector('#gCirculatingPumpBladeWheel').classList.remove("rotate");
      this.content.querySelector("#gStorageChargingPump").style.display = config.storageChargingPumpRunning ? 'inline' : 'none';
      this.content.querySelector('#gStorageChargingPump').classList.remove("rotate");
      this.content.querySelector("#gTankHP").style.display = config.tankHP ? 'inline' : 'none';
      this.content.querySelector("#gWW").style.display = config.tankWW ? 'inline' : 'none';

      var type1 = config.heatingCircuitType1;
      if (!type1 || type1 === 'off') {
        this.content.querySelector('#gHeaterCircuit1').style.display = 'none';
      } else {
        this.content.querySelector('#gHeaterCircuit1').style.display = 'inline';
        this.content.querySelector('#gHeaterCircuitFloor1').style.display = (type1 === 'underfloor' ? 'inline' : 'none');
        this.content.querySelector('#radiator1').style.display = (type1 === 'radiator' ? 'inline' : 'none');
      }
      this.content.querySelector('#gHeatingCircuitPump').classList.remove("rotate");
      this.content.querySelector("#gHeatingCircuitPump").style.display = config.heatingCircuitPumpRunning ? 'inline' : 'none';

      var type2 = config.heatingCircuitType2;
      if (!type2 || type2 === 'off') {
        this.content.querySelector('#gHeaterCircuit2').style.display = 'none';
      } else {
        this.content.querySelector('#gHeaterCircuit2').style.display = 'inline';
        this.content.querySelector('#gHeaterCircuitFloor2').style.display = (type2 === 'underfloor' ? 'inline' : 'none');
        this.content.querySelector('#radiator2').style.display = (type2 === 'radiator' ? 'inline' : 'none');
      }
      this.content.querySelector('#gHeatingCircuitPump2').classList.remove("rotate");
      this.content.querySelector("#gHeatingCircuitPump2").style.display = config.heatingCircuitPumpRunning2 ? 'inline' : 'none';
      this.content.querySelector("#pathPipeToHP2").style.display = (!type1 || type1 === 'off') && (!type2 || type2 === 'off') ? 'none' : 'inline';

      var type3 = config.heatingCircuitType3;
      if (!type3 || type3 === 'off') {
        this.content.querySelector('#gHeaterCircuit3').style.display = 'none';
      } else {
        this.content.querySelector('#gHeaterCircuit3').style.display = 'inline';
        this.content.querySelector('#gHeaterCircuitFloor3').style.display = (type3 === 'underfloor' ? 'inline' : 'none');
        this.content.querySelector('#radiator3').style.display = (type3 === 'radiator' ? 'inline' : 'none');
      }
      this.content.querySelector('#gHeatingCircuitPump3').classList.remove("rotate");
      this.content.querySelector("#gHeatingCircuitPump3").style.display = config.heatingCircuitPumpRunning3 ? 'inline' : 'none';

      var noHeating = (!type1 || type1 === 'off') && (!type2 || type2 === 'off') && (!type3 || type3 === 'off') && !config.tankHP;
      var noHotWater = !config.tankWW;

      if (noHeating && noHotWater) {
        this.content.querySelector("#gPipe").style.display = 'none';
        this.content.querySelector("#gPipeBuffer").style.display = 'none';
        this.content.querySelector("#gPipeLayeredChargeStorage").style.display = 'none';
        this.content.querySelector("#gHP").setAttribute("transform", "translate(460 -300)");
        this.content.querySelector("#gSettings").setAttribute("transform", "translate(-25)");
      } else {
        this.content.querySelector("#pathPipeToBuffer").style.display = noHeating ? 'none' : 'inline';
        this.content.querySelector("#pathPipeFromBuffer").style.display = noHeating ? 'none' : 'inline';
        this.content.querySelector("#gPipe").style.display = 'inline';
        this.content.querySelector("#gPipeBuffer").style.display = config.layeredChargeStorage ? 'none' : 'inline';
        this.content.querySelector("#gPipeLayeredChargeStorage").style.display = config.layeredChargeStorage ? 'inline' : 'none';
        this.content.querySelector("#gWWHeatingValve").style.display = config.layeredChargeStorage ? 'none' : 'inline';
        this.content.querySelector("#gHP").removeAttribute("transform");
        this.content.querySelector("#gSettings").removeAttribute("transform");
      }

      if (!config.thermalSolarAvailable || config.thermalSolarAvailable === 'off') {
        this.content.querySelector('#gThermalSolar').style.display = 'none';
      } else {
        this.content.querySelector('#gThermalSolar').style.display = 'inline';
      }
      this.content.querySelector('#gThermalSolarPump').classList.remove("rotate");

      this.content.querySelector("#textLabel000").textContent = config.additionalLabel000 ? config.additionalLabel000 : '';
      this.content.querySelector("#textLabel001").textContent = config.additionalLabel001 ? config.additionalLabel001 : '';
      this.content.querySelector("#textLabel002").textContent = config.additionalLabel002 ? config.additionalLabel002 : '';
      this.content.querySelector("#textLabel003").textContent = config.additionalLabel003 ? config.additionalLabel003 : '';
      this.content.querySelector("#textLabel004").textContent = config.additionalLabel004 ? config.additionalLabel004 : '';
      this.content.querySelector("#textLabel005").textContent = config.additionalLabel005 ? config.additionalLabel005 : '';
      this.content.querySelector("#textLabel006").textContent = config.additionalLabel006 ? config.additionalLabel006 : '';
      this.content.querySelector("#textLabel007").textContent = config.additionalLabel007 ? config.additionalLabel007 : '';
      this.content.querySelector("#textLabel008").textContent = config.additionalLabel008 ? config.additionalLabel008 : '';
      this.content.querySelector("#textLabel009").textContent = config.additionalLabel009 ? config.additionalLabel009 : '';
      this.content.querySelector("#textLabel010").textContent = config.additionalLabel010 ? config.additionalLabel010 : '';
      this.content.querySelector("#textLabel011").textContent = config.additionalLabel011 ? config.additionalLabel011 : '';

      this.setLinks();
    }
  }

  setLinks() {}
}

customElements.define("heat-pump-card", HeatPumpCard);

window.customCards = window.customCards || [];
window.customCards.push({
  type: "heat-pump-card",
  name: "Heat Pump Card",
  description: "A custom card displaying heat pump state"
});

/*
 * IP-Symcon adapter.
 * Sämtliche Darstellungslogik liegt in dieser Datei.
 */
window.SymconHeatPump = {
  init(payload) {
        let currentConfig = payload.config || {};
        let currentControls = payload.controls || {};
        let embeddedSvg = payload.svg || '';
        let embeddedLocalization = payload.localization || {};
        let currentData = payload.data || {};

        /*
         * Ansichts-Speicherung nach demselben Instanz-Prinzip wie im
         * Energiefluss-Modul:
         *
         *   /visu/36446/ -> instance-36446
         *
         * Damit besitzt jede Wärmepumpen-Instanz im selben Browser bzw.
         * in der Symcon-App ihren eigenen gespeicherten Ansichtsstatus.
         */

        const errorBox = document.getElementById('wp-error');

        const showError = (message) => {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = message;
            errorBox.style.display = 'block';
        };

        const clearError = () => {
            if (!errorBox) {
                return;
            }

            errorBox.textContent = '';
            errorBox.style.display = 'none';
        };

        const HeatPumpClass = customElements.get('heat-pump-card');

        if (!HeatPumpClass) {
            showError('heat-pump-card.js wurde injiziert, aber das Custom Element "heat-pump-card" wurde nicht registriert.');
            return;
        }

        /*
         * Symcon-Adapter:
         * Die Originaldatei heat-pump-card.js bleibt unangetastet.
         * Nur die beiden Methoden, die in Home Assistant per XMLHttpRequest
         * SVG und Sprachdatei nachladen, werden zur Laufzeit auf die bereits
         * von PHP eingebetteten Ressourcen umgebogen.
         */

        const originalTempColor = HeatPumpClass.prototype.tempColor;

        /*
         * Einheitliche Temperatur-Farbskala für die gesamte Hydraulik:
         * 20 / 30 / 40 / 50 / 60 / 70 °C.
         * Dazwischen wird stufenlos interpoliert.
         */
        const getTemperatureColorStops = () => {
            const configured = [];

            const defaults = [
                {temperature: 15, color: 26316},
                {temperature: 20, color: 2730472},
                {temperature: 25, color: 3790293},
                {temperature: 30, color: 16765286},
                {temperature: 35, color: 16769126},
                {temperature: 40, color: 16761395},
                {temperature: 45, color: 16750616},
                {temperature: 50, color: 16734744},
                {temperature: 55, color: 15018795},
                {temperature: 60, color: 16711680}
            ];

            const intToRgb = (value, fallback) => {
                const number = Number(value);
                if (!Number.isFinite(number)) {
                    return fallback;
                }

                const color = Math.max(0, Math.min(0xFFFFFF, Math.trunc(number)));

                return [
                    (color >> 16) & 0xFF,
                    (color >> 8) & 0xFF,
                    color & 0xFF
                ];
            };

            const fallbackRgb = [
                [0, 102, 204],
                [41, 169, 232],
                [57, 213, 213],
                [255, 209, 102],
                [255, 224, 102],
                [255, 194, 51],
                [255, 152, 24],
                [255, 90, 24],
                [229, 43, 43],
                [255, 0, 0]
            ];

            const stops = defaults.map((fallback, index) => {
                const item = configured[index] || fallback;
                const temperature = Number(item.temperature);

                return [
                    Number.isFinite(temperature)
                        ? temperature
                        : fallback.temperature,
                    intToRgb(item.color, fallbackRgb[index])
                ];
            });

            /*
             * Die Temperaturwerte dürfen frei konfiguriert werden.
             * Für die Interpolation sortieren wir sie automatisch aufsteigend.
             */
            stops.sort((a, b) => a[0] - b[0]);

            return stops;
        };

        const rgbToHex = (rgb) =>
            '#' + rgb.map((component) =>
                Math.max(0, Math.min(255, Math.round(component)))
                    .toString(16)
                    .padStart(2, '0')
            ).join('');

        const temperatureColor = (temperature) => {
            if (!currentConfig.useCustomTemperatureColors) {
                return null;
            }

            const value = Number(temperature);

            if (!Number.isFinite(value)) {
                return null;
            }

            const stops = getTemperatureColorStops();

            if (value <= stops[0][0]) {
                return rgbToHex(stops[0][1]);
            }

            const last = stops[stops.length - 1];
            if (value >= last[0]) {
                return rgbToHex(last[1]);
            }

            for (let i = 0; i < stops.length - 1; i++) {
                const [t1, c1] = stops[i];
                const [t2, c2] = stops[i + 1];

                if (value >= t1 && value <= t2) {
                    const factor = (value - t1) / (t2 - t1);

                    return rgbToHex(c1.map((component, index) =>
                        component + (c2[index] - component) * factor
                    ));
                }
            }

            return null;
        };

        /*
         * Die Speicher der Original-Card verwenden tempColor().
         * Dadurch gilt automatisch dieselbe Skala für Puffer und Warmwasser.
         */
        HeatPumpClass.prototype.tempColor = function(temp) {
            if (!false) {
                if (typeof originalTempColor === 'function') {
                    return originalTempColor.call(this, temp);
                }

                return '#ffffff00';
            }

            return temperatureColor(temp) || '#ffffff00';
        };

        const resolveLayoutTextColor = () => {
            /*
             * Symcon stellt dem HTML-SDK die aktuelle Designfarbe über
             * --content-color bereit. Diese verwenden wir direkt statt
             * einer eigenen Hell-/Dunkel-Erkennung.
             *
             * Damit folgt die Card automatisch dem tatsächlich gewählten
             * Symcon-Design:
             *   helles Layout  -> dunkle/schwarze Content-Farbe
             *   dunkles Layout -> helle/weiße Content-Farbe
             */
            const candidates = [
                document.documentElement,
                document.body,
                document.getElementById('wp-root')
            ].filter(Boolean);

            for (const element of candidates) {
                const value = getComputedStyle(element)
                    .getPropertyValue('--content-color')
                    .trim();

                if (value) {
                    return value;
                }
            }

            /*
             * Nur als Sicherheitsfallback, falls eine ältere Umgebung
             * --content-color nicht bereitstellt.
             */
            const bodyColor =
                getComputedStyle(document.body).color;

            return bodyColor || '#ffffff';
        };

        const resolveThemeCssValue = (names, fallback = '') => {
            const candidates = [
                document.documentElement,
                document.body,
                document.getElementById('wp-root')
            ].filter(Boolean);

            for (const element of candidates) {
                const style = getComputedStyle(element);
                for (const name of names) {
                    const value = style.getPropertyValue(name).trim();
                    if (value && value !== 'transparent' && value !== 'rgba(0, 0, 0, 0)') {
                        return value;
                    }
                }
            }

            return fallback;
        };

        const isBlack = (value) => {
            const normalized = String(value || '')
                .trim()
                .toLowerCase()
                .replace(/\s+/g, '');

            return [
                'black',
                '#000',
                '#000000',
                'rgb(0,0,0)',
                'rgba(0,0,0,1)'
            ].includes(normalized);
        };

        const isWhite = (value) => {
            const normalized = String(value || '')
                .trim()
                .toLowerCase()
                .replace(/\s+/g, '');

            return [
                'white',
                '#fff',
                '#ffffff',
                'rgb(255,255,255)',
                'rgba(255,255,255,1)'
            ].includes(normalized);
        };

        const isNeutralThemeColor = (value) => {
            return isBlack(value) || isWhite(value);
        };

        const applyThemeColors = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const textColor = resolveLayoutTextColor();

            // Keine eigene Hell-/Dunkel-Palette mehr. Die Kachel übernimmt
            // direkt die tatsächlich von Symcon gelieferten Theme-Farben.
            const tileBackground = resolveThemeCssValue([
                '--card-background-color',
                '--content-background-color',
                '--content-background',
                '--background-color'
            ], 'transparent');

            const panelBackground = resolveThemeCssValue([
                '--card-background-color',
                '--content-background-color',
                '--content-background'
            ], tileBackground);

            const borderColor = resolveThemeCssValue([
                '--divider-color',
                '--border-color',
                '--content-border-color'
            ], 'rgba(127,127,127,.35)');

            svg.style.setProperty('--wp-bg', 'transparent');
            svg.style.setProperty('--wp-panel1', panelBackground);
            svg.style.setProperty('--wp-panel2', panelBackground);
            svg.style.setProperty('--wp-button', panelBackground);
            svg.style.setProperty('--wp-border', borderColor);
            svg.style.setProperty('--wp-border2', borderColor);
            svg.style.setProperty('--wp-text', textColor);
            svg.style.setProperty('--wp-icon', textColor);
            svg.style.setProperty('--wp-equipment-face',
                'color-mix(in srgb, ' + textColor + ' 4%, ' + panelBackground + ')');
            svg.style.setProperty('--wp-equipment-edge',
                'color-mix(in srgb, ' + textColor + ' 12%, ' + panelBackground + ')');

            const bg = svg.querySelector('#symconThemeBackground');
            if (bg) {
                bg.style.setProperty('fill', 'transparent', 'important');
            }

            const panelGradient = svg.querySelector('#p');
            if (panelGradient) {
                panelGradient.querySelectorAll('stop').forEach((stop) => {
                    stop.setAttribute('stop-color', panelBackground);
                });
            }

            // Buttons und Panels bekommen die echte Symcon-Fläche; die
            // Gesamtfläche bleibt transparent und zeigt den Kachelhintergrund.
            svg.querySelectorAll('.bt').forEach((element) => {
                element.style.setProperty('fill', panelBackground, 'important');
                element.style.setProperty('stroke', borderColor, 'important');
            });
            svg.querySelectorAll('.pn').forEach((element) => {
                element.style.setProperty('fill', panelBackground, 'important');
                element.style.setProperty('stroke', borderColor, 'important');
            });
            svg.querySelectorAll('.theme-surface').forEach((element) => {
                element.style.setProperty('fill', panelBackground, 'important');
                element.style.setProperty('stroke', borderColor, 'important');
            });
            svg.querySelectorAll('.theme-dot, .hk-pump-rotor').forEach((element) => {
                element.style.setProperty('fill', textColor, 'important');
            });

            const fanRotor = svg.querySelector('#pathHPFan');
            if (fanRotor) {
                fanRotor.style.setProperty('fill', textColor, 'important');
                fanRotor.style.setProperty('opacity', '.55', 'important');
            }

            /*
             * Symcon-Design direkt in die SVG weiterreichen.
             */
            svg.style.setProperty(
                '--content-color',
                textColor,
                'important'
            );
            svg.style.setProperty(
                '--primary-text-color',
                textColor,
                'important'
            );
            svg.style.setProperty(
                'color',
                textColor,
                'important'
            );

            svg.style.setProperty(
                'background',
                'transparent',
                'important'
            );
            svg.style.setProperty(
                'background-color',
                'transparent',
                'important'
            );
            svg.style.setProperty(
                '--card-background-color',
                panelBackground
            );

            /*
             * Sämtliche normale Beschriftung folgt --content-color.
             */
            svg.querySelectorAll('text, tspan').forEach((element) => {
                element.style.setProperty(
                    'fill',
                    'var(--content-color)',
                    'important'
                );
                element.style.setProperty(
                    'color',
                    'var(--content-color)',
                    'important'
                );
            });

            /*
             * Nur neutrale Originalfarben (weiß/schwarz) umstellen.
             * Temperaturfarben, Speicherfarben, Kältekreisgradienten und
             * Flussanimationen werden nicht verändert.
             */
            svg.querySelectorAll('*').forEach((element) => {
                if (
                    (element.classList && element.classList.contains('wp-live-flow'))
                    || (element.classList && element.classList.contains('pn'))
                    || (element.classList && element.classList.contains('bt'))
                    || (element.classList && element.classList.contains('theme-surface'))
                    || (element.classList && element.classList.contains('theme-dot'))
                    || (element.classList && element.classList.contains('hk-pump-rotor'))
                    || element.id === 'symconThemeBackground'
                    || element.id === 'pathHPFan'
                ) {
                    return;
                }

                const computed = getComputedStyle(element);

                const fill =
                    element.getAttribute('fill')
                    || element.style.fill
                    || computed.fill;

                const stroke =
                    element.getAttribute('stroke')
                    || element.style.stroke
                    || computed.stroke;

                const fillText = String(fill || '');
                const strokeText = String(stroke || '');

                if (
                    !fillText.includes('url(')
                    && isNeutralThemeColor(fillText)
                ) {
                    element.style.setProperty(
                        'fill',
                        'var(--content-color)',
                        'important'
                    );
                }

                if (
                    !strokeText.includes('url(')
                    && isNeutralThemeColor(strokeText)
                ) {
                    element.style.setProperty(
                        'stroke',
                        'var(--content-color)',
                        'important'
                    );
                }
            });
        };

        const rebuildCard = () => {
            const root = document.getElementById('wp-root');

            if (!root) {
                showError('Der Wärmepumpen-Container wurde im HTML nicht gefunden.');
                return;
            }

            closeModeMenu();

            const oldCard = document.getElementById('wp-card');
            const newCard = document.createElement('heat-pump-card');
            newCard.id = 'wp-card';

            if (oldCard) {
                oldCard.replaceWith(newCard);
            } else {
                const menu = document.getElementById('wp-mode-menu');
                if (menu) {
                    root.insertBefore(newCard, menu);
                } else {
                    root.appendChild(newCard);
                }
            }

            requestAnimationFrame(() => {
                queueMicrotask(() => applyCardData());
            });
        };

        const closeModeMenu = () => {
            const menu = document.getElementById('wp-mode-menu');
            if (menu) {
                menu.style.display = 'none';
                menu.innerHTML = '';
            }
        };

        const openModeMenu = (functionName, event) => {
            event.preventDefault();
            event.stopPropagation();

            const control = currentControls && currentControls[functionName];
            if (!control || !control.configured || !Array.isArray(control.options) || control.options.length === 0) {
                return;
            }

            const menu = document.getElementById('wp-mode-menu');
            if (!menu) {
                return;
            }

            const titles = {
                heating: 'Heizen',
                hotwater: 'Warmwasser',
                cooling: 'Kühlen',
                pool: 'Schwimmbad'
            };

            menu.innerHTML = '';

            const title = document.createElement('div');
            title.className = 'wp-mode-title';
            title.textContent = titles[functionName] || functionName;
            menu.appendChild(title);

            const valuesEqual = (left, right) => {
                const leftNumber = Number(left);
                const rightNumber = Number(right);

                if (!Number.isNaN(leftNumber) && !Number.isNaN(rightNumber)) {
                    return leftNumber === rightNumber;
                }

                return String(left) === String(right);
            };

            control.options.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';

                const selected = valuesEqual(option.value, control.currentValue);

                button.textContent = (selected ? '✓ ' : '   ') + option.name;
                button.dataset.value = String(option.value);

                if (selected) {
                    button.classList.add('active');
                    button.setAttribute('aria-current', 'true');
                }

                button.addEventListener('click', (clickEvent) => {
                    clickEvent.preventDefault();
                    clickEvent.stopPropagation();

                    requestAction(
                        'SetControlMode',
                        JSON.stringify({
                            function: functionName,
                            value: option.value
                        })
                    );

                    closeModeMenu();
                });

                menu.appendChild(button);
            });

            menu.style.display = 'block';
        };

        const openHeaterMenu = (event) => {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.getElementById('wp-mode-menu');
            if (!menu) return;
            menu.replaceChildren();
            const title = document.createElement('div');
            title.className = 'wp-mode-title';
            title.textContent = 'Heizstäbe';
            menu.appendChild(title);
            const heaters = Array.isArray(currentControls.heaterSwitches) ? currentControls.heaterSwitches : [];
            heaters.forEach((heater) => {
                const heading = document.createElement('div');
                heading.className = 'wp-mode-title';
                const power = typeof heater.powerWatts === 'number' ? (heater.powerWatts / 1000).toFixed(2) + ' kW' : '—';
                heading.textContent = heater.label + ': ' + (heater.stateLabel || '—') + ' · ' + power;
                menu.appendChild(heading);
                const options = Array.isArray(heater.options) && heater.options.length ? heater.options : [{name:'Nicht schaltbar', value:null}];
                options.forEach((option) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.heater = String(heater.number);
                    button.dataset.value = String(option.value);
                    button.disabled = !heater.configured;
                    button.textContent = (option.role === 'on' ? 'Einschalten: ' : option.role === 'off' ? 'Ausschalten: ' : '') + String(option.name);
                    if (option.value === heater.currentValue) button.classList.add('active');
                    button.addEventListener('click', (clickEvent) => {
                        clickEvent.preventDefault();
                        clickEvent.stopPropagation();
                        if (!heater.configured) return;
                        requestAction('SetHeaterSwitch', JSON.stringify({number:heater.number, value:option.value}));
                        closeModeMenu();
                    });
                    menu.appendChild(button);
                });
            });
            menu.style.display = 'block';
        };

        const openNumericMenu = (functionName, event) => {
            event.preventDefault();
            event.stopPropagation();

            const control = currentControls && currentControls[functionName];
            if (!control || !control.configured) {
                return;
            }

            const menu = document.getElementById('wp-mode-menu');
            if (!menu) {
                return;
            }

            const titles = {
                warmWaterSetpoint: 'Warmwasser-Solltemperatur',
                heatingTemperatureCorrection: 'Heiztemperaturkorrektur',
                roomTemperatureSetpoint: 'Raumtemperatur-Soll'
            };

            const min = Number(control.min);
            const max = Number(control.max);
            const step = Number(control.step) > 0 ? Number(control.step) : 0.5;
            const current = Number(control.currentValue);

            menu.innerHTML = '';

            const title = document.createElement('div');
            title.className = 'wp-mode-title';
            title.textContent = titles[functionName] || functionName;
            menu.appendChild(title);

            const row = document.createElement('div');
            row.className = 'wp-numeric-row';

            const minus = document.createElement('button');
            minus.type = 'button';
            minus.textContent = '−';

            const input = document.createElement('input');
            input.type = 'number';
            input.step = String(step);
            if (Number.isFinite(min)) input.min = String(min);
            if (Number.isFinite(max)) input.max = String(max);
            input.value = Number.isFinite(current) ? String(current) : '0';

            const unit = document.createElement('span');
            unit.className = 'wp-numeric-unit';
            unit.textContent = control.unit || '';

            const plus = document.createElement('button');
            plus.type = 'button';
            plus.textContent = '+';

            const clamp = (value) => {
                if (!Number.isFinite(value) || !Number.isFinite(min)
                    || !Number.isFinite(max) || min > max
                    || !Number.isFinite(step) || step <= 0) {
                    return NaN;
                }
                // Use the input's minimum as the step origin. Only select
                // grid points inside the range, including at either boundary.
                const lastIndex = Math.floor((max - min) / step + 1e-9);
                const index = Math.max(0, Math.min(lastIndex, Math.round((value - min) / step)));
                const result = Number((min + index * step).toPrecision(15));
                return Math.max(min, Math.min(max, result));
            };

            minus.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                input.value = String(
                    clamp(Number(input.value || 0) - step)
                );
            });

            plus.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                input.value = String(
                    clamp(Number(input.value || 0) + step)
                );
            });

            row.appendChild(minus);
            row.appendChild(input);
            row.appendChild(unit);
            row.appendChild(plus);
            menu.appendChild(row);

            const apply = document.createElement('button');
            apply.type = 'button';
            apply.textContent = 'Übernehmen';
            apply.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (input.value.trim() === '') {
                    return;
                }
                const value = clamp(Number(input.value));
                if (!Number.isFinite(value)) {
                    return;
                }

                requestAction(
                    'SetNumericControl',
                    JSON.stringify({
                        function: functionName,
                        value
                    })
                );

                closeModeMenu();
            });
            menu.appendChild(apply);

            menu.style.display = 'block';

            /*
             * Auf Desktop Feld direkt fokussieren.
             * Auf Touch-Geräten nicht automatisch fokussieren, weil sonst
             * WebView/Browser zusätzliche Eingabe-/Pfeilflächen öffnen kann.
             */
            const touchDevice =
                ('ontouchstart' in window)
                || (
                    navigator.maxTouchPoints
                    && navigator.maxTouchPoints > 0
                );

            if (!touchDevice) {
                input.focus();
                input.select();
            }
        };

        const bindTap = (element, handler) => {
            if (!element || element.dataset.symconTapBound) {
                return;
            }

            element.dataset.symconTapBound = '1';

            let pointerHandled = false;

            if (window.PointerEvent) {
                element.addEventListener(
                    'pointerup',
                    (event) => {
                        if (
                            event.pointerType === 'touch'
                            || event.pointerType === 'pen'
                        ) {
                            pointerHandled = true;
                            event.preventDefault();
                            event.stopPropagation();

                            handler(event);

                            window.setTimeout(() => {
                                pointerHandled = false;
                            }, 400);
                        }
                    }
                );
            }

            element.addEventListener(
                'click',
                (event) => {
                    if (pointerHandled) {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }

                    handler(event);
                }
            );
        };


        const luxtronikDialog = document.getElementById('wp-luxtronik-dialog');
        const luxtronikCanvas = document.getElementById('wp-luxtronik-display');
        const luxtronikHelp = document.getElementById('wp-luxtronik-help');
        const luxtronikControls = document.getElementById('wp-luxtronik-controls');
        let luxtronikSocket = null;
        let luxtronikRefresh = null;
        let luxtronikTimeout = null;
        let luxtronikRetryTimer = null;
        let luxtronikRetryCount = 0;
        let luxtronikConnectedAt = 0;
        let luxtronikReconnectOnVisible = false;
        let luxtronikMenuID = '';
        let luxtronikDisplayID = '';
        let luxtronikReady = false;
        let luxtronikPressed = false;
        let eevSocket = null;
        let eevTimeout = null;
        const stopEEVDiagnostic = () => {
            clearTimeout(eevTimeout);
            eevTimeout = null;
            const socket = eevSocket;
            eevSocket = null;
            if (socket && socket.readyState < WebSocket.CLOSING) socket.close(1000, 'Lesetest beendet');
            document.getElementById('wp-eev-test').disabled = false;
            document.getElementById('wp-web-test').disabled = false;
        };
        const getLuxtronikWebURL = () => {
            try {
                const url = new URL(currentConfig.luxtronikWebInterfaceURL || '');
                return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : '';
            } catch (_) {
                return '';
            }
        };
        const sendLuxtronikMove = (direction) => {
            if (luxtronikReady && luxtronikSocket && luxtronikSocket.readyState === WebSocket.OPEN) {
                luxtronikSocket.send('MOVE;' + direction);
            }
        };
        const releaseLuxtronikButton = () => {
            if (luxtronikPressed) sendLuxtronikMove(6);
            luxtronikPressed = false;
        };
        const stopLuxtronik = () => {
            stopEEVDiagnostic();
            releaseLuxtronikButton();
            clearInterval(luxtronikRefresh);
            clearTimeout(luxtronikTimeout);
            clearTimeout(luxtronikRetryTimer);
            luxtronikRefresh = luxtronikTimeout = luxtronikRetryTimer = null;
            luxtronikReconnectOnVisible = false;
            const socket = luxtronikSocket;
            luxtronikSocket = null;
            if (socket && socket.readyState < WebSocket.CLOSING) socket.close(1000, 'Bedienverbindung beendet');
            luxtronikMenuID = luxtronikDisplayID = '';
            luxtronikReady = false;
            luxtronikControls.disabled = true;
            luxtronikCanvas.getContext('2d').clearRect(0, 0, 160, 64);
        };
        const failLuxtronik = (message) => {
            stopLuxtronik();
            luxtronikHelp.textContent = message;
        };
        const retryLuxtronik = (message) => {
            stopLuxtronik();
            if (!luxtronikDialog.open) return;
            if (luxtronikRetryCount >= 3) {
                luxtronikHelp.textContent = message + ' Bitte erneut verbinden oder das Originalinterface öffnen.';
                return;
            }
            if (document.hidden) {
                luxtronikReconnectOnVisible = true;
                luxtronikHelp.textContent = message + ' Die Verbindung wird wiederhergestellt, sobald die Visu sichtbar ist.';
                return;
            }
            const delay = [1000, 3000, 10000][luxtronikRetryCount++];
            luxtronikHelp.textContent = message + ' Automatische Wiederverbindung in ' + (delay / 1000) + ' Sekunden …';
            luxtronikRetryTimer = setTimeout(() => {
                luxtronikRetryTimer = null;
                if (luxtronikDialog.open) connectLuxtronik(true);
            }, delay);
        };
        // Same 160 x 64, vertically packed monochrome LCD format as Lux.js.
        const drawLuxtronikDisplay = (encoded) => {
            const bytes = atob(encoded.trim());
            if (bytes.length !== 1280) throw new Error('Invalid LCD image');
            const context = luxtronikCanvas.getContext('2d');
            const image = context.createImageData(160, 64);
            for (let row = 0; row < 8; row++) {
                for (let column = 0; column < 160; column++) {
                    const byte = bytes.charCodeAt(row * 160 + column);
                    for (let bit = 0; bit < 8; bit++) {
                        const offset = ((row * 8 + bit) * 160 + column) * 4;
                        image.data[offset] = image.data[offset + 1] = byte & (1 << bit) ? 255 : 0;
                        image.data[offset + 2] = image.data[offset + 3] = 255;
                    }
                }
            }
            context.putImageData(image, 0, 0);
            const firstFrame = !luxtronikReady;
            luxtronikReady = true;
            luxtronikControls.disabled = false;
            if (firstFrame && document.activeElement?.tagName !== 'INPUT') luxtronikCanvas.focus({preventScroll: true});
            luxtronikHelp.textContent = 'Verbunden. Das Display mit Pfeiltasten und Enter bedienen oder die Schaltflächen anklicken. Ein Mausklick auf das Display entspricht Enter; auch das Mausrad wird unterstützt.';
        };
        const readLuxtronikResponse = (text) => {
            if (typeof text !== 'string' || text.length > 262144) throw new Error('Invalid response');
            const xml = new DOMParser().parseFromString(text, 'application/xml');
            if (xml.querySelector('parsererror')) throw new Error('Invalid XML');
            const root = xml.documentElement;
            const childText = (node, name) => Array.from(node.children).find(child => child.localName === name)?.textContent.trim() || '';
            if (root.localName === 'Navigation') {
                const remote = Array.from(root.querySelectorAll('item')).find(item =>
                    ['fernsteuerung', 'remote control'].includes(childText(item, 'name').toLowerCase()));
                const id = remote?.getAttribute('id');
                if (!id || /[;\r\n]/.test(id)) {
                    failLuxtronik('Die Fernsteuerung wird vom Regler nicht angeboten. Bitte das Originalinterface separat öffnen.');
                    return;
                }
                // Honor the navigation lock used by the original buildNavLine().
                if (childText(remote, 'readOnly')) {
                    failLuxtronik('Die Fernsteuerung ist vom Regler nicht zur Bedienung freigegeben. Bitte das Originalinterface öffnen.');
                    return;
                }
                if (id !== luxtronikMenuID) {
                    releaseLuxtronikButton();
                    luxtronikReady = false;
                    luxtronikControls.disabled = true;
                    luxtronikMenuID = id;
                    luxtronikDisplayID = '';
                    luxtronikSocket.send('GET;' + id);
                }
            } else if (root.localName === 'Content') {
                const image = root.querySelector('item > img');
                if (!image || !image.parentElement.getAttribute('id')) throw new Error('Missing LCD image');
                luxtronikDisplayID = image.parentElement.getAttribute('id');
                drawLuxtronikDisplay(image.textContent);
            } else if (root.localName.toUpperCase() === 'VALUES' && luxtronikDisplayID) {
                const item = Array.from(root.querySelectorAll('item')).find(node => node.getAttribute('id') === luxtronikDisplayID);
                if (item) drawLuxtronikDisplay(childText(item, 'value'));
            }
            return ['NAVIGATION', 'CONTENT', 'VALUES'].includes(root.localName.toUpperCase());
        };
        const connectLuxtronik = (retry = false) => {
            stopLuxtronik();
            if (!retry) luxtronikRetryCount = 0;
            const address = getLuxtronikWebURL();
            if (!address || !luxtronikDialog.open) return;
            const url = new URL(address);
            document.getElementById('wp-luxtronik-external').href = address;
            // The uploaded Lux.js uses unencrypted ws on 8214, including for HTTPS web URLs.
            const pageProtocol = window.location.protocol === 'about:'
                ? new URL(document.baseURI).protocol : window.location.protocol;
            if (pageProtocol === 'https:') {
                luxtronikHelp.textContent = 'Die Fernsteuerung verwendet einen unverschlüsselten WebSocket (Port 8214). Aus einer HTTPS-Visu bitte „Originalinterface öffnen“ wählen und dort „Fernsteuerung“ öffnen.';
                return;
            }
            if (!('WebSocket' in window)) {
                luxtronikHelp.textContent = 'Der Browser unterstützt keine WebSockets. Bitte das Originalinterface öffnen.';
                return;
            }
            url.protocol = 'ws:';
            url.port = '8214';
            url.pathname = '/';
            url.search = url.hash = '';
            luxtronikHelp.textContent = 'Verbinde mit der Luxtronik …';
            try {
                const socket = new WebSocket(url.href, 'Lux_WS');
                luxtronikSocket = socket;
                luxtronikTimeout = setTimeout(() => failLuxtronik('Keine Displaydaten empfangen. Passwort und Erreichbarkeit von Port 8214 prüfen oder das Originalinterface öffnen.'), 12000);
                socket.onopen = () => {
                    if (luxtronikSocket !== socket) return;
                    luxtronikConnectedAt = Date.now();
                    const password = document.getElementById('wp-luxtronik-password').value;
                    if (/[;\r\n]/.test(password)) {
                        failLuxtronik('Das Passwort darf keine Semikolons oder Zeilenumbrüche enthalten.');
                        return;
                    }
                    socket.send('LOGIN;' + (password || '0'));
                    luxtronikHelp.textContent = 'Anmeldung gesendet – warte auf die Geräteanzeige …';
                    luxtronikRefresh = setInterval(() => {
                        if (luxtronikSocket === socket && socket.readyState === WebSocket.OPEN) socket.send('REFRESH');
                    }, 1000);
                };
                socket.onmessage = (event) => {
                    if (luxtronikSocket !== socket) return;
                    try {
                        const recognized = readLuxtronikResponse(event.data);
                        if (recognized && luxtronikSocket === socket && luxtronikReady) {
                            // Empty Values responses are valid heartbeats for an unchanged LCD.
                            clearTimeout(luxtronikTimeout);
                            luxtronikTimeout = setTimeout(() => retryLuxtronik('Keine Antwort vom Regler.'), 15000);
                            if (Date.now() - luxtronikConnectedAt >= 30000) luxtronikRetryCount = 0;
                        }
                    }
                    catch (_) { failLuxtronik('Die Displaydaten konnten nicht gelesen werden. Bitte erneut verbinden oder das Originalinterface öffnen.'); }
                };
                socket.onerror = () => {
                    if (luxtronikSocket === socket) retryLuxtronik('Verbindung fehlgeschlagen. Der Regler muss auf Port 8214 erreichbar sein.');
                };
                socket.onclose = () => {
                    if (luxtronikSocket === socket) retryLuxtronik('Verbindung zur Luxtronik beendet.');
                };
            } catch (_) {
                failLuxtronik('Der Browser konnte die Verbindung nicht öffnen. Bitte das Originalinterface separat öffnen.');
            }
        };
        const startEEVDiagnostic = (allInformation = false) => {
            stopLuxtronik();
            document.getElementById('wp-eev-diagnostic').open = true;
            const result = document.getElementById('wp-eev-result');
            const copy = document.getElementById('wp-eev-copy');
            const steps = [];
            const groups = [];
            let menus = [];
            let currentMenu = null;
            let allEEV = null;
            let stage = 'Verbindung';
            let requestedID = '';
            result.value = '';
            copy.disabled = true;
            const finish = (report) => {
                stopEEVDiagnostic();
                result.value = JSON.stringify({source:'WebSocket / Lux_WS',capturedAt:new Date().toISOString(),stage,steps,
                    ...(allInformation ? {count:groups.reduce((sum,group)=>sum+group.count,0),groups,eev:allEEV} : {}),...report},null,2);
                copy.disabled = false;
                luxtronikHelp.textContent = report.ok
                    ? 'Lesetest abgeschlossen. Ergebnis kopieren; „Erneut verbinden“ öffnet wieder die Geräteanzeige.'
                    : 'Lesetest: ' + report.error;
            };
            try {
                const address = getLuxtronikWebURL();
                if (!address || !luxtronikDialog.open) throw new Error('Kein aktiver LUX-Webinterface-Zugang.');
                const pageProtocol = window.location.protocol === 'about:' ? new URL(document.baseURI).protocol : window.location.protocol;
                if (pageProtocol === 'https:') throw new Error('Der Lesetest benötigt eine über HTTP geöffnete Visu, damit ws auf Port 8214 erreichbar ist.');
                const password = document.getElementById('wp-luxtronik-password').value;
                if (password.length > 256 || /[;\r\n\x00]/.test(password)) throw new Error('Das Passwort enthält unzulässige Zeichen.');
                const url = new URL(address);
                url.protocol = 'ws:'; url.port = '8214'; url.pathname = '/'; url.search = url.hash = '';
                const socket = new WebSocket(url.href,'Lux_WS');
                eevSocket = socket;
                document.getElementById('wp-eev-test').disabled = true;
                document.getElementById('wp-web-test').disabled = true;
                luxtronikHelp.textContent = 'Lese Informationen → Ausgänge …';
                eevTimeout = setTimeout(() => finish({ok:false,error:'Keine vollständige Antwort innerhalb von 12 Sekunden. Passwort und Port 8214 prüfen.'}),12000);
                const child = (node,name) => Array.from(node.children).find(item => item.localName === name);
                const key = name => String(name).trim().toLocaleLowerCase('de').replace(/[\s_-]+/g,'');
                const find = (root,names) => Array.from(root.querySelectorAll('item')).find(item => names.includes(key(child(item,'name')?.textContent || '')));
                const capture = (root) => {
                    const outputs = Array.from(root.querySelectorAll('item')).filter(item => child(item,'value'))
                        .map(item => ({id:item.getAttribute('id') || '',name:child(item,'name')?.textContent.trim() || '',raw:child(item,'value').textContent.trim()}));
                    const eev = {heating:null,cooling:null};
                    outputs.forEach(item => {
                        const name = key(item.name);
                        const functionName = ['eevheizen','eevheating'].includes(name) ? 'heating'
                            : ['eevkühlen','eevkuehlen','eevcooling'].includes(name) ? 'cooling' : '';
                        if (!functionName) return;
                        if (eev[functionName]) throw new Error('EEV-Bezeichnung mehrfach vorhanden.');
                        const match = item.raw.replace(/\u00a0|\u202f/g,' ').match(/^([0-9]+(?:[.,][0-9]+)?)\s*%?$/);
                        const value = match ? Number(match[1].replace(',','.')) : NaN;
                        eev[functionName] = {...item,value:Number.isFinite(value) && value <= 100 ? value : null,unit:'%'};
                    });
                    if(allInformation) {
                        groups.push({name:currentMenu.name,id:currentMenu.id,count:outputs.length,fields:outputs});
                        if(['ausgänge','ausgaenge','outputs'].includes(key(currentMenu.name))) allEEV=eev;
                        nextMenu();
                        return;
                    }
                    finish({ok:true,path:'Informationen / Ausgänge',eev,
                        missing:Object.entries(eev).filter(([,item])=>item?.value === null || !item).map(([name])=>name),
                        outputs,outputXml:new XMLSerializer().serializeToString(root)});
                };
                const nextMenu = () => {
                    currentMenu=menus.shift();
                    if(!currentMenu) {finish({ok:true,path:'Informationen / alle angebotenen Seiten'});return;}
                    stage='Informationsseite';
                    requestedID=currentMenu.id;
                    steps.push({stage:currentMenu.name,id:requestedID});
                    if(child(currentMenu.node,'item')) {capture(currentMenu.node);return;}
                    if(!/^[^\x00-\x20;]{1,128}$/.test(requestedID)) throw new Error('Ungültige Menükennung für '+currentMenu.name+'.');
                    socket.send('GET;'+requestedID);
                };
                const advance = (root) => {
                    if(allInformation && stage==='Informationen') {
                        menus=Array.from(root.children).filter(item=>item.localName==='item' && child(item,'name') && !child(item,'value'))
                            .map(item=>({name:child(item,'name').textContent.trim(),id:item.getAttribute('id') || '',node:item}));
                        if(!menus.length) throw new Error('Keine Informationsseiten angeboten.');
                        nextMenu();return;
                    }
                    if(allInformation && stage==='Informationsseite') {capture(root);return;}
                    if (stage === 'Ausgänge') { capture(root); return; }
                    const names = stage === 'Navigation' ? ['informationen','information'] : ['ausgänge','ausgaenge','outputs'];
                    const item = find(root,names);
                    if (!item) {
                        steps.push({stage,availableItems:Array.from(root.querySelectorAll('item')).slice(0,128)
                            .map(item=>({id:item.getAttribute('id') || '',name:child(item,'name')?.textContent.trim() || ''}))});
                        throw new Error(stage === 'Navigation' ? 'Menü Informationen fehlt; Anmeldung prüfen.' : 'Menü Ausgänge fehlt.');
                    }
                    stage = stage === 'Navigation' ? 'Informationen' : 'Ausgänge';
                    requestedID = item.getAttribute('id') || '';
                    steps.push({stage,id:requestedID});
                    if (child(item,'item')) { advance(item); return; }
                    if (!/^[^\x00-\x20;]{1,128}$/.test(requestedID)) throw new Error('Ungültige Menükennung für ' + stage + '.');
                    socket.send('GET;' + requestedID);
                };
                socket.onopen = () => {
                    if (eevSocket !== socket) return;
                    stage = 'Navigation';
                    socket.send('LOGIN;' + (password || '0'));
                    socket.send('REFRESH');
                };
                socket.onmessage = event => {
                    if (eevSocket !== socket) return;
                    try {
                        if (typeof event.data !== 'string' || event.data.length > 262144 || /<!DOCTYPE|<!ENTITY/i.test(event.data)) throw new Error('Ungültige XML-Antwort.');
                        const xml = new DOMParser().parseFromString(event.data,'application/xml');
                        if (xml.querySelector('parsererror')) throw new Error('XML-Antwort konnte nicht gelesen werden.');
                        const root = xml.documentElement;
                        const type = root.localName.toLowerCase();
                        if (steps.length < 32) steps.push({stage,reply:type,id:root.getAttribute('id') || ''});
                        if (stage === 'Navigation' && type === 'navigation') advance(root);
                        else if (stage !== 'Navigation' && type === 'content') {
                            if (root.hasAttribute('id') && root.getAttribute('id') !== requestedID) throw new Error('Antwort gehört zu einem anderen Menü.');
                            advance(root);
                        }
                    } catch (error) { finish({ok:false,error:error.message}); }
                };
                socket.onerror = () => { if (eevSocket === socket) finish({ok:false,error:'Verbindung auf Port 8214 fehlgeschlagen. Webinterface ab Firmware 3.81 und Netzwerkzugriff prüfen.'}); };
                socket.onclose = () => { if (eevSocket === socket) finish({ok:false,error:'Der Regler hat die Verbindung beendet; Anmeldung prüfen.'}); };
            } catch (error) { finish({ok:false,error:error.message}); }
        };
        document.getElementById('wp-eev-test').addEventListener('click',()=>startEEVDiagnostic(false));
        document.getElementById('wp-web-test').addEventListener('click',()=>startEEVDiagnostic(true));
        document.getElementById('wp-eev-copy').addEventListener('click',async () => {
            const result = document.getElementById('wp-eev-result');
            try { await navigator.clipboard.writeText(result.value); }
            catch (_) { result.focus(); result.select(); luxtronikHelp.textContent = 'Ergebnis markiert – mit Strg+C oder dem Kopieren-Menü kopieren.'; }
        });
        const closeLuxtronikWebInterface = () => {
            if (!luxtronikDialog) return;
            stopLuxtronik();
            document.getElementById('wp-luxtronik-password').value = '999999';
            if (typeof luxtronikDialog.close === 'function') luxtronikDialog.close();
            else luxtronikDialog.removeAttribute('open');
        };
        const openLuxtronikWebInterface = () => {
            if (!getLuxtronikWebURL() || !luxtronikDialog) return;
            if (luxtronikDialog.open) return;
            if (typeof luxtronikDialog.showModal === 'function') luxtronikDialog.showModal();
            else luxtronikDialog.setAttribute('open', '');
            connectLuxtronik();
        };
        if (luxtronikDialog) {
            luxtronikDialog.addEventListener('close', () => { if (!luxtronikDialog.open) stopLuxtronik(); });
            luxtronikDialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                closeLuxtronikWebInterface();
            });
            window.addEventListener('pagehide', stopLuxtronik);
            window.addEventListener('blur', releaseLuxtronikButton);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && luxtronikDialog.open && luxtronikReconnectOnVisible) connectLuxtronik(true);
            });
            document.getElementById('wp-luxtronik-close').addEventListener('click', closeLuxtronikWebInterface);
            document.getElementById('wp-luxtronik-login').addEventListener('submit', (event) => {
                event.preventDefault();
                connectLuxtronik();
            });
            for (const button of luxtronikControls.querySelectorAll('[data-luxtronik-turn]')) {
                button.addEventListener('click', () => sendLuxtronikMove(Number(button.dataset.luxtronikTurn)));
            }
            const press = () => {
                if (!luxtronikPressed && luxtronikReady) {
                    sendLuxtronikMove(2);
                    luxtronikPressed = true;
                }
            };
            for (const element of [document.getElementById('wp-luxtronik-press'), luxtronikCanvas]) {
                element.addEventListener('pointerdown', (event) => {
                    if (event.button !== 0 || !luxtronikReady) return;
                    element.focus();
                    element.setPointerCapture(event.pointerId);
                    press();
                    event.preventDefault();
                });
                for (const name of ['pointerup', 'pointercancel', 'lostpointercapture', 'blur']) {
                    element.addEventListener(name, releaseLuxtronikButton);
                }
                element.addEventListener('keydown', (event) => {
                    if (['Enter', ' '].includes(event.key)) {
                        event.preventDefault();
                        if (!event.repeat) press();
                    } else if (element === luxtronikCanvas && ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown'].includes(event.key)) {
                        event.preventDefault();
                        sendLuxtronikMove(['ArrowLeft', 'ArrowUp'].includes(event.key) ? 1 : 0);
                    }
                });
                element.addEventListener('keyup', (event) => {
                    if (['Enter', ' '].includes(event.key)) {
                        event.preventDefault();
                        releaseLuxtronikButton();
                    } else if (element === luxtronikCanvas && event.key.startsWith('Arrow')) sendLuxtronikMove(6);
                });
            }
            document.getElementById('wp-luxtronik-press').addEventListener('click', (event) => {
                // Keyboard is handled above; assistive click actions have no pointer events.
                if (event.detail === 0) { press(); releaseLuxtronikButton(); }
            });
            luxtronikCanvas.addEventListener('wheel', (event) => {
                if (!luxtronikReady || !event.deltaY) return;
                event.preventDefault();
                sendLuxtronikMove(event.deltaY < 0 ? 1 : 0);
            }, {passive: false});
        }


        let valveActivityTimer = null;
        const applyModernDashboard = (card) => {
            if (!card || !card.content || !card.content.getAttribute('data-symcon-modern')) {
                return false;
            }

            const svg = card.content;
            const cfg = currentConfig || {};
            const data = currentData || {};
            const controls = currentControls || {};
            const previewAll = cfg.fullConfigurationPreview === true;
            const powerText = (watts) => typeof watts === 'number' && Number.isFinite(watts) ? (watts / 1000).toFixed(2) + ' kW' : '—';
            const heaterWatts = data.heaterRodTotalPower?.value;
            const heaterPowerText = powerText(heaterWatts);
            const heaterPowerDescription = 'Summe der konfigurierten Ist-Leistungsvariablen; W/kW laut Profil.';
            clearTimeout(valveActivityTimer);
            valveActivityTimer = null;
            const valveExpiries = [];
            const recentlyChanged = (...keys) => {
                const changed = Math.max(0, ...keys.map(key => Number(data[key]?.changedAt) || 0));
                const remaining = changed * 1000 + 60000 - Date.now();
                if (!changed || remaining <= 0 || remaining > 60000) return false;
                valveExpiries.push(remaining);
                return true;
            };

            const show = (selector, visible) => {
                const el = svg.querySelector(selector);
                if (el) {
                    el.style.setProperty('display', visible ? 'inline' : 'none', 'important');
                }
            };

            const setText = (selector, value) => {
                const el = svg.querySelector(selector);
                if (el) {
                    el.textContent = value === null || value === undefined ? '' : String(value);
                }
            };

            // Reuse the old settings tile for the original LCD protocol and controls.
            const settings = svg.querySelector('#gSettings');
            show('#gSettings', !!getLuxtronikWebURL());
            if (settings) {
                const texts = settings.querySelectorAll('text');
                if (texts[0]) texts[0].textContent = 'LUX';
                if (texts[1]) texts[1].textContent = 'Bedienfeld';
                settings.style.cursor = 'pointer';
                settings.setAttribute('role', 'button');
                settings.setAttribute('tabindex', '0');
                settings.setAttribute('aria-label', 'LUX Bedienfeld öffnen');
                if (!settings.dataset.symconLuxtronikBound) {
                    bindTap(settings, openLuxtronikWebInterface);
                    settings.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            openLuxtronikWebInterface();
                        }
                    });
                    settings.dataset.symconLuxtronikBound = '1';
                }
            }

            const formatted = (key) => {
                if (!key || !data[key]) return '';
                const item = data[key];
                // A cached profile string is not a reading without a raw value.
                if (item.value === undefined || item.value === null
                    || (typeof item.value === 'string' && item.value.trim() === '')
                    || (typeof item.value === 'number' && !Number.isFinite(item.value))) return '';
                if (item.formatted !== undefined && item.formatted !== null && String(item.formatted) !== '') {
                    return String(item.formatted);
                }
                return String(item.value) + (item.unit ? ' ' + item.unit : '');
            };

            const temperature = (key) => {
                const value = formatted(key);
                return value && !/[°]|\bK\b/.test(value) ? value + ' °C' : value;
            };
            const temperatureLineGap = 28;

            const raw = (key) => {
                if (!key || !data[key]) return null;
                return data[key].value;
            };

            const measuredNumber = (key) => {
                const value = raw(key);
                const number = typeof value === 'number' ? value
                    : typeof value === 'string' && value.trim() !== '' ? Number(value.replace(',', '.')) : NaN;
                return Number.isFinite(number) ? number : null;
            };

            const binary = (key) => {
                const v = raw(key);
                if (typeof v === 'boolean') return v;
                if (typeof v === 'number') return v !== 0;
                if (typeof v === 'string') {
                    return ['1','true','on','yes','ja','ein','active','aktiv'].includes(v.trim().toLowerCase());
                }
                return false;
            };

            const optionName = (control) => {
                if (!control || !control.configured) return '';
                const current = control.currentValue;
                const options = Array.isArray(control.options) ? control.options : [];
                const found = options.find((o) => {
                    const a = Number(o.value), b = Number(current);
                    return (!Number.isNaN(a) && !Number.isNaN(b)) ? a === b : String(o.value) === String(current);
                });
                return found ? String(found.name) : String(current ?? '');
            };

            // Only activated native controls belong in the upper button bar.
            show('#gHPStatusOnOff', false);
            show('#gHPStatusOff', false);
            show('#gHPStatusParty', false);
            show('#gHPStatusSave', false);
            show('#gTimeSymbolNight', false);
            show('#gTimeSymbolDay', false);
            show('#gDefrost', false);
            show('#gAdditionalHeating', false);

            // Real mode values from the configured Symcon profiles.
            setText('#textControlHeating', optionName(controls.heating));
            setText('#textControlHotWater', optionName(controls.hotwater));
            setText('#textControlCooling', optionName(controls.cooling));
            setText('#textControlPool', optionName(controls.pool));

            // Betrieb/Party/Eco sind bewusst keine Elemente der oberen Bedienleiste.
            // Die spätere Event-Bindung erwartet diese Liste weiterhin.
            const binaryDefs = [];

            // Mode buttons are visible only if a real control exists.
            [
                ['heating', '#gHPStatusHeating'],
                ['hotwater', '#gHPStatusWW'],
                ['cooling', '#gHPStatusCooling'],
                ['pool', '#gHPStatusPool']
            ].forEach(([name, selector]) => {
                const c = controls[name];
                show(selector, previewAll || !!(c && c.configured && Array.isArray(c.options) && c.options.length));
            });

            // Numerische Bedienungen: nur anzeigen, wenn wirklich konfiguriert.
            [
                ['warmWaterSetpoint', '#gSymconWarmWaterSetpoint', '#gSymconWarmWaterSetpointValue'],
                ['heatingTemperatureCorrection', '#gSymconHeatingCorrection', '#gSymconHeatingCorrectionValue'],
                ['roomTemperatureSetpoint', '#gSymconRoomTemperatureSetpoint', '#textRoomTemperatureSetpointValue']
            ].forEach(([name, groupSel, valueSel]) => {
                const c = controls[name];
                const configured = !!(c && c.configured);
                show(groupSel, previewAll || configured);
                if (!configured) return;

                const value = c.formattedValue !== undefined && c.formattedValue !== null
                    ? String(c.formattedValue)
                    : String(c.currentValue ?? '');
                setText(valueSel, value);

                const el = svg.querySelector(groupSel);
                if (el) {
                    el.style.cursor = 'pointer';
                    el.style.pointerEvents = 'all';
                    if (!el.dataset.symconModernBound) {
                        bindTap(el, (event) => openNumericMenu(name, event));
                        el.dataset.symconModernBound = '1';
                    }
                }
            });

            const heaterControls = Array.isArray(controls.heaterSwitches) ? controls.heaterSwitches : [];
            show('#gHeaterControlButton', heaterControls.length > 0);
            const heaterButton = svg.querySelector('#gHeaterControlButton');
            if (heaterButton) {
                setText('#textHeaterButtonState', heaterPowerText);
                let title = heaterButton.querySelector('title');
                if (!title) {title = document.createElementNS(svg.namespaceURI,'title');heaterButton.appendChild(title);}
                title.textContent = heaterPowerDescription;
                heaterButton.style.cursor = 'pointer';
                if (!heaterButton.dataset.bound) {
                    bindTap(heaterButton, openHeaterMenu);
                    heaterButton.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') openHeaterMenu(event);
                    });
                    heaterButton.dataset.bound = '1';
                }
            }

            // Sichtbare Buttons lückenlos von links anordnen.
            const topButtons = [
                '#gHPStatusWW',
                '#gHPStatusHeating',
                '#gHPStatusCooling',
                '#gHPStatusPool',
                '#gSymconWarmWaterSetpoint',
                '#gSymconHeatingCorrection',
                '#gSymconRoomTemperatureSetpoint',
                '#gHeaterControlButton',
                '#gSettings'
            ];
            let nextButtonX = 28;
            const buttonGap = 12;
            const minButtonWidth = 135;
            const horizontalPadding = 18;
            topButtons.forEach((selector) => {
                const el = svg.querySelector(selector);
                if (!el || el.style.display === 'none') return;

                const rect = el.querySelector('rect');
                const texts = Array.from(el.querySelectorAll('text'));
                let contentWidth = 0;
                texts.forEach((textEl) => {
                    try {
                        contentWidth = Math.max(contentWidth, textEl.getBBox().width);
                    } catch (_) {}
                });

                const width = Math.max(minButtonWidth, Math.ceil(contentWidth + (horizontalPadding * 2)));
                if (rect) rect.setAttribute('width', String(width));
                el.setAttribute('transform', 'translate(' + nextButtonX + ' 25)');
                nextButtonX += width + buttonGap;
            });

            const ns = 'http://www.w3.org/2000/svg';
            const node = (parent, tag, attrs, text) => {
                const el = document.createElementNS(ns, tag);
                Object.entries(attrs || {}).forEach(([key, value]) => el.setAttribute(key, String(value)));
                if (text !== undefined) el.textContent = text;
                parent.appendChild(el);
                return el;
            };
            const group = (id, parent) => {
                let el = svg.querySelector('#' + id);
                if (!el) el = node(parent, 'g', {id});
                el.replaceChildren();
                return el;
            };
            const fittedText = (parent, attrs, text, width) => {
                const el = node(parent, 'text', attrs, text);
                if (el.getComputedTextLength() > width) {
                    el.setAttribute('textLength', width);
                    el.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                }
                return el;
            };

            // Decode OpenWeather icon codes locally; no remote image request is needed.
            const weather = group('gOutdoorWeatherIcon', svg.querySelector('#info'));
            weather.setAttribute('transform', 'translate(55 195)');
            const weatherCode = [raw('weatherIcon'), data.weatherIcon?.formatted]
                .map(value => String(value ?? '').trim().toLowerCase())
                .find(value => /^(01|02|03|04|09|10|11|13|50)[dn]$/.test(value)) || '';
            const weatherType = weatherCode.slice(0,2);
            const night = weatherCode.endsWith('n');
            const weatherNames = {'01':'Klar','02':'Leicht bewölkt','03':'Bewölkt','04':'Bedeckt',
                '09':'Regenschauer','10':'Regen','11':'Gewitter','13':'Schnee','50':'Nebel'};
            const weatherLabel = weatherCode ? weatherNames[weatherType] + ' (' + (night ? 'Nacht' : 'Tag') + ', ' + weatherCode + ')'
                : 'Standardsymbol Außentemperatur';
            weather.setAttribute('role','img');
            weather.setAttribute('aria-label',weatherLabel);
            weather.setAttribute('data-weather-code',weatherCode);
            node(weather,'title',{},weatherLabel);
            const skySymbol = (parent, isNight) => {
                if (isNight) {
                    node(parent,'path',{d:'M60 14A36 36 0 1 0 85 72A32 32 0 0 1 60 14Z',fill:'#c8d8ef'});
                } else {
                    node(parent,'circle',{cx:50,cy:50,r:19,fill:'#ffc51b'});
                    node(parent,'path',{d:'M50 19V7M50 81V93M19 50H7M81 50H93M28 28L19 19M72 72L81 81M28 72L19 81M72 28L81 19',
                        fill:'none',stroke:'#ffc51b','stroke-width':3});
                }
            };
            if (!weatherCode || weatherType === '01') skySymbol(weather,night);
            else {
                if (weatherType === '02' || weatherType === '10') {
                    skySymbol(node(weather,'g',{transform:'translate(-3 -3) scale(.72)'}),night);
                }
                node(weather,'path',{d:'M25 70C8 70 8 46 25 44C27 23 60 20 68 42C92 39 104 70 80 70Z',
                    fill:weatherType === '04' ? '#7a8999' : '#aebdcb',stroke:'var(--wp-icon)','stroke-width':1.5});
                if (weatherType === '09' || weatherType === '10') {
                    node(weather,'path',{d:'M30 79L24 90M53 79L47 90M76 79L70 90',
                        fill:'none',stroke:'#499ce8','stroke-width':4,'stroke-linecap':'round'});
                } else if (weatherType === '11') {
                    node(weather,'path',{d:'M47 66H63L52 78H61L38 94L44 80H34Z',fill:'#ffc51b'});
                } else if (weatherType === '13') {
                    [28,51,74].forEach(x => node(weather,'path',{d:'M'+x+' 77V91M'+(x-6)+' 80L'+(x+6)+' 88M'+(x-6)+' 88L'+(x+6)+' 80',
                        fill:'none',stroke:'#73bbf1','stroke-width':2,'stroke-linecap':'round'}));
                } else if (weatherType === '50') {
                    node(weather,'path',{d:'M16 76H87M25 85H78M14 94H73',fill:'none',stroke:'var(--wp-icon)','stroke-width':3,'stroke-linecap':'round'});
                }
            }

            // Optional hydraulic components.
            const hk1 = previewAll || (!!cfg.heatingCircuitType1 && cfg.heatingCircuitType1 !== 'off');
            const hk2 = previewAll || (!!cfg.heatingCircuitType2 && cfg.heatingCircuitType2 !== 'off');
            const hk3 = previewAll || (!!cfg.heatingCircuitType3 && cfg.heatingCircuitType3 !== 'off');
            const heating = hk1 || hk2 || hk3;
            show('#gHeating', heating);
            show('#gHK1', hk1);
            show('#gHK2', hk2);
            show('#gHK3', hk3);

            [
                [1, previewAll && (!cfg.heatingCircuitType1 || cfg.heatingCircuitType1 === 'off') ? 'underfloor' : cfg.heatingCircuitType1],
                [2, previewAll && (!cfg.heatingCircuitType2 || cfg.heatingCircuitType2 === 'off') ? 'radiator' : cfg.heatingCircuitType2],
                [3, previewAll && (!cfg.heatingCircuitType3 || cfg.heatingCircuitType3 === 'off') ? 'underfloor' : cfg.heatingCircuitType3]
            ].forEach(([n, type]) => {
                show('#gHKFloor' + n, type === 'underfloor');
                show('#gHKRadiator' + n, type === 'radiator');
            });


            const waterSupplyY = 592.5;
            const waterReturnY = 762.5;
            const dhwLift = -45.5;
            const mixers = Array.isArray(cfg.mixers) ? cfg.mixers.slice(0,3) : [];
            const narrowHydraulics = !previewAll && !cfg.tankHP && !cfg.thermalSolarAvailable;
            const dhwShift = previewAll || cfg.thermalSolarAvailable || cfg.circulation ? 24 : 80;
            const circulationVisible = previewAll || (!!cfg.tankWW && !!cfg.circulation && !!cfg.circulatingPumpRunning);
            const activeMixers = mixers.some(item => [hk1,hk2,hk3][(item.circuit || item.number) - 1]);
            const storageClearance = hk3 && cfg.tankWW ? 1353 + dhwShift : 0;
            const contentRight = Math.max(narrowHydraulics ? Math.max(activeMixers ? 1335 : 1235,storageClearance) : 1570,nextButtonX - buttonGap);
            const heatingShift = narrowHydraulics ? Math.min(0,contentRight - 1525) : 0;
            svg.querySelector('#gHeating')?.setAttribute('transform','translate(' + heatingShift + ' 0)');
            const hotHeaderX = Math.max(825,945 + heatingShift);
            const coldHeaderX = hotHeaderX + 20;
            const hotHeaderLocalX = hotHeaderX - heatingShift;
            const coldHeaderLocalX = coldHeaderX - heatingShift;
            const mixerReadingX = Math.max(990,coldHeaderLocalX + 15);
            const crowdedMixerLabels = narrowHydraulics && mixerReadingX > 990;

            // Separate supply/return headers feed each independently mixed branch.
            const lastCircuit = hk3 ? 3 : (hk2 ? 2 : 1);
            const lastReturn = 295 + (lastCircuit - 1) * 120;
            const firstReturn = 295 + Math.max(0,[hk1,hk2,hk3].findIndex(Boolean)) * 120;
            const firstSupply = firstReturn - 50;
            const headers = group('gHeatingHeaders', svg.querySelector('#gHeating'));
            node(headers, 'path', {class:'hot',d:'M' + hotHeaderLocalX + ' ' + (cfg.tankHP || previewAll ? 245 : firstSupply) + 'V' + (245 + (lastCircuit - 1) * 120)});
            node(headers, 'path', {class:'cold',d:'M' + coldHeaderLocalX + ' ' + (cfg.tankHP || previewAll ? Math.min(firstReturn,380) : firstReturn) + 'V' + (cfg.tankHP || previewAll ? Math.max(lastReturn,380) : lastReturn)});
            [1,2,3].forEach(n => {
                const y = 245 + (n - 1) * 120;
                svg.querySelector('#gHK' + n + ' > path.hot')?.setAttribute('d','M' + hotHeaderLocalX + ' ' + y + 'H1260');
                svg.querySelector('#gHK' + n + ' > path.cold')?.setAttribute('d','M1260 ' + (y + 50) + 'H' + coldHeaderLocalX);
                svg.querySelector('#gHKPumpIcon' + n)?.setAttribute('transform','translate(-25 0)');
                const mixedBranch = mixers.some(item => (item.circuit || item.number) === n);
                svg.querySelector('#textHeatingPump' + n)?.setAttribute('x',crowdedMixerLabels && mixedBranch ? '1200' : '1170');
                svg.querySelector('#textHeatingPump' + n)?.setAttribute('y',String(y - (mixedBranch ? 48 : 31)));
            });
            const bufferPaths = svg.querySelectorAll('#gBuffer > path.hot, #gBuffer > path.cold');
            const bufferGeometry = ['M825 ' + (waterSupplyY - 25) + 'V430H690V245H720','M720 380H705V440H790V' + waterReturnY,'M890 245H945','M965 380H890'];
            bufferPaths.forEach((path,index) => path.setAttribute('d',bufferGeometry[index]));
            svg.querySelector('#gNoBuffer > path.hot')?.setAttribute('d','M825 ' + (waterSupplyY - 25) + 'V' + firstSupply + 'H' + hotHeaderX);
            svg.querySelector('#gNoBuffer > path.cold')?.setAttribute('d','M' + coldHeaderX + ' ' + firstReturn + 'H790V' + waterReturnY);

            show('#gBuffer', heating && (previewAll || !!cfg.tankHP));
            show('#gNoBuffer', heating && !previewAll && !cfg.tankHP);
            const solarBuffer = cfg.solarTarget === 'buffer' && !!cfg.tankHP;
            const solarHotWater = !!cfg.thermalSolarAvailable && !solarBuffer;
            show('#gDHW', previewAll || !!cfg.tankWW || solarHotWater);
            svg.querySelector('#gDHW')?.setAttribute('transform','translate(' + dhwShift + ' ' + dhwLift + ')');
            svg.querySelector('#gDHW > path.hot')?.setAttribute('d','M' + (850 - dhwShift) + ' ' + (waterSupplyY - dhwLift) + 'H900');
            svg.querySelector('#gDHW > path.cold')?.setAttribute('d','M900 ' + (waterReturnY - dhwLift) + 'H' + (790 - dhwShift));
            show('#gSolar', previewAll || (!!cfg.thermalSolarAvailable && (solarBuffer ? heating : true)));
            const solarPipe = svg.querySelector('#gSolar > path.sol');
            if (solarPipe) solarPipe.setAttribute('d', solarBuffer
                ? 'M884 300H910V155H1230V680H1260M1260 820H1245V140H900V330H884'
                : 'M' + (1064 + dhwShift) + ' ' + (790 + dhwLift) + 'H1185V680H1260M1260 820H1185V' + (835 + dhwLift) + 'H' + (1064 + dhwShift));
            const solarPumpIcon = svg.querySelector('#gSolarPumpIcon');
            if (solarPumpIcon) solarPumpIcon.setAttribute('transform', solarBuffer ? 'translate(45 -155)' : 'translate(0 ' + dhwLift + ')');

            // Optional DHW circulation uses its confirmed native output.
            show(
                '#gCirculatingPumpVisual',
                circulationVisible
            );

            // Vollansicht bedeutet bewusst: maximale Anlagenkonfiguration.
            // Komponenten werden zur Layoutkontrolle gezeigt, auch wenn keine
            // Symcon-Variable dafür konfiguriert ist. Unkonfigurierte Werte bleiben leer.
            if (previewAll) {
                [
                    '#gBuffer',
                    '#gDHW',
                    '#gSolar',
                    '#gCirculatingPumpVisual',
                    '#gHeating',
                    '#gHK1',
                    '#gHK2',
                    '#gHK3'
                ].forEach((selector) => show(selector, true));

                // In der Maximalansicht drei unterschiedliche Heizflächen zeigen.
                show('#gHKFloor1', true);
                show('#gHKRadiator1', false);
                show('#gHKFloor2', false);
                show('#gHKRadiator2', true);
                show('#gHKFloor3', true);
                show('#gHKRadiator3', false);


            }

            // Live values.
            setText('#textOutdoorTemperatureValue', formatted(cfg.outdoorTemperature));
            let indoorKey = cfg.ambientTemperatureNormal;
            if (binary(cfg.heatingPumpPartyMode) && formatted(cfg.ambientTemperatureParty)) {
                indoorKey = cfg.ambientTemperatureParty;
            } else if (binary(cfg.heatingPumpNightMode) && formatted(cfg.ambientTemperatureReduced)) {
                indoorKey = cfg.ambientTemperatureReduced;
            }
            setText('#textIndoorTemperatureValue', formatted(indoorKey));

            // Room sources are explicit external additions; all other readings use own Idents.
            const rooms = Array.isArray(cfg.roomTemperatures) ? cfg.roomTemperatures.slice(0, 3) : [];
            const roomGroup = group('gRoomTemperatures', svg.querySelector('#info'));
            show('#textIndoorTemperatureValue', rooms.length === 0);
            show('#indoorDefaultIcon', rooms.length === 0);
            const roomHeading = svg.querySelector('#textIndoorTemperatureLabel');
            if (roomHeading) { roomHeading.textContent = ''; show('#textIndoorTemperatureLabel', false); }
            rooms.forEach((room, index) => {
                const single = rooms.length === 1;
                const size = single ? 54 : (rooms.length === 2 ? 31 : 27);
                const y = single ? 343 : (rooms.length === 2 ? 316 + index * 47 : 307 + index * 35);
                const row = node(roomGroup, 'g', {id: 'gRoomTemperature' + (index + 1)});
                const showIcon = room.display !== 'name' && room.icon !== 'none';
                const showName = room.display !== 'icon';
                if (showIcon) {
                    if (room.icon && /^fa-(light|regular|solid|brands|kit) fa-[a-z0-9-]+$/.test(room.icon)) {
                        const icon = node(row, 'foreignObject', {x: 59, y, width: size + 5, height: size + 2});
                        const body = document.createElementNS('http://www.w3.org/1999/xhtml', 'div');
                        body.style.cssText = 'color:var(--wp-icon);font-size:' + size + 'px;line-height:1.1;text-align:center';
                        const i = document.createElementNS('http://www.w3.org/1999/xhtml', 'i');
                        i.setAttribute('class', room.icon);
                        i.setAttribute('aria-hidden', 'true');
                        body.appendChild(i);
                        icon.appendChild(body);
                    } else {
                        node(row, 'path', {class:'w', d:'M2 22L24 2L46 22M9 16V43H39V16',
                            transform:'translate(59 ' + y + ') scale(' + size / 48 + ')'});
                    }
                }
                if (showName) fittedText(row, {id:'textRoomName' + (index + 1), class:'sm', x:single || !showIcon ? 55 : 101, y:single ? 325 : y + size * .8,
                    style:'font-size:' + (single ? 19 : 18) + 'px'}, room.label || 'Raum', single ? 250 : showIcon ? 103 : 149);
                fittedText(row, {id:'textRoomTemperature' + (index + 1), class:'md', x:single ? (showIcon ? 135 : 55) : 305,
                    y:single ? 393 : y + size * .8, 'text-anchor':single ? 'start' : 'end',
                    style:'font-size:' + (single ? 42 : rooms.length === 2 ? 26 : 23) + 'px'},
                    formatted(room.key), single ? (showIcon ? 175 : 250) : 94);
            });

            // Each mixer belongs to its selected branch, downstream of the optional buffer.
            // Auf/Zu are independent relay signals, not valve position measurements.
            const circuitPump = n => {
                const mixer = mixers.find(item => (item.circuit || item.number) === n);
                return mixer ? mixer.pump : cfg[n === 1 ? 'heatingCircuitPumpRunning' : 'heatingCircuitPumpRunning' + n];
            };
            const circuitPumpValue = n => {
                const mixer = mixers.find(item => (item.circuit || item.number) === n);
                return mixer ? (mixer.number === 1 ? cfg.heatingCircuitPumpValue : '')
                    : cfg[n === 1 ? 'heatingCircuitPumpValue' : 'heatingCircuitPumpValue' + n];
            };
            const pumpState = (runningKey, percentageKey) => {
                const relayValue = raw(runningKey);
                const relayKnown = relayValue !== null && relayValue !== undefined && relayValue !== '';
                const percentage = measuredNumber(percentageKey);
                const percent = percentage !== null && percentage >= 0 ? Math.min(100, percentage) : null;
                const active = (!relayKnown || binary(runningKey)) && (percent !== null ? percent > 0 : binary(runningKey));
                return {active, percent};
            };
            const relay = (key) => raw(key) === null || raw(key) === undefined ? '—' : (binary(key) ? 'Ein' : 'Aus');
            const mixerSymbol = (parent, mixer, x, y, radius) => {
                const known = raw(mixer.open) !== null && raw(mixer.open) !== undefined && raw(mixer.close) !== null && raw(mixer.close) !== undefined;
                const state = !known ? 'unknown' : binary(mixer.open) && binary(mixer.close) ? 'conflict'
                    : binary(mixer.open) ? 'opening' : binary(mixer.close) ? 'closing' : 'stopped';
                const labels = {unknown:'Status unbekannt',conflict:'Auf und Zu aktiv',opening:'Ansteuerung Auf',closing:'Ansteuerung Zu',stopped:'Keine Ansteuerung'};
                const symbol = node(parent, 'g', {id:'gMixerValveSymbol' + (mixer.circuit || mixer.number), 'data-mixer':mixer.number, role:'img',
                    'aria-label':'Mischer ' + mixer.number + ': ' + labels[state], 'data-state':state,
                    transform:'translate(' + x + ' ' + y + ')'});
                node(symbol, 'title', {}, 'Mischer ' + mixer.number + ': ' + labels[state] + '. Auf ' + relay(mixer.open) + ', Zu ' + relay(mixer.close) + '. Keine Positionsrückmeldung.');
                node(symbol, 'circle', {r:radius, fill:'var(--wp-button)', stroke:state === 'conflict' ? '#f04444' : 'var(--wp-icon)', 'stroke-width':2});
                node(symbol, 'path', {d:'M-14-6L-5 0L-14 6Z M14-6L5 0L14 6Z M-6 14L0 5L6 14Z',
                    fill:'var(--wp-icon)', stroke:'var(--wp-icon)', 'stroke-width':1, transform:'scale(' + radius / 18 + ')'});
                const recent = recentlyChanged(mixer.open, mixer.close);
                node(symbol, 'circle', {r:2,fill:'var(--wp-icon)'});
                const scale = radius / 18;
                const actuator = node(symbol, 'g', {transform:'scale(' + scale + ')'});
                node(actuator, 'path', {d:'M18 0H23',fill:'none',stroke:'var(--wp-icon)','stroke-width':1.5});
                node(actuator, 'rect', {x:23,y:-24,width:26,height:48,rx:3,fill:'var(--wp-button)',
                    stroke:state === 'conflict' ? '#f04444' : 'var(--wp-icon)','stroke-width':1.5});
                node(actuator, 'text', {x:36,y:-4,'text-anchor':'middle',style:'font-size:18px;font-weight:bold'}, 'M');
                node(actuator, 'text', {x:36,y:16,'text-anchor':'middle',class:recent ? 'wp-valve-recent' : '',
                    style:'font-size:18px;font-weight:bold'},
                    {opening:'↑',closing:'↓',stopped:'•',unknown:'?',conflict:'!'}[state]);
                return symbol;
            };
            let mixerStyle = svg.querySelector('#mixerMotionStyle');
            if (!mixerStyle) {
                mixerStyle = node(svg, 'style', {id:'mixerMotionStyle'});
                mixerStyle.textContent = '@keyframes valveRecentChange{0%,100%{opacity:1}50%{opacity:.45}}.wp-valve-recent{animation:valveRecentChange 1.5s ease-in-out infinite}@media(prefers-reduced-motion:reduce){.wp-valve-recent{animation:none}}';
            }
            const mixerReadings = group('gMixerValveReadings', svg.querySelector('#gHeating'));
            [1,2,3].forEach(n => {
                const branch = svg.querySelector('#gHK' + n);
                const hydraulic = group('gHKMixer' + n, branch);
                const mixer = mixers.find(item => (item.circuit || item.number) === n);
                if (!mixer || ![hk1,hk2,hk3][n - 1]) return;
                const y = 245 + (n - 1) * 120;
                node(hydraulic, 'path', {id:'pathHKMixingReturn' + n,class:'cold',d:'M1045 ' + (y + 50) + 'V' + (y + 18)});
                node(hydraulic, 'circle', {cx:1045,cy:y + 50,r:4,fill:'#35a6ff'});
                mixerSymbol(hydraulic, mixer, 1045, y, 18);
                const readings = node(mixerReadings, 'g', {id:'gMixerReading' + n,transform:'translate(' + mixerReadingX + ' ' + (y - temperatureLineGap) + ')'});
                node(readings, 'title', {}, 'Mischer ' + mixer.number + ': Vorlauf Ist und Soll');
                fittedText(readings, {id:'textMixerActual' + n,class:'sm',x:0,y:0,'dominant-baseline':'text-after-edge',style:'font-size:18px'},'MK' + mixer.number + ': ' + (temperature(mixer.actual) || '—'),90);
                fittedText(readings, {id:'textMixerTarget' + n,class:'sm',x:100,y:0,'dominant-baseline':'text-after-edge',style:'font-size:18px'},'Soll: ' + (temperature(mixer.target) || '—'),90);
            });

            // The information panel keeps its size; operating data replaces VL/RL.
            const info = svg.querySelector('#info');
            const status = svg.querySelector('#textHPStatus');
            if (status) {
                info.appendChild(status);
                status.setAttribute('x', '365');
                status.setAttribute('y', '185');
                status.style.fontSize = '20px';
            }
            const efficiency = group('gEfficiency', info);
            const labelStyle = getComputedStyle(svg.querySelector('#textHeatingPowerLabel') || svg.querySelector('#info > text.lb') || info);
            const valueStyle = getComputedStyle(svg.querySelector('#textPowerValue') || info);
            [[cfg.computedCOP, 'COP', 365, 480], [cfg.computedJAZ, 'JAZ', 505, 650]].forEach(([key, label, x, right]) => {
                const heading = node(efficiency, 'text', {id:'textLabel' + label, class:'lb', x, y:248}, label);
                const value = node(efficiency, 'text', {id:'textComputed' + label, class:'md', x:right, y:248,
                    'text-anchor':'end'}, formatted(key) || '—');
                [[heading,labelStyle],[value,valueStyle]].forEach(([el, reference]) => {
                    ['font-size','font-family','font-weight'].forEach((name) => el.style.setProperty(name, reference.getPropertyValue(name), 'important'));
                });
            });
            const inputPower = group('gInputPower', info);
            const inputWatts = data[cfg.inputPower]?.watts;
            fittedText(inputPower, {id:'textInputPower', class:'md', x:650, y:385, 'text-anchor':'end'}, powerText(inputWatts), 100);
            node(inputPower, 'title', {}, data[cfg.inputPower]?.sourceLabel || 'Keine gültige Eingangsleistung verfügbar');
            const heatOutput = svg.querySelector('#textPowerValue');
            if (heatOutput) {
                heatOutput.setAttribute('x', '650');
                heatOutput.setAttribute('text-anchor', 'end');
            }

            // Each sensor keeps its physical level, even if the other sensors
            // are absent. Removing a link also removes the old text completely.
            const placeTankValues = (definitions) => {
                definitions.forEach(([selector, key, y]) => {
                    const el = svg.querySelector(selector);
                    if (!el) return;
                    const value = formatted(key);
                    el.textContent = value;
                    el.style.setProperty('display', value !== '' ? 'inline' : 'none', 'important');
                    el.setAttribute('y', String(y));
                });
            };

            // Only the measured DHW temperature belongs in the tank; the setpoint is in the top button.
            const dhwReadings = group('gDHWTemperatures', svg.querySelector('#gDHW'));
            fittedText(dhwReadings, {id:'textDHWIst', class:'md', x:982, y:710, 'text-anchor':'middle'}, temperature(cfg.tankTempWWMiddle) || '—', 130);
            const boilerPower = group('gBoilerHeaterPower', svg.querySelector('#gDHW'));
            const total = heaterWatts;
            if (data.heaterRodTotalPower) {
                const active = typeof total === 'number' && total > 0;
                const powerRow = node(boilerPower, 'g', {id:'gBoilerHeaterPowerRow'});
                node(powerRow, 'path', {id:'pathBoilerHeatBolt', d:'M11 0L0 22H10L5 42L24 16H14L20 0Z',
                    transform:'translate(0 -29) scale(.8)', fill:'#ffc51b',
                    class:active ? 'wp-boiler-heater-active' : '', opacity:active ? 1 : .35});
                fittedText(powerRow, {id:'textBoilerHeaterPower', class:'md', x:27, y:0}, heaterPowerText, 95);
                const powerBounds = powerRow.getBBox();
                powerRow.setAttribute('transform', 'translate(' + (982 - powerBounds.x - powerBounds.width / 2) + ' 813)');
                node(boilerPower,'title',{},heaterPowerDescription);
            }
            let heaterStyle = svg.querySelector('#boilerHeaterStyle');
            if (!heaterStyle) {
                heaterStyle = node(svg, 'style', {id:'boilerHeaterStyle'});
                heaterStyle.textContent = '@keyframes boilerHeatPulse{0%,100%{opacity:.45}50%{opacity:1}}.wp-boiler-heater-active{animation:boilerHeatPulse 1.6s ease-in-out infinite}@media(prefers-reduced-motion:reduce){.wp-boiler-heater-active{animation:none}}';
            }

            placeTankValues([
                ['#textTankTempHPUp', cfg.tankTempHPUp, 82],
                ['#textTankTempHPMiddle', cfg.tankTempHPMiddle, 128],
                ['#textTankTempHPDown', cfg.tankTempHPDown, 190]
            ]);
            const solarReading = group('gSolarStorageReading', svg.querySelector('#gSolar'));
            if (formatted(cfg.solarStorageTemperature)) {
                fittedText(solarReading, {id:'textSolarStorageTemperature', class:'sm', x:1295, y:900,
                    style:'font-size:14px'}, 'Speicher ' + formatted(cfg.solarStorageTemperature), 215);
            }

            setText('#textHeatingCircuitName1', cfg.heatingCircuitName1 || 'HK1');
            setText('#textHeatingCircuitName2', cfg.heatingCircuitName2 || 'HK2');
            setText('#textHeatingCircuitName3', cfg.heatingCircuitName3 || 'HK3');

            [
                [1, cfg.supplyTemperatureHeating],
                [2, cfg.supplyTemperatureHeating2],
                [3, cfg.supplyTemperatureHeating3]
            ].forEach(([n, supply]) => {
                const mixer = mixers.find(item => (item.circuit || item.number) === n);
                if (mixer) supply = mixer.actual;
                const pumpValueItem = circuitPumpValue(n);
                const supplySelector = n === 1 ? '#textSupplyTemperatureHeating' : '#textSupplyTemperatureHeating' + n;
                const value = svg.querySelector(supplySelector);
                const name = svg.querySelector('#textHeatingCircuitName' + n);
                const offset = (n - 1) * 120;
                if (name) {
                    const fullName = cfg['heatingCircuitName' + n] || 'HK' + n;
                    name.setAttribute('y',String(232.5 + offset));
                    name.setAttribute('x','1370');
                    name.setAttribute('text-anchor','start');
                    name.setAttribute('dominant-baseline','text-before-edge');
                    name.setAttribute('class','sm');
                    name.style.fontSize = '18px';
                    name.removeAttribute('textLength');
                    name.removeAttribute('lengthAdjust');
                    let visibleName = fullName;
                    name.textContent = visibleName;
                    while (name.getComputedTextLength() > 140 && visibleName.length > 1) {
                        visibleName = visibleName.slice(0,-1);
                        name.textContent = visibleName + '…';
                    }
                    name.parentElement.querySelector('title').textContent = fullName;
                }
                if (value) {
                    const reading = temperature(supply);
                    value.textContent = reading ? 'VL: ' + reading : '';
                    value.style.display = reading !== '' ? 'inline' : 'none';
                    value.style.fontSize = '18px';
                    value.setAttribute('x','1370');
                    value.setAttribute('y',String(260.5 + offset));
                    value.setAttribute('text-anchor','start');
                    value.setAttribute('dominant-baseline','text-before-edge');
                    value.removeAttribute('textLength');
                    value.removeAttribute('lengthAdjust');
                    if (value.getComputedTextLength() > 140) {
                        value.setAttribute('textLength','140');
                        value.setAttribute('lengthAdjust','spacingAndGlyphs');
                    }
                }

                // HUP percentage belongs to mixer 1; no invented RPM or percentages for other pumps.
                const pumpText = pumpValueItem ? formatted(pumpValueItem) : '';
                setText('#textHeatingPump' + n, n === 1 && pumpText ? pumpText + (pumpText.includes('%') ? '' : ' %') : pumpText);
                const pumpLabel = svg.querySelector('#textHeatingPump' + n);
                if (pumpLabel) {
                    pumpLabel.style.fontSize = '18px';
                    pumpLabel.removeAttribute('textLength');
                    pumpLabel.removeAttribute('lengthAdjust');
                    if (pumpLabel.getComputedTextLength() > 150) {
                        pumpLabel.setAttribute('textLength', '150');
                        pumpLabel.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                    }
                }
            });

            setText('#textEvaporatorPressure', formatted(cfg.evaporatorPressure));
            setText('#textEvaporatorTemperature', formatted(cfg.evaporatorTemperature));
            setText('#textCondenserPressure', formatted(cfg.condenserPressure));
            setText('#textCondenserTemperature', formatted(cfg.condenserTemperature));
            const hotGasReadings = group('gHotGasReadings', svg.querySelector('#gRefrigerantCircuit'));
            const hotGas = temperature(cfg.hotGasTemperature);
            if (hotGas) fittedText(hotGasReadings,{id:'textHotGasTemperature',class:'sm',x:630,
                y:575 - temperatureLineGap,'text-anchor':'end','dominant-baseline':'text-after-edge'},'Heißgas: ' + hotGas,150);
            setText('#textExpansionValveOpening', formatted(cfg.expansionValveOpening));
            const eevCooling = binary(cfg.heatingPumpCoolingMode);
            const eevKey = eevCooling ? cfg.eevCoolingOpening : cfg.eevHeatingOpening;
            const eevValue = raw(eevKey);
            const validEEV = eevValue !== null && eevValue !== '' && typeof eevValue !== 'boolean'
                && Number.isFinite(Number(eevValue)) && Number(eevValue) >= 0 && Number(eevValue) <= 100;
            setText('#textExpansionValveOpening', 'EEV ' + (eevCooling ? 'Kühlen' : 'Heizen') + ': ' + (validEEV ? Number(eevValue).toFixed(1) + ' %' : '—'));

            const expansionLabel = svg.querySelector('#textExpansionValveLabel');
            const expansionSymbol = svg.querySelector('#expansionValveSymbol');
            const expansionValue = svg.querySelector('#textExpansionValveOpening');
            if (expansionLabel && expansionSymbol && expansionValue) {
                const labelBounds = expansionLabel.getBBox();
                const valveBounds = expansionSymbol.getBBox();
                if (labelBounds.height > 0 && valveBounds.height > 0) {
                    const gap = Math.max(0,valveBounds.y - labelBounds.y - labelBounds.height);
                    expansionValue.setAttribute('y',String(valveBounds.y + valveBounds.height + gap));
                }
            }

            // Umschaltventil: EIN = Warmwasser, AUS = Heizung.
            // Nur anzeigen, wenn eine Variable konfiguriert ist.
            const wwValveToHotWater =
                !!cfg.wwHeatingValve && binary(cfg.wwHeatingValve);

            // Angeforderte Richtung darstellen; keine gemessene Ventilposition.
            // Heizung    = von links kommend nach oben
            // Warmwasser = von links nach rechts durchgehend
            const valveKnown = !!cfg.wwHeatingValve && raw(cfg.wwHeatingValve) !== null && raw(cfg.wwHeatingValve) !== undefined;
            const switchValveFlow = svg.querySelector('#switchValveFlow');
            if (switchValveFlow) {
                switchValveFlow.setAttribute(
                    'd',
                    !valveKnown ? '' : wwValveToHotWater ? 'M-16 0H16' : 'M-16 0H0V-16'
                );
                switchValveFlow.classList.toggle('wp-valve-recent', recentlyChanged(cfg.wwHeatingValve));
                switchValveFlow.setAttribute('data-target', !valveKnown ? 'unknown' : wwValveToHotWater ? 'hotwater' : 'heating');
                const description = valveKnown ? 'Ansteuerung ' + (wwValveToHotWater ? 'Warmwasser' : 'Heizung') + '; keine Positionsrückmeldung' : 'Ansteuerung unbekannt; keine Positionsrückmeldung';
                switchValveFlow.setAttribute('aria-label',description);
                const valve = svg.querySelector('#switchValve');
                let title = valve.querySelector('title');
                if (!title) title = node(valve,'title',{});
                title.textContent = description;
            }
            if (valveExpiries.length) {
                valveActivityTimer = setTimeout(() => applyModernDashboard(card), Math.min(...valveExpiries) + 50);
            }
            setText('#textPowerValue', formatted(cfg.heatingPumpPower));
            setText('#textCompressorSpeedValue', formatted(cfg.compressorValue));
            const frequencyReadings = group('gCompressorFrequencyReadings', svg.querySelector('#gRefrigerantCircuit'));
            if (formatted(cfg.compressorFrequencyTarget)) {
                const frequencyActual = data[cfg.compressorValue]?.ident === 'Verdichterfrequenz';
                setText('#textCompressorSpeedValue', 'Ist ' + (formatted(cfg.compressorValue) || '—'));
                const targetText = frequencyActual ? 'Soll ' + formatted(cfg.compressorFrequencyTarget)
                    : 'Frequenz Ist ' + (formatted(cfg.compressorFrequencyActual) || '—') + ' / Soll ' + formatted(cfg.compressorFrequencyTarget);
                node(frequencyReadings,'text',{id:'textCompressorFrequencyTarget',class:'sm',x:422.5,y:644,'text-anchor':'middle',style:'font-size:16px'},targetText);
            }
            setText('#textThermalSolarPanelTemp', formatted(cfg.thermalSolarPanelTemp));
            setText('#textThermalSolarPumpSpeed', formatted(cfg.thermalSolarPumpSpeed) || (cfg.thermalSolarPump ? relay(cfg.thermalSolarPump) : '—'));

            // Wärmepumpen-Status: ausschließlich der formatierte Profilwert
            // der konfigurierten Betriebsstatusvariable. Keine erfundenen Texte.
            setText('#textHPStatus', cfg.operatingStatus ? formatted(cfg.operatingStatus) : '');
            if (status) {
                status.removeAttribute('textLength');
                status.removeAttribute('lengthAdjust');
                if (status.getComputedTextLength() > 285) {
                    status.setAttribute('textLength', '285');
                    status.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                }
            }
            const waterLineGap = 12;
            const flow = formatted(cfg.heatingFlow);
            const flowReadings = group('gHeatPumpFlow', svg.querySelector('#main'));
            if (flow !== '') {
                node(flowReadings,'title',{},'Durchflussquelle: ' + (data[cfg.heatingFlow]?.ident || 'nicht verfügbar'));
                fittedText(flowReadings, {id:'textHeatingFlow', class:'sm', x:680, y:waterSupplyY - waterLineGap,
                    'dominant-baseline':'text-after-edge', style:'font-size:18px', 'aria-label':'Durchfluss: ' + flow},
                    flow + (/l\s*\/\s*h/i.test(flow) ? '' : ' l/h'), 110);
            }
            // Common WP temperatures belong before the DHW/heating split, in every operating mode.
            const wpTemperatures = group('gHeatPumpTemperatures', svg.querySelector('#heatPump'));
            wpTemperatures.setAttribute('transform','translate(680 0)');
            [
                ['textWPSupplyTemperature','VL',cfg.supplyTemperature,waterSupplyY,true],
                ['textWPReturnTemperature','RL',cfg.heatPumpReturnTemperature,waterReturnY,false],
                ['textWPReturnTarget','RL Soll',cfg.returnTemperatureSetpoint,waterReturnY,true]
            ].forEach(([id,label,key,lineY,below]) => {
                const reading = temperature(key);
                if (!reading) return;
                fittedText(wpTemperatures,{id,class:'sm',x:0,
                    y:lineY + (below ? waterLineGap : -waterLineGap),
                    'dominant-baseline':below ? 'text-before-edge' : 'text-after-edge'},
                    label + ': ' + reading,below ? 155 : 105);
            });

            // Touch handling. Bind once per SVG element.
            [
                ['heating', '#gHPStatusHeating'],
                ['hotwater', '#gHPStatusWW'],
                ['cooling', '#gHPStatusCooling'],
                ['pool', '#gHPStatusPool']
            ].forEach(([name, selector]) => {
                const el = svg.querySelector(selector);
                const c = controls[name];
                if (!el || !c || !c.configured || !Array.isArray(c.options) || !c.options.length) return;
                el.style.cursor = 'pointer';
                el.style.pointerEvents = 'all';
                if (!el.dataset.symconModernBound) {
                    bindTap(el, (event) => openModeMenu(name, event));
                    el.dataset.symconModernBound = '1';
                }
            });

            binaryDefs.forEach(([name, groupSel, _textSel, dataKey]) => {
                const el = svg.querySelector(groupSel);
                const c = controls[name];
                if (!el || !c || !c.configured || !dataKey) return;
                el.style.cursor = 'pointer';
                el.style.pointerEvents = 'all';
                if (!el.dataset.symconModernBound) {
                    bindTap(el, (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        requestAction('SetBinaryControl', JSON.stringify({
                            function: name,
                            value: !binary(dataKey)
                        }));
                    });
                    el.dataset.symconModernBound = '1';
                }
            });

            // Lüfterlogik wie im ursprünglich geposteten Modul:
            // FanSpeed steuert die Drehzahl. Nur wenn FanSpeed nicht konfiguriert
            // ist, wird HpRunning als Fallback verwendet.
            const fanReading = group('gFanSpeedReading', svg.querySelector('#gHPUnitCabinet'));
            const fanSpeedText = formatted(cfg.fanSpeed);
            if (fanSpeedText) fittedText(fanReading,{id:'textFanSpeed',class:'sm',x:145,y:602,
                'text-anchor':'middle','dominant-baseline':'text-after-edge'},
                'Lüfter: ' + fanSpeedText + (/(?:rpm|u\s*\/\s*(?:min|m))\b/i.test(fanSpeedText) ? '' : ' RPM'),160);
            const fanRotor = svg.querySelector('#pathHPFan');
            if (!svg.querySelector('#symconModernRotationStyle')) {
                const style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
                style.setAttribute('id', 'symconModernRotationStyle');
                style.textContent = `
                    @keyframes symcon-modern-rotate {
                        from { transform: rotate(0deg); }
                        to   { transform: rotate(360deg); }
                    }
                `;
                svg.appendChild(style);
            }

            if (fanRotor) {
                let rpm = 0;
                if (cfg.fanSpeed && data[cfg.fanSpeed]) {
                    const fanRaw = raw(cfg.fanSpeed);
                    const normalized = String(fanRaw ?? '').trim().toLowerCase();
                    if (['true','on','yes','ja','ein','active','aktiv'].includes(normalized)) {
                        rpm = 250;
                    } else if (!['false','off','no','nein','aus','inactive','inaktiv',''].includes(normalized)) {
                        rpm = Number(fanRaw);
                        if (!Number.isFinite(rpm)) rpm = 0;
                    }
                }

                // Fallback: liefert FanSpeed keinen positiven Wert, aber die
                // Wärmepumpe läuft, soll der Lüfter trotzdem sichtbar drehen.
                if (rpm <= 0 && cfg.hpRunning && binary(cfg.hpRunning)) {
                    rpm = 250;
                }

                let duration = 0;
                if (rpm > 0) {
                    const clamped = Math.max(0, Math.min(500, rpm));
                    const points = [[0,3.0],[100,2.5],[200,1.8],[300,1.2],[400,0.8],[500,0.5]];
                    duration = 3.0;
                    for (let i = 0; i < points.length - 1; i++) {
                        const [r1,d1] = points[i], [r2,d2] = points[i+1];
                        if (clamped >= r1 && clamped <= r2) {
                            const f = (clamped-r1)/(r2-r1);
                            duration = d1 + (d2-d1)*f;
                            break;
                        }
                    }
                }

                fanRotor.style.setProperty('transform-box', 'view-box', 'important');
                fanRotor.style.setProperty('transform-origin', '0px 0px', 'important');
                fanRotor.style.setProperty(
                    'animation',
                    duration > 0 ? 'symcon-modern-rotate ' + duration.toFixed(2) + 's linear infinite' : 'none',
                    'important'
                );
            }

            // Command percentage controls schematic speed; an explicit off relay stops it.
            const pumpStates = [1,2,3].map(n => pumpState(circuitPump(n), circuitPumpValue(n)));
            pumpStates.forEach((state, index) => {
                const n = index + 1;
                const rotor = svg.querySelector('#hkPumpRotor' + n);
                if (!rotor) return;
                const speed = state.active ? (state.percent ?? 50) : 0;
                const duration = speed > 0
                    ? Math.max(0.55, Math.min(2.8, 3.0 - Math.min(speed, 100) * 0.0245))
                    : 0;
                rotor.style.setProperty('transform-box', 'view-box', 'important');
                rotor.style.setProperty('transform-origin', '0px 0px', 'important');
                rotor.style.setProperty(
                    'animation',
                    duration > 0 ? 'symcon-modern-rotate ' + duration.toFixed(2) + 's linear infinite' : 'none'
                );
            });

            // Warmwasser-Zirkulationspumpe.
            [
                ['#circulatingPumpRotor', cfg.circulatingPumpRunning]
            ].forEach(([selector, key]) => {
                const rotor = svg.querySelector(selector);
                if (!rotor) return;
                const running = !!key && binary(key);
                rotor.style.setProperty('transform-box', 'view-box', 'important');
                rotor.style.setProperty('transform-origin', '0px 0px', 'important');
                rotor.style.setProperty(
                    'animation',
                    running ? 'symcon-modern-rotate 1.4s linear infinite' : 'none',
                    'important'
                );
            });

            // Use raw rpm, never a formatted profile string, for the schematic speed.
            const compressorRaw = raw(cfg.compressorValue);
            const compressorSpeed = typeof compressorRaw === 'number' ? compressorRaw
                : typeof compressorRaw === 'string' && compressorRaw.trim() !== ''
                    ? Number(compressorRaw.replace(',', '.')) : NaN;
            const hasCompressorSpeed = Number.isFinite(compressorSpeed);
            // Measured actual speed/frequency wins over the relay and setpoint.
            // Without a usable actual reading, retain the legacy relay fallback.
            const compressorActive = hasCompressorSpeed
                ? compressorSpeed > 0
                : binary(cfg.compressorRunning);
            // Controller durations are already converted; never count from browser startup.
            const relayValue = raw(cfg.compressorRunning);
            const compressorStateKnown = hasCompressorSpeed || (relayValue !== null && relayValue !== undefined && relayValue !== '');
            const elapsed = formatted(compressorActive ? cfg.heatPumpRuntime : cfg.compressorStandstill);
            const cycleReadings = group('gHeatPumpCycle', svg.querySelector('#heatPump'));
            const cycleText = compressorStateKnown ? (compressorActive ? 'Läuft seit ' : 'Steht seit ') + (elapsed || '—') : 'Lauf-/Standzeit —';
            fittedText(cycleReadings, {id:'textHeatPumpCycle',class:'sm',x:142.5,y:863,'text-anchor':'middle','dominant-baseline':'text-after-edge',style:'font-size:18px'},cycleText,175);
            const defrostTime = formatted(cfg.timeUntilDefrost);
            if (defrostTime) fittedText(cycleReadings,{id:'textTimeUntilDefrost',class:'sm',x:142.5,y:895,'text-anchor':'middle','dominant-baseline':'text-after-edge',style:'font-size:18px'},'Abtauen in ' + defrostTime,175);
            node(cycleReadings,'title',{},compressorStateKnown
                ? 'Reglerwert: ' + (compressorActive ? 'Waermepumpe_laeuft_seit (ID 67)' : 'Verdichter_Standzeit (ID 73)')
                : 'Verdichterzustand nicht verfügbar');
            const compressor = svg.querySelector('#pathCompressor');
            if (compressor) {
                // Frequency and rpm use different schematic scales. The displayed
                // value/profile is unchanged; no conversion to physical rpm is inferred.
                const referenceSpeed = /Hz/i.test(data[cfg.compressorValue]?.unit || data[cfg.compressorValue]?.formatted || '') ? 100 : 3000;
                const duration = compressorActive
                    ? (hasCompressorSpeed ? Math.max(0.6, Math.min(3.0, referenceSpeed / compressorSpeed)) : 1.4)
                    : 0;
                compressor.style.setProperty('transform-box', 'fill-box', 'important');
                compressor.style.setProperty('transform-origin', 'center', 'important');
                // Allow the SVG's reduced-motion rule to override this animation.
                compressor.style.setProperty(
                    'animation',
                    duration > 0 ? 'symcon-modern-rotate ' + duration.toFixed(2) + 's linear infinite' : 'none'
                );
            }

            svg.setAttribute('viewBox','15 0 ' + contentRight + ' 925');

            // Live flow overlays use actual running variables; no idle animation.
            const heatingActive = pumpStates.some((state,index) => [hk1,hk2,hk3][index] && state.active);
            const chargingActive = binary(cfg.storageChargingPumpRunning);
            const measuredFlow = measuredNumber(cfg.heatingFlow);
            const hasMeasuredFlow = measuredFlow !== null && measuredFlow >= 0;
            const primaryPump = pumpState(cfg.heatingCircuitPumpRunning, cfg.heatingCircuitPumpValue);
            const primaryRelay = raw(cfg.heatingCircuitPumpRunning);
            const hasPrimaryPump = primaryPump.percent !== null || (primaryRelay !== null && primaryRelay !== undefined && primaryRelay !== '');
            const primaryWaterActive = hasMeasuredFlow ? measuredFlow > 0 : hasPrimaryPump ? primaryPump.active : compressorActive;
            // Visual scale only: 600 l/h or 50% uses 2.8 s; never derive a displayed l/h value.
            const flowDuration = (value, reference) => value > 0 ? Math.max(0.6, Math.min(8, 2.8 * reference / value)) : 2.8;
            const primaryFlowDuration = hasMeasuredFlow ? flowDuration(measuredFlow, 600)
                : primaryPump.percent !== null ? flowDuration(primaryPump.percent, 50) : 2.8;
            const circuitFlowDurations = pumpStates.map(state => state.percent !== null ? flowDuration(state.percent, 50) : 2.8);
            const activeCircuitDurations = circuitFlowDurations.filter((_,index) => [hk1,hk2,hk3][index] && pumpStates[index].active);
            const headerFlowDuration = activeCircuitDurations.length ? Math.min(...activeCircuitDurations) : 2.8;
            const solarActive = Number(raw(cfg.thermalSolarPumpSpeed)) > 0 || binary(cfg.thermalSolarPump);
            const solarRotor = svg.querySelector('#solarPumpRotor');
            if (solarRotor) solarRotor.style.setProperty('animation', solarActive ? 'symcon-modern-rotate 1.4s linear infinite' : 'none', 'important');
            const flows = [
                ['#gRefrigerantCircuit path.hot, #gRefrigerantCircuit path.cold', compressorActive],
                ['#main path.hot, #main path.cold', primaryWaterActive, primaryFlowDuration],
                ['#gBuffer > path.hot, #gBuffer > path.cold', heatingActive, headerFlowDuration],
                ['#gNoBuffer path.hot, #gNoBuffer path.cold', heatingActive, headerFlowDuration],
                ['#gHeatingHeaders path.hot, #gHeatingHeaders path.cold', heatingActive, headerFlowDuration],
                ['#gHK1 > path.hot, #gHK1 > path.cold, #pathHKMixingReturn1', pumpStates[0].active, circuitFlowDurations[0]],
                ['#gHK2 > path.hot, #gHK2 > path.cold, #pathHKMixingReturn2', pumpStates[1].active, circuitFlowDurations[1]],
                ['#gHK3 > path.hot, #gHK3 > path.cold, #pathHKMixingReturn3', pumpStates[2].active, circuitFlowDurations[2]],
                ['#gDHW > path.hot, #gDHW > path.cold', chargingActive && binary(cfg.wwHeatingValve), primaryFlowDuration],
                ['#gSolar path.sol', solarActive]
            ];
            flows.forEach(([selector, active, duration = 2.8]) => {
                svg.querySelectorAll(selector).forEach((path) => {
                    if (!active) {
                        if (path.__wpLiveFlow) path.__wpLiveFlow.remove();
                        path.__wpLiveFlow = null;
                        return;
                    }
                    const overlay = path.__wpLiveFlow
                        || document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    overlay.setAttribute('d', path.getAttribute('d') || '');
                    overlay.setAttribute('class', 'wp-live-flow');
                    overlay.style.setProperty('--wp-flow-duration', duration.toFixed(2) + 's');
                    if (path.hasAttribute('transform')) overlay.setAttribute('transform', path.getAttribute('transform'));
                    if (!overlay.isConnected) path.after(overlay);
                    path.__wpLiveFlow = overlay;
                });
            });

            // Heizstab-Anzeige im modernen Renderer ebenfalls aktualisieren.
            // Bisher wurde diese Funktion nur im klassischen Renderer aufgerufen,
            // weil applyModernDashboard() vorher mit true zurückkehrt.
            // Heater power is aggregated in the backend and shown only inside DHW.

            // Keep every schematic label at least as large as the refrigerant labels.
            const minimumFont = parseFloat(getComputedStyle(svg.querySelector('#textEvaporatorPressure')).fontSize) || 18;
            svg.querySelectorAll('text,tspan').forEach(el => {
                if (parseFloat(getComputedStyle(el).fontSize) < minimumFont) el.style.setProperty('font-size',minimumFont + 'px','important');
            });

            return true;
        };

        /*
         * Einheitliches Schließverhalten für ALLE Popups.
         *
         * Capture-Modus ist hier wichtig: Einige SVG-Elemente der Card
         * stoppen ihre Klick-Events selbst. Mit pointerdown + capture wird
         * ein Klick außerhalb des Menüs trotzdem sicher erkannt.
         */
        document.addEventListener(
            'pointerdown',
            (event) => {
                const menu = document.getElementById('wp-mode-menu');

                if (
                    menu
                    && menu.style.display === 'block'
                    && !menu.contains(event.target)
                ) {
                    closeModeMenu();
                }
            },
            true
        );

        /*
         * Zusätzlich lassen sich alle Menüs mit Escape schließen.
         */
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModeMenu();
            }
        });

        const applyCardData = () => {
            const card = document.getElementById('wp-card');

            if (!card) {
                showError('Das Element <heat-pump-card> wurde im HTML nicht gefunden.');
                return;
            }

            try {
                clearError();

                if (!card.content) {
                    card.mount(embeddedSvg, embeddedLocalization);
                }

                /*
                 * Alle Symcon-Erweiterungen direkt nach dem nativen
                 * Daten-Rendering ausführen. Das gebündelte SVG verwendet ausschließlich die integrierte Vollansicht.
                 */
                if (!card.__symconSetDataHookInstalled && typeof card.setData === 'function') {
                    const originalSetData = card.setData;

                    card.setData = function(data) {
                        const result = originalSetData.call(this, data);

                        if (this.content) {
                            applyModernDashboard(this);
                            applyThemeColors(this);
                        }

                        return result;
                    };

                    card.__symconSetDataHookInstalled = true;
                }

                card.setConfig(currentConfig);
                card.setData(currentData);
                applyModernDashboard(card);
                if (currentConfig.circuitWarning) showError(currentConfig.circuitWarning);
            } catch (error) {
                showError(
                    'Fehler beim Übergeben der Symcon-Daten an die Card:\\n'
                    + (error && error.stack ? error.stack : String(error))
                );
            }
        };

        /*
         * HTML-SDK: Nachrichten vom PHP-Modul.
         * GetVisualizationTile() liefert den kompletten Initialzustand.
         * Danach aktualisiert UpdateVisualizationValue() nur noch die Daten.
         */
        window.handleMessage = (message) => {
            let data = message;

            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (error) {
                    showError('Ungültige HTML-SDK-Nachricht: ' + String(error));
                    return;
                }
            }

            if (!data || typeof data !== 'object') {
                return;
            }

            if (data.command === 'reloadHtml') {
                window.location.reload();
                return;
            }

            if (data.config) {
                const previousDeviceURL = getLuxtronikWebURL();
                currentConfig = data.config;
                // A switch to SHI/read-only or another device revokes an open panel too.
                if (previousDeviceURL !== getLuxtronikWebURL()) closeLuxtronikWebInterface();
            }

            if (data.data) {
                currentData = data.data;
            }

            if (data.controls) {
                currentControls = data.controls;
            }

            if (data.type === 'rebuild') {
                rebuildCard();
                return;
            }

            applyCardData();
        };

        if (window.matchMedia) {
            const scheme = window.matchMedia('(prefers-color-scheme: dark)');
            const onSchemeChange = () => {
                rebuildCard();
            };

            if (typeof scheme.addEventListener === 'function') {
                scheme.addEventListener('change', onSchemeChange);
            } else if (typeof scheme.addListener === 'function') {
                scheme.addListener(onSchemeChange);
            }
        }

        applyCardData();
  }
};
SYMC0N_HEATPUMP_JAVASCRIPT;
    }

    private function GetVisualizationIdents(string $property): array
    {
        if ($this->ReadPropertyString('CommunicationMode') === 'modbus' && $property === 'MixerPump1') return ['SHI_Pumpe_Mischkreis_1'];
        if (preg_match('/^(SupplyTemperatureHeating|RefluxTemperatureHeating|HeatingCircuitPumpRunning|HeatingCircuitPumpValue)([1-3])$/D', $property, $match)
            && !in_array($property, ['HeatingCircuitPumpRunning1','HeatingCircuitPumpValue1'], true)
            && !$this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $match[2])) return [];
        if (preg_match('/^SupplyTemperatureHeating([1-3])$/D', $property, $match)) {
            $circuit = (int)$match[1];
            $mixer = $this->ReadPropertyInteger('VisualizationHeatingCircuitMixer' . $circuit);
            return self::VISUALIZATION_EXTRA_IDENT_MAP['MixerActual' . ($mixer > 0 ? $mixer : $circuit)];
        }

        return self::VISUALIZATION_EXTRA_IDENT_MAP[$property] ?? self::LUXTRONIK_IDENT_MAP[$property] ?? [];
    }

    private function GetVisualizationVariableID(string $property): int
    {
        if ($property === 'InputPower') {
            $id = $this->ReadPropertyInteger('kwin');
            if ($id > 0 && IPS_VariableExists($id)) return $id;
        }
        if (preg_match('/^(SupplyTemperatureHeating|RefluxTemperatureHeating|HeatingCircuitPumpRunning|HeatingCircuitPumpValue)([1-3])$/D', $property, $match)
            && !in_array($property, ['HeatingCircuitPumpRunning1','HeatingCircuitPumpValue1'], true)
            && !$this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $match[2])) return 0;
        $idents = $this->GetVisualizationIdents($property);
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && IPS_VariableExists($id)) {
                return $id;
            }
        }
        if ($property === 'InputPower') return 0;
        if (isset(self::VISUALIZATION_EXTRA_IDENT_MAP[$property])
            && !in_array($property, ['ThermalSolarPump','HeatPumpReturnTemperature','SupplyTemperatureHeating2','SupplyTemperatureHeating3'], true)) return 0;
        $configured = $this->ReadPropertyInteger($property);
        if ($configured <= 0 || !IPS_VariableExists($configured)) return 0;
        // A legacy binding to the common WP sensor is no longer a circuit measurement.
        if (str_starts_with($property, 'SupplyTemperatureHeating')
            && (IPS_GetObject($configured)['ObjectIdent'] ?? '') === 'Vorlauftemperatur_Heizkreis') return 0;
        return $configured;
    }

    private function GetLuxtronikWebInterfaceURL(): string
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus') || $this->ReadPropertyString('CommunicationMode') === 'modbus') {
            return '';
        }
        $url = trim($this->ReadPropertyString('LuxtronikWebInterfaceURL'));
        if ($url === '') {
            $address = trim($this->ReadPropertyString('IPAddress'));
            if ($address === '' || in_array($address, ['0.0.0.0', '::'], true)) {
                return '';
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $address = '[' . $address . ']';
            } elseif (!filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return '';
            }
            $url = 'http://' . $address . '/Webserver/index.html';
        }
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return '';
        }
        return $url;
    }

    private function VisualizationControlAvailable(string $property, int $id): bool
    {
        if (!$this->ReadPropertyBoolean('InstanceStatus')) return false;
        $controls = [
            'HeatingControlVariable' => ['Mode_Heizung', 'HeizungVisible'],
            'HotWaterControlVariable' => ['Mode_WW', 'WarmwasserVisible'],
            'CoolingControlVariable' => ['Mode_Kuehlung', 'KuehlungVisible'],
            'PoolControlVariable' => ['Mode_Schwimmbad', 'SchwimmbadVisible'],
            'WarmWaterSetpointVariable' => ['Anpassung_WW', 'WWsetVisible'],
            'HeatingTemperatureCorrectionVariable' => ['Anpassung_Temp', 'TempsetVisible'],
            'RoomTemperatureSetpointVariable' => ['Anpassung_RBE', 'RBEsetVisible']
        ];
        if (!isset($controls[$property])) return false;
        [$ident, $visible] = $controls[$property];
        $shared = in_array($ident, ['Anpassung_WW', 'Anpassung_Temp', 'Anpassung_RBE'], true);
        if (!($shared ? $this->SharedNumericControlEnabled($ident) : $this->ReadPropertyBoolean($visible))) return false;
        if ($this->ReadPropertyString('CommunicationMode') === 'modbus') {
            if (!$shared || $this->GetSHIVisualizationPoint($ident) === null) return false;
        }
        if (@$this->GetIDForIdent($ident) !== $id) return false;
        $variable = IPS_GetVariable($id);
        return in_array((int)$variable['VariableType'], [1, 2], true)
            && (int)($variable['VariableCustomAction'] ?: $variable['VariableAction']) > 0;
    }

    private function VisualizationPresentation(int $id): array
    {
        return function_exists('IPS_GetVariablePresentation') ? IPS_GetVariablePresentation($id) : [];
    }

    /** Supports modern OPTIONS and legacy profile associations for every scalar type. */
    private function VisualizationAssociations(int $id): array
    {
        $type = (int)IPS_GetVariable($id)['VariableType'];
        $presentation = $this->VisualizationPresentation($id);
        $rows = [];
        if (isset($presentation['OPTIONS'])) {
            $rows = is_string($presentation['OPTIONS']) ? json_decode($presentation['OPTIONS'], true) : $presentation['OPTIONS'];
            $rows = is_array($rows) ? $rows : [];
        } else {
            $name = $this->GetVariableProfileName($id);
            if ($name !== '' && IPS_VariableProfileExists($name)) $rows = IPS_GetVariableProfile($name)['Associations'] ?? [];
        }
        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('Value', $row)) continue;
            $value = $row['Value'];
            $valid = match ($type) {
                0 => is_bool($value) || (is_numeric($value) && in_array((float)$value, [0.0, 1.0], true)),
                1 => is_int($value) || (is_numeric($value) && is_finite((float)$value) && floor((float)$value) === (float)$value
                    && (float)$value > PHP_INT_MIN && (float)$value < PHP_INT_MAX),
                2 => !is_bool($value) && is_numeric($value) && is_finite((float)$value),
                3 => is_string($value) || is_numeric($value), default => false
            };
            if (!$valid) continue;
            $options[] = ['value' => $this->CastValueForVariableType($value, $type),
                'name' => (string)($row['Caption'] ?? $row['Name'] ?? $value),
                'icon' => (string)(array_key_exists('IconValue', $row) ? (!empty($row['IconActive']) ? $row['IconValue'] : '') : ($row['Icon'] ?? ''))];
        }
        if ($options === [] && $type === VARIABLETYPE_BOOLEAN && $rows === []) {
            $options = [['value'=>false,'name'=>'Aus'], ['value'=>true,'name'=>'Ein']];
        }
        return $options;
    }

    private function HeaterAssociationToken(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function HeaterConfigurationOptions(int $id): array
    {
        $options = [['caption'=>'Nicht festgelegt (alle Profilwerte)', 'value'=>'']];
        if ($id <= 0 || !IPS_VariableExists($id)) return $options;
        foreach ($this->VisualizationAssociations($id) as $option) {
            $token = $this->HeaterAssociationToken($option['value']);
            $options[] = ['caption'=>$option['name'] . ' (' . $token . ')', 'value'=>$token];
        }
        return $options;
    }

    private function HeaterConfigurationFields(int $number): array
    {
        $id = $this->ReadPropertyInteger('VisualizationHeaterSwitch' . $number);
        $options = $this->HeaterConfigurationOptions($id);
        $switch = $this->VariableSelect('Schaltvariable', 'VisualizationHeaterSwitch' . $number);
        $switch['onChange'] = 'WPLUX_UpdateHeaterConfiguration(' . $this->InstanceID . ', ' . $number . ', $VisualizationHeaterSwitch' . $number . ');';
        return ['type'=>'ExpansionPanel','caption'=>'Heizstab ' . $number,'items'=>[
            ['type'=>'RowLayout','items'=>[$this->VariableSelect('Ist-Leistung (W/kW)', 'HeaterRod' . $number),$switch]],
            ['type'=>'RowLayout','items'=>[
                ['type'=>'Select','name'=>'VisualizationHeaterOnValue' . $number,'caption'=>'Ein-Wert','options'=>$options],
                ['type'=>'Select','name'=>'VisualizationHeaterOffValue' . $number,'caption'=>'Aus-Wert','options'=>$options]
            ]]
        ]];
    }

    /** Refreshes choices for an unsaved variable selection; never actuates a device. */
    public function UpdateHeaterConfiguration(int $number, int $variableID): void
    {
        if ($number < 1 || $number > 3) throw new InvalidArgumentException('Unbekannter Heizstab.');
        $options = json_encode($this->HeaterConfigurationOptions($variableID), JSON_THROW_ON_ERROR);
        foreach (['On','Off'] as $role) {
            $name = 'VisualizationHeater' . $role . 'Value' . $number;
            $this->UpdateFormField($name, 'options', $options);
            $this->UpdateFormField($name, 'value', '');
        }
    }

    private function BuildVisualizationHeaters(): array
    {
        $heaters = [];
        for ($i = 1; $i <= 3; ++$i) {
            $switchID = $this->ReadPropertyInteger('VisualizationHeaterSwitch' . $i);
            $powerID = $this->ReadPropertyInteger('HeaterRod' . $i);
            if ($switchID <= 0 && $powerID <= 0) continue;
            $exists = $switchID > 0 && IPS_VariableExists($switchID);
            $variable = $exists ? IPS_GetVariable($switchID) : [];
            $type = $exists ? (int)$variable['VariableType'] : -1;
            $allOptions = $exists ? $this->VisualizationAssociations($switchID) : [];
            $options = $allOptions;
            $on = $this->ReadPropertyString('VisualizationHeaterOnValue' . $i);
            $off = $this->ReadPropertyString('VisualizationHeaterOffValue' . $i);
            $mappingValid = true;
            $current = $exists ? GetValue($switchID) : null;
            $label = $exists ? GetValueFormatted($switchID) : '';
            foreach ($allOptions as $option) if ($current === $option['value']) { $label = $option['name']; break; }
            $enabled = $type === VARIABLETYPE_BOOLEAN ? (bool)$current : null;
            if ($on !== '' || $off !== '') {
                $options = [];
                $enabled = null;
                foreach (['off'=>$off,'on'=>$on] as $role=>$token) {
                    if ($token === '') continue;
                    $found = false;
                    foreach ($allOptions as $option) {
                        if ($this->HeaterAssociationToken($option['value']) !== $token) continue;
                        $options[] = $option + ['role'=>$role];
                        if ($current === $option['value']) $enabled = $role === 'on';
                        $found = true;
                        break;
                    }
                    if (!$found) $mappingValid = false;
                }
                if ($on !== '' && $on === $off) $mappingValid = false;
            }
            $action = (int)($variable['VariableCustomAction'] ?? 0) ?: (int)($variable['VariableAction'] ?? 0);
            $power = $this->VisualizationHeaterPower($i);
            $heaters[] = ['number' => $i, 'label' => 'Heizstab ' . $i, 'variableId' => $exists ? $switchID : 0,
                'configured' => $mappingValid && $options !== [] && $action > 1 && $action !== $this->InstanceID && IPS_GetParent($switchID) !== $this->InstanceID && $this->ReadPropertyBoolean('InstanceStatus'),
                'currentValue' => $current, 'enabled' => $enabled, 'stateLabel' => $label, 'options' => $options, 'type' => $type, 'boolean' => $type === VARIABLETYPE_BOOLEAN,
                'powerWatts' => $power['watts'] ?? null];
        }
        return $heaters;
    }

    private function HandleHeaterSwitchAction(mixed $value): void
    {
        $payload = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (!is_array($payload) || !isset($payload['number']) || !is_int($payload['number'])
            || $payload['number'] < 1 || $payload['number'] > 3 || !isset($payload['value'])) {
            throw new InvalidArgumentException('Ungültiger Heizstab-Schaltauftrag.');
        }
        foreach ($this->BuildVisualizationHeaters() as $heater) {
            if ($heater['number'] !== $payload['number']) continue;
            if (!$heater['configured']) throw new RuntimeException('Keine aktive externe Schaltaktion mit Assoziationen für diesen Heizstab.');
            $raw = $payload['value'];
            $validType = match ($heater['type']) {
                0 => is_bool($raw), 1 => is_int($raw),
                2 => (is_int($raw) || is_float($raw)) && is_finite((float)$raw), 3 => is_string($raw), default => false
            };
            $newValue = $validType ? $this->CastValueForVariableType($raw, $heater['type']) : null;
            if (!$validType || !in_array($newValue, array_column($heater['options'], 'value'), true)) {
                throw new InvalidArgumentException('Der Wert ist keine gültige Assoziation dieses Heizstabs.');
            }
            // Only the configured target is resolved server-side. Never set the variable directly.
            if (!\RequestAction($heater['variableId'], $newValue)) {
                throw new RuntimeException('Die Heizstab-Schaltaktion wurde nicht ausgeführt.');
            }
            $this->RefreshVisualization();
            return;
        }
        throw new RuntimeException('Dieser Heizstab ist nicht konfiguriert.');
    }

    private function HandleVisualizationAction(string $action, mixed $value): void
    {
        $payload = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Ungültige Grafik-Steuerungsdaten.');
        }
        $modes = ['heating' => ['HeatingControlVariable', 'Mode_Heizung'],
            'hotwater' => ['HotWaterControlVariable', 'Mode_WW'], 'cooling' => ['CoolingControlVariable', 'Mode_Kuehlung'],
            'pool' => ['PoolControlVariable', 'Mode_Schwimmbad']];
        $numeric = ['warmWaterSetpoint' => ['WarmWaterSetpointVariable', 'Anpassung_WW'],
            'heatingTemperatureCorrection' => ['HeatingTemperatureCorrectionVariable', 'Anpassung_Temp'],
            'roomTemperatureSetpoint' => ['RoomTemperatureSetpointVariable', 'Anpassung_RBE']];
        $function = $payload['function'] ?? '';
        if (!is_string($function)) {
            throw new InvalidArgumentException('Ungültige Grafik-Steuerfunktion.');
        }
        $target = ($action === 'SetControlMode' ? $modes : ($action === 'SetNumericControl' ? $numeric : []))[$function] ?? null;
        if ($target === null) {
            throw new InvalidArgumentException('Unbekannte oder nicht unterstützte Grafik-Steuerfunktion.');
        }
        [$property, $ident] = $target;
        $id = $this->GetVisualizationVariableID($property);
        if ($id === 0 || !$this->VisualizationControlAvailable($property, $id)) {
            throw new RuntimeException('Für diese Funktion ist keine aktive Luxtronik-Steueraktion verfügbar.');
        }
        $newValue = $payload['value'] ?? null;
        if ((!is_int($newValue) && !is_float($newValue) && !is_string($newValue))
            || !is_numeric($newValue) || !is_finite((float)$newValue)) {
            throw new InvalidArgumentException('Der Steuerwert muss eine endliche Zahl sein.');
        }
        if ($action === 'SetControlMode') {
            $allowed = $this->GetProfileAssociationValues($id);
            if (floor((float)$newValue) !== (float)$newValue || ($allowed !== [] && !$this->ValueInList($newValue, $allowed))) {
                throw new InvalidArgumentException('Ungültige Betriebsart.');
            }
        } else {
            if ((int)IPS_GetVariable($id)['VariableType'] === 1 && floor((float)$newValue) !== (float)$newValue) {
                throw new InvalidArgumentException('Der Sollwert passt nicht zum ganzzahligen Variablentyp.');
            }
            $control = $this->GetNumericControlInfo($function);
            if ((float)$newValue < $control['min'] || (float)$newValue > $control['max']) {
                throw new InvalidArgumentException('Der Sollwert liegt außerhalb des zulässigen Bereichs.');
            }
        }
        // Native validation, write and read-back remain exclusively in Luxtronik RequestAction.
        $this->RequestAction($ident, $this->CastValueForVariableType($newValue, (int)IPS_GetVariable($id)['VariableType']));
    }

    private function RegisterVariableMessages(): void
    {
        $ids = [];
        $collect = static function (mixed $value) use (&$collect, &$ids): void {
            if (!is_array($value)) return;
            if (isset($value['variableId']) && $value['variableId'] > 0 && IPS_VariableExists((int)$value['variableId'])) {
                $ids[] = (int)$value['variableId'];
            }
            foreach ($value as $child) if (is_array($child)) $collect($child);
        };
        $collect($this->BuildVisualizationData());
        $collect($this->BuildControlData());
        // Keep watching action availability even while an existing control is disabled.
        foreach (array_keys(self::LUXTRONIK_IDENT_MAP + self::VISUALIZATION_EXTRA_IDENT_MAP) as $property) {
            $id = $this->GetVisualizationVariableID($property);
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        $old = json_decode($this->GetBuffer('VisualizationRegisteredVariables'), true);
        $old = is_array($old) ? $old : [];
        foreach (array_diff($old, $ids) as $id) {
            foreach ([VM_UPDATE, VM_DELETE, VM_CHANGEPROFILEACTION] as $message) $this->UnregisterMessage($id, $message);
        }
        foreach (array_diff($ids, $old) as $id) {
            foreach ([VM_UPDATE, VM_DELETE, VM_CHANGEPROFILEACTION] as $message) $this->RegisterMessage($id, $message);
        }
        foreach ([OM_CHILDADDED, OM_CHILDREMOVED, IM_CHANGESTATUS] as $message) $this->RegisterMessage($this->InstanceID, $message);
        $this->SetBuffer('VisualizationRegisteredVariables', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        $own = $SenderID === $this->InstanceID && in_array($Message, [OM_CHILDADDED, OM_CHILDREMOVED, IM_CHANGESTATUS], true);
        $ids = json_decode($this->GetBuffer('VisualizationRegisteredVariables'), true);
        if ($own || (is_array($ids) && in_array($SenderID, $ids, true) && in_array($Message, [VM_UPDATE, VM_DELETE, VM_CHANGEPROFILEACTION], true))) {
            $this->RefreshVisualization($own || $Message === VM_DELETE);
        }
    }

    private function RefreshVisualization(bool $rebuild = false): void
    {
        try {
            $this->RegisterVariableMessages();
            if (IPS_GetKernelRunlevel() === KR_READY) {
                if ($rebuild) {
                    $resource = $this->GetResourceFiles();
                    $renderer = hash_file('sha256', __FILE__) . hash_file('sha256', $resource['svg']);
                    if ($this->GetBuffer('VisualizationRenderer') !== $renderer) {
                        $this->SetBuffer('VisualizationRenderer', $renderer);
                        // A data rebuild cannot replace JavaScript already loaded in a browser.
                        $this->UpdateVisualizationValue(json_encode(['command'=>'reloadHtml'], JSON_THROW_ON_ERROR));
                    }
                }
                $this->UpdateVisualizationValue(json_encode([
                    'type' => $rebuild ? 'rebuild' : 'update', 'config' => $this->BuildCardConfig(),
                    'data' => $this->BuildVisualizationData(), 'controls' => $this->BuildControlData()
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (Throwable $error) {
            $this->LogMessage('Visualisierung: ' . $error->getMessage(), KL_ERROR);
        }
    }

    private function AddVisualizationForm(array $form): array
    {
        $items = [
            ['type' => 'Label', 'caption' => 'Komponenten auswählen; die Grafik erhält ihre internen Messwerte automatisch, unabhängig von der ID-Liste.'],
            $this->VariableSelect('Wettercode (OpenWeather, z. B. 10d; optional)', 'VisualizationWeatherCode'),
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'TankHP', 'caption' => 'Pufferspeicher'],
                ['type' => 'CheckBox', 'name' => 'TankWW', 'caption' => 'Warmwasserspeicher'],
                ['type' => 'CheckBox', 'name' => 'VisualizationCirculation', 'caption' => 'Warmwasser-Zirkulation']
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'ThermalSolarAvailable', 'caption' => 'Solarthermie'],
                ['type' => 'Select', 'name' => 'VisualizationSolarTarget', 'caption' => 'Solarwärme an',
                    'options' => [['caption'=>'Warmwasserspeicher','value'=>'hotwater'],['caption'=>'Pufferspeicher (Heizunterstützung)','value'=>'buffer']]]
            ]],
            ['type' => 'Label', 'caption' => 'Ohne Pufferspeicher wird die Solarwärme am Warmwasserspeicher dargestellt.',
                'visible' => $this->ReadPropertyBoolean('ThermalSolarAvailable') && $this->ReadPropertyString('VisualizationSolarTarget') === 'buffer' && !$this->ReadPropertyBoolean('TankHP')],
            ...array_map(static fn(int $i): array => ['type'=>'RowLayout','items'=>[
                ['type'=>'CheckBox','name'=>'VisualizationHeatingCircuit' . $i,'caption'=>'Heizkreis ' . $i],
                ['type'=>'ValidationTextBox','name'=>'HeatingCircuitName' . $i,'caption'=>'Bezeichnung'],
                ['type'=>'Select','name'=>'VisualizationHeatingCircuitMixer' . $i,'caption'=>'Anbindung',
                    'options'=>[['caption'=>'Direkt','value'=>0],['caption'=>'Mischkreis 1','value'=>1],
                        ['caption'=>'Mischkreis 2','value'=>2],['caption'=>'Mischkreis 3','value'=>3]]]
            ]],range(1,3)),
            ['type' => 'ExpansionPanel', 'caption' => 'Heizstäbe', 'items' => [
                ['type' => 'Label', 'caption' => 'Ist-Leistungsvariablen werden mit ihrer Einheit W/kW summiert. Schaltvariablen und deren Assoziationen dienen ausschließlich der Bedienung.'],
                ...array_map(fn(int $i): array => $this->HeaterConfigurationFields($i), range(1, 3))
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Innentemperaturen', 'items' => $this->VisualizationRoomFields()]
        ];
        $index = count($form['elements']);
        foreach ($form['elements'] as $position => $element) {
            if (($element['name'] ?? '') === 'IDListe') { $index = $position; break; }
        }
        array_splice($form['elements'], $index, 0, [['type' => 'ExpansionPanel', 'caption' => 'HTML-SDK / Visualisierung', 'items' => $items]]);
        return $form;
    }

    private function MigrateVisualizationSettings(array &$data): bool
    {
        if (!isset($data['configuration']) || !is_array($data['configuration'])) return false;
        $config = &$data['configuration'];
        $changed = false;
        if (array_key_exists('VisualizationHeatingCircuit1', $config)
            || !array_key_exists('VisualizationDirectCircuit', $config)) return $changed;
        // Retain the number of displayed places from 4.9/Build 1. Old mixer flags
        // remain stored; mixer telemetry is now discovered automatically.
        $count = !empty($config['VisualizationDirectCircuit']) ? 1 : 0;
        for ($i = 1; $i <= 3; ++$i) $count += !empty($config['VisualizationMixedCircuit' . $i]) ? 1 : 0;
        for ($i = 1; $i <= 3; ++$i) $config['VisualizationHeatingCircuit' . $i] = $i <= min(3, $count);
        return true;
    }

    private function VisualizationSourceKey(): string
    {
        $modbus = $this->ReadPropertyString('CommunicationMode') === 'modbus';
        $key=[$this->ReadPropertyString('CommunicationMode'), $this->ReadPropertyString('IPAddress'),
            $this->ReadPropertyInteger($modbus ? 'ModbusPort' : 'Port'), $modbus ? $this->ReadPropertyInteger('ModbusUnitID') : 0];
        if ($modbus) array_push($key,$this->ReadPropertyInteger('Port'),$this->ReadPropertyBoolean('ModbusJavaSupplement'));
        return json_encode($key, JSON_THROW_ON_ERROR);
    }

    private function VisualizationCachedPoints(): array
    {
        $cache = json_decode($this->GetBuffer('VisualizationPointCache'), true);
        return is_array($cache) && ($cache['source'] ?? '') === $this->VisualizationSourceKey() ? ($cache['points'] ?? []) : [];
    }

    /** Receives engineering values, never raw protocol words; does not create objects. */
    private function StoreVisualizationPoints(array $values, bool $merge = false): void
    {
        $previous = $this->VisualizationCachedPoints();
        $points = $merge ? $previous : [];
        foreach ($values as $id => $value) {
            $point = $this->GetDataPointConfig((int)$id);
            $value = match ($point['type']) {
                'bool' => $this->NormalizeBinaryValue($value), 'int' => (int)$value,
                'float' => round((float)$value, (int)$point['decimals']), default => (string)$value
            };
            $old = $previous[$point['name']] ?? null;
            // First observation is not a change. Retain the time while a command stays active.
            $changedAt = $old !== null && $old['value'] !== $value ? microtime(true) : ($old['changedAt'] ?? 0);
            $points[$point['name']] = ['value' => $value, 'id' => $id, 'changedAt' => $changedAt];
        }
        $this->SetBuffer('VisualizationPointCache', json_encode(['source' => $this->VisualizationSourceKey(), 'points' => $points],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** A current converted Java reading; never falls back to an object or another Ident. */
    private function GetCachedVisualizationPoint(string $ident): ?array
    {
        $availability=json_decode($this->GetBuffer('JavaAvailability'),true)??[];
        if(($availability['source']??'')===$this->VisualizationSourceKey() && !empty($availability['failed'])) return null;
        $points = $this->VisualizationCachedPoints();
        if (!isset($points[$ident])) return null;
        $entry = $points[$ident];
        $definition = $this->GetDataPointConfig($entry['id']);
        $profileName = $definition['profile'];
        $profile = $profileName !== '' && IPS_VariableProfileExists($profileName) ? IPS_GetVariableProfile($profileName) : [];
        $unit = trim((string)($profile['Suffix'] ?? match ($profileName) {
            '~Temperature' => ' °C', '~Watt' => ' W', 'WPLUX.kW' => ' kW', 'WPLUX.lh' => ' l/h', 'WPLUX.Ver' => ' rpm', default => ''
        }));
        $value = $entry['value'];
        $formatted = is_bool($value) ? ($value ? 'Ein' : 'Aus') : (string)$value;
        if (is_numeric($value) && $definition['type'] !== 'string') {
            $digits = (int)($profile['Digits'] ?? ($definition['type'] === 'float' ? $definition['decimals'] : 0));
            $formatted = (string)($profile['Prefix'] ?? '') . number_format((float)$value, max(0, min(10, $digits)), '.', '') . ($unit !== '' ? ' ' . $unit : '');
        }
        foreach ($profile['Associations'] ?? [] as $association) {
            if ($this->ValueInList($value, [$association['Value']])) { $formatted = (string)$association['Name']; break; }
        }
        $id = @$this->GetIDForIdent($ident);
        return ['variableId' => $id !== false && IPS_VariableExists($id) ? $id : 0,
            'ident' => $ident, 'value' => $value, 'formatted' => $formatted, 'unit' => $unit, 'binary' => false,
            'changedAt' => $entry['changedAt'] ?? 0, 'source' => 'Java', 'protocolId' => $entry['id']];
    }

    private function GetVisualizationPointData(string $property): ?array
    {
        if (in_array($property,['EEVHeatingOpening','EEVCoolingOpening'],true)
            && !in_array($this->ReadPropertyString('IPAddress'),['','0.0.0.0','::'],true)) {
            return $this->GetWebEEVPoint($property==='EEVHeatingOpening'?'EEV_Heizen':'EEV_Kuehlen');
        }
        if($this->ReadPropertyString('CommunicationMode')==='websocket') foreach($this->GetVisualizationIdents($property) as $ident) {
            $web=$this->GetWebEEVPoint($ident);
            if($web!==null) return $web;
        }
        if ($property === 'InputPower') {
            $id = $this->ReadPropertyInteger('kwin');
            if ($id > 0 && IPS_VariableExists($id)) {
                return ['variableId'=>$id,'ident'=>(string)(IPS_GetObject($id)['ObjectIdent'] ?? ''),'value'=>GetValue($id),
                    'formatted'=>GetValueFormatted($id),'unit'=>$this->GetVariableUnit($id),'binary'=>false];
            }
        }
        if (preg_match('/^(SupplyTemperatureHeating|RefluxTemperatureHeating|HeatingCircuitPumpRunning|HeatingCircuitPumpValue)([1-3])$/D', $property, $match)
            && !in_array($property, ['HeatingCircuitPumpRunning1','HeatingCircuitPumpValue1'], true)
            && !$this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $match[2])) return null;
        if ($this->ReadPropertyString('CommunicationMode') === 'modbus' && !in_array($property, ['ComputedCOP','ComputedJAZ'], true)) {
            foreach ($this->GetVisualizationIdents($property) as $ident) {
                $point = $this->GetHybridVisualizationPoint($ident);
                if ($point !== null) return $point;
            }
            foreach($this->GetVisualizationIdents($property) as $ident) {
                $web=$this->GetWebEEVPoint($ident);
                if($web!==null) return $web;
            }
            // Explicit external sensors remain usable; old own Java objects are not live SHI readings.
            $id = $this->GetVisualizationVariableID($property);
            if ($id <= 0 || IPS_GetParent($id) === $this->InstanceID) return null;
            return ['variableId'=>$id,'ident'=>(string)(IPS_GetObject($id)['ObjectIdent'] ?? ''),'value'=>GetValue($id),
                'formatted'=>GetValueFormatted($id),'unit'=>$this->GetVariableUnit($id),'binary'=>false];
        }
        foreach ($this->GetVisualizationIdents($property) as $ident) {
            $point = $this->GetCachedVisualizationPoint($ident);
            if ($point !== null) return $point;
        }
        foreach($this->GetVisualizationIdents($property) as $ident) {
            $web=$this->GetWebEEVPoint($ident);
            if($web!==null) return $web;
        }
        // Existing own/external assignments remain compatible when no internal reading is available.
        $id = $this->GetVisualizationVariableID($property);
        if ($id <= 0 || !IPS_VariableExists($id)) return null;
        $availability=json_decode($this->GetBuffer('JavaAvailability'),true)??[];
        if(($availability['source']??'')===$this->VisualizationSourceKey() && IPS_GetParent($id)===$this->InstanceID) {
            foreach($this->GetVisualizationIdents($property) as $ident) foreach(self::DATA_POINT_CONFIG as $nativeID=>$native) {
                if($native['name']===$ident && $nativeID>=($availability['count']??0)) return null;
            }
        }
        return ['variableId' => $id, 'ident' => (string)(IPS_GetObject($id)['ObjectIdent'] ?? ''),
            'value' => GetValue($id), 'formatted' => GetValueFormatted($id), 'unit' => $this->GetVariableUnit($id), 'binary' => false];
    }

    /** On-demand raw capture; 3003 reads parameters, 3004 reads calculated measurements. */
    public function GetEEVDiagnosticData(int $Command = 3004): string
    {
        if (!in_array($Command, [3003,3004], true)) throw new InvalidArgumentException('Für diese Lesediagnose sind nur 3003 und 3004 zulässig. 3002 ist ein Schreibbefehl.');
        if (!$this->ReadPropertyBoolean('InstanceStatus')) throw new RuntimeException('Die Instanz ist deaktiviert.');
        if ($this->ReadPropertyString('CommunicationMode') !== 'java') throw new RuntimeException('Diese Diagnose benötigt den Java-Betrieb.');
        $values = $this->ReadData($Command);
        if ($values === null) throw new RuntimeException('Keine vollständige Java-Antwort erhalten.');
        $rows = [];
        $parameterNames = [1=>'Anpassung_Temp',3=>'Mode_Heizung',4=>'Mode_WW',105=>'Anpassung_WW',
            108=>'Mode_Kuehlung',119=>'Mode_Schwimmbad',1148=>'Anpassung_RBE'];
        // Known password/access fields are not needed to identify EEV measurements.
        $privateParameters = [106,107,755,756,757,758,759,760];
        foreach ($values as $id => $value) {
            if ($Command === 3003 && in_array($id, $privateParameters, true)) {
                $rows[] = ['id'=>$id,'ident'=>'Zugangsdaten_ausgeblendet','raw'=>null,'redacted'=>true];
                continue;
            }
            $ident = $Command === 3004 ? (self::DATA_POINT_CONFIG[$id]['name'] ?? ('ID_' . $id))
                : ($parameterNames[$id] ?? ('Parameter_' . $id));
            $rows[] = ['id'=>$id,'ident'=>$ident,'raw'=>$value];
        }
        return json_encode(['command'=>$Command,'count'=>count($rows),'values'=>$rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function GetVisualizationRequiredPointIDs(): array
    {
        $idents = [];
        $mixers = [];
        for ($i = 1; $i <= 3; ++$i) {
            if ($this->ReadPropertyBoolean('VisualizationHeatingCircuit' . $i)) {
                $mixers[$this->ReadPropertyInteger('VisualizationHeatingCircuitMixer' . $i)] = true;
            }
        }
        foreach (self::LUXTRONIK_IDENT_MAP + self::VISUALIZATION_EXTRA_IDENT_MAP as $property => $candidates) {
            if (preg_match('/^Mixer(?:Actual|Target|Open|Close|Pump)([1-3])$/D', $property, $match)
                && !isset($mixers[(int)$match[1]])) continue;
            if ($property === 'ThermalSolarPanelTemp' && !$this->ReadPropertyBoolean('ThermalSolarAvailable')) continue;
            if (in_array($property, ['SolarStorageTemperature', 'ThermalSolarPump'], true) && !$this->ReadPropertyBoolean('ThermalSolarAvailable')) continue;
            if ($property === 'CirculatingPumpRunning' && !$this->ReadPropertyBoolean('VisualizationCirculation')) continue;
            foreach ($this->GetVisualizationIdents($property) as $ident) $idents[$ident] = true;
        }
        $result = [];
        foreach (self::DATA_POINT_CONFIG as $id => $point) {
            if (isset($idents[$point['name']])) $result[$id] = true;
        }
        return $result;
    }

    private function VisualizationHeaterPower(int $number): ?array
    {
        $id = $this->ReadPropertyInteger('HeaterRod' . $number);
        if ($id <= 0 || !IPS_VariableExists($id)) return null;
        $reading = ['variableId'=>$id, 'value'=>GetValue($id), 'formatted'=>GetValueFormatted($id),
            'unit'=>$this->GetVariableUnit($id), 'binary'=>false];
        // A measured power value is independent of all heater switch states.
        // Preserve signed readings too; do not reject a meter's small negative standby value.
        $value = $reading['value'];
        $unit = $this->VisualizationPowerUnit($reading['unit'] !== '' ? $reading['unit'] : 'W');
        if ((is_int($value) || is_float($value)) && is_finite((float)$value) && $unit !== null) {
            $watts = (float)$value * ($unit === 'kW' ? 1000 : 1);
            if (is_finite($watts)) return array_replace($reading, ['watts'=>$watts, 'unit'=>$unit]);
        }
        return null;
    }

    private function VisualizationPowerUnit(string $unit): ?string
    {
        $unit=strtolower((string)preg_replace('/[\s\p{Z}]+/u','',$unit));
        return match ($unit) {'w','watt','watts'=>'W','kw','kilowatt','kilowatts'=>'kW',default=>null};
    }

    private function VisualizationPowerReading(array $reading, string $fallbackUnit): ?array
    {
        $value = $reading['value'];
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || $value < 0) return null;
        $unit=$this->VisualizationPowerUnit($reading['unit']!==''?$reading['unit']:$fallbackUnit);
        if ($unit===null) return null;
        $watts=(float)$value*($unit==='kW'?1000:1);
        return is_finite($watts)?array_replace($reading,['watts'=>$watts,'unit'=>$unit]):null;
    }

    private function GetVisualizationRoomSources(): array
    {
        $sources = [];
        for ($i = 1; $i <= 3; ++$i) {
            $id = $this->ReadPropertyInteger('VisualizationRoomVariable' . $i);
            if ($id <= 0 || !IPS_VariableExists($id)) continue;
            $label = trim($this->ReadPropertyString('VisualizationRoomLabel' . $i));
            $icon = '';
            if ($this->ReadPropertyBoolean('VisualizationRoomIcons')) {
                $icon = (string)(IPS_GetObject($id)['ObjectIcon'] ?? '');
                if ($icon === '') {
                    $presentation = $this->VisualizationPresentation($id);
                    $icon = (string)($presentation['ICON'] ?? '');
                    foreach ($this->VisualizationAssociations($id) as $option) {
                        if (GetValue($id) === $option['value'] && !empty($option['icon'])) { $icon = $option['icon']; break; }
                    }
                }
                if ($icon === '') {
                    $profile = $this->GetVariableProfileName($id);
                    if ($profile !== '' && IPS_VariableProfileExists($profile)) $icon = (string)(IPS_GetVariableProfile($profile)['Icon'] ?? '');
                }
            }
            $display = $this->ReadPropertyString('VisualizationRoomDisplay' . $i);
            $sources[] = ['key' => 'roomTemperature' . $i, 'variableId' => $id,
                'label' => $label === '' ? IPS_GetName($id) : $label,
                'display' => in_array($display, ['name', 'icon', 'both'], true) ? $display : 'both',
                'icon' => $this->VisualizationIconClass($icon)];
        }
        return $sources;
    }

    private function VisualizationIconClass(string $icon): string
    {
        if ($icon === 'Transparent') return 'none';
        $legacy = ['Temperature' => 'temperature-half', 'House' => 'house', 'Home' => 'house', 'Bed' => 'bed', 'Bath' => 'bath'];
        if (isset($legacy[$icon])) return 'fa-light fa-' . $legacy[$icon];
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $icon)) return 'fa-light fa-' . preg_replace('/^fa-/', '', $icon);
        if (preg_match('/^fa-(light|regular|solid|brands|kit) fa-[a-z0-9-]+$/D', $icon)) return $icon;
        return ''; // Unavailable/legacy icons fall back to a house, never arbitrary HTML.
    }

    private function VisualizationRoomFields(): array
    {
        $fields = [['type' => 'CheckBox', 'name' => 'VisualizationRoomIcons', 'caption' => 'Variablensymbole verwenden (sonst Haus)']];
        for ($i = 1; $i <= 3; ++$i) {
            $fields[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'VisualizationRoomLabel' . $i, 'caption' => 'Raum ' . $i . ' (leer = Variablenname)'],
                $this->VariableSelect('Temperatur ' . $i, 'VisualizationRoomVariable' . $i),
                ['type' => 'Select', 'name' => 'VisualizationRoomDisplay' . $i, 'caption' => 'Anzeige',
                    'options' => [['caption' => 'Name und Icon', 'value' => 'both'], ['caption' => 'Nur Name', 'value' => 'name'], ['caption' => 'Nur Icon', 'value' => 'icon']]]
            ]];
        }
        return $fields;
    }

}
