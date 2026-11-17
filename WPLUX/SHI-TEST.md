# Modbus TCP / SHI in Version 5.0

Die Version wird auf `development` geprüft. Native Gateway-Kommunikation,
Direktverbindung und SHI-Abläufe sind mit lokalen Simulatoren getestet; die konkrete
Regler-/Symcon-Kombination benötigt weiterhin einen Gerätetest.

## Einrichten

1. Geräte-IP, gemeinsames Aktualisierungsintervall und Webinterface-Passwort unter
   **Verbindung und Lesen** eintragen. WebSocket wird automatisch erkannt.
2. Bei bestätigter Firmware-Unterstützung für Modbus wie beim Goodwe-Modul ein
   natives **Symcon-Modbus-Gateway**
   neu anlegen oder ein vorhandenes Gateway zur Wärmepumpe auswählen. Wie bei
   Goodwe sind beide Wege im Assistenten erlaubt. Client Socket: Port 502;
   Gateway: Modbus TCP und Geräteadresse normalerweise 1, Adressoffset 0.
3. Gewünschte Messvariablen in der gemeinsamen **Istwertliste** wählen.
   „Aktuelle Quelle“ zeigt Java, WebSocket oder Modbus an. Alle zusätzlichen
   lesbaren SHI-Punkte stehen in derselben Liste.
4. Für externe Regelungen die gewünschten Einträge unter **Modbus / SHI – externe
   Regelung** anhaken und übernehmen. Die Variablen werden auch offline angelegt;
   Aktionen bleiben ohne aktuelle Rückmeldung/Freigabe gesperrt.

Bestehende unverbundene Installationen behalten die direkte TCP-Anbindung als
Übergang. Mit verbundenem Gateway verwendet das Modul dessen Kommunikation;
IP/Port und Geräteadresse sind dann im Socket/Gateway einzustellen. Es gibt keine
separaten Modbus-Adressinstanzen und keinen weiteren Aktualisierungstimer.
Die frühere Timeout-Einstellung betrifft nur die Antwortwartezeit der direkten
Verbindung, nicht deren Abfrageintervall.

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
Datentyp, Bereich, Auflösung, aktuelle Gerätegrenzen und Freigabe. Zielwert und
benötigte Modusabhängigkeiten werden geschrieben und zurückgelesen. Ein Fehler
führt weder zu unbestätigten Erfolgswerten noch zu einem zusätzlichen Java-Schreibzugriff.

Laut Anleitung fallen Vorgaben nach 15 Minuten ohne Modbus-Anfrage auf Standard
zurück. Eine regelmäßig laufende Istwertabfrage kann diese Kommunikation aufrecht
halten. Eine aktive Vorgabe deshalb über ihren Modus beenden, bevor die Variable
abgewählt wird. Das Modul sendet alte Sollwerte nach einem Neustart nicht erneut.

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
`GetSHIData()` nennt zusätzlich den verwendeten Transport und die Gateway-ID.

## Firmware-Erkennung prüfen

- V3.79: WebSocket-/SHI-Bereiche verborgen, kein neues Gateway angeboten.
- V3.81 und V3.90.0: WebSocket vorhanden, SHI verborgen.
- V3.90.1 und neuer: beide Bereiche sichtbar; neue und vorhandene kompatible
  Gateways werden angeboten.
- Unbekannte/andere Versionsreihe: Unterstützung bleibt unbestätigt, bis die
  Schnittstelle erfolgreich antwortet; keine automatische Gateway-Erstellung.
- Firmwarewechsel und Geräte-IP-Wechsel: Erkennungsdaten erneuern, bestehende
  Variablen, eigene Namen, Quellenauswahlen und Gateway-Verbindungen erhalten.
- „Offset Heizen“ auswählen, übernehmen und −1,0 K senden: Wert und automatisch
  gewählter Modus 2 werden zurückgelesen. Auswahl und Übernehmen allein schreiben
  keine Vorgaben. Die Freigabe am Regler bleibt Voraussetzung.

## Verwendete Referenzen

- Hersteller: **83026900aDE SHI**, insbesondere Einstellungen, Registertabellen und
  Rückfallverhalten; ergänzend **83055400pDE Luxtronik 2.1 Teil 2**.
- [Native Symcon-Modbus-Anbindung](https://www.symcon.de/de/llms/modules/modbus-rtu-tcp.md).
- [Goodwe-Modul](https://github.com/mb-stern/Goodwe): natives Gateway und
  `SendDataToParent()` mit Interface E310B701-4AE7-458E-B618-EC13A1A6F6A8.
- [Home Assistant Luxtronik SHI](https://github.com/Bankaifan/ha-luxtronik-shi):
  getrennte SHI-Steuerungen, Bereichsprüfung und Rücklesen.
