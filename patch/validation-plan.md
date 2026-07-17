# Draft-only 候选验证计划

## 测试前提

- 在隔离的 WordPress 验证环境使用与生产一致的 SlyTranslate 1.9.0、Polylang、PublishPress Series Free 3.1.2、W3TC 配置和候选 MU Plugin。
- 使用专用测试文章和此前从未公开的新英文 URL，避免依赖全站缓存清理。
- 每个场景记录中文文章 ID、英文文章 ID、中文/英文 series term ID、翻译前后英文状态和 REST 返回状态。
- A、B 应确认不存在英文翻译；C、D 应确认 Polylang 已关联目标英文文章并启用 overwrite。
- 所有断言均通过 WordPress API、后台界面和页面响应验证，不直接修改数据库。

## 状态测试矩阵

| 场景 | 初始条件 | 操作 | apply 前预期决策 | 翻译完成后必须满足 |
|---|---|---|---|---|
| A | 中文 `publish`；无英文翻译 | 执行 GLM 5.2 翻译 | `existing_translation_id=0` → `draft` | 英文为 `draft`，不得继承中文 `publish` |
| B | 中文 `draft`；无英文翻译 | 执行 GLM 5.2 翻译 | `existing_translation_id=0` → `draft` | 英文为 `draft` |
| C | 已存在英文 `draft` | 开启 overwrite 再次翻译 | 读取现有状态 `draft` | 英文仍为 `draft` |
| D | 已存在英文 `publish` | 开启 overwrite 再次翻译 | 读取现有状态 `publish` | 英文始终保持 `publish`，不得自动降级 |

每个场景还需确认：

- GLM 5.2 仍只执行既有的一次性整篇翻译请求。
- 标题、摘要、正文均为本次翻译结果。
- 占位符保护、受保护结构恢复和 exact-content SHA256 校验仍成功。
- REST 返回的 `translated_post_id` 正确，返回 `post_status` 与实际英文文章状态一致。
- 没有额外自动发布、状态回写或第二次标准 `wp_update_post()`。

## 新英文草稿完整性检查（场景 A、B）

在翻译完成、人工发布之前检查：

1. 英文文章状态为 draft。
2. 标题、摘要、正文完整且与翻译结果一致。
3. 分类关系正确，并映射到预期英文分类。
4. 标签关系正确，并映射到预期英文标签。
5. `series` taxonomy relationship 正确，并指向预期英文 series term。
6. Polylang 中英文翻译关系双向正确。
7. PublishPress Series 此时允许尚无部分编号；候选不得预写 `_series_part_<英文 series term ID>`。
8. 匿名访问英文正式 permalink 不应返回公开文章；登录用户的 preview 不算公开访问。
9. 正式英文 URL 不应生成可供匿名用户命中的 W3TC Page Cache 页面。
10. taxonomy 归档、series 页面和 Cloudflare 公网页面不应出现该 draft。

## 已有翻译检查（场景 C、D）

### 场景 C：已有 draft

- 翻译前后状态均为 draft。
- 原英文文章 ID 不变，不创建重复翻译。
- taxonomy、series 与 Polylang 关系保持正确。
- 正文 exact persistence 和占位符恢复继续有效。

### 场景 D：已有 publish

- 翻译前、apply 后、exact-content persistence 后均为 publish。
- 原英文文章 ID 不变，不创建重复翻译。
- 不出现临时 draft、404 或发布状态抖动。
- taxonomy、series 与 Polylang 关系同步结果正确。
- 这是在线覆盖路径；需额外观察现有页面缓存失效行为，但不得用全站 flush 作为通过条件。

## 手动逐篇发布验证（场景 A、B）

按照期望的英文系列顺序逐篇操作，每篇在英文编辑器中使用正常“发布”操作：

1. 发布前再次确认文章为 draft，且英文 series relationship 已存在。
2. 发布后确认发生 draft → publish，而不是程序化翻译阶段提前 publish。
3. 在 PublishPress Series 管理页确认自动生成部分编号，不再显示“当前没有部分编号”。
4. 通过 WordPress API/受控诊断确认编号位于 `_series_part_<英文 series term ID>`；不得写入中文 series ID 对应 key。
5. 第一篇、第二篇等编号与实际手动发布顺序一致，且没有 `0`、空编号或重复编号。
6. 分类、标签和 series 在英文前台文章及其归档中正确。
7. Polylang 中英文切换与翻译关系正确。

## 缓存与公开页面验证

对每个首次发布的新英文 URL，在不做全站缓存清理的前提下验证：

1. 发布后第一次匿名请求返回的 HTML 已包含正确分类、标签、series 和语言关系。
2. WordPress/W3TC relationship Object Cache 读取结果与数据库最终关系一致，不出现 `Uncategorized` 或空 tag/series。
3. W3TC Page Cache 首次生成的 HTML 正确；第二次命中缓存时内容仍正确。
4. taxonomy 归档和 series 页面首次生成即包含正确英文文章与部分编号。
5. Cloudflare 首次获取及随后缓存命中的页面均正确，不出现翻译阶段的 draft/不完整 HTML。
6. 精确执行单对象检查即可；不得把全站 cache flush 作为修复或测试前置条件。

## 回归与失败判定

出现以下任一情况即失败：

- A 或 B 的新英文文章翻译后为 publish、future、pending 或 private，而不是 draft。
- C 的已有英文 draft 被发布或改变为其他状态。
- D 的已有英文 publish 被降级或发生可观察的状态抖动。
- 新文章在 taxonomy/Polylang 关系完成前可公开访问或生成正式公开缓存。
- 手动发布后 PublishPress Series 仍无编号、编号写到中文 series key、编号重复或顺序错误。
- 分类、标签、series、Polylang 关系、一次性整篇翻译、占位符恢复或 exact-content 校验发生回归。
- 验证必须依赖全站缓存清理才能得到正确页面。

