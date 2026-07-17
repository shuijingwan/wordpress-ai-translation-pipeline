# PublishPress Series 部分编号与顺序审计

## 范围与结论

本报告只分析生产服务器实际安装并复制到本地的 `PublishPress Series Free 3.1.2`、SlyTranslate 1.9.0 与当前 GLM 5.2 MU Plugin。插件身份见 `sources/publishpress-series-installed/orgSeries.php:3-10`；类 `N/A（插件引导文件）`；方法 `N/A（插件头）`。

- [源码直接证明] `series` taxonomy relationship 与系列部分编号是两套数据：relationship 只表示文章属于哪个系列；部分编号存于按系列 ID 区分的 post meta。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,553-583`；类 `N/A（过程式函数）`；函数 `set_series_order()`、`wp_set_post_series()`。
- [源码直接证明] 对 `series_ID=36385`，部分编号 key 是 `_series_part_36385`，不是 `wp_term_relationships.term_order`。路径 `sources/publishpress-series-installed/orgSeries.php:121-130` 与 `sources/publishpress-series-installed/orgSeries-setup.php:80-91`；类 `orgSeries`；方法 `__construct()`、`part_key()`。
- [源码直接证明] SlyTranslate 创建新译文时先执行 draft → 请求目标状态，再写 taxonomy；若目标状态是 `publish`，PublishPress 的 `draft_to_publish` 自动编号 Hook 触发时，译文尚无 series relationship。Sly 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-183,192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。Hook 路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:409-417,814-815`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`。
- [源码直接证明] 后续单独调用 `wp_set_object_terms()` 不会触发 PublishPress 的编号写入，因为 3.1.2 没有注册 `set_object_terms`、`added_term_relationship` 或 REST 保存完成回调。Sly 调用路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。PublishPress 相关 Hook 注册路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:811-815`；类 `N/A（Hook 注册区）`；回调见该行。
- [源码直接证明] SlyTranslate 没有 PublishPress Series 专用顺序复制或生命周期兼容代码；实际相关持久化只有 generic taxonomy loop。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [合理推断，高置信度] 当前英文文章“已属于系列但无部分编号”的直接代码路径是：发布转换发生过早，自动编号查不到 series；之后只补 relationship，没有第二次编号机会。依据路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`；以及 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-450,811-815`；类 `N/A`；函数 `wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`、`wp_set_post_series()`。
- [结论边界] “SlyTranslate 没有复制系列顺序”是已确认的源码行为；“没有复制中文编号是当前异常的唯一原因”不是已确认结论。历史 AutoPoly 手工发布流程很可能依靠 PublishPress 的 draft → publish 自动追加，而不是复制中文编号。

## 1. “当前部分”列的来源

1. [源码直接证明] `page=manage-issues` 由 `PPS_Publisher_Admin::admin_menu()` 注册，回调为 `series_issue_manager_admin()`。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:495-508`；类 `PPS_Publisher_Admin`；方法 `admin_menu()`。
2. [源码直接证明] `action=part` 时，`screen_option()` 加载并实例化 `PPS_Publisher_Post_Part_Table`。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:523-541`；类 `PPS_Publisher_Admin`；方法 `screen_option()`。
3. [源码直接证明] `series_issue_manager_admin()` 在 `part` 分支调用 `ppseries_publisher_part_output()`，后者调用表格的 `prepare_items()` 和 `display()`。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:544-570,784-815`；类 `PPS_Publisher_Admin`；方法 `series_issue_manager_admin()`、`ppseries_publisher_part_output()`。
4. [源码直接证明] “Current Part”列定义在 `get_columns()`；列内容由 `column_part()` 输出。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:58-70,302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_columns()`、`column_part()`。
5. [源码直接证明] “(Currently has no Part number)”的准确条件是 `empty(trim($series_part))`。`$series_part` 来自 `get_post_meta($item->ID, $part_key, true)`。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。

因此，meta 不存在、空字符串，以及会被 PHP `empty()` 判空的值（包括字符串 `"0"`）都会显示“当前没有部分编号”。

## 2. 部分编号的数据模型

1. [源码直接证明] 基础 key 常量是 `_series_part`。路径 `sources/publishpress-series-installed/orgSeries.php:121-130`；类 `N/A（插件引导文件）`；方法 `N/A（常量定义）`。
2. [源码直接证明] `orgSeries::__construct()` 注册 `orgseries_part_key` 过滤器；`orgSeries::part_key()` 把 `_<series_id>` 附加到基础 key。路径 `sources/publishpress-series-installed/orgSeries-setup.php:18-84,87-91`；类 `orgSeries`；方法 `__construct()`、`part_key()`。
3. [源码直接证明] `wp_series_part()` 用最终 key 调用 `get_post_meta(..., true)`。路径 `sources/publishpress-series-installed/orgSeries-template-tags.php:335-358`；类 `N/A（过程式函数）`；函数 `wp_series_part()`。
4. [源码直接证明] `set_series_order()` 用 `delete_post_meta()` 后接 `add_post_meta()` 保存编号。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-162`；类 `N/A（过程式函数）`；函数 `set_series_order()`。

实际保存位置结论：

| 候选位置 | 结论 | 源码依据 |
|---|---|---|
| `wp_postmeta` | **是**，key 为 `_series_part_<series term ID>` | 路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A`；函数 `set_series_order()` |
| `wp_term_relationships.term_order` | **否** | 路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:553-583`；类 `N/A`；函数 `wp_set_post_series()`；其中 `wp_set_object_terms()` 与 `set_series_order()` 分开调用 |
| post `menu_order` | **否，非权威源** | 路径 `sources/publishpress-series-installed/inc/settings/settings-upgrade.php:100-155`；类 `N/A`；函数 `publishpress_series_sync_menu_order()`；它从 part meta 反向同步 `menu_order` |
| term meta | **否** | 读取路径 `sources/publishpress-series-installed/orgSeries-template-tags.php:335-358`；类 `N/A`；函数 `wp_series_part()`；写入路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A`；函数 `set_series_order()` |
| option | **否** | 路径 `sources/publishpress-series-installed/orgSeries-setup.php:297-355`；类 `orgSeries`；方法 `add_settings()`；option 存插件设置，不存文章 part |
| 自定义表 | **否** | 路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-162`；类 `N/A`；函数 `set_series_order()`；实际编号函数只操作 post meta |

[源码直接证明] `orgSeries-manage.php` 中的 `term_order` 是写入 `$wpdb->terms.term_order` 的“系列 term 之间的顺序”，不是系列内文章部分编号。路径 `sources/publishpress-series-installed/orgSeries-manage.php:147-190`；类 `N/A（过程式函数）`；函数 `wp_insert_series()`、`wp_update_series()`。

## 3. 编号如何写入、自动追加和更新

### 3.1 核心写入函数

- [源码直接证明] `set_series_order($series_id, $postid, $series_part, $is_published)` 是核心写入函数。显式非零编号直接写入；编号强制转换为 `0` 时，仅在 `$is_published` 为真时自动计算。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A（过程式函数）`；函数 `set_series_order()`。
- [源码直接证明] 自动计算读取该系列其他文章的有序数组，取最后一个 part 加 `1`；没有其他编号文章则写 `1`。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:136-148`；类 `N/A（过程式函数）`；函数 `set_series_order()`。
- [源码直接证明] 如果当前文章已经有非空 part，自动计算后又恢复原 part，因此重复触发不会把已有编号移动到末尾。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:142-152`；类 `N/A（过程式函数）`；函数 `set_series_order()`。
- [源码直接证明] 未发布且请求自动编号时直接返回 `false`。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:153-156`；类 `N/A（过程式函数）`；函数 `set_series_order()`。

### 3.2 状态转换自动编号

- [源码直接证明] 插件注册的相关 Hook 是：`save_post`、`future_to_publish`、`draft_to_publish`、`pending_to_publish`，优先级均为 `10`。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:811-815`；类 `N/A（Hook 注册区）`；回调 `series_wp_save_post()`、`wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`。
- [源码直接证明] 三个 `*_to_publish` 回调先通过 `wp_get_post_series($post_ID)` 取得**转换当时已经存在**的 series relationship，再对每个 series 调用 `set_series_order(..., 0, true)` 自动追加。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-417`；类 `N/A（过程式函数）`；函数 `wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`。
- [源码直接证明] 插件没有注册 `wp_after_insert_post`、通用 `transition_post_status`、`publish_post`、`set_object_terms`、`added_term_relationship` 或 REST 保存完成 Hook；全插件 PHP Hook 搜索中，编号生命周期注册项就是上述 `orgSeries-taxonomy.php:811-815`。类 `N/A`；方法/函数见上一项。

### 3.3 后台编辑器保存编号

- [源码直接证明] 后台 metabox 发送 `post_series`、`series_part[<series id>]` 和隐藏字段 `is_series_save=1`。路径 `sources/publishpress-series-installed/orgSeries-admin.php:356-381,398-450`；类 `N/A（过程式函数）`；函数 `write_series_list()`、`series_edit_meta_box()`。
- [源码直接证明] `save_post` 回调最终进入 `wp_set_post_series()`；若请求缺 `series_part` 或 `post_series`，它在第一个条件直接返回；若缺 `is_series_save`，也返回。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:419-450`；类 `N/A（过程式函数）`；函数 `series_wp_save_post()`、`wp_set_post_series()`。
- [源码直接证明] 通过后台请求时，该函数先调用 `wp_set_object_terms()`，再调用 `set_series_order()`；这是关系与编号必须分别处理的直接证据。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:553-583`；类 `N/A（过程式函数）`；函数 `wp_set_post_series()`。

### 3.4 系列管理页拖动更新

- [源码直接证明] 管理页“Update Order”提交 `action=order` 和拖动后的文章 ID 顺序。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:829-845`；类 `PPS_Publisher_Admin`；方法 `ppseries_publisher_part_output()`。
- [源码直接证明] `series_issue_manager_part()` 先把提交文章的 part meta 清空，再按提交顺序逐个调用 `publisher_wp_set_post_series()`；后者调用核心 `set_series_order()`。路径 `sources/publishpress-series-installed/addons/publisher/series_issue_manager.php:7-44,148-172`；类 `N/A（过程式函数）`；函数 `series_issue_manager_part()`、`publisher_wp_set_post_series()`。

## 4. 查询与未编号文章为何排第一

### 4.1 通用系列顺序

- [源码直接证明] `get_series_order()` 逐篇读取 `_series_part_<series id>`；meta 不存在时，WordPress `get_post_meta(..., true)` 返回空字符串；随后用 `_usort_series_by_part()` 升序比较 `part`。路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-19,27-74`；类 `N/A（过程式函数）`；函数 `_usort_series_by_part()`、`get_series_order()`。
- [源码直接证明] 插件默认归档排序设置是 `series_posts_orderby=meta_value`、`series_posts_order=ASC`。路径 `sources/publishpress-series-installed/orgSeries-setup.php:297-355`；类 `orgSeries`；方法 `add_settings()`。
- [源码直接证明] series 页把 meta 值转为数值 `meta_value + 0` 并按设置方向排序；默认是升序。路径 `sources/publishpress-series-installed/orgSeries-setup.php:570-620`；类 `orgSeries`；方法 `sort_series_page_join()`、`sort_series_page_where()`、`sort_series_page_orderby()`。
- [源码直接证明] 设置 UI 允许改成按 `post_date`，也允许 ASC/DESC；因此前台具体实例还受生产设置影响。路径 `sources/publishpress-series-installed/inc/settings/tab-legacy.php:97-107`；类 `N/A（设置模板）`；方法 `N/A（模板输出）`。

### 4.2 当前后台 `action=part` 列表

- [源码直接证明] 后台表先用 series taxonomy 查询已发布文章，然后建立同时覆盖 part meta 存在和不存在的 `meta_query`，以 part 相关子句 ASC 排序。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:72-110`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。
- [源码直接证明] 该查询没有声明 `post_date`、ID 或创建时间作为次级排序字段。路径同上；类与方法同上。
- [合理推断，高置信度] 缺失/空 part 在插件的升序 part 语义中相当于最小值，位于编号 `1` 之前；这与后台“未编号英文文章第一项、后续为 1、2”的观察完全一致。依据路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-19,63-71`；类 `N/A`；函数 `_usort_series_by_part()`、`get_series_order()`。后台依据路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:93-107`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。
- [尚未确认] 若同一系列有多篇未编号文章，它们之间的稳定次序无法由当前管理表源码确认，因为没有显式 secondary order。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:93-107`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。

结论：当前“第一项”无需用 Object Cache 解释；缺失 part meta 加升序排序已经能够解释。前台若生产设置改成按日期，则需读取实际设置才能断言前台顺序，但题述后台页由上面的管理表代码控制。

## 5. SlyTranslate → PublishPress 完整时序

### 5.1 实际 GLM/SlyTranslate 新建译文路径

1. [源码直接证明] GLM MU Plugin 把请求 `post_status` 交给 `ClientTranslationWorkflowService::prepare_single()`。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:248-290`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
2. [源码直接证明] prepare 只读取信息、构造 units 和规范化目标状态，不创建译文文章。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:44-84`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `prepare_single()`。
3. [源码直接证明] MU 把 prepare 返回的状态原样放入 apply 输入。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
4. [源码直接证明] `apply_single()` 把状态和翻译数据交给 adapter。路径 `sources/slytranslate-installed/inc/ClientTranslationWorkflowService.php:92-174`；类 `SlyTranslate\ClientTranslationWorkflowService`；方法 `apply_single()`。
5. [源码直接证明] `PolylangAdapter::create_translation()` 先以 `draft` 创建占位文章。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-153`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
6. [源码直接证明] 它设置目标语言后，马上通过 `wp_update_post()` 写标题、正文、摘要和请求目标状态；若目标状态为 `publish`，此处发生 draft → publish。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:154-183`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
7. [源码直接证明] meta 写入在状态更新之后。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:185-190`；类与方法同上。
8. [源码直接证明] 所有 taxonomy（包括 series）在状态更新之后才通过 generic loop 和 `wp_set_object_terms()` 写入。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类与方法同上。
9. [源码直接证明] Polylang 翻译关系更晚，在 taxonomy 后建立。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:222-227`；类与方法同上。
10. [源码直接证明] MU 在 apply 后用 `$wpdb->update()` 二次写正文，仅调用 `clean_post_cache()`；该 raw 写入不会产生新的 draft → publish 转换，也不会补 series part。路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:457-476,491-584`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`、`persist_exact_post_content()`。

### 5.2 PublishPress 在该时序中的反应

- [源码直接证明] 第 6 步 draft → publish 时，PublishPress 的 `wp_set_post_series_draft_transition()` 会运行，但它只能读取当时已存在的 relationship。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:409-417,814-815`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`。
- [源码直接证明] SlyTranslate 到第 8 步才建立 relationship，因此新译文在转换 Hook 时不属于任何 series；循环为空，不写 part。Sly 路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:161-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。PublishPress 路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:409-417`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`。
- [源码直接证明] 第 8 步的 `wp_set_object_terms()` 只建立 relationship；PublishPress 没有关系新增 Hook，因而不会自动补 part。Sly 调用路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。PublishPress Hook 注册路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:811-815`；类 `N/A`；回调如上。
- [源码直接证明] 同一次 `wp_update_post()` 产生的 `save_post` 也不能补 part，因为 SlyTranslate 请求没有 PublishPress metabox 的 `series_part`、`post_series`、`is_series_save` 字段，`wp_set_post_series()` 会提前返回。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:419-450`；类 `N/A`；函数 `series_wp_save_post()`、`wp_set_post_series()`。
- [合理推断，高置信度] 这条链准确产生“英文文章已经在 series taxonomy 中，但 `_series_part_<target series ID>` 不存在”的最终状态。依据路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:135-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`；以及 `sources/publishpress-series-installed/orgSeries-taxonomy.php:398-450,811-815`；类 `N/A`；函数 `wp_set_post_series_draft_transition()`、`wp_set_post_series()`。

若 apply 目标状态仍是 draft，series relationship 会先写好；以后真正发生 draft → publish 时，PublishPress 自动编号 Hook 就具备条件。问题不是“程序化调用永远不能自动编号”，而是**关系与状态转换的先后顺序**。

## 6. SlyTranslate 是否复制中文部分编号

- [源码直接证明] SlyTranslate taxonomy loop 读取源 term ID，按 Polylang 映射目标 term ID，再调用 `wp_set_object_terms()`；它只复制 series 归属。路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
- [源码直接证明] SlyTranslate 与 MU Plugin 的 PHP 源码中没有 `PublishPress`、`organize-series`、`SERIES_PART_KEY`、`_series_part`、`set_series_order()` 或 `wp_series_part()` 专用兼容调用。对应实际持久化入口仍是上述 generic taxonomy loop；类/方法同上。
- [源码直接证明] 当前 GLM/client apply 只合并 `meta:*` translation units；units 只来自配置为 translate 的简单字符串 meta。路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:524-562,574-586`；类 `SlyTranslate\MetaTranslationService`；方法 `build_meta_units()`、`merge_translated_meta_units()`。
- [源码直接证明] 当前 GLM path 没有把 PublishPress part meta 作为 series 顺序数据读取、把源 series ID remap 到目标 series ID，或写入 `_series_part_<target series ID>`。调用路径 `sources/mu-plugins/swq-glm52-translation-tuning.php:280-290,426-437`；类 `SWQ_GLM52_Translation_Tuning`；方法 `run_full_article_job()`。
- [源码直接证明] 原生 server-side 元数据流程会复制大多数未跳过 meta，但也没有 PublishPress-aware 的 key 后缀 remap。路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:91-190,461-476`；类 `SlyTranslate\MetaTranslationService`；方法 `prepare_translation_meta()`、`should_skip_meta_key()`。
- [合理推断] 即便原样复制 `_series_part_<中文 series ID>`，当 Polylang 把 series term 映射成不同的英文 term ID 时，PublishPress 读取的是 `_series_part_<英文 series ID>`，原样 key 仍不能成为英文 series 的部分编号。term 映射证据位于 `PolylangAdapter.php:204-218`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。

所以，“SlyTranslate 没有复制系列顺序”是已确认的源码行为；更准确地说，它没有执行“读源 series 的 part → 映射目标 series → 写目标 series part”的兼容步骤。当前异常还同时暴露了自动编号生命周期被错序绕过。

## 7. AutoPoly 历史行为的解释

已知历史步骤是：按中文文章顺序逐篇生成英文文章，进入英文编辑器，再由用户手动发布；最终英文系列顺序与中文一致。

- [源码直接证明] PublishPress 3.1.2 能在 draft/pending/future → publish 时自动把已属于系列、尚无编号的文章追加到该系列末尾。路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,398-417,811-815`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series_transition()`、`wp_set_post_series_draft_transition()`。
- [源码直接证明] 后台编辑器 metabox 还可以在普通保存请求中显式提交 series 与 part，并在建立 relationship 后调用 `set_series_order()`。路径 `sources/publishpress-series-installed/orgSeries-admin.php:356-381,398-450` 与 `orgSeries-taxonomy.php:419-450,553-583`；类 `N/A`；函数 `write_series_list()`、`series_edit_meta_box()`、`series_wp_save_post()`、`wp_set_post_series()`。
- [合理推断，高置信度] 如果 AutoPoly 创建的英文 draft 在用户点击发布前已经带有英文 series relationship，那么手工发布满足 `draft_to_publish` Hook 条件，插件会按当时英文系列末尾自动分配 `1`、`2`、`3`。按中文顺序逐篇操作就会得到相同顺序，无需复制中文 part 值。依据路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,409-417,814-815`；类 `N/A`；函数 `set_series_order()`、`wp_set_post_series_draft_transition()`。
- [合理推断] 已有英文文章显示 part `1`、`2` 可以由该自动追加机制解释；它也排除了“所有旧英文文章都无编号、仅按发布日期排序”作为对这些已编号文章的解释。依据路径同上；类与函数同上。
- [尚未确认] 当前本地源码没有 AutoPoly 实际版本源码、旧请求记录或旧文章逐条写入历史，无法直接证明每个旧编号确由哪个回调写入。路径 `N/A（AutoPoly 源码与历史事件缺失）`；类 `N/A`；方法 `N/A`。

结论：历史行为不是“必须复制中文 part”的证据，反而强烈支持“人工发布触发了 PublishPress 自动追加，而当前 SlyTranslate 在错误时点发布后才加入 series”。

## 8. 两种目标语义

### A. 精确复制中文现有编号

[分析设计] 读取中文文章在中文 series ID 下的 part，然后在英文 relationship 已建立后，把同一数值通过 PublishPress 核心函数写入英文 series ID 对应的 key。优点是允许乱序翻译、补译，并精确对齐中英文编号；风险是英文系列缺文章、已有冲突编号或两种语言系列结构不一时，需要明确冲突策略。

### B. 模拟人工发布，自动追加英文系列末尾

[分析设计] 让英文文章保持 draft，先建立英文 series relationship，最后用标准状态转换发布；PublishPress 自己的 `draft_to_publish` Hook 会调用官方核心机制自动追加。它最接近 AutoPoly 历史行为、对插件内部存储耦合最低；但只有在翻译顺序等于期望英文顺序时，结果才与中文一致。乱序补译会追加到末尾，而不是复制中文槽位。

两种目标都必须分别识别中文 series term 与英文 series term。A 从中文 key 读取、向英文 key 写入；B 只应在英文系列内求“当前末尾”。不应改写中文系列的 part。

## 9. 十三个问题的直接回答

1. 后台列：`series-part-post-table.php` 的 `PPS_Publisher_Post_Part_Table::column_part()`；路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`。
2. 无编号条件：`empty(trim(get_post_meta(..., true)))`；路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。
3. 保存位置：`wp_postmeta`，key `_series_part_<series term ID>`；路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160`；类 `N/A`；函数 `set_series_order()`。
4. 写入/更新：核心 `set_series_order()`；状态转换、后台 metabox 保存、管理页拖动都可调用它；路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:128-160,398-450,553-583`；类 `N/A`；相关函数见 §§3.1–3.3。
5. 查询排序：核心辅助按 part 升序；series 页默认 `meta_value + 0 ASC`；后台管理表按 part/缺失 meta 子句升序。路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-74`；类 `N/A`；函数 `_usort_series_by_part()`、`get_series_order()`。路径 `sources/publishpress-series-installed/orgSeries-setup.php:570-620`；类 `orgSeries`；方法 `sort_series_page_orderby()`。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:72-110`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。
6. 未设置值：meta row 不存在或值为空；UI 也把 `0` 判作无编号；路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。
7. 排第一：缺失/空 part 在升序 part 语义中位于 `1` 之前；后台查询没有显式日期/ID次序。路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-74`；类 `N/A`；函数 `_usort_series_by_part()`、`get_series_order()`。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:93-107`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。
8. Sly 的 `wp_set_object_terms()`：只建立目标 series term relationship；路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
9. `wp_set_object_terms()`：不会自动写 PublishPress part；插件自身也把关系写入与 `set_series_order()` 分开执行；路径 `sources/publishpress-series-installed/orgSeries-taxonomy.php:553-583`；类 `N/A`；函数 `wp_set_post_series()`。
10. Sly 专用兼容：没有；实际持久化路径 `sources/slytranslate-installed/inc/PolylangAdapter.php:192-220`；类 `SlyTranslate\PolylangAdapter`；方法 `create_translation()`。
11. 中文 part 是否复制：当前 GLM/Sly path 没有 remap 并写入英文 series part；路径 `sources/slytranslate-installed/inc/MetaTranslationService.php:524-586`；类 `SlyTranslate\MetaTranslationService`；方法 `build_meta_units()`、`merge_translated_meta_units()`。
12. 缺编号能否解释第一项：能，足以解释当前后台观察；多篇未编号间的次序未确认。路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:72-110`；类 `PPS_Publisher_Post_Part_Table`；方法 `get_table_data()`。路径 `sources/publishpress-series-installed/orgSeries-utility.php:12-74`；类 `N/A`；函数 `_usort_series_by_part()`、`get_series_order()`。
13. 是否独立于 W3TC Object Cache：是。Object Cache 可影响 relationship 的读取，但不能创建缺失的 `_series_part_<id>`；当前后台显示无编号和 part 升序都有独立源码解释。读取路径 `sources/publishpress-series-installed/addons/publisher/series-part-post-table.php:302-315`；类 `PPS_Publisher_Post_Part_Table`；方法 `column_part()`。

## 10. 尚未确认事项

- 生产 `org_series_options` 当前是否仍为默认 `meta_value ASC`；本阶段禁止数据库访问，未读取。路径 `N/A（生产 option 未读取）`；类 `N/A`；方法 `N/A`。
- [尚未确认] 文章 `19512` 的 `_series_part_36385` 是 meta row 不存在、空字符串还是 `0`；后台 UI 对三者显示相同，本阶段未访问数据库。路径 `N/A（生产 post meta 未读取）`；类 `N/A`；方法 `N/A`。
- [尚未确认] AutoPoly 旧版本的具体保存调用；但 PublishPress 3.1.2 当前源码已经证明人工 draft → publish 所需的自动追加机制存在。路径 `N/A（AutoPoly 源码未提供）`；类 `N/A`；方法 `N/A`。
