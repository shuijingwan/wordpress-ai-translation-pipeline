<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function add_filter( ...$args ): void {}
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function shortcode_exists( string $tag ): bool { return in_array( $tag, [ 'gallery', 'contact-form-7' ], true ); }
class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

/* This compact fixture parser supplies the top-level AST facts used by this test. */
function parse_blocks( string $content ): array {
	preg_match_all( '~<!--\s+wp:([A-Za-z0-9_/-]+)(?:\s+(\{.*?\}))?\s+-->(.*?)<!--\s+/wp:\1\s+-->~su', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	$blocks = [];
	$cursor = 0;
	foreach ( $matches as $match ) {
		$offset = (int) $match[0][1];
		$freeform = substr( $content, $cursor, $offset - $cursor );
		if ( '' !== $freeform ) {
			$blocks[] = [
				'blockName' => null,
				'attrs' => [],
				'innerBlocks' => [],
				'innerHTML' => $freeform,
			];
		}
		$name = str_contains( $match[1][0], '/' ) ? $match[1][0] : 'core/' . $match[1][0];
		$attrs = '' === ( $match[2][0] ?? '' ) ? [] : ( json_decode( $match[2][0], true ) ?: [ '_invalid' => true ] );
		$blocks[] = [
			'blockName' => $name,
			'attrs' => $attrs,
			'innerBlocks' => [],
			'innerHTML' => $match[3][0],
		];
		$cursor = $offset + strlen( $match[0][0] );
	}
	$freeform = substr( $content, $cursor );
	if ( '' !== $freeform ) {
		$blocks[] = [
			'blockName' => null,
			'attrs' => [],
			'innerBlocks' => [],
			'innerHTML' => $freeform,
		];
	}
	return $blocks;
}

require __DIR__ . '/swq-glm52-translation-tuning.php';

function para_invoke( string $method, ...$args ) {
	return ( new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method ) )->invoke( null, ...$args );
}
function para_check( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}
	echo "PASS: {$name}\n";
}
function para( string $text, string $attrs = '' ): string {
	return '<!-- wp:paragraph' . $attrs . ' --><p>' . $text . '</p><!-- /wp:paragraph -->';
}
function block( string $name, string $html ): string {
	return '<!-- wp:' . $name . ' -->' . $html . '<!-- /wp:' . $name . ' -->';
}
function code_block_pro( string $language, string $code, array $extra_attributes = [] ): string {
	$attributes = json_encode( array_merge( [ 'language' => $language, 'code' => $code, 'codeHTML' => '' ], $extra_attributes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return '<!-- wp:kevinbatdorf/code-block-pro ' . $attributes . ' --><pre><code>' . htmlspecialchars( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</code></pre><!-- /wp:kevinbatdorf/code-block-pro -->';
}
function para_tokens( string $payload ): array {
	preg_match_all( '/SWQ(?:BLOCK|INLINE|HTML|STRUCT|PARARUNSTART|PARARUNSTOP|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)\d{6}END/', $payload, $matches );
	return $matches[0] ?? [];
}
function protect( string $content ): array { return para_invoke( 'protect_content', $content ); }
function validate_runs( string $payload, array $runs ) { return para_invoke( 'validate_paragraph_runs', $payload, $runs ); }
function restore_runs( string $payload, array $runs ) { return para_invoke( 'restore_paragraph_runs', $payload, $runs ); }

@unlink( '/tmp/swq-glm52-gutenberg-structure-drift-summary.json' );
@unlink( '/tmp/swq-glm52-gutenberg-structure-drift-restored.txt' );

$source = para( 'A' ) . para( 'B' ) . block( 'heading', '<h2>H</h2>' ) . para( 'C' ) . para( 'D' );
$protected = protect( $source );
para_check( 2 === count( $protected['paragraph_runs'] ), 'heading separates two top-level paragraph runs' );
para_check( 4 === substr_count( $protected['payload'], 'SWQPARARUN' ), 'two runs use four fixed PARARUN boundaries' );
para_check( 2 === substr_count( $protected['payload'], 'SWQSTRUCT' ), 'ordinary paragraph comments no longer become STRUCT while heading does' );

$single = protect( para( 'Only one' ) );
para_check( 1 === count( $single['paragraph_runs'] ), 'a single eligible top-level paragraph forms one run' );

foreach ( [
	'image' => para( 'A' ) . block( 'image', '<figure><img src="x"></figure>' ) . para( 'B' ),
	'quote' => para( 'A' ) . block( 'quote', '<blockquote><p>Q</p></blockquote>' ) . para( 'B' ),
	'list' => para( 'A' ) . block( 'list', '<ul><li>L</li></ul>' ) . para( 'B' ),
	'code block' => para( 'A' ) . block( 'code', '<pre>x</pre>' ) . para( 'B' ),
] as $name => $content ) {
	$protected = protect( $content );
	para_check( 2 === count( $protected['paragraph_runs'] ), $name . ' terminates paragraph runs' );
}
$self_closing = protect( para( 'A' ) . '<!-- wp:shortcode /-->' . para( 'B' ) );
para_check( 2 === count( $self_closing['paragraph_runs'] ), 'self-closing Gutenberg block terminates runs without disabling them' );
$code_block_protected = protect( para( 'A' ) . code_block_pro( 'php', '<?php echo 1;' ) . para( 'B' ) );
para_check( 2 === count( $code_block_protected['paragraph_runs'] ) && str_contains( $code_block_protected['payload'], 'SWQBLOCK000001END' ), 'Code Block Pro remains protected and terminates runs' );
$plaintext_block_protected = protect( para( 'A' ) . code_block_pro( 'plaintext', "中文说明\n/path" ) . para( 'B' ) );
para_check( 2 === count( $plaintext_block_protected['paragraph_runs'] ) && 1 === count( $plaintext_block_protected['plain_regions'] ), 'Plaintext restoration region terminates runs without changing its semantics' );
$image_alt_protected = protect( para( 'A' ) . block( 'image', '<figure><img src="x" alt="中文图"></figure>' ) . para( 'B' ) );
para_check( 2 === count( $image_alt_protected['paragraph_runs'] ) && 1 === count( $image_alt_protected['alt_regions'] ), 'image ALT region terminates runs without changing its semantics' );

$attribute = protect( para( 'A', ' {"dropCap":true}' ) );
para_check( [] === $attribute['paragraph_runs'] && 2 === substr_count( $attribute['payload'], 'SWQSTRUCT' ), 'attribute paragraph remains on the strict STRUCT path' );
$quote_inner = protect( block( 'quote', '<blockquote>' . para( 'Q' ) . '</blockquote>' ) );
para_check( [] === $quote_inner['paragraph_runs'], 'quote-internal paragraph does not form a run' );
$group_inner = protect( block( 'group', '<div>' . para( 'G' ) . '</div>' ) );
para_check( [] === $group_inner['paragraph_runs'], 'group-internal paragraph does not form a run' );
$shortcode = protect( para( 'Before [gallery id="1"] after' ) );
para_check( [] === $shortcode['paragraph_runs'], 'shortcode paragraph remains on the strict STRUCT path' );
$plain_brackets_source = para( '【订阅号与服务号认证后均可用】' );
$plain_brackets_restored = para( '[available for both subscription and service accounts after authentication]' );
para_check( 1 === count( protect( $plain_brackets_restored )['paragraph_runs'] ), 'ordinary ASCII bracketed prose forms a paragraph run' );
para_check(
	para_invoke( 'paragraph_run_block_signature', $plain_brackets_source ) === para_invoke( 'paragraph_run_block_signature', $plain_brackets_restored ),
	'post 5050 full-width brackets translated to ordinary ASCII brackets keep the paragraph-run signature'
);
$registered_shortcode_without_attributes = protect( para( 'Before [gallery] after' ) );
para_check( [] === $registered_shortcode_without_attributes['paragraph_runs'], 'registered shortcode without attributes remains on the strict STRUCT path' );
$registered_shortcode_pair = protect( para( 'Before [contact-form-7]form[/contact-form-7] after' ) );
para_check( [] === $registered_shortcode_pair['paragraph_runs'], 'registered enclosing shortcode remains on the strict STRUCT path' );

$protected = protect( para( 'A' ) . para( 'B' ) );
$run = $protected['paragraph_runs'][0];
$merged = $run['start_token'] . "\n<p>English A and B.</p>\n" . $run['stop_token'];
para_check( true === validate_runs( $merged, $protected['paragraph_runs'] ), 'post 25425 pattern: consecutive source paragraphs may merge without paragraph STRUCT tokens' );
$restored = restore_runs( $merged, $protected['paragraph_runs'] );
para_check( 1 === substr_count( $restored, '<!-- wp:paragraph -->' ) && str_contains( $restored, 'English A and B.' ), 'merged run restores one standard Gutenberg paragraph' );

$protected = protect( para( 'A' ) );
$run = $protected['paragraph_runs'][0];
$split = $run['start_token'] . "\n<p>English A.</p>\n<p>English B.</p>\n" . $run['stop_token'];
para_check( true === validate_runs( $split, $protected['paragraph_runs'] ), 'one source paragraph may split into two target paragraphs' );
para_check( 2 === substr_count( restore_runs( $split, $protected['paragraph_runs'] ), '<!-- wp:paragraph -->' ), 'split run restores two standard Gutenberg paragraphs' );

$protected = protect( para( 'A' ) . para( 'B' ) . para( 'C' ) );
$run = $protected['paragraph_runs'][0];
$three_to_two = $run['start_token'] . "\n<p>A and B.</p>\n<p>C.</p>\n" . $run['stop_token'];
para_check( true === validate_runs( $three_to_two, $protected['paragraph_runs'] ), 'three source paragraphs may become two target paragraphs' );

$wrapper_source = protect( para( 'A' ) . para( 'B' ) );
$wrapper_run = $wrapper_source['paragraph_runs'][0];
foreach ( [ 'class' => '<p class="model">English A.</p>', 'style' => '<p style="color:red">English A.</p>' ] as $attribute => $paragraph ) {
	$wrapper_payload = $wrapper_run['start_token'] . "\n" . $paragraph . "\n<p>English B.</p>\n" . $wrapper_run['stop_token'];
	para_check( true === validate_runs( $wrapper_payload, $wrapper_source['paragraph_runs'] ), 'model ' . $attribute . ' paragraph wrapper is accepted as a non-structural wrapper' );
	$wrapper_restored = restore_runs( $wrapper_payload, $wrapper_source['paragraph_runs'] );
	para_check( ! str_contains( $wrapper_restored, '<p ' ) && 2 === substr_count( $wrapper_restored, '<!-- wp:paragraph -->' ), 'model ' . $attribute . ' paragraph wrapper attributes are discarded during standard restoration' );
}

foreach ( [
	'zero paragraphs' => "\n",
	'empty paragraph' => '<p></p>',
	'whitespace-only paragraph' => '<p> &nbsp; </p>',
	'punctuation-only paragraph' => '<p>.,:;()【】</p>',
	'nested paragraph' => '<p>Outer <p>Inner</p></p>',
	'Gutenberg comment' => '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->',
	'block-level heading' => '<h2>H</h2>',
	'block-level list' => '<ul><li>A</li></ul>',
	'block-level image' => '<figure><img src="x"></figure>',
	'block-level quote' => '<blockquote>A</blockquote>',
	'block-level pre' => '<pre>A</pre>',
] as $name => $html ) {
	$payload = $run['start_token'] . "\n" . $html . "\n" . $run['stop_token'];
	para_check( is_wp_error( validate_runs( $payload, $protected['paragraph_runs'] ) ), $name . ' is rejected inside a run' );
}
foreach ( [
	'missing START' => "<p>A</p>\n" . $run['stop_token'],
	'missing STOP' => $run['start_token'] . "\n<p>A</p>",
	'duplicate boundary' => $run['start_token'] . "\n<p>A</p>\n" . $run['stop_token'] . "\n" . $run['stop_token'],
	'extra boundary' => $run['start_token'] . "\n<p>A</p>\n" . $run['stop_token'] . "\nSWQPARARUNSTART999999END",
	'nested boundary' => $run['start_token'] . "\nSWQPARARUNSTART999999END<p>A</p>SWQPARARUNSTOP999999END\n" . $run['stop_token'],
] as $name => $payload ) {
	para_check( is_wp_error( validate_runs( $payload, $protected['paragraph_runs'] ) ), $name . ' is rejected' );
}

$inline = protect( para( 'Run <code>wp cache flush</code> now.' ) );
$run = $inline['paragraph_runs'][0];
$inline_payload = $run['start_token'] . "\n<p>Run SWQINLINE000001END now.</p>\n" . $run['stop_token'];
para_check( true === validate_runs( $inline_payload, $inline['paragraph_runs'] ), 'SWQINLINE remains valid inside a paragraph run' );
$inline_restored = str_replace( array_keys( $inline['map'] ), array_values( $inline['map'] ), restore_runs( $inline_payload, $inline['paragraph_runs'] ) );
para_check( str_contains( $inline_restored, '<code>wp cache flush</code>' ), 'SWQINLINE restores after paragraph-run restoration' );

$link = protect( para( '<a href="https://example.com">Link</a> text.' ) );
$run = $link['paragraph_runs'][0];
$link_payload = $run['start_token'] . "\n<p><a href=\"https://example.com\">Link</a> text.</p>\n" . $run['stop_token'];
para_check( true === validate_runs( $link_payload, $link['paragraph_runs'] ), 'existing inline links remain valid inside a paragraph run' );

$expected = para_tokens( $protected['payload'] );
para_check( is_wp_error( para_invoke( 'validate_protected_tokens', str_replace( $run['start_token'], '', $protected['payload'] ), $expected, $protected['payload'], $protected['map'], '' ) ), 'PARARUN START missing fails token validation' );
para_check( is_wp_error( para_invoke( 'validate_protected_tokens', $protected['payload'] . "\n" . $run['stop_token'], $expected, $protected['payload'], $protected['map'], '' ) ), 'PARARUN duplicate fails token validation' );

$fixed_source = protect( para( 'A' ) . block( 'heading', '<h2>H</h2>' ) . para( 'B' ) );
$fixed_expected = para_tokens( $fixed_source['payload'] );
$fixed_actual = array_reverse( $fixed_expected );
para_check( is_wp_error( para_invoke( 'validate_protected_tokens', implode( "\n", $fixed_actual ), $fixed_expected, $fixed_source['payload'], $fixed_source['map'], '' ) ), 'fixed Gutenberg structure reordering still fails' );
para_check( is_wp_error( para_invoke( 'validate_protected_tokens', str_replace( 'SWQSTRUCT000001END', '', $fixed_source['payload'] ), $fixed_expected, $fixed_source['payload'], $fixed_source['map'], '' ) ), 'high-risk STRUCT missing still fails' );
para_check( is_wp_error( para_invoke( 'validate_protected_tokens', $fixed_source['payload'] . "\nSWQSTRUCT000001END", $fixed_expected, $fixed_source['payload'], $fixed_source['map'], '' ) ), 'high-risk STRUCT duplicate still fails' );

$signature_source = para( 'A' ) . para( 'B' ) . block( 'heading', '<h2>H</h2>' ) . para( 'C' ) . para( 'D' );
$signature_target = para( 'A and B' ) . block( 'heading', '<h2>H</h2>' ) . para( 'C' ) . para( 'D' ) . para( 'E' );
para_check( para_invoke( 'paragraph_run_block_signature', $signature_source ) === para_invoke( 'paragraph_run_block_signature', $signature_target ), 'normalized signature accepts paragraph count changes within separate runs' );

$plaintext_signature_source = code_block_pro( 'plaintext', "正文 A\n正文 B", [ 'codeHTML' => '<pre>中文</pre>', 'highestLineNumber' => 2, 'theme' => 'one-dark', 'lineNumbers' => true ] );
$plaintext_signature_restored = code_block_pro( 'plaintext', "Body A\nBody B\nBody C", [ 'codeHTML' => '<pre>English</pre>', 'highestLineNumber' => 3, 'theme' => 'one-dark', 'lineNumbers' => true ] );
para_check( para_invoke( 'paragraph_run_block_signature', $plaintext_signature_source ) === para_invoke( 'paragraph_run_block_signature', $plaintext_signature_restored ), 'Plaintext Code Block Pro content attributes are normalized after restoration' );
$plaintext_theme_changed = code_block_pro( 'plaintext', "Body A\nBody B\nBody C", [ 'codeHTML' => '<pre>English</pre>', 'highestLineNumber' => 3, 'theme' => 'github-light', 'lineNumbers' => true ] );
para_check( para_invoke( 'paragraph_run_block_signature', $plaintext_signature_source ) !== para_invoke( 'paragraph_run_block_signature', $plaintext_theme_changed ), 'Plaintext Code Block Pro non-content configuration remains strict' );
$plaintext_line_numbers_changed = code_block_pro( 'plaintext', "Body A\nBody B\nBody C", [ 'codeHTML' => '<pre>English</pre>', 'highestLineNumber' => 3, 'theme' => 'one-dark', 'lineNumbers' => false ] );
para_check( para_invoke( 'paragraph_run_block_signature', $plaintext_signature_source ) !== para_invoke( 'paragraph_run_block_signature', $plaintext_line_numbers_changed ), 'Plaintext Code Block Pro line-number configuration remains strict' );
$plaintext_language_changed = code_block_pro( 'php', "Body A\nBody B\nBody C", [ 'codeHTML' => '<pre>English</pre>', 'highestLineNumber' => 3, 'theme' => 'one-dark', 'lineNumbers' => true ] );
para_check( para_invoke( 'paragraph_run_block_signature', $plaintext_signature_source ) !== para_invoke( 'paragraph_run_block_signature', $plaintext_language_changed ), 'Plaintext Code Block Pro language remains strict' );
$non_plaintext_signature_source = code_block_pro( 'php', '<?php echo "中文";', [ 'theme' => 'one-dark' ] );
$non_plaintext_signature_restored = code_block_pro( 'php', '<?php echo "English";', [ 'theme' => 'one-dark' ] );
para_check( para_invoke( 'paragraph_run_block_signature', $non_plaintext_signature_source ) !== para_invoke( 'paragraph_run_block_signature', $non_plaintext_signature_restored ), 'non-Plaintext Code Block Pro attributes remain fully strict' );

$whitespace_run_source = para( 'A' ) . "\n \t\n" . para( 'B' );
$whitespace_run_restored = para( 'A and B' );
para_check( [ '0:PARAGRAPH_RUN' ] === para_invoke( 'paragraph_run_block_signature', $whitespace_run_source ), 'whitespace-only freeform does not split a paragraph run' );
para_check( para_invoke( 'paragraph_run_block_signature', $whitespace_run_source ) === para_invoke( 'paragraph_run_block_signature', $whitespace_run_restored ), 'different whitespace-only freeform representation does not drift' );
$meaningful_freeform = para( 'A' ) . '<div class="legacy">Visible freeform HTML</div>' . para( 'B' );
$meaningful_freeform_signature = para_invoke( 'paragraph_run_block_signature', $meaningful_freeform );
para_check( 3 === count( $meaningful_freeform_signature ) && str_contains( $meaningful_freeform_signature[1], ':FREEFORM:' ), 'meaningful freeform HTML has its own strict signature item' );
$separator_signature = para( 'A' ) . block( 'separator', '<hr class="wp-block-separator">' ) . para( 'B' );
para_check( 3 === count( para_invoke( 'paragraph_run_block_signature', $separator_signature ) ), 'separator remains a strict paragraph-run boundary' );
$quote_signature = para( 'A' ) . block( 'quote', '<blockquote><p>Q</p></blockquote>' ) . para( 'B' );
para_check( 3 === count( para_invoke( 'paragraph_run_block_signature', $quote_signature ) ), 'quote remains a strict paragraph-run boundary' );

$same_signature = para_invoke( 'paragraph_run_block_signature', $signature_source );
para_check( $same_signature === para_invoke( 'paragraph_run_block_signature', $signature_source ), 'matching normalized signatures do not drift' );
para_check( ! file_exists( '/tmp/swq-glm52-gutenberg-structure-drift-summary.json' ) && ! file_exists( '/tmp/swq-glm52-gutenberg-structure-drift-restored.txt' ), 'matching signatures do not create drift diagnostics' );

$drift_source = para( 'A' ) . block( 'heading', '<h2>H</h2>' ) . block( 'quote', '<blockquote><p>Q</p></blockquote>' ) . para( 'B' );
$drift_restored = para( 'A' ) . block( 'quote', '<blockquote><p>Q</p></blockquote>' ) . para( 'B' );
$drift_source_signature = para_invoke( 'paragraph_run_block_signature', $drift_source );
$drift_restored_signature = para_invoke( 'paragraph_run_block_signature', $drift_restored );
$drift = para_invoke( 'paragraph_run_structure_drift_diagnostic', $drift_source, $drift_restored, 25425, $drift_source_signature, $drift_restored_signature );
para_check( 1 === $drift['first_mismatch_index'], 'fixed heading omission reports the first mismatch index' );
para_check( '0:core/heading:' . hash( 'sha256', '[]' ) === $drift['source_item_at_mismatch']['signature'], 'heading omission reports the source fixed block' );
para_check( '0:core/quote:' . hash( 'sha256', '[]' ) === $drift['restored_item_at_mismatch']['signature'], 'heading omission reports the restored fixed block' );
para_check( 'core/heading' === $drift['source_item_at_mismatch']['block_name'] && 'core/quote' === $drift['restored_item_at_mismatch']['block_name'], 'drift items include readable block names' );
para_check( 4 === count( $drift['source_signature_context'] ) && 3 === count( $drift['restored_signature_context'] ), 'drift context includes neighboring items' );

$quote_source = para( 'A' ) . block( 'quote', '<blockquote><p>Q</p></blockquote>' ) . para( 'B' );
$quote_restored = para( 'A' ) . para( 'B' );
$quote_drift = para_invoke(
	'paragraph_run_structure_drift_diagnostic',
	$quote_source,
	$quote_restored,
	25425,
	para_invoke( 'paragraph_run_block_signature', $quote_source ),
	para_invoke( 'paragraph_run_block_signature', $quote_restored )
);
para_check( 'core/quote' === $quote_drift['source_item_at_mismatch']['block_name'] && 'EOF' === $quote_drift['restored_item_at_mismatch']['status'], 'quote loss and restored EOF are explicit in drift diagnostics' );

$candidate_file = '/tmp/swq-paragraph-run-diagnostic-candidate.txt';
$summary_file = '/tmp/swq-paragraph-run-diagnostic-summary.json';
@unlink( $candidate_file );
@unlink( $summary_file );
para_invoke( 'write_validation_diagnostic_files', $summary_file, $candidate_file, $drift, $drift_restored );
para_check( $drift_restored === file_get_contents( $candidate_file ), 'drift candidate file contains the post-restore Gutenberg candidate' );
para_check( 0600 === ( fileperms( $candidate_file ) & 0777 ) && 0600 === ( fileperms( $summary_file ) & 0777 ), 'drift diagnostics use restrictive permissions' );
@unlink( $candidate_file );
@unlink( $summary_file );

$post_5050_paths = para_invoke( 'gutenberg_structure_drift_diagnostic_paths', 5050 );
$post_5079_paths = para_invoke( 'gutenberg_structure_drift_diagnostic_paths', 5079 );
para_check(
	'/tmp/swq-glm52-gutenberg-structure-drift-5050-summary.json' === $post_5050_paths['summary']
		&& '/tmp/swq-glm52-gutenberg-structure-drift-5050-restored.txt' === $post_5050_paths['restored'],
	'Gutenberg structure drift diagnostics include the post ID'
);
para_check( $post_5050_paths !== $post_5079_paths, 'different posts cannot overwrite each other structure drift diagnostics' );

$prompt = para_invoke( 'build_content_prompt', 'en' );
para_check( str_contains( $prompt, 'SWQPARARUNSTART' ) && str_contains( $prompt, '一个或多个顶层且无 attributes 的 <p>' ), 'prompt contains paragraph-run rules' );
para_check( str_contains( $prompt, '不得留下仅包含句号、逗号、冒号、分号、括号或其他标点符号的独立 paragraph' ), 'prompt forbids punctuation-only standalone paragraphs' );

echo "ALL TESTS PASSED\n";
