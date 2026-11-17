# Istwerte und Steuerungen

## Einrichten

Unter **Verbindung und Lesen** stehen Geräte-IP, das gemeinsame
Aktualisierungsintervall und das Webinterface-Passwort. WebSocket wird automatisch
geprüft; eine Statuszeile zeigt die Verfügbarkeit. Java bleibt die voreingestellte
Quelle. Bei fehlenden Daten ergänzen WebSocket und Modbus die zugeordneten Werte.
XML und JSON werden automatisch unterschieden. Bei fehlender Erreichbarkeit lässt
sich daraus allein keine fehlende Firmwareunterstützung ableiten.

Die Firmware wird aus der normalen Java-Abfrage gelesen, zusätzlich aus dem
Webinterface, wenn verfügbar. Die Statuszeile zeigt Firmware und Schnittstellen.
Für die Versionsreihe V3 gilt: WebSocket ab **3.81**, Modbus/SHI laut Hersteller ab
**3.90.1**. Bei eindeutig älterer Firmware werden die betreffenden Bereiche und
unbenutzten zusätzlichen Istwerte ausgeblendet; ein neues Modbus-Gateway wird erst
bei bestätigter Unterstützung angeboten. Bestehende Gateways, Variablen und
Quellenauswahlen bleiben erhalten.

Andere Versionsreihen und unvollständige Versionsangaben werden nicht anhand der
V3-Grenzen abgewiesen. Eine erfolgreiche Schnittstellenabfrage bestätigt deren
Unterstützung. Für Modbus dient dazu bei Bedarf eine einzelne lesende Abfrage der
Software-Hauptversion (Input-Register 10701), bei Fehler höchstens alle fünf Minuten.
Die Formularanzeige und Gateway-Auswahl selbst führen keine Netzwerkabfrage aus.
Ein Verbindungsfehler bleibt „noch nicht bestätigt“ und beweist keine fehlende
Unterstützung. Beim erkannten Firmwarewechsel werden nur Erkennungscaches erneuert.

**Quellen je Istwert einstellen** blendet die optionale bevorzugte Quelle und
Einzelzuordnungen ein. Die Wahl wird mit dem normalen **Übernehmen** gespeichert.
Vorhandene Einzelzuordnungen bleiben erhalten, bis eine andere Quelle gewählt wird.

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

**Beispiel:** „Offset Heizen“ anhaken und übernehmen. Deine Regelung kann über
diese Variable beispielsweise **+1,0 K** vorgeben und damit die Rücklauf-Solltemperatur
um 1 K anheben. Das Modul stellt den passenden SHI-Modus automatisch ein. Die Tabelle
verwendet die Funktionsnamen ohne zusätzliches „SHI“ wie im Webinterface.

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
Modbus-Anfrage**. Normales Abfragen hält die Kommunikation aufrecht, sendet jedoch
keine alten Schreibwerte erneut. Eine aktive Vorgabe wird über ihren SHI-Modus
beendet; das Löschen der Symcon-Variable beendet sie nicht automatisch.

## Native Symcon-Modbus-Anbindung

Wie beim Goodwe-Modul kann Luxtronik mit einem nativen **Modbus-Gateway** verbunden
werden. Der Assistent bietet wie bei Goodwe neue und vorhandene kompatible
Gateways an (`connect`). Das gewählte Gateway und sein Client Socket müssen zur
Wärmepumpe gehören. IP/Port werden im Socket, TCP-Betrieb und Geräteadresse im
Gateway konfiguriert (SHI: Port 502,
Geräteadresse normalerweise 1). Es sind keine zusätzlichen Modbus-Adressinstanzen
und keine zusätzlichen Aktualisierungstimer nötig.

Mit verbundenem Gateway sendet das Modul Registeranfragen über `SendDataToParent()`;
Symcon übernimmt TCP-Verbindung, MBAP-Rahmen und Transaktionen. Die SHI-Bedeutung,
Skalierung und bestätigten Schreibabläufe bleiben im Modul. Ein Gatewayfehler führt
nicht zu einem zweiten Schreibversuch über die Direktverbindung.

Bestehende unverbundene Installationen behalten als Übergang die bisherige direkte
TCP-Anbindung. Deren Port, Geräteadresse und Antwort-Timeout stehen ausschließlich
unter **Diagnose und erweiterte Einstellungen → Bisherige Direktverbindung**. Der
Timeout ist eine Antwortwartezeit, kein weiterer Abfragetimer. Sobald ein Gateway
verbunden ist, hat dessen Konfiguration Vorrang. Der normale SHI-Bereich zeigt
statt doppelter Anschlussfelder den Verbindungsstatus des Symcon-Gateways.

## COP und Diagnose

Ohne externen COP-Leistungsmesser kann der interne COP aus **ID 257 (kW)** geteilt
durch **ID 268 (W) / 1000** berechnet werden. Eine gewählte externe Leistungsvariable
hat Vorrang. Die interne Berechnung ist separat abschaltbar; die JAZ-Konfiguration
bleibt erhalten. Energiezähler in kWh werden nicht als momentane Leistung benutzt.

Die eingeklappten Diagnosefunktionen liefern WebSocket-Inventar und Modbus-Status.
Die normale Konfiguration benötigt keine separaten WebSocket-Schalter oder Timer.
