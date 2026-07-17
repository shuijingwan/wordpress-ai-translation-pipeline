# 最小修复方案比较

> 本文件只做方案审计，不生成补丁，不修改第三方源码。

## 比较表

| 方案 | 最小实现思路 | 能否阻止不完整公开状态 | 能否处理已形成的 stale relationship cache | 主要代价/风险 | 结论 |
|---|---|---:|---:|---|---|
| A. 翻译期间始终 draft，完成后统一 publish | 保存 desired final status；apply 阶段传 draft；taxonomy、翻译关系、精确正文完成后再用标准 post API 切到 final status | **是，最直接** | 间接减少；建议配 D | overwrite 已发布译文时，临时降级会造成可见性抖动；需区分新建与更新 | **首选原则** |
| B. 恢复被 MU 绕过的原生 finalization | 补回 oEmbed meta、progress/log 等原生尾部行为 | **否** | **否** | 当前原生 finalization 本身没有 term cache 清理；直接重跑原生 service 会重复翻译/持久化 | 仅作行为兼容，不能单独修根因 |
| C. 调整 apply 参数或调用顺序 | MU 保存 `$desired_status`，以 draft 调 `apply_single()`；exact persist 后再标准 publish | **是** | 需配 D | 逻辑局部、但要正确处理 error/overwrite/原状态 | **最小可控落点，推荐与 A/D 组合** |
| D. 最后精确 `clean_object_term_cache()` + `clean_post_cache()` | 在所有 taxonomy/link/content 写入和最终状态变更之后，针对目标 ID 清理 | **否，单独使用仍有公开窗口** | **是，生产现象已验证 term 清理有效** | 清理时点必须在最后；还要保证 Page Cache/CDN 在完整状态下失效/重建 | **必要防线，但不应单独使用** |
| E. 修改 SlyTranslate 第三方源码 | 在 `PolylangAdapter::create_translation()` 内把 final status 移到 meta/taxonomy/link 后，并加精确清理 | **是，覆盖所有调用方** | 可同时处理 | vendor 更新覆盖、本地维护成本、需上游协调与广泛回归 | **架构最正确，部署成本最高** |

## 各方案的源码依据

### A. 全程 draft，最后 publish

- [源码直接证明] adapter 已经以 draft 创建占位文章，天然具备 staging 起点。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-153`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [源码直接证明] 问题是 final status 被放进 taxonomy 前的 `wp_update_post()`。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [合理推断] 将 publish 推迟到 meta、taxonomy、translation link 和 exact content 完成之后，可让 publish/save hooks 首次观察目标文章时已是完整状态。危险调用顺序路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-227`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。WordPress hook 实现不在当前工作区。
- 风险：已有 publish 翻译的 overwrite 不宜无条件先改 draft；可只对新建目标采用 staging，已有目标采用专门的事务式顺序或第三方 adapter 修复。

### B. 恢复原生 finalization

- [源码直接证明] 原生 server-side 成功尾部清理 `_oembed_` meta、更新进度和日志，finally 清进度。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。
- [源码直接证明] 这些步骤不包含 `clean_object_term_cache()`，也不把 publish 移到 taxonomy 后。因此 B 单独不能解决已观察问题。路径 `sources/slytranslate-installed/inc/PostTranslationService.php:402-425,453-472`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`、`clear_embed_cache_meta()`。
- [源码直接证明] client apply controller 没有额外 finalization 可恢复。路径 `sources/slytranslate-installed/slytranslate.php:777-780` 与 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:174-195`；类 `SlyTranslate\AI_Translate`、`SlyTranslate\ClientTranslationWorkflowService`；方法 `execute_apply_client_translation()`、`apply_single()`。

### C. 调整 apply 参数/顺序

- [源码直接证明] MU 当前把 `prepared['post_status']` 直接传给 apply。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
- [源码直接证明] apply 会再次归一化并把该值传给 adapter。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:155-174`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
- 最小策略（分析设计）：在 MU 内保存 desired status，apply 固定传 draft；apply 完成后执行 exact content persist；最后使用标准 `wp_update_post()` 将状态设为 desired status。这样不需要修改 `sources/slytranslate-installed/`。
- [合理推断] 最终状态变更必须使用标准 post API，不能用 `$wpdb->update()`，以便正常触发依赖 publish/save 生命周期的归档、Page Cache/CDN invalidation。当前 raw 写入证据：路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:523-552`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。

### D. 最终精确清理

- [源码直接证明] 当前 MU 只清 post cache。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:552-584`；类 `SWQ_GLM52_Translation_Tuning`；方法 `persist_exact_post_content()`。
- [生产证据] `clean_object_term_cache(19512, 'post')` 已实际恢复全部关系，证明 D 对现存故障有效。
- 推荐时点（分析设计）：**taxonomy + translation link + exact content + final status 全部成功之后**，先精确清 object-term relationship，再清 post cache；随后应由最终标准 post update 触发的页面/CDN invalidation 在完整状态下运行。
- 不建议只加 D：publish hooks 仍可能在清理之前把不完整 HTML 推入 Page Cache/CDN；目标是避免泄漏，不只是事后修复 Redis。

### E. 修改 SlyTranslate 第三方插件

- [源码直接证明] 共同顺序缺陷集中在 `PolylangAdapter::create_translation()`，所有原生 server-side 与 client apply 最终都调用它。调用点 `sources/slytranslate-installed/inc/PostTranslationService.php:389-402`；类 `SlyTranslate\PostTranslationService`；方法 `translate_post()`。client 调用点 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:155-174`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。实现路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:106-228`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- 架构上最正确的上游改法是 adapter 始终在 draft 状态写字段/meta/taxonomy/link，最后再切目标状态并做精确清理。
- 代价是第三方升级冲突、所有入口回归、已有 overwrite 行为兼容和上游维护；本阶段明确不修改该源码。

## 推荐优先级

1. **短期最小且符合目标：A + C + D**。在 MU 自定义流程中以 draft apply，exact content 完成后标准 publish，最后精确清目标文章的 object-term 与 post cache。
2. **B 作为兼容性补充**：补回 oEmbed/progress 等行为，但不要把它误当 taxonomy 修复。
3. **E 作为长期上游修复**：把正确顺序下沉到 `PolylangAdapter`，覆盖非 GLM、GLM、client API 和其他调用方。

## PublishPress Series 部分编号方案比较

本节的 A–E 专指系列部分编号，不替代前面的 Object Cache 生命周期方案。当前只比较方向，不生成代码或部署命令。

### 已确认的实现约束

- [源码直接证明] 真实编号保存在 `_series_part_<series term ID>` post meta；核心写入函数是 `set_series_order()`。路径 `sources/publishpress-series-installed/orgSeries.php:121-130`；类 `N/A（插件引导文件）`；方法 `N/A（常量定义）`。写入路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-162`；类 `N/A（过程式函数）`；函数 `set_series_order()`。
- [源码直接证明] draft/pending/future → publish Hook 会对转换时已有的每个 series 自动执行 `set_series_order(..., 0, true)`。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-417,811-815`；类 `N/A`；函数 `wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`。
- [源码直接证明] SlyTranslate 当前顺序相反：先 draft → 目标状态，再写 series relationship。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-183,192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [源码直接证明] `wp_set_object_terms()` 与部分编号写入是分开的操作；PublishPress 自己的后台保存也是先调用它，再调用 `set_series_order()`。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:553-583`；类 `N/A`；函数 `wp_set_post_series()`。

| 方向 | 机制 | 能否生成缺失编号 | 顺序语义 | 升级/运行风险 | 结论 |
|---|---|---:|---|---|---|
| A. 复制中文文章的 part | 读取中文 series 下的 part；映射到英文 series ID；通过 PublishPress 核心写入函数写同一数字 | 是 | 中英文编号精确对齐 | 中；需处理缺译、重复号、series term 映射 | 适合乱序翻译或严格一一对齐 |
| B. 调用官方排序机制/模拟人工发布 | 先让英文 draft 拥有英文 series relationship，再标准 publish，让官方 Hook 自动追加；或关系建立后显式调用 `set_series_order(..., 0, true)` | 是 | 追加到当前英文系列末尾 | **自动方案中最低**；遵循插件生命周期 | 最接近 AutoPoly 历史行为，优先候选 |
| C. 直接更新 `wp_term_relationships.term_order` | 直接数据库写 relationship 的 `term_order` | **否** | 不影响 PublishPress part | 高；写错权威字段且绕过插件 | 排除 |
| D. 保持未编号，后台人工排序 | 用户在 Manage Series 拖动后点 Update Order | 是，人工操作后才有 | 由人工排列决定 | 代码升级风险最低，但有操作成本与漏操作风险 | 可作为人工兜底，不适合全自动目标 |
| E. 仅清缓存 | 清 Object/Post/Page Cache | **否** | 不创建 part | 不能修数据状态 | 排除为编号修复 |

### A. 精确复制中文编号

- [分析设计] A 的业务目标是“相同 part 数字”，而不是“按翻译完成顺序追加”。应读取中文文章在**中文 series ID** 下的 part，再向**英文 series ID** 写同一值。
- [源码直接证明] PublishPress 的 key 含 series ID；PolylangAdapter 也可能把源 term ID 映射为另一个目标 term ID。key 路径 `sources/publishpress-series-installed/orgSeries-setup.php:80-91`；类 `orgSeries`；方法 `__construct()`、`part_key()`。term 映射路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:204-218`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [分析设计] 若采用 A，应该复用 PublishPress 的 `set_series_order($target_series_id, $target_post_id, $source_part, true)` 语义，而不是直接写数据库；这样仍使用实际插件的核心机制。
- [风险] 如果中文有 part 5、英文只翻译了 1 和 5，英文系列会出现缺口；如果目标已有 part 5，还需冲突策略。源码不会自动解决显式重复号。依据 `set_series_order()` 对非零值直接写入：`sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A`；函数 `set_series_order()`。

### B. 官方自动追加 / 模拟人工发布

- [源码直接证明] 标准生命周期方案是：relationship 已存在 → draft_to_publish → `wp_set_post_series_draft_transition()` → `set_series_order(..., 0, true)` → 当前末尾加一。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,409-417,814-815`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series_draft_transition()`。
- [合理推断，高置信度] AutoPoly 时代按中文顺序逐篇进入英文编辑器并发布，很可能正是依次走这条链，所以最终英文 part 为 `1`、`2`……。依据路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,409-417,814-815`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series_draft_transition()`。
- [分析设计] 对当前 GLM 5.2 MU Plugin，最自然的落点与 Object Cache 的首选生命周期修复一致：apply 时保持 draft；taxonomy、翻译关系和精确正文完成；最后通过标准 post API 发布。这样不需要直接知道 post meta key，也不需要伪造后台请求字段。
- [分析设计] 若某分支无法产生真实的 draft → publish（例如更新已发布译文），可在 relationship 完成后调用插件实际核心函数作为兼容 fallback；但这比自然状态转换多一层函数存在性与版本兼容判断。
- [限制] B 只保证英文系列内“按完成/发布先后追加”。若以后乱序翻译、补译旧文章，它不会自动对齐中文 part；此时 A 更符合目标。

### C. 直接更新 `wp_term_relationships.term_order`

- [源码直接证明] 这不是 PublishPress 3.1.2 的 part 存储位置。插件用 post meta 读写 part，relationship 与编号是两个独立步骤。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,553-583`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series()`。
- [结论] C 属于直接数据库操作，而且写错权威字段；即便值改变，后台 `column_part()` 仍读不到编号，因此不能解决“当前没有部分编号”。不应采用。

### D. 暂时无编号，后台手动排序

- [源码直接证明] 管理页 Update Order 把拖动后的 ID 序列交给 `series_issue_manager_part()`；它清空 part 后按顺序调用 `publisher_wp_set_post_series()`，最终进入 `set_series_order()`。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:7-44,148-172,829-845`；类 `PPS_Publisher_Admin`（页面输出）/`N/A（过程式排序函数）`；方法 `ppseries_publisher_part_output()`；函数 `series_issue_manager_part()`、`publisher_wp_set_post_series()`。
- [结论] D 使用插件官方后台机制，插件升级风险和自动代码风险最低，但在人工操作前问题仍存在，也容易漏做；适合作为低频兜底，不适合当前自动翻译目标。

### E. 仅执行缓存清理

- [源码直接证明] “当前部分”直接读 post meta，核心写入由 `set_series_order()` 完成。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。写入路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A`；函数 `set_series_order()`。
- [结论] 缓存清理不会创建 `_series_part_<id>`，不能解决缺编号或第一项；它只对先前确认的 stale relationship 问题有效。

### 选择建议

1. **最适合现有 GLM 5.2 MU Plugin：B 的自然生命周期形式。** 它与“全程 draft、完成后统一 publish”的缓存修复共用同一个时序调整，并让 PublishPress 自己分配编号，最接近历史人工发布行为。
2. **需要精确中英文槽位时选择 A。** 特别是翻译可能乱序、补译或重跑时；实现仍应通过 PublishPress 核心函数，不直接写数据库。
3. **不要选择 C 或 E。** C 写错字段，E 不写数据，都不能消除“当前没有部分编号”。
4. **D 是最低升级风险的人工兜底。** 如果比较的是自动化代码方案，则 B 的标准状态转换风险最低。
5. **中文与英文 series 必须分别处理。** A 从中文 series 读取、向英文 series 写；B 只在英文 series 自动追加。两种方案都不应重新编号或修改中文 series。

### 下一阶段条件

[结论] 已具备生成最小兼容补丁的源码条件：实际插件版本、part key、核心函数、后台路径、自动编号 Hook 与 SlyTranslate 的错误时序均已确认。开始编码前只剩一个业务选择：要“严格复制中文 part（A）”，还是“复现 AutoPoly 的按英文发布顺序自动追加（B）”。若继续沿用按中文顺序逐篇翻译，B 是更小、升级风险更低且同时改善 Object Cache 生命周期的方案。

## 必须回归的边界（后续阶段）

- 新建翻译与 overwrite 现有 publish 翻译分开验证；
- 输入 status 为 draft/pending/private/future/publish；
- category、post_tag、series、language、post_translations 在 publish hook 时已完整；
- exact content raw write 与最终标准 status update 的内容一致性；
- Page Cache、taxonomy archive、series 页面、CDN 只在完整状态后失效/重建；
- 只清目标 ID 的 post/object-term cache，不做全站 flush。
- Series：英文 relationship 必须在最终 publish 前存在；确认 part meta 使用英文 series ID 后缀；新建、乱序翻译、补译和 overwrite 已发布译文分别验证。
- Series：若选 A，验证缺号/重复号/不同语言系列不完整；若选 B，验证发布顺序即期望英文顺序。
