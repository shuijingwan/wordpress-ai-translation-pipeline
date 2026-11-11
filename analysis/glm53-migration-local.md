# GLM-5.3 本地迁移记录

阶段：本地副本修改完成。仅修改本地仓库，未进行真实 API 翻译、生产 REST 调用、SSH、数据库操作、部署、Git commit 或 push。

## 来源与文件差异

- 当前维护入口及本轮唯一后续部署取件文件：`/home/wangqiang/code/wordpress-ai-translation-pipeline/swq-glm52-translation-tuning.php`。沿用相同 MU Plugin 文件名；历史类 `SWQ_GLM52_Translation_Tuning` 和回调 `tune_glm52_request` 不重命名，运行逻辑只匹配 `glm-5.3`。
- 修改前 Git HEAD 为 `36a9621`。根目录文件修改前 SHA-256：`95c9b6b03bd8f78c1f39117edc4513277d7452dff948579830c503c41206aef8`，与本地 `analysis/tag-omission-20261020/source/wp-content/mu-plugins/swq-glm52-translation-tuning.php` 完全相同。该未跟踪采集副本仅作只读核对，保持原样；没有查询当前生产服务器。
- README 将根目录文件标为当前实现。Git 中根目录文件自 `aeca9aa` 起独立维护，后续包含 Paragraph Run（`ddca594`）、结构签名与诊断修复、普通 critical/mandatory 词汇误判修复及带属性 p 标签修复（`3e2d43c`）。因此本轮以根目录文件为迁移基线。
- `sources/mu-plugins/swq-glm52-translation-tuning.php` 来源于安装态采集，参见 `evidence/installed-sources.md`。其最后一次更新为 `f9109a7`（2026-08-08），缺少上述后续维护改动，仍保留为历史 GLM-5.2 快照。本轮不将它迁移、不覆盖、不作为 GLM-5.3 部署来源。
- 两文件修改前共相差 599 行新增、20 行删除（以旧快照到根目录计）。差异并非纯粹单向升级：快照另含 `f9109a7` 的“无中文 Plaintext 隐藏为完整 SWQBLOCK”优化；根目录仍采用“暴露语义上下文、还原时 preserve_source”的既有实现。模型迁移保留当前实现，不自动合并该优化，也不引入归档的未发布动态 Token 命名空间补丁。
- `DEVELOPMENT-BASELINE.md` 记录的是 2026-07-17 的历史排查基线，早于当前维护提交及 10 月采集副本。本轮为指定仓库的本地模型迁移，未把历史文档中的 sources 路径当作当前部署来源，也未重新执行生产采集。
- 开始时存在的未跟踪 `analysis/tag-omission-20261020/` 文件全部保留；完成时逐文件核对 SHA-256，未改动其内容。

## API 核对与实现

核对日期：2026-11-11。官方文档：

- [GLM-5.3](https://docs.bigmodel.cn/cn/guide/models/text/glm-5.3)：始终启用 thinking，只允许 enabled；推理强度支持 low/high/max。本轮按需求固定 high。
- [对话补全 API](https://docs.bigmodel.cn/api-reference/模型-api/对话补全)：支持 do_sample=false（temperature/top_p 此时忽略）；响应中最终答案为 message.content，思考为 message.reasoning_content，并提供 finish_reason。
- [核心参数](https://docs.bigmodel.cn/cn/guide/start/concept-param)：GLM-5.3 默认 max_tokens=65536，最大 131072。
- [深度思考](https://docs.bigmodel.cn/cn/guide/capabilities/thinking)：思考与最终回答分别返回，不可用思考内容替代译文。

请求及响应变化：

1. 请求 body.model、REST model_slug、整篇实际请求防护统一使用去空白、不区分大小写的完整模型 ID 相等比较。GLM-5.2、GLM-5.3-Flash、FlashX、前后缀和版本近似值均不匹配。不增加双模型或切换配置；不负责删除 SlyTranslate 原生配置中的其他模型。
2. 强制 thinking.type=enabled、reasoning_effort=high；清除旧 chat_template_kwargs，避免 enable_thinking=false 冲突；保留官方支持的 do_sample=false。强制 stream=false，沿用现有一次返回完整结果的解析流程。
3. 思考会占用输出预算：普通字段使用至少 65536，最多 131072；整篇固定 131072。它们是共享预算，不能保证其中有固定份额留给正文。增加预算不会增加模型请求次数。
4. timeout 是 WordPress HTTP 客户端参数，单位秒，并非本次核对的 API body 字段。保留整篇最低 600 秒策略，并应用到 GLM-5.3 字段请求；保留调用方设置的更长超时。未进行实测延迟或吞吐保证，超时继续走安全退出。
5. 新增 priority=0 的 http_response 检查，先于安装态运行时 priority=1 的 reasoning-only 内容提升逻辑。拒绝 length、非 stop/缺失 finish_reason、输出用量达到请求预算、成功状态但无有效 choice、缺少非空最终 content。思考内容不提升为正文；HTTP/传输错误交回既有错误处理。
6. 截断或预算耗尽返回 swq_glm53_output_truncated。仅有思考内容返回 swq_glm53_final_content_missing。此类失败不允许使用部分译文；整篇第二次请求仍被原有防护拦截，不自动重试。
7. 显示名称、描述、错误提示、运行日志和 /tmp 诊断前缀更新为 GLM-5.3。现有保护、修复、验证、草稿状态、apply 和精确正文保存算法保持原样。

## 离线验证

```bash
php -l swq-glm52-translation-tuning.php
php -l sources/mu-plugins/swq-glm52-translation-tuning.php
php -l swq-glm53-migration-test.php
php swq-glm53-migration-test.php
php swq-glm53-migration-test.php --workflow
php swq-plaintext-structure-test.php
php swq-token-validation-test.php
php swq-adjacent-token-repair-test.php
php swq-tail-struct-token-repair-test.php
php swq-paragraph-run-test.php
php swq-field-assistant-validation-test.php
php patch/token-normalization-tests.php
php patch/featured-image-media-sync-tests.php
php patch/featured-image-sync-tests.php
git diff --check
```

新增迁移测试覆盖模型排除/触发、thinking 覆盖、采样及流式参数、预算与超时边界、截断/仅思考/异常响应、真实安装态运行时的响应过滤器顺序、单请求限制和维护版本 Plaintext 处理。

`--workflow` 使用离线传输夹具和真实现有 TranslationValidator。约 110 KB 完整文章通过整篇保护、还原、验证后到达 apply 检查点，确认不拆分请求、正文还原和草稿策略；截断、仅有思考、超时、重试、重复 Token 均不到达 apply。该夹具不创建 WordPress 数据库，也不模拟实际生产保存成功；保存算法通过未改动的 diff 和既有测试核对。

原 Plaintext 测试保留针对旧快照的优化断言。相邻 Token 测试改为加载根目录维护文件；Token 和 Paragraph Run 测试仅适配新增过滤器数量及 GLM-5.3 诊断文件名。其他既有测试不改动断言。

特色图片测试直接运行 PHP 测试文件。旧 `patch/run-featured-image-tests.sh` 另含“根目录与 sources 文件 SHA 必须相同”的过时断言，两份文件在迁移前已经不同，本轮未运行或修改该包装脚本，也未为了满足它覆盖快照。

本轮不具备真实 API 与 WordPress 集成验证结论。后续交维护者审查，生产部署尚未执行。

## 完成结果

- 11 次离线测试运行通过，合计 347 项 PASS 断言；其中新增参数/响应测试 70 项，整篇流程测试 25 项。
- 当前实现、历史 sources 快照、新测试及 3 个改动的既有测试共 6 份 PHP 文件语法检查通过。
- 完整 diff 与空白检查通过。对比 HEAD 的方法体，剔除 GLM 日志名称和 /tmp 路径替换后，只有 boot、请求调参、实际请求模型防护及 REST 模型匹配发生变化；新增两个方法分别负责响应检查和精确匹配。
- 原有 652 个未跟踪文件逐文件 SHA-256 核对通过；历史 sources 文件未修改。
- 最终根目录 MU Plugin SHA-256：`426857efa513e872ddfc8b75a03cd6107887126baebba9278ee8c739fd9b9021`。
- 完整本地补丁：`/tmp/glm53-migration-local.patch`；逐项测试结果：`/tmp/glm53-final-tests.json`。

修改文件（均相对于项目根目录）：

1. `swq-glm52-translation-tuning.php`
2. `swq-glm53-migration-test.php`（新增）
3. `swq-adjacent-token-repair-test.php`
4. `swq-paragraph-run-test.php`
5. `swq-token-validation-test.php`
6. `README.md`
7. `analysis/glm53-migration-local.md`（新增）
