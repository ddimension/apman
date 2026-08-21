#!/usr/bin/env python3
# Which DNS name a MAC address used to have. Run it ON the syslog host, where
# kea and bind log:
#
#   python3 dns-names.py 20:f1:b2:8e:a0:cf 48:55:19:17:ab:ab ...
#
# Neither log line has both halves: kea-dhcp4 names the mac and the lease it
# handed out, kea-dhcp-ddns names the lease and the fqdn it registered. This
# walks every system-app2-n*.log*, oldest first, and joins them on the address
# and the time the lease was held.
#
# Written on 2026-08-21 to identify eight IoT devices that had dropped off the
# network and were known only by their mac.
from collections import defaultdict

MACS = [m.lower() for m in sys.argv[1:]]
MON = {m: i for i, m in enumerate(
    'Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec'.split(), 1)}

def ts(line):
    p = line.split()
    if len(p) < 3: return None
    try:
        h, mi, s = p[2].split(':')
        return (MON.get(p[0], 0), int(p[1]), int(h), int(mi), int(s))
    except Exception:
        return None

files = sorted(glob.glob('/var/log/system-app2-n*.log*'),
               key=lambda f: (-int(re.search(r'\.log\.(\d+)', f).group(1))
                              if re.search(r'\.log\.(\d+)', f) else 0))
re_lease = re.compile(r'\[hwtype=1 ([0-9a-f:]{17})\].*?lease ((?:\d{1,3}\.){3}\d{1,3})')
re_alloc = re.compile(r'\[hwtype=1 ([0-9a-f:]{17})\].*?DHCPACK.*?to ((?:\d{1,3}\.){3}\d{1,3})')
re_fqdn = re.compile(r'FQDN: \[([^\]]+)\]')
re_ipa = re.compile(r'IP Address: \[((?:\d{1,3}\.){3}\d{1,3})\]')

holds = defaultdict(list)      # mac -> [(ts, ip)]
ddns = []                      # (ts, ip, fqdn, action)
direct = defaultdict(set)      # mac -> names seen on the same line

pending_fqdn = {}              # pid -> (ts, fqdn, action)

for f in files:
    op = gzip.open if f.endswith('.gz') else open
    with op(f, 'rt', errors='replace') as fh:
        for line in fh:
            t = ts(line)
            if 'kea-dhcp4' in line:
                low = line.lower()
                for m in MACS:
                    if m in low:
                        mm = re_lease.search(low) or re_alloc.search(low)
                        if mm and mm.group(1) == m:
                            holds[m].append((t, mm.group(2)))
                        for n in re.findall(r'hostname[= ]\[?([A-Za-z0-9._-]+)', line):
                            direct[m].add(n)
            elif 'kea-dhcp-ddns' in line:
                pid = line.split('kea-dhcp-ddns[')[1].split(']')[0] if 'kea-dhcp-ddns[' in line else '?'
                if 'DHCP_DDNS_ADD_SUCCEEDED' in line or 'DHCP_DDNS_REMOVE_SUCCEEDED' in line:
                    pending_fqdn[pid] = [t, None, 'add' if 'ADD' in line else 'remove']
                elif pid in pending_fqdn:
                    q = re_fqdn.search(line)
                    if q: pending_fqdn[pid][1] = q.group(1).rstrip('.')
                    r = re_ipa.search(line)
                    if r and pending_fqdn[pid][1]:
                        ddns.append((pending_fqdn[pid][0], r.group(1),
                                     pending_fqdn[pid][1], pending_fqdn[pid][2]))
                        del pending_fqdn[pid]

by_ip = defaultdict(list)
for t, ip, name, act in ddns:
    by_ip[ip].append((t, name, act))

for m in MACS:
    seen = holds[m]
    print('%s' % m)
    if not seen:
        print('    keine lease gefunden')
        continue
    ips = {}
    for t, ip in seen:
        ips.setdefault(ip, [t, t])
        ips[ip][1] = t
    names = defaultdict(int)
    for ip, (first, last) in sorted(ips.items()):
        cands = [(t, n) for t, n, a in by_ip.get(ip, [])
                 if t is not None and first is not None and last is not None
                 and first <= t <= (last[0], last[1], 23, 59, 59)]
        for t, n in cands:
            names[n] += 1
        print('    %-16s leases %s .. %s' % (
            ip,
            '%02d-%02d %02d:%02d' % (first[0], first[1], first[2], first[3]),
            '%02d-%02d %02d:%02d' % (last[0], last[1], last[2], last[3])))
    if direct[m]:
        print('    hostname im dhcp4-log: %s' % ' '.join(sorted(direct[m])))
    if names:
        for n, c in sorted(names.items(), key=lambda x: -x[1]):
            print('    -> %-40s (%d ddns-eintraege)' % (n, c))
    else:
        print('    -> kein ddns-name auf diesen adressen gefunden')
