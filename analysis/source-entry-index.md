# 源码入口索引

## 审计范围与基线

- Git 基线：`9eeeadd8c58d9c55e325fe0b1d435b0d0789d5d1`，tag `installed-baseline-2026-07-17`。
- SlyTranslate 插件头声明版本 `1.9.0`。[源码直接证明] 路径 `sources/slytranslate-installed/slytranslate.php:2-13`；类 `SlyTranslate\AI_Translate`（文件主类）；方法 `N/A（插件头）`。
- GLM MU Plugin 插件头声明版本 `2026.07.16.12`。[源码直接证明] 路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:2-6`；类 `SWQ_GLM52_Translation_Tuning`；方法 `N/A（插件头）`。

## 结论等级

- **源码直接证明**：可由当前 `sources/` 中的具体语句与调用顺序直接得出。
- **合理推断**：当前源码给出必要条件，结合题设中的生产证据或 WordPress 公共行为推导，但缺少至少一个外部组件源码。
- **尚未确认**：当前源码不足以判定，报告会明确列出缺失材料。

## 主要入口

| 作用 | 路径、类、方法、行号 | 审计用途 |
|---|---|---|
| REST 注册 | `sources/slytranslate-installed/slytranslate.php:157-279`；`SlyTranslate\AI_Translate::register_editor_rest_routes()` | 原生 REST 路由、回调和统一 wrapper |
| 原生 translate-content 回调 | `sources/slytranslate-installed/slytranslate.php:390-439`；`SlyTranslate\AI_Translate::execute_translate_content()` | 真正的原生 `/translate-content/run` 控制器 |
| 原生 server-side 翻译编排 | `sources/slytranslate-installed/inc/PostTranslationService.php:29-425`；`SlyTranslate\PostTranslationService::translate_post()` | 原生翻译、meta 处理、adapter 调用和结束步骤 |
| client prepare | `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:44-84`；`SlyTranslate\ClientTranslationWorkflowService::prepare_single()` | 拆分 translation units；不持久化目标文章 |
| client apply | `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:92-195`；`SlyTranslate\ClientTranslationWorkflowService::apply_single()` | 组装 adapter payload 并持久化 |
| 状态归一化 | `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:517-536`；`SlyTranslate\ClientTranslationWorkflowService::normalize_post_status_for_client()` | 请求状态、源文章状态、draft fallback |
| Polylang 持久化 | `sources/slytranslate-installed/inc/PolylangAdapter.php:106-228`；`SlyTranslate\PolylangAdapter::create_translation()` | draft 创建、语言、文章更新、meta、taxonomy、翻译关系的准确顺序 |
| Polylang 翻译关系 | `sources/slytranslate-installed/inc/PolylangAdapter.php:230-240`；`SlyTranslate\PolylangAdapter::link_translation()` | `pll_save_post_translations()` 调用 |
| client meta units | `sources/slytranslate-installed/inc/MetaTranslationService.php:524-562`；`SlyTranslate\MetaTranslationService::build_meta_units()` | GLM/client 流程实际带入 apply 的 meta 范围 |
| native meta 处理 | `sources/slytranslate-installed/inc/MetaTranslationService.php:91-190`；`SlyTranslate\MetaTranslationService::prepare_translation_meta()` | 原生 server-side 流程复制、翻译或清除 meta |
| GLM REST 截获 | `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246`；`SWQ_GLM52_Translation_Tuning::intercept_translate_content()` | 分叉点与原生回调是否被绕过 |
| GLM 翻译编排 | `sources/mu-plugins/swq-glm52-translation-tuning.php:248-477`；`SWQ_GLM52_Translation_Tuning::run_full_article_job()` | prepare、翻译、apply、exact persist、返回 |
| exact content 写入 | `sources/mu-plugins/swq-glm52-translation-tuning.php:491-585`；`SWQ_GLM52_Translation_Tuning::persist_exact_post_content()` | 二次数据库写入与缓存动作 |

## 关键结构判定

1. [源码直接证明] 原生 `/ai-translate/translate-content/run` 映射到 `AI_Translate::execute_translate_content()`，后者调用 `AI_Translate::translate_post()` / `PostTranslationService::translate_post()`，并不调用 client prepare/apply。路径 `sources/slytranslate-installed/slytranslate.php:193-200,390-439,796-798`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_translate_content()`、`translate_post()`。
2. [源码直接证明] `prepare_single()` 与 `apply_single()` 分别暴露为 `/prepare-client-translation/run` 和 `/apply-client-translation/run` 两个独立 POST 端点。路径 `sources/slytranslate-installed/slytranslate.php:244-251,265-277,772-780`；类 `SlyTranslate\AI_Translate`；方法 `register_editor_rest_routes()`、`execute_prepare_client_translation()`、`execute_apply_client_translation()`。
3. [源码直接证明] GLM MU Plugin 把原本独立的 client prepare/apply 串入 `/translate-content/run` 的一次请求。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:152-246,248-477`；类 `SWQ_GLM52_Translation_Tuning`；方法 `intercept_translate_content()`、`run_full_article_job()`。

## 当前源码缺口

- [尚未确认] 工作区不含 WordPress Core、W3 Total Cache object-cache drop-in、Polylang、实际 series 插件源码，因此不能仅凭当前源码证明 WordPress hook 的每个内部触发点、W3TC 键删除实现或 series 插件的排序字段。相关外部调用点可在 `sources/slytranslate-installed/inc/PolylangAdapter.php:137-218` 的 `SlyTranslate\PolylangAdapter::create_translation()` 中看到，但被调用组件的实现不在审计范围。

