#!/usr/bin/perl
# randmal.pl - random MAC address generator
# Copyright (C) 2013 Ian Campbell
#
my $agpl = <<EOL;
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, either version 3 of the
    License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <a href="http://www.gnu.org/licenses/">http://www.gnu.org/licenses/</a>.
EOL

use strict;
use warnings;


my $default_scope = "local";
my %scope_labels  = (
    'local'  => "Locally Administered",
    'global' => "Globally Unique (OUI Enforced)"
);

my $default_type = "unicast";
my %type_labels  = (
    'multicast' => "Multicast",
    'unicast'   => "Unicast"
);

my $scope = 'local';
$scope = $default_scope unless $scope_labels{$scope};
my $local  = $scope eq "local";
my $global = $scope eq "global";

my $type = "unicast";
$type = $default_type unless $type_labels{$type};
my $unicast   = $type eq "unicast";
my $multicast = $type eq "multicast";

### Generate the address

my ( @bytes, @address, $warning, $oui );
$oui  = "";
if ($local) {
    @bytes = map { int( rand(256) ) } ( 0 .. 5 );

    #$warning = sprintf( "<tt>%#08b</tt>", $bytes[0] );
}
elsif ($global) {
    if ( defined $oui ) {
        @bytes = ( 0, 0, 0 );
        push @bytes, map { int( rand(256) ) } ( 0 .. 2 );

# Pairs of hex digits, seperated by any character or none. Accepts common formats:
# aa:bb:cc
# aa-bb-cc
# aabb.cc
# etc
        if ( $oui =~
            m/^\s*([[:xdigit:]]{2}).?([[:xdigit:]]{2}).?([[:xdigit:]]{2}).*/ )
        {
            $bytes[0] = hex($1);
            $bytes[1] = hex($2);
            $bytes[2] = hex($3);

            $warning = "OUI had locally administered bit set, will clear"
              if $bytes[0] & 0x2;
        }
        else {
            $warning = "Failed to parse OUI";
        }
    }
    else {
        $warning = "You need to specify an OUI " . param("oui");
    }
}
else {
    $warning = "Unknown address scope &lsquo;$scope&rsquo;";
}

$bytes[0] &= 0xfc;
$bytes[0] |= 0x1 if $multicast;
$bytes[0] |= 0x2 if $local;

printf("%02x:%02x:%02x:%02x:%02x:%02x\n", @bytes);
