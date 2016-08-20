#!/usr/bin/perl
# This file prepares a zip file for distribution.
# Eventually, this may upload and install everything immediately.

use strict;
use warnings;
use Carp;

my @files = qw| ZysysHosting.php includes |;

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

if (`mkdir ZysysHostingOptimizations 2>&1 3>&1` =~ /exists/) {
    croak "ERROR: The folder ZysysHostingOptimizations exists. Please remove it.\n\t";
}
foreach (@files) {
    system("cp -r $_ ZysysHostingOptimizations");
}
system ("zip -r ZysysHostingOptimizations-" . $version . ".zip ZysysHostingOptimizations/");
system("rm -R ZysysHostingOptimizations/");
