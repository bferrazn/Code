#!/usr/bin/perl
#
# Reset the test Ligmincha Joomla to the state of the main Joomla
#

# Database
qx( /var/www/tools/prefix-admin.pl root joomla3 joomla_ --copy joomla3 test_ );

# File structure
$bak = '/home/nad/ligtest-' . time();
qx( mv /var/www/domains/ligtest $bak );
print "Backed up ligtest to $bak\n";
qx( cp -pR /var/www/domains/ligmincha /var/www/domains/ligtest );
qx( cp $bak/configuration.php /var/www/domains/ligtest/ );
qx( cp $bak/plugins/system/mwsso/mwsso.php /var/www/domains/ligtest/plugins/system/mwsso/ );
print "Duplicated file structure (except for configuration.php and mwsso.php)\n";
