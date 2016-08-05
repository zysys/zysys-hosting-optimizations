#!/usr/bin/perl
# This file prepares a zip file for distribution.
# Eventually, this may upload and install everything immediately.

use strict;
use warnings;
use Carp;

open (ZY, 'ZysysHosting.php');
my $version = 0;
while (<ZY>) {
    if (/Version: ([\d\.]+)/) {
        $version = $1;
    }

    if (/define\('ZYSYSHOSTING_OPTIMIZATIONS_VERSION', '([\d\.]+)'/) {
        if ($version ne $1) {
            croak "ERROR: Hey, your versions aren't matching up.  Please confirm you've updated the plugin version and the constants.\n\t";
        }
    }
}
close (ZY);

system ("zip ZysysHostingOptimizations-" . $version . ".zip ZysysHosting.php includes/*");
