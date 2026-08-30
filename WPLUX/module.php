<?php

class Luxtronik extends IPSModuleStrict
{
    private $updateTimer;


    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('IPAddress', '0.0.0.0');
        $this->RegisterPropertyInteger('Port', 8889);
        $this->RegisterPropertyBoolean('InstanceStatus', true);
        $this->RegisterPropertyString('IDListe', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 0); 
        $this->RegisterPropertyBoolean('HeizungVisible', false);
        $this->RegisterPropertyBoolean('KuehlungVisible', false);
        $this->RegisterPropertyBoolean('SchwimmbadVisible', false);
        $this->RegisterPropertyBoolean('WarmwasserVisible', false);
        $this->RegisterPropertyBoolean('TempsetVisible', false);
        $this->RegisterPropertyBoolean('WWsetVisible', false);
        $this->RegisterPropertyBoolean('RBEsetVisible', false);
        $this->RegisterPropertyInteger('kwin', 0);
        $this->RegisterPropertyInteger('kwhin', 0);
        $this->RegisterPropertyInteger('kwhout', 0);
        $this->RegisterPropertyInteger('HZ_TimerWeekVisible', 0);
        $this->RegisterPropertyInteger('HZ_TimerWeekendVisible', 0);
        $this->RegisterPropertyInteger('HZ_TimerDayVisible', 0);
        $this->RegisterPropertyInteger('BW_TimerWeekVisible', 0);
        $this->RegisterPropertyInteger('BW_TimerWeekendVisible', 0);
        $this->RegisterPropertyInteger('BW_TimerDayVisible', 0);
        $this->RegisterPropertyString('SelectedIDs', '[]');

        //Attribute als unsichtbare Variablen
        $this->RegisterAttributeFloat("start_value_out", 0);
        $this->RegisterAttributeFloat("start_kwh_in", 0);

        // Timer für Aktualisierung registrieren
        $this->RegisterTimer('UpdateTimer', 0, 'WPLUX_Update(' . $this->InstanceID . ');');  
    }

    public function Migrate(string $JSONData): string
    {
        // Niemals entfernen!
        parent::Migrate($JSONData);

        // Alte Persistenz dekodieren
        $data = json_decode($JSONData, true);
        if (!is_array($data) || !isset($data['configuration']['IDListe'])) {
            // Nichts zu tun
            return '';
        }

        // IDListe ist als JSON-String gespeichert
        $raw = json_decode($data['configuration']['IDListe'], true);
        if (!is_array($raw) || empty($raw)) {
            // Nichts Sinnvolles drin
            return '';
        }

        // Prüfen: schon neues Format? (id + enabled)
        $alreadyNew = true;
        foreach ($raw as $row) {
            if (!is_array($row) || !array_key_exists('id', $row) || !array_key_exists('enabled', $row)) {
                $alreadyNew = false;
                break;
            }
        }
        if ($alreadyNew) {
            // Schon migriert -> nichts ändern
            return '';
        }

        // --- Migration: alte IDs -> [{enabled, id}] ---
        $new  = [];
        $seen = [];

        foreach ($raw as $row) {
            $id      = 0;
            $enabled = true; // Default TRUE für alte Einträge

            if (is_int($row) || (is_string($row) && ctype_digit($row))) {
                // Früher: Nur nackte IDs
                $id = (int)$row;
            } elseif (is_array($row)) {
                // Falls jemand zwischendurch schon "id" verwendet hat
                $id = (int)($row['id'] ?? 0);
                if (array_key_exists('enabled', $row)) {
                    $enabled = (bool)$row['enabled'];
                }
            }

            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $new[]     = ['enabled' => $enabled, 'id' => $id];
            }
        }

        if (empty($new)) {
            // Keine brauchbaren IDs gefunden -> nichts ändern
            return '';
        }

        // Sortieren wie vorher
        usort($new, fn($a, $b) => $a['id'] <=> $b['id']);

        // Zurück in die Konfiguration (wieder als JSON-String)
        $data['configuration']['IDListe'] = json_encode($new);

        return json_encode($data);
    }

    public function ApplyChanges(): void
    {
         //Never delete this line!
        parent::ApplyChanges();

        if (!$this->ReadPropertyBoolean('InstanceStatus')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(104); // Instanz inaktiv -> Ausrufezeichen im Objektbaum
            $this->SendDebug('Instanz', 'Modul ist deaktiviert', 0);
            return;
        }

        $this->SetStatus(102); // Instanz aktiv

        // Variablenprofile zentral aus dieser module.php erstellen
        $this->CreateVariableProfiles();

        // Timer für Aktualisierung aktualisieren
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
    
        // Hole die IP-Adresse und andere Konfigurationseinstellungen
        $ipAddress = $this->ReadPropertyString('IPAddress');
        $port = $this->ReadPropertyInteger('Port');

        // Überprüfe, ob die IP-Adresse nicht die Muster-IP ist
        if ($ipAddress == '0.0.0.0') 
        {
            $this->SendDebug("Konfiguration", "IP-Adresse ist nicht konfiguriert", 0);   
            $this->LogMessage("IP-Adresse ist nicht konfiguriert", KL_ERROR);
        } 
        else 
        {
            // Bei Änderungen am Konfigurationsformular oder bei der Initialisierung auslösen
            $this->Update();
        }

        // Überprüfen, ob die Checkboxen im Konfigurationsformuler zum erstellen der Variablen aktiviert sind
        $heizungVisible = $this->ReadPropertyBoolean('HeizungVisible');
        $kuehlungVisible = $this->ReadPropertyBoolean('KuehlungVisible');
        $warmwasserVisible = $this->ReadPropertyBoolean('WarmwasserVisible');
        $schwimmbadVisible = $this->ReadPropertyBoolean('SchwimmbadVisible');
        $tempsetVisible = $this->ReadPropertyBoolean('TempsetVisible');
        $wwsetVisible = $this->ReadPropertyBoolean('WWsetVisible');
        $rbesetVisible = $this->ReadPropertyBoolean('RBEsetVisible');
        $copVarId = $this->ReadPropertyInteger('kwin');
        $jazVarId = $this->ReadPropertyInteger('kwhin');
        $hz_timerWeekVisible = $this->ReadPropertyInteger('HZ_TimerWeekVisible');
        $hz_timerWeekendVisible = $this->ReadPropertyInteger('HZ_TimerWeekendVisible');
        $hz_timerDayVisible = $this->ReadPropertyInteger('HZ_TimerDayVisible');
        $bw_timerWeekVisible = $this->ReadPropertyInteger('BW_TimerWeekVisible');
        $bw_timerWeekendVisible = $this->ReadPropertyInteger('BW_TimerWeekendVisible');
        $bw_timerDayVisible = $this->ReadPropertyInteger('BW_TimerDayVisible');

        // Steuervariablen erstellen und senden an die Funktion RequestAction
        if ($heizungVisible) 
        {
            $this->RegisterVariableInteger('Mode_Heizung', 'Modus Heizung', 'WPLUX.Wwhe', 0);
            $this->getParameter('Mode_Heizung');
            $Value = $this->GetValue('Mode_Heizung');
            $this->EnableAction('Mode_Heizung');
        } 
        else 
        {
            $this->UnregisterVariable('Mode_Heizung');
        }

        if ($warmwasserVisible) 
        {
            $this->RegisterVariableInteger('Mode_WW', 'Modus Warmwasser', 'WPLUX.Wwhe', 1);
            $this->getParameter('Mode_WW');
            $Value = $this->GetValue('Mode_WW');
            $this->EnableAction('Mode_WW');
        } 
        else 
        {
            $this->UnregisterVariable('Mode_WW');
        }

        if ($kuehlungVisible) 
        {
            $this->RegisterVariableInteger('Mode_Kuehlung', 'Modus Kühlung', 'WPLUX.Kue', 2);
            $this->getParameter('Mode_Kuehlung');
            $Value = $this->GetValue('Mode_Kuehlung');   
            $this->EnableAction('Mode_Kuehlung');
        } 
        else 
        {
            $this->UnregisterVariable('Mode_Kuehlung');
        }

        if ($tempsetVisible) 
        {
            $this->RegisterVariableFloat('Anpassung_Temp', 'Temperaturkorrektur', 'WPLUX.Tset', 3);
            $this->getParameter('Anpassung_Temp'); 
            $Value = $this->GetValue('Anpassung_Temp'); 
            $this->EnableAction('Anpassung_Temp');
        } 
        else 
        {
            $this->UnregisterVariable('Anpassung_Temp');
        }

        if ($wwsetVisible) 
        {
            $this->RegisterVariableFloat('Anpassung_WW', 'Warmwasser Soll', 'WPLUX.Wset', 4);
            $this->getParameter('Anpassung_WW'); 
            $Value = $this->GetValue('Anpassung_WW'); 
            $this->EnableAction('Anpassung_WW');
        } 
        else 
        {
            $this->UnregisterVariable('Anpassung_WW');
        }

        if ($schwimmbadVisible) 
        {
            $this->RegisterVariableInteger('Mode_Schwimmbad', 'Modus Schwimmbad', 'WPLUX.Wwhe', 5);
            $this->getParameter('Mode_Schwimmbad');
            $this->EnableAction('Mode_Schwimmbad');
        } 
        else 
        {
            $this->UnregisterVariable('Mode_Schwimmbad');
        }

        if ($rbesetVisible) 
        {
            $this->RegisterVariableFloat('Anpassung_RBE', 'Raumtemperatur Soll', 'WPLUX.Wset', 6);
            $this->getParameter('Anpassung_RBE'); 
            $Value = $this->GetValue('Anpassung_RBE'); 
            $this->EnableAction('Anpassung_RBE');
        } 
        else 
        {
            $this->UnregisterVariable('Anpassung_RBE');
        }

        if ($copVarId !== 0 && IPS_VariableExists($copVarId)) {
            $created = $this->RegisterVariableFloat('copfaktor', 'COP-Faktor', 'WPLUX.Cop', 7);
            if ($created) {
                $this->SetValueIfChanged('copfaktor', 0);
            }
        } else {
            $this->UnregisterVariable('copfaktor');
        }

        if ($jazVarId !== 0 && IPS_VariableExists($jazVarId)) {
            $created = $this->RegisterVariableFloat('jazfaktor', 'JAZ-Faktor', 'WPLUX.Cop', 8);
            if ($created) {
                $this->SetValueIfChanged('jazfaktor', 0);
            }
        } else {
            $this->UnregisterVariable('jazfaktor');
        }

        //Variabelerstellung Timer Woche Heizung

        if ($hz_timerWeekVisible >= 0 && $hz_timerWeekVisible <= 3) 
        {
            $ids = [];
            
            if ($hz_timerWeekVisible === 3) 
            {
                $ids = 
                [
                    'set_223' => 'Timer Heizung Woche von (1)', 'set_224' => 'Timer Heizung Woche bis (1)',
                    'set_225' => 'Timer Heizung Woche von (2)', 'set_226' => 'Timer Heizung Woche bis (2)',
                    'set_227' => 'Timer Heizung Woche von (3)', 'set_228' => 'Timer Heizung Woche bis (3)'
                ];
            } 
            elseif ($hz_timerWeekVisible === 2) 
            {
                {
                    $ids =
                    [
                        'set_227', 'set_228' //abgewählte Timer löschen
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }

                }
                $ids = 
                [
                    'set_223' => 'Timer Heizung Woche von (1)', 'set_224' => 'Timer Heizung Woche bis (1)', 'set_225' => 'Timer Heizung Woche von (2)', 'set_226' => 'Timer Heizung Woche bis (2)'
                ];
            }
            elseif ($hz_timerWeekVisible === 1) 
            {

                {
                    $ids =
                    [
                        'set_225', 'set_226', 'set_227', 'set_228' //abgewählte Timer löschen
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }

                }
                $ids = 
                [
                    'set_223' => 'Timer Heizung Woche von (1)', 'set_224' => 'Timer Heizung Woche bis (1)'
                ];

            }
            
            $position = -60;

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->EnableAction($id);
                // holt Wert von der Lux und schreibt intern per $this->SetValueIfChanged(...)
                $this->getParameter($id);
            }
        } 
        
        if ($hz_timerWeekVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids =
            [
                'set_223', 'set_224', 'set_225', 'set_226', 'set_227', 'set_228'
            ];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }

        //Variabelerstellung Timer Mo-Fr/Sa+So Heizung

        if ($hz_timerWeekendVisible >= 0 && $hz_timerWeekendVisible <= 3) 
        {
            $ids = [];
            
            if ($hz_timerWeekendVisible === 3) 
            {
                $ids = 
                [
                'set_229' => 'Timer Heizung Mo-Fr von (1)', 'set_230' => 'Timer Heizung Mo-Fr bis (1)', 'set_231' => 'Timer Heizung Mo-Fr von (2)', 'set_232' => 'Timer Heizung Mo-Fr bis (2)', 
				'set_233' => 'Timer Heizung Mo-Fr von (3)', 'set_234' => 'Timer Heizung Mo-Fr bis (3)', 'set_235' => 'Timer Heizung Sa+So von (1)', 'set_236' => 'Timer Heizung Sa+So bis (1)', 
				'set_237' => 'Timer Heizung Sa+So von (2)', 'set_238' => 'Timer Heizung Sa+So bis (2)', 'set_239' => 'Timer Heizung Sa+So von (3)', 'set_240' => 'Timer Heizung Sa+So bis (3)'
                ];
            } 
            elseif ($hz_timerWeekendVisible === 2) 
            {
                {
                    $ids = 
                    [
                        'set_233', 'set_234', 'set_239', 'set_240' //abgewählte Timer löschen
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                    'set_229' => 'Timer Heizung Mo-Fr von (1)', 'set_230' => 'Timer Heizung Mo-Fr bis (1)', 'set_231' => 'Timer Heizung Mo-Fr von (2)', 'set_232' => 'Timer Heizung Mo-Fr bis (2)', 
					'set_235' => 'Timer Heizung Sa+So von (1)', 'set_236' => 'Timer Heizung Sa+So bis (1)', 'set_237' => 'Timer Heizung Sa+So von (2)', 'set_238' => 'Timer Heizung Sa+So bis (2)'
                ];
            }
            elseif ($hz_timerWeekendVisible === 1) 
            {
                {
                    $ids =
                    [
                        'set_231', 'set_232', 'set_233', 'set_234','set_237', 'set_238', 'set_239', 'set_240' //abgewählte Timer löschen
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                    'set_229' => 'Timer Heizung Mo-Fr von (1)', 'set_230' => 'Timer Heizung Mo-Fr bis (1)', 'set_235' => 'Timer Heizung Sa+So von (1)', 'set_236' => 'Timer Heizung Sa+So bis (1)'
                ];
            }
            
            $position = -56; //ab dieser Position im Objektbaum einordnen

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->getParameter($id);
                $this->GetValue($id);
                $this->EnableAction($id);
            }
        } 
        if ($hz_timerWeekendVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids = ['set_229', 'set_230', 'set_231', 'set_232', 'set_233', 'set_234', 'set_235', 'set_236', 'set_237', 'set_238', 'set_239', 'set_240'];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }

        //Variabelerstellung Timer Tage Heizung

        if ($hz_timerDayVisible >= 0 && $hz_timerDayVisible <= 3) 
{
            $ids = [];
            
            if ($hz_timerDayVisible === 3) 
            {
                $ids = 
                [
                'set_241' => 'Timer Heizung Sonntag von (1)', 'set_242' => 'Timer Heizung Sonntag bis (1)', 'set_243' => 'Timer Heizung Sonntag von (2)', 'set_244' => 'Timer Heizung Sonntag bis (2)', 'set_245' => 'Timer Heizung Sonntag von (3)', 'set_246' => 'Timer Heizung Sonntag bis (3)',
                'set_247' => 'Timer Heizung Montag von (1)', 'set_248' => 'Timer Heizung Montag bis (1)', 'set_249' => 'Timer Heizung Montag von (2)', 'set_250' => 'Timer Heizung Montag bis (2)', 'set_251' => 'Timer Heizung Montag von (3)', 'set_252' => 'Timer Heizung Montag bis (3)',
                'set_253' => 'Timer Heizung Dienstag von (1)', 'set_254' => 'Timer Heizung Dienstag bis (1)', 'set_255' => 'Timer Heizung Dienstag von (2)', 'set_256' => 'Timer Heizung Dienstag bis (2)', 'set_257' => 'Timer Heizung Dienstag von (3)', 'set_258' => 'Timer Heizung Dienstag bis (3)',
                'set_259' => 'Timer Heizung Mittwoch von (1)', 'set_260' => 'Timer Heizung Mittwoch bis (1)', 'set_261' => 'Timer Heizung Mittwoch von (2)', 'set_262' => 'Timer Heizung Mittwoch bis (2)', 'set_263' => 'Timer Heizung Mittwoch von (3)', 'set_264' => 'Timer Heizung Mittwoch bis (3)',
                'set_265' => 'Timer Heizung Donnerstag von (1)', 'set_266' => 'Timer Heizung Donnerstag bis (1)', 'set_267' => 'Timer Heizung Donnerstag von (2)', 'set_268' => 'Timer Heizung Donnerstag bis (2)', 'set_269' => 'Timer Heizung Donnerstag von (3)', 'set_270' => 'Timer Heizung Donnerstag bis (3)',
                'set_271' => 'Timer Heizung Freitag von (1)', 'set_272' => 'Timer Heizung Freitag bis (1)', 'set_273' => 'Timer Heizung Freitag von (2)', 'set_274' => 'Timer Heizung Freitag bis (2)', 'set_275' => 'Timer Heizung Freitag von (3)', 'set_276' => 'Timer Heizung Freitag bis (3)',
                'set_277' => 'Timer Heizung Samstag von (1)', 'set_278' => 'Timer Heizung Samstag bis (1)', 'set_279' => 'Timer Heizung Samstag von (2)', 'set_280' => 'Timer Heizung Samstag bis (2)', 'set_281' => 'Timer Heizung Samstag von (3)', 'set_282' => 'Timer Heizung Samstag bis (3)'
                ];
            } 
            elseif ($hz_timerDayVisible === 2) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_245', 'set_246', 'set_251', 'set_252', 'set_257', 'set_258', 'set_263', 'set_264', 'set_269', 'set_270', 'set_275', 'set_276', 'set_281', 'set_282'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                    'set_241' => 'Timer Heizung Sonntag von (1)', 'set_242' => 'Timer Heizung Sonntag bis (1)', 'set_243' => 'Timer Heizung Sonntag von (2)', 'set_244' => 'Timer Heizung Sonntag bis (2)',
					'set_247' => 'Timer Heizung Montag von (1)', 'set_248' => 'Timer Heizung Montag bis (1)', 'set_249' => 'Timer Heizung Montag von (2)', 'set_250' => 'Timer Heizung Montag bis (2)',
					'set_253' => 'Timer Heizung Dienstag von (1)', 'set_254' => 'Timer Heizung Dienstag bis (1)', 'set_255' => 'Timer Heizung Dienstag von (2)', 'set_256' => 'Timer Heizung Dienstag bis (2)',
					'set_259' => 'Timer Heizung Mittwoch von (1)', 'set_260' => 'Timer Heizung Mittwoch bis (1)', 'set_261' => 'Timer Heizung Mittwoch von (2)', 'set_262' => 'Timer Heizung Mittwoch bis (2)',
					'set_265' => 'Timer Heizung Donnerstag von (1)', 'set_266' => 'Timer Heizung Donnerstag bis (1)', 'set_267' => 'Timer Heizung Donnerstag von (2)', 'set_268' => 'Timer Heizung Donnerstag bis (2)',
					'set_271' => 'Timer Heizung Freitag von (1)', 'set_272' => 'Timer Heizung Freitag bis (1)', 'set_273' => 'Timer Heizung Freitag von (2)', 'set_274' => 'Timer Heizung Freitag bis (2)',
					'set_277' => 'Timer Heizung Samstag von (1)', 'set_278' => 'Timer Heizung Samstag bis (1)', 'set_279' => 'Timer Heizung Samstag von (2)', 'set_280' => 'Timer Heizung Samstag bis (2)'
                ];
            }
            elseif ($hz_timerDayVisible === 1) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_243', 'set_244', 'set_245', 'set_246', 'set_249', 'set_250', 'set_251', 'set_252', 'set_255', 'set_256', 'set_257', 'set_258', 'set_261', 'set_262','set_263', 'set_264',
                    'set_267', 'set_268', 'set_269', 'set_270', 'set_273', 'set_274', 'set_275', 'set_276', 'set_279', 'set_280', 'set_281', 'set_282'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                    'set_241' => 'Timer Heizung Sonntag von (1)', 'set_242' => 'Timer Heizung Sonntag bis (1)', 'set_247' => 'Timer Heizung Montag von (1)', 'set_248' => 'Timer Heizung Montag bis (1)', 'set_253' => 'Timer Heizung Dienstag von (1)', 'set_254' => 'Timer Heizung Dienstag bis (1)',
					'set_259' => 'Timer Heizung Mittwoch von (1)', 'set_260' => 'Timer Heizung Mittwoch bis (1)', 'set_265' => 'Timer Heizung Donnerstag von (1)', 'set_266' => 'Timer Heizung Donnerstag bis (1)', 'set_271' => 'Timer Heizung Freitag von (1)', 'set_272' => 'Timer Heizung Freitag bis (1)',
					'set_277' => 'Timer Heizung Samstag von (1)', 'set_278' => 'Timer Heizung Samstag bis (1)'
                ];
            }
            
            $position = -42; //ab dieser Position im Objektbaum einordnen

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->getParameter($id);
                $this->GetValue($id);
                $this->EnableAction($id);
            }
        } 
        if ($hz_timerDayVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids = 
			[
			'set_241', 'set_242', 'set_243', 'set_244', 'set_245', 'set_246', 'set_247', 'set_248', 'set_249', 'set_250', 'set_251', 'set_252', 'set_253', 'set_254', 'set_255', 'set_256', 'set_257', 'set_258', 'set_259', 'set_260', 'set_261', 'set_262','set_263', 'set_264',
            'set_265', 'set_266', 'set_267', 'set_268', 'set_269', 'set_270', 'set_271', 'set_272', 'set_273', 'set_274', 'set_275', 'set_276', 'set_277', 'set_278', 'set_279', 'set_280', 'set_281', 'set_282'
			];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }

        //Variabelerstellung Timer Woche Warmwasser

        if ($bw_timerWeekVisible >= 0 && $bw_timerWeekVisible <= 5) 
        {
            $ids = [];
            
            if ($bw_timerWeekVisible === 5) 
            {
                $ids = 
                [
                'set_406' => 'Timer Warmwasser Woche von (1)', 'set_407' => 'Timer Warmwasser Woche bis (1)', 'set_408' => 'Timer Warmwasser Woche von (2)', 'set_409' => 'Timer Warmwasser Woche bis (2)', 'set_410' => 'Timer Warmwasser Woche von (3)', 
                'set_411' => 'Timer Warmwasser Woche bis (3)', 'set_412' => 'Timer Warmwasser Woche von (4)', 'set_413' => 'Timer Warmwasser Woche bis (4)', 'set_414' => 'Timer Warmwasser Woche von (5)', 'set_415' => 'Timer Warmwasser Woche bis (5)'
                ];
            } 
            elseif ($bw_timerWeekVisible === 4) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_414', 'set_415'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_406' => 'Timer Warmwasser Woche von (1)', 'set_407' => 'Timer Warmwasser Woche bis (1)', 'set_408' => 'Timer Warmwasser Woche von (2)', 'set_409' => 'Timer Warmwasser Woche bis (2)', 'set_410' => 'Timer Warmwasser Woche von (3)', 
                'set_411' => 'Timer Warmwasser Woche bis (3)', 'set_412' => 'Timer Warmwasser Woche von (4)', 'set_413' => 'Timer Warmwasser Woche bis (4)'
                ];
            }
            elseif ($bw_timerWeekVisible === 3) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_412', 'set_413', 'set_414', 'set_415'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_406' => 'Timer Warmwasser Woche von (1)', 'set_407' => 'Timer Warmwasser Woche bis (1)', 'set_408' => 'Timer Warmwasser Woche von (2)', 'set_409' => 'Timer Warmwasser Woche bis (2)', 'set_410' => 'Timer Warmwasser Woche von (3)', 
                'set_411' => 'Timer Warmwasser Woche bis (3)'
                ];
            }
			elseif ($bw_timerWeekVisible === 2) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_410', 'set_411', 'set_412', 'set_413', 'set_414', 'set_415'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_406' => 'Timer Warmwasser Woche von (1)', 'set_407' => 'Timer Warmwasser Woche bis (1)', 'set_408' => 'Timer Warmwasser Woche von (2)', 'set_409' => 'Timer Warmwasser Woche bis (2)'
                ];
            }
            elseif ($bw_timerWeekVisible === 1) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_408', 'set_409', 'set_410', 'set_411', 'set_412', 'set_413', 'set_414', 'set_415'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                    'set_406' => 'Timer Warmwasser Woche von (1)', 'set_407' => 'Timer Warmwasser Woche bis (1)'
                ];
            }
            
            $position = -160; //ab dieser Position im Objektbaum einordnen

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->getParameter($id);
                $this->GetValue($id);
                $this->EnableAction($id);
            }
        } 

        if ($bw_timerWeekVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids = 
			[
			'set_406', 'set_407', 'set_408', 'set_409', 'set_410', 'set_411', 'set_412', 'set_413', 'set_414', 'set_415'
			];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }

        //Variabelerstellung Timer Mo-Fr/Sa+So Warmwasser

        if ($bw_timerWeekendVisible >= 0 && $bw_timerWeekendVisible <= 5) 
        {
            $ids = [];
            
            if ($bw_timerWeekendVisible === 5) 
            {
                $ids = 
                [
                'set_416' => 'Timer Warmwasser Mo-Fr von (1)', 'set_417' => 'Timer Warmwasser Mo-Fr bis (1)',  'set_418' => 'Timer Warmwasser Mo-Fr von (2)', 'set_419' => 'Timer Warmwasser Mo-Fr bis (2)', 'set_420' => 'Timer Warmwasser Mo-Fr von (3)', 'set_421' => 'Timer Warmwasser Mo-Fr bis (3)', 
                'set_422' => 'Timer Warmwasser Mo-Fr von (4)', 'set_423' => 'Timer Warmwasser Mo-Fr bis (4)', 'set_424' => 'Timer Warmwasser Mo-Fr von (5)', 'set_425' => 'Timer Warmwasser Mo-Fr bis (5)', 'set_426' => 'Timer Warmwasser Sa+So von (1)', 'set_427' => 'Timer Warmwasser Sa+So bis (1)', 
                'set_428' => 'Timer Warmwasser Sa+So von (2)', 'set_429' => 'Timer Warmwasser Sa+So bis (2)', 'set_430' => 'Timer Warmwasser Sa+So von (3)', 'set_431' => 'Timer Warmwasser Sa+So bis (3)', 'set_432' => 'Timer Warmwasser Sa+So von (4)', 'set_433' => 'Timer Warmwasser Sa+So bis (4)',
                'set_434' => 'Timer Warmwasser Sa+So von (5)', 'set_435' => 'Timer Warmwasser Sa+So bis (5)'
                ];
            } 
            elseif ($bw_timerWeekendVisible === 4) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_424', 'set_425', 'set_434', 'set_435'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_416' => 'Timer Warmwasser Mo-Fr von (1)', 'set_417' => 'Timer Warmwasser Mo-Fr bis (1)',  'set_418' => 'Timer Warmwasser Mo-Fr von (2)', 'set_419' => 'Timer Warmwasser Mo-Fr bis (2)', 'set_420' => 'Timer Warmwasser Mo-Fr von (3)', 'set_421' => 'Timer Warmwasser Mo-Fr bis (3)', 
                'set_422' => 'Timer Warmwasser Mo-Fr von (4)', 'set_423' => 'Timer Warmwasser Mo-Fr bis (4)', 'set_426' => 'Timer Warmwasser Sa+So von (1)', 'set_427' => 'Timer Warmwasser Sa+So bis (1)', 'set_428' => 'Timer Warmwasser Sa+So von (2)', 'set_429' => 'Timer Warmwasser Sa+So bis (2)', 
				'set_430' => 'Timer Warmwasser Sa+So von (3)', 'set_431' => 'Timer Warmwasser Sa+So bis (3)', 'set_432' => 'Timer Warmwasser Sa+So von (4)', 'set_433' => 'Timer Warmwasser Sa+So bis (4)'
                ];
            }
            elseif ($bw_timerWeekendVisible === 3) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_422', 'set_423', 'set_424', 'set_425', 'set_432', 'set_433', 'set_434', 'set_435'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_416' => 'Timer Warmwasser Mo-Fr von (1)', 'set_417' => 'Timer Warmwasser Mo-Fr bis (1)',  'set_418' => 'Timer Warmwasser Mo-Fr von (2)', 'set_419' => 'Timer Warmwasser Mo-Fr bis (2)', 'set_420' => 'Timer Warmwasser Mo-Fr von (3)', 'set_421' => 'Timer Warmwasser Mo-Fr bis (3)', 
                'set_426' => 'Timer Warmwasser Sa+So von (1)', 'set_427' => 'Timer Warmwasser Sa+So bis (1)', 'set_428' => 'Timer Warmwasser Sa+So von (2)', 'set_429' => 'Timer Warmwasser Sa+So bis (2)', 'set_430' => 'Timer Warmwasser Sa+So von (3)', 'set_431' => 'Timer Warmwasser Sa+So bis (3)'
                ];
            }
			elseif ($bw_timerWeekendVisible === 2) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_420', 'set_421', 'set_422', 'set_423', 'set_424', 'set_425', 'set_430', 'set_431', 'set_432', 'set_433', 'set_434', 'set_435'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_416' => 'Timer Warmwasser Mo-Fr von (1)', 'set_417' => 'Timer Warmwasser Mo-Fr bis (1)',  'set_418' => 'Timer Warmwasser Mo-Fr von (2)', 'set_419' => 'Timer Warmwasser Mo-Fr bis (2)', 
                'set_426' => 'Timer Warmwasser Sa+So von (1)', 'set_427' => 'Timer Warmwasser Sa+So bis (1)', 'set_428' => 'Timer Warmwasser Sa+So von (2)', 'set_429' => 'Timer Warmwasser Sa+So bis (2)'
                ];
            }
            elseif ($bw_timerWeekendVisible === 1) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_418', 'set_419', 'set_420', 'set_421', 'set_422', 'set_423', 'set_424', 'set_425', 'set_428', 'set_429', 'set_430', 'set_431', 'set_432', 'set_433', 'set_434', 'set_435'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids =
                [
                    'set_416' => 'Timer Warmwasser Mo-Fr von (1)', 'set_417' => 'Timer Warmwasser Mo-Fr bis (1)', 'set_426' => 'Timer Warmwasser Sa+So von (1)', 'set_427' => 'Timer Warmwasser Sa+So bis (1)'
                ];
            }
            
            $position = -150; //ab dieser Position im Objektbaum einordnen

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->getParameter($id);
                $this->GetValue($id);
                $this->EnableAction($id);
            }
        } 
        if ($bw_timerWeekendVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids = 
			[
			'set_416', 'set_417', 'set_418', 'set_419', 'set_420', 'set_421', 'set_422', 'set_423', 'set_424', 'set_425', 'set_426', 'set_427', 'set_428', 'set_429', 'set_430', 'set_431', 'set_432', 'set_433', 'set_434', 'set_435'
			];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }

        //Variabelerstellung Timer Tage Warmwasser

        if ($bw_timerDayVisible >= 0 && $bw_timerDayVisible <= 5) 
        {
            $ids = [];
            
            if ($bw_timerDayVisible === 5) 
            {
                $ids = 
                [
                'set_436' => 'Timer Warmwasser Sonntag von (1)', 'set_437' => 'Timer Warmwasser Sonntag bis (1)', 'set_438' => 'Timer Warmwasser Sonntag von (2)', 'set_439' => 'Timer Warmwasser Sonntag bis (2)', 'set_440' => 'Timer Warmwasser Sonntag von (3)',
                'set_441' => 'Timer Warmwasser Sonntag bis (3)', 'set_442' => 'Timer Warmwasser Sonntag von (4)', 'set_443' => 'Timer Warmwasser Sonntag bis (4)', 'set_444' => 'Timer Warmwasser Sonntag von (5)', 'set_445' => 'Timer Warmwasser Sonntag bis (5)',
                'set_446' => 'Timer Warmwasser Montag von (1)', 'set_447' => 'Timer Warmwasser Montag bis (1)', 'set_448' => 'Timer Warmwasser Montag von (2)', 'set_449' => 'Timer Warmwasser Montag bis (2)', 'set_450' => 'Timer Warmwasser Montag von (3)',
                'set_451' => 'Timer Warmwasser Montag bis (3)', 'set_452' => 'Timer Warmwasser Montag von (4)', 'set_453' => 'Timer Warmwasser Montag bis (4)', 'set_454' => 'Timer Warmwasser Montag von (5)', 'set_455' => 'Timer Warmwasser Montag bis (5)',
                'set_456' => 'Timer Warmwasser Dienstag von (1)', 'set_457' => 'Timer Warmwasser Dienstag bis (1)', 'set_458' => 'Timer Warmwasser Dienstag von (2)', 'set_459' => 'Timer Warmwasser Dienstag bis (2)', 'set_460' => 'Timer Warmwasser Dienstag von (3)',
                'set_461' => 'Timer Warmwasser Dienstag bis (3)', 'set_462' => 'Timer Warmwasser Dienstag von (4)', 'set_463' => 'Timer Warmwasser Dienstag bis (4)', 'set_464' => 'Timer Warmwasser Dienstag von (5)', 'set_465' => 'Timer Warmwasser Dienstag bis (5)',
                'set_466' => 'Timer Warmwasser Mittwoch von (1)', 'set_467' => 'Timer Warmwasser Mittwoch bis (1)', 'set_468' => 'Timer Warmwasser Mittwoch von (2)', 'set_469' => 'Timer Warmwasser Mittwoch bis (2)', 'set_470' => 'Timer Warmwasser Mittwoch von (3)',
                'set_471' => 'Timer Warmwasser Mittwoch bis (3)', 'set_472' => 'Timer Warmwasser Mittwoch von (4)', 'set_473' => 'Timer Warmwasser Mittwoch bis (4)', 'set_474' => 'Timer Warmwasser Mittwoch von (5)', 'set_475' => 'Timer Warmwasser Mittwoch bis (5)',
                'set_476' => 'Timer Warmwasser Donnerstag von (1)', 'set_477' => 'Timer Warmwasser Donnerstag bis (1)', 'set_478' => 'Timer Warmwasser Donnerstag von (2)', 'set_479' => 'Timer Warmwasser Donnerstag bis (2)', 'set_480' => 'Timer Warmwasser Donnerstag von (3)',
                'set_481' => 'Timer Warmwasser Donnerstag bis (3)', 'set_482' => 'Timer Warmwasser Donnerstag von (4)', 'set_483' => 'Timer Warmwasser Donnerstag bis (4)', 'set_484' => 'Timer Warmwasser Donnerstag von (5)', 'set_485' => 'Timer Warmwasser Donnerstag bis (5)',
                'set_486' => 'Timer Warmwasser Freitag von (1)', 'set_487' => 'Timer Warmwasser Freitag bis (1)', 'set_488' => 'Timer Warmwasser Freitag von (2)', 'set_489' => 'Timer Warmwasser Freitag bis (2)', 'set_490' => 'Timer Warmwasser Freitag von (3)',
                'set_491' => 'Timer Warmwasser Freitag bis (3)', 'set_492' => 'Timer Warmwasser Freitag von (4)', 'set_493' => 'Timer Warmwasser Freitag bis (4)', 'set_494' => 'Timer Warmwasser Freitag von (5)', 'set_495' => 'Timer Warmwasser Freitag bis (5)',
                'set_496' => 'Timer Warmwasser Samstag von (1)', 'set_497' => 'Timer Warmwasser Samstag bis (1)', 'set_498' => 'Timer Warmwasser Samstag von (2)', 'set_499' => 'Timer Warmwasser Samstag bis (2)', 'set_500' => 'Timer Warmwasser Samstag von (3)',
                'set_501' => 'Timer Warmwasser Samstag bis (3)', 'set_502' => 'Timer Warmwasser Samstag von (4)', 'set_503' => 'Timer Warmwasser Samstag bis (4)', 'set_504' => 'Timer Warmwasser Samstag von (5)', 'set_505' => 'Timer Warmwasser Samstag bis (5)'
                ];
            } 
            elseif ($bw_timerDayVisible === 4) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_444', 'set_445', 'set_454', 'set_455', 'set_464', 'set_465', 'set_474', 'set_475', 'set_484', 'set_485', 'set_494', 'set_495', 'set_504', 'set_505'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_436' => 'Timer Warmwasser Sonntag von (1)', 'set_437' => 'Timer Warmwasser Sonntag bis (1)', 'set_438' => 'Timer Warmwasser Sonntag von (2)', 'set_439' => 'Timer Warmwasser Sonntag bis (2)', 'set_440' => 'Timer Warmwasser Sonntag von (3)',
                'set_441' => 'Timer Warmwasser Sonntag bis (3)', 'set_442' => 'Timer Warmwasser Sonntag von (4)', 'set_443' => 'Timer Warmwasser Sonntag bis (4)', 'set_446' => 'Timer Warmwasser Montag von (1)', 'set_447' => 'Timer Warmwasser Montag bis (1)', 
				'set_448' => 'Timer Warmwasser Montag von (2)', 'set_449' => 'Timer Warmwasser Montag bis (2)', 'set_450' => 'Timer Warmwasser Montag von (3)', 'set_451' => 'Timer Warmwasser Montag bis (3)', 'set_452' => 'Timer Warmwasser Montag von (4)', 'set_453' => 'Timer Warmwasser Montag bis (4)',
                'set_456' => 'Timer Warmwasser Dienstag von (1)', 'set_457' => 'Timer Warmwasser Dienstag bis (1)', 'set_458' => 'Timer Warmwasser Dienstag von (2)', 'set_459' => 'Timer Warmwasser Dienstag bis (2)', 'set_460' => 'Timer Warmwasser Dienstag von (3)',
                'set_461' => 'Timer Warmwasser Dienstag bis (3)', 'set_462' => 'Timer Warmwasser Dienstag von (4)', 'set_463' => 'Timer Warmwasser Dienstag bis (4)', 'set_466' => 'Timer Warmwasser Mittwoch von (1)', 'set_467' => 'Timer Warmwasser Mittwoch bis (1)', 
				'set_468' => 'Timer Warmwasser Mittwoch von (2)', 'set_469' => 'Timer Warmwasser Mittwoch bis (2)', 'set_470' => 'Timer Warmwasser Mittwoch von (3)', 'set_471' => 'Timer Warmwasser Mittwoch bis (3)', 'set_472' => 'Timer Warmwasser Mittwoch von (4)', 'set_473' => 'Timer Warmwasser Mittwoch bis (4)',
                'set_476' => 'Timer Warmwasser Donnerstag von (1)', 'set_477' => 'Timer Warmwasser Donnerstag bis (1)', 'set_478' => 'Timer Warmwasser Donnerstag von (2)', 'set_479' => 'Timer Warmwasser Donnerstag bis (2)', 'set_480' => 'Timer Warmwasser Donnerstag von (3)',
                'set_481' => 'Timer Warmwasser Donnerstag bis (3)', 'set_482' => 'Timer Warmwasser Donnerstag von (4)', 'set_483' => 'Timer Warmwasser Donnerstag bis (4)', 'set_486' => 'Timer Warmwasser Freitag von (1)', 'set_487' => 'Timer Warmwasser Freitag bis (1)', 
				'set_488' => 'Timer Warmwasser Freitag von (2)', 'set_489' => 'Timer Warmwasser Freitag bis (2)', 'set_490' => 'Timer Warmwasser Freitag von (3)', 'set_491' => 'Timer Warmwasser Freitag bis (3)', 'set_492' => 'Timer Warmwasser Freitag von (4)', 'set_493' => 'Timer Warmwasser Freitag bis (4)',
                'set_496' => 'Timer Warmwasser Samstag von (1)', 'set_497' => 'Timer Warmwasser Samstag bis (1)', 'set_498' => 'Timer Warmwasser Samstag von (2)', 'set_499' => 'Timer Warmwasser Samstag bis (2)', 'set_500' => 'Timer Warmwasser Samstag von (3)',
                'set_501' => 'Timer Warmwasser Samstag bis (3)', 'set_502' => 'Timer Warmwasser Samstag von (4)', 'set_503' => 'Timer Warmwasser Samstag bis (4)'
                ];
            }
            elseif ($bw_timerDayVisible === 3) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_442', 'set_443', 'set_444', 'set_445', 'set_452', 'set_453', 'set_454', 'set_455', 'set_462', 'set_463', 'set_464', 'set_465', 
                    'set_472', 'set_473', 'set_474', 'set_475', 'set_482', 'set_483', 'set_484', 'set_485', 'set_492', 'set_493', 'set_494', 'set_495', 
                    'set_502', 'set_503', 'set_504', 'set_505'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_436' => 'Timer Warmwasser Sonntag von (1)', 'set_437' => 'Timer Warmwasser Sonntag bis (1)', 'set_438' => 'Timer Warmwasser Sonntag von (2)', 'set_439' => 'Timer Warmwasser Sonntag bis (2)', 'set_440' => 'Timer Warmwasser Sonntag von (3)',
                'set_441' => 'Timer Warmwasser Sonntag bis (3)', 'set_446' => 'Timer Warmwasser Montag von (1)', 'set_447' => 'Timer Warmwasser Montag bis (1)', 'set_448' => 'Timer Warmwasser Montag von (2)', 'set_449' => 'Timer Warmwasser Montag bis (2)', 
				'set_450' => 'Timer Warmwasser Montag von (3)', 'set_451' => 'Timer Warmwasser Montag bis (3)', 'set_456' => 'Timer Warmwasser Dienstag von (1)', 'set_457' => 'Timer Warmwasser Dienstag bis (1)', 'set_458' => 'Timer Warmwasser Dienstag von (2)', 
				'set_459' => 'Timer Warmwasser Dienstag bis (2)', 'set_460' => 'Timer Warmwasser Dienstag von (3)', 'set_461' => 'Timer Warmwasser Dienstag bis (3)', 'set_466' => 'Timer Warmwasser Mittwoch von (1)', 'set_467' => 'Timer Warmwasser Mittwoch bis (1)', 
				'set_468' => 'Timer Warmwasser Mittwoch von (2)', 'set_469' => 'Timer Warmwasser Mittwoch bis (2)', 'set_470' => 'Timer Warmwasser Mittwoch von (3)', 'set_471' => 'Timer Warmwasser Mittwoch bis (3)', 'set_476' => 'Timer Warmwasser Donnerstag von (1)', 
				'set_477' => 'Timer Warmwasser Donnerstag bis (1)', 'set_478' => 'Timer Warmwasser Donnerstag von (2)', 'set_479' => 'Timer Warmwasser Donnerstag bis (2)', 'set_480' => 'Timer Warmwasser Donnerstag von (3)', 'set_481' => 'Timer Warmwasser Donnerstag bis (3)', 
				'set_486' => 'Timer Warmwasser Freitag von (1)', 'set_487' => 'Timer Warmwasser Freitag bis (1)', 'set_488' => 'Timer Warmwasser Freitag von (2)', 'set_489' => 'Timer Warmwasser Freitag bis (2)', 'set_490' => 'Timer Warmwasser Freitag von (3)', 'set_491' => 'Timer Warmwasser Freitag bis (3)',
                'set_496' => 'Timer Warmwasser Samstag von (1)', 'set_497' => 'Timer Warmwasser Samstag bis (1)', 'set_498' => 'Timer Warmwasser Samstag von (2)', 'set_499' => 'Timer Warmwasser Samstag bis (2)', 'set_500' => 'Timer Warmwasser Samstag von (3)', 'set_501' => 'Timer Warmwasser Samstag bis (3)'
                ];
            }
			elseif ($bw_timerDayVisible === 2) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_440', 'set_441', 'set_442', 'set_443', 'set_444', 'set_445', 'set_450', 
                    'set_451', 'set_452', 'set_453', 'set_454', 'set_455', 'set_460', 'set_461', 'set_462', 'set_463', 'set_464', 'set_465', 
                    'set_470', 'set_471', 'set_472', 'set_473', 'set_474', 'set_475', 'set_480', 
                    'set_481', 'set_482', 'set_483', 'set_484', 'set_485', 'set_490', 'set_491', 'set_492', 'set_493', 'set_494', 'set_495', 
                    'set_500', 'set_501', 'set_502', 'set_503', 'set_504', 'set_505'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_436' => 'Timer Warmwasser Sonntag von (1)', 'set_437' => 'Timer Warmwasser Sonntag bis (1)', 'set_438' => 'Timer Warmwasser Sonntag von (2)', 'set_439' => 'Timer Warmwasser Sonntag bis (2)', 'set_446' => 'Timer Warmwasser Montag von (1)', 
				'set_447' => 'Timer Warmwasser Montag bis (1)', 'set_448' => 'Timer Warmwasser Montag von (2)', 'set_449' => 'Timer Warmwasser Montag bis (2)', 'set_456' => 'Timer Warmwasser Dienstag von (1)', 'set_457' => 'Timer Warmwasser Dienstag bis (1)', 
				'set_458' => 'Timer Warmwasser Dienstag von (2)', 'set_459' => 'Timer Warmwasser Dienstag bis (2)', 'set_466' => 'Timer Warmwasser Mittwoch von (1)', 'set_467' => 'Timer Warmwasser Mittwoch bis (1)', 'set_468' => 'Timer Warmwasser Mittwoch von (2)', 
				'set_469' => 'Timer Warmwasser Mittwoch bis (2)', 'set_476' => 'Timer Warmwasser Donnerstag von (1)', 'set_477' => 'Timer Warmwasser Donnerstag bis (1)', 'set_478' => 'Timer Warmwasser Donnerstag von (2)', 'set_479' => 'Timer Warmwasser Donnerstag bis (2)',
				'set_486' => 'Timer Warmwasser Freitag von (1)', 'set_487' => 'Timer Warmwasser Freitag bis (1)', 'set_488' => 'Timer Warmwasser Freitag von (2)', 'set_489' => 'Timer Warmwasser Freitag bis (2)', 'set_496' => 'Timer Warmwasser Samstag von (1)', 
				'set_497' => 'Timer Warmwasser Samstag bis (1)', 'set_498' => 'Timer Warmwasser Samstag von (2)', 'set_499' => 'Timer Warmwasser Samstag bis (2)'
                ];
            }
            elseif ($bw_timerDayVisible === 1) 
            {
                {
                    $ids = //abgewählte Timer löschen
                    [
                    'set_438', 'set_439', 'set_440', 'set_441', 'set_442', 'set_443', 'set_444', 'set_445', 'set_448', 'set_449', 'set_450', 
                    'set_451', 'set_452', 'set_453', 'set_454', 'set_455', 'set_458', 'set_459', 'set_460', 'set_461', 'set_462', 'set_463', 'set_464', 'set_465', 
                    'set_468', 'set_469', 'set_470', 'set_471', 'set_472', 'set_473', 'set_474', 'set_475', 'set_478', 'set_479', 'set_480', 
                    'set_481', 'set_482', 'set_483', 'set_484', 'set_485', 'set_488', 'set_489', 'set_490', 'set_491', 'set_492', 'set_493', 'set_494', 'set_495', 
                    'set_498', 'set_499', 'set_500', 'set_501', 'set_502', 'set_503', 'set_504', 'set_505'
                    ];
                    
                    foreach ($ids as $id) 
                    {
                        $this->UnregisterVariable($id);
                    }
                }
                $ids = 
                [
                'set_436' => 'Timer Warmwasser Sonntag von (1)', 'set_437' => 'Timer Warmwasser Sonntag bis (1)', 'set_446' => 'Timer Warmwasser Montag von (1)', 'set_447' => 'Timer Warmwasser Montag bis (1)', 'set_456' => 'Timer Warmwasser Dienstag von (1)', 'set_457' => 'Timer Warmwasser Dienstag bis (1)', 
				'set_466' => 'Timer Warmwasser Mittwoch von (1)', 'set_467' => 'Timer Warmwasser Mittwoch bis (1)', 'set_476' => 'Timer Warmwasser Donnerstag von (1)', 'set_477' => 'Timer Warmwasser Donnerstag bis (1)', 'set_486' => 'Timer Warmwasser Freitag von (1)', 
				'set_487' => 'Timer Warmwasser Freitag bis (1)', 'set_496' => 'Timer Warmwasser Samstag von (1)', 'set_497' => 'Timer Warmwasser Samstag bis (1)'
				];
            }
            
            $position = -130; //ab dieser Position im Objektbaum einordnen

            foreach ($ids as $id => $name) 
            {
                $this->RegisterVariableInteger($id, $name, '~UnixTimestampTime', $position++);
                $this->getParameter($id);
                $this->GetValue($id);
                $this->EnableAction($id);
            }
        } 
        if ($bw_timerDayVisible === 0) //alle Timer löschen wenn Option deaktiviert
        {
            $ids = 
			[
			'set_436', 'set_437', 'set_438', 'set_439', 'set_440', 'set_441', 'set_442', 'set_443', 'set_444', 'set_445', 'set_446', 'set_447', 'set_448', 'set_449', 'set_450', 
            'set_451', 'set_452', 'set_453', 'set_454', 'set_455', 'set_456', 'set_457', 'set_458', 'set_459', 'set_460', 'set_461', 'set_462', 'set_463', 'set_464', 'set_465', 
            'set_466', 'set_467', 'set_468', 'set_469', 'set_470', 'set_471', 'set_472', 'set_473', 'set_474', 'set_475', 'set_476', 'set_477', 'set_478', 'set_479', 'set_480', 
            'set_481', 'set_482', 'set_483', 'set_484', 'set_485', 'set_486', 'set_487', 'set_488', 'set_489', 'set_490', 'set_491', 'set_492', 'set_493', 'set_494', 'set_495', 
            'set_496', 'set_497', 'set_498', 'set_499', 'set_500', 'set_501', 'set_502', 'set_503', 'set_504', 'set_505'
			];
            
            foreach ($ids as $id) 
            {
                $this->UnregisterVariable($id);
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void 
    {

            if (!$this->ReadPropertyBoolean('InstanceStatus')) {
                $message = 'Aktion nicht möglich: Die Instanz ist deaktiviert.';
                $this->SendDebug('RequestAction', $message, 0);
                echo $message;
                return;
            }

        // Parameterbereich von 'set_223' bis 'set_504'
        if (strpos($Ident, 'set_') === 0 && intval(substr($Ident, 4)) >= 223 && intval(substr($Ident, 4)) <= 504) 
        {
            // Funktionen aufrufen
            $this->setParameter($Ident, $Value);
            $this->getParameter($Ident);
            $this->SendDebug("Parameter $Ident", "Folgender Wert wird an die Funktion setParameter gesendet: $Value", 0);
        }
        // Weitere spezifische Werte wie 'Mode_Heizung', 'Mode_Kuehlung' usw.
        elseif (in_array($Ident, ['Mode_Heizung', 'Mode_Kuehlung', 'Mode_WW', 'Mode_Schwimmbad', 'Anpassung_WW', 'Anpassung_Temp', 'Anpassung_RBE'])) 
        {
            // Funktionen aufrufen
            $this->setParameter($Ident, $Value);
            $this->getParameter($Ident);
            $this->SendDebug("Parameter $Ident", "Folgender Wert wird an die Funktion setParameter gesendet: $Value", 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        // Namen und IDs kommen direkt aus der zentralen ID-Konfiguration
        // am Schluss dieser module.php. Eine separate Java-Datensatzdatei ist
        // dafür nicht mehr erforderlich.
        $dataset = [];
        foreach (self::DATA_POINT_CONFIG as $id => $config) {
            $dataset[(int)$id] = (string)($config['name'] ?? ('ID_' . $id));
        }

        $saved = json_decode($this->ReadPropertyString('IDListe'), true);
        if (!is_array($saved)) {
            $saved = [];
        }

        $enabledMap = [];
        foreach ($saved as $row) {
            if (is_array($row) && isset($row['id'])) {
                $enabledMap[(int)$row['id']] = (bool)($row['enabled'] ?? true);
            }
        }

        $values = [];
        $values = [];
        foreach ($dataset as $id => $name) {
            $id = (int)$id;

            // IDs 0–10 ausblenden
            if ($id >= 0 && $id <= 9) {
                continue;
            }

            $values[] = [
                'enabled' => $enabledMap[$id] ?? false,
                'id'      => $id,
                'name'    => (string)$name
            ];
        }

        usort($values, fn($a, $b) => ($a['id'] <=> $b['id']));

        $timerOptions = [
            ['caption' => 'deaktiviert', 'value' => 0],
            ['caption' => '1 Zeitfenster', 'value' => 1],
            ['caption' => '2 Zeitfenster', 'value' => 2],
            ['caption' => '3 Zeitfenster', 'value' => 3],
            ['caption' => '4 Zeitfenster', 'value' => 4],
            ['caption' => '5 Zeitfenster', 'value' => 5]
        ];

        $form = [
            'elements' => [
                [
                    'type'    => 'CheckBox',
                    'name'    => 'InstanceStatus',
                    'caption' => 'Modul aktiv'
                ],
                [
                    'name'    => 'IPAddress',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'IP-Address'
                ],
                [
                    'name'    => 'Port',
                    'type'    => 'NumberSpinner',
                    'caption' => 'Port (8888 oder 8889)'
                ],
                [
                    'name'    => 'UpdateInterval',
                    'type'    => 'IntervalBox',
                    'caption' => 'Sekunden'
                ],

                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Timer / Zusatzfunktionen',
                    'items'   => [
                        [
                        'type'    => 'CheckBox',
                        'name'    => 'HeizungVisible',
                            'caption' => 'Steuervariable für Heizungs-Modus aktivieren'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'WarmwasserVisible',
                            'caption' => 'Steuervariable für Warmwasser-Modus aktivieren'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'SchwimmbadVisible',
                            'caption' => 'Steuervariable für Schwimmbad-Modus aktivieren'
                        ],
                        [
                        'type'    => 'CheckBox',
                        'name'    => 'KuehlungVisible',
                        'caption' => 'Steuervariable für Kühlungs-Modus aktivieren'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'TempsetVisible',
                            'caption' => 'Steuervariable für Temperaturkorrektur aktivieren'
                        ],
                        [
                        'type'    => 'CheckBox',
                        'name'    => 'WWsetVisible',
                        'caption' => 'Steuervariable für Warmwasser-Soll aktivieren'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'RBEsetVisible',
                            'caption' => 'Steuervariable für Raumbedieneinheit aktivieren'
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'BW_TimerWeekVisible',
                            'caption' => 'Timer Mo-Fr Heizung',
                            'options' => $timerOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'BW_TimerWeekendVisible',
                            'caption' => 'Timer Mo-Fr/Sa+So Warmwasser',
                            'options' => $timerOptions
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'BW_TimerDayVisible',
                            'caption' => 'Timer Tage Warmwasser',
                            'options' => $timerOptions
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'JAZ & COP berechnen',
                            'bold'    => true
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'SelectVariable',
                                    'name'    => 'kwin',
                                    'caption' => 'Eingangsleistung zur Berechnung des COP (kW)'
                                ],
                                [
                                    'type'    => 'SelectVariable',
                                    'name'    => 'kwhin',
                                    'caption' => 'Eingangsenergie zur Berechnung des JAZ (kWh)'
                                ],
                                [
                                    'type'    => 'SelectVariable',
                                    'name'    => 'kwhout',
                                    'caption' => 'Externer Wärmemengenzähler für JAZ (kWh)'
                                ],
                                [
                                    'type'    => 'Button',
                                    'label'   => 'JAZ-Berechnung zurücksetzen',
                                    'onClick' => 'WPLUX_reset_jaz($id);'
                                ]
                            ]
                        ]
                    ]
                ],

                // ---- ID-Konfiguration bewusst ganz unten wie beim GoodWe-Modul
                [
                    'type'    => 'List',
                    'name'    => 'IDListe',
                    'caption' => "Überwachte ID's",
                    'rowCount' => 15,

                    'loadValuesFromConfiguration' => false,
                    'add'    => false,
                    'delete' => false,
                    'sort'   => ['column' => 'id', 'direction' => 'ascending'],

                    'columns' => [
                        [
                            'name'    => 'enabled',
                            'caption' => 'Aktiv',
                            'width'   => '70',
                            'save'    => true,
                            'edit'    => ['type' => 'CheckBox']
                        ],
                        [
                            'name'    => 'id',
                            'caption' => 'ID des Wertes',
                            'width'   => '150',
                            'save'    => true,
                            'edit'    => ['type' => 'NumberSpinner']
                        ],
                        [
                            'name'    => 'name',
                            'caption' => 'Name',
                            'width'   => 'auto',
                            'save'    => false
                        ]
                    ],

                    'values' => $values
                ],
            ],

            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Jetzt aktualisieren',
                    'onClick' => 'WPLUX_Update($id);'
                ],
                [
                    "type" => "Label",
                    "caption" => "Sag danke und unterstütze den Modulentwickler:"
                ],
                [
                    "type" => "RowLayout",
                    "items" => [
                        [
                            "type" => "Image",
                            "onClick" => "echo 'https://paypal.me/mbstern';",
                           "image" => "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                        ],
                        [
                            "type" => "Label",
                            "caption" => ""
                        ]
                    ]
                ]
            ]
        ];

        return json_encode($form);
    }

    public function Update()
    {
        // Verbindung zur Lux
        $IpWwc      = (string)$this->ReadPropertyString('IPAddress');
        $WwcJavaPort = (int)$this->ReadPropertyInteger('Port');
        $SiteTitle  = "WÄRMEPUMPE"; // falls du es später nutzt

        // Namen der Variablen direkt aus der zentralen ID-Konfiguration laden.
        $java_dataset = [];
        foreach (self::DATA_POINT_CONFIG as $id => $config) {
            $java_dataset[(int)$id] = (string)($config['name'] ?? ('ID_' . $id));
        }

        // ID-Liste lesen (List-Property mit: enabled + id)
        $idListe = json_decode($this->ReadPropertyString('IDListe'), true);
        if (!is_array($idListe)) {
            $idListe = [];
        }

        // Enabled-IDs als Map für schnellen Lookup
        $enabledIds = [];
        foreach ($idListe as $row) {
            $id = (int)($row['id'] ?? 0);
            $enabled = (bool)($row['enabled'] ?? false);

            if ($id > 0 && $enabled) {
                $enabledIds[$id] = true;
            }
        }

        // Socket verbinden
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        $connect = @socket_connect($socket, $IpWwc, $WwcJavaPort);

        if (!$connect) {
            $error_code = socket_last_error($socket);
            $this->SendDebug(
                "Socketverbindung",
                "Verbindung zum Socket fehlerhaft: {$IpWwc}:{$WwcJavaPort} Fehler: {$error_code}",
                0
            );
            $this->LogMessage(
                "Verbindung zum Socket fehlerhaft: {$IpWwc}:{$WwcJavaPort} Fehler: {$error_code}",
                KL_ERROR
            );
            socket_close($socket);
            return;
        }

        $this->SendDebug("Socketverbindung", "Verbindung zum Socket erfolgreich: {$IpWwc}:{$WwcJavaPort}", 0);

        // Anfrage 3004 senden
        $msg = pack('N*', 3004);
        socket_write($socket, $msg, 4);

        $msg = pack('N*', 0);
        socket_write($socket, $msg, 4);

        // Header lesen
        socket_recv($socket, $Test, 4, MSG_WAITALL);  // sollte 3004 zurückkommen
        $Test = unpack('N*', $Test);

        socket_recv($socket, $Test, 4, MSG_WAITALL);  // Status
        $Test = unpack('N*', $Test);

        socket_recv($socket, $Test, 4, MSG_WAITALL);  // Länge der nachfolgenden Werte
        $Test = unpack('N*', $Test);

        $JavaWerte = (int)implode($Test);

        // Daten lesen
        $daten_raw = [];
        $InBuff = [];

        for ($i = 0; $i < $JavaWerte; ++$i) {
            socket_recv($socket, $InBuff[$i], 4, MSG_WAITALL);
            $daten_raw[$i] = (int)implode(unpack('N*', $InBuff[$i]));
        }

        socket_close($socket);

        // Init für JAZ (falls 151/152 nicht vorkommen)
        $value_out_heizung = 0;
        $value_out_warmwasser = 0;

        // Alle Werte einmal durchgehen
        for ($i = 0; $i < $JavaWerte; ++$i) {

            // --- Werte erfassen für COP/JAZ unabhängig von Auswahl ---
            if ($i == 257) { // Wärmeleistung an Funktion senden zur Berechnung des COP
                $value = $this->convertValueBasedOnID($daten_raw[$i], $i);
                $this->calc_cop('cop', $value);
            }

            if ($i == 151) { // Wärmemenge Heizung
                $value_out_heizung = $this->convertValueBasedOnID($daten_raw[$i], $i);
            }

            if ($i == 152) { // Wärmemenge Warmwasser
                $value_out_warmwasser = $this->convertValueBasedOnID($daten_raw[$i], $i);
            }

            // --- Nur ausgewählte IDs verarbeiten ---
            if (isset($enabledIds[$i])) {

                // umrechnen wenn nötig
                $value = $this->convertValueBasedOnID($daten_raw[$i], $i);

                // Ident aus Dataset, Fallback falls nicht vorhanden
                $ident = $java_dataset[$i] ?? ('ID_' . $i);

                // Variable anlegen/aktualisieren
                $this->CreateOrUpdateVariable($ident, $value, $i);

                // Debug
                $this->SendDebug(
                    "Wert gesendet",
                    "Der Wert: {$daten_raw[$i]} der ID: {$i} wurde erfasst, umgerechnet in: {$value} und an 'CreateOrUpdateVariable' gesandt",
                    0
                );

            } else {
                // Variable löschen, da nicht ausgewählt
                if (isset($java_dataset[$i])) {
                    $this->DeleteVariableIfExists($java_dataset[$i]);
                }
            }
        }

        // JAZ berechnen
        $value_out = $value_out_heizung + $value_out_warmwasser;
        $this->calc_jaz('jaz', $value_out);

        /*
         * Sollwerte / Betriebsarten ebenfalls zyklisch von der Luxtronik
         * einlesen. Damit werden Änderungen, die direkt an der Luxtronik
         * vorgenommen wurden, auch in IP-Symcon übernommen.
         *
         * Wichtig: Eine einzige 3003-Abfrage pro Update-Zyklus, nicht eine
         * neue Socket-Verbindung je Sollwert.
         */
        $this->RefreshControlValues();
    }

    private function GetDataPointConfig(int $id): array
    {
        return self::DATA_POINT_CONFIG[$id] ?? [
            'name'       => 'ID_' . $id,
            'type'       => 'string',
            'profile'    => '',
            'conversion' => 'factor',
            'factor'     => 1.0,
            'decimals'   => 1
        ];
    }

    private function convertValueBasedOnID($value, $id)
    {
        $config = $this->GetDataPointConfig((int)$id);
        $conversion = (string)$config['conversion'];

        switch ($conversion) {
            case 'minus_correction':
                /*
                 * Luxtronik-Minuskorrektur:
                 * als unsigned 32-bit empfangene negative Werte zuerst
                 * wieder in den vorzeichenbehafteten Wert zurückwandeln.
                 *
                 * Die eigentliche Skalierung kommt danach sauber aus
                 * 'factor' in der zentralen ID-Konfiguration.
                 */
                if ($value > 2147483647) {
                    $value -= 4294967296;
                }

                $factor = (float)($config['factor'] ?? 1.0);
                $decimals = (int)($config['decimals'] ?? 1);

                return round($value * $factor, $decimals);

            case 'duration':
                $time = (int)$value;
                $hours = (int)floor($time / 3600);
                $time -= $hours * 3600;
                $minutes = (int)floor($time / 60);

                return "{$hours}h {$minutes}m";

            case 'hours':
                return (int)floor(((int)$value) / 3600);

            case 'ascii':
                return chr((int)$value);

            case 'ip':
                return long2ip((int)$value);

            case 'factor':
            default:
                $factor = (float)($config['factor'] ?? 1.0);
                $decimals = (int)($config['decimals'] ?? 1);

                return round($value * $factor, $decimals);
        }
    }

    private function CreateOrUpdateVariable(string $ident, mixed $value, int $id): void
    {
        $config = $this->GetDataPointConfig($id);
        $type = (string)$config['type'];
        $profile = (string)$config['profile'];

        /*
         * Variablentyp und Profil kommen ausschließlich aus der zentralen
         * DATA_POINT_CONFIG-Konfiguration am Schluss der Klasse.
         */
        switch ($type) {
            case 'bool':
                $this->RegisterVariableBoolean(
                    $ident,
                    $ident,
                    $profile,
                    $id
                );
                break;

            case 'int':
                $this->RegisterVariableInteger(
                    $ident,
                    $ident,
                    $profile,
                    $id
                );
                break;

            case 'float':
                $this->RegisterVariableFloat(
                    $ident,
                    $ident,
                    $profile,
                    $id
                );
                break;

            case 'string':
            default:
                $this->RegisterVariableString(
                    $ident,
                    $ident,
                    $profile,
                    $id
                );
                break;
        }

        /*
         * Vor dem Vergleich auf die in der zentralen ID-Konfiguration
         * vorgegebene Genauigkeit runden. Dadurch ändern versteckte
         * Nachkommastellen (z.B. Hochdruck 12.341 -> 12.344) nicht mehr
         * den Aktualisierungszeitpunkt, solange sichtbar 12.3 bleibt.
         */
        if ($type === 'float') {
            $value = round((float)$value, (int)$config['decimals']);
        }

        // Nur bei einer tatsächlichen Wertänderung nach IP-Symcon schreiben.
        if ($this->SetValueIfChanged($ident, $value)) {
            $this->SendDebug(
                'Variable aktualisiert',
                "ID: $id, Ident: $ident, neuer Wert: $value",
                0
            );
        }
    }

    /**
     * Schreibt einen Wert nur dann in eine Modulvariable, wenn er sich
     * gegenüber dem aktuell in IP-Symcon gespeicherten Wert geändert hat.
     *
     * Dadurch bleibt VariableUpdated unverändert, solange von der Luxtronik
     * lediglich derselbe Wert erneut geliefert wird.
     */
    private function SetValueIfChanged(string $ident, mixed $value): bool
    {
        $variableID = @$this->GetIDForIdent($ident);

        if ($variableID === false || !IPS_VariableExists($variableID)) {
            return false;
        }

        $variable = IPS_GetVariable($variableID);

        // Den neuen Wert passend zum tatsächlichen Variablentyp vergleichen.
        switch ((int)$variable['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $newValue = (bool)$value;
                break;

            case VARIABLETYPE_INTEGER:
                $newValue = (int)$value;
                break;

            case VARIABLETYPE_FLOAT:
                $newValue = (float)$value;
                break;

            case VARIABLETYPE_STRING:
            default:
                $newValue = (string)$value;
                break;
        }

        $oldValue = GetValue($variableID);

        if ($oldValue === $newValue) {
            return false;
        }

        // Nur bei tatsächlicher Wertänderung aktualisieren.
        $this->SetValue($ident, $newValue);
        return true;
    }

    private function DeleteVariableIfExists($ident)
    {
        $variableID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($variableID !== false) 
        {
            // Variable löschen
            $this->UnregisterVariable($ident);
            
            // Debug-Ausgabe
            $this->SendDebug("Variable gelöscht", "Variable wurde gelöscht da die ID nicht mehr in der ID-Liste vorhanden ist - Variablen-ID: ".$variableID."  Name: ".$ident."", 0);       
        }
    }

    /**
     * Liest die konfigurierten Sollwerte/Betriebsarten mit EINER 3003-Abfrage
     * und übernimmt nur tatsächlich geänderte Werte nach IP-Symcon.
     */
    private function RefreshControlValues(): void
    {
        $targets = [];

        if ($this->ReadPropertyBoolean('HeizungVisible')) {
            $targets['Mode_Heizung'] = ['index' => 3, 'conversion' => 'raw'];
        }

        if ($this->ReadPropertyBoolean('WarmwasserVisible')) {
            $targets['Mode_WW'] = ['index' => 4, 'conversion' => 'raw'];
        }

        if ($this->ReadPropertyBoolean('KuehlungVisible')) {
            $targets['Mode_Kuehlung'] = ['index' => 108, 'conversion' => 'raw'];
        }

        if ($this->ReadPropertyBoolean('SchwimmbadVisible')) {
            $targets['Mode_Schwimmbad'] = ['index' => 119, 'conversion' => 'raw'];
        }

        if ($this->ReadPropertyBoolean('TempsetVisible')) {
            $targets['Anpassung_Temp'] = ['index' => 1, 'conversion' => 'minus_correction'];
        }

        if ($this->ReadPropertyBoolean('WWsetVisible')) {
            $targets['Anpassung_WW'] = ['index' => 105, 'conversion' => 'tenth'];
        }

        if ($this->ReadPropertyBoolean('RBEsetVisible')) {
            $targets['Anpassung_RBE'] = ['index' => 1148, 'conversion' => 'tenth'];
        }

        if ($targets === []) {
            return;
        }

        $ipWwc = $this->ReadPropertyString('IPAddress');
        $wwcJavaPort = $this->ReadPropertyInteger('Port');

        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if ($socket === false) {
            return;
        }

        $connect = @socket_connect($socket, $ipWwc, $wwcJavaPort);

        if (!$connect) {
            $errorCode = socket_last_error($socket);
            $this->SendDebug(
                'Sollwerte empfangen',
                "3003-Verbindung fehlgeschlagen: {$ipWwc}:{$wwcJavaPort}, Fehler: {$errorCode}",
                0
            );
            socket_close($socket);
            return;
        }

        socket_write($socket, pack('N*', 3003), 4);
        socket_write($socket, pack('N*', 0), 4);

        $buffer = '';
        if (socket_recv($socket, $buffer, 4, MSG_WAITALL) !== 4) {
            socket_close($socket);
            return;
        }

        $buffer = '';
        if (socket_recv($socket, $buffer, 4, MSG_WAITALL) !== 4) {
            socket_close($socket);
            return;
        }

        $header = unpack('Nvalue', $buffer);
        $count = (int)($header['value'] ?? 0);

        $datenRaw = [];
        for ($i = 0; $i < $count; ++$i) {
            $buffer = '';

            if (socket_recv($socket, $buffer, 4, MSG_WAITALL) !== 4) {
                break;
            }

            $unpacked = unpack('Nvalue', $buffer);
            $datenRaw[$i] = (int)($unpacked['value'] ?? 0);
        }

        socket_close($socket);

        foreach ($targets as $ident => $target) {
            $index = (int)$target['index'];

            if (!array_key_exists($index, $datenRaw)) {
                $this->SendDebug(
                    'Sollwerte empfangen',
                    "Index {$index} für {$ident} wurde von 3003 nicht geliefert",
                    0
                );
                continue;
            }

            $value = $datenRaw[$index];

            switch ($target['conversion']) {
                case 'minus_correction':
                    if ($value > 429496000) {
                        $value -= 4294967296;
                    }
                    $value *= 0.1;
                    break;

                case 'tenth':
                    $value *= 0.1;
                    break;

                case 'raw':
                default:
                    break;
            }

            if ($this->SetValueIfChanged($ident, $value)) {
                $this->SendDebug(
                    'Sollwerte empfangen',
                    "{$ident} wurde von der Luxtronik übernommen: {$value}",
                    0
                );
            }
        }
    }

    private function setParameter($type, $value) //Parameter setzen, 3002
{
    // IP-Adresse und Port aus den Konfigurationseinstellungen lesen
    $ipWwc = $this->ReadPropertyString('IPAddress');
    $wwcJavaPort = $this->ReadPropertyInteger('Port');

    // Verbindung zum Socket herstellen
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    $connect = socket_connect($socket, $ipWwc, $wwcJavaPort);

    if (!$connect)
    {
        $error_code = socket_last_error($socket);
        $this->SendDebug("Socketverbindung", "Verbindung zum Socket fehlerhaft: " . $ipWwc . ":" . $wwcJavaPort . " Fehler: " . $error_code, 0);
        $this->LogMessage("Verbindung zum Socket fehlerhaft: " . $ipWwc . ":" . $wwcJavaPort . " Fehler: " . $error_code, KL_ERROR);
        socket_close($socket);
        return;
    }

    // Daten senden
    $msg = pack('N*', 3002); // 3002 senden aktivieren
    socket_write($socket, $msg, 4);

    // Parameter je nach Typ festlegen
    $parameter = 0;

    switch ($type) 
    {
        case 'Anpassung_Temp':
            $parameter = 1;
            if ($value >= -5 && $value <= 5) $value *= 10; // Wert für Temperaturkorrektur
            break;
        case 'Anpassung_WW':
            $parameter = 105;
            if ($value >= 30 && $value <= 65) $value *= 10; // Wert für Warmwasserkorrektur
            break;
        case 'Anpassung_RBE':
            $parameter = 1148;
            if ($value >= 0 && $value <= 35) $value *= 10; // Wert für Raumbedieneinheit
            break;
        case 'Mode_Heizung':
            $parameter = 3;
            break;
        case 'Mode_WW':
            $parameter = 4;
            break;
        case 'Mode_Schwimmbad':
            $parameter = 119;
            break;
        case 'Mode_Kuehlung':
            $parameter = 108;
            $value = ($value == 0) ? 0 : 1; // Wert für Kühlung auf 0 oder 1 setzen
            break;
        
        default: //Hier werden die ganzen Timer gespeichert
            if (strpos($type, 'set_') === 0) 
            {
                $parameter = (int) substr($type, 4);
                if ($parameter >= 223 && $parameter <= 505 && $value >= -3600 && $value <= 82800) 
                {
                    $value += 3600; // Unix-Zeit korrigieren
                }
            }
            break;
    }

        // SetParameter senden
        $msg = pack('N*', $parameter);
        socket_write($socket, $msg, 4);

        // Wert senden
        $msg = pack('N*', $value);
        socket_write($socket, $msg, 4);

        // Daten vom Socket empfangen und verarbeiten
        socket_recv($socket, $test, 4, MSG_WAITALL);  // Lesen, sollte 3002 zurückkommen
        socket_recv($socket, $test, 4, MSG_WAITALL); // Lesen, sollte Status zurückkommen

        // Socket schließen
        socket_close($socket);

        // Debug senden
        $this->SendDebug("Socketverbindung", "Der Parameter: $parameter mit dem Wert: $value wurde an den Socket gesendet", 0);
    }

    private function getParameter($mode) //Parameter holen, 3003
    {
        $ipWwc = $this->ReadPropertyString('IPAddress');
        $wwcJavaPort = $this->ReadPropertyInteger('Port');

        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        $connect = socket_connect($socket, $ipWwc, $wwcJavaPort);

        if (!$connect)
        {
            $error_code = socket_last_error($socket);
            $this->SendDebug("Socketverbindung", "Verbindung zum Socket fehlerhaft: " . $ipWwc . ":" . $wwcJavaPort . " Fehler: " . $error_code, 0);
            $this->LogMessage("Verbindung zum Socket fehlerhaft: " . $ipWwc . ":" . $wwcJavaPort . " Fehler: " . $error_code, KL_ERROR);
            socket_close($socket);
            return;
        }

        $msg = pack('N*', 3003);
        socket_write($socket, $msg, 4);

        $msg = pack('N*', 0);
        socket_write($socket, $msg, 4);

        socket_recv($socket, $test, 4, MSG_WAITALL);
        socket_recv($socket, $test, 4, MSG_WAITALL);
        $test = unpack('N*', $test);
        $javaWerte = implode($test);

        for ($i = 0; $i < $javaWerte; ++$i) 
        {
            socket_recv($socket, $inBuff[$i], 4, MSG_WAITALL);
            $datenRaw[$i] = implode(unpack('N*', $inBuff[$i]));
        }

        socket_close($socket);

        switch ($mode) {
            case 'Mode_Heizung':
            case 'Mode_WW':
            case 'Mode_Kuehlung':
            case 'Mode_Schwimmbad':
            case 'Anpassung_Temp':
            case 'Anpassung_WW':
            case 'Anpassung_RBE':
                // Index bestimmen
                $index = match ($mode) {
                    'Anpassung_Temp' => 1,
                    'Anpassung_WW'   => 105,
                    'Mode_Heizung'   => 3,
                    'Mode_WW'        => 4,
                    'Mode_Kuehlung'  => 108,
                    'Mode_Schwimmbad' => 119,
                    'Anpassung_RBE'  => 1148,
                    default          => null
                };
        
                if ($index === null || !isset($datenRaw[$index])) {
                    $this->SendDebug("Parameter $mode", "Index $index ungültig oder Wert nicht gefunden.", 0);
                    break;
                }
        
                // Wert aus dem Index holen
                $value = $datenRaw[$index];
        
                // Berechnungen basierend auf dem Modus durchführen
                if ($mode === 'Anpassung_Temp') {
                    if ($value > 429496000) {
                        $value -= 4294967296;
                    }
                    $value *= 0.1;
                } elseif (in_array($mode, ['Anpassung_WW', 'Anpassung_RBE'], true)) {
                    $value *= 0.1;
                }
        
                // Nur bei einer tatsächlichen Änderung schreiben.
                if ($this->SetValueIfChanged($mode, $value)) {
                    $this->SendDebug(
                        "Parameter $mode",
                        "Neuer Wert des Parameters $mode: $value von der Lux geholt und gespeichert",
                        0
                    );
                }
                break;
        
            default: // Hier werden die ganzen Timer geholt
                if (strpos($mode, 'set_') === 0) {
                    $index = (int) substr($mode, 4);
                    if ($index >= 223 && $index <= 505) {
                        $this->SetValueIfChanged($mode, $datenRaw[$index] - 3600);
                    }
                }
                break;
        }        
    }

    private function calc_cop(string $mode, float $value): void
    {
        $copfaktorVariableID = @$this->GetIDForIdent('copfaktor');
        $kwInVarId = $this->ReadPropertyInteger('kwin');

        if ($mode !== 'cop' || $kwInVarId === 0 || !IPS_VariableExists($kwInVarId) || $copfaktorVariableID === false) {
            return;
        }

        $kw_in = GetValue($kwInVarId);
        if ((float)$kw_in == 0.0) {
            $this->SetValueIfChanged('copfaktor', 0);
            $this->SendDebug('COP-Faktor', 'Eingangsleistung (kw_in) ist 0. COP-Faktor wurde auf 0 gesetzt.', 0);
            return;
        }

        // Bereits VOR dem Vergleich auf die tatsächlich angezeigte Genauigkeit runden.
        // So ändert sich die Variable nicht wegen unsichtbarer Nachkommastellen.
        $cop = round($value / (float)$kw_in, 1);
        $this->SetValueIfChanged('copfaktor', $cop);
        $this->SendDebug('COP-Faktor', "Faktor: $cop berechnet (kw_in=$kw_in, Wärmeleistung=$value)", 0);
    }

    private function calc_jaz(string $mode, float $value_out): void
    {
        $kwhinVarId  = $this->ReadPropertyInteger('kwhin');
        $kwhoutVarId = $this->ReadPropertyInteger('kwhout');
        $jazfaktorVariableID = @$this->GetIDForIdent('jazfaktor');

        if ($mode === 'jaz' && $kwhinVarId !== 0 && IPS_VariableExists($kwhinVarId) && $jazfaktorVariableID !== false) {

            $kwh_in = (float) GetValue($kwhinVarId);

            // Externen Wärmemengenzähler nutzen, falls gesetzt, sonst internen Wert
            if ($kwhoutVarId !== 0 && IPS_VariableExists($kwhoutVarId)) {
                $kwh_out = (float) GetValue($kwhoutVarId);
                $this->SendDebug("JAZ-Berechnung", "Berechnung des JAZ über externen Wärmemengenzähler", 0);
            } else {
                $kwh_out = (float) $value_out;
                $this->SendDebug("JAZ-Berechnung", "Berechnung des JAZ über internen Wärmemengenzähler", 0);
            }

            $this->SendDebug(
                "JAZ-Berechnung",
                "Berechnungsgrundlagen: Verbrauch (Reset): " . $this->ReadAttributeFloat('start_kwh_in') .
                " kWh, Produktion (Reset): " . $this->ReadAttributeFloat('start_value_out') .
                " kWh, Verbrauch (gesamt): $kwh_in kWh, Produktion (gesamt): $kwh_out kWh",
                0
            );

            // Erst-Sync
            if ($this->ReadAttributeFloat('start_kwh_in') == 0 || $this->ReadAttributeFloat('start_value_out') == 0) {
                $this->WriteAttributeFloat('start_kwh_in', $kwh_in);
                $this->WriteAttributeFloat('start_value_out', $kwh_out);
                $this->SendDebug("JAZ-Synch", "Variablen synchronisiert (einmalig nach Reset)", 0);
                return;
            }

            $kwh_in_Change    = $kwh_in  - $this->ReadAttributeFloat('start_kwh_in');
            $value_out_Change = $kwh_out - $this->ReadAttributeFloat('start_value_out');

            if ($kwh_in_Change != 0) {
                // Bereits VOR dem Vergleich auf die tatsächlich angezeigte Genauigkeit runden.
                // So ändert sich die Variable nicht wegen unsichtbarer Nachkommastellen.
                $jaz = round($value_out_Change / $kwh_in_Change, 1);
                $this->SetValueIfChanged('jazfaktor', $jaz);
                $this->SendDebug("JAZ-Faktor", "Faktor: $jaz (Verbrauch seit Reset: $kwh_in_Change kWh, Produktion seit Reset: $value_out_Change kWh)", 0);
            } else {
                $this->SetValueIfChanged('jazfaktor', 0);
                $this->SendDebug("JAZ-Faktor", "Noch keine Berechnung möglich (Verbrauch seit Reset unverändert)", 0);
            }
        }
    }

    public function reset_jaz() //Startwerte der JAZ-Berechnung zurücksetzen
    {
        $this->WriteAttributeFloat('start_kwh_in', 0);
        $this->WriteAttributeFloat('start_value_out', 0);
        $this->SendDebug("JAZ-Reset", "Der Reset der Start-Werte zur JAZ-Berechnung wurde durchgeführt", 0);
    }

    private function CreateVariableProfiles(): void
    {
        // Benötigte Variablenprofile erstellen

        // WPLUX.Imp
        if (!IPS_VariableProfileExists("WPLUX.Imp")) {
            IPS_CreateVariableProfile("WPLUX.Imp", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Imp erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Imp", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.Imp", 0);
            IPS_SetVariableProfileText("WPLUX.Imp", "", " impulse");
        }

        // WPLUX.Typ
        if (!IPS_VariableProfileExists("WPLUX.Typ")) {
            IPS_CreateVariableProfile("WPLUX.Typ", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Typ erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Typ", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.Typ", 0);
            IPS_SetVariableProfileText("WPLUX.Typ", "", "");
        }

        // WPLUX.Biv
        if (!IPS_VariableProfileExists("WPLUX.Biv")) {
            IPS_CreateVariableProfile("WPLUX.Biv", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Biv erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Biv", 1, 3, 1);
            IPS_SetVariableProfileDigits("WPLUX.Biv", 0);
            IPS_SetVariableProfileText("WPLUX.Biv", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Biv", 1, "ein Verdichter darf laufen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Biv", 2, "zwei Verdichter dürfen laufen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Biv", 3, "zusätzlicher Wärmeerzeuger darf mitlaufen", "", -1);
        }

        // WPLUX.BZ
        if (!IPS_VariableProfileExists("WPLUX.BZ")) {
            IPS_CreateVariableProfile("WPLUX.BZ", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.BZ erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.BZ", 0, 7, 1);
            IPS_SetVariableProfileDigits("WPLUX.BZ", 0);
            IPS_SetVariableProfileText("WPLUX.BZ", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 0, "Heizen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 1, "Warmwasser", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 2, "Schwimmbad / Photovoltaik", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 3, "EVU", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 4, "Abtauen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 5, "Keine Anforderung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 6, "Heizen ext. Energiequelle", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.BZ", 7, "Kühlbetrieb ", "", -1);
        }

        // WPLUX.Off
        if (!IPS_VariableProfileExists("WPLUX.Off")) {
            IPS_CreateVariableProfile("WPLUX.Off", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Off erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Off", 1, 9, 1);
            IPS_SetVariableProfileDigits("WPLUX.Off", 0);
            IPS_SetVariableProfileText("WPLUX.Off", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Off", 1, "Wärmepumpe Störung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 2, "Anlagen Störung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 3, "Betriebsart Zweiter Wärmeerzeuger", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 4, "EVU-Sperre", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 5, "Lauftabtau (nur LW-Geräte)", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 6, "Temperatur Einsatzgrenze maximal", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 7, "Temperatur Einsatzgrenze minimal", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 8, "Untere Einsatzgrenze", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Off", 9, "Keine Anforderung ", "", -1);
        }

        // WPLUX.Comf
        if (!IPS_VariableProfileExists("WPLUX.Comf")) {
            IPS_CreateVariableProfile("WPLUX.Comf", 0); // 0 = Bool
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Comf erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Comf", 0, 1, 1);
            IPS_SetVariableProfileDigits("WPLUX.Comf", 0);
            IPS_SetVariableProfileText("WPLUX.Comf", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Comf", 0, "nicht verbaut", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Comf", 1, "verbaut", "", -1);
        }

        // WPLUX.Men1
        if (!IPS_VariableProfileExists("WPLUX.Men1")) {
            IPS_CreateVariableProfile("WPLUX.Men1", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men1 erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Men1", 0, 7, 1);
            IPS_SetVariableProfileDigits("WPLUX.Men1", 0);
            IPS_SetVariableProfileText("WPLUX.Men1", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 0, "Wärmepumpe läuft", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 1, "Wärmepumpe steht", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 2, "Wärmepumpe kommt", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 3, "Fehlercode Speicherplatz 0", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 4, "Abtauen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 5, "Warte auf LIN-Verbindung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 6, "Verdichter heizt auf", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men1", 7, "Pumpenvorlauf ", "", -1);
        }

        // WPLUX.Men2
        if (!IPS_VariableProfileExists("WPLUX.Men2")) {
            IPS_CreateVariableProfile("WPLUX.Men2", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men2 erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Men2", 0, 1, 1);
            IPS_SetVariableProfileDigits("WPLUX.Men2", 0);
            IPS_SetVariableProfileText("WPLUX.Men2", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Men2", 0, "seit :", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men2", 1, "in : ", "", -1);
        }

        // WPLUX.Men3
        if (!IPS_VariableProfileExists("WPLUX.Men3")) {
            IPS_CreateVariableProfile("WPLUX.Men3", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men3 erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Men3", 0, 17, 1);
            IPS_SetVariableProfileDigits("WPLUX.Men3", 0);
            IPS_SetVariableProfileText("WPLUX.Men3", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 0, "Heizbetrieb", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 1, "Keine Anforderung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 2, "Netz-Einschaltverzögerung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 3, "Schaltspielsperre", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 4, "Sperrzeit", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 5, "Brauchwasser", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 6, "Info Ausheizprogramm", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 7, "Abtauen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 8, "Pumpenvorlauf", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 9, "Thermische Desinfektion", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 11, "Heizbetrieb", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 12, "Schwimmbad / Photovoltaik", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 13, "Heizen ext. Energiequelle", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 14, "Brauchwasser ext. Energiequelle", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 16, "Durchflussüberachung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Men3", 17, "Zweiter Wärmeerzeuger 1 Betrieb ", "", -1);
        }

        // WPLUX.Akt
        if (!IPS_VariableProfileExists("WPLUX.Akt")) {
            IPS_CreateVariableProfile("WPLUX.Akt", 0); // 0 = Bool
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Akt erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Akt", 0, 1, 1);
            IPS_SetVariableProfileDigits("WPLUX.Akt", 0);
            IPS_SetVariableProfileText("WPLUX.Akt", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Akt", 0, "inaktiv", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Akt", 1, "aktiv", "", -1);
        }

        // WPLUX.Pres
        if (!IPS_VariableProfileExists("WPLUX.Pres")) {
            IPS_CreateVariableProfile("WPLUX.Pres", 2); // 2 = Float
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Pres erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Pres", 0, 0, 0.1);
            IPS_SetVariableProfileDigits("WPLUX.Pres", 1);
            IPS_SetVariableProfileText("WPLUX.Pres", "", " bar");
        }

        // WPLUX.Fan
        if (!IPS_VariableProfileExists("WPLUX.Fan")) {
            IPS_CreateVariableProfile("WPLUX.Fan", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Fan erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Fan", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.Fan", 0);
            IPS_SetVariableProfileText("WPLUX.Fan", "", " rpm");
        }

        // WPLUX.Ver
        if (!IPS_VariableProfileExists("WPLUX.Ver")) {
            IPS_CreateVariableProfile("WPLUX.Ver", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Ver erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Ver", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.Ver", 0);
            IPS_SetVariableProfileText("WPLUX.Ver", "", " rpm");
        }

        // WPLUX.HzState
        if (!IPS_VariableProfileExists("WPLUX.HzState")) {
            IPS_CreateVariableProfile("WPLUX.HzState", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.HzState erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.HzState", 0, 4, 1);
            IPS_SetVariableProfileDigits("WPLUX.HzState", 0);
            IPS_SetVariableProfileText("WPLUX.HzState", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.HzState", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.HzState", 1, "Normal", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.HzState", 2, "Abgesenkt", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.HzState", 3, "Heizgrenze", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.HzState", 4, "Frostschutz", "", -1);
        }

        // WPLUX.Bet
        if (!IPS_VariableProfileExists("WPLUX.Bet")) {
            IPS_CreateVariableProfile("WPLUX.Bet", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Bet erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Bet", 0, 12, 1);
            IPS_SetVariableProfileDigits("WPLUX.Bet", 0);
            IPS_SetVariableProfileText("WPLUX.Bet", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 1, "Kühlung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 2, "Heizung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 3, "Störung", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 4, "Übergang", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 5, "Abtauen", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 6, "Warte", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 7, "Warte", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 8, "Übergang", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 9, "Stop", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 10, "Manuell ", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 11, "Simulation Start", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Bet", 12, "EVU Sperre", "", -1);
        }

        // WPLUX.lh
        if (!IPS_VariableProfileExists("WPLUX.lh")) {
            IPS_CreateVariableProfile("WPLUX.lh", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.lh erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.lh", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.lh", 0);
            IPS_SetVariableProfileText("WPLUX.lh", "", " l/h");
        }

        // WPLUX.Wwhe
        if (!IPS_VariableProfileExists("WPLUX.Wwhe")) {
            IPS_CreateVariableProfile("WPLUX.Wwhe", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Wwhe erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Wwhe", 0, 4, 0);
            IPS_SetVariableProfileDigits("WPLUX.Wwhe", 0);
            IPS_SetVariableProfileText("WPLUX.Wwhe", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 0, "Automatik", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 1, "Zus. Wärmeerzeugun", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 2, "Party", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 3, "Ferien", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 4, "Aus", "", -1);
        }

        // WPLUX.Kue
        if (!IPS_VariableProfileExists("WPLUX.Kue")) {
            IPS_CreateVariableProfile("WPLUX.Kue", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Kue erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Kue", 0, 1, 0);
            IPS_SetVariableProfileDigits("WPLUX.Kue", 0);
            IPS_SetVariableProfileText("WPLUX.Kue", "", "");
            IPS_SetVariableProfileAssociation("WPLUX.Kue", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("WPLUX.Kue", 1, "Automatik", "", -1);
        }

        // WPLUX.Tset
        if (!IPS_VariableProfileExists("WPLUX.Tset")) {
            IPS_CreateVariableProfile("WPLUX.Tset", 2); // 2 = Float
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Tset erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Tset", -5, 5, 0.5);
            IPS_SetVariableProfileDigits("WPLUX.Tset", 1);
            IPS_SetVariableProfileText("WPLUX.Tset", "", " °C");
        }

        // WPLUX.Wset
        if (!IPS_VariableProfileExists("WPLUX.Wset")) {
            IPS_CreateVariableProfile("WPLUX.Wset", 2); // 2 = Float
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Wset erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Wset", 30, 65, 0.5);
            IPS_SetVariableProfileDigits("WPLUX.Wset", 1);
            IPS_SetVariableProfileText("WPLUX.Wset", "", " °C");
        }

        // WPLUX.Std
        if (!IPS_VariableProfileExists("WPLUX.Std")) {
            IPS_CreateVariableProfile("WPLUX.Std", 1); // 1 = Integer
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Std erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Std", 0, 0, 1);
            IPS_SetVariableProfileDigits("WPLUX.Std", 0);
            IPS_SetVariableProfileText("WPLUX.Std", "", " Std.");
        }

        // WPLUX.kW
        if (!IPS_VariableProfileExists("WPLUX.kW")) {
            IPS_CreateVariableProfile("WPLUX.kW", 2); // 2 = Float
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.kW erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.kW", 0, 0, 0.01);
            IPS_SetVariableProfileDigits("WPLUX.kW", 2);
            IPS_SetVariableProfileText("WPLUX.kW", "", " kW");
        }

        // WPLUX.Cop
        if (!IPS_VariableProfileExists("WPLUX.Cop")) {
            IPS_CreateVariableProfile("WPLUX.Cop", 2); // 2 = Float
            $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Cop erstellt", 0);
            IPS_SetVariableProfileValues("WPLUX.Cop", 0, 0, 0.1);
            IPS_SetVariableProfileDigits("WPLUX.Cop", 1);
            IPS_SetVariableProfileText("WPLUX.Cop", "", "");
        }

    }

    /*
     * ================================================================
     * ZENTRALE ID-KONFIGURATION – JEDE ID GENAU EINE ZEILE
     * ================================================================
     * Format:
     * ID => [Typ, Profil, Umrechnung, Faktor, Nachkommastellen]
     *
     * Typ: bool | int | float | string
     * Umrechnung: factor | minus_correction | duration | hours | ascii | ip
     */

    private const DATA_POINT_CONFIG = [
        0 => ['name' => 'unbekannt_0', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        1 => ['name' => 'unbekannt_1', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        2 => ['name' => 'unbekannt_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        3 => ['name' => 'unbekannt_3', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        4 => ['name' => 'unbekannt_4', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        5 => ['name' => 'unbekannt_5', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        6 => ['name' => 'unbekannt_6', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        7 => ['name' => 'unbekannt_7', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        8 => ['name' => 'unbekannt_8', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        9 => ['name' => 'unbekannt_9', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        10 => ['name' => 'Vorlauftemperatur_Heizkreis', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        11 => ['name' => 'Ruecklauftemperatur_Heizkreis', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        12 => ['name' => 'Ruecklauf_Soll_Heizkreis', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        13 => ['name' => 'Ruecklauftemperatur_im_Trennspeicher', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        14 => ['name' => 'Heisgastemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        15 => ['name' => 'Aussentemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        16 => ['name' => 'Durchschnittstemperatur_Aussen_ueber_24_h', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        17 => ['name' => 'Warmwasser_Ist_Temperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        18 => ['name' => 'Warmwasser_Soll_Temperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        19 => ['name' => 'Waermequellen_Eintrittstemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        20 => ['name' => 'Waermequellen_Austrittstemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        21 => ['name' => 'Mischkreis_1_Vorlauftemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        22 => ['name' => 'Mischkreis_1_Vorlauf_Soll_Temperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        23 => ['name' => 'Raumtemperatur_Raumstation_1', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        24 => ['name' => 'Mischkreis_2_Vorlauftemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        25 => ['name' => 'Mischkreis_2_Vorlauf_Soll_Temperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        26 => ['name' => 'Fuehler_Solarkollektor', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        27 => ['name' => 'Fuehler_Solarspeicher', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        28 => ['name' => 'Fuehler_externe_Energiequelle', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        29 => ['name' => 'Eingang_Abtauende_Soledruck_Durchfluss', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        30 => ['name' => 'Eingang_Brauchwarmwasserthermostat', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        31 => ['name' => 'Eingang_EVU_Sperre', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        32 => ['name' => 'Eingang_Hochdruck_Kaeltekreis', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        33 => ['name' => 'Eingang_Motorschutz_OK', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        34 => ['name' => 'Eingang_Niederdruck', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        35 => ['name' => 'Eingang_Ueberwachungskontakt_fuer_Potentiostat', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        36 => ['name' => 'Eingang_Schwimmbadthermostat', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        37 => ['name' => 'Ausgang_Abtauventil', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        38 => ['name' => 'Ausgang_Brauchwasserpumpe_Umstellventil', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        39 => ['name' => 'Ausgang_Heizungsumwaelzpumpe', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        40 => ['name' => 'Ausgang_Mischkreis_1_Auf', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        41 => ['name' => 'Ausgang_Mischkreis_1_Zu', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        42 => ['name' => 'Ausgang_Ventilation_Lueftung', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        43 => ['name' => 'Ausgang_Solepumpe_Ventilator', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        44 => ['name' => 'Ausgang_Verdichter_1', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        45 => ['name' => 'Ausgang_Verdichter_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        46 => ['name' => 'Ausgang_Zirkulationspumpe', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        47 => ['name' => 'Ausgang_Zusatzumwaelzpumpe', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        48 => ['name' => 'Ausgang_Steuersignal_Zusatzheizung_v_Heizung', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        49 => ['name' => 'Ausgang_Steuersignal_Zusatzheizung_Stoersignal', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        50 => ['name' => 'Ausgang_Zusatzheizung_3', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        51 => ['name' => 'Ausgang_Pumpe_Mischkreis_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        52 => ['name' => 'Ausgang_Solarladepumpe', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        53 => ['name' => 'Ausgang_Schwimmbadpumpe', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        54 => ['name' => 'Ausgang_Mischkreis_2_Zu', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        55 => ['name' => 'Ausgang_Mischkreis_2_Auf', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        56 => ['name' => 'Betriebsstunden_Verdichter_1', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        57 => ['name' => 'Impulse_Verdichter_1', 'type' => 'int', 'profile' => 'WPLUX.Imp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        58 => ['name' => 'Betriebsstunden_Verdichter_2', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        59 => ['name' => 'Impulse_Verdichter_2', 'type' => 'int', 'profile' => 'WPLUX.Imp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        60 => ['name' => 'Betriebsstunden_Zweiter_Waermeerzeuger_1', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        61 => ['name' => 'Betriebsstunden_Zweiter_Waermeerzeuger_2', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        62 => ['name' => 'Betriebsstunden_Zweiter_Waermeerzeuger_3', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        63 => ['name' => 'Betriebsstunden_Waermepumpe', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        64 => ['name' => 'Betriebsstunden_Heizung', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        65 => ['name' => 'Betriebsstunden_Warmwasser', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        66 => ['name' => 'Betriebsstunden_Kuehlung', 'type' => 'int', 'profile' => 'WPLUX.Std', 'conversion' => 'hours', 'factor' => 1, 'decimals' => 1],
        67 => ['name' => 'Waermepumpe_laeuft_seit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        68 => ['name' => 'Zweiter_Waermeerzeuger_1_laeuft_seit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        69 => ['name' => 'Zweiter_Waermeerzeuger_2_laeuft_seit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        70 => ['name' => 'Netzeinschaltverzoegerung', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        71 => ['name' => 'Schaltspielsperre_Aus', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        72 => ['name' => 'Schaltspielsperre_Ein', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        73 => ['name' => 'Verdichter_Standzeit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        74 => ['name' => 'Heizungsregler_Mehr_Zeit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        75 => ['name' => 'Heizungsregler_Weniger_Zeit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        76 => ['name' => 'Thermische_Desinfektion_laeuft_seit', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        77 => ['name' => 'Sperre_Warmwasser', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        78 => ['name' => 'Waermepumpentyp', 'type' => 'int', 'profile' => 'WPLUX.Typ', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        79 => ['name' => 'Bivalenzstufe', 'type' => 'int', 'profile' => 'WPLUX.Biv', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        80 => ['name' => 'Betriebszustand', 'type' => 'int', 'profile' => 'WPLUX.BZ', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        81 => ['name' => 'SoftStand1', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        82 => ['name' => 'SoftStand2', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        83 => ['name' => 'SoftStand3', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        84 => ['name' => 'SoftStand4', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        85 => ['name' => 'SoftStand5', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        86 => ['name' => 'SoftStand6', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        87 => ['name' => 'SoftStand7', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        88 => ['name' => 'SoftStand8', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        89 => ['name' => 'SoftStand9', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        90 => ['name' => 'SoftStand10', 'type' => 'string', 'profile' => '', 'conversion' => 'ascii', 'factor' => 1, 'decimals' => 1],
        91 => ['name' => 'IP_Adresse', 'type' => 'string', 'profile' => '', 'conversion' => 'ip', 'factor' => 1, 'decimals' => 1],
        92 => ['name' => 'Subnetzmaske', 'type' => 'string', 'profile' => '', 'conversion' => 'ip', 'factor' => 1, 'decimals' => 1],
        93 => ['name' => 'Broadcast_Adresse', 'type' => 'string', 'profile' => '', 'conversion' => 'ip', 'factor' => 1, 'decimals' => 1],
        94 => ['name' => 'Standard_Gateway', 'type' => 'string', 'profile' => '', 'conversion' => 'ip', 'factor' => 1, 'decimals' => 1],
        95 => ['name' => 'Zeitstempel_Fehler_0', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        96 => ['name' => 'Zeitstempel_Fehler_1', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        97 => ['name' => 'Zeitstempel_Fehler_2', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        98 => ['name' => 'Zeitstempel_Fehler_3', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        99 => ['name' => 'Zeitstempel_Fehler_4', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        100 => ['name' => 'Fehlercode_Fehler_0', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        101 => ['name' => 'Fehlercode_Fehler_1', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        102 => ['name' => 'Fehlercode_Fehler_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        103 => ['name' => 'Fehlercode_Fehler_3', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        104 => ['name' => 'Fehlercode_Fehler_4', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        105 => ['name' => 'Anzahl_der_Fehler', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        106 => ['name' => 'Grund_Abschaltung_0', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        107 => ['name' => 'Grund_Abschaltung_1', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        108 => ['name' => 'Grund_Abschaltung_2', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        109 => ['name' => 'Grund_Abschaltung_3', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        110 => ['name' => 'Grund_Abschaltung_4', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        111 => ['name' => 'Zeitstempel_Abschaltung_0', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        112 => ['name' => 'Zeitstempel_Abschaltung_1', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        113 => ['name' => 'Zeitstempel_Abschaltung_2', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        114 => ['name' => 'Zeitstempel_Abschaltung_3', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        115 => ['name' => 'Zeitstempel_Abschaltung_4', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        116 => ['name' => 'Comfort_Platine_installiert', 'type' => 'bool', 'profile' => 'WPLUX.Comf', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        117 => ['name' => 'Status_Zeile_1', 'type' => 'int', 'profile' => 'WPLUX.Men1', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        118 => ['name' => 'Status_Zeile_2', 'type' => 'int', 'profile' => 'WPLUX.Men2', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        119 => ['name' => 'Status_Zeile_3', 'type' => 'int', 'profile' => 'WPLUX.Men3', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        120 => ['name' => 'Zeit_seit_in_von_Wert_118', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        121 => ['name' => 'Stufe_Ausheizprogramm', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        122 => ['name' => 'Temperatur_Ausheizprogramm', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        123 => ['name' => 'Laufzeit_Ausheizprogramm', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        124 => ['name' => 'Brauchwasser_aktiv_inaktiv_Symbol', 'type' => 'bool', 'profile' => 'WPLUX.Akt', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        125 => ['name' => 'Heizung_Symbol', 'type' => 'int', 'profile' => 'WPLUX.HzState', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        126 => ['name' => 'Mischkreis_1_Symbol', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        127 => ['name' => 'Mischkreis_2_Symbol', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        128 => ['name' => 'Einstellung_Kurzprogramm', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        129 => ['name' => 'Status_Slave_1', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        130 => ['name' => 'Status_Slave_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        131 => ['name' => 'Status_Slave_3', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        132 => ['name' => 'Status_Slave_4', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        133 => ['name' => 'Status_Slave_5', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        134 => ['name' => 'Aktuelle_Zeit_der_Waermepumpe', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        135 => ['name' => 'Mischkreis_3_Symbol', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        136 => ['name' => 'Mischkreis_3_Vorlauf_Soll_Temperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        137 => ['name' => 'Mischkreis_3_Vorlauftemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        138 => ['name' => 'Ausgang_Mischkreis_3_Zu', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        139 => ['name' => 'Ausgang_Mischkreis_3_Auf', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        140 => ['name' => 'Pumpe_Mischkreis_3', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        141 => ['name' => 'Zeit_bis_Abtauen', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        142 => ['name' => 'Raumtemperatur_Raumstation_2', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        143 => ['name' => 'Raumtemperatur_Raumstation_3', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        144 => ['name' => 'Schaltuhr_Schwimmbad_Symbol', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        145 => ['name' => 'Betriebsstunden_Schwimmbad', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        146 => ['name' => 'Freigabe_Kuehlung', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        147 => ['name' => 'Analoges_Eingangssignal', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        148 => ['name' => 'SonderZeichen', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        149 => ['name' => 'Zirkulationspumpen_Symbol', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        150 => ['name' => 'WebsrvProgrammWerteBeobarten', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        151 => ['name' => 'Waermemengenzaehler_Heizung', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        152 => ['name' => 'Waermemengenzaehler_Brauchwasser', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        153 => ['name' => 'Waermemengenzaehler_Schwimmbad', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        154 => ['name' => 'Waermemengenzaehler_Gesamt', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        155 => ['name' => 'Waermemengenzaehler_Durchfluss', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        156 => ['name' => 'Analog_Ausgang_1', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        157 => ['name' => 'Analog_Ausgang_2', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        158 => ['name' => 'Sperre_zweiter_Verdichter_Heissgas', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        159 => ['name' => 'Zulufttemperatur', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        160 => ['name' => 'Ablufttemperatur', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        161 => ['name' => 'Betriebstundenzaehler_Solar', 'type' => 'string', 'profile' => '', 'conversion' => 'duration', 'factor' => 1, 'decimals' => 1],
        162 => ['name' => 'Analog_Ausgang_3', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        163 => ['name' => 'Analog_Ausgang_4', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        164 => ['name' => 'Zuluft_Ventilator_Abtaufunktion', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        165 => ['name' => 'Abluft_Ventilator', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        166 => ['name' => 'Ausgang_VSK', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        167 => ['name' => 'Ausgang_FRH', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        168 => ['name' => 'Analog_Eingang_2', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        169 => ['name' => 'Analog_Eingang_3', 'type' => 'float', 'profile' => '~Volt', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        170 => ['name' => 'Eingang_SAX', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        171 => ['name' => 'Eingang_SPL', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        172 => ['name' => 'Lueftungsplatine_verbaut', 'type' => 'bool', 'profile' => 'WPLUX.Comf', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        173 => ['name' => 'Durchfluss_Waermequelle', 'type' => 'int', 'profile' => 'WPLUX.lh', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        174 => ['name' => 'LIN_BUS_verbaut', 'type' => 'bool', 'profile' => 'WPLUX.Comf', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        175 => ['name' => 'Temperatur_Ansaug_Verdampfer', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        176 => ['name' => 'Temperatur_Ansaug_Verdichter', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        177 => ['name' => 'Temperatur_Verdichter_Heizung', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        178 => ['name' => 'Ueberhitzung', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        179 => ['name' => 'Ueberhitzung_Soll', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        180 => ['name' => 'Hochdruck', 'type' => 'float', 'profile' => 'WPLUX.Pres', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        181 => ['name' => 'Niederdruck', 'type' => 'float', 'profile' => 'WPLUX.Pres', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        182 => ['name' => 'Ausgang_Verdichterheizung', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        183 => ['name' => 'Steuersignal_Umwaelzpumpe', 'type' => 'float', 'profile' => '~Valve.F', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        184 => ['name' => 'Ventilator_Drehzahl', 'type' => 'int', 'profile' => 'WPLUX.Fan', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        185 => ['name' => 'EVU_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        186 => ['name' => 'Sicherheits_Temperatur_Begrenzer_Fussbodenheizung', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        187 => ['name' => 'Leistung_Sollwert', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        188 => ['name' => 'Leistung_Istwert', 'type' => 'float', 'profile' => '~Electricity', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        189 => ['name' => 'Temperatur_Vorlauf_Soll', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        190 => ['name' => 'Software_Stand_SEC_Board', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        191 => ['name' => 'Betriebszustand_SEC_Board', 'type' => 'int', 'profile' => 'WPLUX.Bet', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        192 => ['name' => 'Vierwegeventil', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        193 => ['name' => 'Verdichterdrehzahl', 'type' => 'int', 'profile' => 'WPLUX.Ver', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        194 => ['name' => 'Verdichtertemperatur_EVI_Enhanced_Vapour_Injection', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        195 => ['name' => 'Ansaugtemperatur_EVI', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        196 => ['name' => 'Ueberhitzung_EVI', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        197 => ['name' => 'Ueberhitzung_EVI_Sollwert', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        198 => ['name' => 'Kondensationstemperatur', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        199 => ['name' => 'Fluessigtemperatur_EEV_elektronisches_Expansionsventil', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        200 => ['name' => 'Unterkuehlung_EEV', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        201 => ['name' => 'Druck_EVI', 'type' => 'float', 'profile' => 'WPLUX.Pres', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        202 => ['name' => 'Spannung_Inverter', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        203 => ['name' => 'Temperarturfuehler_Heissgas_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        204 => ['name' => 'Temperaturfuehler_Waermequelleneintritt_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        205 => ['name' => 'Ansaugtemperatur_Verdampfer_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        206 => ['name' => 'Ansaugtemperatur_Verdichter_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        207 => ['name' => 'Temperatur_Verdichter_2_Heizung', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        208 => ['name' => 'Ueberhitzung_2', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        209 => ['name' => 'Ueberhitzung_Soll_2', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        210 => ['name' => 'Hochdruck_2', 'type' => 'float', 'profile' => 'WPLUX.Pres', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        211 => ['name' => 'Niederdruck_2', 'type' => 'float', 'profile' => 'WPLUX.Pres', 'conversion' => 'factor', 'factor' => 0.01, 'decimals' => 1],
        212 => ['name' => 'Eingang_Druckschalter_Hochdruck_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        213 => ['name' => 'Ausgang_Abtauventil_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        214 => ['name' => 'Ausgang_Solepumpe_Ventilator_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        215 => ['name' => 'Ausgang_Verdichter_1_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        216 => ['name' => 'Ausgang_Verdichter_Heizung_2', 'type' => 'bool', 'profile' => '~Switch', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        217 => ['name' => 'Grund_Abschaltung_0_im_Speicher', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        218 => ['name' => 'Grund_Abschaltung_1_im_Speicher', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        219 => ['name' => 'Grund_Abschaltung_2_im_Speicher', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        220 => ['name' => 'Grund_Abschaltung_3_im_Speicher3', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        221 => ['name' => 'Grund_Abschaltung_4_im_Speicher', 'type' => 'int', 'profile' => 'WPLUX.Off', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        222 => ['name' => 'Zeitstempel_Abschaltung_0_im_Speicher', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        223 => ['name' => 'Zeitstempel_Abschaltung_1_im_Speicher', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        224 => ['name' => 'Zeitstempel_Abschaltung_2_im_Speicher', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        225 => ['name' => 'Zeitstempel_Abschaltung_3_im_Speicher', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        226 => ['name' => 'Zeitstempel_Abschaltung_4_im_Speicher', 'type' => 'int', 'profile' => '~UnixTimestamp', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        227 => ['name' => 'Raumtemperatur_Istwert', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        228 => ['name' => 'Raumtemperatur_Sollwert', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        229 => ['name' => 'Temperatur_Brauchwasser_Oben', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        230 => ['name' => 'Waermepumpen_Typ_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        231 => ['name' => 'Verdichterfrequenz', 'type' => 'int', 'profile' => 'WPLUX.Ver', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        232 => ['name' => 'Vapourisation_Temperature', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        233 => ['name' => 'Liquefaction_Temperature', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        234 => ['name' => 'unbekannt_234', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        235 => ['name' => 'unbekannt_235', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        236 => ['name' => 'Verdichterfrequenz_Soll', 'type' => 'int', 'profile' => 'WPLUX.Ver', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        237 => ['name' => 'Freq_VD_Min', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        238 => ['name' => 'Freq_VD_Max', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        239 => ['name' => 'VBO_Temp_Spread_Soll', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        240 => ['name' => 'VBO_Temp_Spread_Ist', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        241 => ['name' => 'Steuersignal_Umwaelzpumpe_2', 'type' => 'float', 'profile' => '~Valve.F', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        242 => ['name' => 'HUP_Temp_Spread_Soll', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        243 => ['name' => 'HUP_Temp_Spread_Ist', 'type' => 'float', 'profile' => '~Temperature.Difference', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        244 => ['name' => 'Temperatur_VLMax', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        245 => ['name' => 'Temperatur_VLMax_2', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        246 => ['name' => 'SEC_EVi', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        247 => ['name' => 'SEC_EEV', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        248 => ['name' => 'Time_ZWE3_akt', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        249 => ['name' => 'unbekannt_249', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        250 => ['name' => 'unbekannt_250', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        251 => ['name' => 'Unterkuehlung', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        252 => ['name' => 'unbekannt_252', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        253 => ['name' => 'unbekannt_253', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        254 => ['name' => 'Flow_Rate', 'type' => 'int', 'profile' => 'WPLUX.lh', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        255 => ['name' => 'unbekannt_255', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        256 => ['name' => 'unbekannt_256', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        257 => ['name' => 'Waermeleistung', 'type' => 'float', 'profile' => 'WPLUX.kW', 'conversion' => 'factor', 'factor' => 0.001, 'decimals' => 2],
        258 => ['name' => 'RBE_Version', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        259 => ['name' => 'unbekannt_259', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        260 => ['name' => 'unbekannt_260', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        261 => ['name' => 'unbekannt_261', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        262 => ['name' => 'unbekannt_262', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        263 => ['name' => 'unbekannt_263', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        264 => ['name' => 'unbekannt_264', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        265 => ['name' => 'unbekannt_265', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        266 => ['name' => 'unbekannt_266', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        267 => ['name' => 'Desired_Room_Temperature', 'type' => 'float', 'profile' => '~Temperature', 'conversion' => 'minus_correction', 'factor' => 0.1, 'decimals' => 1],
        268 => ['name' => 'Leistungsaufnahme', 'type' => 'float', 'profile' => '~Watt', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        269 => ['name' => 'unbekannt_269', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        270 => ['name' => 'unbekannt_270', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        271 => ['name' => 'unbekannt_271', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        272 => ['name' => 'unbekannt_272', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        273 => ['name' => 'unbekannt_273', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        274 => ['name' => 'unbekannt_274', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
        275 => ['name' => 'unbekannt_275', 'type' => 'string', 'profile' => '', 'conversion' => 'factor', 'factor' => 1, 'decimals' => 1],
    ];
}
