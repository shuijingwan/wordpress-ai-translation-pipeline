# GLM 5.2 MU Plugin 流程调用链

## Hook、路由与分叉

1. [源码直接证明] MU Plugin 在 `boot()` 注册三个 filter：`http_request_args`，优先级 `PHP_INT_MAX - 10`；`pre_http_request`，优先级 `PHP_INT_MAX`；`rest_dispatch_request`，优先级 `PHP_INT_MAX`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:32-36,2268`；类 `SWQ_GLM52_Translation_Tuning`；方法 `boot()`。
2. [源码直接证明] REST 截获目标精确为 `/ai-translate/v1/ai-translate/translate-content/run`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:12,152-157`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。
3. [源码直接证明] 仅在 route 匹配、已有 dispatch result 为 `null`、请求为 POST、且 `model_slug` 包含 `glm-5.2` 时进入自定义流程；否则返回原 `$dispatch_result`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-219`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。
4. [合理推断] 按 WordPress `rest_dispatch_request` 的 pre-dispatch filter 契约，方法在匹配时返回自定义 `$result` 会跳过已注册的原生 handler，因此 `AI_Translate::execute_translate_content()` 不执行。当前 MU 源码可直接证明它在 221-235 行生成并返回非 null 结果；WordPress REST server 实现未包含在工作区。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:221-235`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。

## run_full_article_job() 时间线

1. [源码直接证明] 方法从原请求读取 `post_id`、目标/源语言、`post_status`、overwrite、translate_title、additional_prompt；`post_status` 仅做 `sanitize_key()`，未强制改成 draft。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-264`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
2. [源码直接证明] prepare 参数为 `source_post_id`、`target_language`、`overwrite`、原请求 `post_status`，可选 `source_language`；然后直接调用 `ClientTranslationWorkflowService::prepare_single()`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:280-294`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
3. [源码直接证明] `prepare_single()` 不创建文章；它返回 units 与归一化状态。对应实现路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:44-84`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`。
4. [源码直接证明] MU Plugin 把 units 分成 content 与非 content，先翻译短字段，再翻译全文；每个结果自行组装为 `['id' => ..., 'translated' => ...]` 的 `$translations` 数组。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:331-424`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
5. [源码直接证明] apply 参数来自 prepare：`source_post_id`、`target_language`、resolved source language、prepared overwrite、**prepared post_status**、source hash、自行组装的 translations；随后直接调用 `ClientTranslationWorkflowService::apply_single()`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:426-447`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
6. [源码直接证明] `prepared['post_status']` 是 client service 归一化后的值：显式请求状态优先，否则源文章状态，否则 draft。因此 MU 没有在 apply 前把状态固定成 draft。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:51-79,517-536`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`、`normalize_post_status_for_client()`。
7. [合理推断] 如果生产请求来自区块编辑器且中文源文章已发布，JS 会传 `publish`，MU 将该值经 prepare 传到 apply，adapter 会在 taxonomy 前直接 publish。JS 证据路径 `sources/slytranslate-installed/assets/editor-plugin.js:924-947`；类 `N/A（JavaScript 模块）`；方法 `handleTranslate()`。MU 传递证据路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-290,426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
8. [源码直接证明] 如果请求来自列表页，则 JS 固定传 `draft`，这条具体入口不会在 apply 中发布。路径 `sources/slytranslate-installed/assets/list-table-dialog.js:199-224,532-550`；类 `N/A（JavaScript 模块）`；方法 `doTranslate()`、`runBulkTranslation()`。

## apply 之后的二次保存

1. [源码直接证明] apply 成功后，MU 取得 `translated_post_id`，然后无条件调用 `persist_exact_post_content()`；最后返回原 `$apply_result`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:446-477`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
2. [源码直接证明] `persist_exact_post_content()` 先比较内容 SHA256；不一致时直接调用 `$wpdb->update($wpdb->posts, ['post_content' => ...], ['ID' => ...])`。它不是 `wp_update_post()`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-550`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
3. [合理推断] 因为使用原始 `$wpdb->update()`，这次内容写入不会通过 `wp_update_post()` 的标准 `save_post`、`post_updated`、`wp_after_insert_post` 生命周期；WordPress Core 源码不在工作区，但当前文件可直接证明没有调用这些 API。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:523-550`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
4. [源码直接证明] 二次写入后只调用 `clean_post_cache($translated_post_id)`，随后重新读取正文并校验 hash。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:552-584`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
5. [源码直接证明] 整个 MU 文件仅有上述一次 `clean_post_cache()`；没有 `clean_object_term_cache()`、`clean_term_cache()` 或 relationship cache 清理。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
6. [源码直接证明] apply 后没有再次调用 `wp_update_post()`，所以没有“标准 WordPress 二次保存”；存在的是一次绕过标准 post API 的原始数据库内容覆盖。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:446-477,491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`、`persist_exact_post_content()`。

## 被绕过或缺失的原生结束步骤

1. [源码直接证明] MU 绕过的是原生 `execute_translate_content()` → `PostTranslationService::translate_post()` 整条 server-side 流程，不只是模型调用。原生映射路径 `sources/slytranslate-installed/slytranslate.php:193-200,390-439,796-798`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`、`translate_post()`。MU 分叉路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。
2. [源码直接证明] 因为改用 client service，MU 不会执行 `PostTranslationService` 成功后的 `_oembed_` meta 清理、saving progress 完成、job-end 日志和 finally 中的 progress 清理。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。
3. [源码直接证明] 但原生 client `apply_single()` 本身就没有额外 finalization；因此不存在一个可被 MU “漏掉”的 client controller term-cache finalizer。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:174-195` 与 `sources/slytranslate-installed/slytranslate.php:777-780`；类 `SlyTranslate\ClientTranslationWorkflowService`、`SlyTranslate\AI_Translate`；方法 `apply_single()`、`execute_apply_client_translation()`。

## REST 返回

[源码直接证明] MU exact persist 成功后原样返回 `apply_single()` 的结果；interceptor 再把它作为 `rest_dispatch_request` 结果返回，没有后续 post/taxonomy finalization。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:468-477,221-235`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`、`intercept_translate_content()`。

