#!/usr/bin/php
<?php
if (in_array($argv[1], array('--help', '-help', '-h', '-?'))) {
?>

Generate DumbledORM models.

  Usage:
  <?php echo $argv[0]; ?> <option>

  <option> 
      -h, -?, --help, -help            Print this help message
      -p, --prefix <prefix>            Prefix generated classes
      -d, --dir <directory>            Output directory for the model instead of the default ./model 

<?php
} else {
$params = array(
  'p:' => 'prefix:',
  'd:' => 'dir:',
);
$opt = getopt(implode('', array_keys($params)), $params);

require('config.php');
require('dumbledorm.php');
Builder::generateBase($opt['p'], ($opt['d'] ? $opt['d'] : 'model'));
}
?>
