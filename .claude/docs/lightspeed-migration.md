# Lightspeed → Craft Commerce migration

## Approach
- Custom Craft module with console commands
- Source = **CSV product export** from the live shop (not the API)
- Every record stores `lightspeedId` → import can run again without duplicates
- Delta sync before go-live = new export + import again
- Client questions + open work: `.claude/docs/backlog.md`

## Current state
- Craft 5.9, fresh install, DDEV
- Craft **Pro** (customer accounts; Solo = 1 user) → client needs a Pro license before go-live
- System time zone: Europe/Brussels
- Commerce 5.7 installed (1 store, dummy gateway)
- Sites: `en` (primary, `/`), `nl` (`/nl`), `fr` (`/fr`)
- Importer reads the CSVs in `storage/lightspeed/` (git-ignored: personal data): `products.csv`, `customers.csv`, `orders.csv`
- Old **demo store** data (8 products, 13 entries, 50 assets) still in Craft → delete

## Commands
- `ddev craft lightspeed/setup` — create content model + tax (safe to run again; tax is DB-only, run on every env)
- `ddev craft lightspeed/import --sample` — **sample import**: small fixed test set (use this during dev)
- `ddev craft lightspeed/import` — **full import**: everything; asks for confirmation
- `ddev craft lightspeed/import/<categories|brands|products|customers|orders> [--sample]` — one part. Note: `import orders` (space) runs **everything**
- Safe to run again; existing orders are skipped
- Sample (`Sample.php`): 1 order per case (multi-line, not matched, guest, cancelled, unpaid, VAT, discount, shipping + fee, GDPR), 1 customer with 2 orders, their customers + 3 VIP members without orders, products, and unsold products per product case
- Import needs `storage/lightspeed/api/customers.json` (`lightspeed/extract customers`): only the API knows guests
- Sample breaks all email addresses (`…@gmail.comx`) → no mail can reach real customers
- `ddev craft lightspeed/extract --sample[=N]` — API export, first 25 (or N) items per list → `storage/lightspeed/api-sample/`
- `ddev craft lightspeed/extract` — **full API export** → `storage/lightspeed/api/`; asks for confirmation; ~10k requests ≈ 3.5 h (limit 3,000/h, 12,000/day)
- `ddev craft lightspeed/extract <name>` — one file (e.g. `products-images`); `lightspeed/extract/counts` — records per list
- API is **read-only**: client blocks every non-GET request (live shop)
- Languages from the API (`nl` default, `en`, `fr`); texts per language in `{lang}/`, the rest once (images: once)
- Code: `modules/lightspeed/`

## Sources
| Source | What | Use |
|---|---|---|
| CSV export `products_export_2026-11-05_05-58-25.csv` | live shop (CDN shop `167000`) | products, categories, brands, images |
| Customer export `customers.csv` | live shop, **partial**: 50 customers (export = 500 pages of 50) | customers; full set later via API |
| Order export `orders.csv` | live shop | orders |
| Stock export | live shop, can be exported | only if CSV `Stock_Level` is not reliable |
| API (key in `.env`) | **live shop**, read-only | full JSON export; customers `type` (`registered` / guest?), groups, newsletter, filters, metafields (SEO) |

## Shop data (CSV export 2026-11-05)
- Format: `;` separated, UTF-8, multi-line HTML in quoted fields
- 1,739 rows = 1,719 products (1 row per variant)
- Languages: NL, EN (all), FR (1,636)
- 1,708 products with 1 variant; 11 with 2–8 (variant title as text, e.g. "Kies kleur: Geel"); `Matrix` empty
- Visible `N`: 192
- Tax: `0` (1,669) = margin scheme, `21` (70)
- Brand: only 13 products; Supplier, Article_Code, Unit: empty
- EAN: 162, SKU: 11
- Images: ~10 per product (Lightspeed max), ~15k in total
- Tags: 4,927 unique, messy
- Category path: `Category_1` > `Category_2` (`Category_3` empty)

| Category_1 | Category_2 | Products |
|---|---|---|
| SOLD | – | 850 |
| (empty) | – | 659 |
| TOP 50 | – | 119 |
| collection in stock | Meubilair, Exclusive selection, Decoratie, Verlichting, Vintage Audio, Te huur | 105 |
| PREVIEW | – | 6 |

### Price hack
- `Price` = 0, 1 or 2 → hack: **sold** or **price on request**
- Real price is then in `Price_Old` (often filled, often 0)
- Stock ≤ 0 → sold; stock > 0 → price on request (~38 products)
- Negative stock exists (-1 … -3) → set to 0

### Custom data fields
| Column | Content | Filled |
|---|---|---|
| `Data_01` | video / image URL | 5 |
| `Data_02` | glo3d 3D viewer URL | 45 |
| `Data_03` | **extra images** (Lightspeed image limit); also some mp4 / YouTube / glo3d | 1,384 |

## Phases
| # | Phase | Output |
|---|---|---|
| 0 | Setup: install Commerce | ✓ |
| 1 | Source data: CSV export | CSV |
| 2 | Content model: sites per language, sections, fields, product type | Project config |
| 3 | Import: categories → brands → products + variants → images | Craft data |
| 4 | Redirects: old Lightspeed URLs → new Craft URLs | Keep SEO |
| 5 | Delta sync before go-live (new export) | Fresh data |
| 6 | Checks: counts, spot checks, VAT | Sign-off |

## Customer data (export 2026-11-05, 50 customers)
- 1 row per customer; billing + shipping address in the same row
- Only 2 of the 650 order customers are in it → export is partial
- Country = English name (`Belgium`, `Netherlands, The`) → ISO code; `Turkey` → alias; empty country (2) → address skipped + warning
- Customer type: `default` (= private) / `company`
- Groups, birthdate, name prefix: all empty; gender: 1
- `Confirmed` = email confirmed in Lightspeed (not newsletter)

## Customer types (API, 2026-11-06)
| Type | Count | With orders |
|---|---|---|
| `registered` (account) | 24,870 | 593 |
| `guest` (no account) | 50 | 48 |
- 9 order customers not in the API (GDPR deleted)
- Registered without orders (24,277) = mostly VIP members (sign up for previews)

## Order data (live export 2026-11-05)
- 1,234 orders, 1,364 rows (1 row per line item; order fields only on the first row)
- 2016–2026; 650 customers (`Customer` = customer `Internal_ID`)
- Status: paid+shipped 893, cancelled 278, not paid 36, paid not shipped 27
- Total = Σ(qty × `Product_price`) + `Price_shipping` + `Price_payment` (checked: all orders)
- `Product_price` already includes the discount; `Discount_price` = info only
- No product ID on lines; 928 / 1,364 match a product title; SKU 23, EAN 143
- Country = ISO code; date `28-12-2016 @ 10:24`; decimal comma
- 29 orders of GDPR-deleted customers (name / email / address = "GDPR deleted")

## Content model
| CSV | Craft |
|---|---|
| Category_1 / Category_2 (`collection in stock` tree) | Structure section `categories` |
| `SOLD` | no category: stock 0, product stays live |
| `TOP 50` | lightswitch `featured` |
| `PREVIEW` | lightswitch `vipPreview` |
| Brand | Channel section `brands`, from the **API** (`lightspeed/extract brands`): 19 brands (18 after merge), `lightspeedId`, slug per language, visibility |
| Brand image (9 of 19) | field `brandImage`, volume `brandImages` (`uploads/brands`) |
| Brand text | none in Lightspeed (API `content` empty) |
| Product | Commerce product type `products`, variants on |
| `Internal_ID` / `Internal_Variant_ID` | `lightspeedId` on product / variant |
| Title_Short / Title_Long | title / `fullTitle` (per site) |
| Description_Short / Long | `shortDescription` / `richText` (per site) |
| `URL` | slug (per site) |
| Variant title | variant title ("Default" → product title) |
| SKU | `SKU` / `LS-{variantId}` |
| Price ≤ 2 | lightswitch `priceOnRequest`, base price = `Price_Old` |
| Price > 2, `Price_Old` > `Price` | price = old price, promotional price = price |
| Tax `0` / `21` | tax category `marginScheme` / `standard` (21% incl) |
| Stock_Level | stock (min 0) |
| Size_X/Y/Z, Weight | native variant length / width / height / weight |
| EAN | field `ean` |
| Visible `N` | disabled |
| Images + `Data_03` images | `productImages`, folder per product ID |
| `Data_02` + glo3d / hotspot viewers in `Data_01/03` | link field `viewerUrl` |
| mp4 / YouTube in `Data_01/03` | link field `video` |
| Meta title / description | SEO plugin (SEOmatic) |
| Tags, Price_Cost, Supplier | not imported |
| Customer | Craft user (see customer type below) |
| Customer `Internal_ID` | user `lightspeedId` (match: ID, then email) |
| Customer type, Coc number, Birthdate, Gender, Confirmed | user fields `customerType`, `cocNumber`, `birthdate`, `gender`, `emailConfirmed` |
| Billing / shipping address | user addresses (1 when equal) + Commerce primary billing / shipping |
| Company / VAT number / Attn | address organization / tax ID / full name |
| Phone / Mobile | address fields `phone` / `mobile` |
| Language | user preference language |
| Groups | user groups (created when missing) |
| Customer `type` (API) `registered` | **pending** user, group **VIP Members** (also default group for new sign-ups) |
| Customer `type` (API) `guest` | **inactive** user (no login, no email), group **Guests** |
| Has ordered | group **Customers** (accounts + guests) |
| Region | dropped by Craft for BE (no region in BE address format) |
| Order | completed Commerce order, `reference` = `ORD…`, no recalculation / stock change / emails |
| Order status | `Paid/Not paid, shipped` → shipped; `… not shipped` → new; `Cancelled` → cancelled |
| Paid orders | successful transaction via hidden gateway `lightspeed`; `datePaid` = order date |
| Order line | snapshot (title, price, qty); linked to the variant when SKU / EAN / unique title matches, else custom line item |
| Shipping / payment fee / VAT | adjustments `shipping` / `paymentFee` / `tax` (included) |
| Invoices, credit notes, payment, discount, comment, memo | order fields |
| Order customer | user by `lightspeedId` / email; new → pending user from the last order (existing users not changed) |
| GDPR-deleted order | order without customer, email and addresses |

- Store currency: EUR; units cm / kg (`config/commerce.php`)
- Missing translation (FR) → EN text, product disabled on that site
- Categories matched on EN title (CSV has no IDs for them); brands on `lightspeedId`
- Duplicate brands (same title, e.g. "Fontana Arte" ×2) → merged into 1 entry (keeps the one with image, else oldest ID)
- CSV has only 1 category path per product → a TOP 50 product has no collection category

## Architecture notes
- Craft: categories + tags deprecated in 5.9 → entries (Structure/Channel) = official way ([blog](https://craftcms.com/blog/2025-in-review))
- Commerce: product types = field layout / checkout flow, not browsing → 1 type
- Unique items: sold = keep live, stock 0 (keeps SEO)
- Plugins to consider: SEOmatic, Retour (redirects), Sprig (filters, search, cart)

## Lightspeed API facts (reference)
- Docs: https://developers.lightspeedhq.com/ecom/introduction/introduction/
- Auth: Basic auth, key + secret; EU1 `api.webshopapp.com`
- Rate limit: 300 / 5 min, 3000 / hour, 12000 / day
- Customer passwords: not exportable → "set new password" email

## Decisions
- Source: CSV export for products / orders (importers built + tested); API JSON export = full backup + data not in the CSVs
- 1 product type `products`, variants on
- Categories → structure section; brands → channel section
- SOLD / TOP 50 / PREVIEW → status / lightswitches, not categories
- Price hack (≤ 2) → `priceOnRequest` + real price from `Price_Old`
- `Data_03` = extra product images → add to `productImages`
- Text pages, tags → not imported
- Tax: `0` = margin scheme
- Images: restricted upload location `{id}` (not `{slug}`: slug differs per site)
- Sites: EN primary + NL + FR
- URLs: `products/{slug}` + 301 redirects from old URLs
- GraphQL disabled (`enableGql(false)`)
- Customers → pending users; at go-live 1 "set your password" email to all
- Keep all customer data (birthdate, gender, groups, …)
- Newsletter: not imported. Client exports subscribers to Klaviyo and deletes them in Lightspeed → Klaviyo is the source
- Orders → real Commerce orders (customers see their past orders); lines = snapshot + link to product when matched
- Orders of customers not in the export → pending user from the order data (option 2); all customers later via the **API** (everyone with an account must keep their login, e.g. VIP previews)
- Dev: `--sample` instead of `--limit`; full import asks for confirmation
- `--file` / `--limit` removed: CSVs always in `storage/lightspeed/`

## Open questions (dev)
- [ ] Templates for products, categories, brands (not built yet)
- Client questions → `backlog.md`

## Progress
- [x] Phase 0 — setup
- [x] Phase 1 — CSV export received (2026-11-05)
- [x] Phase 2 — content model: 3 sites + new fields
- [ ] Phase 3 — importer for products, customers, orders built; tested with `--sample` (full import + customer API later)
- [ ] Phase 4 — redirects
- [ ] Phase 5 — delta sync
- [ ] Phase 6 — checks
