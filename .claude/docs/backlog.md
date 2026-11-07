# Backlog

## Questions for the client
| # | Topic | Question | Answer |
|---|---|---|---|
| 1 | Rental | "Te huur" (5 products): how does renting work? Online booking/payment, or only a contact form? | |
| 2 | VIP preview | "PREVIEW" products: who may see them? Logged-in VIP customers only? | |
| 3 | TOP 50 | What is "TOP 50"? A highlight on the home page, or a separate page? | |
| 4 | Price on request | Show the old price on sold products, or only "Sold"? | |
| 5 | VAT | Margin scheme invoice text ("Bijzondere regeling – Gebruikte goederen") → confirm with the accountant | |
| 6 | Languages | NL, EN and FR all needed at launch? | EN = main language |
| 7 | Tags | Tags are not used on the new site. OK? | |
| 10 | Customers | API key for the live shop (read customers), to import all ~25k accounts? | ✓ live key in `.env` |
| 9 | Craft Pro | Customer accounts need a Craft Pro license (~$299 + yearly updates). OK? | |
| 8 | Sold items | Keep all 850+ sold items online (archive, SEO) or hide part of them? | |

## Dev backlog
- [x] Switch importer from API JSON to CSV
- [ ] Delete demo store data (API import)
- [x] Content model: 3 sites, new fields
- [x] API extract: full read-only JSON export of the live shop
- [ ] Run the full API export (~3.5 h)
- [ ] SEO meta title / description (API metafields) → `core-seo-geo`
- [ ] Currency per visitor: Europe = EUR, USA = USD, … (Commerce: 1 base currency per store → payment currencies, or a store per region?)
- [ ] Redirects old → new URLs
- [ ] Templates: product, category, brand
- [ ] All customers (~25k): import from the full customer CSV / API JSON; account vs guest via API `type`
- [ ] Go-live: send "set your password" email to all pending customers
- [x] Order importer
- [ ] Order templates: custom line items have no purchasable (guard `lineItem.type`)
- [ ] Full import must run interactive (it asks for confirmation)
