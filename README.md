#DumbledORM
A PHP Novelty ORM

##Requirements:
* PHP 5.3+
* All tables must have a single (not composite) primary key called `id` that is an auto-incrementing integer
* All foreign keys must follow the convention of `table_name_id`
* All meta tables must be:
 * named `table_name_meta` 
 * have a foreign key to the corresponding parent table
 * have a column called `key` 
 * have a column called `val`


##Setup:

1. Download/clone DumbledORM
2. Ensure that your config.php has the correct host, user, pass, etc.
3. When you have set up your database or have made a change, go to the command line and type ```./generate.php```
4. Add the following lines to your code:

		require('config.php');
		require('dumbledorm.php');
		require('./model/base.php');

That's it.  There's an autoloader built in for the generated classes.

###Database configuration

The PHP Data objects or PDO extension is used to access mysql and the configuration file `config.php` is home for class
DbConfig the source where DumbledORM finds your database settings.

You are able to configure the settings for host, port, database, username and password.

```
      class DbConfig {
        const HOST = 'localhost';
        const PORT = 3306;
        const DBNAME = 'test_database';
        const USER = 'root';
        const PASSWORD = 'password';
      }
```

NOTE: On rare occations mysql will not resolve localhost and PDO will attempt to connect to a unix socket,
if this fails you will likely find a PDOException complaining that there is "No such file or directory".
By changing localhost to the ip 127.0.0.1 instead mysql will be able to resolve the host and a connection can be established.

###CLI Script generate.php

DumbledORM includes a PHP script to generate your database schema model classes.

At the command line type ```./generate.php -h``` for usage

```
        Generate DumbledORM models.

          Usage:
          ./generate.php <option>

          <option>
              -h, -?, --help, -help            Print this help message
              -p, --prefix <prefix>            Prefix generated classes
              -d, --dir <directory>            Output directory for the model instead of the default ./model
```


###Builder configuration

To generate the model programatically:

		require('config.php');
		require('dumbledorm.php');
		Builder::generateBase();

`Builder::generateBase()` will always overwrite `base.php` but never any generated classes.

If you want to prefix the classes that are generated:

	Builder::generateBase('myprefix');

If you want to put the generated classes in a different directory than the default "model":

	Builder::generateBase(null,'mymodeldir/model');

###Testing

DumbledORM includes a simple test script.  You can run it from the command line.  Just modify the DbConfig in the test script to your params.

	php test.php

##Usage

####Create a new record
	$user = new User(array(
	  'name' => 'Jason', 
	  'email' => 'jasonmoo@me.com', 
	  'created_at' => new PlainSql('NOW()'), 
	));
	$user->save();

####Load an existing record and modify it
	$user = new User(13);  // load record with id 13
	$user->setName('Jason')->save();

####Find a single record and delete it
	User::one(array('name' => 'Jason'))->delete();

####Find all records matching both fields and delete them all 
	User::find(array('name' => 'Jason','job' => 'PHP Dev'))->delete();

####Find all records matching a query and modify them
    // applies setLocation and save to the entire set
    PhoneNumber::select('`number` like "607%"')
      ->setLocation('Ithaca, NY')
      ->setType(null)  // sets field to SQL NULL
      ->save();

####Find all records matching a query and access a single record by id
	$users = User::select('`name` like ?',$val);
	echo $users[13]->getId(); // 13

####Find all records matching a query and iterate over them
	foreach (User::select('`name` like ? and `job` IS NOT NULL order by `name`',$val) as $id => $user) {
	  echo $user->getName().": $id\n";  // Jason: 13
	}

####Create a related record
	$user->create(new PhoneNumber(array(
	  'type' => 'home', 
	  'number' => '607-333-2840', 
	)))->save();

####Fetch a related record and modify it
	// fetches a single record only
	$user->getPhoneNumber()->setType('work')->save();

####Fetch all related records and iterate over them.	
	// boolean true causes all related records to be fetched
	foreach ($user->getPhoneNumber(true) as $ph) {
	  echo $ph->getType().': '.$ph->getNumber();
	}

####Fetch all related records matching a query and modify them
	$user->getPhoneNumber('`type` = ?',$type)
	  ->setType($new_type)
	  ->save()

####Set/Get metadata for a record
	// set a batch
	$user->addMeta(array(
	  'background' => 'blue', 
	  'last_page' => '/', 
	));
	// set a single
	$user->setMeta('background','blue');
	// get a single
	$user->getMeta('background'); // blue
	// metadata saved automatically
	$user->save();  
