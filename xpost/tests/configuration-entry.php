<?php
define('VERSION','2.2.2');
class config
{
    public $entries=array(); private static $instance;
    public static function get_instance(){if(!self::$instance){self::$instance=new self();}return self::$instance;}
    public function group_exists($group){return !empty($this->entries[$group]);}
    public $adds=0;
    public function add($name,$value,$type,$sg,$fs,$selection,$sort,$set,$group,$tab){++$this->adds;$this->entries[$group][$name]=array($value,$type,$sg,$fs,$sort,$tab);}
    public function get_config($group){if(empty($this->entries[$group])){return false;}$r=array();foreach($this->entries[$group] as $n=>$e){$r[$n]=$e[0];}return $r;}
}
require __DIR__.'/../plugins/xpost/install_defaults.php';
if(!plugin_initconfig_xpost()){throw new RuntimeException('Void-return config add not supported');}
$manager=config::get_instance();$before=$manager->entries;
if(array_keys($before['xpost'])!==array('sg_main','tab_api','fs_api','api_settings_link','sg_posting','tab_posting','fs_posting','announcement_interval_hours')){throw new RuntimeException('Unexpected configuration metadata');}
if($before['xpost']['announcement_interval_hours']!==array(24,'text',1,1,10,1)||$before['xpost']['api_settings_link'][2]!==0){throw new RuntimeException('Posting interval must default to 24h in its own subgroup');}
echo "PASS: native config registration stores API navigation metadata and a separate 24h posting interval subgroup\n";
if(!plugin_initconfig_xpost()||$manager->entries!==$before){throw new RuntimeException('Registration not idempotent');}echo "PASS: repeated registration preserves existing metadata\n";
$manager->entries['xpost']['announcement_interval_hours'][0]='6';$saved=$manager->entries;$adds=$manager->adds;
if(!plugin_initconfig_xpost()||$manager->entries!==$saved||$manager->adds!==$adds){throw new RuntimeException('Saved interval reset or re-added');}echo "PASS: re-registration never resets an administrator's saved interval\n";
foreach(array('sg_posting','tab_posting','fs_posting','announcement_interval_hours') as $n){unset($manager->entries['xpost'][$n]);}$adds=$manager->adds;
plugin_initconfig_xpost();if($manager->adds-$adds!==4||$manager->entries['xpost']['announcement_interval_hours'][0]!==24||count($manager->entries['xpost'])!==8){throw new RuntimeException('Existing install migration failed');}
echo "PASS: existing 1.0 installs gain only the four missing posting entries\n";
$_CONF=array('site_admin_url'=>'https://www.happa.bz/admin');
require __DIR__.'/../plugins/xpost/language/japanese_utf-8.php';
if(strpos($LANG_confignames['xpost']['api_settings_link'],'/settings.php')===false||$LANG_configsections['xpost']['label']!=='X-Post'){throw new RuntimeException('Japanese navigation invalid');}echo "PASS: Japanese config label is X-Post\n";
require __DIR__.'/../plugins/xpost/language/english.php';
if(strpos($LANG_confignames['xpost']['api_settings_link'],'/settings.php')===false||$LANG_configsections['xpost']['label']!=='X-Post'){throw new RuntimeException('English navigation invalid');}echo "PASS: English config label is X-Post\n";
if(strpos($LANG_confignames['xpost']['api_settings_link'],'id="xp-settings-panel"')===false || strpos($LANG_confignames['xpost']['api_settings_link'],'?embedded=1')===false || strpos($LANG_confignames['xpost']['api_settings_link'],'<iframe')!==false){throw new RuntimeException('Inline configuration panel missing');}
if(strpos(xpost_configuration_panel('https://example.test/"','"test'),'/&quot;')===false){throw new RuntimeException('Panel URL escaping failed');}
echo "PASS: inline document embeds independent forms and escapes attributes\n";
if(stripos($LANG_confignames['xpost']['api_settings_link'],'<script')!==false){throw new RuntimeException('Script tag in search metadata');}
$dom=new DOMDocument();$dom->loadHTML('<html><head><script>var autocomplete_data='.json_encode(array('label'=>$LANG_confignames['xpost']['api_settings_link']),JSON_UNESCAPED_SLASHES).';</script></head><body></body></html>');
if(trim($dom->getElementsByTagName('body')->item(0)->textContent)!==''){throw new RuntimeException('Search metadata spills into page');}
echo "PASS: config search metadata never closes script or spills code into body\n";
$root=true;function SEC_inGroup($group){global $root;return $root;}
foreach(array('sg_posting','tab_posting','fs_posting','announcement_interval_hours') as $n){unset($manager->entries['xpost'][$n]);}
require __DIR__.'/../plugins/xpost/functions.inc';
$_SERVER['SCRIPT_NAME']='/admin/configuration.php';
if(strpos(plugin_getheadercode_xpost(),'settings-embedded.js')===false||plugin_getadminoption_xpost()[0]!=='X-Post'){throw new RuntimeException('Header hook or menu label invalid');}
if(!isset($manager->entries['xpost']['announcement_interval_hours'])){throw new RuntimeException('Root admin menu did not register missing interval');}
echo "PASS: configuration loads panel script through header hook and names menu X-Post\n";echo "PASS: Root admin menu registers the missing interval setting without a plugin upgrade\n";
$adds=$manager->adds;$root=false;plugin_getadminoption_xpost();$root=true;if($manager->adds!==$adds){throw new RuntimeException('Non-Root request changed configuration');}echo "PASS: non-Root requests never write configuration\n";
require __DIR__."/../plugins/xpost/language/japanese_utf-8.php";$_CONF_VALIDATE=array();require __DIR__.'/../plugins/xpost/configuration_validation.php';$rule=$_CONF_VALIDATE['xpost']['announcement_interval_hours']['rule'];
foreach(array('0','1','24','99','100','699','700','719','720') as $v){if(!preg_match($rule,$v)){throw new RuntimeException('Valid interval rejected: '.$v);}}
foreach(array('','-1','721','1000','24.5','024','abc','24 ',"24\n") as $v){if(preg_match($rule,$v)){throw new RuntimeException('Invalid interval accepted: '.json_encode($v));}}
if(strpos($_CONF_VALIDATE['xpost']['announcement_interval_hours']['message'],'0〜720')===false){throw new RuntimeException('Validation message not localized');}
echo "PASS: configuration validation accepts whole hours 0-720 only with a localized message\n";
foreach(array('sg_posting','tab_posting','fs_posting','announcement_interval_hours') as $n){unset($manager->entries['xpost'][$n]);}
plugin_getheadercode_xpost();if(!isset($manager->entries['xpost']['announcement_interval_hours'])){throw new RuntimeException('Configuration page did not register missing interval');}echo "PASS: Root configuration page registers the missing interval setting\n";
$_SERVER['SCRIPT_NAME']='/index.php';if(plugin_getheadercode_xpost()!==''){throw new RuntimeException('Script leaked to other pages');}
$_SERVER['SCRIPT_NAME']='/admin/configuration.php';$root=false;foreach(array('sg_posting','tab_posting','fs_posting','announcement_interval_hours') as $n){unset($manager->entries['xpost'][$n]);}$adds=$manager->adds;if(plugin_getheadercode_xpost()!==''||$manager->adds!==$adds){throw new RuntimeException('Script leaked to non-Root');}$root=true;plugin_getheadercode_xpost();
echo "PASS: header hook limited to Root configuration requests\n";
echo "TOTAL: 14 configuration entry checks passed; no database or X calls\n";
