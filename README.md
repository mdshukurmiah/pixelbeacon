# PixelBeacon for WooCommerce

**Contributors:** Md Shukur Miah  
**Tags:** pixel, conversions api, server-side tracking, woocommerce, meta pixel  
**Requires at least:** 6.2  
**Tested up to:** 7.1  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.6  
**License:** GPLv2 or later  
**License URI:** [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)  

Meta Pixel & Conversions API (CAPI) and TikTok Pixel & Events API tracking for WooCommerce with deduplication, high Event Match Quality (EMQ), and server-side signals.

---

## Description

**PixelBeacon for WooCommerce** enables you to transmit Meta Pixel events directly from your WordPress server to Meta using the Conversions API alongside client-side browser tracking. With identical `event_id` generation across browser and server, PixelBeacon ensures accurate deduplication, high EMQ scores, and immunity to ad blockers and browser privacy restrictions.

### Key Features

- **Hybrid Tracking with True Deduplication**: Fires browser pixel and server CAPI with identical `event_id` tokens so Meta eliminates duplicates.
- **Deep WooCommerce Integration**: Comprehensive tracking for `PageView`, `ViewContent`, `ViewCategory`, `AddToCart`, `RemoveFromCart`, `InitiateCheckout`, `AddPaymentInfo`, `Purchase`, `AddToWishlist`, and `CustomizeProduct`.
- **Custom E-Commerce Audience Triggers**: Track `FirstOrder`, `ReturningCustomer`, and high-value `VipClient` (LTV >= 500 or 5+ orders).
- **High Event Match Quality (EMQ)**: Hashes and transmits user data (email, phone, name, city, state, country, zip, IP address, user agent, `_fbp`, `_fbc`, and external persistent user IDs).
- **Full Catalog Attribution**: Passes `content_ids`, `content_type`, `contents`, `num_items`, `value`, `currency`, `content_name`, `content_category`, shipping, and tax parameters.
- **Reliable Fallbacks & Queue**: Failed API calls are automatically queued in custom database tables with exponential retry logic.
- **Form Persistence**: Automatically captures lead and contact fields on user input for improved attribution.
- **Block & Classic Checkout Support**: Fully compatible with classic shortcode checkouts and modern WooCommerce Checkout blocks.
- **Privacy & Compliance**: Built-in support for Meta Limited Data Use (LDU) / CCPA compliance.

---

## Installation

1. Upload the plugin files to `/wp-content/plugins/pixelbeacon`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > PixelBeacon**.
4. Enter your Facebook Pixel ID and Conversions API Access Token.
5. Select the events you want to track.
6. Test your connection using the built-in test tool.

---

## Frequently Asked Questions

### How do I get a Facebook Pixel ID?
1. Open [Meta Events Manager](https://business.facebook.com/events_manager).
2. Select your Data Source (Pixel).
3. Copy the numeric Pixel ID from settings.

### How do I generate a Conversions API Access Token?
1. Go to your Pixel settings in Meta Events Manager.
2. Scroll to **Conversions API**.
3. Under "Set up manually", click **Generate access token**.

### Does PixelBeacon cause duplicate events in Meta Events Manager?
No. Every browser and server event pair is tagged with matching `event_id` and action parameters, allowing Meta's deduplication engine to count only a single event while combining data signals for maximum attribution accuracy.

---

## Changelog

### 1.0.6
- **New: TikTok Pixel & Events API Integration**: Added complete hybrid browser and server-side tracking cloned and optimized from PixelYourSite Pro specifications with native `wp_remote_post` implementation.
- **New: Tabbed Admin Settings**: Added modern tabbed interface separating Facebook and TikTok configurations with active-tab persistence.
- **New: TikTok Event Mapping**: Built-in support for `CompletePayment` (Purchase), `AddToCart`, `InitiateCheckout`, `ViewContent`, `AddPaymentInfo`, `Search`, `AddToWishlist`, `CompleteRegistration`, `Contact`, and `PageView`.
- **New: TikTok Deduplication**: Synchronized RFC UUIDv4 `event_id` generation between browser pixel (`ttq.track`) and server-side Events API.
- **New: TikTok First-Party Cookies & Attribution**: Added automated `_ttp` 1st-party cookie generation (13-month lifetime) and `ttclid` capture (90-day lifetime).
- **New: Order Meta Locking**: Locked TikTok and Facebook cookies into order metadata (`_pixelbeacon_tiktok_cookie`) to maintain attribution across external payment gateway webhooks.
- **Improved: Phone Number Normalization**: Added E.164 phone normalization with local Bangladesh mobile detection (`01X` -> `+8801X`) for higher EMQ across both Meta and TikTok.
- **New: Dedicated TikTok Connection Test Tool**: Verify TikTok Pixel ID and Events API Access Token credentials directly from the admin interface.
- **New: WooCommerce HPOS Compatibility**: Declared official compatibility for WooCommerce High-Performance Order Storage (HPOS).

### 1.0.5
- Renamed plugin to PixelBeacon for WooCommerce.
- Modernized slugs, text domains, classes (`PixelBeacon`), and global helper `pixelBeacon()`.
- Added backward compatibility aliases for smooth upgrade transition.
- Added comprehensive catalog parameters across all standard e-commerce events.
- Added custom audience triggers: FirstOrder, ReturningCustomer, VipClient.
