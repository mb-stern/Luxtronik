# Modul für Wärmepumpen mit Luxtronik für IP-Symcon


Folgende Module beinhaltet das WPLUX Symcon Repository:
- __Luxtronik__ ([Dokumentation](WPLUX))  

Dieses Modul ermöglicht, Daten der Luxtronik von verschiedener Wärmepumpen-Hersteller abzufragen.
Dazu muss sichergestellt werden, dass der Port 8888 (ältere Lux) oder 8889 (neuere Lux) nicht durch die Firewall blockiert ist.
Dieses Modul funktioniert nur mit Java-Abfrage, ab einem gewissen FW-Stand findet die Abfrage über Websocket statt.
Die Bedeutung den Typ der Variablen sind hier zu finden: https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface
