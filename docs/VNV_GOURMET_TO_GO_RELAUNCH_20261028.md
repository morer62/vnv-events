# VNV Gourmet To Go relaunch — 2026-10-28

## Delivered

- Replaced the retired dinner catalog with a compact, mobile-first menu for appetizer boxes and family desserts.
- Added Small (30 pieces / choose 2), Medium (45 / choose 3), and Large (60 / choose 4) appetizer boxes. Every selected variety contributes 15 pieces.
- Added server-validated multi-selection. The selected varieties remain attached to the cart line, checkout, order, confirmation email, customer order view, and staff order views.
- Added Ensaimada, Brazo Gitano, Cream Profiteroles, Pastry Cream Tartlets, and Chocolate Truffles. Boca Abierta remains a non-public draft until its format and price are confirmed.
- Made office pickup the default free fulfillment method at 10258 NW 47th St, Sunrise, FL 33351. Delivery remains optional and uses the existing quote flow.
- Set the configured pickup and delivery minimum to $65 and the notice period to 24 hours.
- Retained historical products and orders; the old Gourmet To Go / Gourmet Express catalog is deactivated, not deleted.
- Added Gemini-generated catalog photography and appetizer-choice thumbnails under `public/assets/images/gourmet-to-go/`.

## Deployment

1. Pull the committed code with `git pull --ff-only origin main`.
2. Run `db/20261028_gourmet_to_go_relaunch.sql` once against the production database.
3. Open `/gourmet-to-go`, then test one pickup and one delivery checkout before announcing the relaunch.

The SQL is designed to be safe on the first production run and preserves historical products by setting them inactive. Do not use it as a routine catalog reset after live carts or orders begin referencing the new variation IDs.

## Editorial assumption

The supplied brief did not specify Chocolate Truffles pricing. They were created with the same 24-piece ($49) and 48-piece ($89) structure as Profiteroles and Tartlets. Confirm or adjust those two prices before launch if needed.

## Excluded by request

No videos were generated or edited. CapCut and Seedance were not used.
