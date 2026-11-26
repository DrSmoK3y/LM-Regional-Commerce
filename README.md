># LM Regional Commerce

Country-based pricing, currency and separate stock for WooCommerce. Built for stores that sell in a few fixed regions, for example **Pakistan (PKR), Saudi Arabia (SAR) and UAE (AED)**.

Visitors pick their region once from a flag popup. From then on they see prices in that region's currency and that region's stock. Their choice is saved in the browser for one year.

**No external API.** Prices come from a multiplier you set, so there are no exchange-rate requests and no extra page load.

![Version](https://img.shields.io/badge/version-1.3.0-blue) ![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b) ![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![HPOS](https://img.shields.io/badge/HPOS-compatible-success)

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Region settings](#region-settings)
- [How prices are calculated](#how-prices-are-calculated)
- [Rounding options](#rounding-options)
- [Custom prices for some products](#custom-prices-for-some-products)
- [Regional stock](#regional-stock)
- [Showing the region switcher](#showing-the-region-switcher)
- [Orders](#orders)
- [Page caching](#page-caching)
- [FAQ](#faq)
- [Technical reference](#technical-reference)
- [Changelog](#changelog)

---

## Features

- **Region popup on first visit.** Flags for each region, mobile friendly. The visitor's choice is saved in a cookie for one year.
- **Multiplier pricing.** Enter prices once in your store currency. Each region converts them with its own multiplier. Change one number and every product updates.
- **Rounding.** Keep decimals, round to whole numbers, round up to 5, 10 or 100, or end prices in .99 or 9.
- **Custom price per product.** Give a product a fixed price in a region instead of the calculated one.
- **Separate stock per region.** Optional for each product and each variation. Only the ordered region's stock goes down.
- **Variable products.** Every variation has its own regional price and stock table.
- **Region switcher** as a shortcode, a floating button, a menu item or any `#lmrc-region` link. Mobile shows only the flag.
- **Region logo.** Optionally replace the site logo for a region.
- **Order tracking.** The region is saved on the order, shown in the admin and written to order notes when stock changes.
- Works with **HPOS** (custom order tables) and the **block cart and checkout**.
- Page-cache friendly. The popup and switcher read the cookie in JavaScript, and WP Rocket and LiteSpeed Cache keep a separate cache for each region.

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 7.4 |

---

## Installation

1. Download `lm-regional-commerce.zip`.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**.

Or upload the `lm-regional-commerce` folder to `/wp-content/plugins/` and activate it from the **Plugins** page.

> **Upgrading from 1.0.x:** deactivate the old version first, then install this one. Saved regional prices and stock are kept. Products that already had a regional price are treated as **custom price** automatically.

---

## Quick start

1. Make sure your **WooCommerce currency** (WooCommerce → Settings → General) is the currency you enter prices in, for example **PKR**.
2. Go to **WooCommerce → Regions**.
3. Click **Add Saudi Arabia, UAE & Pakistan**. This fills in:

   | Region | Currency | Symbol | Multiplier | Decimals |
   |---|---|---|---|---|
   | Pakistan | PKR | Rs | 1 | 0 |
   | Saudi Arabia | SAR | ر.س | 0.0135791 | 2 |
   | United Arab Emirates | AED | د.إ | 0.0132864 | 2 |

   > The preset multipliers are only filled in when the store currency is PKR. Check them against the current rate before you save.

4. Choose the **default region**. Visitors see it until they pick one.
5. Click **Save region settings**.

That's it. Every product now shows the converted price in the visitor's region.

---

## Region settings

**WooCommerce → Regions**

### General


<img width="467" height="281" alt="image" src="https://github.com/user-attachments/assets/ce65b27a-d910-4f70-8eb1-c092d962d3c2" />



| Setting | Description |
|---|---|
| Default region | Used until the visitor picks a region. |
| Ask visitors to choose a region on their first visit | Shows the flag popup to new visitors. They must pick a region before they can close it. |
| Show a small floating region button | A small flag button in the bottom-left corner that reopens the popup. |

### Each region

| Field | Description |
|---|---|
| Region name | Shown in the popup and the switcher, for example *Saudi Arabia*. |
| Currency code | 3-letter ISO code: `PKR`, `SAR`, `AED`. |
| Currency symbol | Optional display symbol: `Rs`, `ر.س`, `د.إ`. If blank, WooCommerce's default symbol is used. |
| Multiplier | 1 unit of store currency × this number = region price. Use `1` for the store's own currency. |
| Rounding | How the converted price is rounded. See [Rounding options](#rounding-options). |
| Price decimals | Decimals shown for this region. Leave blank to use the store setting. |
| Flag image | Image used in the popup, switcher and menu. Upload it to your Media Library for best speed. |
| Region logo | Optional. Replaces the theme's custom logo while this region is selected. |

The **Example** box under each region shows a live preview, for example `PKR 10,000 × 0.0132864 = AED 132.86`.

**Removing a region** hides its prices and stock on products but does not delete them. If you add a region with the same key again, the data comes back.



<img width="879" height="588" alt="image" src="https://github.com/user-attachments/assets/53ba3b8e-5f70-4e64-ab60-244b818aa3c9" />



---

## How prices are calculated

```
Region price = Normal WooCommerce price × Multiplier → Rounding
```

Example with PKR as the store currency:

| Normal price | Region | Multiplier | Result |
|---|---|---|---|
| Rs 10,000 | Pakistan | 1 | Rs 10,000 |
| Rs 10,000 | UAE | 0.0132864 | AED 132.86 |
| Rs 10,000 | Saudi Arabia | 0.0135791 | SAR 135.79 |

- Regular price and sale price are converted separately, so sales still work in every region.
- Prices are calculated when a page loads. Nothing is written to the database. **To update every product, change the multiplier and save.**
- In wp-admin you always see and edit the normal store-currency price.

---


## Rounding options

Results for PKR 10,000 → AED (multiplier 0.0132864, raw result 132.86):

| Option | Result |
|---|---|
| No rounding (keep decimals) | 132.86 |
| Nearest whole number | 133 |
| Round up to whole number | 133 |
| Round up to 5 | 135 |
| Round up to 10 | 140 |
| Round up to 100 | 200 |
| End in .99 | 132.99 |
| End in 9 | 139 |

> For the default region (multiplier 1) keep rounding on **No rounding**, so your entered prices stay exactly the same.

---

## Custom prices for some products

Use a custom price when a product needs a fixed price in a region instead of the calculated one.

### Simple products

1. Edit the product and open **Product data → Regional prices**.
2. Each region card shows the calculated price, for example *Calculated: PKR 10,000 × 0.0132864 = AED 132.86*.
3. Tick **Custom price?** for that region and enter the **Regular price**, plus an optional **Sale price**, in the region's currency.
4. Update the product.

Leave **Custom price?** unticked to use the calculated price.

### Variable products

1. Open **Product data → Variations** and expand a variation.
2. Under the normal price fields there is a **Regional prices & stock** table with one row per region.
3. The **Calculated** column shows the automatic price. Tick **Custom** to enter your own regular and sale price.
4. Click **Save changes**.

Notes:
- A sale price that is equal to or higher than the regular price is ignored.
- A custom regular price with no custom sale price does **not** use the normal sale price.

---

## Regional stock

Each product or variation can have its own stock in each region.

1. **Simple product:** Regional prices tab → tick **Manage stock?** for the region → enter **Stock quantity**.
   **Variation:** tick **Manage stock** in the region's row → enter **Stock**.
2. Save.

How it works:

- When regional stock is on, the region's quantity **replaces** WooCommerce's normal stock for visitors in that region. Availability text, "Out of stock" and the quantity limit all follow it.
- Customers cannot add more to the cart than the region has. This is checked at add to cart, in the cart and at checkout.
- When an order becomes **Processing**, **On hold** or **Completed**, only that region's stock goes down.
- When an order becomes **Cancelled**, **Refunded**, **Failed** or **Pending**, the stock is restored.
- WooCommerce's normal stock is **not** reduced for items that use regional stock.
- Each change is written to the order notes, for example *Regional stock reduced (UAE): Blue Shirt 12→10*.

If **Manage stock** is off for a region, that region uses WooCommerce's normal stock settings.

---

## Showing the region switcher

### Shortcode

| Shortcode | Desktop | Mobile (≤ 768px) |
|---|---|---|
| `[lmrc_region_switcher]` | Flag + name + currency | Flag + arrow |
| `[lmrc_region_switcher style="flag"]` | Flag + arrow | Flag + arrow |
| `[lmrc_region_switcher style="full"]` | Flag + name + currency | Flag + name + currency |

Clicking the switcher opens the region popup. Put the shortcode in a header widget, an Elementor or Gutenberg shortcode block, or a theme template:



<img width="345" height="115" alt="image" src="https://github.com/user-attachments/assets/7416b933-59e2-4025-9ff3-61c4c47974b7" />




```php
<?php echo do_shortcode('[lmrc_region_switcher]'); ?>
```

### Menu item

1. Go to **Appearance → Menus → Custom Links**.
2. **URL:** `#lmrc-region`. **Link text:** anything, it gets replaced.
3. Add it to the menu and save.

The menu item shows the current flag and name (only the flag on mobile) and opens the popup. Menu items get the CSS class `lmrc-menu-item`.

### Any link or button

Any link whose URL ends with `#lmrc-region` opens the popup, for example an Elementor button or a footer link.

```html
<a href="#lmrc-region">Change region</a>
```

Any element with the `data-lmrc-open` attribute opens it too.

### Direct link

`https://yourstore.com/#lmrc-region` opens the popup when the page loads. This is useful in emails or on social media.

You can also set the region straight from a URL. This sets the cookie and redirects back to a clean URL:

```
https://yourstore.com/shop/?lmrc_region=uae
```

Use the region key shown next to the region name on the Regions page.

### Floating button

Turn it on or off in **WooCommerce → Regions → General**. It shows in the bottom-left corner, as a flag only on mobile.

---

## Orders

- The region key, name and currency are saved on each order (classic and block checkout).
- The order edit screen shows **Shopping region: United Arab Emirates (AED)**.
- The order total is stored in the region's currency.

Order meta keys: `_lmrc_region`, `_lmrc_region_name`, `_lmrc_currency`, `_lmrc_stock_processed`.

---

## Page caching

Prices are rendered on the server, so a full-page cache must store **a separate copy for each region**. The cache key is the cookie `lmrc_region`.

| Cache | What to do |
|---|---|
| **WP Rocket** | Automatic. The plugin registers the cookie as a dynamic cookie. Clear the cache once after activating. |
| **LiteSpeed Cache** | Automatic. The cookie is added to the cache vary. Purge all once after activating. |
| **W3 Total Cache, WP Super Cache, others** | Add `lmrc_region` to the cookies that vary the cache or bypass it. |
| **Cloudflare "Cache Everything" / APO** | Bypass the cache when the `lmrc_region` cookie is present, or don't cache HTML for shop pages. |
| **Server cache (Varnish, Nginx FastCGI)** | Add `lmrc_region` to the cache key. |

**Test:** open the shop in a private window, pick UAE, reload a product page, then switch to Pakistan. The price and currency should change each time. If the currency doesn't change, your cache is serving one copy to everyone.

The popup, switcher and menu flag read the cookie in JavaScript, so they are always correct even on cached pages.

---

## FAQ

**Does it use an exchange-rate API?**
No. You set the multipliers yourself. This keeps the site fast. Update them whenever you want to follow the market rate.

**I changed the multiplier but the prices didn't change.**
Clear your page cache. If you use WooCommerce's object cache or a persistent cache (Redis), flush it once.

**Which price do customers in the default region pay?**
The normal WooCommerce price, as long as that region's multiplier is `1` and rounding is off.

**Can I have more than 3 regions?**
Yes. Click **+ Add region**. The popup layout adapts automatically.

**What happens to the popup if there is only one region?**
It doesn't show. The popup and switcher need at least two regions.

**Does it change shipping costs or coupons?**
No. Product prices and currency change. Fixed shipping rates and fixed-amount coupons stay in the numbers you entered. Set them with that in mind, or use a shipping zone per country.

**Are payment gateways affected?**
The order is placed in the region's currency. Make sure your payment gateway supports SAR, AED and PKR, or only offer gateways that do.

**Can visitors change their region later?**
Yes, from the switcher, the floating button, the menu item or any `#lmrc-region` link.

**The popup doesn't appear.**
- At least two regions must exist.
- **Ask visitors to choose a region on their first visit** must be ticked.
- It only appears when the visitor has no region cookie. Test in a private window.

---

## Technical reference

### Storage

| Key | Type | Content |
|---|---|---|
| `lmrc_regions` | option | Region settings array, keyed by region key |
| `lmrc_default_region` | option | Default region key |
| `lmrc_show_modal` | option | `1` / `0` |
| `lmrc_floating_button` | option | `1` / `0` |
| `_lmrc_regions` | product / variation meta | `[region_key => [regular_price, sale_price, price, custom_price, manage_stock, stock]]` |
| `lmrc_region` | cookie | Selected region key (1 year, `SameSite=Lax`) |

### Main hooks used

- Prices: `woocommerce_product_get_price` / `_regular_price` / `_sale_price` and the `woocommerce_product_variation_get_*` versions, plus `woocommerce_variation_prices_*` and `woocommerce_get_variation_prices_hash` for variable price ranges.
- Currency: `woocommerce_currency`, `woocommerce_currency_symbol`, `wc_get_price_decimals`.
- Stock: `woocommerce_product_get_stock_quantity`, `woocommerce_product_get_manage_stock`, `woocommerce_product_is_in_stock`, `woocommerce_is_purchasable`, `woocommerce_get_availability`, `woocommerce_add_to_cart_validation`, `woocommerce_check_cart_items`.
- Orders: `woocommerce_checkout_create_order`, `woocommerce_store_api_checkout_update_order_from_request`, `woocommerce_order_status_*`.

Prices and stock are only changed on the frontend. wp-admin and admin AJAX always show the original values.

### CSS classes for theming

| Class | Element |
|---|---|
| `.lmrc-switcher` | Switcher button (`--auto`, `--flag`, `--full`, `--floating`) |
| `.lmrc-menu-item`, `.lmrc-menu-link` | Menu item and its content |
| `.lmrc-modal`, `.lmrc-modal__dialog`, `.lmrc-modal__close` | Popup |
| `.lmrc-choice` | Region card in the popup (`.is-current` for the selected one) |
| `.lmrc-flag` | Flag image |

The plugin's front-end CSS uses the `html body` prefix. Use the same prefix, or a more specific selector, when you override it.

---

## Changelog

### 1.3.0
- Redesigned the Regions admin page. Fixed the layout breaking because of long dropdown options.
- Moved the popup close button to the top-right corner and stopped theme button styles from affecting it.
- The switcher shows only the flag and arrow on mobile. Added `style="flag"` and `style="full"` shortcode options.
- Added a menu item with the current flag (custom link `#lmrc-region`). Any `#lmrc-region` link and the `/#lmrc-region` URL now open the popup.

---

## License

GPL-2.0-or-later, the same license as WordPress.

Developed by **DrSmoK3y**.
