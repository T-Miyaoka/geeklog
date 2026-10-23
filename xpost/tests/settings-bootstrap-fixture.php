<?php
require __DIR__ . '/base-fixture.php';
if (!in_array($case, array('get', 'anonymous'), true)) { $_GET['embedded'] = '1'; }
class config
{
    private static $instance;
    private $groups = array();
    public static function get_instance() { if (!self::$instance) { self::$instance = new self(); } return self::$instance; }
    public function group_exists($group) { return isset($this->groups[$group]); }
    public function get_config($group) { return isset($this->groups[$group]) ? array_map(function ($e) { return $e[0]; }, $this->groups[$group]) : false; }
    public function add($name, $value, $type, $sg, $fs, $selection, $sort, $set, $group, $tab)
    {
        $this->groups[$group][$name] = array($value, $type); return true;
    }
}
if ($case === 'register') { $_POST = array('action'=>'register_configuration'); }
if ($case === 'blank') { $_POST = array('action'=>'settings','username'=>'__MeeSan__'); }
if ($case === 'invalid') { $_POST = array('action'=>'settings','username'=>'bad/name'); }
if ($case === 'array') { $_POST = array('action'=>'settings','username'=>array('bad')); }
if ($case === 'check_csrf') { $case='csrf'; $_POST = array('action'=>'check'); }
if ($case === 'unknown') { $_POST = array('action'=>'publish'); }
if (strpos($case, 'embedded_') === 0) {
    $_GET = array('embedded'=>'1');
    $_SERVER['REQUEST_METHOD'] = $case === 'embedded_get' ? 'GET' : 'POST';
    $_POST = array('action'=>'settings', 'username'=>'__MeeSan__');
    if ($case === 'embedded_csrf') { $case = 'csrf'; }
}
