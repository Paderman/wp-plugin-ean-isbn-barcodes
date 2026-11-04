=== EAN & ISBN Barcodes ===
Contributors: pader-man
Tags: ean, isbn, barcode, gtin, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

EAN, UPC, ISBN, ISSN and ISMN barcodes and GS1 Digital Link QR codes as block or shortcode – with product data from several sources.

== Description ==

* Sharp SVG barcodes generated locally – no account or API needed.
* Formats: EAN-13, EAN-8, UPC-A, UPC-E, ISBN (books), ISSN (periodicals) and ISMN (sheet music), each optionally with a 2- or 5-digit add-on (issue number, price).
* ISBN support: ISBN-13 (978/979) is detected automatically, ISBN-10 is converted, and book barcodes get the classic “ISBN …” line above the bars – likewise “ISSN …” and “ISMN …”.
* Check digit validation (EAN, UPC, ISBN-10, ISSN) with helpful hints for editors.
* QR codes with the GS1 Digital Link (e.g. https://id.gs1.org/01/04006381333931), optionally with batch and serial number: phones open the product's web address, 2D-capable checkout scanners read the GTIN. Generated locally as SVG.
* Built-in Digital Link resolver (optional): QR codes point to your own domain, and /01/{GTIN} forwards to the matching WooCommerce product.
* Product names and product cards from several data sources, asked one after another. If one service does not know a code or is down, the next one is asked:
  * Open Library – books (free, no account)
  * Open Food Facts – food (free, no account)
  * ean-search.org – all kinds of products (API token required)
* Developers can add more data sources via the `eanbc_providers` filter.
* Block "EAN & ISBN Barcode" with live preview: barcode, product card or product name only. Fetch the product name once and correct it before publishing.
* Modern settings page with live preview and shortcode builder – and you decide where it lives in the admin menu (own item, under Settings or under Tools).
* Free choice of link target: the matching data source, your own URL template (e.g. your shop search) or a URL per barcode.
* Reads the EAN/ISBN from a custom field or the product's GTIN.
* WooCommerce (optional, used only when it is active):
  * Barcode and/or QR code on the product page – with SKU and categories or below the summary. For variable products, it follows the selected variation.
  * GTIN column in the product list with a small barcode; invalid GTINs (wrong check digit) are marked.
  * “Look up product data” next to the GTIN field: shows name, brand or author and takes the name over as product name.
  * Reads the GTIN from WooCommerce's own field and from Germanized, German Market and EAN for WooCommerce. More fields via the `eanbc_gtin_meta_keys` filter.
* Translated into German (incl. a Swiss variant), French, Spanish, Italian and Dutch. Regional variants such as de_AT, fr_CA or es_MX use the matching main language.

= Shortcodes =

`[barcode ean="00885909943074"]` – barcode (aliases `[ean]`, `[ean_barcode]`)
`[barcode 4006381333931]` – short form
`[ean_product ean="3017620422003"]` – product card
`[ean_name ean="3017620422003"]` – product name as text
`[isbn 978-3-16-148410-0]` – book barcode with ISBN line (alias `[isbn_barcode]`)
`[isbn 3-16-148410-X]` – ISBN-10, converted automatically
`[isbn_product isbn="9780140328721"]` / `[isbn_name …]` – book card / title
`[isbn 978-3-16-148410-0 addon="52499"]` – book with price add-on
`[barcode issn="0317-8471" addon="05"]` – periodical with issue number
`[barcode ismn="979-0-2600-0043-8"]` – sheet music
`[barcode ean="04252614"]` – UPC-E
`[ean_qr ean="4006381333931"]` – QR code with GS1 Digital Link (alias `[gs1_qr]`)
`[ean_qr ean="4006381333931" batch="L2026-11" serial="0001"]` – with batch and serial number

Attributes: ean (or isbn, issn, ismn), type, addon, isbn_text, width, height, number, name, link, url, align, style, color, background, source, output, title, caption, field, id, class, fallback, batch, serial, size, ecc. See the "Help" tab in the plugin settings.

== External services ==

Barcodes are always generated on your server. Only product names and product cards need data from external services. The requests are made by your web server – never by your visitors' browsers – and contain only the product code (EAN/ISBN) and, where required, your API token. Results are cached on your server (30 days by default). Each service can be switched off under “Data sources”.

* **Open Library** (Internet Archive) – book data for ISBNs. Endpoint: https://openlibrary.org/api/books. API: https://openlibrary.org/developers/api
* **Open Food Facts** – food data. Endpoint: https://world.openfoodfacts.org/api/v3/. Terms of use: https://world.openfoodfacts.org/terms-of-use – data license: Open Database License (ODbL); product cards show the source.
* **ean-search.org** – product data for all kinds of products and, optionally, PNG barcode images. Only used when you enter an API token. Endpoint: https://api.ean-search.org/api. Terms: https://www.ean-search.org/ean-database-api.html

QR codes contain a web address. By default it points to the GS1 resolver id.gs1.org (https://www.gs1.org/standards/gs1-digital-link); the plugin itself never contacts it – only people who scan the code open it. You can use your own domain instead.

The plugin adds a suggested text to Settings → Privacy that lists the active data sources.

== Changelog ==

= 1.0.0 =
* Initial release.
