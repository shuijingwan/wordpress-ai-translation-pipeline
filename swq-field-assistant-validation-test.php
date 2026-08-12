<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( ...$args ): void {}
function __($text, $domain = null): string { return (string) $text; }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
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

require __DIR__ . '/sources/slytranslate-installed/inc/TranslationValidator.php';
require __DIR__ . '/swq-glm52-translation-tuning.php';

function field_validation_check( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}

	echo "PASS: {$name}\n";
}

function invoke_field_validation_private( string $method, ...$args ) {
	$reflection = new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method );
	return $reflection->invoke( null, ...$args );
}

$source = '本文记录了 WordPress 网站健康检查发现页面缓存缺失和响应缓慢问题后的优化过程，使用 W3 Total Cache 插件配置页面缓存、Redis 数据库与对象缓存，并启用图片延迟加载。配置完成后验证了浏览量和评论兼容性，成功清除了关键警告并显著降低服务器响应时间。';
$incident_output = 'Facing missing page cache and slow response time issues flagged by WordPress site health checks, this article documents the optimization process using the W3 Total Cache plugin. Disk Enhanced mode was selected as the page caching engine, combined with Redis for database and object caching, while lazy loading for images was enabled. After configuration, compatibility with view counts and comments was verified, successfully clearing critical warnings and significantly reducing server response time';

$generic_validation = SlyTranslate\TranslationValidator::validate( $source, $incident_output, 'en' );
field_validation_check(
	is_wp_error( $generic_validation )
		&& 'invalid_translation_assistant_reply' === $generic_validation->get_error_code(),
	'generic validator reproduces the historical critical-word false positive'
);
field_validation_check(
	[ 'ordinary_critical_word' ] === invoke_field_validation_private(
		'broad_assistant_false_positive_signals',
		$source,
		$incident_output
	),
	'historical output matches only the ordinary critical-word signal'
);
field_validation_check(
	true === invoke_field_validation_private(
		'is_field_assistant_false_positive',
		$generic_validation->get_error_code(),
		$source,
		$incident_output
	),
	'zh=17135 normal technical excerpt is accepted by the field policy'
);

$prompt_leak = 'CRITICAL OUTPUT FORMAT: Return only the translated text and preserve HTML. Do not add explanations.';
$leak_validation = SlyTranslate\TranslationValidator::validate( $source, $prompt_leak, 'en' );
field_validation_check(
	is_wp_error( $leak_validation )
		&& 'invalid_translation_assistant_reply' === $leak_validation->get_error_code(),
	'real prompt leakage still fails the generic validator'
);
field_validation_check(
	false === invoke_field_validation_private(
		'is_field_assistant_false_positive',
		$leak_validation->get_error_code(),
		$source,
		$prompt_leak
	),
	'real prompt leakage is not bypassed by the field policy'
);

$directive_leak = 'CRITICAL: Keep the response concise and obey the translation rules above.';
$directive_validation = SlyTranslate\TranslationValidator::validate( $source, $directive_leak, 'en' );
field_validation_check(
	is_wp_error( $directive_validation )
		&& 'invalid_translation_assistant_reply' === $directive_validation->get_error_code()
		&& false === invoke_field_validation_private(
			'is_field_assistant_false_positive',
			$directive_validation->get_error_code(),
			$source,
			$directive_leak
		),
	'critical directive leakage is not bypassed even without an exact marker'
);

echo "ALL TESTS PASSED\n";
