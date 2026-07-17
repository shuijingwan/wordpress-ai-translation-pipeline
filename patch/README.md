# GLM 5.2 新翻译保持草稿候选补丁

## 范围

这是基于 Git `71f7e64333931b813d2230b22bedb8180cedf8f9`（标签 `analysis-baseline-2026-07-17`）生成的本地候选，不是部署文件，也未应用到 `sources/`。

- 原始生产 MU Plugin：`sources/mu-plugins/swq-glm52-translation-tuning.php`
- 完整候选：`patch/swq-glm52-translation-tuning-draft-only.php`
- 统一差异：`patch/draft-only.patch`
- 候选版本：`2026.07.17.13`（原版本 `2026.07.16.12`）

## 已确认根因

`ClientTranslationWorkflowService::prepare_single()` 返回 `existing_translation_id` 和归一化后的 `post_status`。原 MU Plugin 把 `prepared['post_status']` 原样传给 `apply_single()`；当中文源文章或请求状态为 `publish` 时，`PolylangAdapter::create_translation()` 会在 taxonomy 和 Polylang 翻译关系写入之前把新英文文章从 draft 改成 publish。

直接源码链路：

1. `prepare_single()` 通过 `TranslationQueryService::get_existing_translation_id()` 判断目标翻译是否存在；不存在返回 `0`，存在返回文章 ID。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:44-80`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`。查询实现位于 `sources/slytranslate-installed/inc/TranslationQueryService.php:196-221`；类 `SlyTranslate\TranslationQueryService`；方法 `get_existing_translation_id()`。
2. 已有翻译且 `overwrite=false` 时，prepare 在翻译前返回 `translation_exists`；只有新建或明确覆盖才继续。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:51-65`；类与方法同上。
3. 原 MU Plugin 从请求读取状态并交给 prepare，随后把 prepared 状态交给 apply。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-290,426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
4. `apply_single()` 再次规范化该状态并传给 adapter。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:92-174`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
5. adapter 先创建 draft，占位文章随后按输入状态执行 `wp_update_post()`，再复制 meta、category、post_tag、series，最后建立 Polylang 翻译关系。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
6. PublishPress Series 的 `draft_to_publish` 回调要求状态转换时 series relationship 已经存在；原顺序下自动编号发生得太早。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-417,811-815`；类 `N/A（过程式函数）`；函数 `wp_set_post_series_draft_transition()`、`set_series_order()`。

## 候选行为

状态决策集中在 `SWQ_GLM52_Translation_Tuning::run_full_article_job()` 构造 `$apply_input` 之前：

- 新翻译：`prepared['existing_translation_id']` 为 `0`，apply 状态固定为 `draft`，不继承中文源文章的 `publish`。
- 已有英文 draft 且执行覆盖：读取目标文章当前状态 `draft`，apply 后继续为 draft。
- 已有英文 publish 且执行覆盖：读取目标文章当前状态 `publish`，不会临时或最终降级为 draft。
- 已有翻译但未允许覆盖：保持 SlyTranslate 原行为，在 prepare 阶段返回错误，不进入 apply。

目标状态在调用 `apply_single()` 前决定。apply 成功后，MU Plugin 只调用 `persist_exact_post_content()`；该方法通过 `$wpdb->update()` 仅写 `post_content`，然后执行现有的 `clean_post_cache()` 和正文 hash 校验，不写 `post_status`。候选文件在 apply 后也没有新增 `wp_update_post()`，因此新文章不会再次自动 publish。路径 `patch/swq-glm52-translation-tuning-draft-only.php:446-486,501-594`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`、`persist_exact_post_content()`。

## 为什么 draft 同时避开两个问题

1. taxonomy/cache 生命周期：新英文文章在标题、摘要、正文、分类、标签、series 和 Polylang 翻译关系完成之前不会公开，因而不会在不完整窗口内生成正式公开页面、taxonomy 归档结果、W3TC Page Cache 或 CDN 页面。
2. PublishPress Series 编号生命周期：SlyTranslate 在文章仍为 draft 时完成英文 series relationship。用户以后在英文编辑器手动发布，正常 `draft_to_publish` Hook 能看到该 relationship，并由 PublishPress Series 自行追加部分编号。

候选不保证已存在且已 publish 的英文文章获得同样的草稿隔离窗口，因为安全要求明确禁止覆盖翻译时把它降级为 draft。

## 实际修改点

1. 插件头版本：候选第 `5` 行，`2026.07.16.12` → `2026.07.17.13`。
2. `SWQ_GLM52_Translation_Tuning::run_full_article_job()`：候选第 `426-441` 行。
   - 默认 `$apply_post_status = 'draft'`。
   - 使用 prepare 已返回的 `existing_translation_id` 区分新建与覆盖。
   - 覆盖时通过 `get_post_status()` 保留目标英文文章当前状态。
   - `$apply_input['post_status']` 改为使用该决策结果。

统一 diff 只有上述两个 hunk。

## 明确没有修改的部分

- 没有修改 `sources/` 中任何文件。
- 没有修改 SlyTranslate 或 PublishPress Series 源码。
- 没有修改 REST 路由、Hook 或优先级。
- 没有改变 GLM 5.2 一次性整篇翻译、模型请求参数、占位符保护、Plaintext 保护、正文还原或 hash 校验。
- 没有改变标题、摘要、正文、meta、category、post_tag、series 或 Polylang 翻译关系的同步实现与顺序。
- 没有写入 `_series_part_<term ID>`，也没有复制或模拟 PublishPress Series 自动编号。
- 没有增加调试日志、额外远程请求、数据库直写逻辑或全站缓存清理。
- 没有新增 apply 后的 `wp_update_post()` 或自动发布步骤。

## 为什么暂不复制 PublishPress Series 部分编号

当前目标是复现此前“按中文顺序逐篇翻译、进入英文编辑器、手动发布”的生命周期。PublishPress Series 3.1.2 已提供 `draft_to_publish` 自动追加机制；只要英文 series relationship 在发布前存在，就无需耦合其内部 `_series_part_<英文 series ID>` 存储。

直接复制编号还需要处理中文/英文 series term ID 映射、缺译造成的编号空洞、重复编号和乱序补译冲突。将这些策略加入本次补丁会扩大修改范围，且不符合当前“由用户逐篇手动发布”的目标。

## 为什么不使用全站缓存清理

全站清理只能在不完整状态已经进入缓存后事后处理，不能关闭“先 publish、后 taxonomy”的窗口，也不能创建 PublishPress Series 部分编号。它影响无关内容、成本高，而且会掩盖生命周期错误。本候选从源头让新文章保持非公开状态，不增加任何缓存 flush。

## 已知限制

- 只影响当前 MU Plugin 截获的 GLM 5.2 自定义 REST 工作流；SlyTranslate 的其他模型和原生入口不受影响。
- 已发布英文文章覆盖时按要求保持 publish，因此仍是在线更新，不具备新建草稿的隔离效果。
- 英文 series 顺序由用户实际手动发布顺序决定；如果发布顺序不同于中文系列顺序，自动编号顺序也会不同。
- 候选读取已有目标文章状态的时点在 apply 前；极端并发下，如果另一请求同时改变该状态，仍需在集成验证中观察。
- SlyTranslate 的状态规范化排除 `auto-draft`、`inherit`、`trash`。本候选验证重点是新建、existing draft 与 existing publish；其他非常规状态应作为扩展用例处理。
- 本阶段没有运行 WordPress/W3TC/Cloudflare 集成环境，只完成静态源码审查、统一差异与 PHP 语法检查。

