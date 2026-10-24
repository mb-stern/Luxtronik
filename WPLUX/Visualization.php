<?php

/**
 * Heat-pump visualization adapted from mb-stern/Waermepumpe, development,
 * aae3f06c0b8e148b5671386797368771bcd3e8e1. Source license: heat-pump/LICENSE.
 * The graphic reads already converted instance values; it does not query devices.
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
        'AmbientTemperatureNormal' => ['Raumtemperatur_Sollwert', 'Desired_Room_Temperature'],
        'AmbientTemperatureActual' => ['Raumtemperatur_Istwert', 'Raumtemperatur_Raumstation_1'],
        'SupplyTemperature' => ['Vorlauftemperatur_Heizkreis'],
        'ReturnTemperatureSetpointVariable' => ['Ruecklauf_Soll_Heizkreis'],
        'FanSpeed' => ['Ventilator_Drehzahl'],
        'HpRunning' => ['Ausgang_Solepumpe_Ventilator'],
        'CompressorRunning' => ['Ausgang_Verdichter_1'],
        'CompressorValue' => ['Verdichterdrehzahl', 'Verdichterfrequenz'],
        'HeatingPumpPower' => ['Waermeleistung'],
        'EvaporatorPressure' => ['Niederdruck'],
        'EvaporatorTemperature' => ['Vapourisation_Temperature'],
        'CondenserPressure' => ['Hochdruck'],
        'CondenserTemperature' => ['Kondensationstemperatur', 'Liquefaction_Temperature'],
        'SupplyTemperatureHeating1' => ['Vorlauftemperatur_Heizkreis'],
        'RefluxTemperatureHeating1' => ['Ruecklauftemperatur_Heizkreis'],
        'HeatingCircuitPumpRunning1' => ['Ausgang_Heizungsumwaelzpumpe'],
        'HeatingCircuitPumpValue1' => ['Steuersignal_Umwaelzpumpe'],
        'TankTempWWUp' => ['Temperatur_Brauchwasser_Oben'],
        'TankTempWWMiddle' => ['Warmwasser_Ist_Temperatur'],
        'WWHeatingValve' => ['Ausgang_Brauchwasserpumpe_Umstellventil'],
        'StorageChargingPumpRunning' => ['Ausgang_Brauchwasserpumpe_Umstellventil'],
        'CirculatingPumpRunning' => ['Ausgang_Zirkulationspumpe'],
        'ThermalSolarPanelTemp' => ['Fuehler_Solarkollektor']
    ];

    private function CreateVisualization(): void
    {

        // Optional browser endpoint override; the normal endpoint uses this instance IPAddress.
        $this->RegisterPropertyString('LuxtronikWebInterfaceURL', '');
        $this->RegisterPropertyBoolean('VisualizationDirectCircuit', true);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit1', false);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit2', false);
        $this->RegisterPropertyBoolean('VisualizationMixedCircuit3', false);
        $this->RegisterPropertyInteger('HeatPumpReturnTemperature', 0);

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

        // Frei belegbare Zusatzwerte der SVG (000 ... 009)
        $this->RegisterPropertyString('AdditionalLabel000', '');
        $this->RegisterPropertyInteger('AdditionalValue000', 0);
        $this->RegisterPropertyString('AdditionalLabel001', '');
        $this->RegisterPropertyInteger('AdditionalValue001', 0);
        $this->RegisterPropertyString('AdditionalLabel002', '');
        $this->RegisterPropertyInteger('AdditionalValue002', 0);
        $this->RegisterPropertyString('AdditionalLabel003', '');
        $this->RegisterPropertyInteger('AdditionalValue003', 0);
        $this->RegisterPropertyString('AdditionalLabel004', '');
        $this->RegisterPropertyInteger('AdditionalValue004', 0);
        $this->RegisterPropertyString('AdditionalLabel005', '');
        $this->RegisterPropertyInteger('AdditionalValue005', 0);
        $this->RegisterPropertyString('AdditionalLabel006', '');
        $this->RegisterPropertyInteger('AdditionalValue006', 0);
        $this->RegisterPropertyString('AdditionalLabel007', '');
        $this->RegisterPropertyInteger('AdditionalValue007', 0);
        $this->RegisterPropertyString('AdditionalLabel008', '');
        $this->RegisterPropertyInteger('AdditionalValue008', 0);
        $this->RegisterPropertyString('AdditionalLabel009', '');
        $this->RegisterPropertyInteger('AdditionalValue009', 0);

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

    /*
     * Dezente Flussanimation für unsere erweiterte Temperaturdarstellung.
     * Die Original-Card bleibt unangetastet, solange eigene Farben
     * deaktiviert sind.
     */
    @keyframes symcon-flow-forward {
        from { stroke-dashoffset: 0; }
        to   { stroke-dashoffset: -24; }
    }

    @keyframes symcon-flow-reverse {
        from { stroke-dashoffset: 0; }
        to   { stroke-dashoffset: 24; }
    }

    .symcon-flow-overlay {
        pointer-events: none;
        fill: none !important;
        stroke: rgba(255,255,255,.68) !important;
        stroke-width: 2.2 !important;
        stroke-dasharray: 4 8 !important;
        stroke-linecap: round !important;
        vector-effect: non-scaling-stroke;
    }

    .symcon-flow-forward {
        animation: symcon-flow-forward 1.4s linear infinite;
    }

    .symcon-flow-reverse {
        animation: symcon-flow-reverse 1.4s linear infinite;
    }

    /*
     * Kältekreis: Flusspunkte bewusst dünner als bei den übrigen Leitungen,
     * damit die Temperaturfarbe der 5-px-Leitung klar sichtbar bleibt.
     */
    .symcon-flow-overlay.symcon-flow-refrigerant {
        stroke-width: 1.25 !important;
        stroke: rgba(255,255,255,.62) !important;
        stroke-dasharray: 3 9 !important;
    }

    /*
     * Heizkörper/Fußbodenheizung: Flusspunkte klar erkennbar,
     * aber weiterhin schmaler als die farbige Leitung.
     */
    .symcon-flow-overlay.symcon-flow-emitter {
        stroke-width: 1.45 !important;
        stroke-dasharray: 3 8 !important;
    }
    #wp-luxtronik-dialog {
        width: 760px;
        max-width: calc(100vw - 24px);
        max-height: calc(100vh - 24px);
        box-sizing: border-box;
        padding: 12px;
        border: 1px solid var(--divider-color, #888);
        border-radius: 10px;
        background: var(--card-background-color, Canvas);
        color: var(--content-color, CanvasText);
    }
    #wp-luxtronik-dialog[open] { display: flex; flex-direction: column; gap: 10px; }
    #wp-luxtronik-dialog::backdrop { background: rgba(0, 0, 0, .4); }
    #wp-luxtronik-dialog .wp-luxtronik-header { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
    #wp-luxtronik-dialog .wp-luxtronik-header strong { flex: 1; }
    #wp-luxtronik-dialog a { color: inherit; }
    #wp-luxtronik-help { margin: 0; font-size: 14px; }
    #wp-luxtronik-login { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    #wp-luxtronik-password { width: 110px; max-width: 100%; }
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
    </form>
    <div id="wp-luxtronik-device">
        <canvas id="wp-luxtronik-display" width="160" height="64" tabindex="0" role="img" aria-label="Luxtronik-Gerätedisplay; Bedienung mit Pfeiltasten und Enter"></canvas>
        <fieldset id="wp-luxtronik-controls" disabled aria-label="Luxtronik bedienen">
            <button type="button" data-luxtronik-turn="1" aria-label="Pfeil links">←</button>
            <button type="button" id="wp-luxtronik-press">Enter</button>
            <button type="button" data-luxtronik-turn="0" aria-label="Pfeil rechts">→</button>
        </fieldset>
    </div>
</dialog>

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
            'instanceFooter'             => $this->GetVisualizationFooter(),
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
            'compressorValue'            => $this->DataKey('CompressorValue', 'compressorValue'),
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

            'additionalLabel000'          => $this->ReadPropertyString('AdditionalLabel000'),
            'additionalValue000'          => $this->DataKey('AdditionalValue000', 'additionalValue000'),
            'additionalLabel001'          => $this->ReadPropertyString('AdditionalLabel001'),
            'additionalValue001'          => $this->DataKey('AdditionalValue001', 'additionalValue001'),
            'additionalLabel002'          => $this->ReadPropertyString('AdditionalLabel002'),
            'additionalValue002'          => $this->DataKey('AdditionalValue002', 'additionalValue002'),
            'additionalLabel003'          => $this->ReadPropertyString('AdditionalLabel003'),
            'additionalValue003'          => $this->DataKey('AdditionalValue003', 'additionalValue003'),
            'additionalLabel004'          => $this->ReadPropertyString('AdditionalLabel004'),
            'additionalValue004'          => $this->DataKey('AdditionalValue004', 'additionalValue004'),
            'additionalLabel005'          => $this->ReadPropertyString('AdditionalLabel005'),
            'additionalValue005'          => $this->DataKey('AdditionalValue005', 'additionalValue005'),
            'additionalLabel006'          => $this->ReadPropertyString('AdditionalLabel006'),
            'additionalValue006'          => $this->DataKey('AdditionalValue006', 'additionalValue006'),
            'additionalLabel007'          => $this->ReadPropertyString('AdditionalLabel007'),
            'additionalValue007'          => $this->DataKey('AdditionalValue007', 'additionalValue007'),
            'additionalLabel008'          => $this->ReadPropertyString('AdditionalLabel008'),
            'additionalValue008'          => $this->DataKey('AdditionalValue008', 'additionalValue008'),
            'additionalLabel009'          => $this->ReadPropertyString('AdditionalLabel009'),
            'additionalValue009'          => $this->DataKey('AdditionalValue009', 'additionalValue009'),
        ];
        $circuits = $this->GetVisualizationCircuits();
        for ($i = 1; $i <= 3; ++$i) {
            $circuit = $circuits[$i - 1] ?? null;
            $type = $this->ReadPropertyString('HeatingCircuitType' . $i);
            $config['heatingCircuitType' . $i] = $circuit === null ? 'off' : ($type === 'radiator' ? 'radiator' : 'underfloor');
            $config['heatingCircuitMixed' . $i] = $circuit !== null && str_starts_with($circuit['source'], 'mixed');
            $name = $this->ReadPropertyString('HeatingCircuitName' . $i);
            $config['heatingCircuitName' . $i] = $circuit === null ? '' : ($name === 'HK' . $i ? $circuit['name'] : $name);
        }
        $config['circuitWarning'] = count($circuits) > 3 ? 'Die Grafik zeigt maximal drei Kreise. Bitte höchstens drei Heiz-/Mischkreise auswählen.' : '';
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

            'additionalValue000' => ['AdditionalValue000', false],
            'additionalValue001' => ['AdditionalValue001', false],
            'additionalValue002' => ['AdditionalValue002', false],
            'additionalValue003' => ['AdditionalValue003', false],
            'additionalValue004' => ['AdditionalValue004', false],
            'additionalValue005' => ['AdditionalValue005', false],
            'additionalValue006' => ['AdditionalValue006', false],
            'additionalValue007' => ['AdditionalValue007', false],
            'additionalValue008' => ['AdditionalValue008', false],
            'additionalValue009' => ['AdditionalValue009', false],
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
            $statusValue = GetValue($this->GetVisualizationVariableID('OperatingStatusVariable'));

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

        $fanSpeedId = $this->GetVisualizationVariableID('FanSpeed');
        if ($fanSpeedId > 0 && IPS_VariableExists($fanSpeedId)) {
            $fanValue = GetValue($fanSpeedId);
            $data['hpRunning'] = $this->BinaryData(
                is_numeric($fanValue)
                    ? (float) $fanValue > 0.0
                    : $this->NormalizeBinaryValue($fanValue)
            );
        }

        foreach ($this->GetVisualizationFooter() as $entry) {
            $id = $entry['variableId'];
            $data[$entry['key']] = ['variableId' => $id, 'value' => GetValue($id),
                'formatted' => GetValueFormatted($id), 'unit' => $this->GetVariableUnit($id), 'binary' => false];
        }
        return $data;
    }

    private function AddVariableData(
        array &$data,
        string $key,
        string $property,
        bool $binary
    ): void {
        $variableId = $this->GetVisualizationVariableID($property);

        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return;
        }

        $value = GetValue($variableId);

        $data[$key] = [
            'variableId'=> $variableId,
            'ident'     => (string) (IPS_GetObject($variableId)['ObjectIdent'] ?? ''),
            'value'     => $binary ? $this->NormalizeBinaryValue($value) : $value,
            'formatted' => $binary ? '' : GetValueFormatted($variableId),
            'unit'      => $binary ? '' : $this->GetVariableUnit($variableId),
            'binary'    => $binary
        ];
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
        $variableId = $this->GetVisualizationVariableID($property);

        return $variableId > 0 && IPS_VariableExists($variableId) ? $key : '';
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
        $variableId = $this->GetVisualizationVariableID('OperatingStatusVariable');
        return $variableId > 0 && IPS_VariableExists($variableId);
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
            $operatingStatusValue = GetValue(
                $this->GetVisualizationVariableID('OperatingStatusVariable')
            );
        }

        return [
            'hasOperatingStatus'           => $hasOperatingStatus,
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
            'power'                        => $this->BuildControlInfo('HeatingPumpStatusOnOff'),
            'warmWaterSetpoint'            => $this->GetNumericControlInfo('warmWaterSetpoint'),
            'heatingTemperatureCorrection' => $this->GetNumericControlInfo('heatingTemperatureCorrection')
        ];
    }

    private function GetOperatingModeActive(
        string $directProperty,
        string $statusValuesProperty
    ): bool {
        if ($this->HasOperatingStatus()) {
            $status = GetValue(
                $this->GetVisualizationVariableID('OperatingStatusVariable')
            );

            return $this->ValueMatchesCsv(
                $status,
                $this->ReadPropertyString($statusValuesProperty)
            );
        }

        $variableId = $this->GetVisualizationVariableID($directProperty);

        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return false;
        }

        return $this->NormalizeBinaryValue(GetValue($variableId));
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
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);

            if (isset($profile['MinValue']) && is_numeric($profile['MinValue'])) {
                $min = (float) $profile['MinValue'];
            }
            if (isset($profile['MaxValue']) && is_numeric($profile['MaxValue'])) {
                $max = (float) $profile['MaxValue'];
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
        $variable = IPS_GetVariable($variableId);

        $profileName = $variable['VariableCustomProfile'] !== ''
            ? $variable['VariableCustomProfile']
            : $variable['VariableProfile'];

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

        const getHeatPumpInstanceID = () => {
            try {
                const match =
                    window.location.pathname.match(
                        /\/visu\/(\d+)\/?/
                    );

                return currentConfig.instanceId ? String(currentConfig.instanceId) : (match ? match[1] : null);
            } catch (_) {
                return null;
            }
        };

        const getInstanceStorageScope = () => {
            const instanceID =
                getHeatPumpInstanceID();

            if (!instanceID) {
                return null;
            }

            return `instance-${instanceID}`;
        };

        const instanceStorageScope =
            getInstanceStorageScope();

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

        const readStateNumber = (entity) => {
            if (!entity || !currentData || !currentData[entity]) {
                return null;
            }

            const raw = currentData[entity].value;
            const normalized = String(raw ?? '')
                .trim()
                .replace(',', '.')
                .replace(/[^0-9+\-.]/g, '');

            const value = Number(normalized);
            return Number.isFinite(value) ? value : null;
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



        const setStroke = (svg, selector, value) => {
            const element = svg.querySelector(selector);
            if (element) {
                element.style.stroke = value;
            }
        };

        const applyRefrigerantCircuitMode = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const cooling = stateIsOn(currentConfig.heatingPumpCoolingMode);

            const getText = (selector) => {
                const el = svg.querySelector(selector);
                return el ? el.textContent : '';
            };

            const setText = (selector, value) => {
                const el = svg.querySelector(selector);
                if (el) {
                    el.textContent = value;
                }
            };

            /*
             * Reversibler Kältekreis:
             *
             * Heizbetrieb:
             *   Außenseite links  = Verdampfer / Niederdruck
             *   Wasserseite rechts = Verflüssiger / Hochdruck
             *
             * Kühlbetrieb:
             *   Außenseite links  = Verflüssiger / Hochdruck
             *   Wasserseite rechts = Verdampfer / Niederdruck
             *
             * Verdichter und Expansionsventil bleiben an ihrer Position.
             * Wir spiegeln NICHT die komplette SVG, damit Texte und Symbole
             * nicht seitenverkehrt werden. Stattdessen werden die Funktionen
             * der beiden Wärmetauscher samt Messwerten sauber vertauscht.
             */

            if (!cooling) {
                return;
            }

            const evaporatorLabel = getText('#textEvaporator');
            const evaporatorPressure = getText('#textEvaporatorPressure');
            const evaporatorTemperature = getText('#textEvaporatorTemperature');

            const condenserLabel = getText('#textCondenser');
            const condenserPressure = getText('#textCondenserPressure');
            const condenserTemperature = getText('#textCondenserTemperature');

            setText('#textEvaporator', condenserLabel || 'Kondensator');
            setText('#textEvaporatorPressure', condenserPressure);
            setText('#textEvaporatorTemperature', condenserTemperature);

            setText('#textCondenser', evaporatorLabel || 'Verdampfer');
            setText('#textCondenserPressure', evaporatorPressure);
            setText('#textCondenserTemperature', evaporatorTemperature);

            /*
             * Die Original-SVG hat eindeutige IDs für genau die beiden
             * Wärmetauscher-Symbole:
             *
             * Verdampfer:
             *   #pathHPModelEvaporatorSymbol001
             *   #pathHPModelEvaporatorSymbol002
             *
             * Verflüssiger:
             *   #pathHPModelCondenserSymbol
             *
             * Im Kühlbetrieb werden ausschließlich diese drei Pfade verschoben.
             * Verdichter, Expansionsventil, Pumpen, Leitungen und Texte bleiben
             * unangetastet.
             */
            const evaporatorSymbols = [
                svg.querySelector('#pathHPModelEvaporatorSymbol001'),
                svg.querySelector('#pathHPModelEvaporatorSymbol002')
            ].filter(Boolean);

            const condenserSymbol = svg.querySelector('#pathHPModelCondenserSymbol');

            if (
                evaporatorSymbols.length === 2
                && condenserSymbol
                && svg.dataset.symconHeatExchangerSymbolsSwapped !== '1'
            ) {
                const unionBox = (elements) => {
                    const boxes = elements.map((element) => element.getBBox());

                    const minX = Math.min(...boxes.map((box) => box.x));
                    const minY = Math.min(...boxes.map((box) => box.y));
                    const maxX = Math.max(...boxes.map((box) => box.x + box.width));
                    const maxY = Math.max(...boxes.map((box) => box.y + box.height));

                    return {
                        cx: (minX + maxX) / 2,
                        cy: (minY + maxY) / 2
                    };
                };

                const evaporatorCenter = unionBox(evaporatorSymbols);
                const condenserCenter = unionBox([condenserSymbol]);

                const evaporatorDx = condenserCenter.cx - evaporatorCenter.cx;
                const evaporatorDy = condenserCenter.cy - evaporatorCenter.cy;

                const condenserDx = evaporatorCenter.cx - condenserCenter.cx;
                const condenserDy = evaporatorCenter.cy - condenserCenter.cy;

                const movePath = (element, dx, dy) => {
                    const originalTransform = element.getAttribute('transform') || '';

                    element.setAttribute(
                        'transform',
                        'translate(' + dx + ' ' + dy + ')'
                        + (originalTransform ? ' ' + originalTransform : '')
                    );
                };

                evaporatorSymbols.forEach((element) => {
                    movePath(element, evaporatorDx, evaporatorDy);
                });

                movePath(condenserSymbol, condenserDx, condenserDy);

                svg.dataset.symconHeatExchangerSymbolsSwapped = '1';
            }

            svg.dataset.refrigerantCircuitDirection = 'reversed';
        };

        const applyCoolingVisualization = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const cooling = stateIsOn(currentConfig.heatingPumpCoolingMode);

            /*
             * WICHTIG:
             * Verdampfer/Kondensator und ihre Messwerte bleiben unverändert.
             * Die Original-Card kennt keine hydraulische Umschaltung im Kühlbetrieb.
             *
             * Wir ändern deshalb ausschließlich die Gebäudeseite:
             *
             * Heizen:
             *   Vorlauf  = warm / rot
             *   Rücklauf = kalt / blau
             *
             * Kühlen:
             *   Vorlauf  = kalt / blau
             *   Rücklauf = wärmer / rot
             */
            const supplyColor = cooling ? '#0a84ff' : '#ff3b30';
            const returnColor = cooling ? '#ff3b30' : '#0a84ff';

            const setStrokeColor = (selector, color) => {
                const element = svg.querySelector(selector);
                if (element) {
                    element.style.setProperty('stroke', color, 'important');
                }
            };


            // Heizwasserseite / Puffer / Heizkreise.
            // Selektoren, die in einer bestimmten Topologie nicht existieren,
            // werden einfach übersprungen.
            [
                '#pathPipeToBuffer',
                '#pathPipeToHeatingCircuitPump',
                '#pathPipeToHeatingCircuitPump2',
                '#pathPipeToHeatingCircuitPump3',
                '#pathPipeBufferToHeating',
                '#pathPipeTankHPToHeating',
                '#pathPipeHeatingSupply',
                '#pathPipeHeatingSupply2',
                '#pathPipeHeatingSupply3'
            ].forEach((selector) => setStrokeColor(selector, supplyColor));

            [
                '#pathPipeFromBuffer',
                '#pathPipeToHP',
                '#pathPipeToHP2',
                '#pathPipeHeatingToBuffer',
                '#pathPipeHeatingReturn',
                '#pathPipeHeatingReturn2',
                '#pathPipeHeatingReturn3'
            ].forEach((selector) => setStrokeColor(selector, returnColor));

            // Falls die Heizkreise ihre Farben über SVG-Verläufe beziehen,
            // diese im Kühlbetrieb ebenfalls umkehren.
            const gradientPairs = [
                ['#stopCircuit1', '#stopCircuit2'],
                ['#stopCircuit3', '#stopCircuit4'],
                ['#stopCircuit5', '#stopCircuit6']
            ];

            gradientPairs.forEach(([supplyStopSelector, returnStopSelector]) => {
                const supplyStop = svg.querySelector(supplyStopSelector);
                const returnStop = svg.querySelector(returnStopSelector);

                if (supplyStop) {
                    supplyStop.style.setProperty('stop-color', supplyColor, 'important');
                }

                if (returnStop) {
                    returnStop.style.setProperty('stop-color', returnColor, 'important');
                }
            });

            // Heizkreis-Symbole optional leicht an den Modus anpassen, ohne
            // die originale Geometrie/Topologie zu verändern.
            if (cooling) {
                [
                    '#gHeaterCircuit1',
                    '#gHeaterCircuit2',
                    '#gHeaterCircuit3'
                ].forEach((selector) => {
                    const group = svg.querySelector(selector);
                    if (group) {
                        group.dataset.symconCooling = '1';
                    }
                });
            } else {
                [
                    '#gHeaterCircuit1',
                    '#gHeaterCircuit2',
                    '#gHeaterCircuit3'
                ].forEach((selector) => {
                    const group = svg.querySelector(selector);
                    if (group) {
                        delete group.dataset.symconCooling;
                    }
                });
            }

            // Nur als interner Marker für spätere Erweiterungen.
            svg.dataset.operatingMode = cooling ? 'cooling' : 'heating';
        };

        const formatStateForSvg = (entityName) => {
            if (!entityName || !currentData || !currentData[entityName]) {
                return '';
            }

            const item = currentData[entityName];
            const raw = item.value;

            if (raw === null || raw === undefined || raw === '') {
                return '';
            }

            const numeric = Number(raw);
            const unit = item.unit ? String(item.unit).trim() : '';

            if (!Number.isNaN(numeric)) {
                const value = new Intl.NumberFormat('de-CH', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                }).format(numeric);

                return unit ? value + ' ' + unit : value;
            }

            return unit ? String(raw) + ' ' + unit : String(raw);
        };

        const updateRefrigerantValues = (card) => {
            if (!card || !card.content) {
                return;
            }

            const values = [
                ['#textEvaporatorPressure', currentConfig.evaporatorPressure],
                ['#textEvaporatorTemperature', currentConfig.evaporatorTemperature],
                ['#textCondenserPressure', currentConfig.condenserPressure],
                ['#textCondenserTemperature', currentConfig.condenserTemperature],
                ['#textExpansionValveOpening', currentConfig.expansionValveOpening],
                ['#textCompressorValue', currentConfig.compressorValue]
            ];

            values.forEach(([selector, entityName]) => {
                const element = card.content.querySelector(selector);
                if (element) {
                    element.textContent = formatStateForSvg(entityName);
                }
            });
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
                    (element.classList && element.classList.contains('symcon-flow-overlay'))
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

        const stateIsOn = (entityName) => {
            if (!entityName || !currentData || !currentData[entityName]) {
                return false;
            }

            const item = currentData[entityName];
            if (item.binary) {
                return !!item.value;
            }

            return ['1', 'true', 'on', 'yes', 'ja', 'ein', 'active', 'aktiv']
                .includes(String(item.value ?? '').trim().toLowerCase());
        };

        const disableOriginalSettingsLink = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const link = svg.querySelector('#linkSettings');

            if (!link || !link.parentNode) {
                return;
            }

            /*
             * Die Original-SVG legt die komplette Statusleiste in
             * <a id="linkSettings" href="#"> ... </a>.
             *
             * Auf dem Handy erzeugt Symcon/WebView daraus eine zusätzliche
             * Link-/Pfeilfläche. Ein preventDefault auf touchend ist aber
             * ebenfalls falsch, weil dadurch der nachfolgende click auf
             * unseren Icons unterdrückt werden kann.
             *
             * Lösung: den <a>-Wrapper komplett entfernen, die Kinder aber
             * unverändert an derselben Stelle im SVG belassen.
             */
            const parent = link.parentNode;

            while (link.firstChild) {
                parent.insertBefore(link.firstChild, link);
            }

            parent.removeChild(link);
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
                cooling: 'Kühlen'
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
                heatingTemperatureCorrection: 'Heiztemperaturkorrektur'
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


        const setIconColor = (group, color) => {
            if (!group) {
                return;
            }

            const tag = String(group.tagName || '').toLowerCase();

            group.setAttribute('color', color);
            group.setAttribute('fill', color);
            group.setAttribute('stroke', color);
            group.style.setProperty('color', color, 'important');
            group.style.setProperty('fill', color, 'important');

            /*
             * Warmwasser ist in der Original-SVG kein <g>, sondern ein
             * einzelnes <use xlink:href="#gWaterTap">. Genau dieses Element
             * muss direkt eingefärbt werden.
             */
            if (tag === 'use') {
                group.style.setProperty('fill', color, 'important');
                group.style.setProperty('stroke', color, 'important');
                return;
            }

            group.querySelectorAll(
                'path, rect, circle, ellipse, line, polyline, polygon, use'
            ).forEach((element) => {
                const computed = getComputedStyle(element);
                const fill = String(computed.fill || '').toLowerCase();
                const stroke = String(computed.stroke || '').toLowerCase();

                if (
                    fill !== 'none'
                    && fill !== 'rgba(0, 0, 0, 0)'
                    && fill !== 'transparent'
                ) {
                    element.setAttribute('fill', color);
                    element.style.setProperty('fill', color, 'important');
                }

                if (
                    stroke !== 'none'
                    && stroke !== 'rgba(0, 0, 0, 0)'
                    && stroke !== 'transparent'
                ) {
                    element.setAttribute('stroke', color);
                    element.style.setProperty('stroke', color, 'important');
                }
            });
        };

        const applyWWValvePipeGeometry = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            const pipeToBuffer = svg.querySelector('#pathPipeToBuffer');
            const pipeFromBuffer = svg.querySelector('#pathPipeFromBuffer');
            const pipeHotCold = svg.querySelector('#pathPipeHotColdHeatpump');
            const pipeHotWater = svg.querySelector('#pathPipeHotWaterToTank');

            /*
             * Das Umschaltventil sitzt bei (620 / 450), Radius 30.
             * Es gibt drei Leitungsstücke, die in den Ventilbereich hineinlaufen:
             *
             * 1. pathPipeToBuffer          -> nach oben
             * 2. pathPipeHotColdHeatpump  -> von links
             * 3. pathPipeHotWaterToTank   -> vom Warmwasserspeicher
             *
             * Nur diese drei Endstücke werden am Ventilrand gekürzt.
             */

            if (pipeToBuffer) {
                const original =
                    'm 598,450 c 22,0 22,-15 22,-30 V 158 c 0,-12 5,-18 18,-18 H 750';

                const withValve =
                    'M 620,420 V 158 c 0,-12 5,-18 18,-18 H 750';

                pipeToBuffer.setAttribute(
                    'd',
                    currentConfig.wwHeatingValve ? withValve : original
                );
            }

            if (pipeFromBuffer) {
                const original =
                    'm 997,265 c 0,15 0,30 -22,30 H 650 c -15,0 -22,13 -22,30 v 170 c 0,18 -18,30 -30,30';

                /*
                 * Blaue Rücklaufleitung:
                 * Der senkrechte Abschnitt liegt bei x=628 und läuft damit
                 * durch den Ventilkreis (Mittelpunkt 620/450, r=30).
                 * Nur der Bereich innerhalb des Kreises wird ausgespart.
                 */
                const withValve =
                    'm 997,265 c 0,15 0,30 -22,30 H 650 c -15,0 -22,13 -22,30 V 420 '
                    + 'M 628,480 V 495 c 0,18 -18,30 -30,30';

                pipeFromBuffer.setAttribute(
                    'd',
                    currentConfig.wwHeatingValve ? withValve : original
                );
            }

            if (pipeHotCold) {
                const original =
                    'm 598,525 h -85 c -15,0 -30,0 -35,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 h 90';

                /*
                 * Original endet bei x=598 / y=450 und läuft damit in den
                 * Ventilkreis hinein. Mit Ventil endet der letzte horizontale
                 * Abschnitt bereits bei x=590, also exakt am linken Kreisrand.
                 */
                const withValve =
                    'm 598,525 h -85 c -15,0 -30,0 -35,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 15,0 30,0 30,10 0,5 -15,5 -30,5 -15,0 -30,0 -30,-15 0,-15 15,-15 30,-15 h 82';

                pipeHotCold.setAttribute(
                    'd',
                    currentConfig.wwHeatingValve ? withValve : original
                );
            }

            if (pipeHotWater) {
                const original =
                    'm 598,525 h 95 c 15,0 30,0 35,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 H 598';

                /*
                 * Dieser Pfad kommt von rechts aus Richtung Warmwasserspeicher.
                 * Mit Ventil endet er deshalb am rechten Kreisrand bei x=650.
                 */
                const withValve =
                    'm 598,525 h 95 c 15,0 30,0 35,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 -15,0 -30,0 -30,10 0,5 15,5 30,5 15,0 30,0 30,-15 0,-15 -15,-15 -30,-15 H 650';

                pipeHotWater.setAttribute(
                    'd',
                    currentConfig.wwHeatingValve ? withValve : original
                );
            }
        };

        const storedCircuitColorKey = 'symconHeatPumpStoredCircuitColors';

        const loadStoredCircuitColors = () => {
            try {
                const raw = window.sessionStorage
                    ? window.sessionStorage.getItem(storedCircuitColorKey)
                    : null;

                return raw ? JSON.parse(raw) : {};
            } catch (error) {
                return {};
            }
        };


        let storedCircuitColors = loadStoredCircuitColors();

        const applyAdditionalValues = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            for (let index = 0; index < 10; index++) {
                const suffix = String(index).padStart(3, '0');
                const labelKey = 'additionalLabel' + suffix;
                const valueKey = 'additionalValue' + suffix;

                const labelElement =
                    svg.querySelector('#textLabel' + suffix);
                const valueElement =
                    svg.querySelector('#textValue' + suffix);

                const label = String(
                    currentConfig[labelKey] || ''
                ).trim();

                const entity = currentConfig[valueKey] || '';
                const value = entity
                    ? formatStateForSvg(entity)
                    : '';

                if (labelElement) {
                    labelElement.textContent = label;
                    labelElement.style.setProperty(
                        'display',
                        label ? 'inline' : 'none',
                        'important'
                    );
                }

                if (valueElement) {
                    valueElement.textContent = value;
                    valueElement.style.setProperty(
                        'display',
                        value ? 'inline' : 'none',
                        'important'
                    );
                }
            }
        };

        const normalizeTemperatureUnits = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            /*
             * Einheitliche Darstellung sämtlicher numerischer Temperaturen:
             * immer "°C".
             *
             * Die Original-Card mischt je nach Element Werte mit "℃", "°C"
             * oder ganz ohne Einheit. Wir normalisieren deshalb ausschließlich
             * Text-Elemente mit Temp/Temperature im ID-Namen UND einem Zahlenwert.
             * Beschriftungen wie "Vorlauftemperatur" bleiben unangetastet.
             */
            svg.querySelectorAll(
                'text[id*="Temp"], text[id*="Temperature"]'
            ).forEach((element) => {
                const raw = String(element.textContent || '').trim();

                if (!/[+-]?\d+(?:[.,]\d+)?/.test(raw)) {
                    return;
                }

                const match = raw.match(/[+-]?\d+(?:[.,]\d+)?/);
                if (!match) {
                    return;
                }

                element.textContent = match[0] + ' °C';
            });
        };

        const applyThermalSolarVisualization = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const solarGroup = svg.querySelector('#gThermalSolar');

            if (!solarGroup) {
                return;
            }

            /*
             * Die Solarthermie-Gruppe wurde beim Einbetten der Original-SVG
             * zunächst auf display:none gesetzt. Wenn Solarthermie in Symcon
             * aktiviert ist, halten wir die gesamte Gruppe inklusive der
             * Solar-Wendel ausdrücklich sichtbar.
             */
            if (!currentConfig.thermalSolarAvailable) {
                solarGroup.style.setProperty('display', 'none', 'important');
                solarGroup.style.setProperty('visibility', 'hidden', 'important');

                const existingCoil =
                    svg.querySelector('#symconThermalSolarTankCoil');

                if (existingCoil) {
                    existingCoil.style.setProperty(
                        'display',
                        'none',
                        'important'
                    );
                    existingCoil.style.setProperty(
                        'visibility',
                        'hidden',
                        'important'
                    );
                }

                const existingHotConnector =
                    svg.querySelector('#symconThermalSolarHotConnector');

                if (existingHotConnector) {
                    existingHotConnector.style.setProperty(
                        'display',
                        'none',
                        'important'
                    );
                    existingHotConnector.style.setProperty(
                        'visibility',
                        'hidden',
                        'important'
                    );
                }

                const existingReturnText =
                    svg.querySelector('#symconThermalSolarReturnTemp');

                if (existingReturnText) {
                    existingReturnText.style.setProperty(
                        'display',
                        'none',
                        'important'
                    );
                    existingReturnText.style.setProperty(
                        'visibility',
                        'hidden',
                        'important'
                    );
                }

                return;
            }

            solarGroup.style.setProperty('display', 'inline', 'important');
            solarGroup.style.setProperty('visibility', 'visible', 'important');

            /*
             * Solarpumpe nur anzeigen, wenn dafür tatsächlich eine Variable
             * konfiguriert wurde. Die Solarthermie selbst kann also sichtbar sein,
             * ohne dass automatisch eine Pumpe dargestellt wird.
             */
            const solarPumpGroup = svg.querySelector('#gThermalSolarPump');
            if (solarPumpGroup) {
                const pumpConfigured = !!currentConfig.thermalSolarPump;

                solarPumpGroup.style.setProperty(
                    'display',
                    pumpConfigured ? 'inline' : 'none',
                    'important'
                );
                solarPumpGroup.style.setProperty(
                    'visibility',
                    pumpConfigured ? 'visible' : 'hidden',
                    'important'
                );
            }

            /*
             * Kühle Solar-Rücklauftemperatur nur anzeigen, wenn Solar Rücklauf
             * tatsächlich konfiguriert ist.
             */
            let solarReturnText =
                svg.querySelector('#symconThermalSolarReturnTemp');

            if (currentConfig.thermalSolarReturnTemp) {
                if (!solarReturnText) {
                    solarReturnText = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'text'
                    );
                    solarReturnText.setAttribute(
                        'id',
                        'symconThermalSolarReturnTemp'
                    );
                    /*
                     * Gleiche X-Position wie textThermalSolarFluxTemp (x=850),
                     * direkt darunter.
                     */
                    /*
                     * Rücklauftemperatur auf derselben horizontalen Linie/
                     * X-Ausrichtung wie die vorhandene Solar-Temperatur,
                     * aber direkt unterhalb der kühlen Rücklaufleitung.
                     */
                    solarReturnText.setAttribute('x', '850');
                    solarReturnText.setAttribute('y', '634');
                    solarReturnText.setAttribute('text-anchor', 'end');
                    solarReturnText.setAttribute('xml:space', 'preserve');
                    solarReturnText.style.setProperty(
                        'font-size',
                        '16px',
                        'important'
                    );
                    solarReturnText.style.setProperty(
                        'fill',
                        'var(--primary-text-color)',
                        'important'
                    );

                    solarGroup.appendChild(solarReturnText);
                }

                const returnValue =
                    readStateNumber(currentConfig.thermalSolarReturnTemp);

                if (returnValue !== null) {
                    solarReturnText.textContent =
                        new Intl.NumberFormat('de-CH', {
                            minimumFractionDigits: 1,
                            maximumFractionDigits: 1
                        }).format(returnValue) + ' °C';
                } else {
                    solarReturnText.textContent = '';
                }

                solarReturnText.style.setProperty(
                    'display',
                    'inline',
                    'important'
                );
                solarReturnText.style.setProperty(
                    'visibility',
                    'visible',
                    'important'
                );
            } else if (solarReturnText) {
                solarReturnText.textContent = '';
                solarReturnText.style.setProperty(
                    'display',
                    'none',
                    'important'
                );
                solarReturnText.style.setProperty(
                    'visibility',
                    'hidden',
                    'important'
                );
            }

            /*
             * Die Original-SVG verwendet für die Solar-Wendel lediglich ein <use>
             * in gThermalSolar. Dieses liegt in der SVG-Reihenfolge HINTER der
             * später gezeichneten, gefüllten Boilerfläche und kann deshalb
             * vollständig verdeckt werden.
             *
             * Wir erzeugen daher einen eigenständigen Pfad direkt in gTankWW,
             * unmittelbar NACH dem Boiler-Chassis. So liegt die Solar-Wendel
             * sichtbar über der Speicherfüllung, aber weiterhin sauber innerhalb
             * des Boilers.
             */
            const tankGroup = svg.querySelector('#gTankWW');
            const tankChassis = svg.querySelector('#pathTankWWChassis');
            const sourceCoil = svg.querySelector('#pathPipeHotColdHeatpump');
            const originalSolarUse = svg.querySelector('#usePathPipeThermalSolarTank');

            let solarCoil = svg.querySelector('#symconThermalSolarTankCoil');

            if (!solarCoil && tankGroup && tankChassis && sourceCoil) {
                solarCoil = sourceCoil.cloneNode(false);
                solarCoil.setAttribute('id', 'symconThermalSolarTankCoil');
                solarCoil.setAttribute('transform', 'translate(193,90)');
                solarCoil.removeAttribute('style');

                if (tankChassis.nextSibling) {
                    tankGroup.insertBefore(solarCoil, tankChassis.nextSibling);
                } else {
                    tankGroup.appendChild(solarCoil);
                }
            }

            /*
             * Die ursprüngliche <use>-Kopie aus gThermalSolar unterdrücken,
             * damit bei halbtransparentem Original-Speicher keine doppelte
             * Wendel sichtbar wird.
             */
            if (originalSolarUse) {
                originalSolarUse.style.setProperty('display', 'none', 'important');
                originalSolarUse.style.setProperty('visibility', 'hidden', 'important');
            }

            if (solarCoil) {
                solarCoil.style.setProperty('display', 'inline', 'important');
                solarCoil.style.setProperty('visibility', 'visible', 'important');
                solarCoil.style.setProperty('fill', 'none', 'important');
                solarCoil.style.setProperty('stroke-width', '5', 'important');
                solarCoil.style.setProperty('stroke-opacity', '1', 'important');
            }

            /*
             * Kurzes heißes Verbindungsstück zwischen Solar-Vorlauf und
             * Solar-Wendel. In der Original-SVG kann dieser Bereich durch die
             * später gezeichnete Boilerfläche optisch unterbrochen werden.
             *
             * Deshalb als eigener Pfad direkt in gTankWW und damit oberhalb der
             * Speicherfüllung zeichnen.
             */
            let solarHotConnector =
                svg.querySelector('#symconThermalSolarHotConnector');

            if (!solarHotConnector && tankGroup) {
                solarHotConnector = document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'path'
                );
                solarHotConnector.setAttribute(
                    'id',
                    'symconThermalSolarHotConnector'
                );

                /*
                 * Eintritt der Solar-Wendel liegt bei y=540.
                 * Das kurze Stück verbindet Boilerwand (x=745) mit dem
                 * vorhandenen Solar-Vorlauf/Wendelanschluss (x=791).
                 */
                solarHotConnector.setAttribute(
                    'd',
                    'M 745 540 H 791'
                );
                solarHotConnector.setAttribute('fill', 'none');

                tankGroup.appendChild(solarHotConnector);
            }

            if (solarHotConnector) {
                solarHotConnector.style.setProperty(
                    'display',
                    'inline',
                    'important'
                );
                solarHotConnector.style.setProperty(
                    'visibility',
                    'visible',
                    'important'
                );
                solarHotConnector.style.setProperty(
                    'stroke-width',
                    '5',
                    'important'
                );
                solarHotConnector.style.setProperty(
                    'stroke-opacity',
                    '1',
                    'important'
                );
                solarHotConnector.style.setProperty(
                    'fill',
                    'none',
                    'important'
                );
            }

            /*
             * Bei abgeschalteten eigenen Temperaturfarben bleibt die originale
             * Farbgebung der Heat-Pump-Card bestehen.
             */
            if (!false) {
                [
                    '#pathPipeThermalSolarHotWater',
                    '#pathPipeThermalSolarColdWater',
                    '#rectThermalSolarPanel',
                    '#symconThermalSolarTankCoil'
                ].forEach((selector) => {
                    const element = svg.querySelector(selector);
                    if (!element) {
                        return;
                    }

                    element.style.removeProperty('stroke');
                    element.style.removeProperty('stroke-opacity');
                    element.style.removeProperty('fill');
                    element.style.removeProperty('fill-opacity');
                });

                if (solarCoil) {
                    solarCoil.style.setProperty(
                        'stroke',
                        'url(#linearGradientPipe1)',
                        'important'
                    );
                    solarCoil.style.setProperty(
                        'stroke-opacity',
                        '1',
                        'important'
                    );
                }

                if (solarHotConnector) {
                    solarHotConnector.style.setProperty(
                        'stroke',
                        '#ff0000',
                        'important'
                    );
                }

                return;
            }

            // Kollektortemperatur bleibt weiterhin als Messwert der Card erhalten.
            // Für die Farbrichtung des Panels sind aber Solar-Vorlauf und
            // Solar-Rücklauf maßgebend.
            const panelTemperature =
                readStateNumber(currentConfig.thermalSolarPanelTemp);

            const supplyTemperature =
                readStateNumber(currentConfig.thermalSolarFluxTemp);

            /*
             * Solar-Rücklauf ist neu optional konfigurierbar.
             * Fehlt er, verwenden wir die untere Boilertemperatur als sinnvolle
             * Näherung für das Fluid, das die Solar-Wendel wieder verlässt.
             */
            const returnTemperature =
                readStateNumber(currentConfig.thermalSolarReturnTemp)
                ?? readStateNumber(currentConfig.tankTempWWDown)
                ?? readStateNumber(currentConfig.tankTempWWMiddle)
                ?? readStateNumber(currentConfig.tankTempWWUp);

            void panelTemperature;

            const supplyColor = temperatureColor(supplyTemperature);
            const returnColor = temperatureColor(returnTemperature);

            const setStroke = (selector, color) => {
                const element = svg.querySelector(selector);
                if (!element || !color) {
                    return;
                }

                element.style.setProperty('stroke', color, 'important');
                element.style.setProperty('stroke-opacity', '1', 'important');
            };


            /*
             * Solarleitungen:
             * - pathPipeThermalSolarHotWater ist der heiße Vorlauf vom Panel
             *   zum Boiler.
             * - pathPipeThermalSolarColdWater ist der kühlere Rücklauf vom
             *   Boiler zurück zum Panel.
             */
            setStroke('#pathPipeThermalSolarHotWater', supplyColor);
            setStroke('#pathPipeThermalSolarColdWater', returnColor);
            setStroke('#symconThermalSolarHotConnector', supplyColor);

            /*
             * Solarpanel:
             * Die Originalgeometrie läuft von links (heißer Ausgang/Vorlauf)
             * nach rechts (kühler Eingang/Rücklauf).
             *
             * Daher muss der Panelverlauf zwingend sein:
             *   links  = Solar-Vorlauffarbe
             *   rechts = Solar-Rücklauffarbe
             *
             * Die Kollektortemperatur bleibt ein eigener Messwert und wird NICHT
             * mehr als Endfarbe des Panels verwendet.
             */
            let panelGradient = svg.querySelector(
                '#symconLinearGradientThermalSolarPanel'
            );

            if (!panelGradient) {
                const originalPanelGradient =
                    svg.querySelector('#linearGradient3');

                if (originalPanelGradient) {
                    panelGradient = originalPanelGradient.cloneNode(true);
                    panelGradient.setAttribute(
                        'id',
                        'symconLinearGradientThermalSolarPanel'
                    );

                    panelGradient.querySelectorAll('stop').forEach(
                        (stop, index) => {
                            stop.setAttribute(
                                'id',
                                'symconThermalSolarPanelStop' + (index + 1)
                            );
                        }
                    );

                    originalPanelGradient.parentNode.appendChild(panelGradient);
                }
            }

            if (panelGradient && supplyColor && returnColor) {
                const panelStops = panelGradient.querySelectorAll('stop');

                if (panelStops.length >= 2) {
                    panelStops[0].style.setProperty(
                        'stop-color',
                        supplyColor,
                        'important'
                    );
                    panelStops[0].setAttribute(
                        'stop-color',
                        supplyColor
                    );

                    panelStops[panelStops.length - 1].style.setProperty(
                        'stop-color',
                        returnColor,
                        'important'
                    );
                    panelStops[panelStops.length - 1].setAttribute(
                        'stop-color',
                        returnColor
                    );
                }

                const panel = svg.querySelector('#rectThermalSolarPanel');

                if (panel) {
                    panel.style.setProperty(
                        'fill',
                        'url(#symconLinearGradientThermalSolarPanel)',
                        'important'
                    );
                    panel.style.setProperty(
                        'fill-opacity',
                        '1',
                        'important'
                    );
                }
            }

            /*
             * Eigener Gradient nur für den eigenständigen Solar-Wendel-Pfad.
             * Damit beeinflusst Solar weder die Wärmepumpen- noch die normale
             * Boiler-Wendel.
             */
            let gradient = svg.querySelector('#symconLinearGradientThermalSolar');

            if (!gradient) {
                const sourceGradient = svg.querySelector('#linearGradientPipe1');

                if (sourceGradient) {
                    gradient = sourceGradient.cloneNode(true);
                    gradient.setAttribute(
                        'id',
                        'symconLinearGradientThermalSolar'
                    );

                    gradient.querySelectorAll('stop').forEach((stop, index) => {
                        stop.setAttribute(
                            'id',
                            'symconThermalSolarStop' + (index + 1)
                        );
                    });

                    sourceGradient.parentNode.appendChild(gradient);
                }
            }

            if (gradient && supplyColor && returnColor) {
                const stops = gradient.querySelectorAll('stop');

                if (stops.length >= 2) {
                    stops[0].style.setProperty(
                        'stop-color',
                        supplyColor,
                        'important'
                    );
                    stops[0].setAttribute('stop-color', supplyColor);

                    stops[stops.length - 1].style.setProperty(
                        'stop-color',
                        returnColor,
                        'important'
                    );
                    stops[stops.length - 1].setAttribute(
                        'stop-color',
                        returnColor
                    );
                }

                if (solarCoil) {
                    solarCoil.style.setProperty(
                        'stroke',
                        'url(#symconLinearGradientThermalSolar)',
                        'important'
                    );
                    solarCoil.style.setProperty(
                        'stroke-opacity',
                        '1',
                        'important'
                    );
                }
            }
        };

        const positionHeatingCircuitTemperatures = (card) => {
            if (!card || !card.content) {
                return;
            }

            /*
             * Durch die einheitliche Anzeige inklusive "°C" benötigt der
             * Vorlaufwert am Heizkörper / an der Fußbodenheizung etwas mehr
             * Platz. Deshalb nur die Vorlauftexte leicht nach links schieben.
             */
            [
                '#textSupplyTemperatureHeating',
                '#textSupplyTemperatureHeating2',
                '#textSupplyTemperatureHeating3'
            ].forEach((selector) => {
                const element = card.content.querySelector(selector);
                if (!element) {
                    return;
                }

                if (!element.hasAttribute('data-symcon-original-x')) {
                    element.setAttribute(
                        'data-symcon-original-x',
                        element.getAttribute('x') || ''
                    );
                }

                const originalX = Number(
                    element.getAttribute('data-symcon-original-x')
                );

                if (Number.isFinite(originalX)) {
                    element.setAttribute('x', String(originalX - 10));
                } else {
                    element.setAttribute('transform', 'translate(-10 0)');
                }
            });
        };

        const ensureFlowOverlay = (
            svg,
            selector,
            overlayId,
            direction = 'forward',
            active = true
        ) => {
            const source = svg.querySelector(selector);

            if (!source) {
                return;
            }

            let overlay = svg.querySelector('#' + overlayId);

            if (!overlay) {
                overlay = source.cloneNode(false);
                overlay.setAttribute('id', overlayId);
                overlay.removeAttribute('style');

                if (source.parentNode) {
                    source.parentNode.insertBefore(
                        overlay,
                        source.nextSibling
                    );
                }
            }

            overlay.setAttribute(
                'class',
                'symcon-flow-overlay '
                + (direction === 'reverse'
                    ? 'symcon-flow-reverse'
                    : 'symcon-flow-forward')
            );

            overlay.style.setProperty(
                'display',
                active ? 'inline' : 'none',
                'important'
            );
            overlay.style.setProperty(
                'visibility',
                active ? 'visible' : 'hidden',
                'important'
            );
        };

        const removeFlowOverlays = (svg) => {
            svg.querySelectorAll('.symcon-flow-overlay').forEach(
                (element) => {
                    if (element && element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                }
            );
        };
        /*
         * ROBUSTER HEIZKREIS-FLUSS FÜR HEIZKÖRPER / FUSSBODENHEIZUNG
         *
         * Die Original-SVG entscheidet selbst, ob Heizkörper oder
         * Fußbodenheizung sichtbar ist.
         *
         * WICHTIG:
         * Auch diese zusätzliche Animation folgt jetzt dem Status der
         * jeweiligen Heizkreispumpe. Steht die Pumpe, steht damit auch
         * die Animation im Heizkörper bzw. in der Fußbodenheizung.
         */

        const applyFlowAnimations = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            /*
             * Flussanimation nur zusammen mit den eigenen Temperaturfarben.
             */
            if (!false) {
                removeFlowOverlays(svg);
                return;
            }

            const compressorRunning =
                stateIsOn(currentConfig.compressorRunning);

            const circuit1Configured =
                String(currentConfig.heatingCircuitType1 || 'off') !== 'off';
            const circuit2Configured =
                String(currentConfig.heatingCircuitType2 || 'off') !== 'off';
            const circuit3Configured =
                String(currentConfig.heatingCircuitType3 || 'off') !== 'off';

            const heatingPump1 =
                circuit1Configured
                && stateIsOn(currentConfig.heatingCircuitPumpRunning);
            const heatingPump2 =
                circuit2Configured
                && stateIsOn(currentConfig.heatingCircuitPumpRunning2);
            const heatingPump3 =
                circuit3Configured
                && stateIsOn(currentConfig.heatingCircuitPumpRunning3);


            const storagePump =
                stateIsOn(currentConfig.storageChargingPumpRunning);

            /*
             * Hydraulische Freigabe der Heizkreise:
             *
             * 1) Ist KEINE Heizkreispumpe als Variable konfiguriert,
             *    übernimmt die Speicherladepumpe die Zirkulation durch die
             *    vorhandenen Heizkreise.
             *
             * 2) Sobald mindestens EINE Heizkreispumpe konfiguriert ist,
             *    wird jeder Heizkreis ausschließlich durch SEINE zugehörige
             *    Heizkreispumpe freigegeben.
             *
             * Der Betriebsmodus allein darf keinen sichtbaren Wasserfluss
             * erzeugen.
             */
            const heatingCircuitPump1Configured =
                !!currentConfig.heatingCircuitPumpRunning;
            const heatingCircuitPump2Configured =
                !!currentConfig.heatingCircuitPumpRunning2;
            const heatingCircuitPump3Configured =
                !!currentConfig.heatingCircuitPumpRunning3;

            const anyHeatingCircuitPumpConfigured =
                heatingCircuitPump1Configured
                || heatingCircuitPump2Configured
                || heatingCircuitPump3Configured;

            const circuit1Active =
                circuit1Configured
                && (
                    anyHeatingCircuitPumpConfigured
                        ? (
                            heatingCircuitPump1Configured
                            && heatingPump1
                        )
                        : storagePump
                );

            const circuit2Active =
                circuit2Configured
                && (
                    anyHeatingCircuitPumpConfigured
                        ? (
                            heatingCircuitPump2Configured
                            && heatingPump2
                        )
                        : storagePump
                );

            const circuit3Active =
                circuit3Configured
                && (
                    anyHeatingCircuitPumpConfigured
                        ? (
                            heatingCircuitPump3Configured
                            && heatingPump3
                        )
                        : storagePump
                );

            const anyHeatingActive =
                circuit1Active
                || circuit2Active
                || circuit3Active;

            const solarPump =
                stateIsOn(currentConfig.thermalSolarPump);

            /*
             * Für die hydraulische Richtung ist – wie bei unserer
             * Temperatur-/Einfrierlogik – ausschließlich das konfigurierte
             * Umschaltventil maßgebend.
             */
            const valveConfigured = !!currentConfig.wwHeatingValve;
            const valveToBoiler =
                valveConfigured
                && stateIsOn(currentConfig.wwHeatingValve);

            /*
             * ------------------------------------------------------------
             * KÄLTEKREIS
             * ------------------------------------------------------------
             * Nur EINE farbige Leitung und EIN Fluss-Overlay.
             *
             * Im Kühlbetrieb wurde der reversible Kältekreis bereits durch
             * applyRefrigerantCircuitMode() auf "reversed" gesetzt.
             * Dann muss auch die komplette gestrichelte Flussrichtung drehen.
             */
            /*
             * Animierter Kältemittel-Fluss:
             *
             * Kühlbetrieb ist aktuell korrekt und bleibt deshalb unverändert.
             * Im Heizbetrieb wird ausschließlich die gestrichelte
             * Animationsrichtung gegenüber dem Kühlbetrieb umgekehrt.
             *
             * Nicht auf das persistierende SVG-dataset verlassen, sondern den
             * aktuellen Kühlzustand direkt aus den vorhandenen Statusquellen
             * bestimmen.
             */
            const refrigerantCooling =
                stateIsOn(currentConfig.heatingPumpCoolingMode)
                || !!(
                    currentControls
                    && currentControls.coolingActive === true
                );

            const refrigerantDirection = (coolingDirection) => {
                if (refrigerantCooling) {
                    return coolingDirection;
                }

                return coolingDirection === 'reverse'
                    ? 'forward'
                    : 'reverse';
            };

            svg.querySelectorAll(
                '[id^="symconFlowRefrigerant"]'
            ).forEach((oldOverlay) => {
                if (
                    oldOverlay.id !==
                    'symconFlowRefrigerantMain'
                    && oldOverlay.parentNode
                ) {
                    oldOverlay.parentNode.removeChild(
                        oldOverlay
                    );
                }
            });

            ensureFlowOverlay(
                svg,
                '#symconRefrigerantCircuitPipe',
                'symconFlowRefrigerantMain',
                refrigerantDirection('forward'),
                compressorRunning
            );

            [
                {name: 'CompressorOuter', direction: 'reverse'},
                {name: 'CompressorInner', direction: 'reverse'},
                {name: 'EvaporatorOuter', direction: 'forward'},
                {name: 'EvaporatorInner', direction: 'forward'},
                {name: 'CondenserOuter', direction: 'forward'},
                {name: 'CondenserInner', direction: 'forward'},
                {name: 'ExpansionOuter', direction: 'forward'},
                {name: 'ExpansionInner', direction: 'forward'}
            ].forEach((definition) => {
                const name = definition.name;

                ensureFlowOverlay(
                    svg,
                    '#symconRefrigerantBridge' + name,
                    'symconFlowRefrigerantBridge' + name,
                    refrigerantDirection(definition.direction),
                    compressorRunning
                );

                const bridgeOverlay =
                    svg.querySelector(
                        '#symconFlowRefrigerantBridge' + name
                    );

                if (bridgeOverlay) {
                    bridgeOverlay.classList.add(
                        'symcon-flow-refrigerant'
                    );
                }
            });

            const refrigerantOverlay =
                svg.querySelector(
                    '#symconFlowRefrigerantMain'
                );

            if (refrigerantOverlay) {
                refrigerantOverlay.classList.add(
                    'symcon-flow-refrigerant'
                );
            }

            /*
             * Die beiden Wärmetauscher-Wendel in der Wärmepumpe explizit
             * mitnehmen. Sie werden absichtlich NICHT über die generische
             * Leitungs-Erkennung animiert.
             */
            ensureFlowOverlay(
                svg,
                '#pathHPModelEvaporatorSymbol001',
                'symconFlowEvaporatorCoil1',
                refrigerantDirection('forward'),
                compressorRunning
            );
            ensureFlowOverlay(
                svg,
                '#pathHPModelEvaporatorSymbol002',
                'symconFlowEvaporatorCoil2',
                refrigerantDirection('forward'),
                compressorRunning
            );
            ensureFlowOverlay(
                svg,
                '#pathHPModelCondenserSymbol',
                'symconFlowCondenserCoil',
                refrigerantDirection('forward'),
                compressorRunning
            );

            /*
             * ------------------------------------------------------------
             * GEMEINSAME HYDRAULIK DIREKT AN DER WÄRMEPUMPE
             * ------------------------------------------------------------
             * Diese Stücke gehören sowohl zum Heizbetrieb als auch zur
             * Boilerladung und dürfen deshalb nicht fehlen.
             */
            const hydraulicActive =
                valveToBoiler || anyHeatingActive || storagePump;

            ensureFlowOverlay(
                svg,
                '#pathPipeHotColdHeatpump',
                'symconFlowHydraulicSupply',
                'forward',
                hydraulicActive
            );

            ensureFlowOverlay(
                svg,
                '#pathPipeRefluxWW',
                'symconFlowHydraulicReturn',
                'reverse',
                hydraulicActive
            );

            /*
             * ------------------------------------------------------------
             * HEIZKREISE
             * ------------------------------------------------------------
             * Bei Boilerstellung werden ALLE Heizkreis-Flüsse hart gestoppt,
             * auch wenn eine Heizkreispumpe noch "aktiv" meldet.
             */
            const heatingAllowed = !valveToBoiler;
            const heatingFlowActive =
                heatingAllowed && anyHeatingActive;

            /*
             * GEMEINSAME HEIZLEITUNG:
             *
             * pathPipeToBuffer ist trotz des historischen Namens die
             * durchgehende Vorlaufleitung vom Umschaltventil bis zum
             * Heizkreisverteiler.
             *
             * pathPipeFromBuffer ist die durchgehende Rücklaufleitung vom
             * Heizkreisverteiler zurück zur Wärmepumpe.
             *
             * Diese beiden Stücke müssen deshalb auch im reinen Heizbetrieb
             * animiert werden – nicht nur bei einer Speicherpumpe.
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeToBuffer',
                'symconFlowHeatingCommonSupply',
                'forward',
                heatingFlowActive
            );

            /*
             * Die SVG-Pfadrichtung von pathPipeFromBuffer geht bereits
             * vom Heizkreis zurück zur Wärmepumpe. Deshalb FORWARD.
             * Das korrigiert die bisher umgekehrte Rücklaufrichtung.
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeFromBuffer',
                'symconFlowHeatingCommonReturn',
                'forward',
                heatingFlowActive
            );

            /*
             * Gemeinsamer vertikaler Rücklauf zwischen Heizkreis 1/2 und
             * dem unteren Sammelpunkt. Bei Heizkreis 1 oder 2 gehört dieses
             * Stück zwingend zur vollständigen Rücklaufkette.
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeToHP2',
                'symconFlowHeatingSharedReturn',
                'forward',
                heatingAllowed
                    && (circuit1Active || circuit2Active)
            );

            const animateEmitter = (
                number,
                enabled
            ) => {
                /*
                 * Fußbodenheizung:
                 * Die Original-Schlange ist bereits ein echter,
                 * durchgehender Wasserweg.
                 */
                const floor =
                    svg.querySelector(
                        '#pathUnderfloorHeating' + number
                    );

                if (floor) {
                    const floorParent = floor.parentElement;
                    const floorVisible =
                        !floorParent
                        || getComputedStyle(floorParent).display !== 'none';

                    ensureFlowOverlay(
                        svg,
                        '#pathUnderfloorHeating' + number,
                        'symconFlowEmitterFloor' + number,
                        'forward',
                        !!enabled && floorVisible
                    );

                    const floorOverlay =
                        svg.querySelector(
                            '#symconFlowEmitterFloor' + number
                        );

                    if (floorOverlay) {
                        floorOverlay.classList.add(
                            'symcon-flow-emitter'
                        );

                        if (floorOverlay.parentNode) {
                            floorOverlay.parentNode.appendChild(
                                floorOverlay
                            );
                        }
                    }
                }

                /*
                 * Heizkörper:
                 *
                 * #rectRadiatorN besteht aus einem Außenrahmen plus vielen
                 * einzelnen geschlossenen Rippen. Eine Dash-Animation darauf
                 * kann deshalb nicht als ein durchgehender Wasserfluss
                 * erscheinen.
                 *
                 * Wir legen im gleichen <g> einen einzigen zusammenhängenden
                 * Serpentinenpfad über alle Rippen. Bei Heizkreis 2 und 3
                 * übernimmt er automatisch den transform des Eltern-<g>.
                 */
                const radiator =
                    svg.querySelector(
                        '#rectRadiator' + number
                    );

                if (!radiator || !radiator.parentNode) {
                    return;
                }

                const radiatorParent = radiator.parentElement;
                const radiatorVisible =
                    getComputedStyle(radiatorParent).display !== 'none';

                let flowPath =
                    svg.querySelector(
                        '#symconRadiatorWaterPath' + number
                    );

                if (!flowPath) {
                    flowPath = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'path'
                    );

                    flowPath.setAttribute(
                        'id',
                        'symconRadiatorWaterPath' + number
                    );

                    /*
                     * Eintritt links oben -> durch alle Rippen ->
                     * Austritt rechts unten.
                     */
                    flowPath.setAttribute(
                        'd',
                        'M 837 82 '
                        + 'L 847 82 L 847 118 '
                        + 'L 857 118 L 857 82 '
                        + 'L 867 82 L 867 118 '
                        + 'L 877 118 L 877 82 '
                        + 'L 887 82 L 887 118 '
                        + 'L 897 118 L 897 82 '
                        + 'L 907 82 L 907 118 '
                        + 'L 917 118 L 917 82 '
                        + 'L 927 82 L 927 115 '
                        + 'L 937 115'
                    );

                    flowPath.setAttribute(
                        'fill',
                        'none'
                    );

                    /*
                     * Der Basis-Pfad selbst bleibt unsichtbar.
                     * Sichtbar ist nur sein animiertes Overlay.
                     */
                    flowPath.style.setProperty(
                        'stroke',
                        'transparent',
                        'important'
                    );
                    flowPath.style.setProperty(
                        'fill',
                        'none',
                        'important'
                    );

                    radiatorParent.appendChild(flowPath);
                }

                /*
                 * Pfad ganz nach vorne holen, damit er nicht durch
                 * Heizkörperfüllung oder andere SVG-Elemente verdeckt wird.
                 */
                if (flowPath.parentNode) {
                    flowPath.parentNode.appendChild(flowPath);
                }

                ensureFlowOverlay(
                    svg,
                    '#symconRadiatorWaterPath' + number,
                    'symconFlowEmitterRadiator' + number,
                    'forward',
                    !!enabled && radiatorVisible
                );

                const radiatorOverlay =
                    svg.querySelector(
                        '#symconFlowEmitterRadiator' + number
                    );

                if (radiatorOverlay) {
                    radiatorOverlay.classList.add(
                        'symcon-flow-emitter'
                    );

                    /*
                     * Nicht auf geerbte SVG-/CSS-Werte verlassen:
                     * der Heizkörper-Fluss bekommt seine sichtbaren
                     * Eigenschaften direkt auf dem Overlay.
                     */
                    radiatorOverlay.style.setProperty(
                        'fill',
                        'none',
                        'important'
                    );
                    radiatorOverlay.style.setProperty(
                        'stroke',
                        'rgba(255,255,255,.82)',
                        'important'
                    );
                    radiatorOverlay.style.setProperty(
                        'stroke-width',
                        '2',
                        'important'
                    );
                    radiatorOverlay.style.setProperty(
                        'stroke-dasharray',
                        '4 7',
                        'important'
                    );
                    radiatorOverlay.style.setProperty(
                        'stroke-linecap',
                        'round',
                        'important'
                    );
                    radiatorOverlay.style.setProperty(
                        'opacity',
                        '1',
                        'important'
                    );

                    if (radiatorOverlay.parentNode) {
                        radiatorOverlay.parentNode.appendChild(
                            radiatorOverlay
                        );
                    }
                }
            };

            const animateCircuit = (
                number,
                active,
                supplySelectors,
                returnSelectors
            ) => {
                const enabled = !!active && heatingAllowed;

                supplySelectors.forEach((selector, index) => {
                    const overlayId =
                        'symconFlowHeatingSupply'
                        + number + '_' + index;

                    ensureFlowOverlay(
                        svg,
                        selector,
                        overlayId,
                        'forward',
                        enabled
                    );

                    if (
                        selector.includes('Radiator')
                        || selector.includes('Underfloor')
                    ) {
                        const overlay =
                            svg.querySelector('#' + overlayId);

                        if (overlay) {
                            overlay.classList.add(
                                'symcon-flow-emitter'
                            );
                        }
                    }
                });

                returnSelectors.forEach((selector, index) => {
                    const overlayId =
                        'symconFlowHeatingReturn'
                        + number + '_' + index;

                    ensureFlowOverlay(
                        svg,
                        selector,
                        overlayId,
                        'forward',
                        enabled
                    );

                    if (selector.includes('Radiator')) {
                        const overlay =
                            svg.querySelector('#' + overlayId);

                        if (overlay) {
                            overlay.classList.add(
                                'symcon-flow-emitter'
                            );
                        }
                    }
                });
            };

            animateCircuit(
                1,
                circuit1Active,
                [
                    '#pathPipeToHeatingCircuitPump',
                    '#pathRadiatorPipeIn1'
                ],
                [
                    '#pathPipeToHP',
                    '#pathRadiatorPipeOut1'
                ]
            );
            animateEmitter(
                1,
                circuit1Active && heatingAllowed
            );

            animateCircuit(
                2,
                circuit2Active,
                [
                    '#pathPipeToHeatingCircuitPump2',
                    '#pathRadiatorPipeIn2'
                ],
                [
                    '#pathRadiatorPipeOut2'
                ]
            );
            animateEmitter(
                2,
                circuit2Active && heatingAllowed
            );

            animateCircuit(
                3,
                circuit3Active,
                [
                    '#pathPipeToHeatingCircuitPump3',
                    '#pathRadiatorPipeIn3'
                ],
                [
                    '#pathRadiatorPipeOut3'
                ]
            );
            animateEmitter(
                3,
                circuit3Active && heatingAllowed
            );

            /*
             * ------------------------------------------------------------
             * BOILER / WARMWASSER
             * ------------------------------------------------------------
             * Bei Boilerstellung läuft der Fluss ausschließlich über diesen
             * Zweig. Der Boiler-Wendel selbst wird ebenfalls animiert.
             */
            /*
             * Boiler-Wendel:
             * Die SVG-Pfadrichtung läuft entgegengesetzt zur gewünschten
             * hydraulischen Flussrichtung, deshalb hier bewusst "reverse".
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeHotWaterToTank',
                'symconFlowBoilerCoil',
                'reverse',
                valveToBoiler
            );

            /*
             * Keine Flussanimation auf der Warmwasserleitung vom Boiler
             * zum Wasserhahn / zur Zapfstelle.
             * Ein eventuell noch vorhandenes Overlay aus einer früheren
             * Aktualisierung wird explizit ausgeblendet.
             */
            const faucetFlow = svg.querySelector('#symconFlowBoilerReturn');
            if (faucetFlow) {
                faucetFlow.style.setProperty(
                    'display',
                    'none',
                    'important'
                );
                faucetFlow.style.setProperty(
                    'visibility',
                    'hidden',
                    'important'
                );
            }

            /*
             * ------------------------------------------------------------
             * PUFFERSPEICHER
             * ------------------------------------------------------------
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeToBuffer',
                'symconFlowBufferSupply',
                'forward',
                storagePump && !valveToBoiler && !anyHeatingActive
            );

            ensureFlowOverlay(
                svg,
                '#pathPipeFromBuffer',
                'symconFlowBufferReturn',
                'forward',
                storagePump && !valveToBoiler && !anyHeatingActive
            );

            ensureFlowOverlay(
                svg,
                '#pathPipeBufferToHeating',
                'symconFlowBufferToHeating',
                'forward',
                storagePump && anyHeatingActive && !valveToBoiler
            );

            ensureFlowOverlay(
                svg,
                '#pathPipeHeatingToBuffer',
                'symconFlowHeatingToBuffer',
                'reverse',
                storagePump && anyHeatingActive && !valveToBoiler
            );

            /*
             * ------------------------------------------------------------
             * SOLARTHERMIE
             * ------------------------------------------------------------
             */
            ensureFlowOverlay(
                svg,
                '#pathPipeThermalSolarHotWater',
                'symconFlowSolarSupply',
                'forward',
                solarPump
            );

            ensureFlowOverlay(
                svg,
                '#pathPipeThermalSolarColdWater',
                'symconFlowSolarReturn',
                'reverse',
                solarPump
            );

            ensureFlowOverlay(
                svg,
                '#symconThermalSolarTankCoil',
                'symconFlowSolarCoil',
                'forward',
                solarPump
            );

            ensureFlowOverlay(
                svg,
                '#symconThermalSolarHotConnector',
                'symconFlowSolarConnector',
                'forward',
                solarPump
            );
        };

        const applySingleCircuitTemperatureDisplay = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            /*
             * Diese Sonderfunktion kann in Heizkreis 1 abgeschaltet werden.
             * Aus = normale Card-Darstellung ohne Einfrieren und ohne die
             * zusätzlichen Vor-/Rücklaufwerte am Warmwasserspeicher.
             */
            if (!currentConfig.singleCircuitHotWaterTemperatureSwitch) {
                [
                    '#symconBoilerSupplyTemperature',
                    '#symconBoilerRefluxTemperature'
                ].forEach((selector) => {
                    const element = svg.querySelector(selector);
                    if (element && element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                });
                return;
            }

            /*
             * Diese Erweiterung gilt bewusst nur bei genau EINEM Heizkreis.
             */
            const circuits = [
                {
                    key: '1',
                    type: String(currentConfig.heatingCircuitType1 || 'off'),
                    supply: currentConfig.supplyTemperatureHeating,
                    reflux: currentConfig.refluxTemperatureHeating,
                    supplyText: '#textSupplyTemperatureHeating',
                    refluxText: '#textRefluxTemperatureHeating'
                },
                {
                    key: '2',
                    type: String(currentConfig.heatingCircuitType2 || 'off'),
                    supply: currentConfig.supplyTemperatureHeating2,
                    reflux: currentConfig.refluxTemperatureHeating2,
                    supplyText: '#textSupplyTemperatureHeating2',
                    refluxText: '#textRefluxTemperatureHeating2'
                },
                {
                    key: '3',
                    type: String(currentConfig.heatingCircuitType3 || 'off'),
                    supply: currentConfig.supplyTemperatureHeating3,
                    reflux: currentConfig.refluxTemperatureHeating3,
                    supplyText: '#textSupplyTemperatureHeating3',
                    refluxText: '#textRefluxTemperatureHeating3'
                }
            ];

            const activeCircuits = circuits.filter(
                (circuit) => circuit.type !== 'off'
            );

            const tankGroup = svg.querySelector('#gTankWW');

            /*
             * Zusatztexte am Boiler entfernen, wenn mehr oder weniger als
             * ein Heizkreis aktiv ist.
             */
            const removeBoilerTexts = () => {
                [
                    '#symconBoilerSupplyTemperature',
                    '#symconBoilerRefluxTemperature'
                ].forEach((selector) => {
                    const element = svg.querySelector(selector);
                    if (element && element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                });
            };

            if (activeCircuits.length !== 1 || !tankGroup) {
                removeBoilerTexts();
                return;
            }

            const circuit = activeCircuits[0];

            /*
             * Allein die Stellung des konfigurierten Umschaltventils ist
             * maßgebend:
             *   aktiv   = Boiler
             *   inaktiv = Heizung
             *
             * Ohne Ventil gibt es keine Einfrier-/Umschaltlogik.
             */
            const valveConfigured = !!currentConfig.wwHeatingValve;
            const boilerActive =
                valveConfigured && stateIsOn(currentConfig.wwHeatingValve);

            const supplyTemperature = readStateNumber(circuit.supply);
            const refluxTemperature = readStateNumber(circuit.reflux);

            const formatTemperature = (value) => {
                if (value === null || !Number.isFinite(Number(value))) {
                    return '';
                }

                return new Intl.NumberFormat('de-CH', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                }).format(Number(value)) + ' °C';
            };

            /*
             * Im Heizbetrieb die letzten echten Heizkreiswerte speichern.
             * Beim Boilerbetrieb werden diese Werte nicht mehr überschrieben.
             */
            if (
                !boilerActive
                && supplyTemperature !== null
                && refluxTemperature !== null
            ) {
                storedHeatingTemperatures[circuit.key] = {
                    supply: supplyTemperature,
                    reflux: refluxTemperature
                };

                saveStoredHeatingTemperatures(storedHeatingTemperatures);
            }

            /*
             * Beim Umschalten auf Boiler bleiben die Temperaturtexte am
             * Heizkörper / an der Fußbodenheizung auf den letzten Heizwerten.
             */
            if (boilerActive) {
                const stored = storedHeatingTemperatures[circuit.key];

                if (stored) {
                    const supplyText = svg.querySelector(circuit.supplyText);
                    const refluxText = svg.querySelector(circuit.refluxText);

                    if (supplyText) {
                        supplyText.textContent =
                            formatTemperature(stored.supply);
                    }

                    if (refluxText) {
                        refluxText.textContent =
                            formatTemperature(stored.reflux);
                    }
                }
            }


            // Only configured storage sensors belong on the boiler. Heating
            // circuit supply/return readings are not boiler sensor readings.
            removeBoilerTexts();

        };

        const applyTerminology = (card) => {
            if (!card || !card.content) {
                return;
            }

            /*
             * Vor JEDEM Datenupdate zuerst den normalen Grundzustand
             * herstellen. applyRefrigerantCircuitMode() vertauscht diese
             * beiden Texte anschließend im Kühlbetrieb.
             *
             * Wichtig: Beide Texte zurücksetzen. Würde nur der Kondensator
             * zurückgesetzt, bleibt der beim vorherigen Kühl-Update bereits
             * vertauschte Verdampfer-Text stehen und nach dem nächsten Tausch
             * hätten beide Wärmetauscher dieselbe Bezeichnung.
             */
            const evaporatorText =
                card.content.querySelector('#textEvaporator');
            if (evaporatorText) {
                evaporatorText.textContent = 'Verdampfer';
            }

            const condenserText =
                card.content.querySelector('#textCondenser');
            if (condenserText) {
                condenserText.textContent = 'Kondensator';
            }
        };

        const applyOptionalStatusVisibility = (card) => {
            if (!card || !card.content) {
                return;
            }

            const setConfiguredVisibility = (selector, configured) => {
                const element = card.content.querySelector(selector);
                if (!element) {
                    return;
                }

                if (!configured) {
                    element.style.setProperty('display', 'none', 'important');
                    element.style.setProperty('visibility', 'hidden', 'important');
                } else {
                    element.style.removeProperty('visibility');
                    element.style.removeProperty('display');
                }
            };

            setConfiguredVisibility('#gWarning', false);

            // Wärmepumpe Ein/Aus:
            // Ohne Datenpunkt kein "Aus"-Symbol anzeigen.
            setConfiguredVisibility(
                '#gHPStatusOff',
                !!currentConfig.heatingPumpStatusOnOff
            );


            // Warmwasser-/Heizungs-Umschaltventil:
            // Die Original-Card zeichnet es in Grundstellung auch ohne Datenpunkt.
            // Ohne Zuordnung soll das Ventilsymbol vollständig verschwinden.
            if (!currentConfig.wwHeatingValve) {
                setConfiguredVisibility('#gWWHeatingValve', false);
            }
        };



        const applyHeaterRodInfoField = (card) => {
            if (!card || !card.content) return;
            const svg = card.content;
            const count = Math.max(0, Math.min(3, Number(currentConfig.heaterRodCount || 0)));
            const entities = [currentConfig.heaterRod1, currentConfig.heaterRod2, currentConfig.heaterRod3];
            const thresholds = [
                Number(currentConfig.heaterRod1Threshold || 1),
                Number(currentConfig.heaterRod2Threshold || 1),
                Number(currentConfig.heaterRod3Threshold || 1)
            ];
            let active = 0;
            for (let i = 0; i < count; i++) {
                const entity = entities[i];
                if (!entity || !currentData || !currentData[entity]) continue;
                const value = currentData[entity].value;
                let on = false;
                if (typeof value === 'boolean') {
                    on = value;
                } else {
                    const text = String(value ?? '').trim().toLowerCase();
                    if (['true','on','yes','ja','ein','active','aktiv'].includes(text)) on = true;
                    else if (!['false','off','no','nein','aus','inactive','inaktiv',''].includes(text)) {
                        const numeric = Number(value);
                        on = Number.isFinite(numeric) && numeric >= thresholds[i];
                    }
                }
                if (on) active++;
            }
            const text = svg.querySelector('#textHeaterRodStatus');
            if (text) text.textContent = active > 0 ? String(active) : 'Aus';
            const waves = svg.querySelector('#gHeaterRodWaves');
            if (waves) waves.style.display = active > 0 ? 'inline' : 'none';
            for (let i = 1; i <= 3; i++) {
                const wave = svg.querySelector('#heaterWave' + i);
                if (wave) wave.style.display = i <= active ? 'inline' : 'none';
            }
        };

        const applyThreeHeaterRods = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;

            /*
             * Aktive elektrische Heizstäbe pulsieren dezent zwischen
             * Orange und Rot. Nur die Darstellung wird animiert; die
             * vorhandene Aktivierungs-/Schwellwertlogik bleibt unverändert.
             */
            let heaterRodStyle =
                svg.querySelector('#symconHeaterRodPulseStyle');

            if (!heaterRodStyle) {
                heaterRodStyle = document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'style'
                );
                heaterRodStyle.setAttribute(
                    'id',
                    'symconHeaterRodPulseStyle'
                );
                heaterRodStyle.textContent = `
                    @keyframes symconHeaterRodPulse {
                        0%, 100% {
                            stroke: #ff9800;
                            filter:
                                drop-shadow(0 0 1.5px rgba(0,0,0,.95))
                                drop-shadow(0 0 1px rgba(255,152,0,.35));
                        }
                        50% {
                            stroke: #f04444;
                            filter:
                                drop-shadow(0 0 1.5px rgba(0,0,0,.95))
                                drop-shadow(0 0 3px rgba(240,68,68,.55));
                        }
                    }

                    .symcon-heater-rod-active {
                        animation:
                            symconHeaterRodPulse 1.8s ease-in-out infinite;
                    }
                `;
                svg.insertBefore(
                    heaterRodStyle,
                    svg.firstChild
                );
            }
            const tankGroup = svg.querySelector('#gTankWW');
            const original = svg.querySelector('#pathHeaterRodWW');

            if (!tankGroup || !original) {
                return;
            }

            const heaterRodCount = Math.max(
                0,
                Math.min(3, Number(currentConfig.heaterRodCount || 0))
            );

            /*
             * Der einzelne Original-WW-Heizstab wird grundsätzlich unterdrückt.
             * Sonst würde er bei Anzahl 0 durch heaterRodWW wieder erscheinen.
             */
            original.style.setProperty('display', 'none', 'important');
            original.style.setProperty('visibility', 'hidden', 'important');

            const ensureRod = (id, y) => {
                let rod = svg.querySelector('#' + id);

                if (!rod) {
                    rod = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    rod.setAttribute('id', id);
                    rod.setAttribute('fill', 'none');
                    tankGroup.appendChild(rod);
                }

                /*
                 * Position bei jeder Aktualisierung neu setzen. So rutschen die
                 * Heizstäbe beim Ändern der Anzahl sofort von unten nach oben.
                 */
                /*
                 * Heizstäbe an der LINKEN Boilerwand:
                 * Eintritt/Austritt liegen bei x=655 und die Schleife ragt
                 * nach rechts in den Speicher. Dadurch bleibt die rechte Seite
                 * für Solar-/Boiler-Wendel und Temperaturtexte frei.
                 */
                rod.setAttribute(
                    'd',
                    'M 655 ' + y + ' H 695 C 701 ' + y + ' 701 ' + (y - 10)
                    + ' 695 ' + (y - 10) + ' H 655'
                );

                rod.style.setProperty('fill', 'none', 'important');
                rod.style.setProperty('stroke-width', '5', 'important');
                rod.style.setProperty('stroke-linecap', 'round', 'important');
                rod.style.setProperty('stroke-linejoin', 'round', 'important');

                return rod;
            };

            /*
             * Die drei Positionen liegen gut verteilt im WW-Speicher.
             * Inaktiv bleibt der vorhandene Stab sichtbar, aber dezent.
             * Aktiv erhält jede Stufe eine klar erkennbare warme Farbe.
             */
            /*
             * Die vorhandenen Heizstäbe werden immer von unten nach oben angeordnet:
             * 1 Heizstab  -> 625 (ganz unten)
             * 2 Heizstäbe -> 595 / 625
             * 3 Heizstäbe -> 565 / 595 / 625
             */
            const rodPositions = {
                1: [610],
                2: [580, 610],
                3: [550, 580, 610]
            };

            const configuredPositions = rodPositions[heaterRodCount] || [];
            const rodEntities = [
                currentConfig.heaterRod1,
                currentConfig.heaterRod2,
                currentConfig.heaterRod3
            ];
            const rodThresholds = [
                currentConfig.heaterRod1Threshold,
                currentConfig.heaterRod2Threshold,
                currentConfig.heaterRod3Threshold
            ];
            const activeRodColor =
                temperatureColor(60) || '#f04444';
            const rodColors = [activeRodColor, activeRodColor, activeRodColor];

            const rods = [0, 1, 2].map((index) => ({
                element: ensureRod(
                    'pathHeaterRodWW' + (index + 1),
                    configuredPositions[index] || 610
                ),
                entity: rodEntities[index],
                threshold: rodThresholds[index],
                activeColor: rodColors[index]
            }));

            const isActive = (entity, threshold) => {
                if (!entity || !currentData || !currentData[entity]) {
                    return false;
                }

                const raw = currentData[entity].value;
                const value = String(raw ?? '').trim().toLowerCase();
                const limit = Number.isFinite(Number(threshold)) ? Number(threshold) : 1;

                if (typeof raw === 'boolean') {
                    return raw;
                }
                if (['true', 'on', 'yes', 'ja', 'ein', 'active', 'aktiv'].includes(value)) return true;
                if (['false', 'off', 'no', 'nein', 'aus', 'inactive', 'inaktiv', ''].includes(value)) return false;
                const numeric = Number(raw);
                return Number.isFinite(numeric) && numeric >= limit;
            };

            rods.forEach((rodInfo, index) => {
                const exists = index < heaterRodCount;

                if (!exists) {
                    rodInfo.element.classList.remove(
                        'symcon-heater-rod-active'
                    );
                    rodInfo.element.style.setProperty('display', 'none', 'important');
                    rodInfo.element.style.setProperty('visibility', 'hidden', 'important');
                    return;
                }

                const active = isActive(rodInfo.entity, rodInfo.threshold);

                rodInfo.element.style.setProperty('display', 'block', 'important');
                rodInfo.element.style.setProperty('visibility', 'visible', 'important');
                if (active) {
                    /*
                     * Keine Inline-Stroke-Farbe mit !important setzen:
                     * die Farbe kommt bei aktivem Stab aus der Pulsanimation.
                     */
                    rodInfo.element.style.removeProperty('stroke');
                    rodInfo.element.style.removeProperty('filter');
                    rodInfo.element.classList.add(
                        'symcon-heater-rod-active'
                    );
                } else {
                    rodInfo.element.classList.remove(
                        'symcon-heater-rod-active'
                    );
                    rodInfo.element.style.setProperty(
                        'stroke',
                        'rgba(255,255,255,0.55)',
                        'important'
                    );
                    rodInfo.element.style.setProperty(
                        'filter',
                        'drop-shadow(0 0 1.5px rgba(0,0,0,0.95))',
                        'important'
                    );
                }
            });
        };



        /*
         * Die obere Statusleiste der Original-SVG besitzt neun fest
         * vorgesehene Icon-Slots.
         *
         * Gemessen an der unveränderten Original-SVG (0.9.0):
         *   1: 102.85
         *   2: 174.49
         *   3: 242.98
         *   4: 301.14
         *   5: 353.41
         *   6: 412.06
         *   7: 469.65
         *   8: 527.56
         *   9: 587.85
         *
         * Die tatsächliche Breite der Icon-Leiste wird zur Laufzeit direkt
         * aus den Originalpositionen der Card ermittelt. Alle sichtbaren Icons
         * werden innerhalb genau dieses Bereichs gleichmäßig verteilt.
         * Tag/Nacht gehört NICHT zu dieser Reihe und wird daher bewusst
         * nicht verschoben.
         */
        const TOP_ICON_SLOTS = [
            102.85,
            174.49,
            242.98,
            301.14,
            353.41,
            412.06,
            469.65,
            527.56,
            587.85
        ];


        const applySetpointIcons = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const settings = svg.querySelector('#gSettings');

            if (!settings) {
                return;
            }

            const createIcon = (
                id,
                shortLabel,
                functionName
            ) => {
                let group = svg.querySelector('#' + id);

                if (!group) {
                    group = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'g'
                    );
                    group.setAttribute('id', id);

                    const circle = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'circle'
                    );
                    circle.setAttribute('cx', '0');
                    circle.setAttribute('cy', '0');
                    circle.setAttribute('r', '17');
                    circle.setAttribute('fill', 'none');
                    circle.setAttribute('stroke-width', '2.6');

                    /*
                     * Zusatz-Sollwerticon in derselben Größe wie die
                     * Original-Icons. Im Kreis steht ausschließlich der
                     * Zahlenwert – ohne WW, ±, Einheit oder Gradzeichen.
                     */
                    const value = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'text'
                    );
                    value.setAttribute('id', id + 'Value');
                    value.setAttribute('x', '0');
                    value.setAttribute('y', '0');
                    value.setAttribute('text-anchor', 'middle');
                    value.setAttribute('dominant-baseline', 'central');
                    value.setAttribute('alignment-baseline', 'central');
                    value.setAttribute('font-size', '11');
                    value.setAttribute('font-weight', '700');

                    group.appendChild(circle);
                    group.appendChild(value);
                    settings.appendChild(group);

                    group.style.setProperty(
                        'cursor',
                        'pointer',
                        'important'
                    );
                    group.style.setProperty(
                        'pointer-events',
                        'all',
                        'important'
                    );

                    bindTap(
                        group,
                        (event) =>
                            openNumericMenu(
                                functionName,
                                event
                            )
                    );
                }

                return group;
            };

            const definitions = [
                {
                    id: 'gSymconWarmWaterSetpoint',
                    label: 'WW',
                    functionName: 'warmWaterSetpoint'
                },
                {
                    id: 'gSymconHeatingCorrection',
                    label: '±',
                    functionName: 'heatingTemperatureCorrection'
                }
            ];

            definitions.forEach((definition) => {
                const control =
                    currentControls
                    && currentControls[definition.functionName];

                const group = createIcon(
                    definition.id,
                    definition.label,
                    definition.functionName
                );

                const visible = !!(
                    control
                    && control.configured
                );

                group.style.setProperty(
                    'display',
                    visible ? 'inline' : 'none',
                    'important'
                );

                if (!visible) {
                    return;
                }

                const color = resolveLayoutTextColor();
                setIconColor(group, color);

                const valueElement = svg.querySelector(
                    '#' + definition.id + 'Value'
                );

                if (valueElement) {
                    valueElement.textContent =
                        control.formattedValue !== undefined && control.formattedValue !== null
                            ? String(control.formattedValue)
                            : String(control.currentValue ?? '');
                }
            });
        };

        const layoutTopIconBar = (card) => {
            if (!card || !card.content) {
                return;
            }

            const svg = card.content;
            const settings = svg.querySelector('#gSettings');
            const frame = svg.querySelector('#rectSettings');

            if (!settings || !frame) {
                return;
            }

            /*
             * EINHEITLICHES LAYOUT FÜR DESKTOP UND HANDY
             * ------------------------------------------------------------
             * Keine getBBox-/getCTM-Messung mehr.
             * Keine Mobile-Sonderbehandlung mehr.
             * Keine Abhängigkeit von Fenster- oder Bildschirmbreite.
             *
             * Sämtliche Icons werden ausschließlich in den festen
             * Koordinaten der Original-SVG angeordnet. Die SVG skaliert
             * danach selbst auf Desktop oder Handy.
             */

            const definitions = [
                {
                    selector: '#gHPStatusOff',
                    custom: false,
                    originalX: 101.371
                },
                {
                    selector: '#gHPStatusWW',
                    custom: false,
                    originalX: 158.047
                },
                {
                    selector: '#gHPStatusHeating',
                    custom: false,
                    originalX: 212.235
                },
                {
                    selector: '#gHPStatusCooling',
                    custom: false,
                    originalX: 258.245
                },
                {
                    selector: '#gDefrost',
                    custom: false,
                    originalX: 299.602
                },
                {
                    selector: '#gAdditionalHeating',
                    custom: false,
                    originalX: 346.000
                },
                {
                    selector: '#gHPStatusParty',
                    custom: false,
                    originalX: 437.380
                },
                {
                    selector: '#gHPStatusSave',
                    custom: false,
                    originalX: 485.077
                },
                {
                    selector: '#gSymconWarmWaterSetpoint',
                    custom: true
                },
                {
                    selector: '#gSymconHeatingCorrection',
                    custom: true
                }
            ];

            const isVisible = (element) => {
                if (!element) {
                    return false;
                }

                const style = getComputedStyle(element);

                return (
                    style.display !== 'none'
                    && style.visibility !== 'hidden'
                    && Number(style.opacity || 1) > 0
                );
            };

            /*
             * Originaltransformationen genau einmal sichern.
             */
            definitions.forEach((definition) => {
                if (definition.custom) {
                    return;
                }

                const element =
                    svg.querySelector(definition.selector);

                if (
                    element
                    && !element.hasAttribute(
                        'data-symcon-original-transform'
                    )
                ) {
                    element.setAttribute(
                        'data-symcon-original-transform',
                        element.getAttribute('transform') || ''
                    );
                }
            });

            /*
             * Alle aktuell sichtbaren Icons in der gewünschten Reihenfolge.
             */
            const visible = definitions.filter((definition) => {
                return isVisible(
                    svg.querySelector(definition.selector)
                );
            });

            /*
             * Die Original-Card besitzt neun Plätze.
             * Originalsymbole haben Vorrang. Zusätzliche Sollwertsymbole
             * werden nur verwendet, solange noch Platz vorhanden ist.
             */
            const visibleOriginals =
                visible.filter(
                    (definition) => !definition.custom
                );

            const visibleCustoms =
                visible.filter(
                    (definition) => definition.custom
                );

            const freeCustomSlots = Math.max(
                0,
                TOP_ICON_SLOTS.length
                - visibleOriginals.length
            );

            visibleCustoms.forEach((definition, index) => {
                const element =
                    svg.querySelector(definition.selector);

                if (!element) {
                    return;
                }

                if (index >= freeCustomSlots) {
                    element.style.setProperty(
                        'display',
                        'none',
                        'important'
                    );
                }
            });

            const shownCustoms =
                visibleCustoms.slice(0, freeCustomSlots);

            const shown = [
                ...visibleOriginals,
                ...shownCustoms
            ];

            if (shown.length === 0) {
                return;
            }

            /*
             * Tatsächlicher Rahmen der Statusleiste.
             */
            const frameX =
                Number(frame.getAttribute('x')) || 65.353;
            const frameWidth =
                Number(frame.getAttribute('width')) || 474.29;

            /*
             * Gleicher Innenabstand links und rechts.
             * Damit kleben weder Wasserhahn noch letztes Symbol am Rahmen.
             */
            const horizontalPadding = 36;
            const leftX =
                frameX + horizontalPadding;
            const rightX =
                frameX + frameWidth - horizontalPadding;

            /*
             * Vertikale Mitte der ORIGINALEN Statuszeile.
             *
             * rectSettings beginnt bei y=50.
             * Die Trennlinie liegt bei ungefähr y=100.
             * Somit ist y=75 die gemeinsame Mitte aller Statusicons.
             */
            /*
             * Reale gemeinsame Y-Mitte der Original-Statussymbole,
             * aus der unveränderten Original-SVG ermittelt.
             *
             * Die Originalicons liegen im Mittel bei y ~= 84.
             * Unsere beiden Sollwerticons werden deshalb exakt dort
             * zentriert und nicht mehr auf der geometrisch nur vermuteten
             * Rahmenmitte y=75.
             */
            const iconCenterY = 84;

            const gap =
                shown.length > 1
                    ? (rightX - leftX) / (shown.length - 1)
                    : 0;

            shown.forEach((definition, index) => {
                const element =
                    svg.querySelector(definition.selector);

                if (!element) {
                    return;
                }

                const targetX =
                    shown.length > 1
                        ? leftX + gap * index
                        : (leftX + rightX) / 2;

                if (definition.custom) {
                    /*
                     * Unsere Sollwerticons sind um (0/0) aufgebaut.
                     * Deshalb direkt auf die gemeinsame Mitte setzen.
                     */
                    element.setAttribute(
                        'transform',
                        'translate('
                        + targetX
                        + ' '
                        + iconCenterY
                        + ')'
                    );
                    return;
                }

                /*
                 * Originalicons horizontal verschieben, ihre originale
                 * vertikale Geometrie aber vollständig beibehalten.
                 */
                const original =
                    element.getAttribute(
                        'data-symcon-original-transform'
                    )
                    || '';

                /*
                 * originalX ist der tatsächlich gemessene Mittelpunkt des
                 * jeweiligen Symbols in der unveränderten Original-SVG.
                 * Dadurch werden alle Originalicons exakt auf targetX gesetzt.
                 */
                const deltaX =
                    targetX - Number(definition.originalX);

                element.setAttribute(
                    'transform',
                    'translate('
                    + deltaX
                    + ' 0)'
                    + (
                        original
                            ? ' ' + original
                            : ''
                    )
                );
            });
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


        const applyModernDashboard = (card) => {
            if (!card || !card.content || !card.content.getAttribute('data-symcon-modern')) {
                return false;
            }

            const svg = card.content;
            const cfg = currentConfig || {};
            const data = currentData || {};
            const controls = currentControls || {};
            const previewAll = cfg.fullConfigurationPreview === true;

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
                if (texts[0]) texts[0].textContent = 'LUX Bedienfeld';
                if (texts[1]) texts[1].textContent = '';
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

            const raw = (key) => {
                if (!key || !data[key]) return null;
                return data[key].value;
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

            // Kompakte obere Leiste: nur Warmwasser, Heizung, Kühlen,
            // WW Soll und Heizkorrektur. Alte Roh-SVG-Statusfelder bleiben aus.
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

            // Betrieb/Party/Eco sind bewusst keine Elemente der oberen Bedienleiste.
            // Die spätere Event-Bindung erwartet diese Liste weiterhin.
            const binaryDefs = [];

            // Mode buttons are visible only if a real control exists.
            [
                ['heating', '#gHPStatusHeating'],
                ['hotwater', '#gHPStatusWW'],
                ['cooling', '#gHPStatusCooling']
            ].forEach(([name, selector]) => {
                const c = controls[name];
                show(selector, previewAll || !!(c && c.configured && Array.isArray(c.options) && c.options.length));
            });

            // Numerische Bedienungen: nur anzeigen, wenn wirklich konfiguriert.
            [
                ['warmWaterSetpoint', '#gSymconWarmWaterSetpoint', '#gSymconWarmWaterSetpointValue'],
                ['heatingTemperatureCorrection', '#gSymconHeatingCorrection', '#gSymconHeatingCorrectionValue']
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

            // Sichtbare Buttons lückenlos von links anordnen.
            const topButtons = [
                '#gHPStatusWW',
                '#gHPStatusHeating',
                '#gHPStatusCooling',
                '#gSymconWarmWaterSetpoint',
                '#gSymconHeatingCorrection',
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

            const heatingCircuitCount = [hk1, hk2, hk3].filter(Boolean).length;

            // 1 HK: kein Verteiler. Roter Vorlauf direkt zum HK1;
            // blauer Rücklauf wie im freigegebenen V33-Beispiel bis zur Hauptrücklaufleitung.
            // 2/3 HK: Verteiler sichtbar und nur so lang wie benötigt.
            show('#gHeatingManifold', heatingCircuitCount > 1);

            const hk1Supply = svg.querySelector('#pathHK1Supply');
            const hk1Return = svg.querySelector('#pathHK1Return');

            if (heatingCircuitCount === 1) {
                if (hk1Supply) {
                    hk1Supply.setAttribute('d', 'M1070 245H1260');
                }
                if (hk1Return) {
                    hk1Return.setAttribute('d', 'M1260 295H1105V440');
                }
            } else {
                if (hk1Supply) {
                    hk1Supply.setAttribute('d', 'M1129 245H1260');
                }
                if (hk1Return) {
                    hk1Return.setAttribute('d', 'M1260 295H1129');
                }
            }

            const hkPumpIcon1 = svg.querySelector('#gHKPumpIcon1');
            if (hkPumpIcon1) {
                hkPumpIcon1.setAttribute('transform', heatingCircuitCount === 1 ? 'translate(-30 0)' : '');
            }
            const hkPumpText1 = svg.querySelector('#textHeatingPump1');
            if (hkPumpText1) {
                hkPumpText1.setAttribute('x', heatingCircuitCount === 1 ? '1165' : '1195');
            }

            // Each mixed circuit has its own three-way valve upstream of its
            // pump/emitter and a branch from that circuit's return. The symbol
            // is schematic; no valve position is inferred from Auf/Zu signals.
            for (let n = 1; n <= 3; n++) {
                const circuit = svg.querySelector('#gHK' + n);
                if (!circuit) continue;
                let mixer = circuit.querySelector('#gHKMixer' + n);
                const enabled = [hk1, hk2, hk3][n - 1] && !!cfg['heatingCircuitMixed' + n];
                if (!enabled) {
                    if (mixer) mixer.remove();
                    continue;
                }
                if (!mixer) {
                    const ns = 'http://www.w3.org/2000/svg';
                    mixer = document.createElementNS(ns, 'g');
                    mixer.id = 'gHKMixer' + n;
                    const title = document.createElementNS(ns, 'title');
                    title.textContent = 'Dreiwege-Mischventil mit Rücklaufbeimischung (schematisch)';
                    mixer.appendChild(title);
                    const branch = document.createElementNS(ns, 'path');
                    branch.id = 'pathHKMixingReturn' + n;
                    branch.setAttribute('class', 'cold');
                    branch.setAttribute('d', 'M0 50V12');
                    mixer.appendChild(branch);
                    const junction = document.createElementNS(ns, 'circle');
                    junction.setAttribute('cy', '50');
                    junction.setAttribute('r', '4');
                    junction.setAttribute('fill', '#35a6ff');
                    mixer.appendChild(junction);
                    const symbol = document.createElementNS(ns, 'circle');
                    symbol.setAttribute('r', '12');
                    symbol.setAttribute('fill', 'var(--wp-button)');
                    symbol.setAttribute('stroke', 'var(--wp-icon)');
                    symbol.setAttribute('stroke-width', '2');
                    mixer.appendChild(symbol);
                    const ports = document.createElementNS(ns, 'path');
                    ports.setAttribute('d', 'M-10-5L0 0L-10 5Z M10-5L0 0L10 5Z M-5 10L0 0L5 10Z');
                    ports.setAttribute('fill', 'none');
                    ports.setAttribute('stroke', 'var(--wp-icon)');
                    ports.setAttribute('stroke-width', '1.3');
                    mixer.appendChild(ports);
                    circuit.insertBefore(mixer, svg.querySelector('#gHKPumpIcon' + n));
                }
                const x = n === 1 && heatingCircuitCount === 1 ? 1120 : 1152;
                mixer.setAttribute('transform', 'translate(' + x + ' ' + (245 + (n - 1) * 120) + ')');
            }

            const manifoldBody = svg.querySelector('#heatingManifoldBody');
            if (manifoldBody) {
                if (heatingCircuitCount === 2) {
                    manifoldBody.setAttribute('y', '220');
                    manifoldBody.setAttribute('height', '215');
                } else if (heatingCircuitCount >= 3) {
                    manifoldBody.setAttribute('y', '220');
                    manifoldBody.setAttribute('height', '335');
                }

            // Gemeinsamen blauen Rücklauf bei mehreren Heizkreisen exakt am
            // letzten aktiven Heizkreis in den Verteiler führen.
            const bufferColdPath = svg.querySelector('#gBuffer > path.cold');
            const noBufferColdPath = svg.querySelector('#gNoBuffer > path.cold');
            const manifoldReturnY = heatingCircuitCount === 2 ? 415 : (heatingCircuitCount >= 3 ? 535 : 440);
            [bufferColdPath, noBufferColdPath].forEach((path) => {
                if (!path || heatingCircuitCount <= 1) return;
                const d = path.getAttribute('d') || '';
                path.setAttribute('d', d.replace(/M1105\s+440/, 'M1105 ' + manifoldReturnY));
            });
            }

            show('#gBuffer', heating && (previewAll || !!cfg.tankHP));
            show('#gNoBuffer', heating && !previewAll && !cfg.tankHP);
            show('#gDHW', previewAll || !!cfg.tankWW);
            show('#gSolar', previewAll || (!!cfg.tankWW && !!cfg.thermalSolarAvailable));

            // Optionale Pumpen nur dort zeigen, wo sie hydraulisch hingehören.
            show(
                '#gStorageChargingPumpVisual',
                previewAll || (!!cfg.tankHP && !!cfg.storageChargingPumpRunning)
            );
            show(
                '#gCirculatingPumpVisual',
                previewAll || (!!cfg.tankWW && !!cfg.circulatingPumpRunning)
            );

            // Vollansicht bedeutet bewusst: maximale Anlagenkonfiguration.
            // Komponenten werden zur Layoutkontrolle gezeigt, auch wenn keine
            // Symcon-Variable dafür konfiguriert ist. Unkonfigurierte Werte bleiben leer.
            if (previewAll) {
                [
                    '#gBuffer',
                    '#gDHW',
                    '#gSolar',
                    '#gStorageChargingPumpVisual',
                    '#gCirculatingPumpVisual',
                    '#gHeating',
                    '#gHeatingManifold',
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

                const manifoldBodyPreview = svg.querySelector('#heatingManifoldBody');
                if (manifoldBodyPreview) {
                    manifoldBodyPreview.setAttribute('y', '220');
                    manifoldBodyPreview.setAttribute('height', '335');
                }
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
            setText('#textSupplyTemperatureValue', formatted(cfg.supplyTemperature));
            const returnValue = svg.querySelector('#textReturnTemperatureValue');
            if (returnValue) {
                const actual = formatted(cfg.heatPumpReturnTemperature || cfg.refluxTemperatureHeating);
                const target = formatted(cfg.returnTemperatureSetpoint);
                // Keep the existing info box; move the values towards their label.
                returnValue.setAttribute('x', '462');
                returnValue.style.fontSize = target ? '21px' : '25px';
                returnValue.removeAttribute('textLength');
                returnValue.removeAttribute('lengthAdjust');
                returnValue.replaceChildren();
                const actualSpan = document.createElementNS('http://www.w3.org/2000/svg', 'tspan');
                actualSpan.setAttribute('id', 'textReturnTemperatureActualValue');
                actualSpan.textContent = actual;
                returnValue.appendChild(actualSpan);
                const targetSpan = document.createElementNS('http://www.w3.org/2000/svg', 'tspan');
                targetSpan.setAttribute('id', 'textReturnTemperatureSetpointValue');
                targetSpan.style.fontSize = '17px';
                targetSpan.textContent = target ? (actual ? ' / Soll ' : 'Soll ') + target : '';
                returnValue.appendChild(targetSpan);
                // Long profile suffixes must also stay inside the unchanged frame.
                const availableWidth = 650 - 462 - 2;
                if (returnValue.getComputedTextLength() > availableWidth) {
                    returnValue.setAttribute('textLength', String(availableWidth));
                    returnValue.setAttribute('lengthAdjust', 'spacingAndGlyphs');
                }
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

            placeTankValues([
                ['#textTankTempWWUp', cfg.tankTempWWUp, 85],
                ['#textTankTempWWMiddle', cfg.tankTempWWMiddle, 162],
                ['#textTankTempWWDown', cfg.tankTempWWDown, 240]
            ]);

            placeTankValues([
                ['#textTankTempHPUp', cfg.tankTempHPUp, 82],
                ['#textTankTempHPMiddle', cfg.tankTempHPMiddle, 158],
                ['#textTankTempHPDown', cfg.tankTempHPDown, 232]
            ]);

            setText('#textHeatingCircuitName1', cfg.heatingCircuitName1 || 'HK1');
            setText('#textHeatingCircuitName2', cfg.heatingCircuitName2 || 'HK2');
            setText('#textHeatingCircuitName3', cfg.heatingCircuitName3 || 'HK3');

            [
                [1, cfg.supplyTemperatureHeating, cfg.refluxTemperatureHeating, cfg.heatingCircuitPumpValue],
                [2, cfg.supplyTemperatureHeating2, cfg.refluxTemperatureHeating2, cfg.heatingCircuitPumpValue2],
                [3, cfg.supplyTemperatureHeating3, cfg.refluxTemperatureHeating3, cfg.heatingCircuitPumpValue3]
            ].forEach(([n, supply, reflux, pumpValueItem]) => {
                const supplySelector = n === 1 ? '#textSupplyTemperatureHeating' : '#textSupplyTemperatureHeating' + n;
                const refluxSelector = n === 1 ? '#textRefluxTemperatureHeating' : '#textRefluxTemperatureHeating' + n;
                setText(supplySelector, formatted(supply));
                setText(refluxSelector, formatted(reflux));
                // A mixed circuit does not imply a measured return temperature.
                [[supplySelector, supply], [refluxSelector, reflux]].forEach(([selector, key]) => {
                    const element = svg.querySelector(selector);
                    if (!element) return;
                    const visible = formatted(key) !== '';
                    element.style.display = visible ? 'inline' : 'none';
                    const label = element.previousElementSibling;
                    if (label && ['VL', 'RL'].includes(label.textContent.trim())) label.style.display = visible ? 'inline' : 'none';
                });

                // Unter der Pumpe nur die separat konfigurierte Drehzahl /
                // den Durchfluss anzeigen. Nicht konfiguriert = leer.
                const pumpText = pumpValueItem ? formatted(pumpValueItem) : '';
                setText('#textHeatingPump' + n, pumpText);
            });

            setText('#textEvaporatorPressure', formatted(cfg.evaporatorPressure));
            setText('#textEvaporatorTemperature', formatted(cfg.evaporatorTemperature));
            setText('#textCondenserPressure', formatted(cfg.condenserPressure));
            setText('#textCondenserTemperature', formatted(cfg.condenserTemperature));
            setText('#textExpansionValveOpening', formatted(cfg.expansionValveOpening));

            // Umschaltventil: EIN = Warmwasser, AUS = Heizung.
            // Nur anzeigen, wenn eine Variable konfiguriert ist.
            const wwValveToHotWater =
                !!cfg.wwHeatingValve && binary(cfg.wwHeatingValve);

            setText(
                '#textWWHeatingValveState',
                cfg.wwHeatingValve ? (wwValveToHotWater ? 'Warmwasser' : 'Heizung') : ''
            );

            // Ventilstellung auch grafisch darstellen:
            // Heizung    = von links kommend nach oben
            // Warmwasser = von links nach rechts durchgehend
            const switchValveFlow = svg.querySelector('#switchValveFlow');
            if (switchValveFlow) {
                switchValveFlow.setAttribute(
                    'd',
                    wwValveToHotWater ? 'M-16 0H16' : 'M-16 0H0V-16'
                );
            }
            setText('#textPowerValue', formatted(cfg.heatingPumpPower));
            setText('#textCompressorSpeedValue', formatted(cfg.compressorValue));
            setText('#textThermalSolarPanelTemp', formatted(cfg.thermalSolarPanelTemp));
            setText('#textThermalSolarPumpSpeed', formatted(cfg.thermalSolarPumpSpeed));

            // Wärmepumpen-Status: ausschließlich der formatierte Profilwert
            // der konfigurierten Betriebsstatusvariable. Keine erfundenen Texte.
            setText('#textHPStatus', cfg.operatingStatus ? formatted(cfg.operatingStatus) : '');

            // Touch handling. Bind once per SVG element.
            [
                ['heating', '#gHPStatusHeating'],
                ['hotwater', '#gHPStatusWW'],
                ['cooling', '#gHPStatusCooling']
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

            // Heizkreispumpen: der neue Rotor dreht sichtbar, sobald die konfigurierte
            // Pumpenvariable einen positiven Wert liefert. Bei Prozent-/Drehzahlwerten
            // wird die Animationsgeschwindigkeit mit dem Messwert skaliert.
            [
                [1, cfg.heatingCircuitPumpRunning],
                [2, cfg.heatingCircuitPumpRunning2],
                [3, cfg.heatingCircuitPumpRunning3]
            ].forEach(([n, key]) => {
                const rotor = svg.querySelector('#hkPumpRotor' + n);
                if (!rotor) return;
                const value = raw(key);
                let speed = 0;
                if (typeof value === 'boolean') {
                    speed = value ? 50 : 0;
                } else {
                    const parsed = Number(String(value ?? '').replace(',', '.'));
                    if (Number.isFinite(parsed) && parsed > 0) speed = parsed;
                    else if (binary(key)) speed = 50;
                }
                const duration = speed > 0
                    ? Math.max(0.55, Math.min(2.8, 3.0 - Math.min(speed, 100) * 0.0245))
                    : 0;
                rotor.style.setProperty('transform-box', 'view-box', 'important');
                rotor.style.setProperty('transform-origin', '0px 0px', 'important');
                rotor.style.setProperty(
                    'animation',
                    duration > 0 ? 'symcon-modern-rotate ' + duration.toFixed(2) + 's linear infinite' : 'none',
                    'important'
                );
            });

            // Speicherlade- und Warmwasser-Zirkulationspumpe.
            [
                ['#storageChargingPumpRotor', cfg.storageChargingPumpRunning],
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
            // A configured OFF signal or a measured zero must keep the rotor still.
            // Without usable rpm, retain the existing running-state fallback.
            const compressorActive = hasCompressorSpeed
                ? compressorSpeed > 0 && (!cfg.compressorRunning || binary(cfg.compressorRunning))
                : binary(cfg.compressorRunning);
            const compressor = svg.querySelector('#pathCompressor');
            if (compressor) {
                // Frequency and rpm use different schematic scales. The displayed
                // value/profile is unchanged; no conversion to physical rpm is inferred.
                const referenceSpeed = data[cfg.compressorValue]?.ident === 'Verdichterfrequenz' ? 100 : 3000;
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

            // Fußzeile: die 10 in der Konfiguration vorhandenen
            // Zusatzwerte 000...009 verwenden. Unkonfigurierte Plätze verschwinden.
            const footerValues = [];
            for (const item of (cfg.instanceFooter || [])) {
                const value = item.key ? formatted(item.key) : '';
                if (value) footerValues.push({label: String(item.label || ''), value});
            }
            for (let index = 0; index < 10; index++) {
                const suffix = String(index).padStart(3, '0');
                const label = String(cfg['additionalLabel' + suffix] || '').trim();
                const key = cfg['additionalValue' + suffix] || '';
                const value = key ? formatted(key) : '';
                if (value) {
                    footerValues.push({
                        label: label,
                        value: value
                    });
                }
            }

            const footer = svg.querySelector('#gFooter');
            if (footer) {
                footer.querySelectorAll('text[id^="textFooterAdditional"]').forEach(el => el.remove());
                const columns = Math.min(5, Math.max(1, footerValues.length));
                const rows = Math.max(1, Math.ceil(footerValues.length / columns));
                const height = Math.max(50, rows * 38 + 12);
                footer.querySelector('rect')?.setAttribute('height', String(height));
                svg.setAttribute('viewBox', '15 0 1570 ' + Math.max(985, 935 + height));
                footerValues.forEach((item, index) => {
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.id = 'textFooterAdditional' + String(index).padStart(3, '0');
                    text.setAttribute('class', 'sm');
                    text.setAttribute('x', String(50 + (index % columns) * (1500 / columns)));
                    text.setAttribute('y', String(948 + Math.floor(index / columns) * 38));
                    const title = document.createElementNS(text.namespaceURI, 'title');
                    title.textContent = item.label;
                    text.appendChild(title);
                    const label = document.createElementNS(text.namespaceURI, 'tspan');
                    label.textContent = item.label.length > 30 ? item.label.slice(0, 29) + '…' : item.label;
                    text.appendChild(label);
                    const value = document.createElementNS(text.namespaceURI, 'tspan');
                    value.setAttribute('x', text.getAttribute('x'));
                    value.setAttribute('dy', '17');
                    value.textContent = item.value;
                    text.appendChild(value);
                    footer.appendChild(text);
                });
            }

            // Live flow overlays use actual running variables; no idle animation.
            const heatingActive = [cfg.heatingCircuitPumpRunning,
                cfg.heatingCircuitPumpRunning2, cfg.heatingCircuitPumpRunning3].some(binary);
            const chargingActive = binary(cfg.storageChargingPumpRunning);
            const solarActive = Number(raw(cfg.thermalSolarPumpSpeed)) > 0;
            const flows = [
                ['#gRefrigerantCircuit path.hot, #gRefrigerantCircuit path.cold', compressorActive],
                ['#main path.hot, #main path.cold', compressorActive],
                ['#gBuffer > path.hot, #gBuffer > path.cold', chargingActive || heatingActive],
                ['#gNoBuffer path.hot, #gNoBuffer path.cold', heatingActive],
                ['#gHK1 > path.hot, #gHK1 > path.cold, #gHKMixer1 > path.cold', binary(cfg.heatingCircuitPumpRunning)],
                ['#gHK2 > path.hot, #gHK2 > path.cold, #gHKMixer2 > path.cold', binary(cfg.heatingCircuitPumpRunning2)],
                ['#gHK3 > path.hot, #gHK3 > path.cold, #gHKMixer3 > path.cold', binary(cfg.heatingCircuitPumpRunning3)],
                ['#gDHW > path.hot, #gDHW > path.cold', chargingActive && binary(cfg.wwHeatingValve)],
                ['#gSolar path.sol', solarActive]
            ];
            flows.forEach(([selector, active]) => {
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
                    if (path.hasAttribute('transform')) overlay.setAttribute('transform', path.getAttribute('transform'));
                    if (!overlay.isConnected) path.after(overlay);
                    path.__wpLiveFlow = overlay;
                });
            });

            // Heizstab-Anzeige im modernen Renderer ebenfalls aktualisieren.
            // Bisher wurde diese Funktion nur im klassischen Renderer aufgerufen,
            // weil applyModernDashboard() vorher mit true zurückkehrt.
            applyHeaterRodInfoField(card);
            applyThreeHeaterRods(card);

            return true;
        };

        const applyControlIcons = (card) => {
            if (!card || !card.content) {
                return;
            }

            const enabledColor = resolveLayoutTextColor();

            const definitions = [
                {
                    functionName: 'heating',
                    selector: '#gHPStatusHeating',
                    dataKey: 'heatingPumpHeatingMode',
                    activeKey: 'heatingActive',
                    runningColor: '#ff9500'
                },
                {
                    functionName: 'hotwater',
                    selector: '#gHPStatusWW',
                    dataKey: 'heatingPumpHotWaterMode',
                    activeKey: 'hotwaterActive',
                    runningColor: '#ff9500'
                },
                {
                    functionName: 'cooling',
                    selector: '#gHPStatusCooling',
                    dataKey: 'heatingPumpCoolingMode',
                    activeKey: 'coolingActive',
                    runningColor: '#0a84ff'
                }
            ];

            definitions.forEach((definition) => {
                const group =
                    card.content.querySelector(definition.selector);

                if (!group) {
                    return;
                }

                const control =
                    currentControls
                    && currentControls[definition.functionName];

                /*
                 * Zwei Quellen absichtlich parallel:
                 * 1. der echte Binärstatus aus currentData
                 * 2. der von PHP aus dem zentralen Betriebsstatus berechnete
                 *    Active-Wert.
                 *
                 * Sobald EINE Quelle aktiv meldet, wird das Statussymbol
                 * eingefärbt.
                 */
                const running =
                    stateIsOn(definition.dataKey)
                    || !!(
                        currentControls
                        && currentControls[definition.activeKey] === true
                    );

                const hasControl = !!(
                    control
                    && control.configured
                    && Array.isArray(control.options)
                    && control.options.length > 0
                );

                const configuredMode =
                    !!currentConfig[definition.dataKey];

                const visible =
                    configuredMode
                    || hasControl
                    || (
                        currentControls
                        && currentControls.hasOperatingStatus
                    )
                    || running;

                group.style.setProperty(
                    'display',
                    visible ? 'inline' : 'none',
                    'important'
                );

                if (!visible) {
                    return;
                }

                group.style.setProperty(
                    'visibility',
                    'visible',
                    'important'
                );
                group.style.setProperty(
                    'opacity',
                    '1',
                    'important'
                );
                group.style.removeProperty('filter');

                /*
                 * Drei Zustände wie bisher gewünscht:
                 *
                 * 1. AUS / nicht eingeschaltet
                 *    -> Originalfarbe, aber matt
                 *
                 * 2. EINGESCHALTET / freigegeben
                 *    -> Originalfarbe, volle Helligkeit
                 *
                 * 3. AKTIV / läuft tatsächlich
                 *    -> Heizen + Warmwasser orange,
                 *       Kühlen blau
                 */
                let color = enabledColor;
                let opacity = 1;

                if (running) {
                    color = definition.runningColor;
                    opacity = 1;
                } else if (hasControl && !control.enabled) {
                    color = enabledColor;
                    opacity = 0.42;
                }

                group.style.setProperty(
                    'opacity',
                    String(opacity),
                    'important'
                );

                /*
                 * Warmwasserhahn ist im Original ein reines Füllsymbol.
                 * Ein zusätzlicher Stroke macht ihn sichtbar dicker und
                 * verformt genau den Eindruck, der im Screenshot auffällt.
                 */
                /*
                 * ALLE Symbole der oberen Statusleiste bleiben jetzt
                 * vollständig im Originalzustand der SVG.
                 *
                 * Die Original-Card färbt diese Symbole über
                 * var(--primary-text-color). Deshalb ändern wir bei
                 * Heizen, Warmwasser und Kühlen nur diese CSS-Variable
                 * am jeweiligen Originalelement.
                 *
                 * Keine Pfade klonen, keine Geometrie ändern,
                 * kein zusätzlicher Stroke.
                 */
                group.style.setProperty(
                    '--primary-text-color',
                    color,
                    'important'
                );
                group.style.setProperty(
                    'color',
                    color,
                    'important'
                );

                /*
                 * fill/currentColor zusätzlich am Container setzen, damit
                 * sowohl <g>-Symbole als auch das <use>-Warmwassersymbol
                 * zuverlässig reagieren.
                 */
                group.style.setProperty(
                    'fill',
                    'var(--primary-text-color)',
                    'important'
                );
                group.style.removeProperty('stroke');

                /*
                 * Bereits von älteren Versionen gesetzte Inline-Farben an
                 * den Originalpfaden entfernen. Danach greift wieder die
                 * Original-SVG-Logik über --primary-text-color.
                 */
                if (String(group.tagName || '').toLowerCase() !== 'use') {
                    group.querySelectorAll(
                        'path, rect, circle, ellipse, line, polyline, polygon'
                    ).forEach((element) => {
                        element.style.removeProperty('fill');
                        element.style.removeProperty('stroke');
                        element.style.removeProperty('color');
                        element.removeAttribute('fill');
                        element.removeAttribute('stroke');
                    });
                }

                if (running) {
                    group.style.setProperty(
                        'filter',
                        'brightness(1.08) saturate(1.15)',
                        'important'
                    );
                } else {
                    group.style.removeProperty('filter');
                }

                if (hasControl) {
                    group.style.setProperty(
                        'cursor',
                        'pointer',
                        'important'
                    );
                    group.style.setProperty(
                        'pointer-events',
                        'all',
                        'important'
                    );

                    if (!group.dataset.symconControlBound) {
                        group.dataset.symconControlBound = '1';

                        bindTap(
                            group,
                            (event) =>
                                openModeMenu(
                                    definition.functionName,
                                    event
                                )
                        );
                    }
                } else {
                    group.style.setProperty(
                        'cursor',
                        'default',
                        'important'
                    );
                }
            });
        };

        const applyFanAnimation = (card) => {
            if (!card || !card.content || currentConfig.heatingPumpType !== 'A2W') {
                return;
            }

            const fan = card.content.querySelector('#pathHPFan');
            if (!fan) {
                return;
            }

            const fanEntity = currentConfig.fanSpeed;

            if (!fanEntity || !currentData[fanEntity]) {
                fan.classList.remove('rotate');
                fan.style.animationDuration = '';
                return;
            }

            const rawState = currentData[fanEntity].value;
            const normalized = String(rawState ?? '').trim().toLowerCase();

            let rpm = 0;

            /*
             * Bool-Unterstützung:
             * true/on/ein/aktiv = mittlere Lüfterleistung, entspricht ca. 250 U/min.
             * false/off/aus     = Lüfter steht.
             */
            if (['true', 'on', 'yes', 'ja', 'ein', 'active', 'aktiv'].includes(normalized)) {
                rpm = 250;
            } else if (['false', 'off', 'no', 'nein', 'aus', 'inactive', 'inaktiv', ''].includes(normalized)) {
                rpm = 0;
            } else {
                rpm = Number(rawState);

                if (Number.isNaN(rpm)) {
                    rpm = 0;
                }
            }

            if (rpm <= 0) {
                fan.classList.remove('rotate');
                fan.style.animationDuration = '';
                return;
            }

            /*
             * Optische Kennlinie für reale 0–500 U/min.
             *
             * Ziel:
             *   ca. 100 U/min -> 2.5 s/Umdrehung
             *   ca. 200 U/min -> 1.8 s/Umdrehung
             *   ca. 300 U/min -> 1.2 s/Umdrehung
             *   ca. 400 U/min -> 0.8 s/Umdrehung
             *   ca. 500 U/min -> 0.5 s/Umdrehung
             *
             * Zwischenwerte werden stufenlos interpoliert.
             * Werte über 500 U/min werden auf die schnellste Darstellung begrenzt.
             */
            const clampedRpm = Math.max(0, Math.min(500, rpm));

            const points = [
                [0,   3.0],
                [100, 2.5],
                [200, 1.8],
                [300, 1.2],
                [400, 0.8],
                [500, 0.5]
            ];

            let duration = 3.0;

            for (let i = 0; i < points.length - 1; i++) {
                const [rpm1, duration1] = points[i];
                const [rpm2, duration2] = points[i + 1];

                if (clampedRpm >= rpm1 && clampedRpm <= rpm2) {
                    const factor = (clampedRpm - rpm1) / (rpm2 - rpm1);
                    duration = duration1 + (duration2 - duration1) * factor;
                    break;
                }
            }

            fan.classList.add('rotate');
            fan.style.animationDuration = duration.toFixed(2) + 's';
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
                 * Daten-Rendering ausführen. Keine HA-Schicht, keine Timer.
                 */
                if (!card.__symconSetDataHookInstalled && typeof card.setData === 'function') {
                    const originalSetData = card.setData;

                    card.setData = function(data) {
                        const result = originalSetData.call(this, data);

                        if (this.content) {
                            if (applyModernDashboard(this)) {
                                applyThemeColors(this);
                                return result;
                            }

                            applyCoolingVisualization(this);
                            updateRefrigerantValues(this);
                            applyTerminology(this);
                            applyThemeColors(this);
                            applyRefrigerantCircuitMode(this);
                            applyOptionalStatusVisibility(this);
                            applyWWValvePipeGeometry(this);
                            applyThreeHeaterRods(this);
                            applyHeaterRodInfoField(this);
                            disableOriginalSettingsLink(this);
                            applyControlIcons(this);
                            applySetpointIcons(this);
                            layoutTopIconBar(this);
                            applyFanAnimation(this);
                            applySingleCircuitTemperatureDisplay(this);
                            applyThermalSolarVisualization(this);
                            applyAdditionalValues(this);
                            normalizeTemperatureUnits(this);
                            positionHeatingCircuitTemperatures(this);
                            applyFlowAnimations(this);

                            /*
                             * Ganz zuletzt: Aktivfarben der oberen Statusleiste.
                             * So kann keine nachfolgende SVG-/Layout-Funktion
                             * die Farbe wieder überschreiben.
                             */
                            applyControlIcons(this);
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

    private function GetVisualizationVariableID(string $property): int
    {
        $idents = self::LUXTRONIK_IDENT_MAP[$property] ?? [];
        if ($property === 'HeatPumpReturnTemperature') $idents = ['Ruecklauftemperatur_Heizkreis'];
        if (preg_match('/^(SupplyTemperatureHeating|RefluxTemperatureHeating|HeatingCircuitPumpRunning|HeatingCircuitPumpValue)([1-3])$/D', $property, $match)) {
            $circuit = $this->GetVisualizationCircuits()[(int)$match[2] - 1] ?? null;
            if ($circuit === null || (int)$match[2] > 3) return 0;
            $idents = $this->GetCircuitIdents($circuit['source'], $match[1]);
        }
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && IPS_VariableExists($id)) {
                return $id;
            }
        }
        $configured = $this->ReadPropertyInteger($property);
        return $configured > 0 && IPS_VariableExists($configured) ? $configured : 0;
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
        if (!$this->ReadPropertyBoolean('InstanceStatus') || $this->ReadPropertyString('CommunicationMode') === 'modbus') {
            return false;
        }
        $native = [
            'HeatingControlVariable' => 'Mode_Heizung', 'HotWaterControlVariable' => 'Mode_WW',
            'CoolingControlVariable' => 'Mode_Kuehlung', 'WarmWaterSetpointVariable' => 'Anpassung_WW',
            'HeatingTemperatureCorrectionVariable' => 'Anpassung_Temp'
        ];
        if (!isset($native[$property]) || @$this->GetIDForIdent($native[$property]) !== $id) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        return in_array((int)$variable['VariableType'], [1, 2], true)
            && (int)($variable['VariableCustomAction'] ?: $variable['VariableAction']) > 0;
    }

    private function HandleVisualizationAction(string $action, mixed $value): void
    {
        $payload = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Ungültige Grafik-Steuerungsdaten.');
        }
        $modes = ['heating' => ['HeatingControlVariable', 'Mode_Heizung'],
            'hotwater' => ['HotWaterControlVariable', 'Mode_WW'], 'cooling' => ['CoolingControlVariable', 'Mode_Kuehlung']];
        $numeric = ['warmWaterSetpoint' => ['WarmWaterSetpointVariable', 'Anpassung_WW'],
            'heatingTemperatureCorrection' => ['HeatingTemperatureCorrectionVariable', 'Anpassung_Temp']];
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

    private function GetVisualizationFooter(): array
    {
        $rows = json_decode($this->ReadPropertyString('IDListe'), true);
        $footer = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || empty($row['visualizationFooter'])) {
                continue;
            }
            $javaID = (int)($row['id'] ?? 0);
            $ident = self::DATA_POINT_CONFIG[$javaID]['name'] ?? ('ID_' . $javaID);
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && IPS_VariableExists($id)) {
                $footer[$javaID] = ['key' => 'instanceFooter' . $javaID, 'variableId' => $id, 'label' => IPS_GetName($id)];
            }
        }
        ksort($footer, SORT_NUMERIC);
        return array_values($footer);
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
        foreach (array_keys(self::LUXTRONIK_IDENT_MAP) as $property) {
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
        $footer = [];
        $rows = json_decode($this->ReadPropertyString('IDListe'), true);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id'])) $footer[(int)$row['id']] = (bool)($row['visualizationFooter'] ?? false);
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') !== 'IDListe') continue;
            $element['columns'][] = ['name' => 'visualizationFooter', 'caption' => 'Fußzeile', 'width' => '100',
                'save' => true, 'edit' => ['type' => 'CheckBox']];
            foreach ($element['values'] as &$row) {
                $row['visualizationFooter'] = $footer[$row['id']] ?? false;
            }
            unset($row);
        }
        unset($element);
        $items = [
            ['type' => 'Label', 'caption' => 'Komponenten auswählen; interne Werte werden über die Idents dieser Instanz gelesen. Benötigte Messpunkte wie bisher in der ID-Liste aktivieren. Interne Zusatzwerte dort mit „Fußzeile“ auswählen.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'TankHP', 'caption' => 'Pufferspeicher'],
                ['type' => 'CheckBox', 'name' => 'TankWW', 'caption' => 'Warmwasserspeicher'],
                ['type' => 'CheckBox', 'name' => 'ThermalSolarAvailable', 'caption' => 'Solarthermie']
            ]],
            ['type' => 'Label', 'caption' => 'Heiz-/Mischkreise – bis zu drei gleichzeitig; fehlende Messwerte bleiben leer.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'VisualizationDirectCircuit', 'caption' => 'Direkter Heizkreis'],
                ['type' => 'CheckBox', 'name' => 'VisualizationMixedCircuit1', 'caption' => 'Mischkreis 1']
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'VisualizationMixedCircuit2', 'caption' => 'Mischkreis 2'],
                ['type' => 'CheckBox', 'name' => 'VisualizationMixedCircuit3', 'caption' => 'Mischkreis 3']
            ]],
            ['type' => 'Label', 'caption' => 'Bitte höchstens drei Heiz-/Mischkreise auswählen.', 'visible' => count($this->GetVisualizationCircuits()) > 3],
            ['type' => 'Select', 'name' => 'HeaterRodCount', 'caption' => 'Anzahl Heizstäbe',
                'options' => array_map(static fn(int $i): array => ['caption' => (string)$i, 'value' => $i], range(0, 3))],
            ['type' => 'ExpansionPanel', 'caption' => 'Drei optionale Fremdvariablen – frei benennbar', 'items' => $this->VisualizationExternalFooterFields()]
        ];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'HTML-SDK / Visualisierung', 'items' => $items];
        return $form;
    }

    private function GetVisualizationCircuits(): array
    {
        $circuits = [];
        if ($this->ReadPropertyBoolean('VisualizationDirectCircuit')) $circuits[] = ['source' => 'direct', 'name' => 'Heizkreis direkt'];
        for ($i = 1; $i <= 3; ++$i) {
            if ($this->ReadPropertyBoolean('VisualizationMixedCircuit' . $i)) $circuits[] = ['source' => 'mixed' . $i, 'name' => 'Mischkreis ' . $i];
        }
        return $circuits;
    }

    private function GetCircuitIdents(string $source, string $field): array
    {
        if ($source === 'direct') {
            return match ($field) {
                'SupplyTemperatureHeating' => ['Vorlauftemperatur_Heizkreis'],
                'RefluxTemperatureHeating' => ['Ruecklauftemperatur_Heizkreis'],
                'HeatingCircuitPumpRunning' => ['Ausgang_Heizungsumwaelzpumpe'],
                'HeatingCircuitPumpValue' => ['Steuersignal_Umwaelzpumpe'], default => []
            };
        }
        $number = (int)substr($source, -1);
        return match ($field) {
            'SupplyTemperatureHeating' => ['Mischkreis_' . $number . '_Vorlauftemperatur'],
            // Auf/Zu is a valve command, not a pump signal or a return sensor.
            'HeatingCircuitPumpRunning' => match ($number) {
                2 => ['Ausgang_Pumpe_Mischkreis_2'], 3 => ['Pumpe_Mischkreis_3'], default => []
            },
            default => []
        };
    }

    private function VisualizationExternalFooterFields(): array
    {
        $fields = [];
        for ($i = 0; $i < 3; ++$i) {
            $suffix = sprintf('%03d', $i);
            $fields[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'AdditionalLabel' . $suffix, 'caption' => 'Bezeichnung ' . ($i + 1)],
                $this->VariableSelect('Externer Wert ' . ($i + 1), 'AdditionalValue' . $suffix)
            ]];
        }
        return $fields;
    }

}
