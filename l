ri Aug 21 19:44:42 2026 daemon.info apman-status[8826]:
Fri Aug 21 19:44:42 2026 daemon.info apman-status[8826]: ctrl wap-kc3 ok: OK
Fri Aug 21 19:44:42 2026 daemon.info apman-status[8826]:
Fri Aug 21 19:44:45 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: disassociated
Fri Aug 21 19:44:46 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: deauthenticated due to inactivity (timer DEAUTH/REMOVE)
Fri Aug 21 19:44:49 2026 daemon.notice hostapd: wap-knet2: AP-STA-DISCONNECTED e2:9e:3f:09:7e:90
Fri Aug 21 19:44:49 2026 daemon.info hostapd: wap-knet2: STA e2:9e:3f:09:7e:90 IEEE 802.11: disassociated
Fri Aug 21 19:44:51 2026 daemon.info hostapd: wap-knet2: STA e2:9e:3f:09:7e:90 IEEE 802.11: deauthenticated due to inactivity (timer DEAUTH/REMOVE)
Fri Aug 21 19:44:53 2026 daemon.info hostapd: wap-fl1: STA dc:e5:5b:00:3f:35 IEEE 802.11: authenticated
Fri Aug 21 19:44:53 2026 daemon.info hostapd: wap-fl1: STA dc:e5:5b:00:3f:35 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:44:53 2026 daemon.notice hostapd: wap-fl1: CTRL-EVENT-EAP-STARTED dc:e5:5b:00:3f:35
Fri Aug 21 19:44:53 2026 daemon.notice hostapd: wap-fl1: CTRL-EVENT-EAP-PROPOSED-METHOD vendor=0 method=1
Fri Aug 21 19:44:53 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:44:53 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:44:53 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:44:54 2026 daemon.notice hostapd: wap-fl1: CTRL-EVENT-EAP-FAILURE2 dc:e5:5b:00:3f:35
Fri Aug 21 19:44:54 2026 daemon.warn hostapd: wap-fl1: STA dc:e5:5b:00:3f:35 IEEE 802.1X: authentication failed - EAP type: 13 (TLS)
Fri Aug 21 19:44:54 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:44:55 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:44:56 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:44:59 2026 daemon.info hostapd: wap-fl1: STA dc:e5:5b:00:3f:35 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:45:00 2026 cron.err crond[2026]: USER root pid 15690 cmd msmtp-runqueue.sh >/dev/null 2>&1
Fri Aug 21 19:45:02 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=0227e2fe6dfe bssid=202061320db6 ssid=kalclients akm=nil
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: RADIUS Received RADIUS message
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: STA 02:27:e2:fe:6d:fe RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius accept 02:27:e2:fe:6d:fe key=ppsk_140_429 bss=20:20:61:32:0d:b6 ssid=kalclients
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc2: STA 02:27:e2:fe:6d:fe IEEE 802.11: start SAE authentication (RX commit, status=126 (SAE_HASH_TO_ELEMENT))
Fri Aug 21 19:45:03 2026 daemon.notice hostapd: wap-kc2: STA 02:27:e2:fe:6d:fe IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=0227e2fe6dfe bssid=20206172bb8f ssid=kalclients akm=nil
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: RADIUS Received RADIUS message
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: STA 02:27:e2:fe:6d:fe RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius accept 02:27:e2:fe:6d:fe key=ppsk_140_429 bss=20:20:61:72:bb:8f ssid=kalclients
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: STA 02:27:e2:fe:6d:fe IEEE 802.11: start SAE authentication (RX commit, status=0 (SUCCESS))
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc3: STA 02:27:e2:fe:6d:fe IEEE 802.11: SAE authentication (RX confirm, status=0 (SUCCESS))
Fri Aug 21 19:45:03 2026 daemon.notice hostapd: wap-kc3: STA 02:27:e2:fe:6d:fe IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=0227e2fe6dfe bssid=20206177d855 ssid=kalclients akm=nil
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: RADIUS Received RADIUS message
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:45:03 2026 daemon.info apman-status[8826]: radius accept 02:27:e2:fe:6d:fe key=ppsk_140_429 bss=20:20:61:77:d8:55 ssid=kalclients
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: start SAE authentication (RX commit, status=0 (SUCCESS))
Fri Aug 21 19:45:03 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: SAE authentication (RX confirm, status=0 (SUCCESS))
Fri Aug 21 19:45:03 2026 daemon.notice hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:10 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: authenticated
Fri Aug 21 19:45:13 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: authenticated
Fri Aug 21 19:45:13 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: associated (aid 1)
Fri Aug 21 19:45:13 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:45:14 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:45:15 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:45:16 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:45:22 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:45:22 2026 daemon.notice hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc2: STA 02:27:e2:fe:6d:fe IEEE 802.11: start SAE authentication (RX commit, status=126 (SAE_HASH_TO_ELEMENT))
Fri Aug 21 19:45:33 2026 daemon.notice hostapd: wap-kc2: STA 02:27:e2:fe:6d:fe IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:33 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: disassociated
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:45:33 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=0227e2fe6dfe bssid=20206177d855 ssid=kalclients akm=nil
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:45:33 2026 daemon.info apman-status[8826]: radius accept 02:27:e2:fe:6d:fe key=ppsk_140_429 bss=20:20:61:77:d8:55 ssid=kalclients
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: RADIUS Received RADIUS message
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.13 sec
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: start SAE authentication (RX commit, status=0 (SUCCESS))
Fri Aug 21 19:45:33 2026 daemon.debug hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: SAE authentication (RX confirm, status=0 (SUCCESS))
Fri Aug 21 19:45:33 2026 daemon.notice hostapd: wap-kc1: STA 02:27:e2:fe:6d:fe IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:45:34 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: deauthenticated due to inactivity (timer DEAUTH/REMOVE)
Fri Aug 21 19:45:37 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:45:48 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: authenticated
Fri Aug 21 19:45:48 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: associated (aid 1)
Fri Aug 21 19:45:48 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:45:49 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:45:50 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:45:51 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:45:54 2026 authpriv.info dropbear[15767]: Child connection from 10.0.251.11:42296
Fri Aug 21 19:45:54 2026 authpriv.info dropbear[15767]: Exit before auth from <10.0.251.11:42296>: Error reading: Connection reset by peer
Fri Aug 21 19:45:55 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: authenticated
Fri Aug 21 19:45:57 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:45:57 2026 daemon.debug hostapd: wap-kc1: WPA rekeying GTK
Fri Aug 21 19:45:59 2026 daemon.debug hostapd: wap-kc2: WPA rekeying GTK
Fri Aug 21 19:46:00 2026 daemon.debug hostapd: wap-kc3: WPA rekeying GTK
Fri Aug 21 19:46:09 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: authenticated
Fri Aug 21 19:46:10 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:46:10 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:46:10 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:46:11 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:46:12 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:46:12 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:46:12 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 5)
Fri Aug 21 19:46:12 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:13 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:14 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:15 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:18 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:46:21 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:46:29 2026 daemon.notice hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:46:30 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:46:30 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:46:30 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:31 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: authenticated
Fri Aug 21 19:46:31 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:32 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:32 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: authenticated
Fri Aug 21 19:46:32 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: associated (aid 6)
Fri Aug 21 19:46:32 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:46:33 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:33 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:46:34 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: authenticated
Fri Aug 21 19:46:34 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: associated (aid 7)
Fri Aug 21 19:46:34 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:46:34 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:46:35 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:46:35 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:46:36 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:46:37 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:46:39 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:46:41 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: disassociated
Fri Aug 21 19:46:41 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:46:42 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: deauthenticated due to inactivity (timer DEAUTH/REMOVE)
Fri Aug 21 19:46:43 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:46:53 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:46:53 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:46:53 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:54 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:55 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:46:56 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:02 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:47:14 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: authenticated
Fri Aug 21 19:47:19 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:47:19 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 1)
Fri Aug 21 19:47:19 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:20 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:21 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:22 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=9a65967ac7b2 bssid=202061320db6 ssid=kalclients akm=nil
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius accept 9a:65:96:7a:c7:b2 key=ppsk_140_435 bss=20:20:61:32:0d:b6 ssid=kalclients
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: RADIUS Received RADIUS message
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: STA 9a:65:96:7a:c7:b2 RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc2: STA 9a:65:96:7a:c7:b2 IEEE 802.11: start SAE authentication (RX commit, status=126 (SAE_HASH_TO_ELEMENT))
Fri Aug 21 19:47:23 2026 daemon.notice hostapd: wap-kc2: STA 9a:65:96:7a:c7:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=9a65967ac7b2 bssid=20206172bb8f ssid=kalclients akm=nil
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: RADIUS Received RADIUS message
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: STA 9a:65:96:7a:c7:b2 RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius accept 9a:65:96:7a:c7:b2 key=ppsk_140_435 bss=20:20:61:72:bb:8f ssid=kalclients
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: STA 9a:65:96:7a:c7:b2 IEEE 802.11: start SAE authentication (RX commit, status=0 (SUCCESS))
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc3: STA 9a:65:96:7a:c7:b2 IEEE 802.11: SAE authentication (RX confirm, status=0 (SUCCESS))
Fri Aug 21 19:47:23 2026 daemon.notice hostapd: wap-kc3: STA 9a:65:96:7a:c7:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: RADIUS Sending RADIUS message to authentication server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: RADIUS Next RADIUS client retransmit in 3 seconds
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius-debug psk=j5cd-wvcf-xk9u-6vp8 from=per-mac n=2 mac=9a65967ac7b2 bssid=20206177d855 ssid=kalclients akm=nil
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: RADIUS Received 112 bytes from RADIUS server
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: RADIUS Received RADIUS message
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: STA 9a:65:96:7a:c7:b2 RADIUS: Received RADIUS packet matched with a pending request, round trip time 0.00 sec
Fri Aug 21 19:47:23 2026 daemon.info apman-status[8826]: radius accept 9a:65:96:7a:c7:b2 key=ppsk_140_435 bss=20:20:61:77:d8:55 ssid=kalclients
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: STA 9a:65:96:7a:c7:b2 IEEE 802.11: start SAE authentication (RX commit, status=0 (SUCCESS))
Fri Aug 21 19:47:23 2026 daemon.debug hostapd: wap-kc1: STA 9a:65:96:7a:c7:b2 IEEE 802.11: SAE authentication (RX confirm, status=0 (SUCCESS))
Fri Aug 21 19:47:23 2026 daemon.notice hostapd: wap-kc1: STA 9a:65:96:7a:c7:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:47:28 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:47:40 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: authenticated
Fri Aug 21 19:47:40 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: associated (aid 1)
Fri Aug 21 19:47:40 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:47:41 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:47:42 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:47:43 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: authenticated
Fri Aug 21 19:47:43 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: associated (aid 5)
Fri Aug 21 19:47:43 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:43 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:17:ab:ab
Fri Aug 21 19:47:44 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:45 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:46 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH ec:fa:bc:6e:fd:31
Fri Aug 21 19:47:49 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:17:ab:ab IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:47:51 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: authenticated
Fri Aug 21 19:47:51 2026 daemon.info hostapd: wap-knet0: STA 48:55:19:18:55:ae IEEE 802.11: associated (aid 1)
Fri Aug 21 19:47:51 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:47:52 2026 daemon.info hostapd: wap-knet0: STA ec:fa:bc:6e:fd:31 IEEE 802.11: deauthenticated due to local deauth request
Fri Aug 21 19:47:52 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:47:53 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:47:54 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH 48:55:19:18:55:ae
Fri Aug 21 19:47:55 2026 daemon.notice hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: did not acknowledge authentication response
Fri Aug 21 19:47:55 2026 daemon.info hostapd: wap-knet0: STA a4:e5:7c:1e:92:b2 IEEE 802.11: associated (aid 5)
Fri Aug 21 19:47:55 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:47:56 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:47:57 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
Fri Aug 21 19:47:58 2026 daemon.notice hostapd: wap-knet0: AP-STA-POSSIBLE-PSK-MISMATCH a4:e5:7c:1e:92:b2
