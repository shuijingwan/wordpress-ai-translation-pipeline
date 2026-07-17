# 根因审计报告

## 结论摘要

根因不是“数据库 taxonomy 写错”，也不是“Page Cache 单独损坏”。当前最能解释全部源码与生产证据的链条是：

1. [源码直接证明] `PolylangAdapter::create_translation()` 创建 draft 占位文章后，在 meta、category、post_tag、series 和翻译关系之前，使用请求给定的最终状态执行 `wp_update_post()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
2. [源码直接证明] GLM MU Plugin 不把状态 staging 为 draft；它将 prepare 返回的状态交给 apply，因而会进入上述共同 adapter 时序。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-290,426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
3. [合理推断，高置信度] 当目标状态为 publish 时，publish/save 生命周期中的读取者可在 taxonomy 尚未同步时看到默认 category 与空 tag/series/translation relationship，并把它写入持久 Object Cache。发布调用点：路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。WordPress/W3TC 内部源码不在工作区。
4. [源码直接证明] adapter 后续确实把正确关系写入数据库，但成功路径没有最终 `clean_object_term_cache()`；MU 也只在 raw content 写入后 `clean_post_cache()`。adapter 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-228`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。MU 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
5. [合理推断，生产证据强支持] W3TC relationship cache 因未被正确失效而继续返回窗口内的早期值；Page Cache 删除后用旧关系重新生成错误 HTML；`clean_object_term_cache(19512, 'post')` 删除旧关系后立即从正确数据库恢复。

## 实际调用链判定

- [源码直接证明] 非 GLM 原生 `/translate-content/run` 并不使用 client prepare/apply，而是 `AI_Translate::execute_translate_content()` → `PostTranslationService::translate_post()` → adapter。路径 `sources/slytranslate-installed/slytranslate.php:193-200,390-439,796-798`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`、`translate_post()`。
- [源码直接证明] client prepare/apply 是两个独立原生端点。路径 `sources/slytranslate-installed/slytranslate.php:244-251,772-780`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_prepare_client_translation()`、`execute_apply_client_translation()`。
- [合理推断] GLM MU Plugin 在 pre-dispatch 层抢占 `/translate-content/run`，从这里开始绕过原生 callback，并自行串联 client prepare → 翻译 → apply。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246,248-477`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`、`run_full_article_job()`。

## 状态与不完整窗口

- [源码直接证明] 新英文文章“初始”一定是 draft。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-147`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [源码直接证明] 其后可能在同一 adapter 调用中直接变成 publish；publish 早于 meta/taxonomy/translation link。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [源码直接证明] 状态优先来自请求；缺省继承源文章状态；最后才 fallback draft。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:517-536`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `normalize_post_status_for_client()`。
- [尚未确认] 没有文章 `19512` 当次 REST payload，故不能直接证明该次传入 publish。实际请求证据路径 `N/A`；类 `N/A`；方法 `N/A`。但区块编辑器会传当前源文章状态，列表页则固定 draft。编辑器路径 `sources/slytranslate-installed/assets/editor-plugin.js:924-947`；类 `N/A（JavaScript 模块）`；方法 `handleTranslate()`。列表路径 `sources/slytranslate-installed/assets/list-table-dialog.js:199-224,532-550`；类 `N/A（JavaScript 模块）`；方法 `doTranslate()`、`runBulkTranslation()`。

## GLM MU Plugin 的独立问题

1. [源码直接证明] 它绕过整个 `PostTranslationService`，不只是修改模型参数。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`。
2. [源码直接证明] client prepare 只产生配置为 translate 的简单 meta units；这与原生 server-side 流程复制/翻译/清空全部未跳过 meta 不等价。client 路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:524-562`；类 `SlyTranslate\MetaTranslationService`；方法 `build_meta_units()`。原生路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:91-190`；类 `SlyTranslate\MetaTranslationService`；方法 `prepare_translation_meta()`。
3. [源码直接证明] apply 后 raw `$wpdb->update()` 再写一次正文，绕过标准 post update API；随后只清 post cache。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:491-585`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
4. [源码直接证明] 被绕过的原生结束步骤只有 oEmbed meta 清理、progress/log 收尾；原生源码本身也没有 term relationship cache finalizer。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。

## 独立的 PublishPress Series 部分编号根因

这一问题必须与“前台 taxonomy relationship 被 Object Cache 读成旧值”分开处理。

1. [源码直接证明] PublishPress Series Free 3.1.2 把系列部分编号保存为 post meta：基础 key `_series_part` 经 `orgSeries::part_key()` 变成 `_series_part_<series term ID>`。路径 `sources/publishpress-series-installed/orgSeries.php:121-130`；类 `N/A（插件引导文件）`；方法 `N/A（常量定义）`。过滤器路径 `sources/publishpress-series-installed/orgSeries-setup.php:80-91`；类 `orgSeries`；方法 `__construct()`、`part_key()`。
2. [源码直接证明] 后台“当前部分”单独读取该 meta；`empty(trim($series_part))` 时显示“当前没有部分编号”。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。
3. [源码直接证明] 插件自动编号依赖 `future_to_publish`、`draft_to_publish`、`pending_to_publish`，回调在状态转换当时读取已有 series relationship，再调用 `set_series_order(..., 0, true)` 追加到末尾。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,398-417,811-815`；类 `N/A（过程式函数）`；函数 `set_series_order()`、`wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`。
4. [源码直接证明] SlyTranslate 新建译文时先创建 draft，再把目标状态写为 publish，之后才用 `wp_set_object_terms()` 加 series relationship。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-183,192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
5. [源码直接证明] PublishPress 没有为 `set_object_terms` 或 `added_term_relationship` 注册编号回调；其相关注册项仅是 `save_post` 和三个 `*_to_publish` Hook。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:811-815`；类 `N/A（Hook 注册区）`；回调见上一项。
6. [源码直接证明] `save_post` 路径要求 PublishPress 后台 metabox 的 `series_part`、`post_series`、`is_series_save` 请求字段；SlyTranslate 程序化保存不带这些字段，回调提前退出。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:419-450`；类 `N/A（过程式函数）`；函数 `series_wp_save_post()`、`wp_set_post_series()`。
7. [合理推断，高置信度] 因而当前英文文章的生命周期是：自动编号 Hook 在 relationship 不存在时空跑；稍后 relationship 建立，但没有关系 Hook 补编号。最终就出现“已属于 series、没有 `_series_part_<英文 series ID>`”。依据路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`；以及 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-450,811-815`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`、`wp_set_post_series()`。
8. [源码直接证明] 未编号值在 part 升序机制中位于编号 `1` 之前；后台管理查询也显式包含 meta 不存在的文章并按 part 子句升序，且没有日期或 ID 次级排序。路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-19,27-74`；类 `N/A`；函数 `_usort_series_by_part()`、`get_series_order()`。后台路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:72-110`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。

### AutoPoly 历史行为

- [源码直接证明] 只要英文 draft 在人工发布前已具有英文 series relationship，标准 draft → publish 就会触发 PublishPress 自动追加。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-417,811-815`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`。
- [合理推断，高置信度] 以前按中文顺序逐篇进入英文编辑器并手动发布，很可能依次触发自动编号 `1`、`2`……，因此能够保持英文顺序，而无需复制中文编号。依据路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,409-417,814-815`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series_draft_transition()`。
- [尚未确认] 缺少 AutoPoly 实际源码与旧事件记录，无法逐条证明历史英文 part 的写入回调。路径 `N/A（AutoPoly 源码/历史事件未提供）`；类 `N/A`；方法 `N/A`。

所以，“SlyTranslate 没有复制系列顺序”是已确认的源码行为；但当前异常更准确的直接触发是它还错过了 PublishPress 自动编号生命周期。“没有复制中文 part”不能被列为唯一原因。

## 根因分层

- **主触发缺陷（代码直接证明）**：SlyTranslate Polylang adapter 的 publish 顺序。
- **共同触发因素（代码直接证明）**：GLM 流程把 prepared final status 直接带入 apply，并在结束时不清 relationship cache。
- **持久化/放大机制（合理推断，生产证据强支持）**：W3TC Object Cache 保留早期 relationship 值；具体为何未被 `wp_set_object_terms()` 的正常失效路径删除，尚需 W3TC drop-in 源码、版本与配置。
- **结果缓存层**：Page Cache、taxonomy archive、series 页面和 CDN 可消费已公开但尚不完整或已缓存的关系；它们不是最初数据库写入错误。
- **独立的系列排序层**：PublishPress part post meta 缺失；不依赖 W3TC 即可造成后台无编号并在升序列表中位于编号 `1` 前。

## 已排除与未确认

- [生产证据直接排除] 数据库关系最终错误：数据库与 `wp_get_object_terms()` 已正确。
- [生产证据直接排除] Page Cache 是唯一根因：删除后仍重建错误 HTML。
- [生产证据强支持] Object-term relationship cache 是直接故障层：精确清理后立即恢复。
- [尚未确认] 文章 `19512` 的实际请求 status、读取早期 taxonomy 的具体 hook、W3TC 未失效的具体 key/group/竞态。
- [源码直接证明] Series 真实排序字段已经确认是 `_series_part_<series ID>` post meta；生产前台是否把设置改为 `post_date` 尚未确认。默认与读取路径 `sources/publishpress-series-installed/orgSeries-setup.php:297-355,570-620`；类 `orgSeries`；方法 `add_settings()`、`sort_series_page_orderby()`。

## 审计结论

应把修复目标定义为“在 taxonomy、翻译关系、必要 meta 和精确正文都完成前，不让目标文章进入最终公开状态；最终发布后再精确失效该文章的 post 与 object-term relationship cache”。仅在故障发生后清全站缓存不能消除生命周期窗口，也不符合题设目标。

对 Series 而言，这个同一生命周期调整还有额外价值：先在 draft 状态建立英文 series relationship，再作最终标准 publish，会让 PublishPress 自己的 `draft_to_publish` 回调自动生成 part。若业务要求中英文编号精确一一对应，则应另行采用“读取中文 part、映射英文 series ID、通过 PublishPress 核心函数写英文 part”的策略；两者的语义不同，不能预先认定复制编号一定更优。
