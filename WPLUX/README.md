# Modul für Wärmepumpen mit Luxtronik für IP-Symcon
Dieses Modul ermöglicht, Daten der Luxtronik verschiedener Wärmepumpen-Hersteller (zB Alpha InnoTec, Buderus (Logamatic HMC20, HMC20 Z), CTA All-In-One (Aeroplus), Elco, Nibe (AP-AW10), Roth (ThermoAura, ThermoTerra), Novelan (WPR NET) and Wolf Heiztechnik (BWL/BWS)) abzufragen.
Ausserdem ist es geeignet für Besitzer einer PV-Anlage, um die überschüssige Energie gemäss eigenen Vorstellungen der Wärmepumpe zuzuführen. Dies ist möglich durch Anpasen von Warmwasser- und Rücklauf-Solltemperatur.
Um das Modul zu nutzen, muss der in der Luxtronik integrierte RJ45 Netzwerkanschluss mit dem heimschen Netzwerk verbunden werden.
Danach muss sichergestellt werden, dass der Port 8888 (ältere Lux) oder 8889 (neuere Lux) nicht durch die Firewall blockiert ist.
Dieses Modul funktioniert über Java-Abfrage, ab einem gewissen FW-Stand der LUX findet die Abfrage über Websocket statt. Java sollte aber weiterhin funktionieren.
Die Bedeutung und ID's der Variablen sind hier zu finden: https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface

![image](https://github.com/mb-stern/WPLUX-Symcon/assets/95777848/ff2b9244-a2b8-4d65-9903-bc2464c1c98d)


### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

* Abfrage der Istwerte und Steurung der Luxtronik, welche in verschiedenen Wärmepumpen als Steuerung verbaut ist.
* Es werden automatisch die gewünschten Variablen angelegt und die benötigten Profile erstellt.
* Es werden jedoch nicht restlos alle Werte in Variablen aufgeschlüsselt, bei Bedarf ist daher der Name manuell einzutragen.
* Ebenfalls werden je nach Wärmepumpen-Typ nicht alle Werte geliefert. Offensichtlich werden mit einer Software alle Wärmepumentypen abgedeckt.
* Es können Variablen für die Steuerung von Heizung, Warmwasser und Kühlung aktiviert werden, je nach Funktionsumfang der Wärmepumpe. Diese Variablen zur Steuerung werden nicht live synchronisiert, sondern immer erst dann, wenn Änderungen am Konfigurationsformular vorgenommen wurden.
* Die Anzeige des COP-Faktor ist nun unter Zuhilfenahme einer externen Leistungsmessung (kW) möglich. Die entsprechende Variable kann im Konfigurationsformular ausgewählt werden.
* Die Anzeige des JAZ-Faktor ist nun unter Zuhilfenahme einer externen Leistungsmessung (kWh) möglich. Die entsprechende Variable kann im Konfigurationsformular ausgewählt und die Berechnung bei Bedarf zurückgesetzt werden.
* Es kann die interne Timerfunktion der Luxtronik genutzt werden. Es kann ausgewählt werden, wie viele Variablen (Zeitfenster) erstellt werden sollen, um nicht unnötige Variablen zu verwenden. Die maximale Menge ist analog den Programmiermöglichkeiten über das Webinterface. Beim deaktivieren der Timerfenster bleiben die ursprünglich gespeicherten Werte aber erhalten.
* Der Sollwert der Raumtermperatur bei vorhandener Raumbedieneinheit kann angepasst werden.

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1

### 3. Software-Installation

* Über den Module Store kann das Modul installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Luxtronik'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
IP-Adresse      |	IP-Adresse des Rechners auf dem der Libre Hardware Monitor läuft
Port            |   Port der Luxtronic/Wärmepumpe (8888 oder 8889). Der Port muss in der Firewall geöffnet sein
Intervall       |   Intervall für das Update der Werte
Überwachte ID's |  Hier die gewünschten ID's der Werte. Diese können über Checkboxen aktiviert werden.


### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl der ID's erstellt. Pro ID wird eine Variable erstellt.

#### Profile

Name   | Typ
------ | ------- 
WPLUX.Imp   |  Integer   
WPLUX.Typ   |  Integer   
WPLUX.Biv   |  Integer   
WPLUX.BZ    |  Integer 
WPLUX.Comf  |  Bool 
WPLUX.Men1  |  Integer
WPLUX.Men2  |  Integer
WPLUX.Men3  |  Integer
WPLUX.Akt   |  Bool
WPLUX.Pres  |  Float
WPLUX.Fan   |  Integer
WPLUX.lh    |  Integer
WPLUX.Wwhe  |  Integer
WPLUX.Kue	|  Integer
WPLUX.Tset	|  Float
WPLUX.Wset	|  Float
WPLUX.Std   |  Integer
WPLUX.Ver   |  Integer
WPLUX.kW    |  Float
WPLUX.Cop   |  Float

### 6. WebFront

Die Variablen zur Steuerung der Luxtronik können aus der Visualisierung heraus gesteuert werden.

### 7. PHP-Befehlsreferenz

`boolean WPLUX_BeispielFunktion(integer $InstanzID);`
Erklärung der Funktion.

Beispiel:
`WPLUX_Update(12345);`

### 8. Versionen

Version 4.6 (25.08.2026)
- Aktualisierungsdatum der Variablen ändern sich nur noch, wenn sich die Daten ändern.
- Variablenprofile werden nicht merhr vom Modul überschrieben.
- Interner Umbau zwecks Wartung der IDs.

Version 4.5 (24.08.2026)
- Variablenprofil für Heizstatus ID125 hinzugefügt.

Version 4.4 (20.08.2026)
- Problem behoben, dass unbekannte Variablen nicht erstellt werden konnten .

Version 4.3 (28.05.2026)
- Schwimmbad-Modus hinzugefügt.

Version 4.2 (16.05.2026)
- Die Instanz lässt sich nun im Konfigurationsformular deaktivieren, um bei ausgeschalteter WP Fehlermeldungen zu verhindern.

Version 4.1 (31.01.2026)
- Migration des Konfigurationsformulars von 3.x auf 4.x verbessert

Version 4.0 (03.01.2026)
- Umrüstung auf IPSModuleStrict und hochsetzen der Kompatibilität auf 8.1
- Komplett neues Konfigurationsformular mit Auswahl der IDs über Checkboxen.
- Die Variablenprofile werden nun richtig angelegt, welche fälschlicherweise keine Auswahlliste hatten.

Version 3.16 (15.6.2025)
- Einige Codeoptinierungen und Fehlerbehadlung bei Socket-Verbindung

Version 3.15 (12.1.2025)
- Raumbedieneinheit RBE als Steuervariable hinzugefügt
- Fehlermeldung COP-Berechnung bei Eingangsleistung 0 abgefangen
- ID 268 Leistungsaufnahme wird als Leistungsvariable erstellt (wenn die Lux diesen ausgibt)

Version 3.14 (22.12.2024)
- Anpassung Modulname
- Anpassung Readme mit geänderter URL

Version 3.13 (23.11.2024)
- Wert 201 (Druck EVI) hat jetzt den korrekten Wert in bar.

Version 3.12 (03.11.2024)
- Minustest zu allen Temperaturvariablen hinzugefügt, damit bei Minustemperaturen plausible Werte angezeigt werden.

Version 3.11 (27.10.2024)
- Problem behoben, dass die Steuervariable für Warmwasser-Soll in der Lux statt den 'Wunschwert' die 'Deckung WP' verstellt hat.

Version 3.10 (26.09.2024)
- Erhöhung der Java-ID's auf 275 sollte Fehlermeldungen gewisser Luxtronik vermeiden.

Version 3.9 (12.08.2024)
- Auswahlmöglichkeit eines externen Wärmemengenzählers zur Berechnung des JAZ hinzugefügt.

Version 3.8 (07.07.2024)
- Dokumentationslink hinzugefügt.
- Bild der Luxtronik unter 'Info zum Modul' hinzugefügt.

Version 3.7 (20.05.2024)
- Die Fehlermeldung beim auswählen von Wert 179 ist behoben.
- Für Wert 241 wir nun richtig zugeeordnet.
- Für Wert 91-94 werden nun die IP, Subnetz, Gateway und Broadcast-Adressen richtig dargestellt
- Wert 81-90 wird nun in ASCII Zeichen konvertiert, um die Luxtronic-Version auszugeben
- Spenden-Button im Konfigurationsformuler eingefügt.

Version 3.6 (23.04.2024)
- Einige Java-Werte ab Wert 232 wurden ergänzt und den entsprechenden Variablenprofile zugeteilt
- Die JAZ-Berechnung hat mit einigen LUX nicht funktioniert, da es die 'Wärmemenge gesamt' als Variablenwert nicht gibt. Neu wird dieser Wert intern vom Modul berechnet durch addieren von 'Wärmemenge Heizung' und 'Wärmemenge Warmwasser'. Dadurch kann es nötig sein, nach dem Update auf Version 3.6 im Konfigurationsformular den Button 'JAZ-Berechnung zurücksetzen' zu betätigen.

Version 3.5 (01.04.2024)
- Es wird nun ein separates Variablenprofil WLUX.Ver und WPLUX.Fan für die Verdichterdrehzahl und Ventilatordrehzahl angelegt. Dies um die Maximalwerte im Profil separat zu definieren. Ebenfalls wird das Profil WPLUX.kW angelegt, um auch hier nicht das allgemeine Profil zu verwenden und somit den Maximalwert definieren zu können. Hintergrund ist die Nutzung der tollen html-Kacheln ua. für die Wärmepumpe von da8ter (https://github.com/da8ter/TileVisu-Kachelsammlung)
- Der Bereich 'Zusätzlich Funktionen' im  Konfigurationsformular ist nun übersichtlicher und platzsparender gestaltet.

Version 3.4 (07.03.2024)
- Es kann nun die internen Timerfunktionen für Heizung und Warmwasser der LUX gesteuert werden. Eine Anpassung für die gesamte Woche, Mo-Fr/Sa+So und Wochentage analog dem LUX-Timer ist möglich. Um Variabeln zu sparen ist es möglich, nur die gewünschte Anzahl Zeitfenster einzublenden. Beim Deaktivieren der Variablen bleiben aber die gespeicherten Zeiten erhalten
- Wert 56, 58, 60-66 (Betriebsstunden) werden nun in Stunden dargestellt

Version 3.3 (25.02.2024)
- Berechnung des JAZ jetzt durch auswählen einer externen Variable für die Eingangsleistung in kWh möglich. Ebenfalls besteht die Möglichkeit, den JAZ-Faktor zu reseten, um zum Beispiel bei Jahresende oder bei Bedarf die Berechnung neu zu starten.

Version 3.2 (20.02.2024)
- Berechnung des COP jetzt durch auswählen einer externen Variable für die Eingangsleistung in kW möglich.
- Weitere Anpassen der Debug- und der Fehler-Ausgabe.
- Problem behoben, dass unter gewissen Umständen wurde eine falsche Laufzeit von -1h59m berechnet wurde.

Version 3.1 (17.02.2024)
- Anpassen der Debug- und der Fehler-Ausgabe.

Version 3.0 (15.02.2024)
- Modul von WPLUX Symcon in Luxtronik umbenannt um die Store-Kompatibilität zu erreichen. Dies erfordert leider eine Neuinstallation des Moduls und das Transferieren der Variablen-Werte durch den Anwender.
- Code massiv umgebaut um die Store-Kompatibilität zu ereichen
- Es kann eine Variable zur Anpassung der Warmwasser Solltemperatur eingeblendet werden. Sinnvoll für PVA Besitzer, welche überschüssige Energie in den Warmwasserspeicher verschieben möchten. Temperaturbereich 30-65 Grad.
- Umgestaltung des Konfigurationsformulars, die aktivierbaren Variablen zur Steuerung der Luxtronic werden nun in einem ExpansionPanel dargestellt.
- Variable 95 - 115 umbenannt da der lange Name zu Fehler führte (Variablen müssen manuell im Baum gelöscht werden wenn sie bereits vorhanden sind)
- Variable 20 umbenannt wegen ungültigem Sonderzeichen (Variablen müssen manuell im Baum gelöscht werden wenn sie bereits vorhanden sind)

Version 2.4 (11.02.2024)
- Erstellung der Variablenprofile von Create() in ApplyChanges() verschoben, damit die Profile bei jeder Änderung auf Vorhandensein geprüft und ggf. erstellt werden.
- Im Integer-Variablenprofil WPLUX.Fan wird die Einheit 'rpm' nun klein geschrieben um ein einheitlicheres Gesamtbild der Werte zu erreichen. Wenn die Kosmetik gewünscht wird, muss das Variablenprofil manuell gelöscht werden. Es wird bei einer Konfigurationsänderung neu erstellt.
- Es kann nun eine Variable zur Anpassung der Temperatur eingeblendet werden. Dieser Wert hebt die Rücklauftemperatur entsprechend an und ermöglicht eine Temperaturanpassung, ohne die Heizkurve zu verändern. Verstellbereich -5 bis +5 Grad.

Version 2.3 (11.02.2024)
- Alle Werte in Sekunden werden nun in Std und Min angezeigt (Wert 56, 58, 60-77, 120, 123, 158, 161)
- Das Integer-Variablenprofil WPLUX.Sec wird bei der Installation nicht mehr erstellt da die Zeitangaben nun als String ausgegeben werden

Version 2.2 (10.02.2024)
- Berechnung für Wert 67 (Waermepumpe_laeuft_seit) und Wert 73 (Verdichter_Standzeit) nun in Stunden und Minuten
- Variablenprofil für Wert 257 angepasst, zeigte kWh statt kW

Version 2.1 (10.02.2024)
- Berechnung für Wert 183 (Steuersignal_Umwaelzpumpe) korrigiert, wurde um Faktor 10 zu gering berechnet

Version 2.0 (10.02.2024)
- Es können nun Variablen für die Modus-Steuerung von Heizung, Warmwasser und Kühlung aktiviert werden.
- Minustest für Aussentemperatur hinzugefügt, so dass plausible Minuswerte ausgegeben werden
- Erhöhung der Java-ID's auf 267 sollte Fehlermeldungen gewisser Luxtronic vermeiden.

Version 1.2 (05.02.2024)
- Muster IP-Adresse wird nicht mehr standardmässig geladen bei Installation des Moduls. Dies führte bei der Installation zu Fehlermeldungen.

Version 1.1 (04.02.2024)
- Kleine Anpassungen an der Variabelzuweisung und Konvertierung
- Vergössertes Fenster Sortierung der ID's im Konfigurationsformular

Version 1.0 (04.02.2024)
- Initiale Version
