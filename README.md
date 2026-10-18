# Tuya Local

Steuert Tuya-/Smart-Life-WLAN-Geräte direkt im lokalen Netz, ohne Tuya-Cloud
und ohne Zusatzdienst. Implementiert ist das Tuya-Protokoll **3.5**
(Rahmen `0x6699`, AES-128-GCM, ausgehandelter Sitzungsschlüssel).

## Module

### Tuya Thermostat (Prefix `TUYA`)

Für WLAN-Thermostate der Tuya-Kategorie `wk`. Getestet mit „Mini Thermostat"
(Product-ID `k5hu1er7silr7miw`) an einer Handtuchheizung.

| DP | Variable | Typ |
|----|----------|-----|
| 1  | Heizung | Boolean, schaltbar |
| 2  | Modus (`comf`/`eco`) | Integer, schaltbar |
| 16 | Solltemperatur 7–30 °C | Float, schaltbar |
| 24 | Isttemperatur | Float |
| 27 | Temperaturkorrektur −5…+5 K | Integer, schaltbar |
| 40 | Kindersicherung | Boolean, schaltbar |
| alle | Datenpunkte (JSON, versteckt) | String |

Jede Abfrage öffnet eine kurze TCP-Sitzung auf Port 6668. Änderungen am Gerät
oder in der App sieht Symcon also erst bei der nächsten Abfrage
(Standard: alle 30 s).

Funktionen:

```php
TUYA_Update(int $InstanceID): bool
TUYA_SetPower(int $InstanceID, bool $Value): bool
TUYA_SetSetpoint(int $InstanceID, float $Value): bool
TUYA_SetDP(int $InstanceID, int $DP, string $JsonValue): bool   // z. B. TUYA_SetDP($id, 2, '"eco"')
```

## Device-ID und Local Key besorgen

Ohne Tuya-Entwicklerkonto, über denselben QR-Login, den Home Assistant nutzt:

1. In Smart Life: **Ich → Zahnrad → Konto und Sicherheit → User Code** ablesen.
2. Auf einem Rechner mit Python:
   ```sh
   python3 -m venv venv && venv/bin/pip install tuya-device-sharing-sdk qrcode pillow
   venv/bin/python tools/tuya_login.py <UserCode> <Ausgabeordner>
   ```
3. Den angezeigten QR-Code in Smart Life scannen (Scan-Symbol oben rechts)
   und bestätigen.
4. `<Ausgabeordner>/tuya_devices.json` enthält je Gerät `id`, `local_key` und
   die Datenpunktbeschreibung. **Die Datei enthält Schlüssel, nicht weitergeben.**

Die IP findet `python -m tinytuya scan` (lauscht auf die Broadcasts an UDP
6666/6667/7000) oder die DHCP-Liste des Routers. Der Local Key ändert sich,
wenn das Gerät neu in der App angelernt wird.

## Test ohne Symcon

```sh
php tests/live_test.php <ip> <local_key>                  # Status lesen
php tests/live_test.php <ip> <local_key> '{"1":false}'    # ausschalten
```

## Beispiel: Handtuchheizung nach dem Duschen

`examples/Handtuchheizung_nach_dem_Duschen.php` ist ein Ereignis-Skript, das die
Heizung an eine Dusch-Szene koppelt: morgens Vorheizen ab Szenenstart, sonst
Start bei „Wasser aus“ (erkannt am fallenden Warmwasserspeicher bzw. an der
Feuchtespitze), danach Trockenzeit abzüglich Wärmenachlauf. Wird die Heizung
außerhalb eines Laufs eingeschaltet, geht sie sofort wieder aus. Die Objekt-IDs
im Skriptkopf gehören zur FACE-Dev-Anlage und müssen angepasst werden.
