#!/usr/bin/perl
# This file prepares a zip file for distribution.
# Eventually, this may upload and install everything immediately.

use strict;
use warnings;
use Carp;
use Path::Tiny qw( path );
use File::Compare;
use File::Copy;

# Tasks:
#   1) identify the version information stored in VERSION and apply it to the script, subbing the contents of the VERSION file with the {VERSION}
#   2) identify all the require(), require_once(), and includes() and include them via concatenation...note: remove starting <?php and ?> from file.

my $orig_staging = 'ZysysHosting.php';
my $version_file = "VERSION";
my $deploy_script = "ZysysHosting-deploy.php";



chomp(my $version = slurp($version_file));
my $tmp_file_id = 0; #temp 

my $staging = $orig_staging;
my $repeat = 1;
while ($repeat == 1) {
    open (ZY, $staging);
    open (ZYOUT, ">.$deploy_script.makezytmp$tmp_file_id");
    while (<ZY>) {
        my $line = $_;
        if (/(require|include)(_once){0,1}\(['"](.*)["']\);/) {
            $line = slurp($3);
            $line =~ s/^<\?(php)*//;
            $line =~ s/\?>$//;
        }
        $line =~ s/{VERSION}/$version/g;
        print ZYOUT $line;
    }
    close (ZY);
    close(ZYOUT);
    if (compare($staging, ".$deploy_script.makezytmp$tmp_file_id") != 0) {
        $staging = ".$deploy_script.makezytmp$tmp_file_id";
        $tmp_file_id++;
    } else {
        $repeat = 0;
    }
}

move(".$deploy_script.makezytmp$tmp_file_id", $deploy_script);
system("rm .$deploy_script.makezytmp*");

if (`mkdir ZysysHostingOptimizations 2>&1 3>&1` =~ /exists/) {
    croak "ERROR: The folder ZysysHostingOptimizations exists. Please remove it.\n\t";
}
system("cp -r $deploy_script ZysysHostingOptimizations");
system ("zip -r ZysysHostingOptimizations-" . $version . ".zip ZysysHostingOptimizations/");
system("rm -R ZysysHostingOptimizations/");


# https://perlmaven.com/slurp
sub slurp {
    my $file = shift;
    return path($file)->slurp_utf8;
}

