<?php
/**
 *
 *  DumbledORM
 * 
 *  http://github.com/jasonmoo/DumbledORM
 * 
 *  DumbledORM is a novelty PHP ORM
 * 
 *  Copyright (c) 2010 Jason Mooberry
 * 
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 * 
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 * 
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 *
 */

/**
 * exceptional moments defined here
 */
class RecordNotFoundException extends Exception {}

/**
 * Builder class for required for generating base classes
 *
 */
abstract class Builder {
  
  /**
   * simple cameCasing method
   *
   * @param string $string 
   * @return string
   */
  public static function camelCase($string)	{
    return ucfirst(preg_replace("/_(\w)/e","strtoupper('\\1')",strtolower($string)));
  }
  
  /**
   * simple un_camel_casing method
   *
   * @param string $string 
   * @return string
   */
  public static function unCamelCase($string)	{
    return strtolower(preg_replace("/(\w)([A-Z])/","\\1_\\2",$string));
  }
  
  /**
   * re/generates base classes for db schema
   *
   * @param string $prefix 
   * @param string $dir 
   * @return void
   */
  public static function generateBase($prefix=null,$dir='model') {
    $tables = array();
    foreach (Db::query('show tables',null,PDO::FETCH_NUM) as $row) {
      foreach (Db::query('show columns from `'.$row[0].'`') as $col) {
        if ($col['Key'] === 'PRI') {
          $tables[$row[0]]['pk'] = $col['Field']; break;
        }
      }
    }
    foreach (array_keys($tables) as $table) {
      foreach (Db::query('show columns from `'.$table.'`') as $col) {
        if (substr($col['Field'],-3,3) === '_id') {
          $rel = substr($col['Field'],0,-3);
          if (array_key_exists($rel,$tables)) {
            if ($table === "{$rel}_meta") {
              $tables[$rel]['meta']['class'] = self::camelCase($table);
              $tables[$rel]['meta']['field'] = $col['Field'];
            }
            $tables[$table]['relations'][$rel] = array('fk' => 'id', 'lk' => $col['Field']);
            $tables[$rel]['relations'][$table] = array('fk' => $col['Field'], 'lk' => 'id');
          }
        }
      }
    }    
    $basetables = "<?php\nspl_autoload_register(function(\$class) { @include(__DIR__.\"/\$class.class.php\"); });\n";
    foreach ($tables as $table => $conf) {
      $relations = preg_replace('/[\n\t\s]+/','',var_export((array)@$conf['relations'],true));
      $meta = isset($conf['meta']) ? "\$meta_class = '{$conf['meta']['class']}', \$meta_field = '{$conf['meta']['field']}'," : '';
      $basetables .= "class ".$prefix.self::camelCase($table)."Base extends BaseTable { protected static \$table = '$table', \$pk = '{$conf['pk']}', $meta \$relations = $relations; }\n";
    }
    @mkdir("./$dir",0777,true);
    file_put_contents("./$dir/base.php",$basetables);
    foreach (array_keys($tables) as $table) {
      $file = "./$dir/$prefix".self::camelCase($table).'.class.php';
      if (!file_exists($file)) {
        file_put_contents($file,"<?php\nclass ".$prefix.self::camelCase($table).' extends '.$prefix.self::camelCase($table).'Base {}');
      }
    }
  }
  
}

/**
 * thin wrapper for PDO access 
 *
 */
abstract class Db {
  
  /**
   * singleton variable for PDO connection
   *
   */
  private static $_pdo;
  
  /**
   * singleton getter for PDO connection
   *
   * @return PDO 
   */
  public static function pdo() {
    if (!self::$_pdo) {
      self::$_pdo = new PDO('mysql:host='.DbConfig::HOST.';dbname='.DbConfig::DBNAME, DbConfig::USER, DbConfig::PASSWORD);
      self::$_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return self::$_pdo;
  }
  
  /**
   * execute sql as a prepared statement
   *
   * @param string $sql 
   * @param mixed $params 
   * @return PDOStatement
   */
  public static function execute($sql,$params=null) {
    $stmt = self::pdo()->prepare($sql);
    $stmt->execute((array)$params);
    return $stmt;
  }
  
  /**
   * execute sql as a prepared statement and return all records
   *
   * @param string $query 
   * @param mixed $params 
   * @param PDO constant $fetch_style 
   * @return Array
   */
  public static function query($query,$params=null,$fetch_style=PDO::FETCH_ASSOC) {
    return self::execute($query,$params)->fetchAll($fetch_style);
  }
  
  /**
   * run a query and return the results as a ResultSet of BaseTable objects
   *
   * @param BaseTable $obj 
   * @param string $query 
   * @param mixed $params 
   * @return ResultSet
   */
  public static function hydrate(BaseTable $obj,$query,$params=null) {
    $set = array();
    foreach (self::query($query,$params) as $record) {
      $clone = clone $obj;
      $clone->hydrate($record);
      $set[$clone->getId()] = $clone;
    }
    return new ResultSet($set);
  }
  
}

/**
 * class to manage result array more effectively
 *
 */
final class ResultSet extends ArrayIterator {

  /**
   * magic method for applying called methods to all members of result set
   *
   * @param string $method 
   * @param Array $params 
   * @return $this
   */
  public function __call($method,$params=array()) {
    foreach ($this as $obj) {
      call_user_func_array(array($obj,$method),$params);
    }
    return $this;
  }
  
}

/**
 * base functionality available to all objects extending from a generated base class
 *
 */
abstract class BaseTable {
  
  protected static 
    /**
     * table name
     */
    $table, 
    /**
     * primary key
     */
    $pk, 
    /**
     * table relations array
     */
    $relations, 
    /**
     * metadata class name
     */
    $meta_class, 
    /**
     * metadata field
     */
    $meta_field;
    
  protected 
    /**
     * record data array
     */
    $data, 
    /**
     * metadata array
     */
    $meta, 
    /**
     * relation data array
     */
    $relation_data, 
    /**
     * record primary key value
     */
    $id, 
    /**
     * array of data fields that have changed since hydration
     */
    $changed;
  
  /**
   * search for single record in self::$table
   *
   * @param Array $constraints 
   * @return BaseTable
   */
  final public static function one(Array $constraints) {
    return self::select('`'.implode('` = ? and `',array_keys($constraints)).'` = ? limit 1',array_values($constraints))->current();
  }
  
  /**
   * search for any number of records in self::$table
   *
   * @param Array $constraints 
   * @return ResultSet
   */
  final public static function find(Array $constraints) {
    return self::select('`'.implode('` = ? and `',array_keys($constraints)).'` = ?',array_values($constraints));
  }
  
  /**
   * execute a query in self::$table
   *
   * @param string $qs 
   * @param mixed $params 
   * @return ResultSet
   */
  final public static function select($qs,$params=null) {
    return Db::hydrate(new static,'select * from `'.static::$table.'` where '.$qs,$params);    
  }
   
  /**
   * construct object and load supplied data or fetch data by supplied id
   *
   * @param mixed $val 
   */   
  public function __construct($val=null) {
    if (is_array($val)) {
      $this->data = $val;
      $this->changed = array_flip(array_keys($this->data));
      $this->_loadMeta();
    } else if (is_numeric($val)) {
      if (!$obj = self::one(array(static::$pk => $val))) {
        throw new RecordNotFoundException("Nothing to be found with id $val");
      }
      $this->hydrate($obj->toArray());
    }
  }
  
  /**
   * most of the magic in here makes it all work
   * - handles all getters and setters on columns and relations
   *
   * @param string $method 
   * @param Array $params 
   * @return mixed
   */
  final public function __call($method,$params=array()) {
    $name = Builder::unCamelCase(substr($method,3,strlen($method)));
    if (strpos($method,'get')===0) {
      if (array_key_exists($name,$this->data)) {
        return $this->data[$name];
      }
      if (isset(static::$relations[$name])) {
        $class = substr($method,3,strlen($method));
        if (count($params)) {
          if ($params[0] === true) { 
            return @$this->relation_data[$name.'_all'] ?: $this->relation_data[$name.'_all'] = $class::find(array(static::$relations[$name]['fk'] => $this->getId()));
          }
          $qparams = array_merge(array($this->getId()),(array)@$params[1]);
          $qk = md5(serialize(array($name,$params[0],$qparams)));
          return @$this->relation_data[$qk] ?: $this->relation_data[$qk] = $class::select('`'.static::$relations[$name]['fk'].'` = ? and '.$params[0],$qparams);
        }
        return @$this->relation_data[$name] ?: $this->relation_data[$name] = $class::one(array(static::$relations[$name]['fk'] => $this->getId()));
      }
    }
    else if (strpos($method,'set')===0) {
      $this->changed[$name] = true;
      $this->data[$name] = array_shift($params);
      return $this;
    }
    throw new BadMethodCallException("No amount of magic can make $method work..");
  }
  
  /**
   * simple output object data as array
   *
   * @return Array
   */
  final public function toArray() {
    return $this->data;
  }
  
  /**
   * simple output object pk id
   *
   * @return integer
   */
  final public function getId() {
    return $this->id;
  }
  
  /**
   * store supplied data and bring object state to current
   *
   * @param Array $data 
   * @return $this
   */
  final public function hydrate(Array $data) {
    $this->id = $data[static::$pk];
    $this->data = $data;
    $this->_loadMeta();
    $this->changed = array();
    return $this;
  }
  
  /**
   * create an object with a defined relation to this one.
   *
   * @param BaseTable $obj 
   * @return BaseTable
   */
  final public function create(BaseTable $obj) {
    return $obj->{'set'.Builder::camelCase(static::$relations[Builder::unCamelCase(get_class($obj))]['fk'])}($this->id);
  }
  
  /**
   * insert or update modified object data into self::$table and any associated metadata
   *
   * @return void
   */
  public function save() {
    if (empty($this->changed)) return;
    $data = array_intersect_key($this->data,$this->changed);
    if ($this->id) {
      $query = 'update `'.static::$table.'` set `'.implode('` = ?, `',array_keys($data)).'` = ? where `'.static::$pk.'` = '.$this->id.' limit 1';
    }
    else {
      $query = 'insert into `'.static::$table.'` (`'.implode('`,`',array_keys($data))."`) values (".rtrim(str_repeat('?,',count($data)),',').")";
    }
    Db::execute($query,array_values($data));
    if ($this->id === null) {
      $this->id = Db::pdo()->lastInsertId();
    }
    $this->meta->{'set'.Builder::camelCase(static::$meta_field)}($this->id)->save();
    $this->hydrate(self::one(array(static::$pk => $this->id))->toArray());
  }
  
  /**
   * delete this object's record from self::$table and any associated meta data
   *
   * @return void
   */
  public function delete() {
    Db::execute('delete from `'.static::$table.'` where `'.static::$pk.'` = ? limit 1',$this->getId());
    $this->meta->delete();
  }
  
  /**
   * add an array of key/val to the metadata 
   *
   * @param Array $data 
   * @return $this
   */
  public function addMeta(Array $data) {
    foreach ($data as $field => $val) {
      $this->setMeta($field,$val);
    }
    return $this;
  }
  
  /**
   * set a field of metadata
   *
   * @param string $field 
   * @param string $val 
   * @return $this
   */
  public function setMeta($field,$val) {
    if (empty($this->meta[$field])) {
      $meta_class = static::$meta_class;
      $this->meta[$field] = new $meta_class(array('key' => $field,'val' => $val));
    }
    else {
      $this->meta[$field]->setVal($val);
    }
    return $this;
  }
  
  /**
   * get a field of metadata
   *
   * @param string $field 
   * @return mixed
   */
  public function getMeta($field) {
    return isset($this->meta[$field]) ? $this->meta[$field]->getVal() : null;
  }
  
  /**
   * internally fetch and load any associated metadata
   *
   * @return void
   */
  private function _loadMeta() {
    if (!$meta_class = static::$meta_class) {
      return $this->meta = new ResultSet;
    }
    foreach ($meta_class::find(array(static::$meta_field => $this->getId())) as $obj) {
      $meta[$obj->getKey()] = $obj;
    }
    $this->meta = new ResultSet((array)@$meta);
  }

}