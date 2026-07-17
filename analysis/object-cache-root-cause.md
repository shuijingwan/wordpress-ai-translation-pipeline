# Object Cache 根因分析

## 已确认生产证据

以下作为题设事实，不由本次源码审计重新验证：文章 `19512` 的数据库与 `wp_get_object_terms()` 关系后来正确；真实英文 HTTP 请求从 W3TC Object Cache 读到 `category_relationships=[8841]`（Uncategorized）、空 `post_tag_relationships`、空 `series_relationships`；删除 Page Cache 后仍会生成错误 HTML；执行 `clean_object_term_cache(19512, 'post')` 后 category、tag、series 与翻译关系立即恢复。

## 源码直接证明的危险时序

1. [源码直接证明] 新目标文章先以 draft 占位状态创建，且创建参数不包含 category、post_tag 或 series。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-153`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
2. [源码直接证明] 设置语言之后，adapter 在一个 `wp_update_post()` 中写入目标内容和最终 `post_status`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:154-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
3. [源码直接证明] meta、所有普通 taxonomy 和 Polylang 翻译关系都在这次 `wp_update_post()` 之后。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:185-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
4. [源码直接证明] 当 prepared/requested status 为 `publish` 时，client apply 不降级为 draft，而是把 publish 交给 adapter。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:99-100,155-174,517-536`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`、`normalize_post_status_for_client()`。
5. [源码直接证明] GLM MU Plugin 将 prepare 返回的 status 原样传到 apply，没有 staging status。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-290,426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
6. [源码直接证明] adapter 正常成功路径没有 `clean_object_term_cache()`；GLM 在 apply 后也只清 post cache。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:222-228`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。GLM 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。

## taxonomy 尚未完整时文章是否已经发布

- [源码直接证明] **代码允许且在目标状态为 publish 时必然如此**：`wp_update_post(...post_status=publish...)` 位于 meta/taxonomy/翻译关系之前。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [尚未确认] 文章 `19512` 当次请求的 payload 未在当前工作区，因此不能仅凭源码证明当次 apply 收到 publish。路径 `N/A（实际请求证据缺失）`；类 `N/A`；方法 `N/A`。
- [合理推断，高置信度] 若请求来自已发布中文文章的编辑器侧栏，它会传当前 `postStatus`，通常为 publish；这与危险时序完全匹配。路径 `sources/slytranslate-installed/assets/editor-plugin.js:924-947`；类 `N/A（JavaScript 模块）`；方法 `handleTranslate()`。

## 早期缓存如何形成

1. [合理推断，高置信度] WordPress 的 `wp_update_post()` 在 draft → publish 的同步调用中会触发状态转换、`save_post`、`wp_after_insert_post` 等标准生命周期。任何 Polylang、series、页面缓存、SEO 或自定义 hook 都可能在 adapter 尚未执行后续 taxonomy loop 时读取目标文章关系。调用点由当前源码直接给出：路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。WordPress Core hook 实现不在工作区，因此 hook 内部时点标为合理推断。
2. [合理推断，高置信度] 新 `post` 占位文章未指定分类时，WordPress 可赋予默认分类；在后续 taxonomy 同步前读取会得到 Uncategorized 和空 tag/series。这个形状与生产缓存的 `[8841], [], []` 精确一致。占位创建参数路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:137-147`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。默认分类逻辑属于缺失的 WordPress Core 源码，故不提升为直接证明。
3. [源码直接证明] adapter 随后确实会把正确 taxonomy 写入数据库：读取源 IDs、映射目标 IDs、调用 `wp_set_object_terms()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
4. [合理推断，高置信度] 若早期读取结果进入持久 Object Cache，而后续 `wp_set_object_terms()` 的 invalidation 没有删除相同 W3TC relationship cache 键，数据库就会正确而 Redis 继续返回旧值。后续 term 写入调用点路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。当前工作区没有 W3TC drop-in 源码，无法确认是 W3TC 缺陷、配置、cache group 映射还是并发重填。

## 为什么删除 Page Cache 无效

[合理推断，生产证据强支持] Page Cache 保存最终 HTML；Object Cache 保存构建 HTML 时使用的对象/关系数据。删除 Page Cache 后，下一次请求仍从持久 Object Cache 得到错误 category/tag/series/翻译关系，于是重新生成同样错误的 HTML。SlyTranslate/MU 源码没有在返回前精确清理 relationship cache：`sources/slytranslate-installed/inc/PolylangAdapter.php:222-228`，类 `SlyTranslate\PolylangAdapter`，方法 `create_translation()`；`sources/mu-plugins/swq-glm52-translation-tuning.php:552-584`，类 `SWQ_GLM52_Translation_Tuning`，方法 `persist_exact_post_content()`。

## 为什么 clean_object_term_cache() 立即恢复

[合理推断，生产证据强支持] 该调用移除了文章 `19512` 的 object-term relationship 缓存；下一次读取回到已经正确的数据库关系，于是 category、post_tag、series 以及以 taxonomy 表达的 Polylang 关系一起恢复。当前工作区没有 WordPress Core 和 Polylang 缓存实现，不能列出实际 cache group/key 删除代码；但“单独清 object term cache 即恢复”的生产 A/B 结果直接把故障层定位到 relationship cache，而非数据库或 Page Cache。

## 根因归属

| 组件 | 作用 | 结论等级 |
|---|---|---|
| SlyTranslate `PolylangAdapter` | 把最终 publish 放在 meta、taxonomy、翻译关系之前，创造公开但不完整的生命周期窗口 | **源码直接证明；主要触发缺陷**。`sources/slytranslate-installed/inc/PolylangAdapter.php:161-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()` |
| GLM MU Plugin | 对 GLM 请求绕过原生 server-side controller，使用 client prepare/apply；不强制 draft，apply 后 raw 写正文且只清 post cache | **源码直接证明；共同触发/放大因素，不是唯一根因**。`sources/mu-plugins/swq-glm52-translation-tuning.php:152-246,426-477,491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`、`run_full_article_job()`、`persist_exact_post_content()` |
| W3TC Object Cache | 让早期 relationship 读值跨请求存活；后续 Page Cache 重建继续消费旧值 | **合理推断且生产证据强支持；具体失效机制尚未确认**，因为 W3TC drop-in 源码/版本/配置不在工作区 |
| Page Cache/CDN | 消费错误关系并扩大可见范围 | **结果层，不是最初根因**；删除 Page Cache 仍复现已排除其为唯一来源 |

最终归类：**SlyTranslate 的持久化顺序提供不完整状态，GLM 路径可把 publish 直接带入该顺序且没有 term-cache finalizer，W3TC 持久化了窗口内的关系读取；这是共同触发链。** 仅归咎于任一单一组件都不足以解释全部证据。
