# Luxtronik für IP-Symcon

Messwerte, Steuerung und Visualisierung für Wärmepumpen mit Luxtronik-Regler.
Das Modul unterstützt verschiedene Hersteller, unter anderem Alpha Innotec,
Buderus, CTA, Elco, Nibe, Roth, Novelan und Wolf.

**Java bleibt Standard.** Version 5.0 ergänzt WebSocket und direkte
Modbus-TCP-/SHI-Anbindung mit einer gemeinsamen Istwertliste. Bestätigte
Gegenstücke verwenden bestehende Variablen; die bisherigen Java-Steuerungen und
Zeitprogramme bleiben erhalten. SHI-Vorgaben für externe Regelungen stehen als
eigene Steuervariablen zur Verfügung.

Die integrierte HTML-SDK-Grafik bietet **Luft/Wasser** und **Sole/Wasser** im
gleichen Stil, mit passenden Messwerten und optionalen Anlagenkomponenten.

## Einstieg

1. Modul im Module Store installieren und eine Luxtronik-Instanz anlegen.
2. Unter **Verbindung und Lesen** Geräte-IP, Java-Port und Aktualisierungsintervall eintragen.
3. Gewünschte Istwerte auswählen und übernehmen. Java ist voreingestellt; alternative Quellen lassen sich einzeln oder gesammelt zuweisen.
4. Unter **HTML-SDK / Visualisierung** Wärmepumpentyp und Komponenten wählen.

Voraussetzung ist **IP-Symcon ab 8.1**. Java verwendet üblicherweise Port 8889
(ältere Geräte 8888), WebSocket 8214, Modbus 502. Für die Firmware-Reihe V3 gilt:
WebSocket ab 3.81, Modbus/SHI laut Hersteller ab 3.90.1 mit aktiviertem SHI.
Andere Versionsreihen werden gesondert geprüft. Die Konfiguration zeigt die
erkannte Unterstützung. Ein Symcon-Modbus-Gateway wird nicht benötigt.

## Dokumentation

- [Einrichtung, Bedienung und Versionsbeschrieb 5.0](WPLUX/README.md)
- [Istwerte und Quellenwahl](WPLUX/DATENQUELLEN.md)
- [Wärmepumpengrafik](WPLUX/VISUALISIERUNG.md)
- [SHI-Einrichtung und Testhinweise](WPLUX/SHI-TEST.md)
- [SHI-Registerkatalog](WPLUX/SHI-REGISTER.md)
- [Unterschiede zwischen Java-Steuerung und SHI](WPLUX/SHI-JAVA-VERGLEICH.md)
- [WebSocket und Diagnose](WPLUX/WEBSOCKET.md)

## Entwicklung und Tests

Version 5.0 befindet sich auf **development**. Entwicklung, Commits und Pushes
erfolgen auf diesem Branch. Die offiziellen Symcon-Referenzen beginnen unter
https://www.symcon.de/de/llms.txt.

Die lokalen PHP-Tests benötigen PHP CLI mit `sockets` und `pcntl`; die
WebSocket-Tests zusätzlich DOM. Im Repository-Verzeichnis ausführen:

```sh
php tests/creation.php
php tests/modbus.php
php tests/web-eev.php
php tests/visualization.php
```

Die Protokoll- und Grafiktests enthalten die Java-Regressionen aus
`tests/regression.php`. Die optionalen Desktop-/Mobiltests benötigen außerdem
Python, Playwright und Chromium:

```sh
python3 tests/browser.py
```

Die Tests verwenden vereinfachte Symcon-APIs und lokale Geräte-Simulatoren.
Sie greifen nicht auf reale Wärmepumpen zu und ersetzen keinen Gerätetest.
Die tatsächliche Instanzanlage im Zielsystem ist gesondert zu prüfen; dafür
steht [tests/native-create.php](tests/native-create.php) zur Verfügung.
Dieser Test legt eine neue Testinstanz in Symcon an und belässt sie zur Kontrolle.
