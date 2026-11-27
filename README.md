# E3/DC Connector für IP-Symcon

Der E3/DC Connector bindet ein einzelnes Hauskraftwerk oder eine E3/DC-Farm mit bis zu zwei konfigurierten Hauskraftwerken an IP-Symcon an. Live-Daten, Zubehör, Tageswerte und unterstützte Steuerbefehle laufen über **lokales RSCP**. Der Connector enthält eine animierte Energiefluss-Kachel und kann eine bearbeitbare Ansicht aus nativen IPSView-Elementen erstellen.

## Voraussetzungen

- IP-Symcon ab **9.0** und ein vom Symcon-Server erreichbares E3/DC-Gerät mit lokalem RSCP.
- Ein für RSCP berechtigtes Konto und der am Gerät eingestellte RSCP-Verschlüsselungsschlüssel. Das Benutzerpasswort und der Verschlüsselungsschlüssel sind getrennte Werte.
- Für die native IPSView-Ansicht ein eingerichteter IPSView Designer. Die Vorlage verwendet Steuerelemente und ein Datenformat von **IPSView 6.5**.
- Die vom Gerät gelieferten Daten und erlaubten Schreibbefehle hängen von Modell, Firmware, Betriebsart und Benutzerrechten ab. Die Entwicklung und Live-Prüfung erfolgten an einer Farm aus zwei E3/DC one. Weitere Modelle können einen anderen Funktionsumfang liefern.

## Installation und Einrichtung

1. In der Symcon-**Modulverwaltung** das Repository `https://github.com/MCVnet/SYMCON_E3DC-Connector` hinzufügen. Der Entwicklungszweig ist `main`.
2. Im Objektbaum eine Instanz **E3/DC Connector** erstellen und **Instanz aktiv** einschalten.
3. Unter **Anlage und RSCP-Verbindung → RSCP-Zugangsdaten** die lokale RSCP-Verbindung aktivieren. Für das Offline-Konto lautet der Benutzer `local.user`. Benutzerpasswort und RSCP-Schlüssel übernehmen.
4. Die Betriebsart passend zur Anlage wählen und die IP-Adresse eintragen. Für die automatische Suche die Zugangsdaten zuerst speichern.
5. **RSCP-Verbindungen testen** und **Jetzt auslesen** ausführen. Der Verbindungsstatus und die Live-Werte stehen im Objektbaum. **Dokumentation auf GitHub** in der Instanz öffnet diese Anleitung.

### Einzelgerät

**Betriebsart → Einzelgerät (Standalone)** wählen und unter **Hauptgerät oder Farmcontroller (IP)** die Geräte-IP eingeben. Das Gerät wird über den Einzelgeräte-Port abgefragt, standardmäßig **5033**. Farmgeräte- und Farmcontroller-Einstellungen werden in dieser Betriebsart ignoriert. Wenn das Konto `local.user` am Einzelgerät abgewiesen wird, können die vom Gerät unterstützten Zugangsdaten in der gemeinsamen **RSCP-Anmeldung** eingetragen werden.

Bei der LAN-Suche wird in dieser Betriebsart nur der Einzelgeräte-Port geprüft. Wenn mehrere Geräte gefunden werden, die gewünschte IP vorgeben. Der Objektbaum zeigt unter **Einzelgeräte → Hauptgerät** die passenden Variablen und die Visualisierung.

### Farm

**Betriebsart → Farm** wählen. Den Farmmaster als Hauptgerät setzen und das weitere Hauskraftwerk unter **Zweites Farmgerät** eintragen. Die Suche prüft Einzelgeräte-Port und Farmcontroller-Port getrennt. Eine eindeutige Zuordnung wird übernommen. Mit **Jetzt auslesen** kannst du die bereits eingetragenen Adressen auch ohne erneute Suche prüfen.

| RSCP-Endpunkt | Standardport | Zweck |
| --- | --- | --- |
| Farmcontroller am Farmmaster | 5034 | Live-Gesamtwerte und Tagessummen der Anlage |
| Farmmaster als Einzelgerät | 5033 | Eigene Leistung, Batterie, Wechselrichter und Zubehör |
| Zweites Farmgerät | 5033 | Eigene Messwerte und Zubehör dieses Geräts |

Die Verbindung zum Farmmaster auf Port 5033 ist optional. Unter **RSCP-Zugangsdaten → Farmmaster als Einzelgerät** können Portal-E-Mail und Portalpasswort für diese **lokalen Leseabfragen** hinterlegt werden. Der Connector nutzt dafür den eingetragenen RSCP-Schlüssel. Für den Farmcontroller und das zweite Farmgerät gilt das gemeinsame RSCP-Konto.

**Automatisch** verwendet die vorhandene Gerätezuordnung. Bei der LAN-Suche sind zwei Antworten allein kein Hinweis darauf, dass die Geräte gemeinsam eine Farm bilden. Für abweichende Ports, Docker-Netze oder VLANs lassen sich unter **Erweiterte Verbindungseinstellungen** Suchnetz und Ports angeben. Die Suche arbeitet mit IPv4-/24-Netzen.

## Messwerte und Objektbaum

- **Farmbetrieb** enthält die Live-Bilanz, den Farmcontroller, die Einzelgeräte der Farm und die Visualisierung. Für die Farm-Gesamtleistung verwendet der Connector die Werte des Farmcontrollers.
- **Einzelgeräte** enthält im Standalone-Betrieb das Hauptgerät mit seiner Leistung, dem Speicher und der Visualisierung.
- **Zubehör und Messpunkte** enthält per RSCP erkannte Zähler, Wechselrichter und Wallboxen. Anzeigenamen und Sichtbarkeit sind in der Zubehörliste einstellbar. Ein eindeutiger Zusatzverbrauchszähler wird im Farmbetrieb für die Aufteilung des Verbrauchs verwendet. Er kann beispielsweise **Wärmepumpe** heißen.
- **System und Diagnose** zeigt Betriebsart, Einrichtung und Verbindungszustand. Externe Systeme können über vorhandene numerische Symcon-Variablen in W oder kW eingebunden werden und stehen als eigene Messpunkte zur Verfügung.

Unter **Zusätzliche Variablen** lassen sich **Tageswerte und Solarprognose**, **Gerätedetails, Phasenwerte und RSCP-Rohdaten** sowie **erweiterte Verbindungsdiagnose** einzeln aktivieren. Bei Neuinstallation sind diese Optionen aus. Wenn eine Option deaktiviert oder die Betriebsart gewechselt wird, erhält der Connector bestehende Objekte und deren Archive. Ausgeblendete Objekte können in der Verwaltungskonsole je nach Filter weiter sichtbar sein.

### Tageswerte und Solarprognose

Die Anzeige unterscheidet direkte RSCP-Tagessummen von den aus Symcon-Live-Werten integrierten kWh. Der Solarertrag bevorzugt die direkte RSCP-Tagesabfrage. Die Kennzeichnung **Datenquelle Solarproduktion** zeigt, welches Ergebnis verwendet wird. Die lokale Verbrauchs- und Netzbilanz beginnt mit dem ersten gültigen Messwert und setzt sich bei Datenlücken aus den tatsächlich erfassten Intervallen zusammen. **Tageserfassung seit** hilft beim Vergleich mit dem E3/DC-Portal.

Die Solarprognose kann über eine vorhandene numerische Symcon-Variable in **kWh** eingebunden werden. Das lokale Auslesen der E3/DC-Portalprognose ist derzeit ungeklärt.

### Batteriekapazität und Ladezustand

Der Connector liest die installierte Kapazität des Geräts aus dem EMS. Bei fehlender oder unplausibler Antwort kann unter **Installierte Batteriekapazität** ein bestätigter Wert je Hauskraftwerk in **Wh** eingetragen werden. Im Farmbetrieb hat **Farm gesamt (Wh)** Vorrang vor der Summe bestätigter Einzelwerte. Beispiel: **41,30 kWh = 41.300 Wh**.

Der Farm-Ladezustand stammt nach Möglichkeit vom Farmcontroller. Fehlt er, kann der Connector beide Gerätewerte nach ihren bestätigten Einzelkapazitäten gewichten. Die angezeigte Restenergie ist eine Schätzung aus Kapazität und Ladezustand. **Batterieindizes prüfen** liest die Indizes 0 bis 3 je Gerät zur Diagnose. Gerätenamen, Modulanzahl und Spezifikationswerte aus dieser Prüfung dienen der Zuordnung. Ein Batterieindex kann mehrere physische Batteriemodule umfassen.

## Visualisierung

### Symcon-Kachelvisualisierung

Im Ordner **Visualisierung** liegt die Webinhalt-Variable **Energiefluss**. Sie kann als eigene Kachel in Symcon platziert oder über einen Link in eine bestehende Visualisierung eingebunden werden. Die Ansicht zeigt PV, Netzleistung, Batterie und Ladezustand, Wallbox, Verbrauch und Momentanautarkie. In der Farm kann ein erkannter Zusatzverbrauchszähler wie eine Wärmepumpe separat erscheinen. Animierte Leitungen zeigen die Flussrichtung. Die Kachel zeichnet sich bei veränderten Anzeigen neu.

Unter **Visualisierung → Farbschema** stehen **Hell**, **Dunkel** und **Automatisch** zur Wahl. Eigene CSS-Regeln überschreiben die Standardwerte. Verwende `.e3dc-flow`, um die Regeln auf die Energiefluss-Kachel zu begrenzen.

```css
.e3dc-flow {
  --e3dc-bg-start: #ffffff;
  --e3dc-bg-end: #ffffff;
  --e3dc-card-tint: 18%;
}

.e3dc-flow .e3dc-value {
  font-size: 25px;
}
```

### IPSView

Unter **Visualisierung → IPSView → IPSView Energiefluss anlegen** lässt sich eine separate, bearbeitbare View erstellen. Der Connector ordnet die Variablen der aktiven Betriebsart zu und legt das Medienobjekt unter **Visualisierung** ab. Die Darstellung nutzt native IPSView-Wertfelder, Formen und Energiefluss-Linien. Ein erneuter Klick verwendet die vorhandene View. Bei einem späteren Wechsel der Betriebsart kann für die neue Betriebsart eine eigene View angelegt werden.

## Lokale Steuerung und Skriptfunktionen

**Lokale Steuerung → Lokale RSCP-Schreibbefehle freigeben** aktiviert die Schreibschnittstelle. Erst ein konkreter Aufruf oder eine Aktion sendet einen Befehl. Schreibaktionen gehen an das ausgewählte Einzelgerät und verwenden das gemeinsame RSCP-Konto. Das Gerät muss den Befehl unterstützen und das Konto zum Schreiben berechtigen.

| Funktion | Zweck |
| --- | --- |
| `E3DCF_Poll($id)` | Live-Messwerte aktualisieren |
| `E3DCF_RefreshMeasurements($id)` | Live-Werte einschließlich einer neuen Kapazitätsabfrage aktualisieren |
| `E3DCF_TestRscpConnections($id, false)` | Die konfigurierten RSCP-Verbindungen testen |
| `E3DCF_ScanRscpAccessories($id)` | Zubehör neu erkennen |
| `E3DCF_CheckBatteryIndices($id)` | Batterieindizes der Einzelgeräte lesend prüfen |
| `E3DCF_PollRscpSolar($id)` | Direkte Tagesdaten aktualisieren, sofern die Tageswerte aktiviert sind |
| `E3DCF_RefreshRscpDetails($id)` | Zubehör und aktivierte Gerätedetails aktualisieren |
| `E3DCF_CreateIpsViewEnergyFlow($id)` | Native IPSView für die aktuelle Betriebsart erstellen |
| `E3DCF_SetBatteryPower($id, $secondDevice, $watts)` | Batterie-Sollleistung in W. Positiv lädt, negativ entlädt, 0 fordert Leerlauf an. |
| `E3DCF_StopBatteryControl($id, $secondDevice)` | Gerät auf Automatik zurückstellen |
| `E3DCF_SetRscpSetting($id, $secondDevice, $setting, $value)` | Eine unterstützte Einstellung senden |
| `E3DCF_SetRscpIdlePeriod($id, $secondDevice, $type, $day, $active, $start, $end)` | Lade- oder Entladesperrzeit setzen |

`$id` ist die Symcon-Instanz-ID. `$secondDevice = false` steht für das Hauptgerät oder den Farmmaster auf seinem Einzelgeräte-Port. `true` wählt das zweite Hauskraftwerk im Farmbetrieb.

In der Instanz sind unter **RSCP-Ladeeinstellungen** unter anderem Lade- und Entladelimits, Entladeschwelle, Stromsparmodus, wetterbasiertes Laden und Wallbox-Priorisierung verfügbar. Die Unterstützung ist geräteabhängig. Die manuelle Ladung kann Netzbezug auslösen. Ein Notstromtest kann die Stromversorgung unterbrechen. Die direkte Wallbox-Ladefreigabe und der direkte Wechsel des Wallbox-Lademodus sind über den Connector derzeit nicht verfügbar.

## Fehlerdiagnose

| Symptom | Prüfen |
| --- | --- |
| Verbindung abgewiesen | Benutzerpasswort, Verschlüsselungsschlüssel, Ziel-IP und Port getrennt prüfen. |
| Farmwerte vorhanden, Einzelwerte des Masters fehlen | Den Verbindungstest für den Einzelgeräte-Port des Farmmasters prüfen und gegebenenfalls die optionale lokale Anmeldung hinterlegen. |
| Gerät fehlt in der LAN-Suche | Geräte-IP manuell eintragen. Prüfen, ob Symcon das Geräte-LAN erreicht und das richtige Suchnetz verwendet. |
| Tageswert weicht vom Portal ab | Datenquelle und Erfassungsbeginn prüfen. Berechnete Symcon-Werte können einen kürzeren Zeitraum abdecken. |
| Kapazität fehlt | Die Kapazitätsprüfung starten oder einen bestätigten Nennwert in Wh hinterlegen. |
| Zusätzliche Messwerte fehlen | Die entsprechende Option unter **Zusätzliche Variablen** einschalten und **Jetzt auslesen** ausführen. |

## Aufbau des Quellcodes

- `E3DCConnector/module.php` verwaltet die Betriebsart, Symcon-Objekte, Timer, Variablen und Schreibfreigabe.
- `E3DCConnector/form.json` definiert die Instanzkonfiguration und ihre Aktionen.
- `libs/FarmRscp.php` implementiert den lokalen RSCP-Transport, Authentifizierung, Dekodierung und Geräteabfragen.
- `libs/RscpDetailCatalog.php` enthält die Geräteabfragen und RSCP-Bezeichnungen.
- `libs/EnergyFlowTile.php` und `libs/IpsViewEnergyFlow.php` erzeugen die beiden Visualisierungen.

Autor: Matthias Vogel. Protokollreferenzen: [RSCPGui](https://github.com/rxhan/RSCPGui), [python-e3dc](https://github.com/fsantini/python-e3dc) und [ioBroker.e3dc-rscp](https://github.com/git-kick/ioBroker.e3dc-rscp).
