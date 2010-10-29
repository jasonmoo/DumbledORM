<?php
class RecordNotFoundException extends Exception {}
abstract class Builder {
	public static function camelCase($string)	{
		return ucfirst(preg_replace("/_(\w)/e","strtoupper('\\1')",strtolower($string)));
	}
	public static function unCamelCase($string)	{
		return strtolower(preg_replace("/(\w)([A-Z])/","\\1_\\2",$string));
	}
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
abstract class Db {
  private static $_pdo;
  public static function pdo() {
    return self::$_pdo ?: self::$_pdo = new PDO('mysql:host='.DbConfig::HOST.';dbname='.DbConfig::DBNAME, DbConfig::USER, DbConfig::PASSWORD);
  }
  public static function query($query,$params=null,$fetch_style=PDO::FETCH_ASSOC) {
    $stmt = self::pdo()->prepare($query);
    $stmt->execute((array)$params);
    return $stmt->fetchAll($fetch_style) ?: array();
  }
  public static function hydrate(BaseTable $obj,$query,$params=null) {
    $set = array();
    foreach (self::query($query,$params) as $record) {
      $obj = clone $obj;
      $obj->hydrate($record);
      $set[$obj->getId()] = $obj;
    }
    return new ResultSet($set);
  }
}
final class ResultSet extends ArrayIterator {
  public function __call($method,$params=array()) {
    foreach ($this as $obj) {
      call_user_func_array(array($obj,$method),$params);
    }
    return $this;
  }
}
abstract class BaseTable {
  protected static $table, $pk, $relations, $meta_class, $meta_field;
  protected $data, $meta, $relation_data, $id, $changed;
  final public static function one(Array $constraints) {
    return self::select('`'.implode('` = ? and `',array_keys($constraints)).'` = ? limit 1',array_values($constraints))->current();
  }
  final public static function find(Array $constraints) {
    return self::select('`'.implode('` = ? and `',array_keys($constraints)).'` = ?',array_values($constraints));
  }
  final public static function select($qs,$params=null) {
    return Db::hydrate(new static,'select * from `'.static::$table.'` where '.$qs,$params);    
  }
      
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
  
  final public function toArray() {
    return $this->data;
  }
  final public function getId() {
    return $this->id;
  }
  final public function hydrate(Array $data) {
    $this->id = $data[static::$pk];
    $this->data = $data;
    $this->_loadMeta();
    $this->changed = array();
    return $this;
  }
  final public function create(BaseTable $obj) {
    return $obj->{'set'.Builder::camelCase(static::$relations[Builder::unCamelCase(get_class($obj))]['fk'])}($this->id);
  }
  public function save() {
    if (empty($this->changed)) return;
    if ($this->id) {
      $query = 'update `'.static::$table.'` set `'.implode('` = ? and `',array_keys($this->changed)).'` = ? where `'.static::$pk.'` = '.$this->id.' limit 1';
    }
    else {
      $query = 'insert into `'.static::$table.'` (`'.implode('`,`',array_keys($this->changed))."`) values (".rtrim(str_repeat('?,',count($this->changed)),',').")";
    }
    $vals = array_values(array_intersect_key($this->data,$this->changed));
    Db::query($query,$vals);
    if ($this->id === null) {
      $this->id = Db::pdo()->lastInsertId();
    }
    $this->meta->{'set'.Builder::camelCase(static::$meta_field)}($this->id)->save();
    $this->hydrate(self::one(array(static::$pk => $this->id))->toArray());
  }
  public function delete() {
    Db::query('delete from `'.static::$table.'` where `'.static::$pk.'` = ? limit 1',$this->getId());
    $this->meta->delete();
  }
  
  public function addMeta(Array $data) {
    foreach ($data as $field => $val) {
      $this->setMeta($field,$val);
    }
    return $this;
  }
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
  public function getMeta($field) {
    return isset($this->meta[$field]) ? $this->meta[$field]->getVal() : null;
  }
  private function _loadMeta() {
    if (!$meta_class = static::$meta_class) {
      return $this->meta = new ResultSet;
    }
    foreach ($meta_class::find(array(static::$meta_field => $this->getId())) as $obj) {
      $meta[$obj->getKey()] = $obj;
    }
    $this->meta = new ResultSet(@$meta ?: array());
  }
}