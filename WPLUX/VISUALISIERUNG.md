# Integrierte Wärmepumpengrafik

Ab Version 5.0 stellt die Luxtronik-Instanz ihre Wärmepumpengrafik über das
Symcon HTML-SDK bereit. Sie verwendet die vorhandenen Instanzvariablen direkt
anhand ihrer Idents. Eine zweite Modulinstanz, eine Quellinstanzauswahl oder ein
Import von Variablenzuordnungen sind nicht erforderlich. Es gibt ausschließlich
die Vollansicht.

## Einrichtung

Die Luxtronik-Instanz in der Kachelvisualisierung mit ihrer individuellen
Darstellung verwenden. Die internen Messpunkte der Grafik werden bei der normalen
Aktualisierung unabhängig von der ID-Liste angelegt und aktualisiert. Java nutzt
weiterhin eine gemeinsame 3004-Abfrage. SHI liest zusätzlich nur bestätigte
Input-Register. Die gespeicherte ID-Auswahl wird nicht verändert. Bedienvariablen
werden weiterhin über die bisherigen Optionen aktiviert.

Der zusätzliche Bereich **HTML-SDK / Visualisierung** bleibt bewusst schlank:

- Komponenten-Checkboxen für Puffer, Warmwasserspeicher und Solarthermie.
- Checkboxen für die drei Heizkreisplätze der ursprünglichen Grafik.
- Mischkreis 1 erscheint standardmäßig mit Ist/Soll und Ventilsymbol nahe der
  Wärmepumpe. Weitere Mischerdaten erscheinen automatisch bei vorhandenen Idents;
  dafür gibt es keine Konfigurationsschalter.
- Bis zu drei optionale Raumtemperaturen mit frei wählbarer Bezeichnung
  (leer = Variablenname). Ein Einzelwert wird größer dargestellt. Auf Wunsch
  wird das Variablensymbol verwendet, sonst ein Haus. Je Raum sind Name, Icon
  oder beides wählbar; eine zusätzliche Überschrift „Innentemperatur“ entfällt.
- Unter **Heizstäbe** bis zu drei Ist-Leistungsvariablen (W oder kW laut Profil;
  ohne Einheit W) und jeweils eine optionale Boolean-Schaltvariable mit Aktion.
- Eine optionale externe Eingangsleistung. Vorrang hat `Leistungsaufnahme` (ID 268,
  Watt), danach die externe SDK-Quelle, danach die bisherige COP-Quelle `kwin` (kW).
- **Drei optionale Fremdvariablen**, jeweils nur Bezeichnung und Variablenauswahl.
  Sie erscheinen als frei benannte Werte in der Fußzeile.

Es gibt keine separaten Auswahlfelder für einzelne Speicherfühler, Pumpen oder
interne Heizkreiswerte. Ein umfassendes Zuordnungsformular, Zuordnungsdiagnosen,
Tabellen, ein Importbutton und ein manueller Modus entfallen.

Die Warmwasserbereitung bleibt ein eigener Speicher-/Ladekreis und belegt keinen
der drei Heizkreisplätze. Vom Luxtronik-Regler gelieferte Mischkreiswerte
sind interne Werte, auch wenn Mischer und Pumpen außerhalb des Wärmepumpengehäuses
montiert sind. Fremdvariablen stammen beispielsweise von separaten Symcon-Zählern.

Heizkreis 1 nutzt wie im Quellprojekt die Idents des Wärmepumpenkreises.
Weitere Heizkreisplätze behalten ausdrücklich konfigurierte Quellen. Ohne
bestätigte Zuordnung bleiben ihre Temperaturfelder leer; Mischkreiswerte werden
ihnen nicht automatisch zugewiesen. Die Heizfläche steht stellvertretend für
angeschlossene Verbraucher, etwa mehrere Fußbodenheizungs-Schleifen.

Die separaten Mischerdaten nutzen `Mischkreis_N_Vorlauftemperatur`,
`Mischkreis_N_Vorlauf_Soll_Temperatur`, `Ausgang_Mischkreis_N_Auf` und
`Ausgang_Mischkreis_N_Zu` (N = 1–3). Auf/Zu zeigt die Ansteuerung des Motors,
keine gemessene Ventilposition: beide Ausgänge aus bedeutet keine Bewegung.
Fehlende Signale erscheinen als „—“. Ist/Soll und Auf/Zu stehen direkt beim Ventil;
der frühere Mischkreis-Kasten entfällt. Für Mischkreis 1 werden die IDs 21, 22, 40
und 41 automatisch verarbeitet. Für weitere aktivierte Heizkreisplätze werden
auch die entsprechenden Mischerdaten automatisch eingebunden.

**Status, COP und JAZ** erscheinen im oberen Infobereich. COP/JAZ nutzen die eigenen
Instanzvariablen `copfaktor` und `jazfaktor`, sobald sie vorhanden sind. Die Grafik übernimmt
deren berechnete Werte und Live-Änderungen; Berechnung und Zählerkonfiguration
bleiben im bestehenden Luxtronik-Modul.

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
konfigurierten Heizstäbe mit Ein-/Ausschaltung. Geschaltet wird ausschließlich
über `RequestAction` der in der Konfiguration ausgewählten externen
Boolean-Variable mit aktiver Aktion. Die Grafik setzt den Variablenwert nicht
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
erhalten. Messpunkte der Grafik werden automatisch aktiviert; die ID-Liste
steuert zusätzliche Werte und die Fußzeile.

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
