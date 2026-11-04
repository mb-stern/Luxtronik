# Sollwerte: Java und Modbus/SHI

Verglichen werden die tatsächlich implementierten Java-Schreibaktionen dieses Moduls
mit dem Herstellerhandbuch **83026900aDE**. Eine fehlende Java-Aktion in dieser
Tabelle bedeutet nicht, dass jede Luxtronik-Firmware diesen Parameter grundsätzlich
nicht über Java bereitstellen könnte.

## Bestehende Variablen mit vergleichbarer Funktion

Diese Idents werden beibehalten. Die Auswahl des Schreibwegs bestimmt das Senden;
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

Das ist der dokumentierte Vorteil für temporäre externe Vorgaben (Handbuch,
PDF-Seite 5, Abschnitt 3). Daraus folgt keine pauschale Aussage, dass jeder
Java-/WebSocket-Schreibzugriff einen nichtflüchtigen Speicher beschreibt oder
jeder Modbus-Zugriff intern garantiert ohne Speicherzugriff bleibt. Solche
Firmwaredetails sind hier nicht belegt. Reine Messwertabfragen ändern keine
Reglerparameter; Modbus bleibt auch für zusätzliche Messwerte sinnvoll.

Java ist die voreingestellte Lesequelle. Eine andere bevorzugte Quelle kann je Messwert
in der ID-Liste gewählt werden. Fehlende benötigte Werte werden automatisch ergänzt.
Die Auswahl „Schreibweg für unterstützte Befehle“ ändert nach Übernehmen nur den
Schreibweg; Nur über Java unterstützte Betriebsarten und Zeitprogramme bleiben über Java bedienbar.
Alle 41 Modbus-Steuervariablen stehen in einer eigenen Auswahlliste. [Konfiguration](DATENQUELLEN.md).

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

Java-Messwerte werden mit Kommando 3004 gelesen. Nur über Java unterstützte Steuervariablen werden
weiter mit 3003 gelesen und bei einer Bedienaktion mit 3002 geschrieben. Für die
drei gemeinsamen Sollwerte bestimmt der gewählte Schreibweg auch die Steuerwertabfrage.

Vollständige Register und Bedienhinweise: [SHI-REGISTER.md](SHI-REGISTER.md) und
[SHI-TEST.md](SHI-TEST.md).

## Heizungs-Offset und negative Werte

Der Praxistest meldet getrennte Java- und SHI-Korrekturen: Der Java-Wert in der
App ändert sich beim Schreiben von HR 10002 nicht. Im Offset-Modus können sich
die Korrekturen addieren, etwa Java −1 K und SHI +1 K zu einer resultierenden
Korrektur von 0 K. Die vorhandene Symcon-Variable `Anpassung_Temp` zeigt den Wert
des gewählten Schreibwegs; sie synchronisiert die beiden Reglerparameter nicht.
Beim Umstieg deshalb die bisherige Java-Korrektur prüfen und bei Bedarf bewusst
am Regler bzw. über die App neutralisieren. Das Modul setzt sie nicht automatisch
zurück und kopiert beim Umschalten auch keinen alten Sollwert auf den anderen Weg.

HR 10002 ist **Int16, ein Register, Big-Endian, Faktor 0,1**. Der Faktor wird genau
einmal angewendet. Beispiele für Lesen und Schreiben:

| Offset | Signed-Rohwert | Datenwort | Derselbe Rohwert als UInt16 |
| --- | --- | --- | --- |
| −1,0 K | −10 | `0xFFF6` | 65526 |
| −1,5 K | −15 | `0xFFF1` | 65521 |
| +1,0 K | 10 | `0x000A` | 10 |

Ein vorzeichenlos ausgelesenes `0xFFF6` ergäbe fälschlich 6552,6 K. Ein gemeldeter
Wert von 6400,0 K lässt ohne Rohdaten noch keine genaue Fehlerzuordnung zu;
Datentyp, Bytefolge und Skalierung der eigenen Modbus-Umsetzung müssen zusammen
geprüft werden. Kein 32-Bit-Integer und kein IEEE-Float: Das Schreiben zweier Wörter
würde auch das benachbarte Register betreffen. Das Modul schreibt nur ein Wort,
liest den Wert vorzeichenbehaftet zurück und lehnt Vorgaben außerhalb −20…+20 K
vor dem Verbindungsaufbau ab.

Für die Übernahme von HR 10002 muss HR 10000 auf **2 (Offset)** stehen. Eine explizite
Offset-Bedienaktion des Moduls bestätigt zuerst HR 10002 und aktiviert danach,
falls nötig, HR 10065=0 und HR 10000=2. Im Modus **1 (Sollwert)** wird stattdessen
die absolute Rücklauf-Solltemperatur vorgegeben; das ist keine additive Korrektur.
