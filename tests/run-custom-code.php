<?php
/** Preserve code through native WordPress unslashing on create and update. */
declare(strict_types=1);
define('ABSPATH',__DIR__);
$abilities=array();$posts=array();
function mcp_abilities_elementor_register_ability($n,$a):void{$GLOBALS['abilities'][$n]=$a;}
function post_type_exists($t):bool{return true;}
function current_user_can(...$a):bool{return true;}
function sanitize_text_field($s):string{return $s;}
function is_wp_error($v):bool{return false;}
function wp_slash($v){return is_array($v)?array_map('wp_slash',$v):(is_string($v)?addslashes($v):$v);}
function wp_insert_post($v,$e){$v=array_map(static fn($x)=>is_string($x)?stripslashes($x):$x,$v);$v['ID']=1;$GLOBALS['posts'][1]=(object)$v;return 1;}
function wp_update_post($v,$e){$v=array_map(static fn($x)=>is_string($x)?stripslashes($x):$x,$v);foreach($v as $k=>$x){$GLOBALS['posts'][$v['ID']]->$k=$x;}return $v['ID'];}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
require __DIR__.'/../includes/register-pro.php';
mcp_abilities_elementor_register_pro_abilities();
$code=<<<'CODE'
<script>const path = "C:\\files"; const pattern = /\d+/; const text = "a\"b";</script>
CODE;
$errors=array();
$r=$abilities['elementor/create-custom-code']['execute_callback'](array('title'=>'Example','code'=>$code,'status'=>'draft'));
if(!$r['success']||$posts[1]->post_content!==$code){$errors[]='create must preserve exact code backslashes and quotes';}
$code.="\n// changed";
$r=$abilities['elementor/update-custom-code']['execute_callback'](array('id'=>1,'code'=>$code));
if(!$r['success']||$posts[1]->post_content!==$code){$errors[]='update must preserve exact code backslashes and quotes';}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "Custom code storage tests passed.\n";
