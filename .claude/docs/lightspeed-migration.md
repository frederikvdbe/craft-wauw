# Lightspeed → Craft Commerce migration

## Approach
- Custom Craft module with console commands
- **Goal: port all data to Craft, as close as possible to the Lightspeed architecture** (1:1: same categories, statuses, groups, …)
- Source = **API JSON export** of the live shop (`lightspeed/extract`); no CSVs anymore
- Every record stores `lightspeedId` → import can run again without duplicates
- Delta sync before go-live = new extract + import again
- Client questions + open work: `.claude/docs/backlog.md`

## Current state
- Craft 5.12, DDEV (PHP 8.5, Node 24)
- Merged with the boilerplate repo `frederikvdbe/craft-wauw` (2026-11-07): Vite, SCSS, templates, `modules/site`, sections home / pages / header / footer, SEO plugin `core-seo-geo`. Our sites, store, order statuses and user / address layouts kept
- CP: `/launchpad`; volume Uploads = `web/uploads/files` (apart from `brands/`, `products/`)
- Pending users are never purged (`purgePendingUsersDuration` removed: imported customers are pending)
- Craft **Pro** (customer accounts; Solo = 1 user) → client needs a Pro license before go-live
- System time zone: Europe/Brussels
- Commerce 5.7 installed (1 store, dummy gateway)
- Sites: `en` (primary, `/`), `nl` (`/nl`), `fr` (`/fr`)
- API export in `storage/lightspeed/api/` (git-ignored: personal data)
- Old **demo store** data (8 products, 13 entries, 50 assets) still in Craft → delete

## Commands
| Command | What |
|---|---|
| `ddev craft lightspeed/sync [--sample]` | **queue**: full extract, then import (`--sample` = sample import); asks to confirm |
| `ddev craft lightspeed/sync/extract [<name>,…]` | queue: extract only (all, or some files) |
| `ddev craft lightspeed/sync/import [<part>,…] [--sample]` | queue: import only (all, or some parts) |
| `ddev craft lightspeed/setup` | content model, tax, order statuses, user groups (safe to run again; tax + statuses are DB-only → run on every env) |
| `ddev craft lightspeed/extract` | **full API export** → `storage/lightspeed/api/`; asks to confirm; ~10k requests ≈ 3.5 h |
| `ddev craft lightspeed/extract --sample[=N]` | first 25 (or N) items per list → `storage/lightspeed/api-sample/` (not used by the import) |
| `ddev craft lightspeed/extract <name>` | one file, e.g. `products-images`; `extract/counts` = records per list. Per-item lists resume after a crash |
| `ddev craft lightspeed/import --sample` | **sample import** from the full export (use this during dev) |
| `ddev craft lightspeed/import` | **full import**; asks to confirm |
| `ddev craft lightspeed/import/<categories\|brands\|products\|customers\|orders> [--sample]` | one part. `import orders` (space) runs **everything** |

- **Control panel: Utilities → Lightspeed sync** = same as `lightspeed/sync`: pick extract files (with last download date), import parts, sample on/off → Start (asks to confirm full extract / full import). Permission `utility:lightspeed-sync`
- Queue: progress in Utilities → Queue Manager. All steps are queued at once (visible in Queue Manager), priority 100, 101, … → they run in order (needs 1 runner). A step stops when an earlier step is still in the queue (failed, or reserved after a killed process) → retry that one first. Data loads when a step runs, not when it is queued. Only 1 sync at a time
- Queue runner: DDEV daemon `craft-queue` (`.ddev/config.queue.yaml`); control panel requests don't run jobs. Production needs a runner too (cron / supervisor)
- API is **read-only**: the client blocks every non-GET request (live shop)
- Rate limit: 300 / 5 min, 3,000 / hour, 12,000 / day → the client waits automatically
- Extract: languages from the API (`nl` default, `en`, `fr`); lists with texts per language in `{lang}/`, the rest once (images: once)
- Import is safe to run again; existing orders are skipped
- Nothing is deleted: records are matched on `lightspeedId` and updated; records deleted in Lightspeed stay in Craft
- **Sample orders keep their broken email** on a full import (existing orders are skipped) → delete sample orders before the full import
- Sample (`Sample.php`): 1 order per case (every Lightspeed status, linked lines, deleted product, guest, GDPR, VAT, discount, shipping + fee, credit note), 1 customer with several orders; their products + 1 product per case (every category, price on request, variants, brand, no FR, hidden, VAT, video); their customers + 3 VIP members without orders + 1 customer per Lightspeed group
- Sample breaks all email addresses (`…@gmail.comx`) → no mail can reach real customers
- Code: `modules/lightspeed/`

## Shop data (API, 2026-11-06)
| List | Count |
|---|---|
| Products / variants | 1,726 / 1,746 |
| Categories | 15 |
| Brands | 19 (18 after merge) |
| Customers | 24,920 (24,870 registered, 50 guest) |
| Customer groups | 4: Gallery's, Architecten, CLUBWAUW MEMBERS, Gewaardeerde klanten (47 links) |
| Orders / invoices | 1,234 / 1,801 (432 credit notes) |
| Shipments / returns / quotes | 1,234 / 3 / 242 |
| Tags | 6,260 (11,815 links) |
| Text pages / redirects / filters | 21 / 1 / 1 ("In stock") |
| Newsletter subscriptions | 13 (Klaviyo is the source) |

### Categories
| Category | Products | Visible |
|---|---|---|
| collection in stock › Meubilair, Exclusive selection, Decoratie, Verlichting, Vintage Audio, Te huur | 101 › 51, 32, 20, 18, 11, 5 | yes |
| TOP 50 | 131 | yes |
| SOLD | 966 | yes |
| New arrivals | 32 | yes |
| SALES 2026 | 40 | no |
| PREVIEW | 6 | no |
| HET HUIS VAN WAUW, Bezoek CLUBWAUW, LOOKBOOK | 0 | no |
- 217 products in 2–5 categories; 656 without category

### Products
- FR missing for 103 products → EN text, disabled on the FR site
- Visible: 1,535; hidden: 191
- Tax: margin scheme 0% (1,675 variants), 21% (71), 6% "kunstwerk" (0)
- Weight / size: `weightValue` (kg), `sizeX/Y/ZValue` (cm)

### Price hack
- `priceIncl` = 0, 1 or 2 → hack: **sold** or **price on request** (1,601 variants)
- Real price is then in `oldPriceIncl`
- Negative stock exists → set to 0

### Custom data fields
| Field | Content | Filled |
|---|---|---|
| `data01` | video / image URL | 5 |
| `data02` | glo3d 3D viewer URL | 45 |
| `data03` | **extra image** (Lightspeed image limit); also some mp4 / YouTube / glo3d | 1,375 |

### Customers
- `type`: `registered` (24,870; 593 with orders) / `guest` (50; 48 with orders)
- 9 order customers not in the API (GDPR deleted)
- Registered without orders (24,277) = mostly VIP members (sign up for previews)
- Country = object with ISO code; empty phone = `+`

### Orders
| Status | Orders |
|---|---|
| `completed_picked_up` | 493 |
| `completed_shipped` | 401 |
| `cancelled` | 278 |
| `processing_awaiting_payment` | 36 |
| `processing_ready_for_pickup` | 20 |
| `processing_awaiting_pickup` | 3 |
| `processing_awaiting_shipment` | 2 |
| `withdrawn` | 1 |
- Payment status: paid / not_paid / cancelled; shipment status: shipped / not_shipped / cancelled
- Total = Σ line `priceIncl` + `shipmentPriceIncl` + `paymentPriceIncl`; line `priceIncl` = line total, discount included
- Lines have product + variant ID
- 29 orders of GDPR-deleted customers (email = "GDPR deleted")

## Phases
| # | Phase | Output |
|---|---|---|
| 0 | Setup: install Commerce | ✓ |
| 1 | Source data: API export | JSON |
| 2 | Content model: sites per language, sections, fields, product type | Project config |
| 3 | Import: categories → brands → products → customers → orders | Craft data |
| 4 | Redirects: old Lightspeed URLs → new Craft URLs | Keep SEO |
| 5 | Delta sync before go-live (new extract) | Fresh data |
| 6 | Checks: counts, spot checks, VAT | Sign-off |

## Content model
| Lightspeed (API) | Craft |
|---|---|
| Category (all 15, tree) | Structure section `categories`, 1:1: parent, order, visible → enabled |
| Category title / fulltitle / description / content / url | title / `fullTitle` / `shortDescription` / `richText` / slug (per site) |
| Product ↔ categories | field `productCategories` (all, in Lightspeed order) |
| Brand | Channel section `brands`; `lightspeedId`, slug per language, visibility |
| Brand image (9 of 19) | field `brandImage`, volume `brandImages` (`uploads/brands`) |
| Brand text | none in Lightspeed (`content` empty) |
| Duplicate brands (same title) | merged into 1 entry (keeps the one with image, else oldest ID) |
| Product | Commerce product type `products`, variants on |
| Product / variant `id` | `lightspeedId` on product / variant |
| title / fulltitle | title / `fullTitle` (per site) |
| description / content | `shortDescription` / `richText` (per site) |
| `url` | slug (per site) |
| Variant title | variant title ("Default" → product title) |
| `sku` | SKU, or `LS-{variantId}` |
| `priceIncl` ≤ 2 | lightswitch `priceOnRequest`, base price = `oldPriceIncl` |
| `oldPriceIncl` > `priceIncl` | price = old price, promotional price = price |
| Tax 0% / 6% / 21% | tax category `marginScheme` / `reduced` / `standard` (incl. in price) |
| `stockLevel` | stock (min 0) |
| size / weight | native variant length / width / height / weight |
| `ean` | field `ean` |
| `isVisible` false | disabled |
| Images + `data03` images | `productImages`, folder per product: `{Craft ID}-{EN slug}` |
| `data02` + glo3d / hotspot viewers in `data01/03` | link field `viewerUrl` |
| mp4 / YouTube in `data01/03` | link field `video` |
| Meta title / description (metafields) | SEO plugin `core-seo-geo` — not yet |
| Customer `registered` | **pending** user, group **VIP Members** (also default group for new sign-ups) |
| Customer `guest` | **inactive** user (no login, no email), group **Guests** |
| Customer with orders | group **Customers** (accounts + guests) |
| Lightspeed customer groups | Craft user groups, 1:1 (created when missing) |
| Customer `id` | user `lightspeedId` (match: ID, then email) |
| isCompany, CoC number, birthDate, gender, isConfirmed | user fields `customerType`, `cocNumber`, `birthdate`, `gender`, `emailConfirmed` |
| Billing / shipping address | user addresses (1 when equal) + Commerce primary billing / shipping |
| Company / VAT number / address name | address organization / tax ID / full name |
| Phone / mobile | address fields `phone` / `mobile` |
| Language | user preference language |
| Region | dropped by Craft for BE (no region in BE address format) |
| Order | completed Commerce order, `reference` = `ORD…`, no recalculation / stock change / emails |
| Order status (8) | Craft order statuses, 1:1; default = Awaiting payment (like Lightspeed) |
| Payment status `paid` | successful transaction via hidden gateway `lightspeed`; `datePaid` = order date |
| Shipment status | order field `shipmentStatus` |
| Order language | order site + language |
| Order line | snapshot (title, price, qty); linked to the variant when it still exists, else custom line item |
| Shipping / payment fee / VAT | adjustments `shipping` / `paymentFee` / `tax` (included) |
| Invoices, credit notes, payment method, discount code, comment, memo | order fields |
| GDPR-deleted order | order without customer, email and addresses |

- Store currency: EUR; units cm / kg (`config/commerce.php`)
- Text fallback: site language → EN → NL

## Not imported (yet)
| Lightspeed data | Note |
|---|---|
| Tags (6,260) | messy; decide later |
| Text pages (21) | decide later |
| Product SEO metafields | with SEOmatic |
| Related products (`products-relations`) | decide later |
| Shipments (tracking codes), returns, quotes | decide later |
| Discounts (9), shipping methods (26) | set up by hand in Commerce? |
| Newsletter | Klaviyo is the source |

## Architecture notes
- Craft: categories + tags deprecated in 5.9 → entries (Structure/Channel) = official way ([blog](https://craftcms.com/blog/2025-in-review))
- Commerce: product types = field layout / checkout flow, not browsing → 1 type
- Unique items: sold = keep live, stock 0 (keeps SEO)
- Plugins to consider: SEOmatic, Retour (redirects), Sprig (filters, search, cart)

## Lightspeed API facts (reference)
- Docs: https://developers.lightspeedhq.com/ecom/introduction/introduction/
- Auth: Basic auth, key + secret; EU1 `api.webshopapp.com`
- Rate limit headers: `X-RateLimit-Remaining` / `-Reset` = `5min/hour/day`
- Customer passwords: not exportable → "set new password" email

## Decisions
- Import mirrors Lightspeed 1:1 (categories, order statuses, customer groups)
- Source: API JSON export; CSV importers removed (2026-11-06)
- 1 product type `products`, variants on
- Categories → structure section; brands → channel section
- Price hack (≤ 2) → `priceOnRequest` + real price from `oldPriceIncl`
- `data03` = extra product images → add to `productImages`
- Tax: 0% = margin scheme; 6% = reduced (created, not used yet)
- Images: restricted upload location `{Craft ID}-{primary site slug}` (title / slug differ per site for 1,640 of 1,726 products → always the EN slug, else the folder moves on every site save)
- Sites: EN primary + NL + FR
- URLs: `products/{slug}` + 301 redirects from old URLs
- GraphQL disabled (`enableGql(false)`)
- Customers → pending users; at go-live 1 "set your password" email to all accounts
- Keep all customer data (birthdate, gender, groups, …)
- Newsletter: not imported. Client exports subscribers to Klaviyo and deletes them in Lightspeed → Klaviyo is the source
- Orders → real Commerce orders (customers see their past orders); lines = snapshot + link to variant
- Dev: `--sample`; full import + full extract ask for confirmation

## In progress (2026-11-06)
- **Committed (2026-11-07):** import ported to the API export (`ApiData`, `Sample`, all importers, setup: 6% tax, 8 order statuses, `shipmentStatus`, category `shortDescription`; `featured` / `vipPreview` removed). Not tested yet
- Full extract: all lists done (17:05–17:20); stopped because Docker went down. **Still missing: the 8 per-item lists** (`products-images`, `-relations`, `-metafields`, `-filtervalues`, `-attributes`, `filters-values`, `orders-products`, `invoices-items`; ≈ 8k requests)
- Per-item lists are now resumable: progress saved every 50 items in `{name}.partial.json`, restart skips done items
- **Queue-based sync built; tested with small lists** (2026-11-06 18:27, daemon running): `Extractor` + `Sync` services (shared by console, CP and jobs), `jobs/ExtractJob` + `ImportJob` (batched), console + web `SyncController`, utility `SyncUtility` (`templates/_utility.twig`), DDEV daemon; module now bootstrapped (`config/app.php`); importers have 1 public method per record
- Next:
  1. Queue the missing per-item lists in Utilities → Lightspeed sync, or `ddev craft lightspeed/sync/extract products-images,products-relations,products-metafields,products-filtervalues,products-attributes,filters-values,orders-products,invoices-items`
  2. When the extract is done: check order totals on all orders, run `import --sample`, verify, then ask to commit
- Open questions for the user: is CLUBWAUW MEMBERS = VIP Members (merge)? Which not-imported data to port (proposal: related products, SEO meta, text pages, shipment tracking codes yes; tags, returns, quotes no; discounts + shipping methods by hand)

## Open questions (dev)
- [ ] Templates for products, categories, brands (not built yet)
- [ ] Not-imported data above: decide per list
- Client questions → `backlog.md`

## Progress
- [x] Phase 0 — setup
- [x] Phase 1 — API export (2026-11-06)
- [x] Phase 2 — content model: 3 sites + fields
- [ ] Phase 3 — importer ported to the API export; test with `--sample`
- [ ] Phase 4 — redirects
- [ ] Phase 5 — delta sync
- [ ] Phase 6 — checks
