# Abgleich Java, WebSocket und Modbus/SHI

Grundlagen: Herstellerhandbuch 83026900aDE (SHI) und die
[Java-API-Beschreibung](https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface).
Alle 129 Modbus-Register im [Registerkatalog](SHI-REGISTER.md) sind dokumentiert.
Hohe IDs in der Checkboxliste sind stabile Auswahlschlüssel, keine unbekannten
Java-Indizes. Eine verfügbare Schnittstelle ist nicht automatisch die aktuelle Quelle.

## Gemeinsame Werte

44 vorhandene Java-ID-Zeilen haben direkte oder abgeleitete Modbus-Zuordnungen.
Hinzu kommen die drei bestehenden Sollwert-Idents `Anpassung_WW`, `Anpassung_Temp`
und `Anpassung_RBE`, die auch über Java-3003/3002 bedient werden. Für diese Werte
werden dieselben Idents und Objekte verwendet. Bitmasken werden nur anhand der
Herstellerbeschreibung in die bestehenden booleschen Ausgänge aufgeteilt.

Beispiele:

| Java-ID / Ident | Modbus | Bedeutung |
| --- | --- | --- |
| 10 / `Vorlauftemperatur_Heizkreis` | IR 10105 | Vorlauf Ist |
| 15 / `Aussentemperatur` | IR 10108 | Außentemperatur |
| 18 / `Warmwasser_Soll_Temperatur` | IR 10121 | Gemessener aktueller WW-Sollwert |
| 43 / `Ausgang_Solepumpe_Ventilator` | IR 10350 | Solepumpen-/Ventilator-Ausgang |
| 44 / `Ausgang_Verdichter_1` | IR 10000, Bit 0 | Verdichter 1 ein/aus |
| 63 / `Betriebsstunden_Waermepumpe` | IR 10404 | Betriebsstunden WP |
| `Anpassung_WW` | HR 10006 | Externe WW-Sollvorgabe bei gewähltem SHI-Schreibweg |

Der WebSocket-Ausgang „Ventil.-BOSUP“ gehört ebenfalls zu Java-ID 43. Die frühere
Zusatz-ID 400003 (`Ventil_BOSUP`) und SHI-Auswahl 110350 (`SHI_Pumpe_BOSUP`) bleiben
für gespeicherte Auswahlen und vorhandene Objekte erhalten. Ohne solche Auswahl
werden diese alten Zusatzzeilen nicht erneut angeboten. Die gemeinsame Zeile 43
zeigt Java, Modbus und WebSocket als verfügbare Quellen.

## Zehn zusätzliche Messwerte in der normalen Liste

| Auswahl-ID | Register | Warum zusätzlich? |
| --- | --- | --- |
| 110201 | IR 10201 | Aktuelle Fehlernummer, nicht der historische Fehler in Speicherplatz 0 |
| 110310 | IR 10310 | Elektrische Energie gesamt laut Regler |
| 110312 | IR 10312 | Elektrische Energie Heizen |
| 110314 | IR 10314 | Elektrische Energie Warmwasser |
| 110316 | IR 10316 | Elektrische Energie Kühlen |
| 110318 | IR 10318 | Elektrische Energie Schwimmbad |
| 110326 | IR 10326 | Thermische Energie Kühlen |
| 110351 | IR 10351 | Mischkreispumpe FUP 1, auch über WebSocket zugeordnet; nicht HUP |
| 110360 | IR 10360 | Smart-Grid-Eingang 1 |
| 110361 | IR 10361 | Smart-Grid-Eingang 2 |

Die elektrischen Zähler des Reglers sind keine Aliasnamen der externen Stromzähler
für COP/JAZ. Für die übrigen Werte gibt es im bisherigen Java-Messkatalog keine
bestätigte gleichbedeutende Zuordnung. Das ist keine Behauptung, dass jede Firmware
sie grundsätzlich nicht zusätzlich über Java liefern könnte.

## Erweiterte Punkte sind nicht unbekannt

Weitere lesbare Grenzen, Konfigurationsmeldungen und Softwarekomponenten sind unter
**Modbus TCP / SHI → Weitere lesbare Modbus-Datenpunkte** einblendbar; ausgewählte
lesbare Zeilen bleiben sichtbar. Alle externen Vorgaben und Schreibfunktionen stehen
ausschließlich in der separaten Liste **Modbus-Steuervariablen**, einschließlich
alter Auswahlen aus der ID-Liste. [Konfiguration und Quellenwahl](DATENQUELLEN.md).
Einige ähnlich benannte Werte sind bewusst getrennt:

- Status Heizen: Aus / keine Anforderung / Anforderung / aktiv. Das ist keine
  Java-Betriebsart Automatik / Party / Urlaub / Aus.
- Mindeststand- und Mindestlaufzeit: konfigurierte Zeitvorgaben. Die Java-Timer
  zeigen laufende Rest- oder Betriebszeiten.
- Min-/Max-Temperaturen und Grenztemperaturen: Konfigurationsgrenzen, nicht die
  aktuellen Ist- und Solltemperaturen der Anlage.
- Konfigurationsmeldungen des Reglers: keine Entsprechung zu den Anzeigeoptionen
  des Symcon-Moduls.
- Externe SHI-Sollwerte, Level, Sperren und Leistungsbegrenzungen: eigene Funktionen;
  ähnliche Einheiten belegen keine identische Bedeutung.
- Software Major/Minor/Plattform: einzelne Komponenten, nicht der vollständige
  Softwarestand einschließlich Revision.

Bei den Schwimmbad-Betriebsstunden gibt es eine namentliche Entsprechung zu Java-ID
145. Die Java-API kennzeichnet deren Skalierung aber mit **„?? (Wert / 7200 = Stunden)“**,
während IR 10412 eindeutig Stunden liefert. Deshalb wird kein Umrechnungsfaktor
erraten und der bestehende Java-Ident nicht verändert. Dieser zusätzliche Punkt
bleibt im erweiterten Bereich, bis die Gleichwertigkeit am Gerät bestätigt ist.
