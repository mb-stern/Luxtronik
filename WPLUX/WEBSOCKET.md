# Messwerte und WebSocket-Ergänzung in 5.0

Java ist die voreingestellte Lesequelle. Unter **Bevorzugte Quelle** kann je Messwert
auch WebSocket oder Modbus gewählt werden; fehlende Werte ergänzen die übrigen
zugeordneten Schnittstellen automatisch. Null und Aus sind gültige Werte. Der Schalter
**Unterstützte Schreibbefehle über Modbus/SHI senden** ändert nur den Schreibweg.
Vorhandene Idents und Objekt-IDs bleiben erhalten. [Konfiguration](DATENQUELLEN.md).

## Konfiguration und Quellenanzeige

Unter **WebSocket-Ergänzung** stehen Firmwarehinweis (ab 3.81, Port 8214, `Lux_WS`),
Webinterface-Passwort, Abfragezeitpunkte und die Diagnose. Die normalen Ergänzungen
laufen automatisch. Die alten separaten Lese-Schalter werden nicht mehr angeboten.
Im Debug bleiben Abfragefehler und die tatsächlich verwendeten Quellen sichtbar.

Die ID-Liste enthält **Verfügbar über** für unterstützte Schnittstellen und
**Bevorzugte Quelle** zur Auswahl und **Aktuelle Quelle** für den tatsächlich verwendeten Wert: Java, WebSocket,
Modbus/SHI oder keine aktuellen Daten. Eine vollständige Java-Sammelantwort zählt
auch für Werte, die nicht zusätzlich für die Grafik gepuffert werden. Einzelne Fehlercodes
und Fehlerzeitstempel verwenden standardmäßig Java; WebSocket kann bevorzugt werden.
Die vollständige Fehlerhistorie und der Meldungstext kommen weiterhin über WebSocket.

## Gezielt lesen und trotzdem neue Felder entdecken

- Im normalen Aktualisierungszyklus werden die bevorzugten und nötigen Ersatzwerte für Grafik und
  ausgewählte Variablen angefordert, und nur deren Informationsseiten gelesen.
- WebSocket liefert ganze Seiten. Weitere mitgelieferte Felder werden nicht als
  benötigte Live-Werte übernommen. Die frühere Feldzählung beschrieb diese Seiten,
  nicht den gesamten Umfang der Schnittstelle; sie wird nicht mehr im Formular gezeigt.
- Beim ersten Kontakt und danach täglich werden alle angebotenen Informationsseiten
  als Bestand erfasst. Bei unvollständigen bzw. fehlgeschlagenen Bestandsabfragen
  folgt nach fünf Minuten ein erneuter Versuch.
- Der Bestand erzeugt keine Messobjekte. Bestätigte Zusatz-IDs sind auch ohne
  angelegte Variablen auswählbar. Noch nicht zugeordnete Felder bleiben im Bericht;
  es entstehen keine geratenen Java-Indizes oder wechselnden Idents.
- Wenn Java ausfällt, werden die zugeordneten Ersatzfelder automatisch angefordert.
  Nach Erholung hat die bevorzugte Quelle wieder Vorrang, mit denselben Idents und Objekt-IDs.

Die Menükennungen werden bei jeder Verbindung anhand von Seitennamen ermittelt.
Hex-Adressen werden nicht fest gespeichert. Zuordnungen berücksichtigen Seite,
Feldname und Einheit, etwa für HUP Ein/Aus und HUP Prozent. Ein angebotenes Feld
bestätigt keinen verbauten Anlagenteil; auch ungenutzte Solarwerte können erscheinen.

## Diagnose in der Instanzkonfiguration

**WebSocket-Ergänzung → Diagnose → Alle WebSocket-Werte prüfen und Bericht herunterladen**
liest alle Informationsseiten vom Symcon-Server und liefert eine JSON-Datei.
Die gespeicherten Verbindungseinstellungen werden verwendet. Die Diagnose erstellt
keine Variablen und schreibt keine Reglerparameter. Sie benötigt keine geöffnete Visu
und funktioniert auch bei HTTPS, weil der Browser keine Diagnose-Verbindung aufbaut.
In der Visu bleibt ausschließlich das bisherige LUX-Gerätedisplay mit Bedienung.

Der erste Gerätebericht enthielt 104 Einträge auf zehn Informationsseiten. `count`
ist die Zahl der Roh-Einträge, `mappedCount` die Zahl der daraus zugeordneten
Datenpunkte. Ein Fehlereintrag kann beispielsweise Code und Zeitstempel liefern;
Kompatibilitätszuordnungen können denselben Rohwert unter einem alten Ident abbilden.
Die Zahlen sind deshalb nicht direkt gleichzusetzen. Weitere Details im
[Abgleich](WEBSOCKET-ABGLEICH.md).

```php
WPLUX_Update(28150);                       // normale Aktualisierung
// Gespeicherter Live-Snapshot einschließlich letzter Bestandsabfrage:
echo WPLUX_GetWebSocketData(28150);
// Explizite vollständige lesende Diagnose:
echo WPLUX_RunWebSocketDiagnostic(28150);
```

## Zusätzliche IDs und Positionen

Die bestehenden Java-IDs und die eingeführten WebSocket-IDs bleiben unverändert.
Zusatzzeilen stehen unten in der ID-Liste. Nur ausgewählte Zeilen erzeugen Objekte;
bei WebSocket-Zusatzobjekten entspricht die Position der Auswahl-ID. Vorhandene
Objekt-IDs, Namen und Profile werden beim Ergänzen beibehalten.

| ID | Ident | Wert |
| --- | --- | --- |
| 400006 | `Freigabe_ZWE_Restzeit` | Restzeit Freigabe ZWE |
| 400007 | `Freigabe_Kuehlung_Restzeit` | Restzeit Freigabe Kühlung, kein boolescher Freigabestatus |
| 400008 | `Durchschnittliche_Laufzeit_Verdichter_1` | Laufzeit Ø VD1 |
| 400009 | `Softwarestand` | Vollständiger Softwarestand einschließlich Revision |
| 400010 | `Softwarestand_HZ_IO` | HZ/IO |
| 400011 | `Softwarestand_ASB` | ASB |
| 400012 | `Hardware_Revision_ASB` | ASB HW Revision |
| 400013 | `Softwarestand_Inverter` | Inverter SW Version |
| 400014 | `Abtaubedarf` | Prozent |
| 400015 | `Letzte_Abtauung` | Datum/Uhrzeit wie im Regler, ohne angenommene Zeitzone |
| 400016 | `Waermemengenzaehler_Zusatzwaermeerzeuger` | kWh |
| 400017 | `Waermemengenzaehler_Seit_Datum` | kWh seit angegebenem Reglerdatum |
| 400018 | `Waermemengenzaehler_Seit_Reset` | kWh seit Regler-Reset |
| 400019 | `GLT_Art` | Art der GLT-Anbindung |
| 400020 | `Fehlerspeicher_Meldungen` | Gesamte Fehlerhistorie mit Datum, Text und Code |

Die frühere Zusatz-ID 400003 (`Ventil_BOSUP`) entspricht dem bestehenden Ausgang
Java 43. Neue Auswahlen verwenden dessen Zeile mit Java/Modbus/WebSocket.
Eine bereits gespeicherte Auswahl von 400003 bleibt sichtbar und behält ihren Ident.
Die sechs bestätigten Betriebsstundenzähler verwenden ihre vorhandenen Java-Idents.

## Fehlerhistorie

Die vollständige Historie kommt über WebSocket. Die bestehenden
IDs 95–99 bleiben die Zeitstempel, 100–104 die Codes und 105 die Anzahl. Die neue
ID 400020 enthält den vollständigen Text. Wird WebSocket für Codes und Zeitstempel
bevorzugt, aktualisiert bzw. leert die Historie auch diese bestehenden Variablen. Eine
leere Historie leert ihren Meldungstext. Codes oder Zeitstempel, die sich nicht eindeutig lesen lassen, werden nicht
erraten. Unter Diagnose kann die Zeitzone des Reglers angegeben werden; leer verwendet
Symcon seine PHP-Zeitzone. Historische Sommerzeit wird bei der Umrechnung berücksichtigt.

In der Grafik öffnet **Fehlerspeicher: Anzahl** im Statusfeld die Historie mit Datum,
Code und Meldung. Die Anzeige beschreibt gespeicherte Meldungen, keine Liste aktuell
anstehender Störungen. Bei WebSocket-Ausfall sind die Java-Codes eine Reserve.

## Grenzen

Die automatische Web-Verbindung ist auf zwei Sekunden begrenzt; die manuelle Diagnose
auf fünf Sekunden. Erfolgreiche reguläre Abfragen liegen mindestens fünf Sekunden
auseinander. Bei Fehlern werden aktuelle Web-Werte verworfen und nach zwei Minuten
erneut versucht; Bestandsdaten sind davon getrennt und dienen nicht als Live-Ersatz.
Geräte-/Passwortwechsel binden die Caches neu. PHP DOM wird zum XML-Lesen benötigt.
Die Implementierung ist mit unabhängigen lokalen Protokoll-Simulatoren und Browsern
geprüft; ein Hardwaretest dieser Änderungen bleibt erforderlich.

Protokoll: [LoxWiki ab FW 3.81](https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935938/Websockets+Webinterface+ab+FW+3.81).
Direkte WebSocket-Parameterschreibbefehle werden nicht verwendet.
