# Series 部分编号后台观察

## 已确认的后台现象

- 系列名称：`Blog SEO Log`。
- 系列管理页 URL 参数包含 `page=manage-issues`、`action=part`、`series_ID=36385`。
- 英文翻译文章已经属于该 `series` taxonomy，并出现在系列管理列表中。
- 该英文文章的“当前部分”显示“（当前没有部分编号）”。
- 列表内其他文章已有部分编号 `1`、`2` 等。
- 未编号的英文文章显示在列表第一项。

## 证据含义

- [后台现象直接证明] “属于系列”与“拥有系列部分编号”是两个不同的数据状态：前者已经成立，后者没有成立。
- [源码直接证明] PublishPress Series 3.1.2 的管理表先用 `series` taxonomy 查询文章，再单独读取按系列区分的 post meta 作为“当前部分”。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:72-110,302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`、`column_part()`。
- [源码直接证明] 对系列 `36385`，实际部分编号 meta key 由 `_series_part` 加系列 ID 构成，即 `_series_part_36385`。常量路径 `sources/publishpress-series-installed/orgSeries.php:121-130`；类 `N/A（插件引导文件）`；方法 `N/A（常量定义）`。后缀过滤器路径 `sources/publishpress-series-installed/orgSeries-setup.php:80-91`；类 `orgSeries`；方法 `__construct()`、`part_key()`。
- [结论边界] 该观察不说明 taxonomy relationship 错误，也不能单独说明 Object Cache 状态；它直接指出的是目标文章缺少独立的 PublishPress Series 部分编号数据。

