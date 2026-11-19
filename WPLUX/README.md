# Luxtronik für IP-Symcon

Das Modul liest Wärmepumpen mit Luxtronik-Regler und stellt Messwerte, Steuerung,
Zeitprogramme sowie eine integrierte Wärmepumpengrafik in IP-Symcon bereit.
Luxtronik wird unter anderem von Alpha Innotec, Buderus, CTA, Elco, Nibe, Roth,
Novelan und Wolf eingesetzt. Der verfügbare Funktionsumfang hängt von Gerät,
Regler und Firmware ab.

Java bleibt die Standardanbindung. Ab Version 5.0 können WebSocket und
Modbus/Smart Home Interface (SHI) bestätigte Messwerte ergänzen oder als bevorzugte
Lesequelle dienen. SHI bietet zusätzlich eigene Vorgaben für externe Regelungen,
beispielsweise eine zeitweise Anhebung bei PV-Überschuss.

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Einrichtung und Datenquellen](#4-einrichtung-und-datenquellen)
5. [Variablen und Darstellung](#5-variablen-und-darstellung)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehle](#7-php-befehle)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

- Ausgewählte Mess- und Statuswerte als Symcon-Variablen mit nativer Variablendarstellung.
- Bestehende Java-Steuerung für Betriebsarten, Heizkorrektur, Warmwasser und Raum-Sollwert sowie die Zeitprogramme des Reglers.
- Gemeinsame Istwertliste mit Quellenwahl je Messwert und Sammelauswahl für passende Werte.
- WebSocket-Ergänzungen aus den Informationsseiten des Webinterfaces, einschließlich Energiemonitor und Fehlerhistorie.
- Direkte Modbus-TCP-Anbindung mit den 129 dokumentierten SHI-Punkten, darunter 41 Holding-Funktionen für externe Vorgaben.
- COP aus Wärme- und elektrischer Leistung; externe Leistungsmessung hat Vorrang, andernfalls wird die interne Leistungsaufnahme verwendet. JAZ mit externen Energiezählern und Rücksetzfunktion.
- HTML-SDK-Grafik für Luft/Wasser- und Sole/Wasser-Wärmepumpen mit optionalem zweiten Heizkreis, Rücklaufpuffer, Warmwasserspeicher, Solarthermie und Zirkulation.

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1.
- Luxtronik mit Netzwerkanschluss und vom Symcon-Server erreichbarer IP-Adresse.
- Java-Port 8889, bei älteren Geräten gegebenenfalls 8888.
- Für WebSocket: unterstützte Firmware, Port 8214 und Webinterface-Passwort (Standard 999999).
- Für Modbus/SHI: unterstützter Regler mit aktiviertem SHI; üblicherweise Port 502, Geräteadresse 1.

Für die Firmware-Versionsreihe V3 gilt: WebSocket ab 3.81, Modbus/SHI laut
Hersteller ab 3.90.1. Andere Versionsreihen werden gesondert über die
Schnittstellen geprüft. Die Konfiguration zeigt erkannte Firmware und
Schnittstellenstatus; sicher nicht unterstützte Bereiche werden ausgeblendet.
Eine fehlende Verbindung allein gilt nicht als Nachweis fehlender Unterstützung.

### 3. Installation

Das Modul über den Module Store installieren und unter Instanz hinzufügen
eine Luxtronik-Instanz anlegen. Die Version 5.0 befindet sich auf dem
Entwicklungszweig `development`.

Beim Upgrade bleiben bestehende Variablen, Idents, Objekt-IDs, Archive und
Quellenauswahlen erhalten. Die bisherigen Java-Steuerungen bleiben eigenständig.
Eine noch nicht konfigurierte Geräte-IP erzeugt keinen Verbindungsfehler im
Systemlog; die zyklische Abfrage startet nach der Einrichtung.

### 4. Einrichtung und Datenquellen

Unter Modul aktiv → Verbindung und Lesen stehen die Verbindungseinstellungen
direkt im Formular:

| Einstellung | Bedeutung |
| --- | --- |
| IP-Adresse | Netzwerkadresse der Wärmepumpe |
| Java-Port | 8889 bzw. 8888 |
| Aktualisierungsintervall | Gemeinsamer Abfragetakt für die benötigten Datenquellen |
| Webinterface-Passwort | Zugang für WebSocket und Reglerdisplay |
| Modbus-Port / Geräteadresse | Direkte SHI-Verbindung zum Regler, normalerweise 502 / 1 |
| Modbus-Antwortwartezeit | Wartezeit auf eine Antwort in Millisekunden; kein zusätzliches Abfrageintervall |

Ein Symcon-Modbus-Gateway oder Client Socket wird nicht benötigt.

In Istwerte – gewünschte Variablen auswählen die benötigten Variablen anhaken
und Übernehmen. Über das Zahnrad lässt sich je Messwert eine bevorzugte,
zugeordnete Quelle wählen. Quellenübersicht und Sammelauswahl anzeigen blendet
die gemeinsame Quellenwahl ein. Für alle passenden Messwerte übernehmen
ändert nur Werte mit einer passenden Zuordnung; die übrigen behalten ihre Quelle.
Die Sammelauswahl betrifft ausschließlich das Lesen der Istwerte.

Bestätigte Gegenstücke verwenden dieselbe Variable. Bei Ausfall der bevorzugten
Quelle können zugeordnete Ersatzquellen einspringen; nach Erholung wird wieder
die bevorzugte Quelle verwendet. Gespeicherte Einstellungen bleiben dabei
bestehen. Zusätzliche Werte ohne bestätigte Entsprechung stehen separat in der
Liste. Nicht jeder Java-Wert ist über WebSocket oder Modbus verfügbar.

Die Liste unterscheidet Lesequelle und Datenstatus. „Nicht abgefragt“
bedeutet, dass der Wert bisher nicht benötigt wurde. Java liefert einen ganzen
Datenrahmen; Modbus und WebSocket lesen gezielt benötigte Punkte.

Die ID ist die Auswahlkennung des Moduls, keine Symcon-Objekt-ID:

| Kennung | Herkunft |
| --- | --- |
| Bisherige Java-ID | Bleibt auch beim Quellenwechsel erhalten |
| 1xxxxx | Zusätzliche Modbus-Messwerte |
| 2xxxxx | Modbus-Freigaben |
| 3xxxxx | SHI-Steuerung |
| 4xxxxx | WebSocket-Zusatzwerte |

Beispielsweise bezeichnet 310002 die SHI-Steuervariable für das echte
Holding-Register 10002. Registerart und Adresse sind im
[Registerkatalog](SHI-REGISTER.md) aufgeführt.

#### SHI für externe Regelungen

Unter Modbus / SHI – externe Regelung die gewünschten Vorgaben auswählen und
übernehmen. Dadurch werden eigene Steuervariablen angelegt, auch ohne bestehende
Verbindung. Bedienbar sind sie mit aktueller Rückmeldung und Freigabe am Regler.

Neu angelegte Steuervariablen heißen einheitlich „SHI: Modus Heizen“,
„SHI: Sollwert Heizen“, „SHI: Offset Heizen“ usw. Namen bestehender Variablen
werden nicht automatisch geändert, auch wenn sie wie frühere Standardnamen
aussehen. Bei der Schrittweitenkorrektur wird der aktuelle Name beibehalten.
Im SHI-Konfigurationsbereich stehen die Funktionsnamen ohne zusätzlichen SHI-Präfix.

SHI-Temperaturen, Offsets und Leistungsgrenzen sind in 0,1er-Schritten
bedienbar, beispielsweise +0,5 K beim Heizungs-Offset. Beim Wechsel zur neuen
Darstellung werden Schieberegler mit ganzen Schritten auf derselben Variable
korrigiert. Betriebsmodi, Levels und Schalter behalten ihre festgelegten Werte.

Am Beispiel 310000 bis 310003:

| Variable | Wirkung |
| --- | --- |
| Modus Heizen (310000) | 0: kein Einfluss; 1: Sollwert; 2: Offset; 3: Level |
| Sollwert Heizen (310001) | Direkte Rücklauf-Solltemperatur bei Modus 1 |
| Offset Heizen (310002) | Korrektur des berechneten Rücklauf-Sollwerts bei Modus 2 |
| Level Heizen (310003) | Kein Einfluss, überhöht mit/ohne Zeitprogramm oder reduziert bei Modus 3 |

Wird Offset Heizen mit +1,0 K geschaltet, werden aus beispielsweise 28 °C
Rücklauf-Solltemperatur 29 °C. Den benötigten Modus 2 setzt das Modul automatisch;
die Modusvariable muss dafür nicht zusätzlich angelegt werden. Ein direkter
Sollwert schaltet auf Modus 1 um. Sollwert und Offset werden nicht addiert.

Die bestehende Java-Heizkorrektur und der SHI-Offset sind unterschiedliche
Reglereingriffe und können sich auf dem Regler addieren. SHI ersetzt daher
keine vorhandene Java-Steuervariable. Auswählen, Speichern und Lesen senden
keine Vorgaben. Das Modul schreibt erst beim Schalten und bestätigt die Werte
durch Rücklesen. Alte Vorgaben werden nach einem Neustart nicht erneut gesendet.

Die geschaltete SHI-Variable zeigt den Wunschwert nach der Prüfung von Freigabe
und Wertebereich sofort. Die tatsächliche Übernahme wird getrennt durch
Rücklesen bestätigt. Gerade die Warmwasserregister 10005–10008 können verzögert
reagieren; das Modul wartet dafür pro Register bis zu 15 Sekunden und
wiederholt den Schreibbefehl nicht. Scheitert die Bestätigung, wird die Anzeige
auf den vor dem Senden gelesenen Wert zurückgesetzt. Die Fehlermeldung nennt
gesendeten und zurückgelesenen Wert.

Die SHI-Register enthalten externe Vorgaben, etwa Holding-Register 10006 für
Warmwasser. Den aktuell wirksamen Warmwasser-Sollwert liefert dagegen
Input-Register 10121, entsprechend Java-Istwert 18. Ein gespeicherter
SHI-Sollwert allein bedeutet daher noch keine aktive Beeinflussung.

Die SHI-Anleitung nennt einen Rückfall nach 15 Minuten ohne Modbus-Anfragen.
Dieser Rückfall wird vom Regler gesteuert; lesende Modbus-Anfragen können die
Kommunikation aufrechterhalten. Zum gezielten Beenden Modus Heizen auf 0
setzen. Weitere Hinweise stehen in der Konfiguration und im
[Leitfaden für SHI](SHI-TEST.md).

Der Zeitraum beginnt somit nicht pauschal beim letzten Schreibbefehl. Ausgewählte
SHI-Steuervariablen und zuletzt geschriebene Modusregister werden lesend abgefragt;
auch diese Kommunikation kann den Rückfall verhindern. Es gibt keine separate
Meldung „Sollwert gelöscht“. Das Modul übernimmt geänderte Registerwerte beim
nächsten erfolgreichen Lesen. Modus 0 bedeutet keinen Einfluss, auch wenn ein
numerischer Sollwert noch gespeichert ist. Ein fehlender Messwert oder eine
unterbrochene Verbindung wird nicht als bestätigte Rücknahme ausgegeben.

[Ausführliche Beschreibung der Datenquellen](DATENQUELLEN.md) ·
[Java und SHI im Vergleich](SHI-JAVA-VERGLEICH.md) ·
[WebSocket und Diagnose](WEBSOCKET.md)

### 5. Variablen und Darstellung

Die Auswahl bestimmt, welche Messwertvariablen im Objektbaum angelegt werden.
Grafik und Berechnungen können benötigte Messwerte auch intern verwenden, ohne
zusätzliche Messwertvariablen zu erzeugen. Bestehende eigene Namen und Profile
werden beim Quellenwechsel beibehalten. Echte Nullwerte und „Aus“ sind gültige Werte.

Unbekannte Java-Indizes können je nach Firmware vorkommen. Die
[Java-Webinterface-Sammlung](https://loxwiki.atlassian.net/wiki/spaces/LOX/pages/1533935933/Java+Webinterface)
ist eine Community-Referenz; eine vollständige Zuordnung für jede Firmware ist
daraus nicht ableitbar. Parameter aus Kommando 3003 und Istwerte aus 3004 haben
getrennte Indexräume.

#### Rückmeldungen der Steuerung

Die oberen Kacheln Warmwasser, Heizung und Kühlen zeigen die
eingestellten Betriebsarten, beispielsweise Automatik oder Aus. Sie zeigen
keine momentan aktive Heiz- oder Warmwasseranforderung.

| Anzeige | Rückmeldung im Modul | Java-Schreibparameter |
| --- | --- | --- |
| Warmwasser: Betriebsart | Befehl 3003, Parameter 4 | 4 |
| Heizung: Betriebsart | Befehl 3003, Parameter 3 | 3 |
| Kühlen: Betriebsart | Befehl 3003, Parameter 108 | 108 |
| WW Soll | Befehl 3004, ID 18: aktuell wirksamer Sollwert | 105 |
| Heizkorrektur / Temperaturkorrektur | Befehl 3003, Parameter 1 | 1 |

Diese Rückmeldungen werden im gemeinsamen Aktualisierungszyklus intern gelesen,
auch ohne angelegte Steuer- oder Istwertvariablen. Angelegte Steuervariablen und
die Grafik verwenden diese Rückmeldungen. Externe Änderungen werden beim nächsten
erfolgreichen Lesen übernommen. Die Quellenwahl für Istwerte stellt die bisherigen
Java-Schreibbefehle nicht um. Für WW Soll gilt auch eine bestätigte, zugeordnete
Ersatzquelle, etwa Modbus-Input-Register 10121.

Beim Warmwasser bleiben Schreiben und Anzeige bewusst getrennt: Der normale
Schreibbefehl setzt Java-Parameter 105; die Anzeige folgt dem wirksamen Sollwert
aus ID 18. Dieser kann durch eine externe Vorgabe abweichen. Heizkorrektur aus
Java-Parameter 1 und SHI-Offset Heizen aus Holding-Register 10002 sind
eigenständige Vorgaben.

Die Kachel Heizkorrektur verwendet die interne Rückmeldung von Parameter 1
direkt, auch wenn eine zugehörige Variable erst später angelegt wird. Der
Schreibweg bleibt die normale Java-Heizkorrektur; SHI-Offset und Java-Korrektur
werden nicht automatisch ineinander übertragen.

#### Darstellung in Symcon

Das Modul verwendet Wertanzeigen für Mess- und Statuswerte, Aufzählungen für
Betriebsarten, Schieberegler für Sollwerte und Uhrzeiten für Zeitprogramme.
Es erstellt und ändert keine klassischen Variablenprofile mehr.
Beim Übernehmen der Konfiguration wechseln bestehende Standarddarstellungen auf
denselben Variablen. Objekt-ID, Name, Datentyp, Wert und Archiv bleiben erhalten.
Benutzerdefinierte Darstellungen und Profilzuordnungen behalten Vorrang.
Eine zusätzliche Zuweisung des früheren Standardprofils wird ebenfalls auf
die neue Darstellung umgestellt. Eigene Texte und Formatierungen aus diesem
Profil werden übernommen.

Einheit, Schrittweite und Nachkommastellen stehen in der Darstellung der
Variablen. Änderungen daran kann das Modul direkt übernehmen. Vorhandene
WPLUX-Profile bleiben unverändert für bestehende Verwendungen verfügbar.
Eigene Skripte, die Darstellungsdaten über `VariableProfile` auslesen, verwenden
für die neue Darstellung `IPS_GetVariablePresentation()`.

### 6. Visualisierung

Die Instanz in der Symcon-Kachelvisualisierung mit ihrer individuellen
Darstellung verwenden. Unter HTML-SDK / Visualisierung den Wärmepumpentyp
Luft/Wasser oder Sole/Wasser und die vorhandenen Komponenten auswählen.
Die Grafik nutzt die internen Messwerte automatisch. Ab Symcon 9.1 ist eine
maximierbare Darstellung möglich.

Die Solevariante zeigt den Pumpenausgang sowie Wärmequellen-Eintritt (Java-ID 19)
und -Austritt (20) am geschlossenen Gerätegehäuse im gleichen Stil. Lüfter,
Lüfterdrehzahl und Abtau-Restzeit entfallen. Verdichter-Ansaugtemperatur und
Sole-Eintritt bleiben getrennte Messungen. Fehlende Werte erscheinen als „—“.

Heizkreis 1 (Standard) verwendet den normalen WP-Vorlauf. Bei zwei
Heizkreisen werden die Mischkreis-Vorlauftemperaturen 1 und 2 verwendet, ohne
Mischersymbole zu zeichnen. Der optionale Puffer ist ein Reihenpuffer im Rücklauf.

Regler / Display öffnet das bedienbare Reglerdisplay über WebSocket. Alte
XML- und neue JSON-Antworten werden unterstützt. Das Passwort kommt aus der
Instanzkonfiguration; das Original-Webinterface lässt sich ebenfalls öffnen.

Die Grafik übernimmt die im Modul berechneten COP-/JAZ-Variablen und nutzt die
volle Kachelfläche. Ab Symcon 9.1 kann die Titelzeile in den visuellen Einstellungen
ausgeblendet werden. Ältere Versionen verwenden weiterhin die bisherige HTML-Darstellung.
Die Soll-Innentemperatur wird mit einer Nachkommastelle und °C angezeigt.

[Einrichtung und Details zur Grafik](VISUALISIERUNG.md)

### 7. PHP-Befehle

Aktualisierung der Instanz:

```php
WPLUX_Update(12345);
```

Ausgewählte SHI-Variable schalten:

```php
$offsetID = IPS_GetObjectIDByIdent('SHI_Offset_Heizen', 12345);
RequestAction($offsetID, 1.0);
```

`SetValue()` ändert nur den lokalen Variablenwert und sendet keinen Reglerbefehl.
Für zusätzliche SHI-Skriptnutzung stehen `WPLUX_GetSHIData($InstanzID)` und
`WPLUX_SetSHIValue($InstanzID, $Ident, $Wert)` zur Verfügung; Freigaben und
Wertebereiche werden auch dabei geprüft. Die optionale Freigabe direkter
SHI-API-Zugriffe ohne Variablenauswahl steht unter Diagnose.

### 8. Versionen

Version 5.0 (in Vorbereitung)

Neue Symcon-Kachel mit Wärmepumpengrafik

Die interaktive Anlagengrafik zeigt die Wärmepumpe mit ihren aktuellen Messwerten direkt in der Symcon-Visualisierung. Betriebsarten und Sollwerte lassen sich in der Kachel bedienen.

- Grafik für Luft/Wasser- und Sole/Wasser-Wärmepumpen. Bei Sole zeigt sie Quelleneintritt, Quellenaustritt und Pumpenzustand. Komponenten wie ein zweiter Heizkreis, Rücklaufpuffer, Solarthermie und Zirkulation lassen sich einblenden.
- Betriebsarten, Heizkorrektur und der wirksame Warmwasser-Sollwert kommen direkt aus dem Regler. Das integrierte Reglerdisplay erkennt XML und JSON. Der Link zum Original-Webinterface passt sich automatisch an die Firmware an.
- Ab Symcon 9.1 lässt sich die Kachel maximieren und der Titel ausblenden.

Weitere Neuerungen

- Umstellung von Variablenprofilen auf die neue Symcon-Variablendarstellung. Einheiten, Nachkommastellen und Schrittweiten stehen direkt an der Variable. Beim ersten „Übernehmen“ wird die neue Standarddarstellung auf die bestehenden Variablen angewendet.
- WebSocket und direktes Modbus TCP/SHI ergänzen Java um zusätzliche Istwerte. In der gemeinsamen Istwertliste lässt sich die Lesequelle einzeln oder gesammelt wählen. Die Liste zeigt die tatsächlich verwendete Quelle und den Datenstatus.
- Das vereinfachte Formular bündelt Verbindung und Lesen direkt unter „Modul aktiv“. Die Firmware- und Schnittstellenerkennung blendet verfügbare Optionen ein. Alle Lesequellen nutzen denselben Aktualisierungstakt.
- WebSocket liest über XML und JSON auch Werte aus dem Energiemonitor, EEV-Werte und die Fehlerhistorie. Diagnoseberichte für WebSocket und Modbus lassen sich herunterladen.
- SHI bietet eigene Steuervariablen für die externe Regelung. Beim Senden setzt das Modul den benötigten Modus, prüft Freigaben und Grenzen und liest den Wert zur Bestätigung zurück. Temperaturen, Offsets und Leistungsgrenzen lassen sich in 0,1er-Schritten einstellen.
- Bei fehlender externer Leistungsquelle berechnet das Modul den COP automatisch mit der internen elektrischen Leistungsaufnahme (Java-ID 268).

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
