# Modbus TCP / SHI – Testfunktion in Version 5.0

Diese erste Version ist **ausschließlich lesend** und noch nicht an einer echten
SHI-Luxtronik erprobt. Java bleibt für neue und bestehende Instanzen Standard.
Die bestehende Java-Kommunikation wurde nicht umgebaut; nur die ausdrücklich
gewählte SHI-Einstellung aktiviert den separaten Modbus-Pfad. Es gibt keinen
Rückfall auf Java bei einem Modbus-Fehler und keine Modbus-Schreibbefehle.

## Voraussetzungen und Einrichtung

Referenz: vom Anwender bereitgestelltes Herstellerhandbuch
**83026900aDE – Smart Home Interface, Modbus TCP**, insbesondere Seiten 4–5 und 22–28.

- Luxtronik 2.1 ab Firmware V3.90.1 mit verfügbarer/aktivierter SHI-Schnittstelle.
- Gleiche IP-Adresse wie für Java, eigener Modbus-Port: standardmäßig **502**.
- Geräteadresse (Unit-ID): standardmäßig **1**, entsprechend der Gerätekonfiguration.
- Timeout: standardmäßig **500 ms**, einstellbar von 100 bis 5000 ms pro Anfrage.
- Registeradressen werden unverändert verwendet, **Offset 0**, Byte- und Wortfolge Big-Endian.

Im Konfigurationsformular `Kommunikation` auf
`Modbus TCP / SHI (Testversion, nur lesen)` stellen, die Verbindungswerte eintragen
und die Einstellungen übernehmen. Danach das Formular neu öffnen; die
SHI-Unterstützung ist dann direkt neben jeder bisherigen Java-ID sichtbar.
Für den ersten Test nur IDs **10, 11, 15, 17, 257 und 268** aktivieren und ein
Abfrageintervall von beispielsweise **30 Sekunden** verwenden.

## Erhalt der Variablen

Für dieselbe physikalische Größe bleiben die **Java-ID, der Ident, die numerische
Variablen-ID, der Variablentyp und vorhandene Namen/Profile** erhalten. Bestehende
Variablen werden im SHI-Pfad nicht neu registriert oder gelöscht. Skripte und
Visualisierungen können ihre bisherigen IDs weiterverwenden. Neue Variablen
verwenden die vorhandene Java-Datenpunktdefinition.

Nicht zugeordnete, abgewählte oder vom Gerät nicht gelieferte Werte bleiben im
SHI-Modus unverändert. Das erhält Verknüpfungen, bedeutet aber ausdrücklich:
**Der letzte Wert ist kein aktueller SHI-Messwert.** Nicht unterstützte Java-IDs
sind im Formular markiert; nicht verfügbare Register und Ersatzwerte erscheinen
im Debug-Protokoll. Insbesondere werden die SHI-Zustandsnummern nicht auf die
anders definierten Java-Betriebsarten übertragen.

Vorhandene Java-Steuer- und Timervariablen sowie ihre Konfiguration bleiben
bestehen. Ihre Modulaktionen werden im SHI-Modus deaktiviert, und direkte
`RequestAction`-Aufrufe werden abgewiesen. Diese Testversion liest nur
Input-Register über **Funktionscode 04**.

COP und JAZ verwenden bei vorhandener Konfiguration weiterhin die bisherigen
externen Messvariablen und dieselbe Berechnung. Die SHI-Eingangsleistung wird
nicht automatisch als neuer COP-/JAZ-Zähler ausgewählt. Die zugehörigen
Wärmedaten werden bei Bedarf auch ohne sichtbare Auswahl abgefragt.

## Unterstützte Zuordnungen

Die Faktoren rechnen den SHI-Rohwert direkt in die bisherige Einheit um.
Insbesondere bleibt `Leistungsaufnahme` in **Watt**, `Waermeleistung` in **kW**
und bleiben die Wärmemengenzähler in **kWh**.

| Java-ID | Bisheriger Ident | SHI-Input-Register | Datentyp | Faktor |
| --- | --- | --- | --- | --- |
| 10 | `Vorlauftemperatur_Heizkreis` | 10105 | uint16 | × 0.1 |
| 11 | `Ruecklauftemperatur_Heizkreis` | 10100 | uint16 | × 0.1 |
| 12 | `Ruecklauf_Soll_Heizkreis` | 10101 | uint16 | × 0.1 |
| 13 | `Ruecklauftemperatur_im_Trennspeicher` | 10102 | uint16 | × 0.1 |
| 15 | `Aussentemperatur` | 10108 | int16 | × 0.1 |
| 16 | `Durchschnittstemperatur_Aussen_ueber_24_h` | 10109 | int16 | × 0.1 |
| 17 | `Warmwasser_Ist_Temperatur` | 10120 | int16 | × 0.1 |
| 18 | `Warmwasser_Soll_Temperatur` | 10121 | uint16 | × 0.1 |
| 19 | `Waermequellen_Eintrittstemperatur` | 10110 | int16 | × 0.1 |
| 20 | `Waermequellen_Austrittstemperatur` | 10111 | int16 | × 0.1 |
| 21 | `Mischkreis_1_Vorlauftemperatur` | 10140 | int16 | × 0.1 |
| 22 | `Mischkreis_1_Vorlauf_Soll_Temperatur` | 10141 | int16 | × 0.1 |
| 24 | `Mischkreis_2_Vorlauftemperatur` | 10150 | int16 | × 0.1 |
| 25 | `Mischkreis_2_Vorlauf_Soll_Temperatur` | 10151 | int16 | × 0.1 |
| 151 | `Waermemengenzaehler_Heizung` | 10322 | int32 | × 0.1 |
| 152 | `Waermemengenzaehler_Brauchwasser` | 10324 | int32 | × 0.1 |
| 153 | `Waermemengenzaehler_Schwimmbad` | 10328 | int32 | × 0.1 |
| 154 | `Waermemengenzaehler_Gesamt` | 10320 | int32 | × 0.1 |
| 189 | `Temperatur_Vorlauf_Soll` | 10113 | int16 | × 0.1 |
| 227 | `Raumtemperatur_Istwert` | 10106 | int16 | × 0.1 |
| 257 | `Waermeleistung` | 10300 | int16 | × 0.1 |
| 268 | `Leistungsaufnahme` | 10301 | uint16 | × 100.0 |

## Ablauf für den Forumstest

1. Vor dem Umschalten Modulversion, Reglertyp/Firmware und die numerischen IDs
   einiger bestehender Variablen notieren; aktuelle Java-Werte zum Vergleich sichern.
2. SHI-Modus wie oben einstellen und `Jetzt aktualisieren` ausführen.
3. Temperaturen und Leistungen mit dem Regler vergleichen, besonders negative
   Außentemperaturen, Watt gegenüber kW sowie die Einheit der Energiezähler.
4. Kontrollieren, dass dieselben Idents und numerischen Variablen-IDs bestehen.
5. Optional weitere unterstützte IDs aktivieren und erneut prüfen.
6. Zum Vergleich zurück auf `Java (Standard)` schalten, übernehmen und das
   Formular neu öffnen. Die bisherigen Java-Einstellungen bleiben gespeichert.

Für eine Rückmeldung genügen Firmware/Reglertyp, verwendete Unit-ID, ausgewählte
Java-IDs, beobachtete Werte und das Modul-Debugprotokoll.

Ein Abbruch oder eine fehlerhafte Modbus-Antwort verwirft den gesamten unvollständigen
Lesezyklus. Ersatzwerte (32767 bzw. beim INT32 zusätzlich 2147483647) und vom Gerät
als ungültig gemeldete optionale Register werden übersprungen. Der Instanzstatus
unterscheidet Verbindungsfehler, ungültige Einstellungen, fehlende Messwerte und
teilweise verfügbare Messwerte. Alte Werte werden nicht durch Null ersetzt.

## Lokale Prüfung

```sh
php tests/modbus.php
```

Benötigt PHP CLI mit `sockets` und `pcntl`. Die Suite enthält die bestehenden
Java-Tests und einen lokalen Modbus-TCP-Simulator. Sie prüft unter anderem den
Wechsel Java → SHI → Java mit unveränderten Variablen-IDs, ausschließlich lesende
Telegramme, korrekte Einheiten, Teilantworten, Geräteausnahmen und Timeouts.
Sie ersetzt den oben beschriebenen Test an einer echten Luxtronik nicht.
