# Paymos CS-Cart 支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 CS-Cart、Multi-Vendor 与 Ultimate 稳定币支付插件。它会注册一个名为 Paymos 的支付处理器：买家下单后跳转至 Paymos 托管收银台，从个人钱包使用 USDT/USDC 等稳定币付款；链上转账确认后，订单状态由经过 HMAC-SHA256 签名验证的 webhook 自动更新。

[![观看视频：12 款官方 CMS 稳定币支付插件 — Paymos 一键连接](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，内置 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- CS-Cart、Multi-Vendor 或 Ultimate 4.x 系列。`addon.xml` 声明的插件架构版本为 `3.0`，并在 Multi-Vendor 和 Ultimate 版本上自动安装
- PHP 7.4 及以上版本，需启用 `curl`、`hash`、`json` 和 `openssl` 扩展——这也是内置 Paymos PHP SDK 要求的最低环境
- 通过 HTTPS 提供服务的独立站店面；一键连接流程会拒绝非 HTTPS 地址
- 已配置 CS-Cart 的 `crypt_key`，因为存储的凭证使用它派生加密密钥
- 一个 Paymos 账户，且已在控制台选中用于该店面收款的项目

## 安装

从 [Releases](https://github.com/Paymos-labs/cscart/releases/latest) 或 Paymos 控制台的 **CMS integration（CMS 集成）** 面板下载 `paymos-cscart-<version>.zip`，并在后台 **Add-ons → Manage add-ons → + → Local** 上传安装。

压缩包会解压出三棵目录树，三者缺一不可：

- `app/addons/paymos` —— 插件本体，内部已自带 Paymos PHP SDK
- `design/backend/…` —— 支付处理器后台配置模板
- `var/langs/…` —— 语言包目录。CS-Cart 始终从**网店根目录**解析插件的 `.po` 语言文件，而不是从插件目录内读取；如果上传时丢失了 `var/`，所有后台标签都会显示为原始键名

安装过程会创建 `paymos_config`、`paymos_invoices` 和 `paymos_events` 三张表，并在支付处理器表中写入 `Paymos`。压缩包对所有商户完全一致，不含任何 API 密钥、API secret、项目 ID、webhook secret、OAuth token 或 device code。

## 一键连接

1. 开始前先在 Paymos 控制台打开目标项目。插件会自动绑定该当前项目，不会再次让你选择。
2. 在后台进入 **Administration → Payment methods**，新增或编辑支付方式，将其处理器（processor）设为 **Paymos**。
3. 在处理器设置页点击 **Connect Paymos**，并在弹出的标签页中批准显示的店面 URL 和项目。若浏览器拦截了该标签页，授权链接和验证码会直接显示在按钮旁边。
4. 保存该支付方式。

一次批准会同时打通 Sandbox 和 Live 两套环境。系统会复用你唯一的活跃 Payment 密钥（若尚不存在则自动创建），并在以下地址注册 Invoice webhook：

```text
https://your-store.example/index.php?dispatch=payment_notification.notify&payment=paymos&mode=webhook
```

`mode=webhook` 是地址的必需组成部分而非装饰：插件依靠它区分 Paymos 回调与普通支付通知分发，因为 CS-Cart 自身的 `$mode` 在该路由上值为 `notify`。仅当回调 URL、类别和项目全部一致时才会复用现有 webhook。

凭证经由店铺 `crypt_key` 派生密钥，以 AES-256-GCM 封装写入 `paymos_config`，绝不会回显到后台管理页面。

## 订单状态

每种支付状态都映射到你在处理器设置中选定的 CS-Cart 状态码，默认使用内置状态码：

| 处理器设置 | 默认值 | 生效时机 |
|---|---|---|
| Order status while awaiting payment | `O` — Open | 账单已创建且买家已跳转至托管收银台 |
| Order status while the payment is confirming on-chain | `O` — Open | `invoice.confirming` |
| Order status when paid | `P` — Processed | `invoice.paid`、`invoice.paid_over` |
| Order status when the payment is underpaid | `F` — Failed | `invoice.underpaid` |
| Order status when the invoice is cancelled or expired | `D` — Declined | `invoice.expired`、`invoice.cancelled` |

店铺定义的任意状态码均可使用，包括自定义状态。写入订单的交易 ID 为已确认转账的链上交易哈希；当回调载荷不包含转账记录时（Sandbox 始终如此），则回退写入 Paymos 账单 ID。

有两件事绝不会发生。第一，已经处于你所配置已付款状态——或处于商户常手动推进到的内置 `C`（Complete）状态——的订单，绝不会被迟到的 `confirming`、`underpaid`、`expired` 或 `cancelled` 事件回退：该事件会被跳过，若开启调试日志则会记入日志。第二，金额或币种与账单不再一致的订单绝不会被标记为已付款。

## Multi-Vendor 与 Ultimate 版本

这两个版本使用同一个插件且行为完全一致：作为店铺级支付处理器，其连接归属店面，具体设置归属各支付方式。同一店铺可以并行配置多个 Paymos 支付方式，分别指定不同模式或不同订单状态码。

所有收款均结算至已连接 Paymos 项目的商户余额。插件不提供分账支付或子商户账户功能，因此多商户订单收益在各供应商之间的拆分仍保留在 CS-Cart 自身的供应商账务体系内处理。

## 上线前测试

1. 在支付方式设置中将 **Mode** 设为 Sandbox，并在前台店面下一笔订单。
2. 保持 Paymos 控制台处于 Sandbox 模式，打开该账单，使用 **Pay Full**、**Pay 50%**、**Pay 150%** 或 **Cancel**。这些按钮会发出与真实付款相同的事件，不会触及任何区块链。
3. 检查订单状态以及订单上的支付信息区块。
4. 将 **Mode** 切换为 Live。两套环境凭证已随同一次批准生成，无需重新连接，也无需二次配置。

## Webhook 机制

回调以 `POST` 请求发往上述 dispatch 地址。`X-Webhook-Signature` 请求头是信任边界——对 `{timestamp}.{body}` 计算十六进制 HMAC-SHA256——签名无效或时间戳过期会在不加载订单的情况下直接返回 `401`。重复的 `event_id` 返回 `200` 且不做任何处理。在应用任何终态事件前，插件会从 Merchant API 回查账单，并与 `paymos_invoices` 中的快照核对：环境、项目、外部订单 ID 与账单 ID 必须全部一致。

账单状态更新不设本地对账定时任务，webhook 自动重投即账单恢复机制。一个重投周期包含约 16 小时内的 11 次尝试，足以覆盖店面短暂离线的情况。超出重投窗口的账单事件可在 Paymos 控制台手动重放。

## 退款

插件通过 Paymos Merchant API（`/v1/refunds`）支持两种原生退款模式：

- `full` —— 全额退还已付账单金额；
- `overpayment` —— 仅退还 `invoice.paid_over` 订单中多付的超额部分。

资金仅退回 Paymos 在账单付款时捕获并验证的 `first_sender` 钱包地址。不支持手动输入收款钱包地址，也不支持任意金额的部分退款。

在 CS-Cart 后台管理退款时，打开 `dispatch=paymos.refunds&order_id=<order_id>`（**Paymos refunds**，需要订单编辑权限）。页面会显示可退金额与经验证的 `first_sender` 地址，提供 **Refund in full**（`full`）和 **Refund overpayment**（`overpayment`）操作（`paymos.refund`），并支持手动查询进行中的退款（`paymos.refund_reconcile`）。处理中的退款也会通过带签名的 webhook 以及命令行定时任务脚本 `php app/addons/paymos/cron/refunds.php` 自动对账，仅在 Paymos 确认达到 `completed` 后才更新订单。Sandbox 模式支持模拟退款终态（`completed`、`failed`、`cancelled`）。

## 故障排查

**后台标签显示为原始键名**（例如 `paymos.mode`）。说明上传过程中 `var/langs` 目录树未被完整解压。请重新上传完整发布压缩包；语言目录位于网店根目录，而不是插件目录内。

**订单始终停留在等待付款状态。** 请检查已注册的回调地址中是否仍保留 `mode=webhook`。缺少该参数时，插件会将每次回调判定为无效回调模式并拒绝处理，服务端则会持续重试而订单无法完成。重新连接即可重新注册正确地址。

**一键连接拒绝启动。** 被批准并用于注册 webhook 的是店面 URL，该地址必须为 `https://`。修正店面 URL 后重新发起连接即可。

**修改配置后全部失效。** 凭证加密封装由 `crypt_key` 派生密钥。如果修改了 `crypt_key`，或将数据库恢复到了使用不同 `crypt_key` 的店铺中，原有封装将无法解密——再次点击 **Connect Paymos** 重新签发一份即可。

## 相关链接

- 文档：[paymos.io/zh-hans/docs/cms-cscart](https://paymos.io/zh-hans/docs/cms-cscart)
- 源码与发布：[Paymos-labs/cscart](https://github.com/Paymos-labs/cscart)
- 技术支持：[support@paymos.io](mailto:support@paymos.io)
