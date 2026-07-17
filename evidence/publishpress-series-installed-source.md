# PublishPress Series 实际安装源码证据

## 收集结果

- 收集时间：`2026-07-17T10:56:03+08:00`；相对路径摘要复核时间：`2026-07-17T11:01:12+08:00`。
- 插件名称：`PublishPress Series Free`。
- 实际版本：`3.1.2`。
- Text Domain：`organize-series`。
- 实际目录名：`organize-series`。
- 实际主文件名：`orgSeries.php`。
- 服务器路径：`/data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/organize-series`。
- 本地路径：`sources/publishpress-series-installed/`。
- 本地普通文件内容总大小：`9,174,618 bytes`（`du -sh` 显示 `11M`）。
- 本地 PHP 文件数量：`231`。
- 服务器 PHP 文件数量：`231`。

插件头直接来自生产服务器实际主文件；本地副本同一头部位于 `sources/publishpress-series-installed/orgSeries.php:3-10`。

## SHA256 核对

“目录 SHA256”采用下列可复现算法：进入插件目录后，按相对路径排序全部普通文件，对每个文件执行 `sha256sum`，再对完整清单执行一次 `sha256sum`。这样摘要同时覆盖相对路径、文件集合和文件内容，不受服务器与本地根路径不同的影响。

- 服务器目录 SHA256：`283504fdb117cace69054135ebaea1157e530d471324a6f132cff0f5a48c4cdd`。
- 本地目录 SHA256：`283504fdb117cace69054135ebaea1157e530d471324a6f132cff0f5a48c4cdd`。
- 核对结果：一致。

最初使用包含绝对路径的清单得到的摘要未作为目录摘要使用，因为绝对路径前缀本身会导致服务器与本地摘要不可比较；上述相对路径算法是最终核对口径。

## 实际执行的收集与核对命令

```bash
ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
plugin_dir=/data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/organize-series
if [ -d "$plugin_dir" ]; then
    echo "PLUGIN_DIR_OK=$plugin_dir"
    find "$plugin_dir" -maxdepth 1 -type f -name "*.php" -print
    grep -Il -m1 "^[[:space:]]*\*[[:space:]]*Plugin Name:" "$plugin_dir"/*.php
else
    echo "PLUGIN_DIR_NOT_FOUND"
fi
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun \
  'sed -n "1,90p" /data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/organize-series/orgSeries.php'

mkdir -p sources/publishpress-series-installed

scp -r -o BatchMode=yes -o ConnectTimeout=10 \
  aliyun:/data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/organize-series/. \
  sources/publishpress-series-installed/

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun \
  'cd /data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/organize-series && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum && du -sb . && find . -type f -name "*.php" | wc -l'

# 工作目录：sources/publishpress-series-installed/
find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum

du -sb sources/publishpress-series-installed
du -sh sources/publishpress-series-installed
find sources/publishpress-series-installed -type f -name '*.php' | wc -l
sed -n '1,35p' sources/publishpress-series-installed/orgSeries.php
```

## 只读性声明

- SSH 命令仅使用 `test`、`find`、`grep`、`sed`、`sha256sum`、`du`、`wc` 和 `cd`。
- `scp` 的方向仅为生产服务器到本地工作区。
- 本阶段没有向服务器创建、修改、删除或覆盖任何文件，没有访问数据库、清理缓存、启停插件或重载服务。

