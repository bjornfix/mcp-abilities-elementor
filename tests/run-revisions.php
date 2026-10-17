<?php
/** Exercise the registered WordPress revision filter, without a CMS. */
declare(strict_types=1);
define('ABSPATH',__DIR__);
class WP_Post { public int $ID; public string $post_type; public int $post_parent=0; public function __construct(int $id,string $type='page',int $parent=0){$this->ID=$id;$this->post_type=$type;$this->post_parent=$parent;} }
$hooks=[];$meta=[];$checks=0;
function add_action($name,$callback,$priority=10,$argc=1):void{$GLOBALS['hooks'][$name][$priority][]=[$callback,$argc];}
function add_filter($name,$callback,$priority=10,$argc=1):void{add_action($name,$callback,$priority,$argc);}
function wp_get_post_revisions(int $id,array $args=[]):array{return $GLOBALS['latest_revisions']??[];}
function get_post_meta(int $id,string $key,bool $single=true){$values=$GLOBALS['meta'][$id][$key]??[];return $single?($values[0]??''):$values;}
function fire(string $name,...$args):void{foreach($GLOBALS['hooks'][$name]??[] as $callbacks)foreach($callbacks as [$cb,$n])$cb(...array_slice($args,0,$n));}
function change(bool $prior,WP_Post $revision,WP_Post $post):bool{$value=$prior;foreach($GLOBALS['hooks']['wp_save_post_revision_post_has_changed']??[] as $callbacks)foreach($callbacks as [$cb,$n])$value=$cb(...array_slice([$value,$revision,$post],0,$n));return $value;}
function check(bool $value,string $message):void{++$GLOBALS['checks'];if(!$value)throw new RuntimeException($message);}
require __DIR__ . '/../mcp-abilities-elementor.php';
fire('plugins_loaded');
check(isset($hooks['wp_save_post_revision_post_has_changed']),'Native revision Interface registered');
$post=new WP_Post(10);$revision=new WP_Post(20,'revision',10);
$base=['_elementor_data'=>['[{"id":"a","settings":{"title":"Before"}}]'],'_elementor_edit_mode'=>['builder'],'_elementor_page_settings'=>[['hide_title'=>'yes']]];
$meta[10]=$base;$meta[20]=$base;
check(!change(false,$revision,$post),'Unchanged document must not force revisions');
foreach(['_elementor_data'=>'[{"id":"a","settings":{"title":"After"}}]','_elementor_page_settings'=>['hide_title'=>'no'],'_elementor_edit_mode'=>'','_elementor_template_type'=>'single-post','_elementor_version'=>'new','_elementor_pro_version'=>'new','_wp_page_template'=>'elementor_header_footer','_thumbnail_id'=>45] as $key=>$value){$meta[10]=$base;$meta[10][$key]=[$value];check(change(false,$revision,$post),'Changed '.$key.' must create a new snapshot');check($meta[20]===$base,'Previous snapshot remains immutable for '.$key);}
$meta[10]=$base;foreach(['_elementor_css','_elementor_element_cache'] as $key)$meta[10][$key]=['derived new cache'];
check(!change(false,$revision,$post),'Derived caches must not generate new document revisions');
check(change(true,$revision,$post),'Existing core/plugin change remains true');
$other=new WP_Post(21,'revision',99);check(!change(false,$other,$post),'Unrelated revision does not force a save');
check(!change(false,new WP_Post(22),$post),'Nonrevision does not force a save');
$meta[30]=['_thumbnail_id'=>[100]];$meta[31]=[];check(!change(false,new WP_Post(31,'revision',30),new WP_Post(30)),'Ordinary nonElementor document retains native behavior');
$meta[10]=[];$meta[20]=$base;check(change(false,$revision,$post),'Removing Elementor data/mode is a revisioned change');
check($meta[20]===$base,'No metadata is modified by the filter');
$meta[10]=$base;$meta[20]=$base;$meta[25]=$base;$latest_revisions=[25=>new WP_Post(25,'revision',10)];$meta[25]['_elementor_data']=['different autosave'];
check(change(false,$revision,$post),'Newer stale autosave must not be selected by native preview fallback');
$meta[25]=$base;check(!change(false,$revision,$post),'Matching newer autosave may be reused');
echo json_encode(['status'=>'PASS','assertions'=>$checks,'Interface'=>'wp_save_post_revision_post_has_changed','scope'=>'Local registered-filter contract; real WP revision/preview runtime still required'],JSON_PRETTY_PRINT)."\n";
