# MikroTik Chateau: das 2,4-GHz-Radio sendet, empfängt aber nichts

Gemessen am 17. August 2026. Alle Zahlen stammen vom Gerät selbst, nicht aus einer
Dokumentation. Die zweite Hälfte beschreibt die Testumgebung, mit der ein Fix sofort
geprüft werden kann.

Gleicher Inhalt als Seite: <https://claude.ai/code/artifact/ef4f3909-4e89-44d7-8721-c266df40b0e8>

## Kurzfassung

Das Gerät beacont auf 2,4 GHz einwandfrei — andere Geräte sehen die BSS mit −57 bis
−80 dBm. Es empfängt aber auf keiner AP- oder Station-Schnittstelle ein einziges Frame.
Als Station schickt es Auth-Frames los und bekommt nie eine Antwort, als AP sieht es die
Auth-Frames der Clients nicht. Ein **Monitor-Interface auf derselben phy empfängt
dagegen problemlos** — Funkstrecke und Empfänger arbeiten, die Frames erreichen nur nie
eine normale vdev.

| Messwert | Wert |
|---|---|
| AP-vdev `rx_packets` | **0** |
| AP-vdev `tx_packets` | 994 |
| Monitor-vdev, 15 s | **1134** |
| `channel receive time` | **0 ms** |
| `channel busy time` | 1085109 ms |
| RX-Fehlerzähler (`soc_dp_stats`) | alle 0 |

## 1. Gerät und Build

| | |
|---|---|
| Modell | MikroTik S53UG+5HaxD2HaxD&RG650E-EU (Chateau 5G R17 ax), `mikrotik,chateau-5g-r17-ax` |
| Build | OpenWrt SNAPSHOT r236+2-b9411790fc, Target `qualcommax/ipq60xx`, Kernel 6.18.41 |
| Treiber | kmod-ath11k 6.18.41.6.18.39-r3, kmod-ath11k-ahb, SoC `c000000.wifi` (ipq6018 hw1.0) |
| Firmware | ath11k-firmware-ipq6018 2024.10.14~15f05012-r1, fw_version 0x25008f8e, WLAN.HK.2.5.0.1-03982-QCAHKSWPL_SILICONZ-3 (2024-03-01) |
| Board | ipq-wifi-mikrotik_chateau-5g-r17-ax 2026.05.18~e20f4c6f-r2, `/lib/firmware/ath11k/IPQ6018/hw1.0/board-2.bin`, 65652 Bytes |
| phy0 | 5 GHz, MAC 04:f4:1c:6b:52:83 — ungenutzt |
| phy1 | 2,4 GHz, MAC 04:f4:1c:6b:52:84 — betroffen |
| Zugang | `ssh root@192.168.203.245`, Root ohne Passwort |

Beide phys hängen am selben Gerät `c000000.wifi`; in ath11k sind das `mac0` (5 GHz) und
`mac1` (2,4 GHz), in debugfs unter
`/sys/kernel/debug/ath11k/ahb-c000000.wifi/mac0` beziehungsweise `…/mac1`.

## 2. Die entscheidende Messung

Der Betriebskanal wird als zu 99 % belegt gemeldet, gesendet wird auch — die
Empfangszeit steht exakt auf null:

```
# iw dev phy1-ap0 survey dump   (nach ~18 Minuten Betrieb)
	frequency:              2462 MHz [in use]
	noise:                  -87 dBm
	channel active time:    1091976 ms
	channel busy time:      1085109 ms
	channel receive time:   0 ms
	channel transmit time:  1986 ms
```

Ein Monitor-Interface auf derselben phy empfängt im selben Moment ohne Mühe:

```
# iw phy phy1 interface add mon1 type monitor && ip link set mon1 up && sleep 15
mon1      rx_packets=1134   tx_packets=0
phy1-ap0  rx_packets=0      tx_packets=1068
phy1-ap1  rx_packets=0      tx_packets=1068
```

Der Datenpfad des Treibers meldet dabei keinen einzigen Fehler — es wird also nichts
verworfen, es kommt gar nicht erst an:

```
# cat /sys/kernel/debug/ath11k/ahb-c000000.wifi/soc_dp_stats
SOC RX STATS:
err ring pkts: 0          Invalid RBM: 0
RXDMA errors:  Overflow 0, MPDU len 0, FCS 0, Decrypt 0, TKIP MIC 0,
               Unencrypt 0, MSDU len 0, MSDU limit 0, WiFi parse 0,
               AMSDU parse 0, SA timeout 0, DA timeout 0, Flow timeout 0
REO errors:    alle 0 (Desc inval, PN check fail, 2k err, …)
HAL REO errors: ring0: 0
```

## 3. Wie es sich in beiden Rollen zeigt

### Als Station

Der Scan findet alles — auch entfernte APs mit −80 dBm —, die Authentifizierung läuft
dann jedes Mal in denselben Timeout, gegen *jeden* AP, auf Kanal 1 wie auf Kanal 11:

```
phy1-sta0: SME: Trying to authenticate with 9c:9d:7e:75:ba:33 (SSID='radtest2' freq=2462 MHz)
phy1-sta0: authenticate with 9c:9d:7e:75:ba:33 (local address=04:f4:1c:6b:52:84)
phy1-sta0: send auth to 9c:9d:7e:75:ba:33 (try 1/3)
phy1-sta0: send auth to 9c:9d:7e:75:ba:33 (try 2/3)
phy1-sta0: send auth to 9c:9d:7e:75:ba:33 (try 3/3)
phy1-sta0: authentication with 9c:9d:7e:75:ba:33 timed out
```

Auf der Gegenseite protokolliert hostapd **nichts** — kein `IEEE 802.11: authenticated`,
keine abgewiesene Station.

### Als Access Point

hostapd meldet sauberen Start, andere Geräte sehen die BSS im Scan und versuchen sich
anzumelden — die Empfangszähler bleiben auf null:

```
hostapd: phy1-ap0: interface state COUNTRY_UPDATE->ENABLED
hostapd: phy1-ap0: AP-ENABLED
hostapd: phy1-ap0: RADIUS Authentication server 192.168.203.38:1812

# ubus call hostapd.phy1-ap0 get_status
{ "status": "ENABLED", "bssid": "04:f4:1c:6b:52:84", "ssid": "radtest2",
  "freq": 2462, "channel": 11, "op_class": 81 }

Ein zweites Gerät sieht die BSS im Scan:
radtest2  04:f4:1c:6b:52:84  2462.0 MHz  -80.00 dBm
radtest3  06:f4:1c:6b:52:84  2462.0 MHz  -80.00 dBm

phy1-ap0 rx_packets=0  tx_packets=994  rx_errors=0  rx_dropped=0
```

## 4. Was ausgeschlossen ist

Alles hier wurde geprüft und ändert nichts am Bild:

* **Regulierung.** Anfangs stand `country='00'`, und der Treiber meldete beim Booten
  `No regulatory rules available in the event info` / `failed to extract regulatory
  info`. Mit `country=DE` und einem Neustart sind diese Meldungen weg, `iw phy1 reg get`
  liefert `country DE: DFS-ETSI` mit `(2402 - 2482 @ 40)` ohne NO-IR, die Kanäle 1/6/11
  stehen mit 20 dBm da. Der Empfang bleibt trotzdem null. wireless-regdb ist installiert;
  phy1 meldet sich als self-managed.
* **Der Kanal.** Kanal 1 und Kanal 11 verhalten sich gleich, `channel='auto'` ebenso.
* **Die Gegenstelle.** Als Station scheitert es an drei verschiedenen APs (ath10k und
  ath11k), von denen zwei produktiv Dutzende Clients tragen. Als AP scheitern zwei
  verschiedene Clients an ihm — einer davon verbindet sich unmittelbar davor und danach
  mit anderen APs.
* **Die Verschlüsselung.** psk2, sae und offen verhalten sich gleich. Auch ohne RADIUS,
  ohne `ppsk`, ohne PMF.
* **hostapd/wpa_supplicant.** Sowohl `wpad-basic-mbedtls` als auch `wpad-mbedtls`
  (2026.08.07~831364bf-r2) — gleiches Verhalten.
* **Neustart.** Ein vollständiger Reboot mit korrekt gesetztem Ländercode ändert nichts.
* **MAC-Adressen.** Hardware-MAC und von netifd abgeleitete Adressen (`06:f4:…`) —
  gleiches Verhalten.

## 5. Wo im Treiber zu suchen ist

Die Kombination aus *Monitor empfängt*, *vdev empfängt nicht*, *keine Fehlerzähler* und
*channel receive time = 0* engt es stark ein. Der Monitor-Pfad hängt am
`mon_dst`/Status-Ring, der normale Pfad an REO — was funktioniert, ist der eine, was
fehlt, der andere.

1. **Ist es die Firmware-Statistik oder wirklich der Empfang?** `channel receive time`
   kommt aus den WMI-pdev-Statistiken (`rx_clear_count`). Steht die auf null, während
   busy hochzählt, hat entweder die Firmware für dieses pdev keine RX-Kette scharf, oder
   die Statistik wird dem falschen pdev zugeordnet. Beides zeigt auf die
   **pdev-/mac-Index-Zuordnung für mac1**.
2. **Die HTT-RX-Ring-Konfiguration je pdev.** `ath11k_dp_tx_htt_rx_filter_setup()` und
   `ath11k_dp_rx_pdev_alloc()` / `…_reo_setup()` bekommen eine `mac_id`. Vergleich von
   `ar->pdev_idx`, `ar->pdev->pdev_id` und der an die Firmware geschickten `mac_id`.
3. **Die Peer-/vdev-Zuordnung im RX-Pfad.** `ath11k_dp_rx_process_received_packets()` →
   `ath11k_dp_rx_h_find_peer()` → `ath11k_dp_rx_deliver_msdu()`. Wird die peer_id nie
   aufgelöst, verschwinden Frames ohne Fehlerzähler — genau das Bild. `ext_rx_stats` und
   `htt_stats` liegen in debugfs bereit: `echo 1 > …/mac1/ext_rx_stats`, dann
   `htt_stats_type` setzen und `htt_stats` lesen.
4. **Das Board-File.** `board-2.bin` kommt aus `ipq-wifi-mikrotik_chateau-5g-r17-ax`. Ist
   die Variante für die 2,4-GHz-Kette falsch oder unvollständig, sendet das Gerät
   (Beacons über die Standard-TX-Kette) und empfängt nichts. Der Bootlog zeigt
   `chip_id 0x0 chip_family 0x4 board_id 0xff soc_id 0xffffffff` — `board_id 0xff` ist
   der Wert, den die Firmware meldet, wenn sie keine gültige Board-ID hat.
5. **Regression eingrenzen.** Der Build ist ein Snapshot mit Kernel 6.18.41 und einem
   sehr neuen mac80211-Backport. Ein älterer Snapshot oder ein Release-Build auf demselben
   Gerät beantwortet in einer halben Stunde, ob es eine Regression ist — die wertvollste
   einzelne Information für alles Weitere.

## 6. Nebenbefund: das 5-GHz-Radio startet gar nicht

In der Standardkonfiguration steht `wireless.radio0.band='6g'`, die phy kann aber nur
5 GHz (5180–5875 MHz). Mit korrigiertem `band='5g'`, Kanal 36 und HE80 kommt hostapd
trotzdem nicht hoch:

```
hostapd: phy0-ap0: interface state UNINITIALIZED->COUNTRY_UPDATE
hostapd: phy0-ap0: interface state COUNTRY_UPDATE->DISABLED
hostapd: phy0-ap0: AP-DISABLED
hostapd: phy0-ap0: Unable to setup interface.
hostapd: hostapd_free_hapd_data: Interface phy0-ap0 wasn't started
hostapd: hostapd.add_iface failed for phy phy0 ifname=phy0-ap0
```

Ein zweiter, möglicherweise verwandter Fehler — beide macs sitzen auf demselben
SoC-Gerät. Nicht weiter verfolgt; das falsche `band='6g'` steht noch so in der
Konfiguration und sollte auf `5g`. Kommt mac0 nach einem Fix sauber hoch und empfängt,
mac1 aber nicht, ist die pdev-Zuordnung aus Punkt 2 der erste Verdächtige.

## 7. Reproduktion in fünf Minuten

1. AP-Rolle aufsetzen (Konfiguration liegt bereits auf dem Gerät, SSIDs `radtest2` und
   `radtest3` auf `radio1`):
   `uci set wireless.radio1.disabled='0'; uci commit wireless; wifi reload radio1`
2. Von einem anderen Gerät die BSS suchen — sie ist da:
   `iw dev <sta> scan | grep -A2 'SSID: radtest2'`
3. Mit diesem Gerät verbinden — es scheitert im Auth-Timeout, und auf dem Chateau steht
   dazu nichts im Log.
4. Den Empfangszähler lesen, das ist der Beweis:
   `cat /sys/class/net/phy1-ap0/statistics/rx_packets` bleibt 0,
   `…/tx_packets` zählt hoch.
5. Gegenprobe mit dem Monitor — derselbe Funk, andere Zustellung:
   `iw phy phy1 interface add mon1 type monitor; ip link set mon1 up; sleep 15;
   cat /sys/class/net/mon1/statistics/rx_packets` (> 1000), danach `iw dev mon1 del`.

## 8. Die Testumgebung, die bereitsteht

Für den Funktest muss nichts aufgebaut werden. Zwei APs strahlen dieselben beiden
Test-SSIDs ab, ein Client steht bereit, und der apman-RADIUS-Server beantwortet die
Anfragen mit gerätespezifischen Schlüsseln. Jede Anmeldung — auch jede gescheiterte —
landet mit Zeitstempel und Antwortzeit im Controller.

| Rolle | Gerät | Was dort läuft |
|---|---|---|
| Test-AP 1 | ap-av-attic | `radtest2` + `radtest3` auf `radio0` (ath10k, 2,4 GHz, Kanal 11), 802.11r, NAS-Id `attic` — funktioniert |
| Test-AP 2 | ap-av-klwz | dieselben zwei SSIDs auf `radio0` (ath10k, 2,4 GHz, Kanal 11), NAS-Id `klwz` — funktioniert |
| Test-AP 3 | chateau, 192.168.203.245 | dieselben zwei SSIDs auf `radio1`, NAS-Id `chateau` — empfängt nichts |
| Testclient | ap-av-boden, 192.168.203.246 | `wireless.sta` auf `radio0` (2,4 GHz), Netz `radtest` mit `proto='none'` — kein DHCP, keine Routen. MAC `00:0c:43:26:60:18` |
| Controller | app1, 192.168.203.38 | apman mit eigenem RADIUS-Server auf Port 1812, Weboberfläche unter `/apman/radius` |

### Die Schlüssel

Netz-Passphrase beider Test-SSIDs ist `netz-passphrase-test`; wer keinen eigenen
Schlüssel hat, bekommt sie vom Controller als Fallback. Eigene Schlüssel:

| SSID | Gerät | Schlüssel | Identität |
|---|---|---|---|
| radtest2 (WPA2) | 00:0c:43:26:60:18 (boden) | `boden-eigener-schluessel` | `boden-wpa2` |
| radtest3 (WPA3/SAE) | 00:0c:43:26:60:18 (boden) | `boden-sae-schluessel` | `boden-sae` |
| radtest2 | 04:f4:1c:6b:52:84 (chateau) | `chateau-wpa2-schluessel` | `chateau-wpa2` |
| radtest2 | beliebig | `wpa2-test-schluessel` | `test-wpa2` |
| radtest3 | beliebig | `wpa3-test-schluessel` | `test-wpa3` |

Für das Chateau liegt also bereits ein eigener WPA2-Schlüssel bereit: sobald der Treiber
empfängt, verbindet es sich als Station mit `chateau-wpa2-schluessel` und taucht im
Controller mit der Identität `chateau-wpa2` auf. Der gemeinsame Schlüssel des
RADIUS-Servers steht auf app1 in `/usr/local/share/apman/.env.local` unter
`RADIUS_SECRET` und ist auf den Test-APs als `wireless.radtest2.auth_secret` hinterlegt.

### Prüfen, ob es geklappt hat

Auf dem Gerät:

```
uci set wireless.sta.ssid='radtest2'
uci set wireless.sta.encryption='psk2'
uci set wireless.sta.key='chateau-wpa2-schluessel'
uci commit wireless; wifi reload

iwinfo | grep -E 'ESSID|Access Point|Signal|Encryption'
cat /sys/class/net/phy1-*/statistics/rx_packets
```

Im Controller — hier steht, ob die Anfrage angekommen ist und was geantwortet wurde:

```
ssh root@app1 'cd /usr/local/share/apman && sudo -u www-data php8.2 bin/console \
  --env prod doctrine:query:sql \
  "SELECT created, ssid_name, nas, result, reason, keyid, ROUND(duration_ms,1) ms \
   FROM radius_auth ORDER BY id DESC LIMIT 5"'
```

Oder im Browser unter `http://app1/apman/radius`.

So sieht ein erfolgreicher Lauf aus, gemessen mit ap-av-boden an ap-av-attic:

```
hostapd: phy0-ap0: STA 00:0c:43:26:60:18 IEEE 802.11: authenticated
hostapd: phy0-ap0: STA 00:0c:43:26:60:18 IEEE 802.11: associated (aid 1)
hostapd: phy0-ap0: AP-STA-CONNECTED 00:0c:43:26:60:18 auth_alg=open
hostapd: phy0-ap0: EAPOL-4WAY-HS-COMPLETED 00:0c:43:26:60:18

Controller:  04:12:16  radtest2  attic  accept  own key  boden-wpa2  4.8 ms
```

## 9. Abnahme, wenn der Fix steht

1. `rx_packets` der AP-vdev zählt hoch, und `channel receive time` im Survey ist nicht
   mehr null.
2. Chateau als **AP**: ap-av-boden verbindet sich mit `boden-eigener-schluessel` auf
   `radtest2`; im Controller erscheint eine Zeile mit `nas=chateau`, `result=accept`,
   `keyid=boden-wpa2`.
3. Chateau als **Station**: mit `chateau-wpa2-schluessel` an `radtest2` von attic oder
   klwz — Controller-Zeile mit `keyid=chateau-wpa2`.
4. WPA3 gegenprüfen: `radtest3`, `encryption='sae'`, `ieee80211w='2'`, Schlüssel
   `boden-sae-schluessel` beziehungsweise ein neu angelegter fürs Chateau.
5. Roaming: mit drei APs auf derselben Mobility Domain `4f57` und
   `ft_psk_generate_local` sollte ein Client zwischen attic, klwz und chateau wechseln —
   jeder Wechsel hinterlässt eine eigene Controller-Zeile mit dem NAS des neuen APs.

---

Die Test-SSIDs und Schlüssel sind Wegwerfwerte und können nach dem Fix entfernt werden;
dsl-modem wurde bereits vollständig zurückgesetzt.
