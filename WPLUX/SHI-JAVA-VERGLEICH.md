# Sollwerte: Java und Modbus/SHI

Verglichen werden die tatsächlich implementierten Java-Schreibaktionen dieses Moduls
mit dem Herstellerhandbuch **83026900aDE**. Eine fehlende Java-Aktion in dieser
Tabelle bedeutet nicht, dass jede Luxtronik-Firmware diesen Parameter grundsätzlich
nicht über Java bereitstellen könnte.

## Bestehende Variablen mit vergleichbarer Funktion

Diese Idents werden beibehalten. Die aktive Schnittstelle bestimmt den Schreibweg;
Modbus prüft seine eigenen Grenzen und Voraussetzungen. Es werden dafür jetzt keine
separaten Sollwertvariablen eingeführt.

| Ident | Java-Validierung | Modbus/SHI | Voraussetzung / Unterschied |
| --- | --- | --- | --- |
| `Anpassung_WW` | Parameter 105, 30–65 °C | HR 10006, 30–75 °C | Warmwasser aktiv, SHI-Warmwasser-Modus 1; zusätzlich aktuelle Min-/Max-Grenzen des Reglers |
| `Anpassung_Temp` | Parameter 1, −5 bis +5 K | HR 10002, −20 bis +20 K | Offset auf Rücklauf-Soll; Heizen aktiv, Gesamtmodus 0 und Heizmodus 2 |
| `Anpassung_RBE` | Parameter 1148, 0–35 °C | HR 10060, 15–30 °C | RBE aktiv und Raumtemperatureinfluss ungleich 0; keine parallelen SHI-Heiz-/Mischkreisvorgaben |

Bereiche oben sind die Protokollvalidierung. Vorhandene Variablenprofile bleiben
erhalten und können die angebotene Auswahl weiter einschränken. Java schreibt
Reglerparameter; SHI setzt externe Vorgaben. Laut Handbuch setzt der Regler diese
SHI-Vorgaben nach **15 Minuten ohne Kommunikation** auf Standard zurück.

## Zusätzliche Modbus-Sollwerte ohne bisherige Java-Schreibaktion im Modul

| Idents | Holding-Register | Bereich | Bedeutung und Unterschied |
| --- | --- | --- | --- |
| `SHI_Sollwert_Heizen` | 10001 | 15–75 °C | Absolute Rücklauf-Solltemperatur statt Temperaturkorrektur; nicht mit `Anpassung_Temp` gleichsetzen |
| `SHI_Sollwert_MK1_Heizen`, `SHI_Sollwert_MK2_Heizen`, `SHI_Sollwert_MK3_Heizen` | 10011 / 10021 / 10031 | 20–65 °C | Eigene Vorlauf-Solltemperatur je Mischkreis beim Heizen |
| `SHI_Sollwert_MK1_Kuehlen`, `SHI_Sollwert_MK2_Kuehlen`, `SHI_Sollwert_MK3_Kuehlen` | 10016 / 10026 / 10036 | 5–25 °C | Eigene Vorlauf-Solltemperatur je Mischkreis beim Kühlen |
| `SHI_Offset_MK1_Heizen`, `SHI_Offset_MK2_Heizen`, `SHI_Offset_MK3_Heizen` | 10012 / 10022 / 10032 | −5 bis +5 K | Vorlauf-Korrektur nur des jeweiligen Mischkreises beim Heizen |
| `SHI_Offset_MK1_Kuehlen`, `SHI_Offset_MK2_Kuehlen`, `SHI_Offset_MK3_Kuehlen` | 10017 / 10027 / 10037 | −5 bis +5 K | Vorlauf-Korrektur nur des jeweiligen Mischkreises beim Kühlen |
| `SHI_Overall_Offset` | 10066 | −5 bis +5 K | Gemeinsame Korrektur für Hauptheizkreis und alle Mischkreise; nicht nur der Rücklauf-Sollwert der Wärmepumpe |
| `SHI_PC_Limit` | 10041 | 0–30 kW | Vorgabe der maximalen elektrischen Leistungsaufnahme; erfordert zuvor gewählten Soft-/Hard-Limit-Modus |

Die gemessenen Solltemperatur-Variablen, etwa `Ruecklauf_Soll_Heizkreis` oder
`Mischkreis_1_Vorlauf_Soll_Temperatur`, sind dabei keine neuen Schreibaktionen.

## Betriebsarten, Sperren und Sonderfunktionen

SHI-Modi wählen **Kein Einfluss / Sollwert / Offset / Level**. SHI-Level bedeuten
**Kein Einfluss / überhöht mit Zeitprogramm / überhöht ohne Zeitprogramm / reduziert**.
Sie sind keine direkten Entsprechungen zu Java **Automatik / Party / Urlaub / Aus**.
Insbesondere bedeutet SHI-Modus 0 **kein externer Einfluss**, nicht Heizung aus.
Daher bleiben die dafür vorgesehenen `SHI_…`-Bedienpunkte getrennt.

Sperren, LPC-Modus, Zirkulationsstart und Extra-Warmwasser sind ebenfalls eigene
SHI-Funktionen. Ein Zirkulationsstart ist kein Schalter mit garantierter
Ausschaltfunktion. Die zusätzliche Warmwasser-Zieltemperatur und -Dauer unter
IR 10500/10501/10502 sind im Handbuch nur lesbar. Die vorhandenen Java-Zeitprogramme
besitzen dort kein schreibbares Gegenstück.

Die Java-Ergänzung im Modbus-Modus liest ausschließlich Messwerte mit Kommando
3004. Sie führt weder Java-Schreibbefehle noch Java-Steuerwertabfragen aus.

Vollständige Register und Bedienhinweise: [SHI-REGISTER.md](SHI-REGISTER.md) und
[SHI-TEST.md](SHI-TEST.md).
