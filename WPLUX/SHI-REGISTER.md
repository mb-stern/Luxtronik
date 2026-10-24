# SHI-Register in Version 5.0

Quelle: Herstellerhandbuch **83026900aDE**, Seiten 6–32. Literaladressen, Offset 0.
Werte und Grenzen unten sind in der angegebenen Zieleinheit, keine Rohwerte.
Die Spalte Schnittstelle in der Konfiguration unterscheidet Java, Modbus und beide.

## Input-Register (FC04)

| Adresse | Ident | Einheit | Wertebereich / Bedeutung |
| --- | --- | --- | --- |
| 10000 | `SHI_Status_Waermeerzeuger` |  | Bitmaske: 1 VD1, 2 VD2, 4 ZWE1, 8 ZWE2, 16 ZWE3 |
| 10002 | `Betriebszustand` |  | 0: Heizen, 1: Warmwasser, 2: Schwimmbad, 3: EVU-Sperre, 4: Abtauen, 5: Keine Anforderung, 6: Nicht belegt, 7: Kühlen |
| 10003 | `SHI_Status_Heizen` |  | 0: Aus, 1: Keine Anforderung, 2: Anforderung, 3: Aktiv |
| 10004 | `SHI_Status_Warmwasser` |  | 0: Aus, 1: Keine Anforderung, 2: Anforderung, 3: Aktiv |
| 10006 | `SHI_Status_Kuehlung` |  | 0: Aus, 1: Keine Anforderung, 2: Anforderung, 3: Aktiv |
| 10007 | `SHI_Status_Schwimmbad` |  | 0: Aus, 1: Keine Anforderung, 2: Anforderung, 3: Aktiv |
| 10100 | `Ruecklauftemperatur_Heizkreis` | °C |  |
| 10101 | `Ruecklauf_Soll_Heizkreis` | °C |  |
| 10102 | `Ruecklauftemperatur_im_Trennspeicher` | °C |  |
| 10103 | `SHI_Ruecklauf_Max` | °C |  |
| 10104 | `SHI_Ruecklauf_Min` | °C |  |
| 10105 | `Vorlauftemperatur_Heizkreis` | °C |  |
| 10106 | `Raumtemperatur_Istwert` | °C |  |
| 10107 | `SHI_Grenztemperatur_Heizen` | °C |  |
| 10108 | `Aussentemperatur` | °C |  |
| 10109 | `Durchschnittstemperatur_Aussen_ueber_24_h` | °C |  |
| 10110 | `Waermequellen_Eintrittstemperatur` | °C |  |
| 10111 | `Waermequellen_Austrittstemperatur` | °C |  |
| 10112 | `Temperatur_VLMax` | °C |  |
| 10113 | `Temperatur_Vorlauf_Soll` | °C |  |
| 10120 | `Warmwasser_Ist_Temperatur` | °C |  |
| 10121 | `Warmwasser_Soll_Temperatur` | °C |  |
| 10122 | `SHI_Warmwasser_Min` | °C |  |
| 10123 | `SHI_Warmwasser_Max` | °C |  |
| 10124 | `SHI_Grenztemperatur_Warmwasser` | °C |  |
| 10140 | `Mischkreis_1_Vorlauftemperatur` | °C |  |
| 10141 | `Mischkreis_1_Vorlauf_Soll_Temperatur` | °C |  |
| 10142 | `SHI_Mischkreis_1_Vorlauf_Min` | °C |  |
| 10143 | `SHI_Mischkreis_1_Vorlauf_Max` | °C |  |
| 10150 | `Mischkreis_2_Vorlauftemperatur` | °C |  |
| 10151 | `Mischkreis_2_Vorlauf_Soll_Temperatur` | °C |  |
| 10152 | `SHI_Mischkreis_2_Vorlauf_Min` | °C |  |
| 10153 | `SHI_Mischkreis_2_Vorlauf_Max` | °C |  |
| 10160 | `Mischkreis_3_Vorlauftemperatur` | °C |  |
| 10161 | `Mischkreis_3_Vorlauf_Soll_Temperatur` | °C |  |
| 10162 | `SHI_Mischkreis_3_Vorlauf_Min` | °C |  |
| 10163 | `SHI_Mischkreis_3_Vorlauf_Max` | °C |  |
| 10201 | `SHI_Fehlernummer` |  |  |
| 10202 | `SHI_Puffertyp` |  | 0: Reihenpuffer, 1: Trennpuffer, 2: Multifunktionspuffer |
| 10203 | `SHI_Schaltspielsperre_Minuten` | min |  |
| 10204 | `SHI_Mindestlaufzeit_Minuten` | min |  |
| 10207 | `Freigabe_Kuehlung` |  | 0: Aus, 1: Ein |
| 10300 | `Waermeleistung` | kW |  |
| 10301 | `Leistungsaufnahme` | W |  |
| 10302 | `SHI_Eingangsleistung_Min` | kW |  |
| 10310 | `SHI_Elektrische_Energie_Gesamt` | kWh |  |
| 10312 | `SHI_Elektrische_Energie_Heizung` | kWh |  |
| 10314 | `SHI_Elektrische_Energie_Warmwasser` | kWh |  |
| 10316 | `SHI_Elektrische_Energie_Kuehlung` | kWh |  |
| 10318 | `SHI_Elektrische_Energie_Schwimmbad` | kWh |  |
| 10320 | `Waermemengenzaehler_Gesamt` | kWh |  |
| 10322 | `Waermemengenzaehler_Heizung` | kWh |  |
| 10324 | `Waermemengenzaehler_Brauchwasser` | kWh |  |
| 10326 | `SHI_Thermische_Energie_Kuehlung` | kWh |  |
| 10328 | `Waermemengenzaehler_Schwimmbad` | kWh |  |
| 10350 | `SHI_Pumpe_BOSUP` |  | 0: Aus, 1: Ein |
| 10351 | `SHI_Pumpe_Mischkreis_1` |  | 0: Aus, 1: Ein |
| 10352 | `Ausgang_Pumpe_Mischkreis_2` |  | 0: Aus, 1: Ein |
| 10353 | `Pumpe_Mischkreis_3` |  | 0: Aus, 1: Ein |
| 10354 | `Ausgang_Heizungsumwaelzpumpe` |  | 0: Aus, 1: Ein |
| 10355 | `Ausgang_Brauchwasserpumpe_Umstellventil` |  | 0: Aus, 1: Ein |
| 10356 | `Ausgang_Zirkulationspumpe` |  | 0: Aus, 1: Ein |
| 10360 | `SHI_SG_1` |  | 0: Aus, 1: Ein |
| 10361 | `SHI_SG_2` |  | 0: Aus, 1: Ein |
| 10400 | `SHI_Software_Plattform` |  |  |
| 10401 | `SHI_Software_Major` |  |  |
| 10402 | `SHI_Software_Minor` |  |  |
| 10404 | `Betriebsstunden_Waermepumpe` | h |  |
| 10406 | `Betriebsstunden_Heizung` | h |  |
| 10408 | `Betriebsstunden_Warmwasser` | h |  |
| 10410 | `Betriebsstunden_Kuehlung` | h |  |
| 10412 | `SHI_Betriebsstunden_Schwimmbad` | h |  |
| 10416 | `Betriebstundenzaehler_Solar` | h |  |
| 10500 | `SHI_Extra_Warmwasser_Soll` | °C |  |
| 10501 | `SHI_Extra_Warmwasser_Dauer` | min |  |
| 10502 | `SHI_Extra_Warmwasser_Restzeit` | min |  |

## Konfigurationsmeldungen (FC02)

| Adresse | Ident | Einheit | Wertebereich / Bedeutung |
| --- | --- | --- | --- |
| 10000 | `SHI_Konfig_Heizen` |  | 0: Aus, 1: Ein |
| 10001 | `SHI_Konfig_Warmwasser` |  | 0: Aus, 1: Ein |
| 10002 | `SHI_Konfig_Kuehlung` |  | 0: Aus, 1: Ein |
| 10003 | `SHI_Konfig_Schwimmbad` |  | 0: Aus, 1: Ein |
| 10004 | `SHI_Konfig_Solar` |  | 0: Aus, 1: Ein |
| 10005 | `SHI_Konfig_RBE` |  | 0: Aus, 1: Ein |
| 10006 | `SHI_Konfig_Heizen_MK1` |  | 0: Aus, 1: Ein |
| 10007 | `SHI_Konfig_Kuehlen_MK1` |  | 0: Aus, 1: Ein |
| 10008 | `SHI_Konfig_Heizen_MK2` |  | 0: Aus, 1: Ein |
| 10009 | `SHI_Konfig_Kuehlen_MK2` |  | 0: Aus, 1: Ein |
| 10010 | `SHI_Konfig_Heizen_MK3` |  | 0: Aus, 1: Ein |
| 10011 | `SHI_Konfig_Kuehlen_MK3` |  | 0: Aus, 1: Ein |

## Holding-Register (FC03 / Schreiben FC16)

| Adresse | Ident | Einheit | Wertebereich / Bedeutung |
| --- | --- | --- | --- |
| 10000 | `SHI_Modus_Heizen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset, 3: Level; Funktion DI 10000 muss aktiv sein; setzt HR 10065=0 |
| 10001 | `SHI_Sollwert_Heizen` | °C | 15 … 75; Funktion DI 10000 muss aktiv sein; setzt HR 10065=0, HR 10000=1 |
| 10002 | `Anpassung_Temp` | K | -20 … 20; Funktion DI 10000 muss aktiv sein; setzt HR 10065=0, HR 10000=2 |
| 10003 | `SHI_Level_Heizen` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10000 muss aktiv sein; setzt HR 10065=0, HR 10000=3 |
| 10005 | `SHI_Modus_Warmwasser` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset, 3: Level; Funktion DI 10001 muss aktiv sein |
| 10006 | `Anpassung_WW` | °C | 30 … 75; Funktion DI 10001 muss aktiv sein; setzt HR 10005=1 |
| 10007 | `SHI_Offset_Warmwasser` | K | -20 … 20; Funktion DI 10001 muss aktiv sein; setzt HR 10005=2 |
| 10008 | `SHI_Level_Warmwasser` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10001 muss aktiv sein; setzt HR 10005=3 |
| 10010 | `SHI_Modus_MK1_Heizen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset, 3: Level; Funktion DI 10006 muss aktiv sein; setzt HR 10065=0 |
| 10011 | `SHI_Sollwert_MK1_Heizen` | °C | 20 … 65; Funktion DI 10006 muss aktiv sein; setzt HR 10065=0, HR 10010=1 |
| 10012 | `SHI_Offset_MK1_Heizen` | K | -5 … 5; Funktion DI 10006 muss aktiv sein; setzt HR 10065=0, HR 10010=2 |
| 10013 | `SHI_Level_MK1_Heizen` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10006 muss aktiv sein; setzt HR 10065=0, HR 10010=3 |
| 10015 | `SHI_Modus_MK1_Kuehlen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset; Funktion DI 10007 muss aktiv sein |
| 10016 | `SHI_Sollwert_MK1_Kuehlen` | °C | 5 … 25; Funktion DI 10007 muss aktiv sein; setzt HR 10015=1 |
| 10017 | `SHI_Offset_MK1_Kuehlen` | K | -5 … 5; Funktion DI 10007 muss aktiv sein; setzt HR 10015=2 |
| 10020 | `SHI_Modus_MK2_Heizen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset, 3: Level; Funktion DI 10008 muss aktiv sein; setzt HR 10065=0 |
| 10021 | `SHI_Sollwert_MK2_Heizen` | °C | 20 … 65; Funktion DI 10008 muss aktiv sein; setzt HR 10065=0, HR 10020=1 |
| 10022 | `SHI_Offset_MK2_Heizen` | K | -5 … 5; Funktion DI 10008 muss aktiv sein; setzt HR 10065=0, HR 10020=2 |
| 10023 | `SHI_Level_MK2_Heizen` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10008 muss aktiv sein; setzt HR 10065=0, HR 10020=3 |
| 10025 | `SHI_Modus_MK2_Kuehlen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset; Funktion DI 10009 muss aktiv sein |
| 10026 | `SHI_Sollwert_MK2_Kuehlen` | °C | 5 … 25; Funktion DI 10009 muss aktiv sein; setzt HR 10025=1 |
| 10027 | `SHI_Offset_MK2_Kuehlen` | K | -5 … 5; Funktion DI 10009 muss aktiv sein; setzt HR 10025=2 |
| 10030 | `SHI_Modus_MK3_Heizen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset, 3: Level; Funktion DI 10010 muss aktiv sein; setzt HR 10065=0 |
| 10031 | `SHI_Sollwert_MK3_Heizen` | °C | 20 … 65; Funktion DI 10010 muss aktiv sein; setzt HR 10065=0, HR 10030=1 |
| 10032 | `SHI_Offset_MK3_Heizen` | K | -5 … 5; Funktion DI 10010 muss aktiv sein; setzt HR 10065=0, HR 10030=2 |
| 10033 | `SHI_Level_MK3_Heizen` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10010 muss aktiv sein; setzt HR 10065=0, HR 10030=3 |
| 10035 | `SHI_Modus_MK3_Kuehlen` |  | 0: Kein Einfluss, 1: Sollwert, 2: Offset; Funktion DI 10011 muss aktiv sein |
| 10036 | `SHI_Sollwert_MK3_Kuehlen` | °C | 5 … 25; Funktion DI 10011 muss aktiv sein; setzt HR 10035=1 |
| 10037 | `SHI_Offset_MK3_Kuehlen` | K | -5 … 5; Funktion DI 10011 muss aktiv sein; setzt HR 10035=2 |
| 10040 | `SHI_LPC_Modus` |  | 0: Keine Begrenzung, 1: Soft Limit, 2: Hard Limit |
| 10041 | `SHI_PC_Limit` | kW | 0 … 30 |
| 10050 | `SHI_Sperre_Heizen` |  | 0: Aus, 1: Ein; Funktion DI 10000 muss aktiv sein |
| 10051 | `SHI_Sperre_Warmwasser` |  | 0: Aus, 1: Ein; Funktion DI 10001 muss aktiv sein |
| 10052 | `SHI_Sperre_Kuehlung` |  | 0: Aus, 1: Ein; Funktion DI 10002 muss aktiv sein |
| 10053 | `SHI_Sperre_Schwimmbad` |  | 0: Aus, 1: Ein; Funktion DI 10003 muss aktiv sein |
| 10060 | `Anpassung_RBE` | °C | 15 … 30; Funktion DI 10005 muss aktiv sein |
| 10065 | `SHI_Overall_Modus` |  | 0: Individuell, 2: Globaler Offset, 3: Globales Level; Funktion DI 10000 muss aktiv sein |
| 10066 | `SHI_Overall_Offset` | K | -5 … 5; Funktion DI 10000 muss aktiv sein; setzt HR 10065=2 |
| 10067 | `SHI_Overall_Level` |  | 0: Kein Einfluss, 1: Überhöht mit Zeitprogramm, 2: Überhöht ohne Zeitprogramm, 3: Reduziert; Funktion DI 10000 muss aktiv sein; setzt HR 10065=3 |
| 10070 | `SHI_Zirkulation_Start` |  | 0: Kein Einfluss, 1: Starten |
| 10071 | `SHI_Extra_Warmwasser` |  | 0: Aus, 1: Ein; Funktion DI 10001 muss aktiv sein |

Die Modus-Tabellen nennen teilweise Maximum 2, beschreiben aber ausdrücklich Level 3.
Für Heizungs-/Warmwasser-Modi wird deshalb die beschriebene Auswahl 0–3 unterstützt;
Kühlmodi bleiben auf 0–2 begrenzt, der Gesamtmodus erlaubt ausschließlich 0/2/3.
Fehlende Firmwarefunktionen werden anhand der Geräteantwort erkannt.

Weitere Hinweise zu Voraussetzungen, Rücknahme und Schreibbestätigungen: [SHI-TEST.md](SHI-TEST.md).
