<?php 
class DbConfig {
  const HOST = 'localhost';
  const DBNAME = 'dumbledorm_test_db'; 
  const USER = 'root';
  const PASSWORD = 'password';
}
$dbname = DbConfig::DBNAME;
require('dumbledorm.php');
class OrmTestException extends Exception {}
class OrmTest {
  public static $fails = 0;
  public static function assertTrue($message,$test) {
    self::log($message);
    if ($test !== true) {
      self::$fails++;
      return self::log(" Failed!\n");
    }
    self::log(" Success.\n");
  }
  private static function log($message) {
    echo $message;
  }
}
try {
  $db = mysql_connect(DbConfig::HOST,DbConfig::USER,DbConfig::PASSWORD);
  mysql_query("drop database if exists $dbname",$db); 
  mysql_query("create database $dbname",$db); 
  mysql_close($db);
  Db::pdo();
  OrmTest::assertTrue("Creating test database $dbname.",true);
} catch (Exception $e) {
  throw new OrmTestException('Unable to create test database.  Probably missing proper config.php or a permissions issue. ('.$e->getMessage().')');
}
try {
  Db::execute("CREATE TABLE `$dbname`.`user` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `active` tinyint(4) NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  Db::execute("CREATE TABLE `$dbname`.`phone_number` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `number` varchar(255) NOT NULL,
    `type` varchar(255) NOT NULL,
    `location` varchar(255) NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  Db::execute("CREATE TABLE `$dbname`.`post` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `title` varchar(255) NOT NULL,
    `body` text NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  Db::execute("CREATE TABLE `$dbname`.`post_meta` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `post_id` int(11) NOT NULL,
    `key` varchar(255) NOT NULL,
    `val` varchar(255) NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  Db::execute("CREATE TABLE `$dbname`.`orphan` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `age` varchar(255) NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
  OrmTest::assertTrue("Creating test database tables.",true);
} catch (Exception $e) {
  throw new OrmTestException('Unable to create test tables.  Probably a permissions issue. ('.$e->getMessage().')');
}
try {
  Builder::generateBase(null,$dbname);
  OrmTest::assertTrue("Checking for generated base classes in ./$dbname/base.php.",file_exists("./$dbname/base.php"));
  require("./$dbname/base.php");
  OrmTest::assertTrue('Checking for generated base classes in php scope.',class_exists('UserBase'));
} catch (Exception $e) {
  Db::query("drop database $dbname");
  exec("read -p 'Press enter to delete the test directory named: $dbname  OR CTRL+C TO ABORT'; rm -rf $dbname");
  throw new OrmTestException('Unable to generate model! ('.$e->getMessage().')');
}
try {
  foreach (array('Jason','Jim','Jerri','Al') as $name) {
    $user = new User(array(
      'name' => $name, 
      'email' => "$name@not_a_domain.com", 
      'active' => 1, 
    ));
    $user->save();
  }
  OrmTest::assertTrue('Testing save() on new object.',is_numeric($user->getId()));
  
  $user->setName("$name Johnson")->save();
  OrmTest::assertTrue('Testing updating field on hydrated object.',$user->getName() === "$name Johnson");
  $user->setName($name)->save();
  
  $id = $user->getId();
  
  $user = new User($id);
  OrmTest::assertTrue('Testing fetching object by id on new object.',$user->getName() === $name);

  $users = User::select('1=?',1);
  OrmTest::assertTrue('Testing fetching objects by select() method.',count($users) === 4 and $users->current()->getName() !== '');
  OrmTest::assertTrue('Testing array access by id of results set.',$users[$id]->getName() !== '');
  
  $users = User::find(array('active'=>1));
  OrmTest::assertTrue('Testing fetching objects by find() method.',count($users) === 4 and $users->current()->getName() !== '');
  
  $user = User::one(array('name' => 'Jason'));
  OrmTest::assertTrue('Testing fetching object by one() method.',$user->getName() === 'Jason');
  
  $phone = $user->create(new PhoneNumber(array(
    'type' => 'home', 
    'number' => '607-000-0000', 
  )));
  OrmTest::assertTrue('Testing create new object through create() method.',$phone instanceof PhoneNumber and $phone->getUserId() === $user->getId());
  
  foreach (array(111,222,333,444,555) as $prefix) {
    $user->create(new PhoneNumber(array(
      'type' => 'home', 
      'number' => '607-'.$prefix.'-0000', 
    )))->save();
  }

  OrmTest::assertTrue('Loading records to test method appication to entire results set.',true);
  PhoneNumber::select('`number` like "607%"')
    ->setLocation('Ithaca, NY')
    ->save();
  $phones = PhoneNumber::find(array('location' => 'Ithaca, NY'));
  OrmTest::assertTrue('Checking to see if method application applied correctly.',count($phones) === 5);

  OrmTest::assertTrue('Testing getRelationClassName style magic method (singular).',$user->getPhoneNumber()->getLocation() === 'Ithaca, NY');
  OrmTest::assertTrue('Testing getRelationClassName style magic method (list) .',count($user->getPhoneNumber(true)) === 5 and $user->getPhoneNumber(true)->current()->getLocation() === 'Ithaca, NY');  
  OrmTest::assertTrue('Testing getRelationClassName style magic method (custom) .',$user->getPhoneNumber('`number` like ?',"%111%")->current()->getNumber() === '607-111-0000');

  
  $post = $user->create(new Post(array(
    'title' => 'test post', 
    'body' => "I ain't got no body..", 
  )));
  $post->addMeta(array(
    'background' => 'blue', 
    'border' => '1px solid grey', 
  ));
  $post->setMeta('border','2px solid white');
  OrmTest::assertTrue('Testing addMeta(), setMeta() and getMeta() methods.',$post->getMeta('background') === 'blue' and $post->getMeta('border') === '2px solid white');
  
  $post->save();
  $post = $user->getPost();
  OrmTest::assertTrue('Testing getMeta() methods after save().',$post->getMeta('background') === 'blue' and $post->getMeta('border') === '2px solid white');
  
  $post->delete();
  OrmTest::assertTrue('Testing delete() method on an object.',count($user->getPost(true)) === 0);  
  
  OrmTest::assertTrue('Testing complete. ('.OrmTest::$fails.' fails)',!OrmTest::$fails);

  Db::execute("drop database $dbname");
  exec("read -p 'Press enter to delete the test directory named: $dbname  OR CTRL+C TO ABORT'; rm -rf $dbname");
} catch (Exception $e) {
  Db::execute("drop database $dbname");
  exec("read -p 'Press enter to delete the test directory named: $dbname  OR CTRL+C TO ABORT'; rm -rf $dbname");
  throw new OrmTestException('Testing failed with a quickness! ('.$e->getMessage().')');
} 