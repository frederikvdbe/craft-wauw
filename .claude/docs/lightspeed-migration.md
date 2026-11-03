# Lightspeed → Craft Commerce migration

## Approach
- Custom Craft module with console commands
- 2 steps: **extract** (API → local JSON) → **import** (JSON → Craft)
- Every record stores `lightspeedId` → import can run again without duplicates
- Delta sync before go-live
- Never import live from the API (rate limit)

## Current state
- Craft 5.9, fresh install, DDEV
- Commerce 5.7 installed (1 store, dummy gateway)

## Commands
- `ddev craft lightspeed/extract/counts` — records per resource
- `ddev craft lightspeed/extract [resource]` — download all (or 1) to `storage/lightspeed/{lang}/*.json`
- `ddev craft lightspeed/setup` — create content model + tax (safe to run again; tax is DB-only, run on every env)
- `ddev craft lightspeed/import [categories|brands|products]` — import from JSON (safe to run again)
- Code: `modules/lightspeed/`

## Shop data (extract 2026-11-03)
- Shop status `test`, language `en` only
- 8 products, 1 variant each ("Default", no options), stock 1 each → one-off design/vintage items
- 5 categories, all top level (VIP PREVIEW, ABOUT US, NEW ARRIVALS, THE ARCHIVE, AVAILABLE COLLECTION)
- 8 brands, 14 text pages (many are system pages: cart, sitemap, …)
- 224 tags, only 4 linked to products
- 0 customers, orders, reviews, blogs, redirects
- 2 tax rates: `1207657` (incl = excl, 0% / margin scheme?) and `868640` (21%)
- Some variants have `oldPriceIncl` (sale price)

## Lightspeed API facts
- Docs: https://developers.lightspeedhq.com/ecom/introduction/introduction/
- Auth: Basic auth, API key + secret
- URL: `https://{key}:{secret}@{cluster}/{lang}/{resource}.json`
- Clusters: EU1 `api.webshopapp.com`, US1 `api.shoplightspeed.com`
- Language is in the path → 1 call per language for texts, slugs, meta
- Rate limit: 300 / 5 min, 3000 / hour, 12000 / day, 20 / sec
- Paging: `page`, `limit` (max 250), `since_id`, `updated_at_min`, `fields`
- Counts: `/{resource}/count.json`
- Product relations are separate endpoints: variants, images, categories, attributes, tags, reviews
- Product `description` max 255 chars; `content` = long HTML
- Price / stock: probably on variants (to verify)
- Customer passwords: not exportable → "set new password" email

## Phases
| # | Phase | Output |
|---|---|---|
| 0 | Setup: install Commerce, get API key/secret, count records | Size of the job |
| 1 | Extract: `craft lightspeed/extract <resource>`, throttled, can resume | JSON in `storage/lightspeed/` |
| 2 | Content model: sites per language, sections, fields, product types | Project config |
| 3 | Import (order below) | Craft data |
| 4 | Redirects: old Lightspeed URLs → new Craft URLs | Keep SEO |
| 5 | Delta sync before go-live (`updated_at_min`) | Fresh data |
| 6 | Checks: counts, spot checks, VAT | Sign-off |

## Import order
1. Languages → Craft sites
2. Categories (tree) → structure section or category group
3. Brands → entries or category group
4. Attributes / filters / option sets → fields or categories
5. Products + variants → Commerce products/variants (SKU, price, stock, tax)
6. Images → assets (download from CDN, no duplicates)
7. Customers + addresses → Craft users + addresses
8. Orders (optional) → completed Commerce orders
9. Reviews, text pages, blog posts
10. Redirects

## Risks
- **Orders:** no clean Commerce API for old orders. Options: full import / read-only archive / skip
- **VAT:** check if prices are incl or excl VAT; match Commerce tax settings
- **Variants:** Lightspeed option sets → Commerce variants + option fields
- **Rate limit:** big shop = many hours to extract. Ask Lightspeed for higher limits if needed

## Open questions
- [ ] Content is Dutch but site language is `en` → change site to `nl-BE`?
- [ ] Keep Lightspeed URL format (`/{slug}.html`) or redirect to `products/{slug}`?
- [ ] Templates for products, categories, brands (not built yet)
- [x] Which data must move? → categories, brands, products (rest is empty or skipped)
- [x] Order history? → 0 orders, nothing to do
- [x] Which languages / storefronts? → `en` only (for now)
- [x] Size? → very small, see Shop data
- [x] API key + secret available? → in `.env`
- [x] Custom module or Feed Me? → custom module

## Content model
| Lightspeed | Craft |
|---|---|
| Category | Structure section `categories` (entry type `category`), URI `categories/{slug}` |
| Brand | Channel section `brands` (entry type `brand`), URI `brands/{slug}` |
| Product | Commerce product type `products`, max 1 variant, URI `products/{slug}` |
| Variant | Variant: SKU = `sku` / `articleCode` / `LS-{id}`, price incl VAT, stock |
| `oldPriceIncl` > `priceIncl` | price = old price, promotional price = current price |
| Image | Asset in volume `productImages` (`web/uploads/products`) |
| Tax `Marge` (1207657) | Tax category `marginScheme` (default, no rate) |
| Tax 21% (868640) | Tax category `standard` + rate 21% included |

- Fields: `lightspeedId`, `fullTitle`, `shortDescription`, `richText` (CKEditor), `productImages`, `productCategories`, `brand`
- Store currency: EUR

## Decisions
- Categories → structure section (not category group)
- Brands → channel section
- Text pages → not imported
- Tags → not imported (for now)
- Tax: "Marge" = margin scheme

## Progress
- [x] Phase 0 — setup
- [x] Phase 1 — extract (first run done)
- [x] Phase 2 — content model
- [x] Phase 3 — import categories, brands, products (first run done 2026-11-03)
- [ ] Phase 4 — redirects
- [ ] Phase 5 — delta sync
- [ ] Phase 6 — checks
