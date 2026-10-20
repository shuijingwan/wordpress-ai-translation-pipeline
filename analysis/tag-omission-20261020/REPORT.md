# 单文件标签迁移方案：重新评估结果

当前阶段：**本地副本修改完成**。本轮仅使用本地生产源码副本和上一轮真实证据；未连接生产服务、未修改生产文件/数据库/缓存/草稿，未重译、未部署、未提交。

推荐采用单文件候选：`candidate/PolylangAdapter.php`。当前 `minimal-fix.patch` 仅包含 `wp-content/plugins/slytranslate/inc/PolylangAdapter.php`。旧两文件候选、报告和结果保存在 `previous-two-file/`，仅供比较。活跃目录中的标签批处理脚本已恢复为生产快照原文，并从当前补丁排除。

## 为什么单加 cache_results=false 不够

生产 Polylang 3.9.0 的 `pll_get_term()` 没有强制读新参数。它经过 `PLL_Translated_Object::get()` / `get_translations()`，先取 `term_translations_relationships` 中的组 ID，再通过 `get_term()` 取得组对象 description。`get_raw_translations()` 仍走相同缓存路径；换这个接口不能解决。

`wp_get_object_terms(..., cache_results=false)` 可以绕过 term-query 结果缓存，但不能让另一次 `pll_get_term()` 忽略 `terms` 组缓存。即使查询翻译组时也用此参数，Core 对 term 的 hydrate/prime 路径仍可能保留已缓存对象。因此本候选不用“查询一次后再 pll_get_term”冒充绕过缓存。

第二次真实证据已包含：数据库及 CLI 组 46229 为 `{zh:46228,en:46232}`、组 46231 为 `{zh:46230,en:46234}`；域名缓存两组都只有 zh。当前候选直接读取数据库中的组 description，既不消费这两个旧对象，也不删除/改写它们。

## 实际修改

1. 保留上一候选的标签预检：通过 `wp_get_object_terms(source, 'post_tag', ['fields'=>'ids','cache_results'=>false,'lang'=>''])` 获取源 ID；任何映射缺失或读取异常明确返回错误。
2. 将预检内的 `pll_get_term()` 改为一个私有 `get_uncached_tag_translation()`。每个源标签执行一次参数化 SELECT，只关联 `$wpdb->term_relationships` 和 `$wpdb->term_taxonomy`，限制 `object_id`、`taxonomy='term_translations'`、`LIMIT 2`；SQL 仅用于标签映射，不用于文章关系或标签写入。
3. 对 description 做禁止类实例化的 PHP 反序列化；检查源语言成员 ID、目标整数 ID；数据库错误、多组、无映射和未知格式均不能静默成功。没有回退到缓存 API，也没有隐式建标签来掩盖旧映射。
4. 标签仍用 WordPress `wp_set_object_terms()` 写入；所有同步钩子与 link 调用后，用无查询结果缓存的 API 读取实际标签 ID，比较完整集合的 missing/extra；不把返回 tt_ids 当成已经落地的 term_id。
5. 移除上一候选对文章 `pll_get_post()` 双向即时读取的成功条件。`link_translation()` 与生产原实现完全一致：保留原保存调用和返回语义。旧文章关系接口不会单独导致新增标签迁移被判失败。实际保存返回 false 仍按原逻辑报错，并附带已创建草稿 ID。

仍采用本站“提前准备好全部 post_tag 映射”的策略；没有增加自动重试、自动重新翻译、全站清缓存或全局禁用对象缓存。新检查发生于 adapter apply 阶段，不能承诺避免此前已经完成的 LLM 调用。分类、series 等其他 taxonomy 复制流程保持生产实现。

## 与两文件方案比较

行数均按当前生产快照的 unified diff 统计，含注释与空行。

| 项目 | 原两文件候选 | 当前单文件候选 |
|---|---|---|
| 可部署代码文件数 | 2 | 1 |
| 增加 / 删除行数 | +68 / -3 | +98 / -2 |
| 代码净增加 | 65 行 | 96 行，较旧方案多 31 行 |
| 处理路径 | 统一批处理 Host，让未来缓存失效一致；旧映射未修复时预检报错 | 标签迁移直接读组映射，不需要跨 Host 缓存一致 |
| 保留真实旧组缓存时 | 拒绝创建草稿 | 仍写入正确 8 个标签 |
| 额外数据库读 | 正常 API 路径 | 每个源标签一次小范围 SELECT；本案 8 次 |
| 维护依赖 | 标签维护入口、Host、缓存命名空间 | Polylang 翻译组存储格式 |
| 文章关系接口旧缓存 | 上一候选可因此误报失败 | 不增加此失败条件 |
| 全站缓存影响 | 会改变维护入口所使用的命名空间 | 不修改 Host，不增加缓存失效操作 |

单文件方案**不是源码行数更少**。它以约 30 行额外代码换取更小的运行和发布范围，符合本轮“只让标签迁移读新”的目标。保留旧缓存仍可通过真实快照回归，是本轮推荐它的主要依据。

## 版本兼容性与边界

- 已按本地生产 Polylang 3.9.0 的 `save_translations()` / `get_raw_objects_translations()` 核对：term_translations 的 description 是语言代码到 term ID 的 PHP 序列化数组。两次故障的数据库快照也符合该格式。源码注释表明该翻译组读写模型已有较长历史，但这不等于测试过所有历史版本。
- SQL 使用 WordPress 提供的实际表名和 prepare，不写死表前缀；使用普通 SELECT/JOIN/LIMIT，不依赖特定 MySQL 新特性。空组、重复组、数据格式异常会显式失败。
- 翻译组存储属于 Polylang 内部约定，**不是其公开 API 的兼容承诺**。当前生产 3.9.0 可用；未来升级若修改 taxonomy 名称或序列化结构，需要重新核对并复跑此小测试。不能宣称对所有 Polylang 版本通用。
- 选用单个小 helper 隔离此依赖，没有引入版本分派器、缓存同步器、跨命名空间扫描或复杂适配层。若将来官方提供真正不读缓存的映射 API，应优先用它替换 helper。
- 写入与最终核验仍依赖 WordPress API 及正常同步钩子；若存在未覆盖的第三方过滤器或后续异步改写，不在本次离线回归的保证范围内。
- 文章关系缓存、既有翻译发现、历史草稿补齐和全站缓存同步均不在本补丁处理范围。已知两篇历史文章不得用重新翻译来验收本补丁。

## 测试结果

复用 `tests/replay.php`，没有新增测试框架或 WordPress 环境。它加载真实生产 Polylang 翻译组读取代码、真实原版/旧候选/新候选 Adapter；其余 WP 读写、数据库连接使用轻量替身。

- 原版 + 真实域名旧组快照：成功返回但只有 6 个标签。
- 旧两文件候选 + 同样旧组：明确报缺失映射。
- 单文件候选 + 同样旧组：成功写入 8 个。测试结束时共享缓存快照字节未变，标签映射没有调用 pll_get_term。
- 文章关系 API 在保存之后仍返回 0：标签完整时正常成功，不产生上一方案的误报。
- 源查询结果缓存旧值、term relationship 旧值、完整映射、第一次故障三个实际数据库映射，均通过相应读取检查。第一次只有事后证据，不把这些测试称为第一次故障瞬间的历史重现。
- 真正缺映射、无翻译组、SQL 错误、重复组、未知序列化格式、源成员错误、非法目标 ID，均停止并明确报错。
- 写入错误、部分成功、写入额外标签、link 钩子移除标签、最终读取失败，均不能静默成功。原保存返回 false 的行为也有覆盖。
- 本轮合计 20 个候选场景、2 个旧方案对照，另有 1 项本地 SQL 执行检查通过。`check-mapping-sql.py` 将候选实际生成的 8 条 SELECT 放到本地 SQLite 快照表执行，检查 JOIN、表前缀、taxonomy 隔离和多组检测。它补足替身的 SQL 语义验证，但不是 MySQL/WordPress 集成测试。
- PHP 语法检查、补丁空白检查及在隔离本地副本上应用并逐字节比较候选均通过。详见 `tests/results.txt`。

## 交付和唯一下一步

- 当前候选：`candidate/PolylangAdapter.php`。
- 单文件差异：`minimal-fix.patch`。
- 基线 Adapter SHA-256：`00cecb4ca1ed349235cd7192b396cd4a7b8bf7652b918a4ee2b103b8914bc21b`。
- 候选 Adapter SHA-256：`42687dd8184d3a0ca84e8f4bf8d192fb492fb97b42c263b391e9b4271fe346ef`。
- 上轮根因分析、两文件候选及一次性补齐讨论仅作历史记录，见 `previous-two-file/REPORT.md`；其中两文件部署范围已被本报告取代，不代表当前任务授权。

唯一下一步：审阅当前单文件 diff，确认接受“针对当前 Polylang 3.9.0 存储格式的标签映射只读查询”这一有限依赖。本轮不进入部署或历史草稿修复。

官方资料仅用于核对公开 API；缓存内部与格式结论以生产源码为准：

- [WP_Term_Query 参数](https://developer.wordpress.org/reference/classes/wp_term_query/)：cache_results 在 WordPress 6.4 引入。
- [Polylang 公开 API](https://polylang.pro/documentation/support/developers/function-reference/)：pll_get_term 没有 bypass-cache 参数。
