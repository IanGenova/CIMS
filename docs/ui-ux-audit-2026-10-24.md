# CIMS UI/UX audit — 24 October 2026

## Scope and method

Reviewed the authenticated local dashboard, product list, product creation, product import dialog, POS, goods receiving, product sales analytics, report navigation, and the public shop at `127.0.0.1:8000`. Compared rendered screens and accessibility trees with relevant Vue and PHP source. Browser error log for the sampled session was empty. No records or settings were changed.

This is a targeted interface and reporting audit. It is not a complete functional, security, accessibility, mobile-device, payment-gateway, or authenticated Hostinger production audit. Findings below distinguish observed defects from design improvements.

## Fix first: reporting correctness and accessibility

| ID | Priority | Finding and evidence | Required outcome |
| --- | --- | --- | --- |
| A01 | High | The dashboard's monthly “Top Selling Products” is ordered by sale-line count (`ReportController::report_dashboard`, `orderBy('count', 'desc')`), while the yearly list is ordered by sales value. On the sampled dashboard, a product with ₱10,296.16 appears before one with ₱21,196.80. The table gives quantity and total without telling users what “top” means. | Choose and label one ranking metric, preferably completed sales value; sort the monthly table by that metric. If quantity or number of transactions is needed, offer an explicit alternate view. Add a regression test for the ordering. |
| A02 | High | Dashboard stock alerts use `qte <= stock_alert` and `take(5)` without a severity order (`ReportController::report_dashboard`). The visible list includes `0` quantity / `0` alert-threshold rows and does not show whether a row is out of stock or below a configured reorder point. The first five database rows may hide a more urgent shortage. | Separate **Out of stock** from **Below reorder point**. Decide and document whether threshold `0` means disabled. Order by urgency before limiting; offer a link to the full filtered alert report. Test ordering and zero-threshold behavior. |
| A03 | High | Several visually labeled controls have no accessible name in the browser accessibility tree: product name, code, purchase cost and selling price, and the POS warehouse selector. In `Add_product.vue`, `b-form-group` labels do not use `label-for`; the POS warehouse `v-select` has no associated label. | Give every form field a stable ID and programmatic label, including custom select components; verify with keyboard-only and screen-reader checks. Preserve visible labels and validation messages. |
| A04 | High | Product Sales Analytics has 18 products with sales, but all 18 have insufficient history, leaving “Most projected sales,” “Least projected sales,” and “Trending products” empty. It still displays a line-fit percentage for a product with only one selling week. With the current data, the module offers little decision support. | Keep forecasts withheld when data is insufficient. Show a clearly labeled **historical sales leaders/laggards** view as a fallback. Hide fit statistics until the sample meets the forecast threshold; show how many more weeks are needed. |

## Improve core staff workflows

| ID | Priority | Finding and evidence | Required outcome |
| --- | --- | --- | --- |
| A05 | Medium | The primary navigation uses tall icon tiles for 13 sections. Opening Reports adds a long secondary column with 19 links. At the sampled 1265 px viewport, the two columns use roughly 340 px, compressing dashboard cards and tables, while users must scroll the navigation to reach Reports. | Use a compact, labeled navigation pattern with collapsible groups, visible current location, and reliable keyboard navigation. Keep content wide enough for common tables at laptop widths. |
| A06 | Medium | POS places customer selection above the required warehouse. Before a warehouse is chosen, the product area is mostly blank and still presents search, category, brand, and pagination controls. The basket has a small explanatory row, but the large area does not guide the cashier. | Make warehouse selection the clear first step. Show a prominent empty state in the product area, defer irrelevant controls, and focus the scanner/search field after the warehouse is ready. Retain the existing auto-selection when only one warehouse is allowed. |
| A07 | Medium | The product list has nine columns plus selection and three icon-only row actions. At the sampled laptop width, text and action targets are small and scanning a row is difficult. “Quantity” gives no visible indication of warehouse scope or whether reserved/QC-held stock is included. | Prioritize name/code/price/available stock, move secondary fields into details or an optional column chooser, enlarge row actions, and define the quantity basis in the header or help text. Check the table at laptop and phone widths. |
| A08 | Medium | Product creation groups fields better than before, but the default form still exposes identity, pricing, three units, stock alert, tax, discount, tracking, QC, shop, serial, warranty, notes, and image upload in one long page. The save action is at the bottom. | Keep common fields in the initial path; place advanced tracking, tax and online options in expandable sections. Keep save/cancel reachable and show a clear validation summary when submission fails. |
| A09 | Medium | The dashboard devotes a large chart to a week with one visible purchase bar; annual and monthly top-product sections repeat similar information. The dashboard lacks an obvious warehouse/branch scope selector for a multi-location business. | Adapt charts to sparse data, replace low-information plots with operational cards when appropriate, and show the active period and location scope. Link cards and alerts to the underlying records. |
| A10 | Medium | The shop uses a modern product-card style, while admin and POS use different typography, spacing, iconography, button treatments and density. Moving between them feels like moving between separate products. | Define shared type, color, spacing, button, field, table and empty-state components. Apply them across admin, POS and shop without sacrificing the faster cashier layout. |

## Lower-priority polish

| ID | Priority | Finding and evidence | Required outcome |
| --- | --- | --- | --- |
| A11 | Low | Direct navigation briefly showed a blank white loading shell with a small spinner before each authenticated screen rendered. It resolved quickly in this session; a prolonged loading failure was not reproduced. | Use a branded loading skeleton and a visible retry/error state if startup fails. Measure route startup on the deployed site before treating this as a performance defect. |
| A12 | Low | Several screens repeat “Products | Product List” or similar breadcrumbs immediately beside the page heading, and the footer occupies substantial space on short forms. | Simplify redundant breadcrumbs and reduce footer prominence in work screens. |

## Recommended sequence

1. Fix A01–A03 first: trustworthy rankings and alerts, then accessible input labels.
2. Improve A04 and A06 so analytics and POS remain useful in the common low-data and pre-selection states.
3. Redesign navigation and the product list (A05, A07), then simplify product creation (A08).
4. Refresh the dashboard and unify the design system (A09, A10); finish with loading and breadcrumb polish (A11, A12).

## Verification needed after fixes

- Test dashboard ranking with products whose sale-line count and sales value disagree.
- Test alert ordering with out-of-stock, low-stock, zero-threshold, and multiple-warehouse cases.
- Run keyboard and screen-reader checks on product create/edit and POS.
- Test the main workflows at laptop and phone widths with representative product and order data.
- Recheck authenticated Hostinger pages separately; this audit used the local instance.

## Local implementation follow-up

The October 24 local changes address A01–A12 in code. Monthly leaders now use completed sales value; dashboard and full-report stock alerts share the same urgency, zero-threshold, and warehouse-access rules. Sparse analytics shows historical results without calling them forecasts. Product fields have programmatic labels, product editing has an advanced section and a reachable save bar, POS guides the user through location selection, and the product list prioritizes the fields needed for scanning. Navigation groups, dashboard states, shared interface tokens, startup retry feedback, breadcrumbs, and footer density were updated.

The dashboard explicitly shows its all-permitted-locations scope and links to the warehouse report for a selected-location breakdown. Authenticated visual and keyboard checks on laptop and phone remain to be completed because the user elected to continue without signing in for this verification session. The Hostinger deployment also needs its own visual check after these local changes are published.
