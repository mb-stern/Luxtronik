<?php

// Benötigte Variabelprofile erstellen
if (!IPS_VariableProfileExists("WPLUX.Sec")) {
    IPS_CreateVariableProfile("WPLUX.Sec", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Sec", 0, 0, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Sec", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Sec", "", " sec"); //Präfix, Suffix
}
if (!IPS_VariableProfileExists("WPLUX.Imp")) {
    IPS_CreateVariableProfile("WPLUX.Imp", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Imp", 0, 0, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Imp", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Imp", "", " impulse"); //Präfix, Suffix
}
if (!IPS_VariableProfileExists("WPLUX.Typ")) {
    IPS_CreateVariableProfile("WPLUX.Typ", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Typ", 0, 0, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Typ", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Typ", "", ""); //Präfix, Suffix
}
if (!IPS_VariableProfileExists("WPLUX.Biv")) {
    IPS_CreateVariableProfile("WPLUX.Biv", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Biv", 1, 3, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Biv", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Biv", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.Biv", 1, "ein Verdichter darf laufen", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Biv", 2, "zwei Verdichter dürfen laufen", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Biv", 3, "zusätzlicher Wärmeerzeuger darf mitlaufen", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.BZ")) {
    IPS_CreateVariableProfile("WPLUX.BZ", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.BZ", 0, 7, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.BZ", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.BZ", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 0, "Heizen", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 1, "Warmwasser", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 2, "Schwimmbad / Photovoltaik", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 3, "EVU", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 4, "Abtauen", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 5, "Keine Anforderung", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 6, "Heizen ext. Energiequelle", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.BZ", 7, "Kühlbetrieb ", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.Off")) {
    IPS_CreateVariableProfile("WPLUX.Off", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Off", 1, 9, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Off", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Off", "", ""); //Präfix, Suffix
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
if (!IPS_VariableProfileExists("WPLUX.Comf")) {
    IPS_CreateVariableProfile("WPLUX.Comf", 0); //0 für Bool
    IPS_SetVariableProfileValues("WPLUX.Comf", 0, 1, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Comf", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Comf", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.Comf", 0, "nicht verbaut", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Comf", 1, "verbaut", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.Men1")) {
    IPS_CreateVariableProfile("WPLUX.Men1", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Men1", 0, 7, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Men1", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Men1", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 0, "Wärmepumpe läuft", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 1, "Wärmepumpe steht", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 2, "Wärmepumpe kommt", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 3, "Fehlercode Speicherplatz 0", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 4, "Abtauen", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 5, "Warte auf LIN-Verbindung", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 6, "Verdichter heizt auf", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men1", 7, "Pumpenvorlauf ", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.Men2")) {
    IPS_CreateVariableProfile("WPLUX.Men2", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Men2", 0, 1, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Men2", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Men2", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.Men2", 0, "seit :", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Men2", 1, "in : ", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.Men3")) {
    IPS_CreateVariableProfile("WPLUX.Men3", 1); //1 für Integer
    IPS_SetVariableProfileValues("WPLUX.Men3", 0, 17, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Men3", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Men3", "", ""); //Präfix, Suffix
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
if (!IPS_VariableProfileExists("WPLUX.Akt")) {
    IPS_CreateVariableProfile("WPLUX.Akt", 0); //0 für Bool
    IPS_SetVariableProfileValues("WPLUX.Akt", 0, 1, 1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Akt", 0); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Akt", "", ""); //Präfix, Suffix
    IPS_SetVariableProfileAssociation("WPLUX.Akt", 0, "inaktiv", "", -1);
    IPS_SetVariableProfileAssociation("WPLUX.Akt", 1, "aktiv", "", -1);
}
if (!IPS_VariableProfileExists("WPLUX.Pres")) {
    IPS_CreateVariableProfile("WPLUX.Pres", 2); //2 für Float
    IPS_SetVariableProfileValues("WPLUX.Pres", 0, 0, 0.1); //Min, Max, Schritt
    IPS_SetVariableProfileDigits("WPLUX.Pres", 1); //Nachkommastellen
    IPS_SetVariableProfileText("WPLUX.Pres", "", " bar"); //Präfix, Suffix
}