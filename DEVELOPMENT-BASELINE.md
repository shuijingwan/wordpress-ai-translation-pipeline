# Development baseline policy

SlyTranslate MU Plugin 后续修复必须遵守以下规则：

1. 每次处理新 Bug 前，先从生产环境当前实际文件重新只读取得基线，并记录生产版本和 SHA-256。
2. 新修复只能基于已核验的当前生产基线制作，不得默认使用历史候选文件。
3. 部署前 diff 必须以当前生产文件为基准。
4. 不得自动合并任何标记为“未部署”或“未批准”的补丁。
5. 特别禁止默认合并 `archive/unreleased-token-namespace-2026.07.17.17/` 中的动态 token 命名空间补丁。
6. 若新 Bug 与归档补丁代码重叠，交付报告必须明确列出是否包含归档补丁中的修改。
7. 只有用户在当前任务中明确授权后，才可重新评估或合并归档补丁。

截至本次收尾，默认开发文件 `sources/mu-plugins/swq-glm52-translation-tuning.php` 已恢复为生产基线 `2026.07.17.16`，基线 SHA-256 为 `965f4b90f35a9f1b02979c7d440632fef92ae158bba6b92248602d599a42b2f0`。
