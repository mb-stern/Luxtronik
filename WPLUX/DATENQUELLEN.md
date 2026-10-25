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
genügt; es gibt jeweils nur ein Objekt. Die Spalte **Weitere Aktivierung** nennt
eine zusätzlich aktive Timer-Option. Vorhandene gemeinsame Variablentypen, Namen,
Profile und Objekt-IDs bleiben erhalten. Alte Modbus-Auswahlen aus der ID-Liste
erscheinen automatisch in der neuen Steuerliste; neue Einstellungen haben Vorrang.

**Unterstützte Schreibbefehle über Modbus/SHI senden** wählt den Schreibweg:

- Aus: gemeinsame Sollwerte über Java; reine Modbus-Variablen sind nicht bedienbar.
- Ein: unterstützte gemeinsame Sollwerte und reine Modbus-Funktionen über Modbus.
  Java-Betriebsarten und Zeitprogramme ohne gleichbedeutende Modbus-Funktion bleiben
  über Java bedienbar. Fehlgeschlagene Modbus-Schreibbefehle werden nicht automatisch
  zusätzlich über Java gesendet.

Auswahl, Übernehmen und Aktivieren senden keine voreingestellten Sollwerte. Erst eine
Bedienaktion oder ein expliziter Schreibaufruf sendet einen Wert. Der Modbus-Weg prüft
Verfügbarkeit, Wertebereich und Voraussetzungen am Regler und liest die Bestätigung
zurück. Bei fehlender Kommunikation bleiben reine Modbus-Bedienaktionen gesperrt.
Modbus benötigt die passende Reglerfirmware und eine aktive SHI-Schnittstelle.
