# Dynamic token namespace patch archive

状态：未部署、未批准合并  
生产版本：2026.07.17.16  
候选版本：2026.07.17.17  
用途：解决源文章主动展示 `SWQ...END` token 时的碰撞  
限制：不得自动合并到后续 Bug 修复  
启用条件：只有获得明确授权后才能重新评估和合并

## Archived files

- `swq-glm52-translation-tuning-token-namespace.php`：未部署候选文件。
- `token-namespace-tests.php`：该候选的离线测试。
- `token-namespace.patch`：相对于生产版本 `2026.07.17.16` 的独立补丁。

此目录不是后续开发基线。后续修复必须重新只读取得并核验当时的生产文件，以生产文件为基准制作和审查 diff。若新修复与本补丁有代码重叠，必须明确说明是否包含本归档的任何修改；未经用户当前任务中的明确授权，不得合并。
