<?php
/**
 * Check snippet result assembly with an unmodified external MCP Adapter 0.7.1 fixture.
 * WordPress storage, permissions and WP_Ability are test doubles; no HTTP or database.
 * Set MCP_ELEMENTOR_ADAPTER_FIXTURE to an extracted upstream plugin directory.
 */
declare( strict_types=1 );

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\CallToolResult;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;

function __( string $text, ...$args ): string { return $text; }
function apply_filters( string $name, $value, ...$args ) { return $value; }
function do_action( ...$args ): void {}
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }

// Only the WordPress ability boundary is doubled. The converter, McpTool,
// ToolsHandler, request records and response records come from the real fixture.
class WP_Ability {
	public $result;
	public function __construct( private string $name, private array $args ) {}
	public function get_name(): string { return $this->name; }
	public function get_label(): string { return $this->args['label']; }
	public function get_description(): string { return $this->args['description']; }
	public function get_input_schema(): array { return $this->args['input_schema']; }
	public function get_output_schema(): array { return $this->args['output_schema']; }
	public function get_meta(): array { return $this->args['meta']; }
	public function check_permissions( $input ) { return ( $this->args['permission_callback'] )(); }
	public function execute( $input ) { return $this->result; }
}

ob_start();
require __DIR__ . '/run-custom-code.php';
$base_report = json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR );
$fixture = getenv( 'MCP_ELEMENTOR_ADAPTER_FIXTURE' );
if ( ! $fixture || ! is_file( $fixture . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Missing external MCP Adapter 0.7.1 fixture\n" );
	exit( 2 );
}
check( 1 === preg_match( '/Version:\s+0\.7\.1\s/', file_get_contents( $fixture . '/mcp-adapter.php' ) ), 'Use the inspected upstream 0.7.1 fixture' );
require $fixture . '/vendor/autoload.php';
$before_adapter = $checks;
$fixture_abilities = $tools = array();
foreach ( array( 'list', 'get', 'create', 'update', 'delete' ) as $name ) {
	$ability_name = 'elementor/' . $name . '-custom-code';
	$fixture_abilities[ $ability_name ] = new WP_Ability( $ability_name, $abilities[ $ability_name ] );
	$tool = McpTool::fromAbility( $fixture_abilities[ $ability_name ] );
	check( $tool instanceof McpTool, 'Real adapter converts registered ' . $ability_name );
	$tools[ $tool->get_name() ] = $tool;
}
$server = new class( $tools ) extends McpServer {
	public function __construct( private array $fixture_tools ) { $this->error_handler = new NullMcpErrorHandler(); }
	public function get_mcp_tool( string $name ): ?McpTool { return $this->fixture_tools[ $name ] ?? null; }
	public function get_tools( Schema $schema ): array {
		return array_map( static fn( McpTool $tool ) => $tool->get_protocol_record( $schema ), $this->fixture_tools );
	}
};
$handler = new ToolsHandler( $server );
$adapter_cases = array();
$callback_cases = array_values( array_filter( $schema_cases, static fn( array $case ) => $case['executed'] ) );
// Match the client protocol relevant to the reported TypeScript SDK behavior.
$schema = Schemas::create()->forVersion( Schemas::V2025_11_25 );
$context = new McpRequestContext( $schema, new stdClass(), null, 'isolated-fixture' );
$list_request = $schema->fromArray( ListToolsRequest::class, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() ) );
$listed = json_decode( json_encode( $handler->list_tools( $list_request, $context ), JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
$listed_schemas = array_column( $listed['tools'], 'outputSchema', 'name' );
$call = static function ( string $name ) use ( $handler, $schema, $context ): array {
	$request = $schema->fromArray( CallToolRequest::class, array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array( 'name' => $name, 'arguments' => new stdClass() ) ) );
	$result = $handler->call_tool( $request, $context );
	$schema->fromArray( CallToolResult::class, $result );
	return json_decode( json_encode( $result, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
};
foreach ( $callback_cases as $case ) {
	$name = str_replace( '/', '-', $case['ability'] );
	$fixture_abilities[ $case['ability'] ]->result = $case['result'];
	$result = $call( $name );
	check( false === $result['isError'] && $case['result'] === $result['structuredContent'], 'Adapter preserves the snippet domain result, including success:false' );
	check( $case['result'] === json_decode( $result['content'][0]['text'], true ), 'Text and structured results agree' );
	$adapter_cases[] = array( 'ability' => $case['ability'], 'result' => $result['structuredContent'], 'schema' => $listed_schemas[ $name ] );
}
foreach ( $fixture_abilities as $ability_name => $ability ) {
	$name = str_replace( '/', '-', $ability_name );
	$capabilities['manage_options'] = false;
	$result = $call( $name );
	check( true === $result['isError'] && 'Permission denied' === $result['content'][0]['text'], 'Denied snippet remains a tool execution error' );
	check( ! array_key_exists( 'structuredContent', $result ), 'Denied call has no result to validate against outputSchema' );
	$capabilities['manage_options'] = true;
	// Represents a framework rejection before the snippet callback. This does
	// not claim to run WordPress input validation in the doubled WP_Ability.
	$ability->result = new WP_Error( 'ability_invalid_input', 'Invalid snippet input.', array( 'last_error' => 'MYSQL_DETAIL_DO_NOT_EXPORT' ) );
	$result = $call( $name );
	check( true === $result['isError'] && 'Invalid snippet input.' === $result['content'][0]['text'], 'Framework WP_Error keeps its text' );
	check( ! array_key_exists( 'structuredContent', $result ) && ! str_contains( json_encode( $result ), 'MYSQL_DETAIL_DO_NOT_EXPORT' ), 'Framework WP_Error has no structured error envelope or exported error_data' );
}

// Inspect the real generic execution schema too; no proposed upstream code is loaded.
\WP\MCP\Abilities\ExecuteAbilityAbility::register();
echo json_encode( array(
	'status' => 'PASS',
	'base_assertions' => $base_report['assertions'],
	'adapter_assertions' => $checks - $before_adapter,
	'adapter_version' => '0.7.1',
	'schema_cases' => $adapter_cases,
	'generic_execution_schema' => $abilities['mcp-adapter/execute-ability']['output_schema'],
), JSON_PRETTY_PRINT ) . "\n";
