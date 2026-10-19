# Modul für Wärmepumpen mit Luxtronik für IP-Symcon

Folgende Module beinhaltet das WPLUX Symcon Repository:
- __Luxtronik__ ([Dokumentation](WPLUX))  

Dieses Modul ermöglicht, Daten der Luxtronik von verschiedener Wärmepumpen-Hersteller abzufragen.
Dazu muss sichergestellt werden, dass der Port 8888 (ältere Lux) oder 8889 (neuere Lux) nicht durch die Firewall blockiert ist.
Dieses Modul funktioniert nur mit Java-Abfrage, ab einem gewissen FW-Stand findet die Abfrage über Websocket statt.
Die Bedeutung den Typ der Variablen sind hier zu finden: https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface

### Entwicklung und Tests

Entwicklung, Commits und Pushes erfolgen auf dem Branch `development`.
Die offiziellen Symcon-Referenzen beginnen unter https://www.symcon.de/de/llms.txt.

Die Regressionstests benötigen PHP CLI mit den Erweiterungen `sockets` und `pcntl`
(Linux/macOS). Aus dem Repository-Verzeichnis ausführen:

```sh
php tests/regression.php
```

Die optionale SHI-Testversion lässt sich mit `php tests/modbus.php` prüfen.
Dieser Aufruf enthält zusätzlich alle Java-Regressionstests.
[Einrichtung und Hinweise für SHI-Tester](WPLUX/SHI-TEST.md).

Die Tests verwenden eine vereinfachte Symcon-API und einen lokalen TCP-Simulator.
Sie prüfen unter anderem Schreibwerte, Zeitgrenzen, unvollständige Antworten,
Temperaturumrechnung und Konfigurationsformulare. Sie ersetzen keinen Test unter
IP-Symcon mit einer echten Luxtronik und greifen nicht auf reale Geräte zu.

Socket-Anfragen werden nach insgesamt fünf Sekunden abgebrochen. Unvollständige
Antworten verändern keine Messwerte; ungültige Steuerwerte werden vor dem Senden
abgewiesen. Die Temperaturen der IDs 203–207 werden in Grad Celsius umgerechnet.
Neue Variablen erhalten den Typ Float. Bereits vorhandene Stringvariablen bleiben
mit ihrer bisherigen ID erhalten und bekommen ebenfalls die korrigierten Werte.
