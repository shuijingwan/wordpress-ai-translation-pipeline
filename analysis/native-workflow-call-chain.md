# SlyTranslate 原生流程调用链

## 首先纠正流程边界

[源码直接证明] 安装源码没有一个“原生控制器”在同一请求内顺序执行 `prepare_single()`、翻译、`apply_single()`。路径 `sources/slytranslate-installed/slytranslate.php:193-200,244-251,390-439,772-780`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`、`execute_prepare_client_translation()`、`execute_apply_client_translation()`。存在两套原生 API：

1. `/ai-translate/v1/ai-translate/translate-content/run`：`AI_Translate::execute_translate_content()` → `AI_Translate::translate_post()` → `PostTranslationService::translate_post()`。路径 `sources/slytranslate-installed/slytranslate.php:193-200,390-439,796-798`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`、`translate_post()`。
2. `/prepare-client-translation/run` 与 `/apply-client-translation/run`：两个独立 REST 请求，中间翻译由调用方完成。路径 `sources/slytranslate-installed/slytranslate.php:244-251,265-277,772-780`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_prepare_client_translation()`、`execute_apply_client_translation()`。

## A. 真正的原生 translate-content 流程

1. [源码直接证明] `rest_api_init` 注册路由；统一 wrapper 解析 `input` 后调用配置的 callback。路径 `sources/slytranslate-installed/slytranslate.php:111-120,157-160,265-277,281-311`；类 `SlyTranslate\AI_Translate`；方法 `add_hooks()`、`register_editor_rest_routes()`、`get_rest_route_input()`。
2. [源码直接证明] `execute_translate_content()` 读取 `post_id`、语言、`post_status`、`overwrite`、`translate_title` 等参数，随后调用 `self::translate_post()`；成功后读取目标文章并组装 REST 返回。路径 `sources/slytranslate-installed/slytranslate.php:390-439`；类 `SlyTranslate\AI_Translate`；方法 `execute_translate_content()`。
3. [源码直接证明] `AI_Translate::translate_post()` 只是一层代理，进入 `PostTranslationService::translate_post()`。路径 `sources/slytranslate-installed/slytranslate.php:796-798`；类 `SlyTranslate\AI_Translate`；方法 `translate_post()`。
4. [源码直接证明] `PostTranslationService::translate_post()` 在持久化前完成 title、content、excerpt 和 meta 的翻译/准备，最后把目标状态、字段、meta、overwrite 一次性交给 adapter。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:109-145,202-402`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`。
5. [源码直接证明] adapter 成功后，原生 service 只清除 `_oembed_` meta、完成/清除进度并记录日志；没有调用 `clean_object_term_cache()` 或最终 `clean_post_cache()`。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。
6. [源码直接证明] 原生 REST 控制器随后只读取目标文章字段并返回，没有额外 finalization、taxonomy 写入或缓存失效。路径 `sources/slytranslate-installed/slytranslate.php:425-439`；类 `SlyTranslate\AI_Translate`；方法 `execute_translate_content()`。

## B. 原生 client 两阶段流程

### prepare_single()

1. [源码直接证明] `prepare_single()` 验证源文章、adapter、源/目标语言与权限，读取现有翻译、源 meta，并构造 title/content/excerpt/可翻译 meta units 和 source hash；它不调用 `wp_insert_post()`、`wp_update_post()` 或 adapter `create_translation()`，因此不会创建英文文章。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:44-84,376-430,443-509`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`、`validate_job_input()`、`build_units_for_post()`、`compute_source_hash()`。
2. [源码直接证明] prepare 返回已经归一化的 `post_status`，但只作为数据返回。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:51-83`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`。

### apply_single()

1. [源码直接证明] apply 重新验证 job，校验可选 source hash，把 `translations` 按 unit ID 建索引，并分离 title、content、excerpt 与 `meta:*` units。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:92-153`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
2. [源码直接证明] apply 组装 `overwrite`、归一化后的 `post_status`、翻译 meta 和存在的文章字段，然后调用一次 active adapter 的 `create_translation()`。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:155-178`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
3. [源码直接证明] adapter 返回后，apply 只读取目标文章、生成 edit link 和返回数组；没有额外保存、taxonomy finalization、`clean_post_cache()` 或 `clean_object_term_cache()`。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:180-195`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
4. [源码直接证明] REST callback 对 apply 的结果直接 `return`，没有控制器尾部步骤。路径 `sources/slytranslate-installed/slytranslate.php:777-780`；类 `SlyTranslate\AI_Translate`；方法 `execute_apply_client_translation()`。

## post_status 来源与默认值

1. [源码直接证明] client prepare/apply 都先读取调用方 `post_status`，有效且不是 `auto-draft`、`inherit`、`trash` 时原样使用；未提供或无效时使用源文章状态；源状态也不可用时才回退到 `draft`。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:51-52,71-79,99-100,156-159,517-536`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`、`apply_single()`、`normalize_post_status_for_client()`。
2. [源码直接证明] 原生 server-side 流程采用同一规则：显式状态优先，其次源文章状态，最后 `draft`。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:109,676-696`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`normalize_post_status()`。
3. [源码直接证明] 区块编辑器侧栏把当前文章状态传入；若当前中文文章已发布，则请求会传 `publish`。路径 `sources/slytranslate-installed/assets/editor-plugin.js:924-947`；类 `N/A（JavaScript 模块）`；方法 `handleTranslate()`。
4. [源码直接证明] 文章列表单篇与批量入口固定传 `draft`。路径 `sources/slytranslate-installed/assets/list-table-dialog.js:199-224,532-550`；类 `N/A（JavaScript 模块）`；方法 `doTranslate()`、`runBulkTranslation()`。
5. [尚未确认] 当前源码和题设没有保存产生文章 `19512` 的实际 REST payload，因此不能断言该次请求来自侧栏还是列表，也不能直接确认它传的是 `publish` 还是 `draft`。路径 `N/A（实际请求证据缺失）`；类 `N/A`；方法 `N/A`。

## PolylangAdapter 的准确持久化顺序

对新翻译，`SlyTranslate\PolylangAdapter::create_translation()` 的源码顺序是：

1. [源码直接证明] 查询现有目标；若不存在，调用 `wp_insert_post()` 创建 **draft** 占位文章，内容为单个空格，并复制源文章 `post_type`、author、`post_date`、`post_date_gmt`、modified 时间。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:111-153`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
2. [源码直接证明] 占位文章创建后立即调用 `set_post_language()` / `pll_set_post_language()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:154-159,57-77`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`、`set_post_language()`。
3. [源码直接证明] 组装 title/content/excerpt，并把目标 `post_status` 放入同一个 `wp_update_post()`；因此新英文文章“最初是 draft”，但如果目标状态为 `publish`，就在这一步由 draft 变成 publish。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
4. [源码直接证明] **在上述 `wp_update_post()` 返回之后**才逐项 `update_post_meta()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:185-190`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
5. [源码直接证明] meta 之后才遍历该 post type 的 taxonomies；排除 `language`、`post_translations`，读取源 term IDs，按 Polylang 映射后逐 taxonomy 调用 `wp_set_object_terms(..., false)`。这一步覆盖 `category`、`post_tag` 和已注册的 `series`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
6. [源码直接证明] taxonomy 全部处理后，最后才调用 `link_translation()`，内部执行 `pll_save_post_translations()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:222-227,230-240`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`、`link_translation()`。
7. [源码直接证明] `create_translation()` 随即返回；正常成功路径没有最终状态更新、最终 post save、relationship cache 清理或 post cache 清理。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:222-228`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。

因此在目标状态为 `publish` 时，准确顺序为：**draft 创建 → 设置语言 → 写标题/摘要/正文并 publish → meta → category/post_tag/series → 翻译关系 → 返回**。这不是推断，而是当前 adapter 的直接调用顺序。
