<?php
/** Registered active-kit behavior at the WordPress option boundary. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
$abilities = array();
$caps = array( 'edit_posts' => true, 'manage_options' => true, 'edit_post' => true );
$posts = array(
  1 => (object) array( 'ID' => 1, 'post_type' => 'elementor_library', 'post_status' => 'publish' ),
  2 => (object) array( 'ID' => 2, 'post_type' => 'elementor_library', 'post_status' => 'publish' ),
  3 => (object) array( 'ID' => 3, 'post_type' => 'elementor_library', 'post_status' => 'trash' ),
);
$types = array( 1 => 'kit', 2 => 'section', 3 => 'kit' );
$active = 99;
$fail_write = false;
$clears = 0;
function mcp_abilities_elementor_register_ability( string $name, array $args ): void { $GLOBALS['abilities'][$name] = $args; }
function current_user_can( string $cap, ...$args ): bool { return $GLOBALS['caps'][$cap] ?? false; }
function get_post( int $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( int $id, string $key, bool $single ) { return '_elementor_template_type' === $key ? ($GLOBALS['types'][$id] ?? '') : ''; }
function get_option( string $name, $default = false ) { return 'elementor_active_kit' === $name ? $GLOBALS['active'] : $default; }
function update_option( string $name, $value ): bool {
  if ( $GLOBALS['fail_write'] || $GLOBALS['active'] === $value ) { return false; }
  $GLOBALS['active'] = $value; return true;
}
function mcp_abilities_elementor_clear_site_cache(): array { ++$GLOBALS['clears']; return array(); }
require __DIR__ . '/../includes/register-site-tools.php';
mcp_abilities_elementor_register_site_tool_abilities();
$a = $abilities['elementor/set-active-kit'];
$errors = array();
function check( bool $ok, string $label ): void { if (!$ok) { $GLOBALS['errors'][] = $label; } }
$caps['manage_options'] = false;
check( !$a['permission_callback'](), 'ordinary author cannot switch the site kit' );
$caps['manage_options'] = true;
foreach (array(2,3,999) as $id) {
  $active = 99; $clears = 0;
  $r = $a['execute_callback'](array('kit_id'=>$id));
  check(!$r['success'] && 99 === $active && 0 === $clears, 'reject invalid kit ' . $id);
}
$caps['edit_post'] = false; $active=99;
$r=$a['execute_callback'](array('kit_id'=>1));
check(!$r['success'] && 99===$active,'require edit permission on the selected kit');
$caps['edit_post']=true; $active=99; $fail_write=true; $clears=0;
$r=$a['execute_callback'](array('kit_id'=>1));
check(!$r['success'] && 99===$active && 0===$clears,'failed option write must not report success or clear cache');
$fail_write=false; $active=99; $clears=0;
$r=$a['execute_callback'](array('kit_id'=>1));
check($r['success'] && 1===$active && 1===$clears,'valid kit switches and clears cache');
$r=$a['execute_callback'](array('kit_id'=>1));
check($r['success'] && 1===$active,'already active kit remains a successful result');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "Active kit selection tests passed.\n";
