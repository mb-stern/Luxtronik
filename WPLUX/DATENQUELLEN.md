# Istwerte und Steuerungen

## Einrichten

Direkt unter **Modul aktiv** stehen unter **Verbindung und Lesen** Geräte-IP,
gemeinsames Aktualisierungsintervall, Java-Port und Webinterface-Passwort
sowie Modbus-Port, Geräteadresse und Antwortwartezeit. Ein weiteres Ausklappmenü
ist dafür nicht nötig. WebSocket wird automatisch
geprüft; eine Statuszeile zeigt die Verfügbarkeit. Java bleibt die voreingestellte
Quelle. Bei fehlenden Daten ergänzen WebSocket und Modbus die zugeordneten Werte.
XML und JSON werden automatisch unterschieden. Bei fehlender Erreichbarkeit lässt
sich daraus allein keine fehlende Firmwareunterstützung ableiten.

Die Firmware wird aus der normalen Java-Abfrage gelesen, zusätzlich aus dem
Webinterface, wenn verfügbar. Die Statuszeile zeigt Firmware und Schnittstellen.
Für die Versionsreihe V3 gilt: WebSocket ab **3.81**, Modbus/SHI laut Hersteller ab
**3.90.1**. Bei eindeutig älterer Firmware werden die betreffenden Bereiche und
unbenutzten zusätzlichen Istwerte ausgeblendet. Bestehende Variablen und
Quellenauswahlen bleiben erhalten.

Andere Versionsreihen und unvollständige Versionsangaben werden nicht anhand der
V3-Grenzen abgewiesen. Eine erfolgreiche Schnittstellenabfrage bestätigt deren
Unterstützung. Für Modbus dient dazu bei Bedarf eine einzelne lesende Abfrage der
Software-Hauptversion (Input-Register 10701), bei Fehler höchstens alle fünf Minuten.
Die Formularanzeige selbst führt keine Netzwerkabfrage aus.
Ein Verbindungsfehler bleibt „noch nicht bestätigt“ und beweist keine fehlende
Unterstützung. Beim erkannten Firmwarewechsel werden nur Erkennungscaches erneuert.

**Quellen je Istwert einstellen** blendet Quellenwahl und Einzelzuordnungen direkt
über der Istwertliste ein. Der Knopf **Für alle passenden Messwerte übernehmen**
steht neben der Quellenwahl. Der Bearbeitungsdialog wird für jeden Istwert einzeln
erzeugt und bietet nur zugeordnete Protokolle mit bestätigter Firmware- oder
Schnittstellenunterstützung an. Java wird bei einem erfolgreich gelesenen kürzeren
Datenrahmen für die darin fehlenden IDs nicht angeboten. Ein späterer
Verbindungsfehler verwirft diese bekannte Zuordnung nicht. Einzeländerungen im
Dialog werden mit dem normalen **Übernehmen** gespeichert; die Sammelauswahl
ändert nur passende Messwerte. Bestehende Variablen und Auswahl-IDs bleiben erhalten.

## Eine gemeinsame Istwertliste

Die Liste enthält Java-Messwerte, bekannte WebSocket-Ergänzungen und alle
zusätzlichen lesbaren SHI-Register. **Aktiv** wählt die gewünschte Symcon-Variable;
**Aktuelle Quelle** nennt die tatsächlich verwendete Schnittstelle. Zusätzliche
Steuerungsregister stehen ausschließlich im SHI-Steuerungsbereich.

Ein Messwert besitzt einen gemeinsamen Ident und eine gemeinsame Objekt-ID, soweit
seine Bedeutung und Einheit für die Quellen bestätigt sind. Ein Quellenwechsel
ersetzt keine bestehenden Objekte, Namen oder Profile. Gültige Nullwerte und „Aus“
werden nicht als fehlend behandelt. Nicht zugeordnete Quellen werden übersprungen.

| Bevorzugte Quelle | Ersatzquellen |
| --- | --- |
| Java | WebSocket, Modbus |
| WebSocket | Java, Modbus |
| Modbus | Java, WebSocket |

Bei Rückkehr der bevorzugten Quelle wird diese wieder verwendet. Ein gemeinsamer
Aktualisierungslauf liest die benötigten Quellen; eine erfolgreiche Abfrage wird
nicht durch eine zusätzliche fünfsekündige Cachepause auf den nächsten Lauf
verschoben. Verbindungsfehler behalten eine begrenzte Wiederholpause.

Die WebSocket-Bestandserfassung liest rekursiv die Informationsseiten einschließlich
**Energiemonitor → Wärmemenge / Leistungsaufnahme**. Benannte, eindeutig zugeordnete
Werte werden integriert. Das garantiert keine 1:1-Abdeckung aller internen
Java-Indizes. Nicht zugeordnete Felder bleiben im herunterladbaren Diagnosebericht
sichtbar. Regler-Menükennungen werden dynamisch gelesen, nicht fest eingebaut.

## Bestehende Java-Steuerung

**Steuerung, Zeitprogramme und COP/JAZ** enthält die bisherigen Einstellungen.
`Anpassung_Temp`, `Anpassung_WW` und `Anpassung_RBE` bleiben Java-Reglereinstellungen.
Betriebsarten und Zeitprogramme behalten ihre bisherigen Variablen und Aktionen.
Die Auswahl eines SHI-Reglers ändert deren Protokoll und Inhalt nicht.

## SHI: eigene Vorgaben für externe Regelungen

**Modbus / SHI – externe Regelung** enthält die 41 dokumentierten Holding-Funktionen.
Ausgewählte Einträge erzeugen nach **Übernehmen** eigene Steuervariablen, auch wenn
noch keine Verbindung besteht. Bedienbar sind sie erst mit gültiger Rückmeldung und
passender Freigabe. Auswählen, Speichern und zyklisches Lesen schreiben keine Werte.

Unter **Hinweise zu SHI** wird das Zusammenspiel der vier Heizvariablen erklärt:

| Variable | Funktion |
| --- | --- |
| Modus Heizen | 0: kein Einfluss, 1: Sollwert, 2: Offset, 3: Level |
| Sollwert Heizen | Direkte Rücklauf-Solltemperatur, beispielsweise 30 °C; wirkt bei Modus 1 |
| Offset Heizen | Korrektur der berechneten Rücklauf-Solltemperatur: 28 °C + 1 K = 29 °C; wirkt bei Modus 2 |
| Level Heizen | 0: kein Einfluss, 1: überhöht mit Zeitprogramm, 2: überhöht ohne Zeitprogramm, 3: reduziert; wirkt bei Modus 3 |

Sollwert und Offset werden nicht zusammengerechnet. Für eine Temperaturkorrektur
reicht die Auswahl von „Offset Heizen“. Beim Senden über **Schalten / RequestAction()**
setzt das Modul den Modus automatisch auf 2; die Modusvariable muss dafür nicht
zusätzlich angelegt werden. Ein später gesendeter direkter Sollwert aktiviert
Modus 1, sodass der gespeicherte Offset nicht mehr wirkt. Die Tabelle verwendet
die Funktionsnamen ohne zusätzliches „SHI“ wie im Webinterface.

| SHI-Variable | Register | Bedeutung |
| --- | --- | --- |
| `SHI_Offset_Heizen` | 10002 | Externer Offset auf den Rücklauf-Sollwert |
| `SHI_Sollwert_Warmwasser` | 10006 | Externe Warmwasser-Sollvorgabe |
| `SHI_Raumtemperatur_Soll` | 10060 | Externe Raumtemperaturvorgabe |

SHI ist eine zusätzliche externe Beeinflussung. Beispielsweise können eine
Java-Korrektur von −1 K und ein SHI-Offset von +1 K gleichzeitig wirken. Die Werte
werden deshalb nicht in derselben Steuervariable zusammengefasst.

Eine Bedienaktion prüft Wertebereich, Auflösung, Reglergrenzen und Freigabe, schreibt
die gewünschte Vorgabe und aktiviert die dokumentierten Modusabhängigkeiten.
Anschließend werden Wert und Modus zurückgelesen. Ein Schreibfehler löst keine
zusätzliche Java-Schreibaktion aus.

Die SHI-Anleitung nennt einen Rückfall auf Standardwerte nach **15 Minuten ohne
Modbus-Anfrage** (Seite 5). Diese Rückfallüberwachung gehört zum Regler.
Das Modul liest Modbus bedarfsgesteuert; laufende Java- oder WebSocket-Abfragen
belegen keine Modbus-Kommunikation. Es sendet keine alten Schreibwerte erneut.
Die [Analyse der Firmware 3.92.3](https://github.com/Bankaifan/ha-luxtronik-shi/blob/main/docs/firmware-3.92.3-shi-analysis.md#shi-watchdog)
beschreibt dagegen 600 Sekunden und einen Neustart des Zählers bei jedem
Modbus-Zugriff einschließlich Lesen. Dauer und Details dürfen daher nicht pauschal
für jede Firmware vorausgesetzt werden. Eine aktive Vorgabe wird über ihren SHI-Modus
beendet; das Löschen der Symcon-Variable beendet sie nicht automatisch.

## Direkte Modbus-TCP-Anbindung

Das Modul verbindet sich selbst mit der oben eingetragenen IP-Adresse der
Wärmepumpe. Unter **Verbindung und Lesen** stehen Modbus-Port (normalerweise
**502**), Geräteadresse / Unit-ID (normalerweise **1**) und Modbus-Antwortwartezeit
(in Millisekunden). Zeitangaben aus dem Webinterface werden standardmäßig mit der
Symcon-Zeitzone ausgewertet.
Der gemeinsame Aktualisierungszyklus liest alle benötigten Werte.

Es werden kein Symcon-Modbus-Gateway, kein Client Socket und keine zusätzlichen
Timer oder Adressinstanzen benötigt. Bereits angelegte Gateways und Client Sockets
werden nicht mehr verwendet und vom Modul nicht gelöscht. Ein noch verbundener
alter Parent beeinflusst die direkte Kommunikation nicht. Bestehende Messwerte,
Steuervariablen, eigene Namen und Auswahl-IDs bleiben erhalten.

## COP und Diagnose

Ohne ausgewählte externe Leistungsquelle wird der COP automatisch aus
**ID 257 (kW)** geteilt durch **ID 268 (W) / 1000** berechnet. Eine gewählte externe
Leistungsvariable hat Vorrang. Beim Wechsel der Quelle bleibt die bestehende
COP-Variable erhalten. Die JAZ-Konfiguration bleibt unverändert. Energiezähler in
kWh werden nicht als momentane Leistung benutzt.

Die eingeklappten Diagnosefunktionen liefern WebSocket-Inventar und Modbus-Status.
Die normale Konfiguration benötigt keine separaten WebSocket-Schalter oder Timer.
