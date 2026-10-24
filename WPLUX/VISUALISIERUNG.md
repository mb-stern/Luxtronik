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
Input-Register. Nur die ID-Liste einschließlich Fußzeilen-Auswahl bestimmt,
welche Messwertvariablen im Objektbaum angelegt werden. Die gespeicherte Auswahl
wird nicht verändert. Bedienvariablen
werden weiterhin über die bisherigen Optionen aktiviert.

Der zusätzliche Bereich **HTML-SDK / Visualisierung** bleibt bewusst schlank:

- Komponenten-Checkboxen für Puffer, Warmwasserspeicher und Solarthermie.
- Optionale Warmwasser-Zirkulation mit Pumpe, Leitung und Hahn; ihr Zustand
  stammt intern von `Ausgang_Zirkulationspumpe` (ID 46).
- Checkboxen für die drei Heizkreisplätze der ursprünglichen Grafik.
- Mischkreis 1 erscheint standardmäßig mit Ist/Soll und Ventilsymbol nahe der
  Wärmepumpe. Weitere Mischerdaten erscheinen automatisch bei vorhandenen Idents;
  dafür gibt es keine Konfigurationsschalter.
- Bis zu drei optionale Raumtemperaturen mit frei wählbarer Bezeichnung
  (leer = Variablenname). Ein Einzelwert wird größer dargestellt. Auf Wunsch
  wird das Variablensymbol verwendet, sonst ein Haus. Je Raum sind Name, Icon
  oder beides wählbar; eine zusätzliche Überschrift „Innentemperatur“ entfällt.
- Unter **Heizstäbe** bis zu drei Ist-Leistungsvariablen (W oder kW laut Profil;
  ohne Einheit W) und jeweils eine optionale Schaltvariable mit Aktion und
  Assoziationen. Legacy-Profile und moderne Aufzählungen werden unterstützt.
- Solarwärme wahlweise an Warmwasserspeicher oder Puffer (Heizunterstützung),
  direkt neben dem Solarthermie-Schalter. Für das Solarziel Puffer muss auch der
  Pufferspeicher aktiviert sein.
- Ansteuerung der Heizwasserpumpe über ID 183 in Prozent. Eine separate
  U/min-Drehzahl ist nicht bestätigt und wird nicht angezeigt.
- Eingangsleistung aus der bereits konfigurierten COP-Quelle `kwin` (kW), ohne
  weiteren Picker. Ist diese nicht konfiguriert, wird der übertragene Wert
  `Leistungsaufnahme` (ID 268, Watt) verwendet. Das allein bestätigt keine echte
  Strommessung. Die verwendete Quelle steht im Tooltip des Leistungswerts.
- **Drei optionale Fremdvariablen**, jeweils nur Bezeichnung und Variablenauswahl.
  Sie erscheinen als frei benannte Werte in der Fußzeile.

Es gibt keine separaten Auswahlfelder für einzelne Speicherfühler, Pumpen oder
interne Heizkreiswerte. Ein umfassendes Zuordnungsformular, Zuordnungsdiagnosen,
Tabellen, ein Importbutton und ein manueller Modus entfallen.

Die Warmwasserbereitung bleibt ein eigener Speicher-/Ladekreis und belegt keinen
der drei Heizkreisplätze. Vom Luxtronik-Regler gelieferte Mischkreiswerte
sind interne Werte, auch wenn Mischer und Pumpen außerhalb des Wärmepumpengehäuses
montiert sind. Fremdvariablen stammen beispielsweise von separaten Symcon-Zählern.

Je Heizkreis wird unter **Anbindung** zwischen **Direkt** und **Mischkreis 1–3**
gewählt. Standardmäßig gehört Mischer 1 zu Heizkreis 1, entsprechend 2 und 3.
Nur aktivierte Heizkreisplätze zeigen ihre ausgewählten Mischer. Messwerte allein
beweisen nicht, dass ein Mischkreis tatsächlich verbaut ist.

Der optionale Parallelpuffer sitzt links beim Infobereich, vor dem Verteiler.
Jeder gemischte Abgang erhält rechts davon sein eigenes Ventil mit
Rücklaufbeimischung, Pumpe und Heizfläche. Ohne Puffer führen die Leitungen direkt
von der Wärmepumpe zu den Abgängen; direkte Heizkreise zeigen kein Mischerventil.
Die Heizfläche steht stellvertretend für die angeschlossenen Verbraucher.

Der allgemeine Vorlauf nutzt `Vorlauftemperatur_Heizkreis` (ID 10). Ein separater
allgemeiner Sekundärvorlauf ist in der verwendeten Schnittstelle nicht bestätigt.
Mischkreise zeigen ihren eigenen Vorlauf (IDs 21, 24, 137). Fehlt dieser, verwendet
die Heizkreisanzeige den normalen Vorlauf mit der Beschriftung **WP VL**. Es wird
kein zusätzlicher Messwert errechnet. Ein eigener Rücklauf je Mischer ist ebenfalls
nicht bestätigt und wird nicht aus dem gemeinsamen Wärmepumpen-Rücklauf abgeleitet.

Die separaten Mischerdaten nutzen `Mischkreis_N_Vorlauftemperatur`,
`Mischkreis_N_Vorlauf_Soll_Temperatur`, `Ausgang_Mischkreis_N_Auf` und
`Ausgang_Mischkreis_N_Zu` (N = 1–3). Auf/Zu zeigt die Ansteuerung des Motors,
keine gemessene Ventilposition: beide Ausgänge aus bedeutet keine Ansteuerung.
Das Ventilsymbol zeigt im kleinen Antrieb den Auf-Befehl (↑), Zu-Befehl (↓), keine Ansteuerung (•),
unbekannten Zustand (?) oder widersprüchliche Auf-/Zu-Ansteuerung (!). Der Pfeil
blinkt dezent für eine Minute nach einer beobachteten Ausgangsänderung; ein gelber Ring entfällt.
Danach bleibt auch ein dauerhaft aktiver Befehl ruhig. Die erste Abfrage löst kein
Blinken aus. Dasselbe gilt für die angeforderte Richtung Heizung/Warmwasser am
Umschaltventil. Eine tatsächliche Ventilstellung wird nicht errechnet.
Ist/Soll stehen unmittelbar neben dem Ventil;
der frühere Mischkreis-Kasten entfällt. Für Mischkreis 1 werden die IDs 21, 22, 40
und 41 automatisch verarbeitet. Für weitere aktivierte Heizkreisplätze werden
die zugeordneten Mischerdaten automatisch eingebunden, unabhängig von der ID-Liste.

Die Pumpenausgänge sind HUP (ID 39) für Mischkreis 1, FP2 (51) für Mischkreis 2
und FP3 (140) für Mischkreis 3. Nur HUP hat hier den bestätigten Prozentwert 183.
Der BUP-Ausgang wird nicht als Pufferspeicher-Ladepumpe dargestellt.

Der Puffer nutzt `Ruecklauftemperatur_im_Trennspeicher` (ID 13) als **RL extern**;
daraus werden keine drei Speicherfühler abgeleitet. Solarthermie nutzt
`Fuehler_Solarkollektor` (26), `Fuehler_Solarspeicher` (27) und
`Ausgang_Solarladepumpe` (52). Die Komponenten-Schalter steuern die Darstellung
und die interne Datenauswahl. Das Solarziel muss zur tatsächlichen Anlage passen.

**Status, COP und JAZ** erscheinen im oberen Infobereich. COP/JAZ nutzen die eigenen
Instanzvariablen `copfaktor` und `jazfaktor`, sobald sie vorhanden sind. Die Grafik übernimmt
deren berechnete Werte und Live-Änderungen; Berechnung und Zählerkonfiguration
bleiben im bestehenden Luxtronik-Modul.
Beschriftungen und Werte verwenden dieselben Schriftstile wie die anderen
Leistungsfelder; COP/JAZ werden nicht horizontal verkleinert.

Heizleistung und Eingangsleistung stehen ebenfalls im Infobereich. Der
Rücklauf-Sollwert steht bei den aktiven Heizkreisen. Bei mehreren Kreisen heißt er
„WP RL Soll“ und bezeichnet ausdrücklich den gemeinsamen Wärmepumpen-Sollwert.
Der Durchfluss des Wärmemengenzählers (ID 155, l/h)
steht am gemeinsamen Wärmepumpenausgang vor der Verzweigung. Der als
Wärmequellendurchfluss bezeichnete Wert (ID 173) wird dafür nicht verwendet.

Der Warmwasserspeicher zeigt nur Ist (`Warmwasser_Ist_Temperatur`) und Soll
(`Warmwasser_Soll_Temperatur`, alternativ `Anpassung_WW`). Gespeicherte Zuordnungen
weiterer Fühler bleiben erhalten. Die Summe der ausgewählten Heizstableistungen
steht mit einem gelben Blitz im Boiler; bei Leistung über null pulsiert er.
Schaltzustände werden nicht in Watt umgedeutet, doppelt ausgewählte Variablen
werden nur einmal summiert. Bei fehlender oder ungültiger Leistung erscheint „—“.

Der Button **Heizstäbe** zwischen Heizkorrektur und LUX-Bedienfeld zeigt die
summierte Ist-Leistung in kW, bei fehlenden Leistungswerten „—“. Er öffnet die
konfigurierten Heizstäbe mit Ein-/Ausschaltung. Geschaltet wird ausschließlich
über `RequestAction` der in der Konfiguration ausgewählten externen
Variable mit aktiver Aktion. Die Bedienung übernimmt Beschriftungen und
tatsächliche Assoziationswerte, auch für Integer, Float und String. Eine
Integer-Auswahl wird nicht auf Boolean oder 0/1 reduziert. Für Boolean ohne
eigene Assoziationen stehen Ein/Aus zur Verfügung. Im Konfigurationsbereich jedes
Heizstabs lassen sich **Ein-Wert** und **Aus-Wert** aus dessen Assoziationen wählen,
beispielsweise Ein = 2 und Aus = 0. Ohne Auswahl bleiben alle Profilwerte bedienbar.
Ein Zwischenzustand wie Automatik wird weiterhin mit seinem Namen angezeigt.
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

## Interne Werte in der Fußzeile

In der vorhandenen Liste **Überwachte IDs** steht neben **Aktiv** die zusätzliche
Checkbox **Fußzeile**. Die Auswahl wird in derselben ID-Liste gespeichert und
bleibt beim Öffnen, Speichern und Neustart erhalten. Eine Auswahl für die Fußzeile
aktiviert den betreffenden Messpunkt bei der normalen Aktualisierung mit; sie
löst keine separate Java-Datenabfrage aus. Im SHI-Modus werden nur unterstützte
Input-Register gelesen, weiterhin ohne Schreibbefehle.

Die Fußzeile zeigt Namen und formatierte Werte der eigenen Variablen, ohne neue
Variablen anzulegen oder Messwerte nochmals umzurechnen. Mehr als zehn ausgewählte
Werte sind möglich; die Fußzeile wächst um weitere Zeilen. Nicht vorhandene
Variablen erscheinen erst, sobald sie durch eine erfolgreiche Datenabfrage
angelegt wurden. Fehlende oder nicht unterstützte SHI-Daten können wie bisher
letzte Werte enthalten; die SHI-Unterstützung ist in der Konfiguration ausgewiesen.

## Messwerte und Bedienung

Die vollständige Ident-Tabelle aus dem Quellstand wurde übernommen, einschließlich
der Alternativen für Verdichterdrehzahl/-frequenz, Kondensationstemperatur und
Raumtemperaturen. Es wird ausschließlich innerhalb der eigenen Instanz nach
internen Idents gesucht. Ohne passenden Ident bleibt eine bereits ausdrücklich
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
steuert die im Objektbaum angelegten Messwertvariablen und die Fußzeile. Die
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
und Mobilansicht, Live-Aktualisierung, Speicherfühler, Bedienpopups, die dynamische
Fußzeile, Raumquellen, Mischerdaten, COP/JAZ und das simulierte LUX-Bedienfeld.
Die echte Symbolbibliothek wird von Symcon bereitgestellt; lokal werden Bindung
und sichere Symbolklassen geprüft. Eine echte Symcon-Instanz mit Wärmepumpe
wurde in dieser Cloud-Umgebung nicht verwendet.
