# Abgleich des WebSocket-Berichts vom 25.10.2026

Basis: 104 Einträge auf zehn Informationsseiten, Software V3.86.1 (r7762).
Die erste Tabelle dokumentiert den Stand vor der gezielten Ergänzung. Die Zahlen
gelten für diesen Bericht, nicht für alle Luxtronik-Modelle. Ein Eintrag ist nicht immer genau eine Symcon-Variable:
Ein Fehlereintrag enthält beispielsweise Zeitstempel und Fehlercode.

| Kategorie | Berichtseinträge | Vorhandene bzw. zusätzliche Idents |
| --- | ---: | ---: |
| Bereits korrekt automatisch zugeordnet | 65 | 65 vorhandene |
| Zusätzlich eindeutig vorhandenen Idents zuordenbar | 13 | 18 vorhandene |
| Klar benannte Zusatzfelder ohne passenden bisherigen Ident | 14 | 14 zusätzliche bei Übernahme |
| Zuordnung zu bestehenden Idents noch zu prüfen | 12 | Noch offen |
| Gesamt | 104 | Keine 1:1-Zählung von Einträgen und Idents |

Damit sind 78 Einträge auf 83 bestehende Idents abbildbar. Darin enthalten sind
die fünf bereits eingeführten WebSocket-Idents für EEV Heizen/Kühlen, Ventil BOSUP
und AO 21/22. Die 14 zusätzlichen Idents sind inzwischen als 400006–400019
unten in der Checkboxliste auswählbar. Bestehende Idents bleiben unverändert.

Mit den bestätigten Beschriftungen der sechs Betriebsstundenzähler sind damit
85 der 104 Berichtseinträge technisch zugeordnet. Zyklisch werden davon nur
benötigte fehlende Werte übernommen. Bivalenzstufe, fünf Fehlereinträge und der
leere Betriebszustand bleiben noch umzusetzen; die zwölf unten beschriebenen
Zuordnungen bleiben offen. Die vollständige Diagnose liest weiterhin alle Seiten.

Die 13 zusätzlich zuordenbaren Einträge sind sechs Betriebsstundenzähler,
Bivalenzstufe, fünf Fehlereinträge (je Zeitstempel und Code) und Betriebszustand.
Der Betriebszustand ist im Bericht leer: Sein Ident ist eindeutig, ein aktueller
Zustandswert lässt sich aus der leeren Zeichenfolge nicht gewinnen. Fehlercodes
und Reihenfolge werden durch den älteren Java-3004-Bericht bestätigt; Zeitstempel
benötigen die Gerätezeitzone.

Die 14 Zusatzfelder sind die Restzeiten Freigabe ZWE und Freigabe Kühlung,
Laufzeit Ø VD1, der vollständige Softwarestand einschließlich Revision,
HZ/IO, ASB, ASB HW Revision, Inverter SW Version, Abtaubedarf, letzte Abtauung,
Wärmemenge Zusatzwärmeerzeuger, Wärmemenge seit angegebenem Datum, Wärmemenge
seit Reset und GLT-Art. Die Kühlfreigabe-Restzeit ist kein Ersatz für den
bestehenden booleschen Ident `Freigabe_Kuehlung`.

Die zwölf offenen Einträge sind TFL1/TFL2, AIn 21/22, die beiden gleich benannten
Wärmepumpentyp-Felder, Leistung Ist und fünf Abschaltungen. Bestehende Temperatur-,
Analog-, Leistungs- und Typ-Idents sind mögliche Kandidaten, aber noch nicht
eindeutig bestätigt. Bei Abschaltungen müssen Reihenfolge und Codeübersetzung
(insbesondere „Halt“) geklärt werden; es existieren zwei alte Ident-Gruppen.

Die vorher gemeldeten 66 automatischen Zuordnungen enthielten einen Fehler:
„Freig. ZWE“ wurde Java-ID 78 (`Waermepumpentyp`) zugewiesen. Diese Zuordnung
ist entfernt. An diesem Ausgangsstand blieben 65 korrekte automatische Zuordnungen; für die übrigen
39 Berichtseinträge war noch Umsetzung bzw. Prüfung erforderlich.

## Schreibbefehle

Der Bericht enthält ausschließlich Informationen, keine Einstellparameter mit
Schreiboptionen, Rohwerten, Grenzen und Schrittweiten. Er bestätigt deshalb null
direkte WebSocket-Parameteraktionen; die Anzahl zusätzlich benötigter Schreib-Idents
ist noch nicht bestimmbar. WebSocket ergänzt Messwerte automatisch; Java/Modbus bestimmen den Schreibweg.

Der vorhandene Katalog umfasst sieben Java-Hauptbedienwerte und 160 konkret in der
Konfiguration angebotene Zeitprogrammfelder, zusammen 167 Java-Schreib-Idents.
Die allgemein validierte Parameter-Spanne 223–505 bedeutet nicht, dass alle 283
Nummern als Zeitprogrammfelder angeboten werden. Modbus bietet 41 Schreibfunktionen,
drei davon mit bereits gemeinsam genutzten Sollwert-Idents. Das ergibt 205
unterschiedliche vorhandene Schreib-Idents über beide Protokolle.

Welche dieser Idents über WebSocket schreibbar sind und welche zusätzlichen
Schreibfelder angeboten werden, muss ein gesonderter lesender Test der Seiten
„Einstellungen“ und „Zeitschaltprogramm“ samt Metadaten ermitteln. Das ist von
einem tatsächlichen Schreibtest mit Rücklesen zu unterscheiden. Die bestehende
LUX-Display-Fernbedienung über WebSocket ist keine direkte Parameterzuordnung.
