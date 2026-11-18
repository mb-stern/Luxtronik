# Java-Reglereinstellungen und externe SHI-Vorgaben

Grundlage ist das Herstellerhandbuch **83026900aDE**. Java-Steuerungen und SHI sind
unterschiedliche Reglerfunktionen; ähnliche Bezeichnungen begründen keine identische
Steuervariable. Die Istwertquellen werden unabhängig davon zusammengeführt.

| Bestehende Java-Variable | Java-Schreibparameter | Eigenständige SHI-Variable | SHI-Register |
| --- | --- | --- | --- |
| `Anpassung_Temp` | 1 | `SHI_Offset_Heizen` | 10002 |
| `Anpassung_WW` | 105 | `SHI_Sollwert_Warmwasser` | 10006 |
| `Anpassung_RBE` | 1148 | `SHI_Raumtemperatur_Soll` | 10060 |

Die Java-Variablen behalten Objekt-IDs, Namen, Profile und ihre Java-Schreibaktionen.
Bei `Anpassung_WW` bleibt der Schreibparameter 105 erhalten; die Anzeige verwendet
den aktuell wirksamen Warmwasser-Sollwert aus Java-Istwert 18 bzw. dessen
zugeordneter Ersatzquelle. Dieser kann sich auch durch eine externe Vorgabe ändern.
Die Heizkorrektur wird weiterhin aus Parameter 1 zurückgelesen. Beide Rückmeldungen
werden intern unabhängig davon gelesen, ob Symcon-Variablen angelegt sind.
SHI-Vorgaben sind zusätzliche externe Eingriffe. Eine Java-Korrektur und ein
SHI-Offset können gleichzeitig wirken und sich beispielsweise gegenseitig aufheben.
SHI-Betriebsmodi „Kein Einfluss / Sollwert / Offset / Level“ sind nicht die
Java-Betriebsarten „Automatik / Party / Aus“.

## Heizungs-Offset und negative Werte

SHI-Register 10002 ist INT16 mit 0,1 K Auflösung und −20 bis +20 K Bereich. −1,0 K
wird als ein 16-Bit-Wort `0xFFF6` (signed −10) übertragen. Damit die Vorgabe wirkt,
setzt das Modul bei einer expliziten Bedienaktion die dokumentierten Abhängigkeiten:
Gesamt-Heizmodus 10065 auf 0 und Heizmodus 10000 auf 2. Freigabe DI 10000 wird zuvor
geprüft; alle geschriebenen Werte werden zurückgelesen.

Auch +0,5 K und −0,5 K sind gültige SHI-Vorgaben. Das Modul setzt die Schrittweite
für die SHI-Vorgaben mit Nachkommastellen ausdrücklich auf 0,1 und korrigiert
bestehende Modulprofile bzw. Schieberegler mit ganzen Schritten auf denselben
Variablen. Die normale Java-Heizkorrektur bleibt davon getrennt; ihre Kachel liest
Parameter 1 aus dem internen Rückmeldespeicher.

Die bisherigen Auswahl-IDs bleiben erhalten: 310002 bezeichnet Register 10002.
320002 ist kein zugeordneter Steuerpunkt. Es wird keine Registeradresse geraten.

## Andere Vorgaben

- SHI-Warmwasser-Soll (10006): 30–75 °C, zusätzlich aktuelle Gerätegrenzen;
  benötigt Warmwasserfreigabe und SHI-Warmwassermodus 1.
- SHI-Raumtemperatur-Soll (10060): 15–30 °C, passende RBE-Freigabe und keine
  parallelen SHI-Heiz-/Mischkreisvorgaben.
- Absolute Rücklauf-Sollvorgabe 10001, Mischkreise, Levels, Leistungsbegrenzung,
  Sperren, Zirkulation und Extra-Warmwasser sind eigene SHI-Funktionen.

Die vollständige Zuordnung steht im [Registerkatalog](SHI-REGISTER.md).

## Kühlungswärmemenge: bisheriger Prüfstand

Java-Istwert 153 ist im bestehenden Modul dem Schwimmbadzähler zugeordnet.
Kühlungswärmemenge wird separat über SHI-Input-Register 10326 (Auswahl-ID 110326)
beziehungsweise den zugeordneten WebSocket-Wert gelesen. Der in Fremdintegrationen
genannte Java-Parameter 1135 gehört zu Kommando 3003 und konnte auf der Testanlage
bereits nicht als nutzbare Alternative bestätigt werden. Er wird deshalb nicht
erneut als bestätigte Java-Zuordnung übernommen. Die Reihenfolge der Zähler im
Webinterface ist kein Nachweis für die Bedeutung des Java-Istwertindex 153.

## Zeitverhalten

Die SHI-Anleitung nennt **15 Minuten ohne Modbus-Kommunikation** bis zum Rückfall
auf Standardwerte. Normales Lesen hält die Kommunikation aufrecht. Das Modul
wiederholt alte Vorgaben bei Verbindungsabbruch oder Neustart nicht automatisch.
Vor dem Abwählen einer noch wirksamen Vorgabe muss deren SHI-Modus bei Bedarf auf
„Kein Einfluss“ zurückgesetzt werden. Aus diesen Schnittstellenbeschreibungen
folgt keine allgemeine Aussage über interne EEPROM-Schreibzugriffe der Firmware.

Frühere Teststände verwendeten die drei Java-Idents auch für SHI. Neu ausgewählte
SHI-Steuerungen besitzen eigene Idents. Bestehende Java-Automationen bleiben Java;
für eine SHI-Regelung sind ausdrücklich die neuen SHI-Variablen zu verwenden.

Bereits vorhandene gemeinsame Testvariablen werden bei noch aktiver zugehöriger
SHI-Auswahl als inaktive Java-Objekte mit ihren IDs, Namen und letzten Werten
behalten. Das vermeidet den Verlust von Historien. Sie werden nicht stillschweigend
zu Java-Schreibzielen umgedeutet; die eigene SHI-Variable ist für neue Aktionen zu
verwenden. Aktivierte reguläre Java-Steuerungen bleiben unverändert bedienbar.
