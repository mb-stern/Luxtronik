# Messwerte und Steuervariablen konfigurieren

## Mess- und Statuswerte: ID-Liste

Die ID-Liste enthält ausschließlich lesende Datenpunkte. **Aktiv** legt die
Messvariable an. Auch ohne Objekt kann die Grafik benötigte Werte intern verwenden.
IDs, Idents und vorhandene Objekt-IDs ändern sich durch einen Quellenwechsel nicht.

| Spalte | Bedeutung |
| --- | --- |
| Verfügbar über | Schnittstellen mit bestätigter Zuordnung für diesen Wert |
| Bevorzugte Quelle | Auswahl Java, WebSocket oder Modbus/SHI |
| Aktuelle Quelle | Tatsächlich verwendete Quelle nach der letzten Aktualisierung |

Über der Liste stehen **Lesequelle gesammelt auswählen** und **Für alle passenden
Messwerte übernehmen**. Die Schaltfläche übernimmt die angezeigte Messwertliste
und setzt die Quelle für alle passenden Datenpunkte; bestehende Einzelzuordnungen
werden dabei ersetzt. Nicht unterstützte Quellen und Modbus-Steuervariablen
bleiben unverändert. Nicht ausgewählte Messvariablen werden dadurch nicht aktiviert.
Danach lassen sich einzelne Zeilen wieder abweichend einstellen und mit dem
normalen **Übernehmen** speichern. Der Schreibweg wird dabei nicht geändert.

Java ist Standard, wenn es für den Wert eine Java-Zuordnung gibt. Ohne Java-Zuordnung
wird die erste zugeordnete Quelle verwendet. Eine Auswahl ohne passende Zuordnung
wird übersprungen. Die Lesereihenfolge ist:

| Bevorzugt | Ersatz bei fehlenden Daten |
| --- | --- |
| Java | WebSocket, danach Modbus |
| WebSocket | Java, danach Modbus |
| Modbus | Java, danach WebSocket |

Es werden jeweils nur zugeordnete Schnittstellen berücksichtigt. Ein gelieferter
Wert **0 oder Aus ist gültig**. Bei Wiederkehr der bevorzugten Quelle kehrt auch die
Anzeige zu dieser zurück. Quellenwahl und Variablenauswahl senden keine Schreibbefehle.
Die Einstellung greift nach **Übernehmen** und gilt auch für die Grafik sowie COP/JAZ.
Java wird als Sammelantwort gelesen; weitere Quellen werden gezielt angefordert.

Die Fehlerhistorie in der Grafik und die Sammelvariable 400020 enthalten den
vollständigen WebSocket-Meldungstext. Für die einzelnen bestehenden Fehlercodes und
Zeitstempel gilt dieselbe Quellenwahl wie für andere Messwerte; Java ist Standard.

## Schreiben: Modbus TCP / SHI → Modbus-Steuervariablen

Eine eigene Liste bietet alle 41 dokumentierten Holding-Funktionen mit Register,
Bereich und Einheit an. **Variable anlegen** erzeugt die passende Steuervariable.
Der aktuelle Modbus-Wert wird angezeigt, wenn er gelesen werden konnte. Diese
Funktionen stehen nicht mehr in der lesenden ID-Liste.

Die drei gemeinsamen Variablen WW-Soll, Heizkorrektur und Raumtemperatur-Soll können
auch über **Timer / Zusatzfunktionen** aktiviert werden. Eine der beiden Auswahlen
genügt; es gibt jeweils nur ein Objekt. Die Spalte **Variable / Aktivierung** nennt bei
gemeinsamen Sollwerten die vorhandene Variable mit ihrer Objekt-ID sowie eine zusätzlich aktive Timer-Option.
So ist sichtbar, dass zum Beispiel Auswahl-ID **310002** die bestehende
**Temperaturkorrektur** (`Anpassung_Temp`) verwendet und kein zweites Objekt erzeugt. Vorhandene gemeinsame Variablentypen, Namen, Profile und Objekt-IDs bleiben erhalten. Alte Modbus-Auswahlen aus der ID-Liste
erscheinen automatisch in der neuen Steuerliste; neue Einstellungen haben Vorrang.

**Schreibweg für unterstützte Befehle** ist eine direkt an die bestehende
Konfiguration gebundene Auswahl. Änderungen werden regulär mit **Übernehmen**
aktiviert; das Auswählen allein ändert den laufenden Schreibweg nicht:

- **Java**: gemeinsame Sollwerte über Java; reine Modbus-Variablen sind nicht bedienbar.
- **Modbus/SHI**: unterstützte gemeinsame Sollwerte und reine Modbus-Funktionen über Modbus.
  Java-Betriebsarten und Zeitprogramme ohne gleichbedeutende Modbus-Funktion bleiben
  über Java bedienbar. Fehlgeschlagene Modbus-Schreibbefehle werden nicht automatisch
  zusätzlich über Java gesendet.

Auswahl, Übernehmen und Aktivieren senden keine voreingestellten Sollwerte. Erst eine
Bedienaktion oder ein expliziter Schreibaufruf sendet einen Wert. Der Modbus-Weg prüft
Verfügbarkeit, Wertebereich und Voraussetzungen am Regler und liest die Bestätigung
zurück. Bei fehlender Kommunikation bleiben reine Modbus-Bedienaktionen gesperrt.
Modbus benötigt die passende Reglerfirmware und eine aktive SHI-Schnittstelle.

Unter **Timer / Zusatzfunktionen** steht der gewählte Schreibweg direkt an den sieben Steuerschaltern. Die Kennzeichnung aktualisiert sich beim Ändern von **Schreibweg für unterstützte Befehle** sofort. Temperaturkorrektur, Warmwasser-Soll und Raumtemperatur-Soll wechseln auf Modbus/SHI; die Betriebsarten Heizung, Warmwasser, Kühlung und Schwimmbad bleiben Java. Die Beschriftung bezeichnet den Befehlsweg, nicht die Erreichbarkeit des Reglers.

Die Auswahl-IDs der Liste sind keine Registeradressen: **310000** bezeichnet
Holding-Register **10000** (SHI-Modus Heizen), **310002** bezeichnet **10002**
(Heizungs-Offset). **320002** ist kein zugeordneter Steuerpunkt.
Negative Heizungs-Offsets verwenden ein einzelnes vorzeichenbehaftetes 16-Bit-Wort
in Big-Endian mit Faktor 0,1; Beispiel −1,0 K: `0xFFF6` (signed −10).
[Umstieg und Protokollbeispiele](SHI-JAVA-VERGLEICH.md#heizungs-offset-und-negative-werte).

## Abfragetakt und Freigaben

Erfolgreiche Modbus-Abfragen werden in jedem neuen Aktualisierungslauf erneut
angefordert. Vorabfrage und Ergänzung teilen ihre Messwerte nur innerhalb desselben
Laufs; eine zusätzliche fünfsekündige Wartezeit entfällt. Bei einem
Kommunikationsfehler bleibt die bestehende Wiederholpause von 120 Sekunden erhalten.
Unveränderte Werte werden weiterhin nicht erneut in Symcon geschrieben: Ein alter
Änderungszeitstempel oder eine konstante Archivkurve beweist daher keine ausgebliebene
Abfrage. Die Leistungsregister haben weiterhin 100-W-Schritte; für feinere Daten
kann Java je Leistungswert bevorzugt bleiben.

Unter **Modbus TCP / SHI** zeigt **SHI-Warmwasser (Discrete Input 10001)** die aktuell
gelesene Freigabe an. Bei aktivierter SHI-Steuervariable wird diese mit abgefragt.
Ohne bestätigte Freigabe bleibt die SHI-Bedienaktion gesperrt. Ein direkter
Schreibaufruf prüft die Freigabe unverändert erneut und nennt Funktion und
Discrete Input bei einer Ablehnung. DI 10001 ist nur lesbar; die Freigabe wird
am Regler eingerichtet, nicht durch das Modul erzwungen.
