#!/bin/sh
if [ "x$1" = "x" ]; then
	echo Missing hostname
	exit
fi

myself=$(readlink -f "$0")
home=$(dirname $myself)
cd $home/../contrib/apfiles || exit 1
tar -cz . | ssh $1 "tar -xzvC /"
exit 0

PASSWORD=$(pwgen  20 1)

set -x
ssh root@$1 "uci set rpcd.apman=login;
uci set rpcd.apman.username='apman';
uci set rpcd.apman.password=\"\$(uhttpd -m $PASSWORD)\";
uci delete rpcd.apman.read;
uci add_list rpcd.apman.read='*';
uci delete rpcd.apman.write;
uci add_list rpcd.apman.write='*';
uci commit rpcd;
/etc/init.d/rpcd restart;
/etc/init.d/apman enable;
/etc/init.d/apman start;
/etc/init.d/uhttpd restart"
bin/console --verbose apman:add-ap "$1" apman "$PASSWORD" "http://$1/ubus"
