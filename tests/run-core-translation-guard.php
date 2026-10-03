<?php
/** Integration regression: real Content callbacks and native Elementor guards. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
define('WP_PLUGIN_DIR', __DIR__.'/fixture-no-adapter');
function WP_Filesystem(){}
function plugins_api(){}
class Plugin_Upgrader{}
class WP_Ajax_Upgrader_Skin{}
function activate_plugin(){}
function wp_update_plugins(){}
function get_current_screen(){}
function wp_generate_attachment_metadata(){}
function wp_create_user(){}
function get_post_types(...$args):array{return [];}
function get_taxonomies(...$args):array{return [];}
function get_object_taxonomies(...$args):array{return [];}
class WP_Post {
 public int $ID; public string $post_type='page'; public string $post_title='Title';
 public string $post_content=''; public string $post_excerpt=''; public string $post_name='page';
 public string $post_status='publish'; public int $post_parent=0; public int $menu_order=0; public $post_author='20';
 public function __construct(int $id, string $body) { $this->ID=$id; $this->post_content=$body; }
}
class WP_Error { public function get_error_message():string { return 'Fixture write failure'; } }
function add_action(...$args):void {}
function add_filter(...$args):void {}
function __(string $s,...$args):string{return $s;}
function esc_html__(string $s,...$args):string{return $s;}
function esc_html(string $s):string{return $s;}
function sanitize_key(string $s):string{return strtolower($s);}
function sanitize_text_field(string $s):string{return $s;}
function sanitize_title(string $s):string{return $s;}
function current_user_can(...$args):bool{return true;}
function get_current_user_id():int{return 1;}
function get_option(string $key,$default=false){return $default;}
function get_permalink(int $id):string{return 'https://example.com/'.$id;}
function is_wp_error($value):bool{return $value instanceof WP_Error;}
function wp_register_ability(string $name,array $definition):void{$GLOBALS['abilities'][$name]=$definition;}
function get_post(int $id){if(!empty($GLOBALS['use_cache'])){$GLOBALS['post_cache'][$id]=$GLOBALS['post_cache'][$id]??(isset($GLOBALS['posts'][$id])?clone $GLOBALS['posts'][$id]:null);return $GLOBALS['post_cache'][$id]?clone $GLOBALS['post_cache'][$id]:null;}return isset($GLOBALS['posts'][$id])?clone $GLOBALS['posts'][$id]:null;}
function apply_filters(string $name,$value,...$args){
 if(in_array($name,['mcp_expose_translation_sibling_post_ids','mcp_abilities_elementor_translation_sibling_post_ids'],true))return $GLOBALS['siblings'][$args[0]]??[];
 return $value;
}
function wp_slash($v){return is_array($v)?array_map('wp_slash',$v):(is_string($v)?addslashes($v):$v);}
function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):(is_string($v)?stripslashes($v):$v);}
function wp_json_encode($v){return json_encode($v);}
function maybe_serialize($v){return is_array($v)||is_object($v)?serialize($v):$v;}
function get_post_meta(int $id,string $key,bool $single=true){if(!empty($GLOBALS['use_cache'])){$GLOBALS['meta_cache'][$id]=$GLOBALS['meta_cache'][$id]??($GLOBALS['meta'][$id]??[]);$rows=$GLOBALS['meta_cache'][$id][$key]??[];}else{$rows=$GLOBALS['meta'][$id][$key]??[];}return $single?($rows[0]??''):$rows;}
function update_post_meta(int $id,string $key,$value){
 if(!empty($GLOBALS['fail_meta']))return false;
 $GLOBALS['meta'][$id][$key]=[wp_unslash($value)];return true;
}
function clean_post_cache(int $id):void{unset($GLOBALS['post_cache'][$id],$GLOBALS['meta_cache'][$id]);}
function wp_cache_delete(...$args):void{if(($args[1]??'')==='post_meta')unset($GLOBALS['meta_cache'][$args[0]]);if(($args[1]??'')==='posts')unset($GLOBALS['post_cache'][$args[0]]);}
function wp_update_post(array $data,bool $wp_error=false){
 if(!empty($GLOBALS['fail_post']))return new WP_Error();
 $id=(int)$data['ID']; foreach(wp_unslash($data) as $key=>$value)if($key!=='ID')$GLOBALS['posts'][$id]->$key=$value;
 // A body save can incidentally copy builder data; this must not become intent.
 if(!empty($GLOBALS['incidental_meta']))$GLOBALS['meta'][$id]['_elementor_data']=['WRONG INCIDENTAL COPY'];
 if(!empty($GLOBALS['use_cache']))clean_post_cache($id);
 return $id;
}
class FixtureDb {
 public string $posts='posts'; public string $postmeta='postmeta';
 public function update($table,$values,$where,...$args){foreach($values as $key=>$v)$GLOBALS['posts'][(int)$where['ID']]->$key=$v;return 1;}
 public function delete($table,$where,...$args){unset($GLOBALS['meta'][(int)$where['post_id']][$where['meta_key']]);return 1;}
 public function insert($table,$row,...$args){$v=$row['meta_value'];if(is_string($v)&&preg_match('/^(?:a|O):/',$v))$v=unserialize($v);$GLOBALS['meta'][(int)$row['post_id']][$row['meta_key']][]=$v;return 1;}
}
$GLOBALS['wpdb']=new FixtureDb();
$base=$argv[1]??dirname(__DIR__,2);
$core_only=($argv[2]??'')==='core-only';
require $base.'/mcp-expose-abilities/mcp-expose-abilities.php';
if(!$core_only)require $base.'/mcp-abilities-elementor/mcp-abilities-elementor.php';
mcp_register_content_abilities();
function check($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,'FAIL: '.$message.'\n');exit(1);}}
function content(int $id,string $body,string $ability='content/update-page'):void{
 $result=($GLOBALS['abilities'][$ability]['execute_callback'])(['id'=>$id,'content'=>$body]);check(true,$result['success']??false,'native Content callback');
}
if(in_array($argv[2]??'', ['foreign-cache','foreign-cache-meta','foreign-cache-author','foreign-cache-text-fields'],true)){
 $GLOBALS['use_cache']=true;$GLOBALS['siblings'][1]=[2];$GLOBALS['siblings'][2]=[1];
 $GLOBALS['posts'][1]=new WP_Post(1,'Norwegian original');$GLOBALS['posts'][2]=new WP_Post(2,'English original');
 $GLOBALS['meta'][1]['_elementor_data']=['NO'];$GLOBALS['meta'][2]['_elementor_data']=['EN'];
 $english_rows=['EN'];if(($argv[2]??'')==='foreign-cache-text-fields'){$nodes=[];for($i=0;$i<12;$i++)$nodes[]=['id'=>'english'.$i,'elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>English field '.$i.' "quoted" <a href="/en/contact/">Contact</a></p>']];$english_rows=[wp_json_encode($nodes)];$GLOBALS['meta'][2]['_elementor_data']=$english_rows;}
 $snapshot=mcp_expose_capture_translation_sibling_state(1);
 // A frontend HTTP request changes the database while the writer retains its local object cache.
 $GLOBALS['posts'][2]->post_content='WRONG SOURCE BODY';$GLOBALS['posts'][2]->post_author='5';$GLOBALS['meta'][2]['_elementor_data']=['WRONG SOURCE DATA'];
 mcp_expose_restore_translation_sibling_state($snapshot);
 if(($argv[2]??'')==='foreign-cache')check('English original',$GLOBALS['posts'][2]->post_content,'foreign-request body must be restored in database');
 if(($argv[2]??'')!=='foreign-cache-author')check($english_rows,$GLOBALS['meta'][2]['_elementor_data'],'foreign-request metadata must be restored in database');else check('20',$GLOBALS['posts'][2]->post_author,'untouched sibling author must be restored');
 echo 'Foreign-request stale-cache preservation passed.'.PHP_EOL;exit(0);
}
if(($argv[2]??'')==='foreign-cache-intent'){
 $GLOBALS['use_cache']=true;$GLOBALS['siblings'][1]=[2];$GLOBALS['siblings'][2]=[1];
 $GLOBALS['posts'][1]=new WP_Post(1,'Norwegian original');$GLOBALS['posts'][2]=new WP_Post(2,'English original');
 $GLOBALS['meta'][1]['_elementor_data']=['NO'];$GLOBALS['meta'][2]['_elementor_data']=['EN'];
 $snapshot=mcp_expose_capture_translation_sibling_state(1);mcp_expose_schedule_translation_sibling_state_restore($snapshot);
 $GLOBALS['posts'][2]->post_content='WRONG SOURCE BODY';$GLOBALS['posts'][2]->post_author='5';$GLOBALS['meta'][2]['_elementor_data']=['WRONG SOURCE DATA'];
 $r=($GLOBALS['abilities']['content/update-post']['execute_callback'])(['id'=>2,'author_id'=>25]);check(true,$r['success'],'explicit target author callback');
 register_shutdown_function(static function():void{
  check('English original',$GLOBALS['posts'][2]->post_content,'author-only edit preserves protected body');
  check(['EN'],$GLOBALS['meta'][2]['_elementor_data'],'author-only edit preserves protected builder rows');
  check(25,$GLOBALS['posts'][2]->post_author,'explicit author remains authoritative');
  echo 'Stale-cache restoration preserves explicit target author.'.PHP_EOL;
 });exit(0);
}
$expected=[];
foreach([1,11,21,31,41,51,61,71] as $source){
 $target=$source+1;$GLOBALS['siblings'][$source]=[$target];$GLOBALS['siblings'][$target]=[$source];
 $GLOBALS['posts'][$source]=new WP_Post($source,'Norwegian original');$GLOBALS['posts'][$target]=new WP_Post($target,'English original');
 $GLOBALS['meta'][$source]['_elementor_data']=['NO'];$GLOBALS['meta'][$target]['_elementor_data']=['EN'];
 $GLOBALS['meta'][$source]['_elementor_page_settings']=[['hide_title'=>'yes']];$GLOBALS['meta'][$target]['_elementor_page_settings']=[['hide_title'=>'yes']];
}
// Model the real late WPML derived-body/meta mutation before final restoration.
register_shutdown_function(static function():void{foreach($GLOBALS['siblings'] as $id=>$siblings){if($id%10===1)foreach($siblings as $sibling){$GLOBALS['posts'][$sibling]->post_content='WRONG SOURCE BODY';$GLOBALS['meta'][$sibling]['_elementor_data']=['WRONG SOURCE DATA'];}}});
if($core_only){
 content(1,'Norwegian edited');content(2,'English first');content(2,'English last');content(1,'Norwegian final');
 register_shutdown_function(static function():void{check('Norwegian final',get_post(1)->post_content,'Core-only source latest');check('English last',get_post(2)->post_content,'Core-only target latest');check(['EN'],get_post_meta(2,'_elementor_data',false),'Core-only untouched builder rows');echo 'Core-only Content guards passed.'.PHP_EOL;});
 exit(0);
}
// Proven Content-after-Elementor ordering, including second explicit body value.
mcp_abilities_elementor_update_guarded_elementor_data(1,wp_slash('NO EDIT'));
content(2,'English first');content(2,'English last "quote" C:\\files');
$expected[1]=['body'=>'Norwegian original','data'=>['NO EDIT']];$expected[2]=['body'=>'English last "quote" C:\\files','data'=>['EN']];
// Reverse direction: old Core meta snapshots must not undo a later target edit.
content(11,'Norwegian edited');
mcp_abilities_elementor_update_guarded_elementor_data(12,wp_slash('EN EDIT'));
mcp_abilities_elementor_update_guarded_page_settings(12,wp_slash(['hide_title'=>'no','nested'=>['quote'=>'"English" C:\\files']]));
$expected[11]=['body'=>'Norwegian edited','data'=>['NO']];$expected[12]=['body'=>'English original','data'=>['EN EDIT'],'settings'=>[['hide_title'=>'no','nested'=>['quote'=>'"English" C:\\files']]]];
// Content must not accept incidental metadata mutations as explicit intent.
mcp_abilities_elementor_update_guarded_elementor_data(21,wp_slash('NO EDIT'));
$GLOBALS['incidental_meta']=true;content(22,'English body only');$GLOBALS['incidental_meta']=false;
$expected[22]=['body'=>'English body only','data'=>['EN']];
// Empty body is an explicit value. Omitted body on another edit stays protected.
mcp_abilities_elementor_update_guarded_elementor_data(31,wp_slash('NO EDIT'));
content(32,'');($GLOBALS['abilities']['content/update-page']['execute_callback'])(['id'=>32,'title'=>'English title']);
$expected[32]=['body'=>'','data'=>['EN']];
// Failed writes must not refresh pending target fields.
mcp_abilities_elementor_update_guarded_elementor_data(41,wp_slash('NO EDIT'));
$GLOBALS['fail_post']=true;$failed=($GLOBALS['abilities']['content/update-page']['execute_callback'])(['id'=>42,'content'=>'FAILED']);$GLOBALS['fail_post']=false;check(false,$failed['success'],'failed Content result');
$GLOBALS['fail_meta']=true;mcp_abilities_elementor_update_guarded_elementor_data(42,wp_slash('FAILED'));$GLOBALS['fail_meta']=false;
$expected[42]=['body'=>'English original','data'=>['EN']];
// Preserve absent/multiple rows in a combined save, using original native rows.
unset($GLOBALS['meta'][52]['_elementor_data']);$GLOBALS['meta'][52]['_thumbnail_id']=['7','8'];
content(51,'Norwegian edited');mcp_abilities_elementor_update_guarded_page_settings(51,wp_slash(['hide_title'=>'no']));
$expected[52]=['body'=>'English original','data'=>[],'thumbnail'=>['7','8']];
// The real post callback has the same explicit-field completion semantics.
content(61,'Norwegian edited','content/update-post');mcp_abilities_elementor_update_guarded_elementor_data(62,wp_slash('EN POST EDIT'));content(62,'English post edited','content/update-post');
$expected[62]=['body'=>'English post edited','data'=>['EN POST EDIT']];
// A later Elementor edit must not bless a body copied by an incidental hook.
content(71,'Norwegian edited');$GLOBALS['posts'][72]->post_content='WRONG INCIDENTAL BODY';mcp_abilities_elementor_update_guarded_elementor_data(72,wp_slash('EN EDIT'));
$expected[72]=['body'=>'English original','data'=>['EN EDIT']];
register_shutdown_function(static function()use($expected):void{
 foreach($expected as $id=>$fields){check($fields['body'],get_post($id)->post_content,'final body '.$id);check($fields['data'],get_post_meta($id,'_elementor_data',false),'final data rows '.$id);if(isset($fields['settings']))check($fields['settings'],get_post_meta($id,'_elementor_page_settings',false),'native settings '.$id);if(isset($fields['thumbnail']))check($fields['thumbnail'],get_post_meta($id,'_thumbnail_id',false),'multiple rows '.$id);}
 echo 'Combined native Content/Elementor guards passed (8 scenarios, both languages).'.PHP_EOL;
});
