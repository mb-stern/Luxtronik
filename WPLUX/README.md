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

Version 5.0 (in Vorbereitung; Änderungen ab 4.8)
- Optionales Modbus/SHI mit allen 129 dokumentierten Lesepunkten und 41 Schreibfunktionen samt Rückleseprüfung; Java bleibt Standard.
- Vorhandene Idents bleiben erhalten. [Hinweise für Tester](SHI-TEST.md).
- Checkboxliste mit Schnittstellenübersicht: Java, Modbus und WebSocket; Variablennamen bleiben unverändert. Spalte „Aktuelle Quelle“ statt WebSocket-Status; Firmwarehinweis ab 3.81 und sichtbare Verbindungsfehler.
- ID-Liste ausschließlich für lesende Mess- und Statuswerte: bevorzugte Quelle je Wert wählbar, Java als Standard, automatischer Ersatz über zugeordnete Schnittstellen. Tatsächliche Quelle in Liste und Debug; Grafik und COP/JAZ verwenden dieselbe Auswahl. [Konfiguration](DATENQUELLEN.md).
- Bereinigte HTML-SDK-Vollansicht mit automatischen Idents, schlanker Konfiguration vor der ID-Liste ohne Fußzeile. Ab Symcon 9.1 maximierbar; ältere Versionen behalten HTML-Typ 1. Bestehende Instanzen werden beim Übernehmen der Konfiguration aktualisiert; „Maximieren-Button anzeigen“ in den visuellen Einstellungen aktivieren.
- Drei Raumtemperaturen mit Name/Icon-Auswahl je Raum, ohne zusätzlichen globalen Symbolschalter; Betriebsstatus, COP/JAZ, Fehlerlink sowie Heiz- und Eingangsleistung rechts im Infobereich mit einheitlicher Schrift (23 px, gleiche Schriftstärke, ohne horizontale Stauchung). Gleiche Innenabstände links und rechts; Fehlerlink rechtsbündig mit den Leistungswerten.
- Vereinfachte Hydraulik mit zwei Heizkreisplätzen (HK1 Standard, HK2 optional), einer sichtbaren gemeinsamen Heizwasserpumpe im Gerät und nachgeschaltetem Boiler-Umschaltventil. Keine Mischersymbole oder Mischereinstellungen; die bisherigen Mischkreis-Vorlauftemperaturen und gespeicherten Sensorzuordnungen bleiben erhalten. Optionaler Vieranschluss-Trennpuffer mit getrennten Lade- und Heizkreisleitungen; falsche äußere Rücklaufverbindung entfernt. Kurze gemeinsame Vor-/Rücklaufleitungen statt der Rückführung zu den Mischern; Leitungsquerungen einschließlich Animation als unverbunden markiert.
- Warmwasserspeicher höher angeordnet, einschließlich seiner Anschlüsse, Solarleitungen, Zirkulation und Wasserhahn. Längere, annähernd gleich lange rote/blaue Rohrstücke direkt am Kondensator; HUP im roten Steigstrang, unmittelbar danach das Boiler-Umschaltventil und gerade weiter zum Puffer. Kurze Leitungsführung zwischen Umschaltventil und Heizkreisabgängen; Solarleitungen hinter den übrigen Leitungen, mit oberen Anschlüssen am Heizpuffer. Ein einzelner Heizkreis ohne Puffer führt ab dem Boilerventil gerade nach oben, ohne zusätzliche Ventilschleifen. Pumpenansteuerung direkt links neben der HUP, Durchfluss oberhalb von RL und mit diesem linksbündig neben der blauen Leitung; Temperaturwerte näher an den Leitungen.
- Puffer und Heizkreisabgänge nach links zusammengerückt; ohne Solarthermie schmalere Zeichenfläche, besonders in der Standardansicht mit einem Heizkreis. Gerader roter Steigstrang ab dem Boiler-Umschaltventil und paralleler blauer Rücklauf außerhalb der Messwertbeschriftungen. Beide Solaranschlüsse am Puffer oberhalb des roten Heizkreisabgangs; am Warmwasserspeicher zwei getrennte waagerechte Solarleitungen mit Platz für den Wasserhahn dazwischen.
- Heizpuffer zeigt automatisch den vorhandenen externen Rücklauffühler (ID 13, `Ruecklauftemperatur_im_Trennspeicher`); keine zusätzlichen Sensoren oder Idents erforderlich.
- Heizkreistemperaturen ohne Puffer während Boilerladung auf dem letzten Heizwert halten (⏸), auch beim erneuten Öffnen der Visu. Erst nach Ladeende und Abkühlung des WP-Vorlaufs auf den vorherigen Wert oder darunter wieder live anzeigen. WP-Temperaturen und echte Messvariablen bleiben unverändert. Durchfluss folgt dem Umschaltweg; die gemeinsame HUP bleibt auch bei Boilerladung animiert.
- Grafik mit internem Messwertspeicher ohne zusätzliche Variablen; Durchfluss aus ID 254 am WP-Ausgang, Verdichter nur mit aktueller Drehzahl ohne Ist-/Soll-Zusätze; am Expansionsventil nur der Prozentwert der aktiven Heiz-/Kühlöffnung. Wasserflussanimation gemäß Durchflusswert.
- Timer- und Variablenmeldungen bei nicht mehr verfügbarer Instanzschnittstelle sofort mit einer Meldung abbrechen; verhindert Warnungskaskaden während Modulupdates. Vorherige PHP-Fehlerbehandlung nach jedem Aufruf wiederherstellen.
- Lauf-/Standzeit (ID 67/73) und Abtau-Restzeit (ID 141) zentriert unter dem ausgerichteten Gerätebild; Ansaugtemperatur (ID 176) vor und Heißgas (ID 14) nach dem Verdichter auf Höhe der Verdichterbeschriftung. Lüfterdrehzahl über dem Lüfterrad.
- Einheitliche Mindestschrift; in kleinen Kacheln bis 600 px Breite moderat auf 20 statt 18 SVG-Einheiten erhöht, in der gesamten Grafik. Dort entfallen die Bauteilnamen im Kältekreis. Temperaturen einzeilig mit °C; Heizkreisname und VL gleich groß untereinander; Durchfluss über, VL direkt unter dem roten Anschluss, Pumpenwert ohne Zusatztext; Ventilbeschriftung mit symmetrischen Abständen.
- Warmwasserspeicher mittig zum Gerät mit symmetrischen Anschlüssen und kurzem Zirkulationsabgang ohne breitere Kachel; Temperatur/Leistung gleich groß und zentriert, Sollwert nur oben. Summierte Heizstableistung aus W/kW-Messwerten mit Blitz und Bedienbutton.
- Ein-/Aus-Assoziationen für Heizstäbe wählbar; Puffer/Solar/Zirkulation optional, Solarziel mit Boiler-Fallback und HUP-Ansteuerung in Prozent.
- Timer / Zusatzfunktionen kennzeichnet an allen sieben Steuerschaltern den tatsächlichen Schreibweg (Java oder Modbus/SHI), mit sofortiger Aktualisierung beim Umschalten. Betriebsarten bleiben Java; Temperaturkorrektur, Warmwasser-Soll und Raumtemperatur-Soll verwenden bei aktiviertem Modbus-Schreibweg SHI.
- Obere Bedienleiste gemäß aktivierten Steuervariablen, einschließlich Schwimmbad und Raumtemperatur-Soll; dynamische Anordnung und Breite. Raum-Sollwert mit eigenem Temperaturprofil.
- LUX-Bedienfeld zweizeilig; Heizstab-Menü mit einer kompakten Zeile je Heizstab (Name, Ein/Aus-Aktion, gemessene Leistung). Pumpendrehung nach Prozent-Ansteuerung und Wasserflussanimation mit variabler Geschwindigkeit nach l/h bzw. vorhandener Pumpen-Ansteuerung.
- Obere Bedienmenüs bleiben nach Schaltbefehlen und Sollwertänderungen geöffnet, aktualisieren bestätigte Zustände und schließen beim Klick daneben oder mit Escape. Menüs und Eingabefelder übernehmen das helle oder dunkle Symcon-Design.
- Optionaler Wettercode aus einer externen Variable (OpenWeather, z. B. 10d/01n) ersetzt die feste Sonne durch ein lokales Wettersymbol mit Tag-/Nacht-Darstellung.
- EEV-Lesediagnose mit `WPLUX_GetEEVDiagnosticData($id, 3003)` für Parameter bzw. unverändert mit 3004 für Berechnungswerte; bekannte Zugangsdatenfelder im Parameterexport ausgeblendet.
- Vollständige WebSocket-Diagnose in der Instanzkonfiguration mit JSON-Download; in der Visu bleibt nur das LUX-Gerätedisplay. Bestandsabfrage beim ersten Kontakt und danach täglich, normale Abfragen nur für benötigte fehlende Werte.
- LUX-Fenster einschließlich Hintergrund, Gerätefläche und Buttons folgt dem Symcon-Design. Gespeichertes Webinterface-Passwort auch für das LCD verwenden, keine erneute Eingabe in der Visu. Einzeilige Symbolleiste für Originalinterface, erneutes Verbinden und Schließen; keine sichtbare Überschrift oder Statuszeile bei vorhandenem Displaybild. Hinweise erscheinen nur ohne Bild. Die Displaygröße berücksichtigt die verfügbare Höhe, damit die Bedienpfeile auch mobil sichtbar bleiben.
- WebSocket als automatische Ergänzung und Reserve; Java bleibt Standard. Der Modbus-Schreibweg ändert die gewählte Lesequelle nicht. Nur benötigte fehlende Werte und deren Informationsseiten werden abgefragt; gültige Nullen bleiben erhalten. Automatische lesende Ergänzungen ohne separate Schalter. Zusätzliche lesbare Modbus-Status-/Konfigurationspunkte erweitert einblendbar. Alle 41 Modbus-Schreibfunktionen in einer separaten Steuerliste mit Variablenauswahl; alte Auswahlen bleiben erhalten. Unterstützte Schreibbefehle über Modbus, übrige Betriebsarten und Zeitprogramme weiterhin über Java; Auswahl und Aktivierung senden keine Sollwerte.
- EEV Heizen/Kühlen aus bestätigten Webinterface-Feldern intern am Expansionsventil, ohne angelegte Messvariablen; optionale Variablen über die ID-Liste. Vollständiger WebSocket-Lesetest zählt alle angebotenen Informationsfelder. [Details](WEBSOCKET.md).
- WebSocket-Zuordnung für „Freig. ZWE“ korrigiert: Die Wartezeit darf den bestehenden Wärmepumpentyp (Java-ID 78) nicht überschreiben.
- 14 zusätzliche WebSocket-Messwerte mit IDs 400006–400019 unten in der Checkboxliste; Objektposition entspricht der ID. Bestehende Idents und Objekt-IDs bleiben erhalten. Automatischer Ersatz bei Java-Ausfall und Rückkehr zu Java nach Erholung; sechs Betriebsstundenzähler mit bestätigten Regler-Beschriftungen zugeordnet.
- Fehlerhistorie mit vollständigen WebSocket-Meldungen, Datum und Code über den Link „Fehler: Anzahl“ in der COP-/JAZ-Zeile sowie Sammelvariable 400020; bestehende Fehlercode-/Zeitstempel-IDs bleiben erhalten und folgen der wählbaren Lesequelle.
- API-Abgleich des Solepumpen-/Ventilator-Ausgangs auf Java-ID 43; alte Zusatz-Idents bleiben für gespeicherte Auswahlen erhalten.

Version 4.7 (19.10.2026)
- Socket-Abfragen und Prüfung der Steuerwerte verbessert.
- Heizungs-Timer, zusätzliche IDs und Deaktivierung korrigiert.
- Temperaturwerte 203–207 und SDK-Kompatibilität korrigiert.

Version 4.6 (25.08.2026)
- Aktualisierungsdatum der Variablen ändern sich nur noch, wenn sich die Daten ändern.
- Variablenprofile werden nicht mehr vom Modul überschrieben.
- Interner Umbau zwecks zentraler Wartung der IDs.

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
