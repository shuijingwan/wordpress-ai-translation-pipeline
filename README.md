# WordPress AI Translation Pipeline

## 中文简介

本仓库用于记录和维护 WordPress 中文技术文章的英文翻译流程，主要涉及：

- SlyTranslate 定制与调用流程；
- 智谱 GLM 翻译质量优化；
- Gutenberg 区块和 HTML 结构保护；
- 代码块、短代码、URL、路径和产品名保护；
- Polylang 中英文文章与媒体关系；
- 英文特色图片媒体附件与 alt 同步；
- 自动化测试、技术排查和生产验证记录。

这是从真实 WordPress 站点实践中形成的站点专用实现，不保证能够直接用于所有 WordPress 环境。使用前应在隔离的测试环境中核对依赖、数据模型、插件配置和 WordPress 生命周期行为。

仓库中的域名、服务器目录、文章 ID、附件 ID 和部署记录可能来自真实生产实践。这些信息本身不是认证凭证。仓库不应包含可用的 API Key、密码或私钥，也不应提交自己的 `wp-config.php`、Cookie、Nonce、数据库导出或其他认证数据。

## 当前主要环境

本项目围绕以下组件工作：

- WordPress 与 Gutenberg；
- Polylang；
- SlyTranslate；
- 智谱 GLM；
- PHP；
- 站点专用 MU Plugin。

具体版本、兼容性和运行环境可能随时间变化，应以实际代码、插件头和仓库中的验证记录为准。

## 仓库结构

- `swq-glm52-translation-tuning.php`：当前站点专用的 MU Plugin 调优实现。
- `sources/`：分析和复现所需的源码快照；其中可能包含安装态第三方插件源码。
- `patch/`：测试、补丁、候选实现和验证工具。
- `archive/`：历史候选或未发布方案，不代表当前生产版本。
- `evidence/`：真实技术排查、源码收集和部署证据。
- `analysis/`：调用链、根因和方案分析。

这些目录并不都是可直接安装的正式产品。部署或复用前应确认权威文件、版本和适用范围。

## 测试

特色图片媒体附件与 alt 同步相关测试可通过以下命令运行：

```bash
bash patch/run-featured-image-tests.sh
```

Plaintext 区块完整性、受保护 Token 和相邻 Token 修复测试可通过以下命令运行：

```bash
php swq-plaintext-structure-test.php
php swq-token-validation-test.php
php swq-adjacent-token-repair-test.php
php swq-tail-struct-token-repair-test.php
php patch/token-normalization-tests.php
```

测试使用轻量 mock/stub 验证站点专用逻辑，不能替代与目标 WordPress、Polylang 和 SlyTranslate 版本一致的集成测试。

## 安全说明

- 不要提交真实 API Key、Access Token、Cookie、Nonce、session、数据库密码、私钥或 `wp-config.php`。
- 真实域名、目录和文章或附件 ID 本身不属于认证凭证；安全不能依赖隐藏公开路径或资源 ID。
- 生产操作前必须自行创建可验证备份，并执行语法、依赖和行为检查。
- 从本仓库复制命令时，应重新确认目标路径、权限和环境，避免将示例直接用于其他站点。

## 许可证

仓库所有者原创且有权授权的代码和文档采用 MIT License，完整条款见 [LICENSE](LICENSE)。

第三方代码、vendor 依赖、翻译和资源继续遵循其各自的原始许可证，并不因收录在本仓库而被重新授权。详情见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。

## English Summary

This repository documents a production-derived WordPress AI translation workflow. It includes site-specific MU plugin code, tests, patches, source snapshots, and technical investigation records.

Original project code and documentation that the repository owner is entitled to license are provided under the MIT License. Third-party components remain under their respective licenses. Users must review dependencies and test the code in a safe environment before production use. No credentials should be committed to this repository.
