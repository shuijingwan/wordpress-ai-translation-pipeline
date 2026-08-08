<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( ...$args ): void {}
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private $data = null
	) {}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

require __DIR__ . '/sources/mu-plugins/swq-glm52-translation-tuning.php';

function invoke_plaintext_method( string $method, ...$args ) {
	$reflection = new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method );
	return $reflection->invoke( null, ...$args );
}

function plaintext_check( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}

	echo "PASS: {$name}\n";
}

function protected_tokens( string $payload ): array {
	preg_match_all(
		'/SWQ(?:BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)\d{6}END/',
		$payload,
		$matches
	);

	return $matches[0] ?? [];
}

function code_block_pro( string $language, string $code ): string {
	$attributes = json_encode(
		[
			'language' => $language,
			'code'     => $code,
			'codeHTML' => '',
		],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	return '<!-- wp:kevinbatdorf/code-block-pro ' . $attributes . ' -->'
		. '<pre><code>' . htmlspecialchars( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</code></pre>'
		. '<!-- /wp:kevinbatdorf/code-block-pro -->';
}

$three_lines = "第一行\n第二行\n第三行";
$one_line    = 'The first, second, and third lines form one natural English sentence.';
plaintext_check(
	$one_line === invoke_plaintext_method( 'normalize_plaintext_translation', $one_line, $three_lines ),
	'three source lines may merge into one translated line'
);

$eleven_lines = implode( "\n", range( 1, 11 ) ) . "\n";
$ten_lines    = implode( "\n", range( 1, 10 ) );
plaintext_check(
	$ten_lines . "\n" === invoke_plaintext_method( 'normalize_plaintext_translation', $ten_lines, $eleven_lines ),
	'eleven source lines may become ten translated lines while source trailing newline is retained'
);

$plain_regions = [
	[
		'start_token' => 'SWQPLAINSTART000001END',
		'stop_token'  => 'SWQPLAINSTOP000001END',
		'source_text' => $three_lines,
	],
];
$valid_plain_payload = "SWQPLAINSTART000001END\n{$one_line}\nSWQPLAINSTOP000001END";
plaintext_check(
	true === invoke_plaintext_method( 'validate_plaintext_regions', $valid_plain_payload, $plain_regions ),
	'non-empty block-level Plaintext region passes'
);

$error = invoke_plaintext_method(
	'validate_plaintext_regions',
	"SWQPLAINSTART000001END\n\nSWQPLAINSTOP000001END",
	$plain_regions
);
plaintext_check(
	is_wp_error( $error )
		&& 'swq_full_article_plaintext_translation_empty' === $error->get_error_code()
		&& 422 === ( $error->get_error_data()['status'] ?? 0 )
		&& false === ( $error->get_error_data()['retryable'] ?? null )
		&& 'plaintext_structure_error' === ( $error->get_error_data()['swq_error_type'] ?? '' ),
	'whole Plaintext region omission fails as a diagnostic non-retryable HTTP 422 structure error'
);

$error = invoke_plaintext_method(
	'validate_plaintext_regions',
	"SWQPLAINSTART000001END\ntranslated\nSWQSTRUCT000002END\nSWQPLAINSTOP000001END",
	$plain_regions
);
plaintext_check(
	is_wp_error( $error ) && 'swq_full_article_plaintext_boundary_drift' === $error->get_error_code(),
	'Plaintext cannot swallow an adjacent protected boundary'
);

$expected_plain_tokens = [ 'SWQPLAINSTART000001END', 'SWQPLAINSTOP000001END' ];
foreach (
	[
		'marker omission'       => 'SWQPLAINSTART000001END translated',
		'marker duplication'    => $valid_plain_payload . "\nSWQPLAINSTOP000001END",
		'marker wrong order'    => "SWQPLAINSTOP000001END\ntranslated\nSWQPLAINSTART000001END",
		'marker renumbering'    => str_replace( 'SWQPLAINSTOP000001END', 'SWQPLAINSTOP000002END', $valid_plain_payload ),
	] as $name => $payload
) {
	plaintext_check(
		is_wp_error(
			invoke_plaintext_method(
				'validate_protected_tokens',
				$payload,
				$expected_plain_tokens,
				$valid_plain_payload,
				[],
				$payload
			)
		),
		$name . ' fails strict protected-token validation'
	);
}

$gutenberg = '<!-- wp:paragraph --><p>正文</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2>标题</h2><!-- /wp:heading -->';
$protected = invoke_plaintext_method( 'protect_content', $gutenberg );
plaintext_check( is_array( $protected ), 'normal Gutenberg content protection succeeds' );
$gutenberg_tokens = protected_tokens( $protected['payload'] );
$damaged_payload  = preg_replace( '/SWQSTRUCT000002END/', '', $protected['payload'], 1 );
plaintext_check(
	is_wp_error(
		invoke_plaintext_method(
			'validate_protected_tokens',
			$damaged_payload,
			$gutenberg_tokens,
			$protected['payload'],
			$protected['map'],
			$damaged_payload
		)
	),
	'Gutenberg boundary damage still fails'
);

$nontranslatable = '<!-- wp:syntaxhighlighter/code -->'
	. "<pre>curl --fail https://example.com/api\n/etc/nginx/nginx.conf\nstatus=500</pre>"
	. '<!-- /wp:syntaxhighlighter/code -->'
	. '<p>Run <code>wp cache flush --all</code> only when authorized.</p>';
$protected = invoke_plaintext_method( 'protect_content', $nontranslatable );
$restored  = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $protected['payload'] );
plaintext_check(
	$nontranslatable === $restored,
	'code block, command, URL, path, status, and inline code restore byte-for-byte'
);

$normal = '<!-- wp:paragraph --><p>普通中文段落。</p><!-- /wp:paragraph -->';
$protected = invoke_plaintext_method( 'protect_content', $normal );
$restored  = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $protected['payload'] );
plaintext_check( $normal === $restored, 'existing normal protection and restoration still pass' );

$php_block = code_block_pro( 'php', '<?php echo "hello";' );
$protected = invoke_plaintext_method( 'protect_content', $php_block );
plaintext_check(
	'SWQBLOCK000001END' === $protected['payload'] && [] === $protected['plain_regions'],
	'PHP Code Block Pro remains a complete SWQBLOCK'
);

$han_block = code_block_pro( 'plaintext', "中文说明\n/path/to/file" );
$protected = invoke_plaintext_method( 'protect_content', $han_block );
plaintext_check(
	str_contains( $protected['payload'], 'SWQPLAINSTART000001END' )
		&& str_contains( $protected['payload'], '中文说明' )
		&& 1 === count( $protected['plain_regions'] ),
	'Plaintext Code Block Pro with Han text remains an editable SWQPLAIN region'
);
plaintext_check(
	true === invoke_plaintext_method( 'validate_plaintext_regions', $protected['payload'], $protected['plain_regions'] ),
	'Han Plaintext Code Block Pro retains existing structure validation'
);

$english_block = code_block_pro( 'text', "Release notes\nhttps://example.com/docs" );
$protected = invoke_plaintext_method( 'protect_content', $english_block );
plaintext_check(
	'SWQBLOCK000001END' === $protected['payload']
		&& ! str_contains( $protected['payload'], 'Release notes' )
		&& [] === $protected['plain_regions'],
	'English-only Plaintext Code Block Pro is hidden behind SWQBLOCK'
);
$restored = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $protected['payload'] );
plaintext_check(
	$english_block === $restored,
	'English-only Plaintext Code Block Pro restores byte-for-byte'
);

$machine_log_block = code_block_pro(
	'txt',
	"/var/log/nginx/access.log\n-rw-r--r-- 1 root root 4096 Aug 08 deploy.tar.gz\nstatus=500"
);
$protected = invoke_plaintext_method( 'protect_content', $machine_log_block );
plaintext_check(
	'SWQBLOCK000001END' === $protected['payload']
		&& ! str_contains( $protected['payload'], '/var/log/nginx/access.log' )
		&& [] === $protected['plain_regions'],
	'machine log and file-list Plaintext Code Block Pro is hidden behind SWQBLOCK'
);
$restored = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $protected['payload'] );
plaintext_check(
	$machine_log_block === $restored,
	'machine log and file-list Plaintext Code Block Pro restores byte-for-byte'
);

echo "ALL TESTS PASSED\n";
