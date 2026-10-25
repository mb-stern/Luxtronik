# WebSocket-Messwerte in 5.0

Unter **Kommunikation** sind Java (Standard), Modbus TCP/SHI und WebSocket wählbar.
Bestehende Idents und Objekt-IDs bleiben erhalten. Die Spalte **Schnittstelle** zeigt
die für einen Datenpunkt zugeordneten Protokolle; das konkrete Gerät muss das Feld
auch anbieten. **WebSocket: letzte Abfrage** zeigt zusätzlich, ob das Gerät einen
nutzbaren Messwert geliefert hat, noch nicht geprüft wurde oder die Abfrage
fehlgeschlagen ist. Nach einer Abfrage die Konfiguration neu öffnen. Die Auswahl
bleibt auch bei fehlenden Web-Werten für andere zugeordnete Quellen möglich.
Ein Nullwert oder „Aus“ ist gültig und löst keinen Ersatz aus.

- Java bevorzugt seine aktuellen Messwerte; WebSocket ergänzt fehlende Zuordnungen.
- WebSocket bevorzugt seine zugeordneten Informationsfelder; fehlende Werte kommen
  weiter über Java. Java wird deshalb in diesem Modus weiterhin lesend abgefragt.
- Modbus bevorzugt seine aktuellen Register. Die optionale Java-Ergänzung bleibt
  bestehen; zusätzlich können fehlende Werte über WebSocket kommen.
- Direkte WebSocket-Schreibbefehle sind noch nicht implementiert. Die bisherigen
  Bedienaktionen verwenden im WebSocket-Messmodus den bewährten Java-Schreibweg
  mit Rückleseprüfung. Modbus-Bedienpunkte bleiben an den Modbus-Modus gebunden.

## Einrichtung und Abdeckung prüfen

Voraussetzung ist das Websockets-Webinterface **ab Firmware 3.81** mit Protokoll
`Lux_WS` auf **Port 8214**. Ein nicht erreichbarer Port, abgelehnter Handshake oder
Anmeldefehler wird unter **WebSocket / Messwerte** und im Debug angezeigt. Ein
geschlossener Port beweist allein keine zu alte Firmware; auch Webserver- und
Netzwerkeinstellungen können die Ursache sein.

Im Bereich **WebSocket / Messwerte** das Webinterface-Passwort einstellen
(Standard `999999`). Der zusätzliche Lesezugriff ist standardmäßig aktiviert.
Bei WebSocket als Hauptquelle wird unabhängig vom Ergänzungsschalter gelesen.
Die Verbindung läuft vom Symcon-Server auf Port 8214; damit benötigt die normale
Grafik keinen direkten Browserzugriff auf die Wärmepumpe und funktioniert auch
in einer HTTPS-Visu.

Die Informationsseiten werden über ihre Namen und die aktuell gelieferten
Menükennungen gesucht. Hex-Adressen werden nicht fest gespeichert. Zuordnungen
prüfen Seitenname, Feldname und Einheit; etwa HUP Ein/Aus und HUP Prozent sind
getrennte Messwerte. Nicht zugeordnete Informationsfelder bleiben im Diagnosebericht
sichtbar und erzeugen keine erfundenen Java-IDs oder Variablen.

Eine allgemeine Anzahl lässt sich nicht angeben: Modell, Firmware und Konfiguration
bestimmen die angebotenen Seiten. Im **LUX-Bedienfeld → Alle WebSocket-Werte testen**
werden alle angebotenen Informationsseiten gelesen. Das Ergebnis enthält die
Gesamtzahl unter `count` und die Felder je Seite unter `groups`. Der bekannte
EEV-Einzellesetest umfasst lediglich die Ausgänge; im bestätigten Gerät waren
das 25 Felder, die alle zugeordnet sind. Die Gesamtzahl der übrigen Seiten ist
noch nicht am Gerät ermittelt. Erfasst werden lesbare Informationsfelder; dies
ist keine vollständige Erfassung aller Einstellparameter.

Die angebotenen Felder können sich mit der Anlagenkonfiguration ändern. Ein
geliefertes Feld bestätigt jedoch nicht, dass der Anlagenteil tatsächlich verbaut
oder aktiviert ist: Das Webinterface kann auch ungenutzte Solar- oder Mischkreiswerte
anzeigen. Deshalb wird aus der Feldliste keine Anlagenkonfiguration abgeleitet.

Der optionale Browsertest benötigt eine über HTTP geöffnete Visu und ihr
Webinterface-Passwort. Die reguläre Serverabfrage benötigt diesen Browsertest nicht.
Sie verwendet PHP DOM zum XML-Lesen. Unter **Datenquelle** erscheint im Debug die
verwendete Herkunft; zusätzliche verfügbare Web-Werte werden getrennt gemeldet.

```php
// Normale Abfrage über die gewählte Hauptquelle und aktivierte Ergänzungen.
WPLUX_Update(28150);

// Letzten WebSocket-Snapshot mit Seiten, Anzahl, Zuordnungen und Fehlern anzeigen.
// Dieser Aufruf selbst baut keine zusätzliche Verbindung auf.
echo WPLUX_GetWebSocketData(28150);
```

## EEV und Fehlerverhalten

Die bestätigten Felder `EEV Heizen` und `EEV Kühlen` liefern bereits Prozentwerte.
Die Grafik liest sie intern als `EEV_Heizen` bzw. `EEV_Kuehlen` unabhängig von der
ID-Liste. Im Kühlbetrieb wird Kühlen, sonst Heizen angezeigt. Optionale Messvariablen
entstehen nur bei Auswahl der entsprechenden WebSocket-Zeilen in der ID-Liste.

Die zusätzliche Verbindung hat eine gemeinsame Zeitgrenze von zwei Sekunden und
wird nach dem Lesen geschlossen. Erfolgreiche Abfragen liegen mindestens fünf
Sekunden auseinander. Bei Fehlern werden aktuelle Web-Werte verworfen und der
nächste Versuch um zwei Minuten verschoben; andere Protokolle bleiben nutzbar.
Nach Wechsel von Gerät, Quelle oder Passwort werden alte Web-Werte nicht übernommen.
Fehlende bzw. abgelaufene Werte werden nicht aus alten Messobjekten rekonstruiert.

Die serverseitige Implementierung ist mit einem unabhängigen lokalen TCP/WebSocket-
Simulator geprüft. Die EEV-Namen und das XML-Format sind durch einen echten
Reglerbericht bestätigt. Die vollständige Feldabdeckung muss der Lesetest auf dem
konkreten Gerät ermitteln.

Der erste vollständige Gerätebericht umfasst 104 Einträge; der
[Abgleich mit bestehenden Idents und Schreibaktionen](WEBSOCKET-ABGLEICH.md)
unterscheidet bereits zugeordnete Werte, weitere eindeutige Entsprechungen,
zusätzliche Felder und noch offene Zuordnungen.

Protokollbeschreibung: [LoxWiki: Websockets-Webinterface ab FW 3.81](https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935938/Websockets+Webinterface+ab+FW+3.81).
Sie beschreibt auch `SET` und `SAVE`; je nach Firmware ist für das Schreiben eine
andere Kennung erforderlich (`id` bzw. `set_id`). Das ist noch kein bestätigter
Schreibweg für dieses Modul; bis zur gesonderten Umsetzung bleiben die bestehenden
Java- bzw. Modbus-Bedienaktionen maßgeblich.
