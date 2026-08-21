-- How long the access point's own RADIUS server takes to answer, measured from
-- a client on the same box. Run it ON an access point:
--
--   lua contrib/radius-rtt.lua "$(uci -q get apman.main.radius_secret)" \
--       <mac> <bssid> <ssid> [count]
--
-- The number matters because macaddr_acl=2 puts this round trip in front of
-- the authentication frame, and a station gives up after three tries in about
-- 330 ms. Anything much above a millisecond is a bug, not a fact of life: the
-- agent used to spend 40-60 ms hashing in pure lua, and does 0.35-1.4 ms with
-- the lua-md5 package installed.
--
-- The request is built once, so the client's own hashing stays outside the
-- measurement and what is timed is the server.
local R=require('apman-radius'); local socket=require('socket')
local hmac=R.hmac_md5
local function u16(n) return string.char(math.floor(n/256)%256, n%256) end
local function attr(t,v) return string.char(t,#v+2)..v end
local secret,mac,bssid,ssid,n = arg[1],arg[2],arg[3],arg[4],tonumber(arg[5] or 20)
local auth='' for i=1,16 do auth=auth..string.char(math.random(0,255)) end
local a=attr(1,mac)..attr(31,mac)..attr(30,bssid..':'..ssid)..attr(188,'\0\15\172\8')
local ma=attr(80,string.rep('\0',16))
local hdr=string.char(1,42)..u16(20+#a+#ma)
local req=hdr..auth..a..attr(80,hmac(secret,hdr..auth..a..ma))
local s=socket.udp(); s:settimeout(3); s:setpeername('127.0.0.1',1812)
local t={}
for i=1,n do
  local t0=socket.gettime(); s:send(req); local r=s:receive()
  if not r then print('NO ANSWER'); os.exit(2) end
  t[#t+1]=(socket.gettime()-t0)*1000
end
table.sort(t)
print(string.format('n=%d  min=%.3f ms  median=%.3f ms  max=%.3f ms  code=%s',
  n, t[1], t[math.floor(#t/2)+1], t[#t], 'ok'))
