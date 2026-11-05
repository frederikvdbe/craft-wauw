# Lightspeed → Craft Commerce migration

## Approach
- Custom Craft module with console commands
- Source = **CSV product export** from the live shop (not the API)
- Every record stores `lightspeedId` → import can run again without duplicates
- Delta sync before go-live = new export + import again
- Client questions + open work: `.claude/docs/backlog.md`

## Current state
- Craft 5.9, fresh install, DDEV
- Commerce 5.7 installed (1 store, dummy gateway)
- Sites: `en` (primary, `/`), `nl` (`/nl`), `fr` (`/fr`)
- Importer reads the CSV (`storage/lightspeed/products.csv`)
- Old **demo store** data (8 products, 13 entries, 50 assets) still in Craft → delete

## Commands
- `ddev craft lightspeed/setup` — create content model + tax (safe to run again; tax is DB-only, run on every env)
- `ddev craft lightspeed/import [categories|brands|products] [--file=…] [--limit=20]` — import from CSV (safe to run again). During dev: always `--limit=20`
- `ddev craft lightspeed/extract …` — API extract (demo store only, see below)
- Code: `modules/lightspeed/`

## Sources
| Source | What | Use |
|---|---|---|
| CSV export `products_export_2026-11-05_05-58-25.csv` | live shop (CDN shop `167000`) | products, categories, brands, images |
| Stock export | live shop, can be exported | only if CSV `Stock_Level` is not reliable |
| API (key in `.env`) | **demo store**, 8 products | not used for the import |

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

## Content model
| CSV | Craft |
|---|---|
| Category_1 / Category_2 (`collection in stock` tree) | Structure section `categories` |
| `SOLD` | no category: stock 0, product stays live |
| `TOP 50` | lightswitch `featured` |
| `PREVIEW` | lightswitch `vipPreview` |
| Brand | Channel section `brands` |
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

- Store currency: EUR; units cm / kg (`config/commerce.php`)
- Missing translation (FR) → EN text, product disabled on that site
- Categories + brands matched on EN title (CSV has no IDs for them)
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
- Source: CSV export, not API (API key = demo store)
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

## Open questions (dev)
- [ ] Templates for products, categories, brands (not built yet)
- Client questions → `backlog.md`

## Progress
- [x] Phase 0 — setup
- [x] Phase 1 — CSV export received (2026-11-05)
- [x] Phase 2 — content model: 3 sites + new fields
- [ ] Phase 3 — CSV importer built; tested with 20 products (full import later)
- [ ] Phase 4 — redirects
- [ ] Phase 5 — delta sync
- [ ] Phase 6 — checks
