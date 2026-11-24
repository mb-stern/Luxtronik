# Abgleich Java, WebSocket und Modbus/SHI

Grundlagen: Herstellerhandbuch 83026900aDE (SHI) und die
[Java-API-Beschreibung](https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface).
Alle 129 Modbus-Register im [Registerkatalog](SHI-REGISTER.md) sind dokumentiert.
Hohe IDs in der Checkboxliste sind stabile Auswahlschlüssel, keine unbekannten
Java-Indizes. Eine verfügbare Schnittstelle ist nicht automatisch die aktuelle Quelle.

## Gemeinsame Werte

Vorhandene Java-Istwert-Zeilen haben dort Modbus-Zuordnungen, wo ihre physikalische Bedeutung bestätigt ist.
Die bisherigen Java-Sollwerte `Anpassung_WW`, `Anpassung_Temp` und
`Anpassung_RBE` bleiben unabhängig. SHI besitzt dafür die eigenen Vorgabe-Idents
`SHI_Sollwert_Warmwasser`, `SHI_Offset_Heizen`, `SHI_Raumtemperatur_Soll`.

## Beispiele zusätzlicher Messwerte in der gemeinsamen Liste

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

Weitere lesbare Grenzen, Konfigurationsmeldungen und Softwarekomponenten stehen
ebenfalls direkt in der gemeinsamen Istwertliste. Alle externen Vorgaben und Schreibfunktionen stehen
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
