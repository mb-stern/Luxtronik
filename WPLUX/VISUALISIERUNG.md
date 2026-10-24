# Integrierte Wärmepumpengrafik

Ab Version 4.9 stellt die Luxtronik-Instanz ihre Wärmepumpengrafik über das
Symcon HTML-SDK bereit. Sie verwendet die vorhandenen Instanzvariablen direkt
anhand ihrer Idents. Eine zweite Modulinstanz, eine Quellinstanzauswahl oder ein
Import von Variablenzuordnungen sind nicht erforderlich. Es gibt ausschließlich
die Vollansicht.

## Einrichtung

Die Luxtronik-Instanz in der Kachelvisualisierung mit ihrer individuellen
Darstellung verwenden. Die benötigten internen Messpunkte und Bedienvariablen
werden wie bisher in der Modulkonfiguration aktiviert.

Der zusätzliche Bereich **HTML-SDK / Visualisierung** bleibt bewusst schlank:

- Komponenten-Checkboxen für Puffer, Warmwasserspeicher und Solarthermie.
- Checkboxen für die drei Heizkreisplätze der ursprünglichen Grafik.
- Mischkreis 1 erscheint standardmäßig mit Ist/Soll und Ventilsymbol nahe der
  Wärmepumpe. Weitere Mischerdaten erscheinen automatisch bei vorhandenen Idents;
  dafür gibt es keine Konfigurationsschalter.
- Bis zu drei optionale Raumtemperaturen mit frei wählbarer Bezeichnung
  (leer = Variablenname). Ein Einzelwert wird größer dargestellt. Auf Wunsch
  wird das Variablensymbol verwendet, sonst ein Haus.
- Die Anzahl der vorhandenen Heizstäbe.
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
Fehlende Signale erscheinen als „—“; fehlende Temperaturfelder bleiben leer.
Das Warmwasser-Umschaltventil sitzt näher am Gerät. Die benötigten internen
Messpunkte müssen wie bisher in der vorhandenen ID-Liste aktiv sein.

**COP und JAZ** erscheinen direkt unter dem Heizgerätebild, sobald die eigenen
Instanzvariablen `copfaktor` und `jazfaktor` vorhanden sind. Die Grafik übernimmt
deren berechnete Werte und Live-Änderungen; Berechnung und Zählerkonfiguration
bleiben im bestehenden Luxtronik-Modul.

Ohne ausdrücklich gewählte Raumquelle bleibt die bisherige interne
Raumtemperaturanbindung bestehen. Symcon-Symbole werden über das dokumentierte
HTML-SDK-Skript `/icons.js` eingebunden. Nicht zuordenbare ältere Symbole erhalten
ein Haus als Ersatz; das besondere Symbol `Transparent` blendet das Symbol aus.
Vorhandene Kreisauswahlen aus Version 4.9 behalten bei der Migration ihre Anzahl
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

`Warmwasser_Ist_Temperatur` gehört zu **Temperatur Mitte** im Warmwasserspeicher;
`Temperatur_Brauchwasser_Oben` gehört nach oben. Zusätzliche Heizkreis-Vor-/Rücklaufwerte
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
erhalten. Abgesehen von der ausdrücklichen Fußzeilen-Auswahl werden keine
zusätzlichen internen Messpunkte automatisch aktiviert.

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
