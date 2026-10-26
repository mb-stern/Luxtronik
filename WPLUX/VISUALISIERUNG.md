# Integrierte Wärmepumpengrafik

Ab Version 5.0 stellt die Luxtronik-Instanz ihre Wärmepumpengrafik über das
Symcon HTML-SDK bereit. Sie verwendet die vorhandenen Instanzvariablen direkt
anhand ihrer Idents. Eine zweite Modulinstanz, eine Quellinstanzauswahl oder ein
Import von Variablenzuordnungen sind nicht erforderlich. Es gibt ausschließlich
die Vollansicht.

## Einrichtung

Die Luxtronik-Instanz in der Kachelvisualisierung mit ihrer individuellen
Darstellung verwenden. Die internen Messpunkte der Grafik werden bei der normalen
Aktualisierung unabhängig von der ID-Liste intern zwischengespeichert. Dafür
werden keine Symcon-Messwertvariablen angelegt. Java nutzt
weiterhin eine gemeinsame 3004-Abfrage. SHI liest zusätzlich nur bestätigte
Input-Register. Nur die ID-Liste bestimmt,
welche Messwertvariablen im Objektbaum angelegt werden. Die gespeicherte Auswahl
wird nicht verändert. Bedienvariablen
werden weiterhin über die bisherigen Optionen aktiviert.

Die Bedienmenüs der oberen Buttons bleiben nach einer Auswahl oder dem
Übernehmen eines Sollwerts geöffnet. Bestätigte Schaltzustände werden darin
aktualisiert; laufende Eingaben bleiben erhalten. Ein Klick daneben oder Escape
schließt das Menü. Hintergrund, Schrift und Eingabefelder folgen dem Symcon-Design.

Ab Symcon 9.1 kann die Kachel maximiert werden. Dafür in den visuellen
Einstellungen **„Maximieren-Button anzeigen“** aktivieren. Bei bestehenden
Instanzen die Modulkonfiguration einmal übernehmen, damit der Darstellungstyp
aktualisiert wird. Ältere Symcon-Versionen verwenden weiterhin den HTML-Typ 1.

Die Bereiche **Modbus TCP / SHI** und **HTML-SDK / Visualisierung** stehen vor der
ID-Auswahlliste. Die Visualisierungseinstellungen bleiben schlank:

- Komponenten-Checkboxen für Puffer, Warmwasserspeicher und Solarthermie.
- Optionale Warmwasser-Zirkulation mit Pumpe, Leitung und Hahn; ihr Zustand
  stammt intern von `Ausgang_Zirkulationspumpe` (ID 46).
- Zwei Heizkreisplätze: HK1 als Standard, HK2 optional, jeweils mit eigener Bezeichnung.
- Eine gemeinsame HUP mit Prozentwert im Gerätebereich und Boiler-Umschaltventil.
  Mischersymbole und Mischereinstellungen entfallen; die bisherigen
  Mischkreis-Vorlauftemperaturen bleiben an den Heizkreisabgängen erhalten.
- Bis zu drei optionale Raumtemperaturen mit frei wählbarer Bezeichnung
  (leer = Variablenname). Ein Einzelwert wird größer dargestellt. Für Icons
  wird das Variablensymbol verwendet, ersatzweise ein Haus. Je Raum sind Name, Icon
  oder beides wählbar; eine zusätzliche Überschrift „Innentemperatur“ entfällt.
- Unter **Heizstäbe** bis zu drei Ist-Leistungsvariablen (W oder kW laut Profil;
  ohne Einheit W) und jeweils eine optionale Schaltvariable mit Aktion und
  Assoziationen. Legacy-Profile und moderne Aufzählungen werden unterstützt.
- Solarwärme wahlweise an Warmwasserspeicher oder Puffer (Heizunterstützung),
  direkt neben dem Solarthermie-Schalter. Ohne aktivierten Puffer wird Solarthermie
  automatisch am Warmwasserspeicher dargestellt, der dafür bei Bedarf eingeblendet wird.
- Ansteuerung der Heizwasserpumpe über ID 183 in Prozent. Eine separate
  U/min-Drehzahl ist nicht bestätigt und wird nicht angezeigt.
- Eingangsleistung aus der bereits konfigurierten COP-Quelle `kwin` (kW), ohne
  weiteren Picker. Ist diese nicht konfiguriert, wird der übertragene Wert
  `Leistungsaufnahme` (ID 268, Watt) verwendet. Das allein bestätigt keine echte
  Strommessung. Die verwendete Quelle steht im Tooltip des Leistungswerts.


Es gibt keine separaten Auswahlfelder für einzelne Speicherfühler, Pumpen oder
interne Heizkreiswerte. Ein umfassendes Zuordnungsformular, Zuordnungsdiagnosen,
Tabellen, ein Importbutton und ein manueller Modus entfallen.

Die Warmwasserbereitung bleibt ein eigener Speicher-/Ladekreis und belegt keinen
der zwei Heizkreisplätze. Vom Luxtronik-Regler gelieferte Mischkreiswerte
sind interne Werte, auch wenn Mischer und Pumpen außerhalb des Wärmepumpengehäuses
montiert sind. Fremdvariablen stammen beispielsweise von separaten Symcon-Zählern.

Je Heizkreis lässt sich eine **Bezeichnung** vergeben. HK1 liest fest
`Mischkreis_1_Vorlauftemperatur` (ID 21), HK2 fest `Mischkreis_2_Vorlauftemperatur`
(ID 24). Alte Mischer- oder Fremdsensorzuordnungen überschreiben diese Quellen
nicht. Die Karten zeigen drei Zeilen: Name, **ab Mischer 1/2**, **VL: … °C**.
Fehlt der jeweilige Messwert, erscheint **VL: —**. Gehaltene Werte aus einer
früheren abweichenden Zuordnung werden beim Umstieg verworfen.
Der dritte Regler-Mischkreis bleibt als Datenquelle und in der ID-Liste erhalten.

Das Schema enthält eine gemeinsame HUP im roten Steigstrang. In Flussrichtung
folgt darüber unmittelbar das Boiler-Umschaltventil. Die kurzen Anschlussstücke am
Kondensator sind nur leicht verlängert; der blaue Rücklauf behält den unteren
Knick. Pumpe und Ventil stehen nahe am Kondensator. Blau liegt parallel
zum roten Steigstrang. Der Warmwasserspeicher steht höher und ist nach oben verlängert. Sein roter
Abzweig liegt höher, untere Anschlüsse und Anbauteile behalten ihre Höhe. Die
blaue Steigleitung steht weiter rechts, ohne den Puffer zu verschieben. VL steht
am roten Strang vor dem Ventil, der Prozentwert neben der Pumpe und RL direkt
neben dem blauen Kondensatoraustritt. Dahinter
zweigt die Boilerladung ab; der Heizungszweig führt direkt zum optionalen Puffer
und von dort zu den Heizkreisen. Der Puffer ist als parallel eingebundener
Vieranschluss-Trennspeicher gezeichnet: WP-Vorlauf/Rücklauf links,
Heizkreis-Vorlauf/Rücklauf rechts. Zwischen den beiden Rückläufen gibt es keine
zusätzliche äußere Verbindung. Kurze gemeinsame Leitungen verteilen das Wasser
auf die Heizkreisabgänge; kleine Unterbrechungen kennzeichnen Kreuzungen ohne
Verbindung. Ein Reihenpuffer entspricht diesem Schema nicht. Der Speicher und die Heizkreisabgänge sind nach links zusammengerückt; ohne
Solarthermie wird die Zeichenfläche entsprechend schmaler. Der rote Steigstrang läuft gerade ab dem Umschaltventil nach oben, der
blaue Rücklauf parallel daneben außerhalb der WP-Temperaturbeschriftungen. Beide
Solaranschlüsse liegen oberhalb des roten Heizkreisabgangs. Bei Solarwärme an den
Warmwasserspeicher verlaufen Vor- und Rücklauf getrennt waagerecht auf Höhe der
Solaranschlüsse; der Wasserhahn liegt dazwischen. Ein gemeinsames Leitungsstück entfällt.

Die Grafik ist kein vollständiger Installationsplan: Bei hydraulischer Trennung
können weitere reale Pumpen nötig sein, die hier nicht zusätzlich gezeichnet werden.
Ohne Puffer führt der Heizungszweig vom Umschaltventil direkt nach oben.
Ohne Puffer und Solarthermie rücken die Abgänge nach links; die Zeichenfläche passt
sich bis zur Bedienleiste an.

Der allgemeine Vorlauf nutzt `Vorlauftemperatur_Heizkreis` (ID 10). Ein separater
allgemeiner Sekundärvorlauf ist in der verwendeten Schnittstelle nicht bestätigt.
Mischkreise zeigen ihren eigenen Vorlauf (IDs 21, 24, 137). Fehlt dieser,
bleibt die Heizkreisanzeige leer. Ein eigener Rücklauf je Mischer ist nicht
bestätigt; die allgemeinen Temperaturen bleiben im Gerätebereich vor dem
Boiler-Umschaltventil. Es werden keine zusätzlichen Messwerte errechnet.

Mischkreis-Sollwerte und Auf-/Zu-Ausgänge bleiben als native Datenpunkte in der
ID-Liste verfügbar, werden aber nicht mehr an zusätzlichen Ventilen dargestellt.
Das Boiler-Umschaltventil zeigt weiterhin seine angeforderte Richtung. Nach einer
beobachteten Ausgangsänderung blinkt die Anzeige für eine Minute; die erste Abfrage
löst kein Blinken aus. Eine tatsächliche Ventilstellung wird nicht errechnet.

Die Pumpenausgänge sind HUP (ID 39) für Mischkreis 1, FP2 (51) für Mischkreis 2
und FP3 (140) für Mischkreis 3. Nur HUP hat hier den bestätigten Prozentwert 183.
Der BUP-Ausgang wird nicht als Pufferspeicher-Ladepumpe dargestellt.

Der externe Rücklauffühler `Ruecklauftemperatur_im_Trennspeicher` (Java-ID 13,
SHI-IR 10102) dient je nach Hydraulikschema der Regelung. Bei aktiviertem Heizpuffer
steht dieser Fühlerwert mittig im Speicher; der Tooltip nennt seine Herkunft.
Die Platzierung bezeichnet keine zusätzliche Messung in der Speichermitte.
Obere und untere Speicherwerte erscheinen nur mit separat zugeordneten Fühlern.
Solarthermie nutzt
`Fuehler_Solarkollektor` (26), `Fuehler_Solarspeicher` (27) und
`Ausgang_Solarladepumpe` (52). Die Komponenten-Schalter steuern die Darstellung
und die interne Datenauswahl. Das Solarziel muss zur tatsächlichen Anlage passen.

Jedes Grafikfeld verwendet genau einen festen Ident. Die Kondensatortemperatur
liest `Liquefaction_Temperature` (233), die Verdichterdrehzahl `Verdichterfrequenz` (231).
Die Solldrehzahl bleibt als Datenpunkt verfügbar, wird aber nicht in der Grafik angezeigt.
Es gibt keine automatische
Auswahl anhand von Null-/Nichtnullwerten. Echte Nullwerte bleiben sichtbar.
Für die Animation zählt der Istwert; ein positiver Sollwert allein startet sie
nicht. Das Relais dient nur bei fehlendem Istwert als Rückfall. Einheiten werden
aus dem Modul übernommen; es erfolgt keine Umrechnung zwischen RPM und Hz.

**Status, COP und JAZ** erscheinen im oberen Infobereich. COP/JAZ nutzen die eigenen
Instanzvariablen `copfaktor` und `jazfaktor`, sobald sie vorhanden sind. Die Grafik übernimmt
deren berechnete Werte und Live-Änderungen; Berechnung und Zählerkonfiguration
bleiben im bestehenden Luxtronik-Modul.
Beschriftungen und Werte verwenden dieselben Schriftstile wie die anderen
Leistungsfelder. COP, JAZ und der anklickbare Link **Fehler: Anzahl** stehen in einer
gemeinsamen Zeile. Bei längeren Werten wird die Zeile innerhalb ihres verfügbaren
Platzes eingepasst; der Betriebsstatus steht darüber ohne Fehlerlink.

Heizleistung und Eingangsleistung stehen ebenfalls im Infobereich. Die allgemeinen Werte für Vorlauf, Rücklauf und Rücklauf-Soll stehen direkt
an der Wärmepumpe vor der Aufteilung für Warmwasser und Heizung. Die Heizkreise
zeigen ausschließlich den Vorlauf ihres jeweiligen Mischkreises, ohne Ersatz
durch WP-Temperaturen oder einen anderen Mischkreis. Fehlende Werte erscheinen als **VL: —**.
Der Durchfluss steht mittig zwischen den blauen Querleitungen. RL und RL Soll
stehen im gleichen Abstand oberhalb und unterhalb der unteren Querleitung.
Der Durchfluss liest ausschließlich `Flow_Rate` (ID 254, l/h) und steht am
gemeinsamen Wärmepumpenausgang. WMZ-Durchfluss (155) und Wärmequellendurchfluss
(173) werden dafür nicht ersatzweise verwendet.

Der Warmwasserspeicher zeigt die Isttemperatur (`Warmwasser_Ist_Temperatur`);
der Sollwert steht ausschließlich im oberen Button. Gespeicherte Zuordnungen
weiterer Fühler bleiben erhalten. Die Summe der ausgewählten Heizstableistungen
steht mit einem gelben Blitz im Boiler; bei Leistung über null pulsiert er.
Die numerischen Ist-Leistungen werden mit ihrer W/kW-Einheit addiert. Null und
vorzeichenbehaftete Messwerte bleiben gültig. Schaltzustände und deren
Assoziationen beeinflussen diese Rechnung nicht. Doppelt ausgewählte Quellen
werden nur einmal summiert. Ohne gültige konfigurierte Leistungsquelle erscheint
„—“; eine fehlende ausgewählte Quelle wird nicht als 0 ausgegeben.

Der Button **Heizstäbe** zwischen Heizkorrektur und LUX-Bedienfeld zeigt die
summierte Ist-Leistung in kW. Er öffnet die
konfigurierten Heizstäbe mit einer Zeile je Heizstab: Name, Ein/Aus-Aktion und
gemessene Leistung. Der Button nennt den nächsten Schaltbefehl; der gemeldete
Zustand steht im Tooltip. Geschaltet wird ausschließlich
über `RequestAction` der in der Konfiguration ausgewählten externen
Variable mit aktiver Aktion. Die Bedienung übernimmt Beschriftungen und
tatsächliche Assoziationswerte, auch für Integer, Float und String. Eine
Integer-Auswahl wird nicht auf Boolean oder 0/1 reduziert. Für Boolean ohne
eigene Assoziationen stehen Ein/Aus zur Verfügung. Im Konfigurationsbereich jedes
Heizstabs lassen sich **Ein-Wert** und **Aus-Wert** aus dessen Assoziationen wählen,
beispielsweise Ein = 2 und Aus = 0. Ohne Ein/Aus-Zuordnung bleiben mehrstufige
Profilwerte über ein kompaktes Auswahlfeld in derselben Zeile bedienbar,
einschließlich Zwischenzuständen wie Automatik.
Die Grafik setzt den Variablenwert nicht
direkt, sondern wartet auf dessen gemeldeten Zustand. Ohne Schaltaktion bleibt
der Eintrag lesend. Bei deaktivierter Luxtronik-Instanz wird nicht geschaltet.
Externe Heizstäbe können auch im SHI-Betrieb bedient werden; dabei werden keine
Luxtronik-Schreibbefehle gesendet.

Ohne ausdrücklich gewählte Raumquelle bleibt die bisherige interne
Raumtemperaturanbindung bestehen. Symcon-Symbole werden über das dokumentierte
HTML-SDK-Skript `/icons.js` eingebunden. Nicht zuordenbare ältere Symbole erhalten
ein Haus als Ersatz; das besondere Symbol `Transparent` blendet das Symbol aus.
Vorhandene Kreisauswahlen aus früheren Entwicklungsständen behalten bei der Migration ihre Anzahl
sichtbarer Heizkreisplätze. Frühere Mischkreisschalter werden nicht mehr benötigt.

## Messwerte und Bedienung

Jedes Grafikfeld verwendet einen eindeutigen, fest zugeordneten Ident. Es wird in der eigenen Instanz nach diesem
internen Ident gesucht. Ohne passenden Ident bleibt eine bereits ausdrücklich
konfigurierte externe Variable als Fallback erhalten; dafür wird keine neue
umfassende Zuordnungsmaske eingeführt. Unbelegte Temperaturfelder
werden ausgeblendet; dieselbe konfigurierte Variable darf mehrere Speicherpositionen
versorgen.

`Warmwasser_Ist_Temperatur` liefert den einzigen Istwert im Warmwasserspeicher.
Zusätzliche Heizkreis-Vor-/Rücklaufwerte
werden am Boiler nicht erzeugt. `SEC_EEV` / Messwert 247 wird nicht automatisch
als Expansionsventilöffnung übernommen, da Zuordnung und Skalierung unbestätigt sind.

Bedienung von Heizen, Warmwasser, Kühlen und den beiden Sollwerten erfolgt über die
bereits vorhandene Luxtronik-Aktionslogik mit deren Prüfung, Schreiben und Rücklesen.
Die Grafik schreibt keine Symcon-Variablen direkt und führt keine eigene
Geräteabfrage aus. Im SHI-Lesebetrieb und bei deaktivierter Instanz sind diese
Grafikaktionen gesperrt. VM_UPDATE und Objektänderungen aktualisieren die Darstellung
und ihre Variablenbindungen über UpdateVisualizationValue.

## LUX-Bedienfeld

Das übernommene LUX-Bedienfeld nutzt die IP-Adresse dieser Instanz, nicht deren
Java-Datenport. Es verbindet sich nur nach bewusstem Öffnen im Browser über das
ursprüngliche WebSocket-Protokoll mit dem Regler. Aus einer HTTPS-Visualisierung
bietet es das separat geöffnete Originalinterface an. Im SHI-Lesebetrieb und bei
deaktivierter Instanz wird das Bedienfeld nicht angeboten.

Protokoll, Anzeige und Wiederverbindung sind mit einem lokalen Browser-Simulator
geprüft. Die reale Geräteintegration und das Verhalten in einer echten
Symcon-Instanz sind weiterhin separat zu prüfen.

## Herkunft und Prüfung

Basis: `mb-stern/Waermepumpe`, Branch `development`, Commit
`aae3f06c0b8e148b5671386797368771bcd3e8e1`. HTML, JavaScript und deutsche
Beschriftungen sind im Modul integriert (`Visualization.php`); die SVG bleibt
als Ressource in `heat-pump/heat-pump-card/`. Die MIT-Lizenz des übernommenen
Quellmaterials liegt unter `heat-pump/LICENSE`.

Die Java- und SHI-Kommunikation bleibt bestehen. Bestehende Modul-/Bibliotheks-IDs,
Variablen-Idents sowie die Typen und Standardwerte bisheriger Einstellungen wurden
erhalten. Messpunkte der Grafik werden intern zwischengespeichert; die ID-Liste
steuert die im Objektbaum angelegten Messwertvariablen. Die
bisherigen Bedien- und Berechnungsvariablen bleiben durch ihre vorhandenen
Optionen gesteuert. Der interne Speicher ist an Verbindung und Betriebsart
gebunden; fehlerhafte Abfragen überschreiben keine vollständigen Messwerte.
Wenn sich HTML/JavaScript oder SVG durch ein Modulupdate ändern, fordert das Modul
beim nächsten Aufbau einmal das Neuladen der Grafik an. Zeigt ein Browser weiterhin
die alte Darstellung, die Visualisierung vollständig schließen und neu öffnen.

```sh
php tests/modbus.php
php tests/visualization.php
python3 tests/browser.py
```

PHP-Prüfungen nutzen eine Symcon-Nachbildung und lokale Protokollsimulatoren.
Die optionalen Browsertests benötigen Playwright und Chromium. Sie prüfen Desktop
und Mobilansicht, Live-Aktualisierung, Speicherfühler, Bedienpopups,
Raumquellen, Heizkreistemperaturen, COP/JAZ und das simulierte LUX-Bedienfeld.
Die echte Symbolbibliothek wird von Symcon bereitgestellt; lokal werden Bindung
und sichere Symbolklassen geprüft. Eine echte Symcon-Instanz mit Wärmepumpe
wurde in dieser Cloud-Umgebung nicht verwendet.


Am Expansionsventil steht nur der Prozentwert, abhängig vom Kühlstatus aus EEV Heizen oder EEV Kühlen.
Die Prozentwerte kommen aus den bestätigten WebSocket-Feldern, nicht aus Java-ID 247.
Die Grafik benötigt dafür keine angelegten Messvariablen. Diagnose und JSON-Download
stehen unter WebSocket-Ergänzung in der Instanzkonfiguration; das LUX-Fenster enthält
nur die bisherige Geräteanzeige mit Bedienung.

Bis 600 px Kachelbreite entfallen die Bauteilnamen Verdichter, Verdampfer,
Kondensator und Expansionsventil. Die Mindestschrift in der gesamten Grafik
steigt dabei moderat von 18 auf 20 SVG-Einheiten; bereits größere Texte bleiben
unverändert. Bei breiteren Ansichten erscheinen die Bauteilnamen wieder.

In der COP-/JAZ-Zeile öffnet „Fehler: Anzahl“ die gespeicherte Historie mit Datum,
Fehlercode und vollständigem Meldungstext. WebSocket wird für diese Daten bevorzugt;
Java bleibt die Reserve. Die Historie stellt keine aktuellen Alarmzustände dar.
Die vorhandenen IDs 95–105 werden wiederverwendet, die zusätzliche Auswahl-ID 400020
bietet den gesamten Meldungstext als Stringvariable. Die konfigurierte Reglerzeitzone
bestimmt die Umrechnung der Fehlerdaten in Zeitstempel.

Das Umschaltventil zeigt ohne zusätzliche Beschriftung seinen angeforderten
Durchgang: BUP (38) ein führt nach rechts zum Warmwasserspeicher, aus nach oben
zur Heizung. Bei unbekanntem Ausgang wird keine Stellung gezeichnet. Der Tooltip
weist darauf hin, dass keine mechanische Positionsrückmeldung vorliegt.

Der Heizungszweig erhält seinen Vorlauf nach der Boilerumschaltung. Während
Warmwasserladung ohne Puffer wird er blasser und nicht als durchströmt animiert.
Die angezeigten Heizkreistemperaturen werden auf dem letzten Heizwert gehalten,
mit ⏸ markiert und auch beim erneuten Öffnen beibehalten. Nach Ladeende bleiben sie
gehalten, bis der WP-Vorlauf wieder auf seinen letzten Heizwert oder darunter
gefallen ist. Erst dann erscheinen wieder aktuelle Heizkreistemperaturen.
Der WP-Vorlauf selbst und alle Messvariablen bleiben live und unverändert. Ohne
vorherigen Heizwert wird keine Temperatur erfunden. Ein hydraulisch getrennter
Puffer kann die Heizkreise unabhängig versorgen; dort bleiben deren Messwerte live.

Der Boilerzweig animiert nur bei angewähltem Warmwasserweg und vorhandenem
Primärdurchfluss. Ein gemessener Durchfluss von 0 stoppt die Primäranimation auch
bei aktivem BUP. Ohne verfügbaren BUP-Wert dient der Warmwasser-Betriebszustand als
Darstellungsersatz; die Ventilstellung bleibt dabei ausdrücklich unbekannt.
Mit Puffer bleibt die unabhängig gepumpte Heizungsseite darstellbar. Die Leitungen
von der WP zum Puffer werden während Boilerladung nicht als durchströmt gezeichnet.
Die Grafik stellt die konfigurierte Umschalthydraulik schematisch dar; sie bestimmt
nicht den tatsächlichen Einbauort von Pumpe oder Ventil im konkreten Gerät.

Die Ansaugtemperatur (`Temperatur_Ansaug_Verdichter`, Java-ID 176; im Regler
„Ansaug VD“) steht als „Ansaug: … °C“ an der blauen Leitung vor dem Verdichter.
Gegenüber steht die Heißgastemperatur (`Heisgastemperatur`, Java-ID 14) nach dem
Verdichter vor dem Kondensator. Beide verwenden die vorhandene Lesequellenauswahl
mit automatischer Ergänzung fehlender Werte; nicht gelieferte Temperaturen
werden ausgeblendet, 0 °C bleibt ein gültiger Messwert.
Die Lüfterdrehzahl (`Ventilator_Drehzahl`, ID 184) steht
über dem Lüfterrad. Unter dem Gerätebild ergänzt `Zeit_bis_Abtauen` (ID 141)
die Lauf-/Standzeit, sofern der Regler den Wert liefert. Im SHI-Betrieb können
beide Werte über die vorhandene optionale Java-Ergänzung kommen. Es werden keine
zusätzlichen Messwertobjekte und kein eigener Abtau-Countdown erzeugt.

Das LUX-Fenster übernimmt Hintergrund, Text und Buttons aus dem Symcon-Design,
unabhängig von der Hell-/Dunkel-Voreinstellung des Browsers. Es verwendet das
Webinterface-Passwort der Instanzkonfiguration (Standard 999999); ein zweites
Passwortfeld entfällt. Oben stehen nur die drei Symbole für **Originalinterface öffnen** (↗),
**Erneut verbinden** (↻) und **Schließen** (×) in einer Zeile. Überschrift und
Verbindungsstatus entfallen bei vorhandenem Displaybild; Hinweise erscheinen,
wenn noch kein Bild empfangen wurde oder die Verbindung scheitert. Das Display
passt sich auch der verfügbaren Höhe an, damit die Bedienpfeile sichtbar bleiben.
