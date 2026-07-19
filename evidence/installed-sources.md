# 实际安装源码收集记录

- 收集时间：`2026-07-17T10:09:14+08:00`
- 当前工作区：`/home/wangqiang/code/wordpress-ai-translation-pipeline`
- 生产服务器 SSH 别名：`aliyun`
- WordPress 根目录：`/data/wwwroot/www.shuijingwanwq.com`

## SlyTranslate

- 名称：`SlyTranslate - AI Translation Abilities`
- slug：`slytranslate`
- 状态：`无法确认`（服务器的非交互环境中没有可用的 `wp`、`php` 或 `mysql` 命令；未加载 WordPress，也未扩大扫描范围）
- 版本：`1.9.0`
- 服务器路径：`/data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/slytranslate`
- 本地保存路径：`/home/wangqiang/code/wordpress-ai-translation-pipeline/sources/slytranslate-installed/`
- 本地目录大小：`1,032,971 bytes`（`du -sb`）
- 目录 SHA256：`ca8db28d663bf2f5e9738aa567ec4137f44cf7513d59943e99edf50d4ec119be`
- SHA256 口径：对目录中所有普通文件按相对路径排序，逐文件执行 `sha256sum`，再对完整清单执行 `sha256sum`。服务器目录与本地副本所得摘要一致。

## MU Plugin

- 文件：`swq-glm52-translation-tuning.php`
- 服务器状态：`存在`
- 服务器路径：`/data/wwwroot/www.shuijingwanwq.com/wp-content/mu-plugins/swq-glm52-translation-tuning.php`
- 本地保存路径：`/home/wangqiang/code/wordpress-ai-translation-pipeline/sources/mu-plugins/swq-glm52-translation-tuning.php`
- 文件大小：`80,221 bytes`
- SHA256：`5176c995e83cc60440ad8933da27c1ce3e6f2d603101078ef389588b282be0d8`
- 服务器文件与本地副本 SHA256 一致。

## 收集结果

- `sources/slytranslate-installed/` 包含 PHP 源码：`是`
- `sources/mu-plugins/swq-glm52-translation-tuning.php` 存在：`是`
- 本阶段对服务器执行写操作：`否`

## 实际执行过的命令

以下命令按执行顺序记录。SSH/SCP 均为服务器读取或服务器到本地的单向复制；本地命令只创建收集目录、计算摘要和生成本记录。

```bash
pwd && find . -mindepth 1 -maxdepth 3 -printf '%p\t%y\n' | LC_ALL=C sort

mkdir -p sources/slytranslate-installed sources/mu-plugins evidence analysis patch tmp

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
cd /data/wwwroot/www.shuijingwanwq.com
wp plugin list --fields=name,status,version --format=table --allow-root | grep -i sly
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
find /data/wwwroot/www.shuijingwanwq.com/wp-content/plugins -maxdepth 2 \( -type d -o -type f \) -iname "*sly*" -print
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun 'bash -lc "type -P wp"'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
sed -n "1,80p" /data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/slytranslate/slytranslate.php
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun 'printf %s PD9waHAKbXlzcWxpX3JlcG9ydChNWVNRTElfUkVQT1JUX09GRik7CiRjb25maWcgPSBmaWxlX2dldF9jb250ZW50cygiL2RhdGEvd3d3cm9vdC93d3cuc2h1aWppbmd3YW53cS5jb20vd3AtY29uZmlnLnBocCIpOwpmdW5jdGlvbiB3cF9jb25zdF92YWx1ZSgkY29uZmlnLCAkbmFtZSkgewogICAgJHBhdHRlcm4gPSAiL2RlZmluZVxcKFxccypbJ1wiXSIgLiBwcmVnX3F1b3RlKCRuYW1lLCAiLyIpIC4gIlsnXCJdXFxzKixcXHMqWydcIl0oW14nXCJdKilbJ1wiXVxccypcXCkvIjsKICAgIHJldHVybiBwcmVnX21hdGNoKCRwYXR0ZXJuLCAkY29uZmlnLCAkbWF0Y2gpID8gJG1hdGNoWzFdIDogIiI7Cn0KaWYgKCFwcmVnX21hdGNoKCIvXFwkdGFibGVfcHJlZml4XFxzKj1cXHMqWydcIl0oW0EtWmEtejAtOV9dKylbJ1wiXVxccyo7LyIsICRjb25maWcsICRwcmVmaXhfbWF0Y2gpKSB7CiAgICBmd3JpdGUoU1RERVJSLCAiVEFCTEVfUFJFRklYX05PVF9GT1VORFxuIik7CiAgICBleGl0KDIpOwp9CiRwcmVmaXggPSAkcHJlZml4X21hdGNoWzFdOwokZGIgPSBuZXcgbXlzcWxpKAogICAgd3BfY29uc3RfdmFsdWUoJGNvbmZpZywgIkRCX0hPU1QiKSwKICAgIHdwX2NvbnN0X3ZhbHVlKCRjb25maWcsICJEQl9VU0VSIiksCiAgICB3cF9jb25zdF92YWx1ZSgkY29uZmlnLCAiREJfUEFTU1dPUkQiKSwKICAgIHdwX2NvbnN0X3ZhbHVlKCRjb25maWcsICJEQl9OQU1FIikKKTsKaWYgKCRkYi0+Y29ubmVjdF9lcnJubykgewogICAgZndyaXRlKFNUREVSUiwgIkRBVEFCQVNFX1JFQURfRkFJTEVEXG4iKTsKICAgIGV4aXQoMyk7Cn0KJHBsdWdpbiA9ICJzbHl0cmFuc2xhdGUvc2x5dHJhbnNsYXRlLnBocCI7CiRzdGF0dXMgPSAiaW5hY3RpdmUiOwokcmVzdWx0ID0gJGRiLT5xdWVyeSgiU0VMRUNUIG9wdGlvbl92YWx1ZSBGUk9NIGAiIC4gJHByZWZpeCAuICJvcHRpb25zYCBXSEVSRSBvcHRpb25fbmFtZT0nYWN0aXZlX3BsdWdpbnMnIExJTUlUIDEiKTsKaWYgKCRyZXN1bHQgJiYgKCRyb3cgPSAkcmVzdWx0LT5mZXRjaF9hc3NvYygpKSkgewogICAgJGFjdGl2ZSA9IEB1bnNlcmlhbGl6ZSgkcm93WyJvcHRpb25fdmFsdWUiXSk7CiAgICBpZiAoaXNfYXJyYXkoJGFjdGl2ZSkgJiYgaW5fYXJyYXkoJHBsdWdpbiwgJGFjdGl2ZSwgdHJ1ZSkpIHsKICAgICAgICAkc3RhdHVzID0gImFjdGl2ZSI7CiAgICB9Cn0KJG5ldHdvcmsgPSAkZGItPnF1ZXJ5KCJTRUxFQ1QgbWV0YV92YWx1ZSBGUk9NIGAiIC4gJHByZWZpeCAuICJzaXRlbWV0YWAgV0hFUkUgbWV0YV9rZXk9J2FjdGl2ZV9zaXRld2lkZV9wbHVnaW5zJyBMSU1JVCAxIik7CmlmICgkbmV0d29yayAmJiAoJHJvdyA9ICRuZXR3b3JrLT5mZXRjaF9hc3NvYygpKSkgewogICAgJHNpdGV3aWRlID0gQHVuc2VyaWFsaXplKCRyb3dbIm1ldGFfdmFsdWUiXSk7CiAgICBpZiAoaXNfYXJyYXkoJHNpdGV3aWRlKSAmJiBhcnJheV9rZXlfZXhpc3RzKCRwbHVnaW4sICRzaXRld2lkZSkpIHsKICAgICAgICAkc3RhdHVzID0gIm5ldHdvcmstYWN0aXZlIjsKICAgIH0KfQplY2hvICRzdGF0dXMsIFBIUF9FT0w7Cg== | base64 -d | php'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
ls -1 /www/server/php/*/bin/php /usr/local/bin/php /usr/bin/php 2>/dev/null
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
ls -1 /www/server/mysql/bin/mysql /usr/local/bin/mysql /usr/bin/mysql 2>/dev/null
'

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
docker ps --format "{{.Names}}\t{{.Image}}\t{{.Mounts}}"
'

scp -r -o BatchMode=yes -o ConnectTimeout=10 aliyun:/data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/slytranslate/. sources/slytranslate-installed/

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
if [ -f /data/wwwroot/www.shuijingwanwq.com/wp-content/mu-plugins/swq-glm52-translation-tuning.php ]; then
    echo MU_PLUGIN_EXISTS
else
    echo MU_PLUGIN_NOT_FOUND
fi
'

scp -p -o BatchMode=yes -o ConnectTimeout=10 aliyun:/data/wwwroot/www.shuijingwanwq.com/wp-content/mu-plugins/swq-glm52-translation-tuning.php sources/mu-plugins/swq-glm52-translation-tuning.php

du -sb sources/slytranslate-installed
find sources/slytranslate-installed -type f -name '*.php' -print
find sources/slytranslate-installed -type f -printf '%P\0' | LC_ALL=C sort -z | while IFS= read -r -d '' f; do sha256sum "sources/slytranslate-installed/$f"; done | sha256sum
stat -c '%s %n' sources/mu-plugins/swq-glm52-translation-tuning.php
sha256sum sources/mu-plugins/swq-glm52-translation-tuning.php
date -Iseconds

ssh -o BatchMode=yes -o ConnectTimeout=10 aliyun '
cd /data/wwwroot/www.shuijingwanwq.com/wp-content/plugins/slytranslate
find . -type f -printf "%P\0" | LC_ALL=C sort -z | while IFS= read -r -d "" f; do sha256sum "$f"; done | sha256sum
sha256sum /data/wwwroot/www.shuijingwanwq.com/wp-content/mu-plugins/swq-glm52-translation-tuning.php
'

# 工作目录：sources/slytranslate-installed
find . -type f -printf '%P\0' | LC_ALL=C sort -z | while IFS= read -r -d '' f; do sha256sum "$f"; done | sha256sum

date -Iseconds

# 工作目录：sources/slytranslate-installed（首次最终检查误用了该工作目录；仅为本地只读检查）
for d in sources sources/slytranslate-installed sources/mu-plugins evidence analysis patch tmp; do test -d "$d" && echo "DIR_OK $d" || echo "DIR_MISSING $d"; done
php_count=$(find sources/slytranslate-installed -type f -name '*.php' | wc -l)
echo "PHP_COUNT $php_count"
test -f sources/mu-plugins/swq-glm52-translation-tuning.php && echo "MU_FILE_OK" || echo "MU_FILE_MISSING"
test -f evidence/installed-sources.md && echo "EVIDENCE_OK" || echo "EVIDENCE_MISSING"
find . -type f -printf '%P\0' | LC_ALL=C sort -z | while IFS= read -r -d '' f; do sha256sum "$f"; done | sha256sum
sha256sum ../mu-plugins/swq-glm52-translation-tuning.php

# 工作目录：工作区根目录（正确的最终存在性检查）
for d in sources sources/slytranslate-installed sources/mu-plugins evidence analysis patch tmp; do test -d "$d" && echo "DIR_OK $d" || echo "DIR_MISSING $d"; done
php_count=$(find sources/slytranslate-installed -type f -name '*.php' | wc -l)
echo "PHP_COUNT $php_count"
test -f sources/mu-plugins/swq-glm52-translation-tuning.php && echo "MU_FILE_OK" || echo "MU_FILE_MISSING"
test -f evidence/installed-sources.md && echo "EVIDENCE_OK" || echo "EVIDENCE_MISSING"
git status --short
```

其中首次 `wp plugin list`、登录 shell 的 `wp` 查找、PHP/MySQL 固定路径检查、只读 PHP 状态查询及容器列表命令均因对应命令不存在而未能提供插件状态；这些失败命令未对服务器产生写操作。
