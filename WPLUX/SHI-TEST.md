# Modbus TCP / SHI in Version 5.0

Die Version wird auf `development` geprüft. Die direkte Modbus-TCP-Verbindung
und SHI-Abläufe sind mit lokalen Simulatoren getestet; die konkrete
Regler-/Symcon-Kombination benötigt weiterhin einen Gerätetest.

## Einrichten

1. Geräte-IP, gemeinsames Aktualisierungsintervall und Webinterface-Passwort unter
   **Verbindung und Lesen** eintragen. WebSocket wird automatisch erkannt.
2. Unter **Verbindung und Lesen** Modbus-Port **502** und Geräteadresse
   **1** einstellen (sofern der Regler andere Werte benötigt, diese verwenden).
   Die Verbindung erfolgt direkt aus dem Modul zur Geräte-IP.
3. Gewünschte Messvariablen in der gemeinsamen **Istwertliste** wählen.
   „Lesequelle“ zeigt Java, WebSocket oder Modbus an; der getrennte Datenstatus
   zeigt, ob ein Wert bereits gelesen wurde. Alle zusätzlichen
   lesbaren SHI-Punkte stehen in derselben Liste.
4. Für externe Regelungen die gewünschten Einträge unter **Modbus / SHI – externe
   Regelung** anhaken und übernehmen. Die Variablen werden auch offline angelegt;
   Aktionen bleiben ohne aktuelle Rückmeldung/Freigabe gesperrt.

Symcon-Modbus-Gateway und Client Socket werden nicht benötigt. Bereits angelegte
Instanzen werden weder verwendet noch gelöscht. Auch ein noch angehängtes Gateway
leitet keine Modbus-Anfragen mehr weiter. Der vorhandene Aktualisierungstimer
bleibt der einzige Timer; der Modbus-Timeout unter Verbindung und Lesen ist die
Antwortwartezeit der direkten Verbindung.

## Review: frische Modulanlage

- Lokal prüft `php tests/creation.php` Anlage, erstes und wiederholtes Übernehmen,
  Formular und Kachel mit leerer Standardkonfiguration. Eine noch fehlende IP ist
  kein Fehler im Systemlog; bis zur Konfiguration ist der Aktualisierungstimer aus.
- Der lokale Test prüft außerdem das erste Übernehmen mit einem Java-Testregler.
- Die tatsächliche Anlage im Symcon-Assistenten ist zusätzlich im Zielsystem
  (insbesondere 9.1 RUST) zu prüfen. Dafür kann `tests/native-create.php` in der
  Symcon-Skriptkonsole ausgeführt werden. Der Test legt **eine neue Testinstanz**
  mit Standardwerten an und belässt sie zur Kontrolle von Formular und Systemlog.
  Er verändert keine bestehenden Instanzen und sendet keine Befehle an die WP.
  Ein bestandener lokaler Test ersetzt diesen nativen Review-Schritt nicht.

## Messwerte und Steuerungen

Die 129 dokumentierten SHI-Punkte umfassen 12 Discrete Inputs, 76 Input Register
und 41 Holding-Funktionen. Registerart und Adresse bilden gemeinsam die technische
Kennung. Gleiche Messwerte behalten ihre bestehenden Idents und Objekt-IDs.

SHI-Vorgaben sind eigenständig gegenüber Java-Einstellungen. Beispielsweise ist
`SHI_Offset_Heizen` eine externe Vorgabe aus Register 10002; `Anpassung_Temp` bleibt
die ursprüngliche Java-Heizkorrektur. Warmwasser und Raumtemperatur werden ebenso
getrennt behandelt. [Vergleich](SHI-JAVA-VERGLEICH.md),
[Konfiguration](DATENQUELLEN.md), [vollständiger Katalog](SHI-REGISTER.md).

Auswahl und zyklisches Lesen schreiben keine Werte. Erst eine Aktion prüft
Datentyp, Bereich, Auflösung, aktuelle Gerätegrenzen und Freigabe. Zuerst werden
die benötigten Modi geschrieben und bestätigt, danach folgt der Zielwert. Ein Fehler
führt weder zu unbestätigten Erfolgswerten noch zu einem zusätzlichen Java-Schreibzugriff.
Selbst geänderte Modi werden bei einem Fehler nach frischer Prüfung nach Möglichkeit
zurückgenommen; ein fremder oder nicht mehr eindeutig zugeordneter Modus wird
nicht überschrieben. Eine fehlende Bestätigung der Rücknahme wird protokolliert.

Warmwasser: Zuerst wird der benötigte Modus 10005 gesetzt und bestätigt, danach
die Vorgabe (10006/10007/10008). Einige Firmwarestände übernehmen diese Register
verzögert. Daher wird bis zu 15 Sekunden je Register ausschließlich zurückgelesen,
ohne den Befehl zu wiederholen. Die tatsächliche Verzögerung hängt vom Regler ab.
[Erfahrungen einer anderen SHI-Integration](https://github.com/Bankaifan/ha-luxtronik-shi/blob/main/docs/firmware-3.92.3-shi-analysis.md#deferred-writes-of-the-hot-water-group)
beschreiben eine solche Verzögerung für die Warmwassergruppe; dies ersetzt keinen
Test des jeweiligen Geräts.

Die SHI-Sollwerte sind eigene, lesbare Holding-Werte. Bei Modus 0 können sie
beispielsweise 35 °C (Heizen, 10001) oder 40 °C (Warmwasser, 10006) melden, während
die wirksamen Sollwerte im Regler anders lauten. Diese sind als Istwerte über
Input-Register 10101 (Rücklauf Soll) bzw. 10121 (Warmwasser Soll) verfügbar.

Laut Anleitung fallen Vorgaben nach 15 Minuten ohne Modbus-Anfrage auf Standard
zurück. Der Zeitraum beginnt nicht pauschal beim letzten Schreiben. Das Modul
liest ausgewählte SHI-Steuervariablen und zuletzt geschriebene Modusregister;
auch solche Leseanfragen können die Kommunikation aufrechterhalten. Die Vorgabe
wird dabei nicht erneut geschrieben. Eine aktive Vorgabe deshalb über ihren
Modus beenden, bevor die Variable abgewählt wird. Das Modul sendet alte Sollwerte
nach einem Neustart nicht erneut.

Eine separate Rückmeldung „Sollwert gelöscht“ ist nicht dokumentiert. Geänderte
Registerwerte werden beim nächsten erfolgreichen Lesen in die bestehenden
Variablen übernommen. Modus 0 beendet den Einfluss auch dann, wenn der numerische
Sollwert noch gespeichert bleibt. Ein lokales Zurücksetzen nach 15 Minuten würde
daher keinen bestätigten Reglerzustand darstellen.

## Quellen und Grenzen

Java ist Standard, WebSocket und Modbus ergänzen bestätigte fehlende Werte.
Optional können Quellen je Istwert gewählt werden. Null und „Aus“ gelten als Werte.
Firmwareabhängig fehlen Messungen oder Funktionen; Modbus bietet insbesondere kein
bestätigtes Gegenstück für EEV-Öffnungen, Verdichterfrequenzen, laufende Zykluszeiten
oder Durchfluss. Die belegten WebSocket-/Java-Zuordnungen ergänzen diese Punkte.

Elektrische Energiezähler in kWh und momentane Leistung in W/kW werden getrennt
behandelt. ID 257 ist Wärmeleistung in kW, ID 268 Leistungsaufnahme in W. Ohne
externen Leistungsmesser kann daraus der interne COP berechnet werden. Modbus
überträgt die Leistungswerte in 100-W-Schritten. Die externe COP-/JAZ-Auswahl bleibt
bestehen. Eine fehlende Energiezählung wird nicht durch eine frei angenommene
Java-Parameterzuordnung ersetzt.

Die erfolgreiche Modbus- und WebSocket-Abfrage wird bei jedem neuen gemeinsamen
Aktualisierungslauf erneut ausgeführt. Innerhalb eines Laufs können Vorabfrage und
Ergänzung aktuelle Messwerte teilen. Fehler führen zu einer Wiederholpause; alte
Objektwerte gelten dann nicht automatisch als aktuelle Messungen. Unveränderte
Werte werden nicht nochmals in Symcon geschrieben.

## PHP und Diagnose

```php
// Ausgewählte, eigenständige SHI-Variablen bedienen:
RequestAction($offsetVariableID, -1.5);

// Explizite SHI-API (ausgewählte Funktion oder zusätzliche API-Freigabe erforderlich):
WPLUX_SetSHIValue($instanceID, 'SHI_Offset_Heizen', -1.5);
WPLUX_SetSHIValue($instanceID, 'SHI_Sollwert_Warmwasser', 50.0);

// Nur aktuell gespeicherten Modbus-/Java-Status ausgeben:
echo WPLUX_GetSHIData($instanceID);
```

Die ausdrücklich SHI-bezogene API akzeptiert aus Kompatibilitätsgründen auch die
früheren Argumentnamen `Anpassung_Temp`, `Anpassung_WW`, `Anpassung_RBE`. Ein normales
`RequestAction` auf diesen bestehenden Java-Variablen bleibt dagegen immer Java.

Unter **Diagnose und erweiterte Einstellungen** stehen WebSocket-Bestandsbericht
und Modbus-Status. Der WebSocket-Bericht erfasst rekursiv die Informationsseiten
und enthält auch nicht zugeordnete Felder; Zugangsdaten und LCD-Bilddaten fehlen.
`GetSHIData()` nennt als Transport **Modbus TCP (Direktverbindung)**. Der zuvor
zur Gateway-Fehlersuche ergänzte Abschnitt `connectionSetup` entfällt.

Unter `connectionDiagnostics` stehen die letzte erfolgreiche Modbus-Leseantwort,
der letzte Lesefehler und die letzte erkannte Leselücke. Das Modul hält eine Lücke
ab zehn Minuten oder bei der ersten Antwort nach einem Lesefehler fest. Der Bericht
merkt sich auch, wann ein zuvor aktiver Heiz-, Warmwasser- oder Mischkreismodus
wieder als 0 („Kein Einfluss“) gelesen wurde. Wenn nach der letzten aktiven Rückmeldung
eine Leselücke erkannt wurde, steht sie beim Moduswechsel dabei. Zeitangaben sind
Unix-Zeitstempel. Die Dauer misst den Abstand zwischen den Leseantworten dieser
Instanz. Für die Ursache des Rückfalls bleibt das EventLog des Reglers massgebend.

## Firmware-Erkennung prüfen

- V3.79: WebSocket-/SHI-Bereiche verborgen.
- V3.81 und V3.90.0: WebSocket vorhanden, SHI verborgen.
- V3.90.1 und neuer: beide Bereiche einschließlich direkter Modbus-Einstellungen sichtbar.
- Unbekannte/andere Versionsreihe: Unterstützung bleibt unbestätigt, bis die
  Schnittstelle erfolgreich antwortet.
- Firmwarewechsel und Geräte-IP-Wechsel: Erkennungsdaten erneuern, bestehende
  Variablen, eigene Namen und Quellenauswahlen erhalten.
- „Offset Heizen“ auswählen, übernehmen und −1,0 K senden: Wert und automatisch
  gewählter Modus 2 werden zurückgelesen. Auswahl und Übernehmen allein schreiben
  keine Vorgaben. Die Freigabe am Regler bleibt Voraussetzung.

## Verwendete Referenzen

- Hersteller: **83026900aDE SHI**, insbesondere Einstellungen, Registertabellen und
  Rückfallverhalten; ergänzend **83055400pDE Luxtronik 2.1 Teil 2**.
- [Home Assistant Luxtronik SHI](https://github.com/Bankaifan/ha-luxtronik-shi):
  getrennte SHI-Steuerungen, Bereichsprüfung und Rücklesen.
