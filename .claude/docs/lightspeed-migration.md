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
- [ ] Which data must move? (products, categories, customers, orders, reviews, blog, text pages, redirects)
- [ ] Order history: full import, archive, or skip?
- [ ] Which languages / storefronts?
- [ ] Size: products, variants, customers, orders?
- [ ] API key + secret available?
- [ ] Custom module (recommended) or Feed Me?

## Decisions
_None yet._

## Progress
- [ ] Phase 0 — setup
- [ ] Phase 1 — extract
- [ ] Phase 2 — content model
- [ ] Phase 3 — import
- [ ] Phase 4 — redirects
- [ ] Phase 5 — delta sync
- [ ] Phase 6 — checks
