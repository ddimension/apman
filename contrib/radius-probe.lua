-- radius test client: builds a valid Access-Request, decrypts every
-- Tunnel-Password of the answer and prints them in hostapd's order
package.path='/usr/lib/lua/?.lua;'..package.path
local R=require('apman-radius'); local socket=require('socket')
local md5,hmac,tohex=R.md5,R.hmac_md5,R.tohex
local function u16(n) return string.char(math.floor(n/256)%256, n%256) end
local function attr(t,v) return string.char(t,#v+2)..v end
local function bxor(a,b) local r,bit=0,1
  for _=1,8 do local x,y=a%2,b%2
    if x~=y then r=r+bit end
    a=math.floor(a/2); b=math.floor(b/2); bit=bit*2 end
  return r end
local function xor(a,b) local o={} for i=1,#a do o[i]=string.char(bxor(a:byte(i),b:byte(i))) end return table.concat(o) end

local function request(secret, mac, bssid, ssid, akm)
  local auth='' for i=1,16 do auth=auth..string.char(math.random(0,255)) end
  local a=attr(1,mac)..attr(31,mac)..attr(30,bssid..':'..ssid)
  if akm then a=a..attr(188,akm) end
  local ma=attr(80,string.rep('\0',16))
  local hdr=string.char(1,42)..u16(20+#a+#ma+0)
  local pkt=hdr..auth..a..ma
  local mac_val=hmac(secret,pkt)
  return hdr..auth..a..attr(80,mac_val), auth
end

local function parse(p)
  local attrs={} local i=21
  while i<#p do local t=p:byte(i); local l=p:byte(i+1); if not l or l<2 then break end
    attrs[#attrs+1]={t=t,v=p:sub(i+2,i+l-1)}; i=i+l end
  return p:byte(1), attrs
end

local function decrypt(secret, auth, v)
  local salt=v:sub(2,3); local ct=v:sub(4)
  local out='' local prev=salt
  local b=md5(secret..auth..salt)
  for o=1,#ct,16 do
    local c=ct:sub(o,o+15)
    out=out..xor(c,b)
    b=md5(secret..c)
  end
  local n=out:byte(1)
  return out:sub(2,1+n)
end

local secret,host,port=arg[1],arg[2] or '127.0.0.1',tonumber(arg[3] or 1812)
local mac,bssid,ssid,akm=arg[4],arg[5],arg[6],arg[7]
local akmv=nil
if akm=='sae' then akmv='\0\15\172\8' elseif akm=='psk' then akmv='\0\15\172\2' end
local req,auth=request(secret,mac,bssid,ssid,akmv)
local s=socket.udp(); s:settimeout(3); s:setpeername(host,port); s:send(req)
local resp=s:receive()
if not resp then print('NO ANSWER'); os.exit(2) end
local code,attrs=parse(resp)
local names={[2]='ACCESS-ACCEPT',[3]='ACCESS-REJECT'}
local pw={} local extra={}
for _,a in ipairs(attrs) do
  if a.t==69 then pw[#pw+1]=decrypt(secret,auth,a.v)
  elseif a.t==64 then extra[#extra+1]='Tunnel-Type'
  elseif a.t==65 then extra[#extra+1]='Tunnel-Medium'
  elseif a.t==81 then extra[#extra+1]='VLAN='..a.v:sub(2)
  elseif a.t==18 then extra[#extra+1]='Reply="'..a.v..'"' end
end
print((names[code] or code)..'  passwords(packet order)='..#pw)
for i,p in ipairs(pw) do print(string.format('  [%d] %s', i, p)) end
if #pw>0 then print('  hostapd tries first: '..pw[#pw]..'   (last in packet = first in its list)') end
print('  '..table.concat(extra,' '))
