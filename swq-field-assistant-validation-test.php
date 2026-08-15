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

$post_25534_source = 'A Tour of Go 简体中文 103 个正式发布页面完成自动翻译和结构校验后，我又进行了一轮完整的发布前正文质量审计。最终 87 页无需修改，14 页列为 C 类建议修订，2 页列为 D 类必须修订，共返修 16 页。';
$post_25534_output = 'After completing automated translation and structural validation for the 103 officially released Simplified Chinese pages of A Tour of Go, I conducted a complete pre-release content quality audit. Ultimately, 87 pages required no changes, 14 were classified as Category C suggested revisions, and 2 as Category D mandatory revisions, for a total of 16 revised pages.';
$post_25534_validation = SlyTranslate\TranslationValidator::validate( $post_25534_source, $post_25534_output, 'en' );
field_validation_check(
	is_wp_error( $post_25534_validation )
		&& 'invalid_translation_assistant_reply' === $post_25534_validation->get_error_code()
		&& true === invoke_field_validation_private(
			'is_field_assistant_false_positive',
			$post_25534_validation->get_error_code(),
			$post_25534_source,
			$post_25534_output
		),
	'zh=25534 Category D mandatory revisions excerpt is accepted by the field policy'
);

foreach (
	[
		'mandatory revisions' => 'The audit identified two mandatory revisions before publication.',
		'mandatory checks'     => 'All mandatory checks completed successfully before release.',
	] as $case_name => $ordinary_mandatory_output
) {
	$ordinary_mandatory_validation = SlyTranslate\TranslationValidator::validate( $source, $ordinary_mandatory_output, 'en' );
	field_validation_check(
		is_wp_error( $ordinary_mandatory_validation )
			&& 'invalid_translation_assistant_reply' === $ordinary_mandatory_validation->get_error_code()
			&& true === invoke_field_validation_private(
				'is_field_assistant_false_positive',
				$ordinary_mandatory_validation->get_error_code(),
				$source,
				$ordinary_mandatory_output
			),
		"ordinary {$case_name} is accepted by the field policy"
	);
}

$ordinary_critical_mandatory_output = 'The audit cleared the critical warnings after all mandatory checks completed successfully.';
$ordinary_critical_mandatory_validation = SlyTranslate\TranslationValidator::validate( $source, $ordinary_critical_mandatory_output, 'en' );
field_validation_check(
	is_wp_error( $ordinary_critical_mandatory_validation )
		&& 'invalid_translation_assistant_reply' === $ordinary_critical_mandatory_validation->get_error_code()
		&& [ 'ordinary_critical_word', 'ordinary_mandatory_word' ] === invoke_field_validation_private(
			'broad_assistant_false_positive_signals',
			$source,
			$ordinary_critical_mandatory_output
		)
		&& true === invoke_field_validation_private(
			'is_field_assistant_false_positive',
			$ordinary_critical_mandatory_validation->get_error_code(),
			$source,
			$ordinary_critical_mandatory_output
		),
	'ordinary critical and mandatory words together are accepted by the field policy'
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

$leak_cases = [
	'Mandatory label directive' => 'Mandatory: translation rules must be followed exactly.',
	'mandatory output format'   => 'Use the mandatory output format and obey every translation rule.',
	'CRITICAL OUTPUT FORMAT'    => 'CRITICAL OUTPUT FORMAT: Return only the translated text and preserve HTML.',
	'return only directive'     => 'Return only the translated text and preserve HTML.',
	'SlyTranslate wrapper'      => '<slytranslate>Translated content</slytranslate>',
];

foreach ( $leak_cases as $case_name => $leak_output ) {
	$case_validation = SlyTranslate\TranslationValidator::validate( $source, $leak_output, 'en' );
	field_validation_check(
		is_wp_error( $case_validation )
			&& 'invalid_translation_assistant_reply' === $case_validation->get_error_code()
			&& false === invoke_field_validation_private(
				'is_field_assistant_false_positive',
				$case_validation->get_error_code(),
				$source,
				$leak_output
			),
		"{$case_name} leakage is rejected by the field policy"
	);
}

$prompt_opener_leak = 'Translate the content from Chinese to English and return only the result.';
$prompt_opener_validation = SlyTranslate\TranslationValidator::validate( $source, $prompt_opener_leak, 'en' );
field_validation_check(
	is_wp_error( $prompt_opener_validation )
		&& 'invalid_translation_assistant_reply' === $prompt_opener_validation->get_error_code()
		&& false === invoke_field_validation_private(
			'is_field_assistant_false_positive',
			$prompt_opener_validation->get_error_code(),
			$source,
			$prompt_opener_leak
		),
	'explicit translation instruction opener is rejected by the field policy'
);

echo "ALL TESTS PASSED\n";
