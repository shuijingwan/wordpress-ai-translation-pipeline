<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'GLM53_WORKFLOW_FIXTURE', in_array( '--workflow', $argv, true ) );
	$GLOBALS['glm53_filters'] = [];
	function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ): void {
		$GLOBALS['glm53_filters'][ $tag ][ $priority ][] = [ $callback, $accepted ];
	}
	function remove_filter( $tag, $callback, $priority = 10 ): void {
		foreach ( $GLOBALS['glm53_filters'][ $tag ][ $priority ] ?? [] as $i => $entry ) {
			if ( $entry[0] === $callback ) {
				unset( $GLOBALS['glm53_filters'][ $tag ][ $priority ][ $i ] );
			}
		}
	}
	function apply_filters( $tag, $value, ...$args ) {
		$priorities = $GLOBALS['glm53_filters'][ $tag ] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $entries ) {
			foreach ( $entries as [ $callback, $accepted ] ) {
				$value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) );
			}
		}
		return $value;
	}
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function absint( $value ): int { return abs( (int) $value ); }
	function __( $text, $domain = null ): string { return (string) $text; }
	function wp_strip_all_tags( $text ): string { return strip_tags( $text ); }
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

namespace SlyTranslate {
	class ClientTranslationWorkflowService {
		public static int $prepare_count = 0;
		public static array $applied = [];
		public static string $source = '';
		public static function prepare_single( $input ) {
			self::$prepare_count++;
			if ( ! GLM53_WORKFLOW_FIXTURE ) {
				return new \WP_Error( 'offline_prepare_checkpoint', 'Stopped before translation.' );
			}
			return [ 'units' => [ [ 'id' => 'content', 'field' => 'content', 'source' => self::$source ] ] ];
		}
		public static function apply_single( $input ) {
			self::$applied[] = $input;
			return new \WP_Error( 'offline_apply_checkpoint', 'Validated package captured; no database exists in this fixture.' );
		}
	}
	if ( GLM53_WORKFLOW_FIXTURE ) {
		// Offline transport only: all request and response filters still execute.
		class TranslationRuntime {
			public static string $mode = 'stop';
			public static int $sent = 0;
			public static function with_model_slug_override( $input, $callback ) { return $callback(); }
			public static function should_skip_html_tag_validation(): bool { return false; }
			public static function translate_text_raw( $text, ...$unused ) {
				$url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
				$args = \request_args( 'glm-5.3' );
				$args['body'] = json_encode( [ 'model' => 'glm-5.3', 'messages' => [ [ 'role' => 'user', 'content' => $text ] ] ] );
				$args = \apply_filters( 'http_request_args', $args, $url );
				$preempt = \apply_filters( 'pre_http_request', false, $args, $url );
				if ( false !== $preempt ) { return $preempt; }
				self::$sent++;
				if ( 'timeout' === self::$mode ) {
					return new \WP_Error( 'http_request_failed', 'Offline timeout fixture' );
				}
				if ( 'retry' === self::$mode ) {
					return \apply_filters( 'pre_http_request', false, $args, $url );
				}
				$content = 'reasoning-only' === self::$mode ? '' : $text;
				if ( 'duplicate-token' === self::$mode ) {
					$content = str_replace( 'SWQTRANSLATIONTAILGUARDEND', 'SWQBLOCK000001END SWQTRANSLATIONTAILGUARDEND', $content );
				}
				$response = \completion_response( 'length' === self::$mode ? 'length' : 'stop', $content );
				$response = \apply_filters( 'http_response', $response, $args, $url );
				return \is_wp_error( $response ) ? $response : json_decode( $response['body'], true )['choices'][0]['message']['content'];
			}
		}
	}
}

namespace {
	require __DIR__ . '/swq-glm52-translation-tuning.php';
	require __DIR__ . '/sources/slytranslate-installed/inc/TranslationValidator.php';
	if ( ! GLM53_WORKFLOW_FIXTURE ) {
		require __DIR__ . '/sources/slytranslate-installed/inc/TranslationRuntime.php';
	}
	function check53( bool $condition, string $name ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); }
		echo "PASS: $name\n";
	}
	function private53( string $method, ...$args ) {
		return ( new \ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method ) )->invoke( null, ...$args );
	}
	function state53( bool $active ): void {
		( new \ReflectionProperty( 'SWQ_GLM52_Translation_Tuning', 'state' ) )->setValue( null, [
			'active' => $active, 'post_id' => 1, 'request_count' => 0, 'request_model' => '',
			'payload_chars' => 0, 'protected_count' => 0, 'max_tokens' => 0,
		] );
	}
	function request_args( string $model ): array {
		return [ 'method' => 'POST', 'timeout' => 30, 'body' => json_encode( [
			'model' => $model, 'thinking' => [ 'type' => 'disabled' ], 'reasoning_effort' => 'none',
			'chat_template_kwargs' => [ 'enable_thinking' => false ], 'stream' => true,
			'do_sample' => true, 'max_tokens' => 512, 'messages' => [ [ 'role' => 'user', 'content' => 'Translate.' ] ],
		] ) ];
	}
	function completion_response( $finish = 'stop', $content = 'Final translation.', int $tokens = 100 ): array {
		return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
			'choices' => [ [ 'finish_reason' => $finish, 'message' => [ 'content' => $content, 'reasoning_content' => 'Private thinking, never article text.' ] ] ],
			'usage' => [ 'completion_tokens' => $tokens ],
		] ) ];
	}
	function rest53( string $model ) {
		$request = new class( $model ) {
			public function __construct( private string $model ) {}
			public function get_route(): string { return '/ai-translate/v1/ai-translate/translate-content/run'; }
			public function get_method(): string { return 'POST'; }
			public function get_json_params(): array { return [ 'model_slug' => $this->model, 'post_id' => 1, 'target_language' => 'en' ]; }
		};
		return SWQ_GLM52_Translation_Tuning::intercept_translate_content( null, $request, $request->get_route(), [] );
	}

	if ( GLM53_WORKFLOW_FIXTURE ) {
		// 100KB article: the existing whole-article path must still make one request.
		$source = '<!-- wp:heading --><h2>Release notes</h2><!-- /wp:heading -->'
			. str_repeat( '<p>Technical documentation preserves reliable commands and links.</p>', 1600 )
			. '<pre><code>curl --retry 0 https://example.com</code></pre>';
		SlyTranslate\ClientTranslationWorkflowService::$source = $source;
		foreach ( [ 'length', 'reasoning-only', 'timeout', 'retry', 'duplicate-token', 'stop' ] as $mode ) {
			SlyTranslate\TranslationRuntime::$mode = $mode;
			SlyTranslate\TranslationRuntime::$sent = 0;
			SlyTranslate\ClientTranslationWorkflowService::$applied = [];
			$result = rest53( 'glm-5.3' );
			$expected_errors = [
				'length' => 'swq_glm53_output_truncated',
				'reasoning-only' => 'swq_glm53_final_content_missing',
				'timeout' => 'http_request_failed',
				'retry' => 'swq_full_article_second_request_blocked',
				'duplicate-token' => 'swq_full_article_token_validation_failed',
				'stop' => 'offline_apply_checkpoint',
			];
			check53( is_wp_error( $result ) && $expected_errors[ $mode ] === $result->get_error_code(), "$mode exits with its expected error or offline checkpoint" );
			check53( 1 === SlyTranslate\TranslationRuntime::$sent, "$mode sends exactly one offline model request" );
			$applied = SlyTranslate\ClientTranslationWorkflowService::$applied;
			if ( 'stop' === $mode ) {
				check53( 1 === count( $applied ) && $source === $applied[0]['translations'][0]['translated'], 'complete long article reaches apply byte-for-byte after existing validation' );
				check53( 'draft' === $applied[0]['post_status'], 'existing draft-save policy remains intact' );
			} else {
				check53( [] === $applied, "$mode never reaches apply or persistence" );
			}
			check53( false === ( new \ReflectionProperty( 'SWQ_GLM52_Translation_Tuning', 'state' ) )->getValue()['active'], "$mode clears active state" );
		}
		echo "ALL WORKFLOW TESTS PASSED\n";
		exit;
	}

	$url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
	foreach ( [ 'glm-5.2', 'GLM-5.2', 'glm-5.3-flash', 'GLM-5.3-FlashX', 'glm-5.30', 'prefix-glm-5.3', 'glm-5.3-preview', '' ] as $model ) {
		$args = request_args( $model );
		check53( $args === apply_filters( 'http_request_args', $args, $url ), "$model is not tuned" );
		check53( null === rest53( $model ), "$model bypasses the custom REST workflow" );
		$response = completion_response( 'length' );
		check53( $response === apply_filters( 'http_response', $response, $args, $url ), "$model response is untouched" );
		state53( true );
		$error = apply_filters( 'pre_http_request', false, $args, $url );
		check53( is_wp_error( $error ) && 'swq_full_article_request_model_mismatch' === $error->get_error_code(), "$model cannot replace GLM-5.3 during an article request" );
		state53( false );
	}
	foreach ( [ 'glm-5.3', ' GLM-5.3 ' ] as $model ) {
		$result = rest53( $model );
		check53( is_wp_error( $result ) && 'offline_prepare_checkpoint' === $result->get_error_code(), "$model enters the custom REST workflow" );
		$args = apply_filters( 'http_request_args', request_args( $model ), $url );
		$body = json_decode( $args['body'], true );
		check53( [ 'type' => 'enabled' ] === $body['thinking'] && 'high' === $body['reasoning_effort'], "$model overrides disabled thinking and none effort" );
		check53( false === $body['do_sample'] && false === $body['stream'] && ! isset( $body['chat_template_kwargs'] ), "$model uses compatible nonstreaming parameters without legacy overrides" );
		check53( 65536 === $body['max_tokens'] && 600 === $args['timeout'] && ! isset( $body['timeout'] ), "$model leaves budget for thinking and sets client timeout" );
	}
	foreach ( [ 70000 => 70000, 999999 => 131072, 0 => 65536 ] as $requested => $expected ) {
		$args = request_args( 'glm-5.3' );
		$body = json_decode( $args['body'], true );
		$body['max_tokens'] = $requested;
		$args['body'] = $body; // The WordPress filter also accepts array bodies.
		$args['timeout'] = 900;
		$tuned = apply_filters( 'http_request_args', $args, $url );
		check53( $expected === json_decode( $tuned['body'], true )['max_tokens'] && 900 === $tuned['timeout'], "budget $requested respects API bounds and longer timeouts" );
	}
	$args = request_args( 'glm-5.3' );
	check53( $args === apply_filters( 'http_request_args', $args, 'https://example.com/chat/completions' ), 'unrelated host is untouched' );
	$args['method'] = 'GET';
	check53( $args === apply_filters( 'http_request_args', $args, $url ), 'GET is untouched' );
	$args = apply_filters( 'http_request_args', request_args( 'glm-5.3' ), $url );
	foreach ( [ 'length', 'content_filter', 'tool_calls', 'unknown', null ] as $finish ) {
		check53( is_wp_error( apply_filters( 'http_response', completion_response( $finish ), $args, $url ) ), 'reject finish_reason=' . var_export( $finish, true ) );
	}
	check53( is_wp_error( apply_filters( 'http_response', completion_response( 'stop', 'Final.', 65536 ), $args, $url ) ), 'reject exhausted budget even with contradictory stop marker' );
	foreach ( [ '', null, '   ' ] as $content ) {
		check53( is_wp_error( apply_filters( 'http_response', completion_response( 'stop', $content ), $args, $url ) ), 'reject reasoning-only response without final content' );
	}
	$response = completion_response();
	check53( $response === apply_filters( 'http_response', $response, $args, $url ), 'complete final answer passes through without reasoning promotion' );
	$invalid = [ 'response' => [ 'code' => 200 ], 'body' => '{broken' ];
	check53( is_wp_error( apply_filters( 'http_response', $invalid, $args, $url ) ), 'malformed success response fails closed' );
	$api_error = [ 'response' => [ 'code' => 429 ], 'body' => '{"error":{"code":"rate_limit"}}' ];
	check53( $api_error === apply_filters( 'http_response', $api_error, $args, $url ), 'upstream HTTP error stays available to existing error handling' );
	$transport_error = new WP_Error( 'http_request_failed', 'Timeout' );
	check53( $transport_error === apply_filters( 'http_response', $transport_error, $args, $url ), 'transport error is preserved' );

	// Invoke the installed runtime's actual priority-1 normalization wrapper.
	$wrapper = new \ReflectionMethod( 'SlyTranslate\\TranslationRuntime', 'with_reasoning_response_normalization_filter' );
	$only_thinking = completion_response( 'stop', '' );
	$result = $wrapper->invoke( null, fn() => apply_filters( 'http_response', $only_thinking, $args, $url ) );
	check53( is_wp_error( $result ) && 'swq_glm53_final_content_missing' === $result->get_error_code(), 'guard runs before the real SlyTranslate reasoning promotion filter' );
	$result = $wrapper->invoke( null, fn() => apply_filters( 'http_response', $response, $args, $url ) );
	check53( $response === $result, 'real runtime retains final content and separate reasoning_content' );

	state53( true );
	$full = apply_filters( 'http_request_args', request_args( 'glm-5.3' ), $url );
	$full_body = json_decode( $full['body'], true );
	check53( 131072 === $full_body['max_tokens'], 'full article uses the documented 128K shared output maximum' );
	check53( [ 'type' => 'enabled' ] === $full_body['thinking'] && 'high' === $full_body['reasoning_effort'] && ! isset( $full_body['chat_template_kwargs'] ), 'full article also forces thinking/high without legacy overrides' );
	check53( false === apply_filters( 'pre_http_request', false, $full, $url ), 'first model request is allowed' );
	$error = apply_filters( 'pre_http_request', false, $full, $url );
	check53( is_wp_error( $error ) && 'swq_full_article_second_request_blocked' === $error->get_error_code(), 'second request or fallback is blocked' );
	state53( false );

	foreach ( [ "中文说明\n/path/to/file", "Release notes\nhttps://example.com/docs", "/var/log/nginx/access.log\nstatus=500" ] as $plain ) {
		$attrs = json_encode( [ 'language' => 'plaintext', 'code' => $plain ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$source = '<!-- wp:kevinbatdorf/code-block-pro ' . $attrs . ' --><pre><code>' . htmlspecialchars( $plain, ENT_QUOTES, 'UTF-8' ) . '</code></pre><!-- /wp:kevinbatdorf/code-block-pro -->';
		$protected = private53( 'protect_content', $source );
		check53( is_array( $protected ) && true === private53( 'validate_plaintext_regions', $protected['payload'], $protected['plain_regions'] ), 'maintained Plaintext implementation validates protected contents' );
		$restored = private53( 'restore_translatable_regions', $protected['payload'], $protected['plain_regions'], $protected['alt_regions'] );
		$restored = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $restored );
		if ( preg_match( '/\p{Han}/u', $plain ) ) {
			check53( $plain === private53( 'extract_plaintext_code_block_pro_text', $restored ), 'editable Han Plaintext retains its text and line structure' );
		} else {
			check53( $source === $restored, 'machine/English Plaintext restores byte-for-byte' );
		}
	}
	echo "ALL MIGRATION TESTS PASSED\n";
}
