# Modbus TCP / SHI – Testfunktion in Version 5.0

Die Testversion unterstützt die im Herstellerhandbuch **83026900aDE** beschriebenen
**76 Input-Datenpunkte, 12 Discrete Inputs und 41 Holding-Register**. Input und
Holding mit derselben Adresse sind unterschiedliche Datenpunkte. Der vollständige
[Katalog mit Idents, Einheiten und Grenzen](SHI-REGISTER.md) und der [API-Abgleich](SHI-ZUORDNUNG.md) gehört zu dieser Version.
Die Implementierung ist mit einem lokalen TCP-Simulator geprüft, noch nicht an einer
echten SHI-Luxtronik. Java bleibt Standard; der Java-Protokollpfad bleibt erhalten.

## Einrichtung und Auswahl

- Voraussetzung laut Handbuch: Luxtronik 2.1 ab V3.90.1 mit verfügbarer/aktiver
  SHI-Schnittstelle. Neuere Funktionen können auf älteren Firmwareständen fehlen.
  Ob die konkrete CTA-Firmware SHI bereitstellt, muss am Gerät geprüft werden.
- Unter **Modbus TCP / SHI** den Schalter **Unterstützte Schreibbefehle über Modbus/SHI senden**
  bei Bedarf aktivieren und übernehmen. Standardmäßig wird über Java geschrieben.
- Java ist unabhängig davon die voreingestellte Lesequelle. **Bevorzugte Quelle**
  kann je Messwert geändert werden. Fehlende Werte werden
  automatisch über WebSocket und bei Bedarf über Modbus ergänzt. Es gibt keine
  separaten Lese-Schalter mehr.
- Gleiche Geräte-IP wie Java; Modbus-Port **502**, Unit-ID **1**, Timeout **500 ms**
  pro Anfrage. Port, Unit-ID und Timeout (100–5000 ms) sind separat konfigurierbar.
- Literaladressen mit **Offset 0**, Byte- und Wortfolge Big-Endian.
- Als anfänglichen Aktualisierungstakt beispielsweise **30 Sekunden** verwenden.
- **Aktuelle Quelle** in der ID-Liste zeigt die tatsächlich verwendete Herkunft.
  **Verfügbar über** nennt die unterstützten Schnittstellen.
- Die normale Liste zeigt Java-Zeilen und zehn zusätzliche Modbus-Messwerte.
  Weitere lesbare Grenzen und Konfigurationsmeldungen stehen unter
  **Weitere lesbare Modbus-Datenpunkte** zur Auswahl. Bereits gewählte lesbare Punkte bleiben
  auch bei ausgeblendeter Erweiterung sichtbar. Es sind dokumentierte Register,
  keine unbekannten Java-Indizes.
- Alle 41 Schreibfunktionen stehen getrennt unter **Modbus-Steuervariablen**.
  Eine Auswahl legt die Variable an, eine Bedienaktion sendet ihren Wert. Gemeinsame
  Sollwerte verwenden die vorhandenen Objekte; Java-Betriebsarten und Zeitprogramme bleiben
  auch bei bevorzugtem Modbus-Schreibweg über Java bedienbar. [Details](DATENQUELLEN.md).
- Die Abfrage beschränkt sich auf benötigte bevorzugte bzw. Ersatzwerte und die ausgewählten
  Zusatzpunkte. Grafik und Diagnose erzeugen keine Messobjekte. Nur ausgewählte
  Zeilen legen zusätzliche Objekte an.
- Ein erfolgreicher expliziter SHI-Schreibauftrag wird gelesen gehalten, solange
  der Modbus-Schreibweg aktiv ist. Das setzt keine Werte erneut und unterstützt
  die im Handbuch genannte Kommunikationsüberwachung.

Ident, numerische Variablen-ID, Name, Typ und Profil bestehender gemeinsamer Werte
bleiben erhalten. Neue SHI-Werte erhalten eigene `SHI_…`-Idents. Keine neuen
Java-Indizes werden erfunden. Beim Zurückschalten bleiben SHI-Objekte erhalten;
ihre Aktionen werden deaktiviert. Java-Einstellungen bleiben gespeichert.

Nicht unterstützte Register, Geräteausnahmen 1/2/3 und Ersatzwerte werden
übersprungen. Die Auswahl bleibt bestehen; vorhandene Objektwerte werden nicht
durch Null ersetzt. Der letzte Objektwert ist dann kein aktueller SHI-Messwert.
Die interne Grafik verwendet dafür keine alten Objektwerte. Ein Transportfehler
verwirft den unvollständigen Modbus-Lesezyklus und kennzeichnet dessen letzten
Snapshot als veraltet. Fehler stehen im Debug; eine fehlgeschlagene optionale Abfrage blockiert die
anderen Lesequellen nicht.

## Ergänzende Java-Messwerte und Datenquelle

Java wird einmal pro Aktualisierung mit Kommando 3004 gelesen. Gültige Java-Werte
haben standardmäßig Vorrang, auch **0 oder Aus**. Die bevorzugte Quelle kann je
Messwert geändert werden. Fehlende benötigte Werte werden automatisch
über WebSocket ergänzt. Modbus liest nur noch benötigte verbleibende Register
sowie ausgewählte zusätzliche Punkte. Die Auswahl des Schreibwegs ändert diese
Lesereihenfolge nicht. Idents und numerische Objekt-IDs bleiben erhalten;
nicht ausgewählte Grafikwerte werden nur intern gespeichert. Unbestätigte
Zuordnungen werden nicht geraten: etwa `SEC_EEV` wird nicht zur EEV-Öffnung erklärt.

So können etwa Durchfluss, Verdichter-Ist/-Soll, Drücke, Temperaturen und laufende
Lauf-/Standzeit weiter über Java kommen. Reine SHI-Werte ohne bestätigtes Java-
Gegenstück werden nicht umgedeutet. Eine fehlerhafte oder unvollständige Java-
Antwort wird vollständig verworfen; bestehende Objekte behalten ihre letzten Werte,
die Grafik übernimmt davon keine alten Java-Daten. Funktionierendes Modbus bleibt
dabei nutzbar. Umgekehrt bleiben Modbus-Verbindungsfehler im Status sichtbar, auch
wenn Java noch Messwerte liefert. Die Java-Verbindung hat die bisherige begrenzte
Zeitüberschreitung von fünf Sekunden. Geräte-IP, beide Ports und Unit-ID binden den internen Speicher an die aktuelle Konfiguration.

Im Debug erscheint unter **Datenquelle** die tatsächliche Quelle pro Datenpunkt,
beispielsweise `Aussentemperatur: Modbus = -2.5 °C` oder
`Flow_Rate: Java = 617 l/h`. Die Spalte Aktuelle Quelle meldet fehlende aktuelle Daten. `GetSHIData()` zeigt den Modbus-Snapshot unter
`values` und aktuelle ergänzende Java-Werte separat unter `javaSupplement`.

Die Messwertergänzung ist **ausschließlich lesend**. Davon unabhängig werden
aktivierte Java-Steuervariablen mit 3003 gelesen und bei einer Bedienaktion mit 3002
geschrieben. Unterstützte gemeinsame Sollwerte nutzen bei eingeschaltetem
Modbus-Schreibschalter Modbus. Ein fehlgeschlagener Modbus-Schreibbefehl wird nicht
automatisch zusätzlich über Java gesendet. Für gemeinsame Funktionen gibt es keine
doppelten Sollwertvariablen.

## Schreiben und Bedienung

Nur die **41 dokumentierten Holding-Register** sind schreibbar. Ausgewählte
Zeilen unter **Modbus-Steuervariablen** erzeugen Bedienvariablen mit passenden Wertebereichen und
Assoziationen. Sie lassen sich über die übliche Symcon-Aktion bedienen. Auswahl,
Konfigurationsspeichern und zyklische Abfrage schreiben keine Vorgabewerte.

Unterstützt sind Heizungs-/Warmwasser-Sollwerte und Offsets, Heiz-Level,
Heiz- und Kühlvorgaben für MK1–3, globale Heizvorgaben, Soft-/Hard-Leistungsgrenze,
Sperren, Raumtemperaturvorgabe, Zirkulationsstart und Extra-Warmwasser.
Die Java-Betriebsarten `Automatik`, `Party` usw. sind nicht dasselbe wie die
SHI-Modi `Kein Einfluss`, `Sollwert`, `Offset`, `Level` und werden nicht umgedeutet.
Java-Zeitprogramme besitzen in diesem Handbuch kein schreibbares Gegenstück.

Die gemeinsamen numerischen Bedienpunkte `Anpassung_WW`, `Anpassung_Temp` und
`Anpassung_RBE` bleiben nutzbar, wenn sie in der Konfiguration aktiviert sind.
Warmwasser-Soll und Heizkorrektur werden auch aus der Grafik über diesen Weg
bedient. Die übrigen SHI-Vorgaben sind über die ausgewählten Bedienvariablen oder
die unten beschriebene PHP-Funktion erreichbar.
Der [Vergleich der Java- und Modbus-Sollwerte](SHI-JAVA-VERGLEICH.md) erklärt
gemeinsame Funktionen, abweichende Bereiche und zusätzliche Modbus-Vorgaben.

Vor dem Schreiben prüft das Modul Datentyp, Bereich, Auflösung und die aktuell
aktivierte Gerätefunktion. Es liest benötigte Modusregister frisch ein und setzt
nach dem Zielwert die dokumentierten Abhängigkeiten: z.B. Warmwasser-Modus 1 für
Sollwert, Heizungs-Modus 2 für Offset und Gesamtmodus 0 für individuelle Heizvorgaben.
Die Wahl einer individuellen Vorgabe kann damit eine globale Vorgabe ablösen;
eine globale Vorgabe aktiviert umgekehrt den Gesamtmodus. Der PC-Limit-Wert
aktiviert keine Leistungsbegrenzung selbst: Soft oder Hard Limit ist ausdrücklich
vorher zu wählen. Bei Raumtemperaturvorgabe dürfen keine SHI-Heiz-/Mischkreisvorgaben
aktiv sein; den erforderlichen RBE-Einflussfaktor prüft der Regler selbst.

Schreiben erfolgt über **FC16**, Lesen über **FC02/03/04**. Jede Schreibantwort
und der zurückgelesene Registerinhalt werden geprüft, bevor der Wert veröffentlicht
wird. Mehrere abhängige Register bilden keine atomare Transaktion: Bei Abbruch
kann ein Teil bereits übernommen sein. Das Modul meldet dies, aktiviert nach einer
fehlenden Zielwertbestätigung keinen weiteren Modus und wiederholt Schreibbefehle
nicht automatisch. Ein bestätigtes Register beweist die gespeicherte Vorgabe,
nicht eine mechanische Ventilposition oder die tatsächlich erreichte Temperatur.
Abfrage und Bedienung derselben Instanz werden gegenseitig gesperrt.

Das Handbuch nennt eine **15-Minuten-Kommunikationsüberwachung**: Bleiben Anfragen
aus, setzt der Regler empfangene Vorgaben auf Standard zurück. Die Abfrage hält die
Kommunikation aufrecht; das Modul sendet alte Schreibwerte nach Neustart oder
Verbindungsabbruch nicht erneut. Beim Umschalten auf Java werden SHI-Vorgaben nicht
ungefragt zurückgeschrieben. Für eine sofortige Rücknahme vorher die betreffenden
SHI-Modi auf `Kein Einfluss` bzw. Sperren/LPC-Modus auf Normalbetrieb stellen.

## Daten und bekannte Grenzen

Temperaturen werden in °C, Offsets in K, Energien in kWh und Betriebsstunden in h
verarbeitet. `Waermeleistung` bleibt kW, `Leistungsaufnahme` bleibt W. Die Solarzeit
behält für den bestehenden Ident das Format `…h …m`. Die externen COP-/JAZ-Zähler
und deren Berechnung bleiben unverändert; interne Stromzähler ersetzen sie nicht.

Status-/Pumpenwerte, MK3 und weitere dokumentierte Größen ergänzen die Grafik.
FUP1 und HUP sind getrennte Pumpen. Die Wärmeerzeuger-Bitmaske liefert VD1/VD2 und
Zusatzheizungszustände; eine Verdichterfrequenz wird daraus nicht berechnet.
SHI-Systemstatus 6 heißt laut Handbuch `nicht belegt` und wird nicht als der
abweichende Java-Status 6 verwendet.

Das vorliegende Handbuch enthält keine bestätigten Register für EEV-Öffnungen,
Verdichterfrequenzen, Durchfluss, Mischer-Auf/Zu, laufende Zyklus-/Standzeit oder
separate Primär-/Sekundär-Vorlauftemperaturen. Mindestlaufzeit und Schaltspielsperre
sind keine aktuelle Zyklusdauer. Solche Werte werden nicht aus anderen Feldern
abgeleitet. Soweit eine bestätigte Java-Zuordnung existiert, kann die lesende
Java-Ergänzung diese Messwerte liefern. Dokumentierte Sensor-Ersatztemperaturen
5/75 °C sind ohne zusätzliche
Fehlerrückmeldung nicht sicher von einem echten Messwert zu unterscheiden.

## PHP und Gerätetest

```php
// Letzten internen Snapshot anzeigen; keine zusätzliche Abfrage, keine Objekte.
echo WPLUX_GetSHIData(12345);

// Warmwasser-Sollwert 50 °C; aktiviert den zugehörigen SHI-Sollwertmodus.
WPLUX_SetSHIValue(12345, 'Anpassung_WW', 50.0);

// SHI-Einfluss auf Warmwasser zurücknehmen.
WPLUX_SetSHIValue(12345, 'SHI_Modus_Warmwasser', 0);
```

`12345` durch die Instanz-ID ersetzen. Die Schreibfunktion akzeptiert Zahlen und
für Bool-Register auch boolesche Werte; keine numerischen Strings oder Rohadressen.

Nach Installation der Firmware zuerst nur lesen, Reglerwerte/Einheiten und
bestehende Objekt-IDs vergleichen. Danach einzelne Vorgaben einschließlich
Rücknahme prüfen. Für eine Rückmeldung genügen Reglertyp/Firmware, Unit-ID,
beide Ports, Ergänzungsschalter, Register/Ident, erwarteter und angezeigter Wert
sowie das Debugprotokoll mit den Datenquellen.

Lokal: `php tests/modbus.php`, `php tests/visualization.php` und optional
`python3 tests/browser.py`. Die Protokolltests verwenden einen separaten lokalen
Modbus-Peer mit fragmentierten Antworten, Ausnahmen, Zeitüberschreitungen und
fehlerhaften Schreibbestätigungen. Sie ersetzen keinen Gerätetest.
