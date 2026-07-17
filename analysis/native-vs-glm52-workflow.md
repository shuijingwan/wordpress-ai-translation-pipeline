# 原生流程与 GLM 5.2 流程对比

## 分叉点

[合理推断] 两条流程在 WordPress REST server 执行已注册 handler 之前的 `rest_dispatch_request` filter 开始分叉。GLM 请求由 `SWQ_GLM52_Translation_Tuning::intercept_translate_content()` 返回非 null 结果；非 GLM 请求继续到 `SlyTranslate\AI_Translate::execute_translate_content()`。MU 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。原生路由路径 `sources/slytranslate-installed/slytranslate.php:193-200,265-277,390-439`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`。WordPress REST server 源码不在工作区，因此“filter 非 null 即 short-circuit”按公共 hook 契约标为合理推断。

## 按时间顺序

| 阶段 | 原生 `/translate-content/run` | GLM 5.2 截获流程 |
|---|---|---|
| 入口 | 路径 `sources/slytranslate-installed/slytranslate.php:390-439`；类 `SlyTranslate\AI_Translate`；方法 `execute_translate_content()` | 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()` |
| 翻译编排 | 路径 `sources/slytranslate-installed/inc/PostTranslationService.php:146-402`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()` | 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-447`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()` |
| 创建英文文章 | `wp_insert_post()` 创建 draft；路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-153`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | apply 进入同一个类和方法；同左 |
| 设置语言 | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:154-159`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | 同一个类和方法；同左 |
| title/excerpt/content 写入 | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | 首次写入同左；之后 raw 覆盖路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-550`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()` |
| meta | 路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:91-190`；类 `SlyTranslate\MetaTranslationService`；方法 `prepare_translation_meta()`；adapter 写入路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:185-190`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | client units 路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:524-562`；类 `SlyTranslate\MetaTranslationService`；方法 `build_meta_units()`；MU 组装路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:331-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()` |
| category | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | 同一个类和方法；同左 |
| post_tag | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | 同一个类和方法；同左 |
| series | 若已注册，由路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220` 的 generic loop 处理；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | 同一个类和方法；同左 |
| 翻译关系 | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:222-240`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`、`link_translation()` | 同一个类和方法；同左 |
| draft → publish | 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` | status 传递路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`；最终进入左侧 adapter |
| 二次保存 | 无；路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()` | raw DB 写入路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-550`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()` |
| 缓存清理 | 无 post/term relationship 最终清理；路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()` | 只 `clean_post_cache()`；路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:552-584`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()` |
| REST 返回 | 路径 `sources/slytranslate-installed/slytranslate.php:425-439`；类 `SlyTranslate\AI_Translate`；方法 `execute_translate_content()` | 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:468-477`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()` |

## 两条流程的共同危险窗口

[源码直接证明] 不论原生 server-side 还是 GLM/client 路径，最终都进入同一个 `PolylangAdapter::create_translation()`；当目标状态是 publish 时，共同顺序都是：draft 占位 → 语言 → `wp_update_post(...publish...)` → meta → taxonomies → translation link。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。

[合理推断] 因此“公开文章已存在，但 category/tag/series/翻译关系尚未最终完成”的窗口并非 GLM 独有；GLM 的作用是绕过原生翻译编排、选择 client service，并在 apply 后加一次 raw content 写入。是否进入 publish 窗口取决于请求状态：编辑器可传 source 的 publish，列表页固定传 draft。编辑器路径 `sources/slytranslate-installed/assets/editor-plugin.js:924-947`；类 `N/A（JavaScript 模块）`；方法 `handleTranslate()`。列表路径 `sources/slytranslate-installed/assets/list-table-dialog.js:199-224,532-550`；类 `N/A（JavaScript 模块）`；方法 `doTranslate()`、`runBulkTranslation()`。

## finalization 差异

- [源码直接证明] 原生 server-side 流程在 adapter 后仅清 `_oembed_` meta 和进度；这不是 taxonomy/cache finalization。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。
- [源码直接证明] GLM 流程漏掉上述 oEmbed/progress 收尾，但增加 raw content 写入和 `clean_post_cache()`；仍没有 relationship cache 清理。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:446-477,491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`、`persist_exact_post_content()`。
- [源码直接证明] client apply REST 原生控制器本来也直接返回，所以“恢复原生 client finalization”并不能找到一个现成的 term-cache 清理步骤。路径 `sources/slytranslate-installed/slytranslate.php:777-780` 与 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:174-195`；类 `SlyTranslate\AI_Translate`、`SlyTranslate\ClientTranslationWorkflowService`；方法 `execute_apply_client_translation()`、`apply_single()`。
