<?php

class Luxtronik extends IPSModuleStrict
{
    private $updateTimer;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('IPAddress', '0.0.0.0');
        $this->RegisterPropertyInteger('Port', 8889);
        $this->RegisterPropertyString('IDListe', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 0); 
        $this->RegisterPropertyBoolean('HeizungVisible', false);
        $this->RegisterPropertyBoolean('KuehlungVisible', false);
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

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Variableprofile erstellen wenn nicht vorhanden
        require_once __DIR__ . '/variable_profile.php';

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

        if ($rbesetVisible) 
        {
            $this->RegisterVariableFloat('Anpassung_RBE', 'Raumtemperatur Soll', 'WPLUX.Wset', 5);
            $this->getParameter('Anpassung_RBE'); 
            $Value = $this->GetValue('Anpassung_RBE'); 
            $this->EnableAction('Anpassung_RBE');
        } 
        else 
        {
            $this->UnregisterVariable('Anpassung_RBE');
        }

        if ($copVarId !== 0 && IPS_VariableExists($copVarId)) {
            $created = $this->RegisterVariableFloat('copfaktor', 'COP-Faktor', 'WPLUX.Cop', 6);
            if ($created) {
                $this->SetValue('copfaktor', 0);
            }
        } else {
            $this->UnregisterVariable('copfaktor');
        }

        if ($jazVarId !== 0 && IPS_VariableExists($jazVarId)) {
            $created = $this->RegisterVariableFloat('jazfaktor', 'JAZ-Faktor', 'WPLUX.Cop', 7);
            if ($created) {
                $this->SetValue('jazfaktor', 0);
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
                // holt Wert von der Lux und schreibt intern per $this->SetValue(...)
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
        // Parameterbereich von 'set_223' bis 'set_504'
        if (strpos($Ident, 'set_') === 0 && intval(substr($Ident, 4)) >= 223 && intval(substr($Ident, 4)) <= 504) 
        {
            // Funktionen aufrufen
            $this->setParameter($Ident, $Value);
            $this->getParameter($Ident);
            $this->SendDebug("Parameter $Ident", "Folgender Wert wird an die Funktion setParameter gesendet: $Value", 0);
        }
        // Weitere spezifische Werte wie 'Mode_Heizung', 'Mode_Kuehlung' usw.
        elseif (in_array($Ident, ['Mode_Heizung', 'Mode_Kuehlung', 'Mode_WW', 'Anpassung_WW', 'Anpassung_Temp', 'Anpassung_RBE'])) 
        {
            // Funktionen aufrufen
            $this->setParameter($Ident, $Value);
            $this->getParameter($Ident);
            $this->SendDebug("Parameter $Ident", "Folgender Wert wird an die Funktion setParameter gesendet: $Value", 0);
        }
    }

public function GetConfigurationForm(): string
{
    // -----------------------------
    // 1) java_3004.php laden (Namen der IDs)
    // -----------------------------
    $dataset = [];
    $javaFile = __DIR__ . '/java_3004.php';
    if (is_file($javaFile)) {
        require_once $javaFile; // erwartet: $java_dataset
        if (isset($java_dataset) && is_array($java_dataset)) {
            $dataset = $java_dataset;
        }
    }

    // -----------------------------
    // 2) Bisher gespeicherte IDListe lesen
    //    Original: List mit Spalte "id"
    //    Neu: wir erweitern um "enabled" Checkbox, behalten aber "id"!
    // -----------------------------
    $saved = json_decode($this->ReadPropertyString('IDListe'), true);
    if (!is_array($saved)) {
        $saved = [];
    }

    // Map: id -> enabled (default: true wenn vorher schon drin war)
    $enabledMap = [];
    foreach ($saved as $row) {
        if (is_array($row) && isset($row['id'])) {
            $enabledMap[(int)$row['id']] = (bool)($row['enabled'] ?? true);
        }
    }

    // Liste komplett aufbauen (alle IDs aus java_3004.php anzeigen)
    $values = [];
    foreach ($dataset as $id => $name) {
        $id = (int)$id;
        $values[] = [
            'enabled' => $enabledMap[$id] ?? false,
            'id'      => $id,
            'name'    => (string)$name
        ];
    }

    // nach ID sortieren
    usort($values, fn($a, $b) => ($a['id'] <=> $b['id']));

    // -----------------------------
    // 3) Formular (alle originalen Property-Namen verwenden!)
    // -----------------------------
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

            // ---- Timer / Zusatz-Config (aus deinem originalen form.json)
            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Timer / Zusatzfunktionen',
                'items'   => [
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

            // ---- Verbindung (originale Namen!)
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

            // ---- IDListe: statt “manuell IDs eintippen” jetzt Checkbox-Auswahl
            [
                'type'    => 'List',
                'name'    => 'IDListe',
                'caption' => "Überwachte ID's",
                'rowCount' => 15,

                // ganz wichtig: wir erzeugen values selbst und mergen Enabled aus gespeicherter Config
                'loadValuesFromConfiguration' => false,

                // Benutzer soll NICHT mehr add/delete machen, sondern nur Checkboxen setzen
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
            ]
        ],

        'actions' => [
            [
                'type'    => 'Button',
                'caption' => 'Jetzt aktualisieren',
                'onClick' => 'WPLUX_Update($id);'
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

    // Namen der Variablen laden (3004 Berechnungen lesen)
    require_once __DIR__ . '/java_3004.php';

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
}

    
    private function convertValueBasedOnID($value, $id)
    {
        // Hier erfolgt die Konvertierung der Werte basierend auf der 'id'
        switch ($id) 
        {
            case (($id >= 10 && $id <= 28) || $id == 122 || ($id >= 136 && $id <= 137) || ($id >= 142 && $id <= 144) || ($id >= 151 && $id <= 154) || ($id >= 175 && $id <= 179) ||$id == 183 || $id == 189 || ($id >= 194 && $id <= 200) || ($id >= 208 && $id <= 209) || ($id >= 227 && $id <= 229) || ($id >= 232 && $id <= 233) || ($id >= 239 && $id <= 240)|| ($id >= 242 && $id <= 243) || $id == 267):
                $minusTest = $value * 0.1;
                if ($minusTest > 429496000) 
                {
                    $value -= 4294967296;
                    $value *= 0.1; 
                } 
                else 
                {
                    $value *= 0.1; 
                }
                return round($value, 1); 

            case (($id >= 67 && $id <= 77) || $id == 120 || $id == 123 || $id == 141|| $id == 158 || $id == 161): //Laufzeit umrechnen und in Stunden und Minuten ausgeben
                $time = $value;
                $hours = floor($time / (60 * 60));
                $time -= $hours * (60 * 60);
                $minutes = floor($time / 60);
                $time -= $minutes * 60;
                $value = "{$hours}h {$minutes}m";
                return ($value); 
            
                case ($id == 56 || $id == 58 || ($id >= 60 && $id <= 66)): //Laufzeit umrechnen und in Stunden ausgeben
                $time = $value;
                $hours = floor($time / (60 * 60));
                $time -= $hours * (60 * 60);
                $value = $hours;
                return ($value);

                case ($id >= 81 && $id <= 90):
                $ascii = $value;
                $value = chr($ascii); // Konvertiert die Dezimalzahl in ASCII Zeichen
                return ($value);
                
                case ($id >= 91 && $id <= 94):
                $decimalValue = $value;
                $value = long2ip($decimalValue); // Konvertiert die Dezimalzahl in eine IP-Adresse
                return ($value);

            case ($id == 147 || ($id >= 156 && $id <= 157) || ($id >= 162 && $id <= 165) || ($id >= 168 && $id <= 169) || ($id >= 180 && $id <= 181) || ($id >= 187 && $id <= 188) || $id == 201 || ($id >= 210 && $id <= 211) || $id == 58):
                return round($value * 0.01, 1);

            case ($id == 257):
                return round($value * 0.001, 2);

            
            
            default:
                return round($value * 1, 1); // Standardmäßig Konvertierung
        }
    }
            
    private function CreateOrUpdateVariable(string $ident, mixed $value, int $id): void
    {
        // Variable erstellen und Profil zuordnen
        switch ($id) {
            case (($id >= 10 && $id <= 28) || $id == 122 || $id == 136 || $id == 137 || ($id >= 142 && $id <= 144) || ($id >= 175 && $id <= 177) || $id == 189 || ($id >= 194 && $id <= 195) || ($id >= 198 && $id <= 200) || ($id >= 227 && $id <= 229) || ($id >= 232 && $id <= 233) || $id == 267):
                $this->RegisterVariableFloat($ident, $ident, '~Temperature', $id);
                break;

            case (($id >= 29 && $id <= 55) || ($id >= 138 && $id <= 140) || $id == 146 || ($id >= 166 && $id <= 167) || ($id >= 170 && $id <= 171) || $id == 182 || $id == 186 || ($id >= 212 && $id <= 216)):
                $this->RegisterVariableBoolean($ident, $ident, '~Switch', $id);
                break;

            case ($id == 56 || $id == 58 || ($id >= 60 && $id <= 66)):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Std', $id);
                break;

            case ($id == 57 || $id == 59):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Imp', $id);
                break;

            case ($id == 78):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Typ', $id);
                break;

            case ($id == 79):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Biv', $id);
                break;

            case ($id == 80):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.BZ', $id);
                break;

            case ((($id >= 95 && $id <= 99) || ($id >= 111 && $id <= 115) || $id == 134) || ($id >= 222 && $id <= 226)):
                $this->RegisterVariableInteger($ident, $ident, '~UnixTimestamp', $id);
                break;

            case (($id >= 106 && $id <= 110) || ($id >= 217 && $id <= 221)):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Off', $id);
                break;

            case ($id == 116 || $id == 172 || $id == 174):
                $this->RegisterVariableBoolean($ident, $ident, 'WPLUX.Comf', $id);
                break;

            case ($id == 117):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Men1', $id);
                break;

            case ($id == 118):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Men2', $id);
                break;

            case ($id == 119):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Men3', $id);
                break;

            case ($id == 124):
                $this->RegisterVariableBoolean($ident, $ident, 'WPLUX.Akt', $id);
                break;

            case ($id == 147 || ($id >= 156 && $id <= 157) || ($id >= 162 && $id <= 165) || ($id >= 168 && $id <= 169)):
                $this->RegisterVariableFloat($ident, $ident, '~Volt', $id);
                break;

            case ($id == 173 || $id == 254):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.lh', $id);
                break;

            case (($id >= 178 && $id <= 179) || ($id >= 196 && $id <= 197) || ($id >= 208 && $id <= 209) || ($id >= 239 && $id <= 240) || ($id >= 242 && $id <= 243)):
                $this->RegisterVariableFloat($ident, $ident, '~Temperature.Difference', $id);
                break;

            case (($id >= 180 && $id <= 181) || $id == 201 || ($id >= 210 && $id <= 211)):
                $this->RegisterVariableFloat($ident, $ident, 'WPLUX.Pres', $id);
                break;

            case ($id == 183 || $id == 241):
                $this->RegisterVariableFloat($ident, $ident, '~Valve.F', $id);
                break;

            case ($id == 184):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Fan', $id);
                break;

            case (($id >= 151 && $id <= 154) || ($id >= 187 && $id <= 188)):
                $this->RegisterVariableFloat($ident, $ident, '~Electricity', $id);
                break;

            case ($id == 191):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Bet', $id);
                break;

            case ($id == 193 || $id == 231 || $id == 236):
                $this->RegisterVariableInteger($ident, $ident, 'WPLUX.Ver', $id);
                break;

            case ($id == 257):
                $this->RegisterVariableFloat($ident, $ident, 'WPLUX.kW', $id);
                break;

            default:
                // Fallback: Float ohne spezielles Profil
                $this->RegisterVariableFloat($ident, $ident, '', $id);
                break;
        }

        // STRICT-KERN: Werte immer übers Modul setzen
        $this->SetValue($ident, $value);

        $this->SendDebug('Variable aktualisiert', "ID: $id, Ident: $ident, Wert: $value", 0);
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
        
                // Wert setzen und Debug-Info senden
                $this->SetValue($mode, $value);
                $this->SendDebug("Parameter $mode", "Wert des Parameters $mode: $value von der Lux geholt und in Variable gespeichert", 0);
                break;
        
            default: // Hier werden die ganzen Timer geholt
                if (strpos($mode, 'set_') === 0) {
                    $index = (int) substr($mode, 4);
                    if ($index >= 223 && $index <= 505) {
                        $this->SetValue($mode, $datenRaw[$index] - 3600);
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
            $this->SetValue('copfaktor', 0);
            $this->SendDebug('COP-Faktor', 'Eingangsleistung (kw_in) ist 0. COP-Faktor wurde auf 0 gesetzt.', 0);
            return;
        }

        $cop = $value / (float)$kw_in;
        $this->SetValue('copfaktor', $cop);
        $this->SendDebug('COP-Faktor', "Faktor: $cop berechnet (kw_in=$kw_in, Wärmeleistung=$value)", 0);
    }

    private function calc_jaz(string $mode, float $value_out): void
    {
        $jazfaktorVariableID = @$this->GetIDForIdent('jazfaktor');
        $kwhInVarId = $this->ReadPropertyInteger('kwhin');
        $kwhOutVarId = $this->ReadPropertyInteger('kwhout');

        if ($mode !== 'jaz' || $kwhInVarId === 0 || !IPS_VariableExists($kwhInVarId) || $jazfaktorVariableID === false) {
            return;
        }

        $kwh_in = (float) GetValue($kwhInVarId);

        // externen Wärmemengenzähler optional
        if ($kwhOutVarId !== 0 && IPS_VariableExists($kwhOutVarId)) {
            $kwh_out = (float) GetValue($kwhOutVarId);
            $this->SendDebug('JAZ-Berechnung', 'Berechnung des JAZ über externen Wärmemengenzähler', 0);
        } else {
            $kwh_out = (float) $value_out;
            $this->SendDebug('JAZ-Berechnung', 'Berechnung des JAZ über internen Wärmemengenzähler', 0);
        }
    }

    public function reset_jaz() //Startwerte der JAZ-Berechnung zurücksetzen
    {
        $this->WriteAttributeFloat('start_kwh_in', 0);
        $this->WriteAttributeFloat('start_value_out', 0);
        $this->SendDebug("JAZ-Reset", "Der Reset der Start-Werte zur JAZ-Berechnung wurde durchgeführt", 0);
    }
}