<?php

// Benötigte Variablenprofile erstellen

// WPLUX.Imp
if (!IPS_VariableProfileExists("WPLUX.Imp")) {
    IPS_CreateVariableProfile("WPLUX.Imp", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Imp erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Imp", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.Imp", 0);
IPS_SetVariableProfileText("WPLUX.Imp", "", " impulse");

// WPLUX.Typ
if (!IPS_VariableProfileExists("WPLUX.Typ")) {
    IPS_CreateVariableProfile("WPLUX.Typ", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Typ erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Typ", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.Typ", 0);
IPS_SetVariableProfileText("WPLUX.Typ", "", "");

// WPLUX.Biv
if (!IPS_VariableProfileExists("WPLUX.Biv")) {
    IPS_CreateVariableProfile("WPLUX.Biv", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Biv erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Biv", 1, 3, 1);
IPS_SetVariableProfileDigits("WPLUX.Biv", 0);
IPS_SetVariableProfileText("WPLUX.Biv", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Biv", 1, "ein Verdichter darf laufen", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Biv", 2, "zwei Verdichter dürfen laufen", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Biv", 3, "zusätzlicher Wärmeerzeuger darf mitlaufen", "", -1);

// WPLUX.BZ
if (!IPS_VariableProfileExists("WPLUX.BZ")) {
    IPS_CreateVariableProfile("WPLUX.BZ", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.BZ erstellt", 0);
}
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

// WPLUX.Off
if (!IPS_VariableProfileExists("WPLUX.Off")) {
    IPS_CreateVariableProfile("WPLUX.Off", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Off erstellt", 0);
}
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

// WPLUX.Comf
if (!IPS_VariableProfileExists("WPLUX.Comf")) {
    IPS_CreateVariableProfile("WPLUX.Comf", 0); // 0 = Bool
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Comf erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Comf", 0, 1, 1);
IPS_SetVariableProfileDigits("WPLUX.Comf", 0);
IPS_SetVariableProfileText("WPLUX.Comf", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Comf", 0, "nicht verbaut", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Comf", 1, "verbaut", "", -1);

// WPLUX.Men1
if (!IPS_VariableProfileExists("WPLUX.Men1")) {
    IPS_CreateVariableProfile("WPLUX.Men1", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men1 erstellt", 0);
}
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

// WPLUX.Men2
if (!IPS_VariableProfileExists("WPLUX.Men2")) {
    IPS_CreateVariableProfile("WPLUX.Men2", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men2 erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Men2", 0, 1, 1);
IPS_SetVariableProfileDigits("WPLUX.Men2", 0);
IPS_SetVariableProfileText("WPLUX.Men2", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Men2", 0, "seit :", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Men2", 1, "in : ", "", -1);

// WPLUX.Men3
if (!IPS_VariableProfileExists("WPLUX.Men3")) {
    IPS_CreateVariableProfile("WPLUX.Men3", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Men3 erstellt", 0);
}
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

// WPLUX.Akt
if (!IPS_VariableProfileExists("WPLUX.Akt")) {
    IPS_CreateVariableProfile("WPLUX.Akt", 0); // 0 = Bool
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Akt erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Akt", 0, 1, 1);
IPS_SetVariableProfileDigits("WPLUX.Akt", 0);
IPS_SetVariableProfileText("WPLUX.Akt", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Akt", 0, "inaktiv", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Akt", 1, "aktiv", "", -1);

// WPLUX.Pres
if (!IPS_VariableProfileExists("WPLUX.Pres")) {
    IPS_CreateVariableProfile("WPLUX.Pres", 2); // 2 = Float
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Pres erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Pres", 0, 0, 0.1);
IPS_SetVariableProfileDigits("WPLUX.Pres", 1);
IPS_SetVariableProfileText("WPLUX.Pres", "", " bar");

// WPLUX.Fan
if (!IPS_VariableProfileExists("WPLUX.Fan")) {
    IPS_CreateVariableProfile("WPLUX.Fan", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Fan erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Fan", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.Fan", 0);
IPS_SetVariableProfileText("WPLUX.Fan", "", " rpm");

// WPLUX.Ver
if (!IPS_VariableProfileExists("WPLUX.Ver")) {
    IPS_CreateVariableProfile("WPLUX.Ver", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Ver erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Ver", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.Ver", 0);
IPS_SetVariableProfileText("WPLUX.Ver", "", " rpm");


// WPLUX.HzState
if (!IPS_VariableProfileExists("WPLUX.HzState")) {
    IPS_CreateVariableProfile("WPLUX.HzState", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.HzState erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.HzState", 0, 4, 1);
IPS_SetVariableProfileDigits("WPLUX.HzState", 0);
IPS_SetVariableProfileText("WPLUX.HzState", "", "");
IPS_SetVariableProfileAssociation("WPLUX.HzState", 0, "Aus", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.HzState", 1, "Normal", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.HzState", 2, "Abgesenkt", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.HzState", 3, "Heizgrenze", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.HzState", 4, "Frostschutz", "", -1);

// WPLUX.Bet
if (!IPS_VariableProfileExists("WPLUX.Bet")) {
    IPS_CreateVariableProfile("WPLUX.Bet", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Bet erstellt", 0);
}
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

// WPLUX.lh
if (!IPS_VariableProfileExists("WPLUX.lh")) {
    IPS_CreateVariableProfile("WPLUX.lh", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.lh erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.lh", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.lh", 0);
IPS_SetVariableProfileText("WPLUX.lh", "", " l/h");

// WPLUX.Wwhe
if (!IPS_VariableProfileExists("WPLUX.Wwhe")) {
    IPS_CreateVariableProfile("WPLUX.Wwhe", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Wwhe erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Wwhe", 0, 4, 0);
IPS_SetVariableProfileDigits("WPLUX.Wwhe", 0);
IPS_SetVariableProfileText("WPLUX.Wwhe", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 0, "Automatik", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 1, "Zus. Wärmeerzeugun", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 2, "Party", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 3, "Ferien", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Wwhe", 4, "Aus", "", -1);

// WPLUX.Kue
if (!IPS_VariableProfileExists("WPLUX.Kue")) {
    IPS_CreateVariableProfile("WPLUX.Kue", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Kue erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Kue", 0, 1, 0);
IPS_SetVariableProfileDigits("WPLUX.Kue", 0);
IPS_SetVariableProfileText("WPLUX.Kue", "", "");
IPS_SetVariableProfileAssociation("WPLUX.Kue", 0, "Aus", "", -1);
IPS_SetVariableProfileAssociation("WPLUX.Kue", 1, "Automatik", "", -1);

// WPLUX.Tset
if (!IPS_VariableProfileExists("WPLUX.Tset")) {
    IPS_CreateVariableProfile("WPLUX.Tset", 2); // 2 = Float
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Tset erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Tset", -5, 5, 0.5);
IPS_SetVariableProfileDigits("WPLUX.Tset", 1);
IPS_SetVariableProfileText("WPLUX.Tset", "", " °C");

// WPLUX.Wset
if (!IPS_VariableProfileExists("WPLUX.Wset")) {
    IPS_CreateVariableProfile("WPLUX.Wset", 2); // 2 = Float
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Wset erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Wset", 30, 65, 0.5);
IPS_SetVariableProfileDigits("WPLUX.Wset", 1);
IPS_SetVariableProfileText("WPLUX.Wset", "", " °C");

// WPLUX.Std
if (!IPS_VariableProfileExists("WPLUX.Std")) {
    IPS_CreateVariableProfile("WPLUX.Std", 1); // 1 = Integer
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Std erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Std", 0, 0, 1);
IPS_SetVariableProfileDigits("WPLUX.Std", 0);
IPS_SetVariableProfileText("WPLUX.Std", "", " Std.");

// WPLUX.kW
if (!IPS_VariableProfileExists("WPLUX.kW")) {
    IPS_CreateVariableProfile("WPLUX.kW", 2); // 2 = Float
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.kW erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.kW", 0, 0, 0.01);
IPS_SetVariableProfileDigits("WPLUX.kW", 2);
IPS_SetVariableProfileText("WPLUX.kW", "", " kW");

// WPLUX.Cop
if (!IPS_VariableProfileExists("WPLUX.Cop")) {
    IPS_CreateVariableProfile("WPLUX.Cop", 2); // 2 = Float
    $this->SendDebug("Variablenprofil", "Variablenprofil WPLUX.Cop erstellt", 0);
}
IPS_SetVariableProfileValues("WPLUX.Cop", 0, 0, 0.1);
IPS_SetVariableProfileDigits("WPLUX.Cop", 1);
IPS_SetVariableProfileText("WPLUX.Cop", "", "");
