<?php
declare(strict_types=1);

define('ABSPATH', '/tmp/');

$GLOBALS['swq_filters'] = [];
$GLOBALS['swq_namespace_candidates'] = [];

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['swq_filters'][$hook][] = [$callback, $priority, $accepted_args];
    return true;
}
function apply_filters($hook, $value, ...$args) {
    if ('swq_glm52_token_namespace_candidate' === $hook && $GLOBALS['swq_namespace_candidates']) {
        return array_shift($GLOBALS['swq_namespace_candidates']);
    }
    return $value;
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value) { return strip_tags($value); }

class WP_Error {
    private string $code;
    private string $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = (string) $code; $this->message = (string) $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

require __DIR__ . '/swq-glm52-translation-tuning-token-namespace.php';

$class = new ReflectionClass('SWQ_GLM52_Translation_Tuning');
function call_private(ReflectionClass $class, string $name, ...$args) {
    $method = $class->getMethod($name);
    $method->setAccessible(true);
    return $method->invoke(null, ...$args);
}
function ok(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS: {$label}\n";
}

ok(count($GLOBALS['swq_filters']['http_request_args'] ?? []) === 1, 'http_request_args hook registered');
ok(count($GLOBALS['swq_filters']['pre_http_request'] ?? []) === 1, 'pre_http_request hook registered');
ok(count($GLOBALS['swq_filters']['rest_dispatch_request'] ?? []) === 1, 'rest_dispatch_request hook registered');

$legacy = "SWQSTRUCT000001END SWQBLOCK000001END SWQINLINE000001END";
$source = $legacy
    . "<!-- wp:paragraph --><p data-demo=\"SWQBLOCK000001END\">开头 <code>SWQINLINE000001END</code> 结尾</p><!-- /wp:paragraph -->"
    . "<!-- wp:code --><pre>SWQSTRUCT000001END</pre><!-- /wp:code -->"
    . "<pre>SWQBLOCK000001END</pre>"
    . "<!-- wp:paragraph --><p>连续：SWQBLOCK000001ENDSWQSTRUCT000001END</p><!-- /wp:paragraph -->"
    . "SWQABCDEF12BLOCK000001ENX" . $legacy;

$GLOBALS['swq_namespace_candidates'] = ['ABCDEF12', '1234ABCD'];
$fields = ['title' => 'SWQABCDEF12BLOCK000001END', 'excerpt' => $legacy, 'content' => $source];
$namespace = call_private($class, 'generate_token_namespace', $fields);
ok($namespace === '1234ABCD', 'first colliding namespace retried');

$protected = call_private($class, 'protect_content', $source, $namespace, 'content');
ok(!is_wp_error($protected), 'legacy token examples do not collide');
ok(strpos($protected['payload'], 'SWQ1234ABCD') !== false, 'request namespace used by protection');
ok(strpos($protected['payload'], 'SWQBLOCK000001END') !== false, 'legacy source token remains ordinary source text where exposed');

preg_match_all('/SWQ1234ABCD(?:BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)\d{6}END/', $protected['payload'], $matches);
$tokens = $matches[0];
ok(count($tokens) > 0 && count($tokens) === count(array_unique($tokens)), 'generated tokens are present and unique');
ok(call_private($class, 'protected_token_sequence_is_strictly_valid', $protected['payload'], $tokens, $namespace), 'strict count uniqueness and fixed order pass');

$restored_regions = call_private($class, 'restore_translatable_regions', $protected['payload'], $protected['plain_regions'], $protected['alt_regions']);
$restored = str_replace(array_keys($protected['map']), array_values($protected['map']), $restored_regions);
ok($restored === $source, 'Gutenberg HTML code pre attributes and legacy examples restore byte-for-byte');

$prompt = call_private($class, 'build_content_prompt', 'en', $namespace);
ok(strpos($prompt, 'SWQ1234ABCDSTRUCT000018END') !== false && strpos($prompt, 'SWQSTRUCT000018END') === false, 'prompt uses only request namespace');

$contracted = str_replace('000001END', '1END', $tokens[0] . implode('', array_slice($tokens, 1)));
$normalized = call_private($class, 'normalize_unique_protected_tokens', $contracted, $tokens, $namespace);
ok($normalized === implode('', $tokens), 'unambiguous leading-zero normalization remains safe');

if (count($tokens) >= 3) {
    $missing = $tokens;
    unset($missing[1]);
    $recovered = call_private($class, 'recover_uniquely_located_missing_token', implode('', $missing), implode('', $tokens), $tokens, $namespace);
    ok($recovered === implode('', $tokens), 'single uniquely located missing token recovery retained');
    $fixed_indexes = [];
    foreach ($tokens as $index => $token) { if (strpos($token, 'INLINE') === false) { $fixed_indexes[] = $index; } }
    if (count($fixed_indexes) >= 2) {
        $reordered = $tokens;
        [$reordered[$fixed_indexes[0]], $reordered[$fixed_indexes[1]]] = [$reordered[$fixed_indexes[1]], $reordered[$fixed_indexes[0]]];
        ok(!call_private($class, 'protected_token_sequence_is_strictly_valid', implode('', $reordered), $tokens, $namespace), 'fixed token reordering fails safely');
    }
}

ok(!call_private($class, 'protected_token_sequence_is_strictly_valid', implode('', $tokens) . $tokens[0], $tokens, $namespace), 'duplicate and extra token fail safely');
ok(!call_private($class, 'protected_token_sequence_is_strictly_valid', str_replace($tokens[0], 'SWQ1234ABCDHTML999999END', implode('', $tokens)), $tokens, $namespace), 'renamed token fails safely');

$valid_payload = implode("\n", $tokens);
ok(call_private($class, 'validate_protected_tokens', $valid_payload, $tokens, $valid_payload, $protected['map'], $valid_payload, $namespace) === true, 'full validator accepts exact tokens');
$extra_error = call_private($class, 'validate_protected_tokens', $valid_payload . 'SWQ1234ABCDBLOCK999999END', $tokens, $valid_payload, $protected['map'], 'raw-extra-response', $namespace);
ok(is_wp_error($extra_error), 'full validator rejects extra token');
$duplicate_error = call_private($class, 'validate_protected_tokens', $valid_payload . $tokens[0], $tokens, $valid_payload, $protected['map'], 'raw-duplicate-response', $namespace);
ok(is_wp_error($duplicate_error), 'full validator rejects duplicate token');
$renamed_error = call_private($class, 'validate_protected_tokens', str_replace($tokens[0], 'SWQ1234ABCDBLOCK999998END', $valid_payload), $tokens, $valid_payload, $protected['map'], 'raw-renamed-response', $namespace);
ok(is_wp_error($renamed_error), 'full validator rejects renamed token');
$diagnostic = json_decode((string) file_get_contents('/tmp/swq-glm52-token-validation-summary.json'), true);
foreach (['expected_count','actual_count','missing_tokens','extra_tokens','duplicate_tokens','fixed_order','first_mismatch_index','raw_response_file','diagnostic_file','token_namespace','collision_pattern'] as $key) {
    ok(array_key_exists($key, $diagnostic), "validation diagnostic includes {$key}");
}
ok((fileperms('/tmp/swq-glm52-token-validation-summary.json') & 0777) === 0600, 'validation diagnostic permission is 0600');
ok((fileperms('/tmp/swq-glm52-token-validation-output.txt') & 0777) === 0600, 'raw model response diagnostic permission is 0600');

$GLOBALS['swq_namespace_candidates'] = array_fill(0, 16, 'ABCDEF12');
$exhausted = call_private($class, 'generate_token_namespace', ['title' => 'SWQABCDEF12BLOCK000001END']);
ok(is_wp_error($exhausted) && $exhausted->get_error_code() === 'swq_full_article_token_namespace_exhausted', 'bounded repeated collisions fail safely');

$collision = call_private($class, 'protect_content', 'before SWQABCDEF12BLOCK000001END after', 'ABCDEF12', 'title');
ok(is_wp_error($collision) && $collision->get_error_code() === 'swq_full_article_token_collision', 'final collision defense remains active');
$data = $collision->get_error_data();
foreach (['collision_token','collision_token_type','collision_field','collision_offset','source_context','token_namespace','collision_pattern','diagnostic_file'] as $key) {
    ok(array_key_exists($key, $data), "collision diagnostic includes {$key}");
}
ok((fileperms($data['diagnostic_file']) & 0777) === 0600, 'collision diagnostic permission is 0600');

$namespaces = [];
for ($i = 0; $i < 8; $i++) { $namespaces[] = call_private($class, 'generate_token_namespace', ['content' => $source]); }
ok(count(array_unique($namespaces)) > 1, 'successive requests use differing namespaces');

echo "ALL TESTS PASSED\n";
