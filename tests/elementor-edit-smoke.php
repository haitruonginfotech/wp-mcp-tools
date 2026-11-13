<?php
// Smoke test of the Elementor edit tools' WordPress glue with in-memory stubs:  php tests/elementor-edit-smoke.php
define( 'ABSPATH', '/x/' ); define( 'ELEMENTOR_VERSION', '4.3.5' );
$R = __DIR__ . '/../includes/';
class WP_Error { public $c; public $m; public $d; function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} function get_error_message(){return $this->m;} function get_error_code(){return $this->c;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$META=array(); $POSTS=array(1=>(object)array('ID'=>1,'post_status'=>'draft','post_type'=>'page')); $NEXT=10; $SAVES=0; $FLUSH=0;
function get_post($id){global $POSTS;return $POSTS[$id]??null;}
function get_post_meta($id,$k,$single=false){global $META;return $META[$id][$k]??'';}
function update_post_meta($id,$k,$v){global $META;$META[$id][$k]=$v;return true;}
function metadata_exists($t,$id,$k){global $META;return isset($META[$id][$k]);}
function wp_slash($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($k){return strtolower(preg_replace('/[^a-z0-9_-]/i','',$k));}
function sanitize_text_field($s){return trim(strip_tags($s));} function wp_json_encode($v){return json_encode($v);}
function current_user_can($c,$id=null){return true;} function clean_post_cache($id){global $FLUSH;$FLUSH++;}
function get_the_title($id){return 'T';} function get_permalink($id){return "http://x/?p=$id";} function admin_url($p){return "http://x/wp-admin/$p";}
function get_post_type_object($t){return (object)array('cap'=>(object)array('create_posts'=>'edit_pages','publish_posts'=>'publish_pages'));}
function wp_insert_post($a,$e=false){global $POSTS,$NEXT;$id=$NEXT++;$POSTS[$id]=(object)array('ID'=>$id,'post_status'=>$a['post_status'],'post_type'=>$a['post_type']);return $id;}
function wp_delete_post($id,$f=false){global $POSTS;unset($POSTS[$id]);}
function get_post_status($id){return 'draft';}
require $R.'class-helpers.php'; require $R.'class-elementor-tree.php';
class WPMCP_Registry { static $g=array(); static $t=array(); static function add_group($g,$a){self::$g[$g]=$a;} static function add($g,$s,$d){self::$t[$s]=$d;} }
require $R.'abilities/class-elementor-edit.php';
$f=0; function t($c,$n){global $f; echo ($c?'ok   ':'FAIL ').$n."\n"; if(!$c)$f++;}
WPMCP_Elementor_Edit::register();
t(count(WPMCP_Registry::$t)===11,'11 tools registered: '.count(WPMCP_Registry::$t));
t(WPMCP_Registry::$g['elementor-edit']['label']!=='' ,'group declared');
foreach(WPMCP_Registry::$t as $s=>$d){ t(is_callable($d['callback']) && is_callable($d['capability'] ?? array('WPMCP_Elementor_Edit','can_edit')) ,"callable: $s"); }
// build a new page
$r=WPMCP_Elementor_Edit::build_page(array('title'=>'Home','elements'=>array(array('type'=>'container','children'=>array(array('widget'=>'heading','settings'=>array('title'=>'Hi')),array('widget'=>'button','settings'=>array('text'=>'Go')))))));
t(!is_wp_error($r)&&$r['created']&&$r['saved']&&$r['id']===10,'build-page creates and saves');
t(json_decode($META[10]['_elementor_data'],true)[0]['elements'][0]['settings']['title']==='Hi','meta holds the tree (no Elementor class = meta fallback)');
t($META[10]['_elementor_edit_mode']==='builder','edit mode set');
$tree=WPMCP_Elementor_Edit::get_tree(array('id'=>10));
t($tree['elements']===3 && $tree['tree'][0]['children'][0]['text']==='Hi','get-tree outline');
$hash=$tree['hash']; $hid=$tree['tree'][0]['children'][0]['id'];
// batch with refs + conflict guard
$b=WPMCP_Elementor_Edit::batch(array('id'=>10,'expected_hash'=>$hash,'operations'=>array(
 array('op'=>'update','element_id'=>$hid,'settings'=>array('title'=>'Hello')),
 array('op'=>'add','ref'=>'b','element'=>array('type'=>'e-flexbox')),
 array('op'=>'add','parent_id'=>'@b','element'=>array('widget'=>'e-heading','settings'=>array('title'=>'Atomic'))),
)));
t(!is_wp_error($b)&&$b['saved']&&$b['elements']===5,'batch saved: '.(is_wp_error($b)?$b->m:''));
$c=WPMCP_Elementor_Edit::update_element(array('id'=>10,'expected_hash'=>$hash,'element_id'=>$hid,'settings'=>array('title'=>'X')));
t(is_wp_error($c)&&$c->c==='wpmcp_elementor_conflict','stale hash refused');
$d=WPMCP_Elementor_Edit::remove_element(array('id'=>10,'element_id'=>$hid,'dry_run'=>true));
t(!is_wp_error($d)&&!$d['saved']&&$d['dry_run'],'dry run does not save');
$e=WPMCP_Elementor_Edit::add_element(array('id'=>10,'element'=>array('widget'=>'heading')));
t(is_wp_error($e)&&strpos($e->m,'root')!==false,'widget at root refused with hint');
$g=WPMCP_Elementor_Edit::find(array('id'=>10,'widget_type'=>'e-heading'));
t($g['count']===1,'find');
$h=WPMCP_Elementor_Edit::replace_text(array('id'=>10,'search'=>'Hello','replace'=>'Bye'));
t(!is_wp_error($h)&&$h['results'][0]['replacements']===1&&$h['saved'],'replace-text');
$el=WPMCP_Elementor_Edit::get_element(array('id'=>10,'element_id'=>$hid));
t($el['element']['settings']['title']==='Bye','get-element');
$bad=WPMCP_Elementor_Edit::get_tree(array('id'=>999)); t(is_wp_error($bad),'unknown post');
$META[1]['_elementor_data']='{broken'; $cor=WPMCP_Elementor_Edit::add_element(array('id'=>1,'element'=>array('type'=>'container')));
t(is_wp_error($cor)&&$cor->c==='wpmcp_elementor_corrupt','corrupt data never overwritten');
echo $f?"\n$f FAILED\n":"\nall passed\n"; exit($f?1:0);
