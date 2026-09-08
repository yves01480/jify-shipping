# Jify Shipping

**[下載 v3.9.2 安裝 ZIP](https://github.com/yves01480/jify-shipping/releases/download/v3.9.2/jify-shipping.zip)** · [版本紀錄](https://github.com/yves01480/jify-shipping/releases) · [試用／問題回報](https://github.com/yves01480/jify-shipping/issues/new?template=store-feedback.md)

免費開源（GPL-2.0-or-later）。已有 WordPress＋WooCommerce 商店即可在測試站開始；主機與其他服務費用另計。

## 買幾件，收多少運費；特殊組合，交給店家報價。

適合需要「依購買件數設定運費」的 WooCommerce 店家，例如箱裝商品、批量採購，或混合商品需要另外確認運送成本的商店。

### 先看一個實際情境

同一個啟用 Jify Shipping 的商品，設定以下運費規則：

| 購買數量 | 該次運費 |
|---|---:|
| 1–5 件 | NT$100 |
| 6–10 件 | NT$150 |

顧客買 3 件，運費是 **NT$100**；改成 8 件，運費變成 **NT$150**。金額按符合的數量區間收取一次。

- **按商品／規格設定：** 不同商品與變體可以各自設定規則。
- **遇到特殊購物車：** 多種 Jify 商品混買、與一般商品混買，或數量超出規則時，進入待報價流程。
- **店家填入運費：** 後台保存報價並寄出通知，顧客原購物車可讀取報價繼續結帳。

**第一次試用：** 先完成上面「3 件 → 8 件」的運費測試，再驗證人工報價流程。[照著入門教學操作](docs/quick-start.zh-TW.md)。

> 人工報價依目前購物車識別碼運作；通知信不會重建購物車，也不是先建立待付款訂單。前台停用結帳按鈕不代表所有結帳入口都有伺服器端封鎖。若需要「未報價絕不能下單」，請先完成該流程的驗證與補強。

### 下載後怎麼安裝

1. 下載上方 **jify-shipping.zip**，不需解壓縮。
2. 到 WordPress **外掛 → 安裝外掛 → 上傳外掛**，選取 ZIP 後安裝、啟用。
3. 依上面的第一次試用情境設定；版本需求與完整行為請見下方英文文件。

有想套用的店家情境？[告訴我你的設定與預期結果](https://github.com/yves01480/jify-shipping/issues/new?template=store-feedback.md)，也歡迎回報第一次安裝卡在哪一步。GitHub Issue 是公開的，請使用測試資料。

---

## English documentation

> Advanced quantity-based shipping manager with mixed product quotes for WooCommerce.

![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Stable](https://img.shields.io/badge/stable-3.9.2-brightgreen)

A WooCommerce plugin for stores that need granular shipping cost rules based on product quantities and complex mixed-cart scenarios — including a "Pending Quote" workflow for items that require manual quoting.

## Key Features

- **Quantity-based rules** — per product / variation, by quantity range (e.g. 1–5 items: $100, 6–10 items: $150)
- **Mixed product handling** — auto-detect special items and trigger a pending-quote workflow
- **Manual quote workflow** — admin reviews mixed carts and sends a custom shipping quote by email
- **Smart cart notices** — guide customers when mixed products are detected
- **Variation support** — different shipping rules per product variation
- **Checkout control** — disable the standard checkout button while a quote is pending; this is not a server-side checkout guarantee

## Installation

1. Upload to `/wp-content/plugins/jify-shipping`, or install via the Plugins screen.
2. Activate from the **Plugins** screen.
3. Add **Jify Shipping** to the applicable **WooCommerce → Settings → Shipping → Shipping zone**.
4. Configure under **Product Data → Jify Shipping** for each product.
5. Follow the [Traditional Chinese quick start](docs/quick-start.zh-TW.md) to check quantity boundaries and the quote flow.

Quotes are stored against the current cart hash. Quote emails do not restore carts or create orders. Verify the full checkout flow before relying on manual quotation in production.

## Documentation

Full feature docs and demo: <https://jify.cloud>

The canonical plugin metadata for WordPress.org lives in [`readme.txt`](readme.txt).

## License

GPL-2.0-or-later — see [`license.txt`](license.txt).
