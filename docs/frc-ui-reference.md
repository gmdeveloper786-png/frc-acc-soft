# FRC Management Software - Existing UI Design Reference

## 1. Purpose and Scope

This document records the UI/UX that is verifiably implemented in the current FRC Management Software project. It is a reference for the existing product, not a specification for a future application.

The extracted visual identity may later be reused by a related FRC product. That reuse should be based on the source-backed values and patterns documented here. This file does not define features, navigation, business rules, or screens for any other product.

This reference was prepared from the current Blade templates, CSS, JavaScript, PHP view data, configuration, assets, and dependency files. Runtime database values are identified as dynamic rather than guessed.

## 2. Verified Frontend Stack

| Area | Existing implementation | Source |
|---|---|---|
| Server-rendered UI | Laravel 10 Blade templates | `composer.json`, `resources/views/` |
| Main UI framework | Bootstrap 5.3.2, loaded from cdnjs in the authenticated layout | `resources/views/layouts/app.blade.php` |
| Icons | Font Awesome 6.5.0, loaded from cdnjs | `resources/views/layouts/app.blade.php`, `resources/views/layouts/auth.blade.php` |
| Primary stylesheet | Custom FRC stylesheet | `public/css/frc.css` |
| Receipt stylesheet | Shared screen, print, and PDF receipt rules | `public/css/receipt.css` |
| Heading font | Poppins, weights 400, 500, 600, 700, 800 | `public/css/frc.css` Google Fonts import |
| Body font | Inter, weights 400, 500, 600 | `public/css/frc.css` Google Fonts import |
| Charts | Chart.js 4.4.1, loaded from jsDelivr on dashboards that include the chart script partial | `resources/views/dashboard/partials/chart-scripts.blade.php` |
| PDF rendering | `barryvdh/laravel-dompdf` 3.1-compatible dependency | `composer.json` |
| Build entry points | Vite 5 with `resources/css/app.css` and `resources/js/app.js` | `vite.config.js`, `package.json` |

`resources/css/app.css` is currently empty. `resources/js/app.js` imports `resources/js/bootstrap.js`, which initializes Axios only. The authenticated layout does not call `@vite`; it directly loads `public/css/frc.css`, CDN Bootstrap, Font Awesome, and the public FRC JavaScript files.

## 3. Existing Visual Identity

The current interface uses teal and navy as its core brand colors. White surfaces sit on a very pale cyan page background, with pale cyan borders and restrained shadows. Poppins is used for headings and Inter for body content.

The main application shell consists of:

- A fixed teal-to-navy gradient sidebar.
- A fixed white topbar with a pale border and subtle shadow.
- A pale page background.
- White content cards with 16px corners.
- Teal primary actions and navy secondary actions.
- Font Awesome icons throughout navigation, cards, buttons, alerts, and status UI.

The visual identity is centralized primarily in `public/css/frc.css`, although several Blade views also contain page-local `<style>` blocks and inline styles.

## 4. Actual CSS Design Tokens

All tokens below are declared in the `:root` selector of `public/css/frc.css`.

| Token / CSS Variable | Actual Value | Usage | Source File |
|---|---:|---|---|
| `--teal` | `#16acac` | Primary buttons, icons, focus borders, active indicators | `public/css/frc.css` |
| `--teal-light` | `#e8f9f9` | Soft teal backgrounds, selected options, icon tiles | `public/css/frc.css` |
| `--teal-dark` | `#0e8a8a` | Teal hover state and emphasized teal text | `public/css/frc.css` |
| `--navy` | `#11517c` | Headings, structural text, navy buttons and icons | `public/css/frc.css` |
| `--navy-light` | `#e8f0f8` | Navy icon-tile background | `public/css/frc.css` |
| `--navy-dark` | `#0a3a5c` | Sidebar gradient endpoint and navy hover state | `public/css/frc.css` |
| `--bg-light` | `#f7ffff` | Page background, table headers, pale panels | `public/css/frc.css` |
| `--border-soft` | `#d8f1f1` | Card, control, divider, and dropdown borders | `public/css/frc.css` |
| `--white` | `#ffffff` | Cards, topbar, controls, menu surfaces | `public/css/frc.css` |
| `--text-dark` | `#1a2b3c` | Default body and table text | `public/css/frc.css` |
| `--text-muted` | `#6c7a8d` | Helper text, labels, metadata | `public/css/frc.css` |
| `--success` | `#28a745` | Success icons, alerts, paid/completed emphasis | `public/css/frc.css` |
| `--warning` | `#ffc107` | Warning alert border | `public/css/frc.css` |
| `--danger` | `#dc3545` | Destructive buttons, errors, cancelled/rejected emphasis | `public/css/frc.css` |
| `--sidebar-w` | `260px` | Expanded desktop sidebar width | `public/css/frc.css` |
| `--sidebar-w-collapsed` | `95px` | Collapsed desktop sidebar width | `public/css/frc.css` |
| `--sidebar-current-w` | `var(--sidebar-w)` | Live shell offset; switches to collapsed width | `public/css/frc.css` |
| `--topbar-h` | `64px` | Desktop topbar height and main-content offset | `public/css/frc.css` |
| `--radius-card` | `16px` | `.card-frc`, `.stat-card`, and `.form-section` | `public/css/frc.css` |
| `--radius-btn` | `10px` | FRC button controls | `public/css/frc.css` |
| `--shadow-card` | `0 2px 16px rgba(17,81,124,.09)` | Cards and dropdowns | `public/css/frc.css` |
| `--shadow-soft` | `0 1px 6px rgba(22,172,172,.12)` | Secondary panels | `public/css/frc.css` |
| `--scrollbar-size` | `8px` | Global and scrollable-panel scrollbars | `public/css/frc.css` |
| `--scrollbar-track` | `var(--bg-light)` | Scrollbar track | `public/css/frc.css` |
| `--scrollbar-thumb` | `rgba(17, 81, 124, .22)` | Scrollbar thumb | `public/css/frc.css` |
| `--scrollbar-thumb-hover` | `rgba(22, 172, 172, .48)` | Scrollbar hover state | `public/css/frc.css` |
| `--scrollbar-thumb-border` | `var(--bg-light)` | Scrollbar thumb inset border | `public/css/frc.css` |

The sidebar scroll area locally overrides the scrollbar size to `5px`, uses a transparent track, and changes the thumb to translucent white.

## 5. Typography

### Existing font rules

- `body`: Inter, `15px`, `line-height: 1.6`, color `var(--text-dark)`.
- `h1` through `h6`: Poppins, weight `600`.
- Topbar `.page-title`: Poppins `18px`, weight `600`, navy.
- Card `.card-title-frc`: Poppins `16px`, weight `600`, navy.
- Stat `.stat-value`: Poppins `22px`, weight `700`, navy, line-height `1`.
- Stat `.stat-label`: Inter `13px`, muted.
- Table headings: Poppins `13px`, weight `600`, navy.
- Table cells: Inter `14px`, dark text.
- Form labels: `13px`, weight `500`.
- Form controls: Inter `14px`.
- Status badges: Poppins `12px`, weight `500`.
- Helper, validation, and form text: commonly `12px`.

Source: base, card, table, form, badge, and responsive selectors in `public/css/frc.css`.

The stylesheet imports Poppins and Inter from Google Fonts. Its declared fallback is `sans-serif`; a named system-font stack is not defined in the current token set.

## 6. Main Blade Layouts

### Authenticated application layout

`resources/views/layouts/app.blade.php` provides the shared application shell:

- Dynamic document title using `@yield('title')` and `$frc['organisation_name']`.
- Fixed role-selected sidebar.
- Fixed topbar.
- Notification bell and dropdown, except for the `approval_discount` role.
- User avatar and profile/logout dropdown.
- Global success, warning, error, and validation alerts.
- Main content through `@yield('content')`.
- `@stack('styles')` in the head and `@stack('scripts')` at the end of the body.

The role is read from `auth()->user()?->role?->name`, then one of the role sidebar partials is included.

### Authentication layout

`resources/views/layouts/auth.blade.php` is a smaller standalone shell:

- Dynamic title using `$frc['organisation_name']`.
- Font Awesome 6.5.0.
- `public/css/frc.css`.
- `.auth-wrapper` around page content.
- `public/js/frc-core.js` for password toggles and shared behaviors.

Bootstrap CSS and Bootstrap JavaScript are not loaded by the authentication layout.

## 7. Actual Layout Measurements

| Element | Desktop Value | Responsive Value if applicable | Source |
|---|---|---|---|
| Sidebar width | `260px` via `--sidebar-w` | Off-canvas at `<= 991.98px`; width `260px`, max `min(88vw, 300px)` | `.frc-sidebar`, `public/css/frc.css` |
| Collapsed sidebar | `95px` via `--sidebar-w-collapsed` | Collapse mode is disabled on mobile by resetting current width | `html.sidebar-collapsed`, `public/css/frc.css` |
| Sidebar nav row | Padding `10px 16px`, margin `2px 10px`, radius `10px` | Collapsed: padding `11px 8px`, margin `3px 8px` | `.frc-sidebar .nav-link`, `public/css/frc.css` |
| Sidebar collapse button | `28px` square, circular, right `-13px`, top `112px` | Hidden at `<= 991.98px` | `.sidebar-collapse-btn`, `public/css/frc.css` |
| Topbar height | `64px` via `--topbar-h` | Auto height with minimum `64px`, padding `10px 12px` at `<= 991.98px` | `.frc-topbar`, `public/css/frc.css` |
| Topbar horizontal padding | `24px` | `12px` at `<= 991.98px` | `.frc-topbar`, `public/css/frc.css` |
| Main content offset | Left `var(--sidebar-current-w)`, top `64px` | Left `0`, top `72px` at `<= 991.98px` | `.frc-main`, `public/css/frc.css` |
| Main content padding | `28px 28px 40px` | `20px 16px 32px` at `<= 991.98px`; `16px` at `<= 576px` | `.frc-main`, `public/css/frc.css` |
| Standard card | Radius `16px`, padding `24px`, 1px border | Padding `15px` at `<= 576px`; panel card padding `16px` | `.card-frc`, `public/css/frc.css` |
| Card header | Bottom margin `20px`, bottom padding `16px` | Margin `14px`, padding `12px` at `<= 576px` | `.card-header-frc`, `public/css/frc.css` |
| Stat card | Padding `22px 24px`, gap `18px`, radius `16px` | Role dashboard rules commonly use `14px 16px`, gap `12px` at `<= 767.98px` | `.stat-card`, dashboard responsive rules in `public/css/frc.css` |
| Stat icon | `54px` square, radius `14px`, icon `22px` | Commonly `44px`, radius `12px`, icon `18px` at `<= 767.98px` | `.stat-card .stat-icon`, `public/css/frc.css` |
| FRC button radius | `10px` | Same token; action groups may stack on mobile | `--radius-btn`, button selectors in `public/css/frc.css` |
| Form input radius | `10px` | Same; form action buttons stack at `<= 575.98px` | `.form-frc .form-control`, `public/css/frc.css` |
| Form control padding | `10px 14px` | No global mobile override found | `.form-frc` controls, `public/css/frc.css` |
| Standard table minimum | `640px` inside FRC/table-responsive wrappers | Horizontal scrolling | `.frc-table-wrap .table-frc`, `public/css/frc.css` |
| Wide table minimum | `960px` | Horizontal scrolling | `.frc-table-wrap--wide .table-frc`, `public/css/frc.css` |
| Notification dropdown | Width `min(100vw - 24px, 360px)`, list max-height `300px` | Fixed to left/right `12px`, top `70px` at `<= 991.98px` | `.frc-notif-menu`, `public/css/frc.css` |
| Auth card | Max-width `960px`, min-height `580px`, radius `24px` | Max-width `600px`, auto height, radius `20px` at `<= 900px` | `.auth-card`, `public/css/frc.css` |
| Registration card | Max-width `740px` | Single-column form and radius `20px` at `<= 640px` | `.auth-card-register`, `public/css/frc.css` |
| Receipt card | Max-width `600px`, padding `28px`, radius `12px` | Print/PDF max-width `170mm`, padding `24px`, radius `8px` | `public/css/receipt.css` |

## 8. Sidebar Behavior

### Desktop

The sidebar is fixed to the left and uses this gradient:

```css
linear-gradient(160deg, var(--teal-dark) 0%, var(--teal-dark) 40%, var(--navy-dark) 100%)
```

Expanded navigation shows section headings, Font Awesome icons, labels, and optional count badges. Hovered links use `rgba(255,255,255,.12)`. Active links use `rgba(22,172,172,.28)` plus an inset white border.

The collapse state is stored in `localStorage` under `frc.sidebar.collapsed`. `public/js/frc-sidebar-pref.js` applies it before paint, and `public/js/frc-layout.js` manages the toggle.

Collapsed navigation centers icons, hides section labels and link text, shrinks the brand logo block to `54px`, and converts count badges into compact positioned indicators.

### Mobile

At `<= 991.98px`, the sidebar is translated off-screen and opened with `.open`. A navy translucent backdrop (`rgba(10, 58, 92, .5)`) is shown. The drawer closes from the backdrop or Escape key. Body scrolling is disabled while it is open.

## 9. Existing Navigation Structure

The following is the actual current hierarchy. Shared staff items are permission-driven through `resources/views/partials/sidebar/staff-modules.blade.php`.

### Super Admin

- Dashboard
- Updates: Notifications
- Users: Staff Users when permitted; Roles & Permissions for Super Admin
- Children: Pending Approvals, All Children, Register Child according to permissions
- Configuration: Therapists, Present Complaints, Services, Branches according to permissions
- Clinical: Assessments, Enrollments, High Discount according to permissions
- System: Settings when permitted
- Finance: Payments, Manual Payment, Verify Payments, Finance Reports according to permissions
- Logout

### Admin

- Dashboard
- Updates: Notifications, Profile
- The shared staff module groups that the authenticated Admin is permitted to access
- Logout

### Finance

- Dashboard
- Updates: Notifications, Profile
- The shared staff module groups that the Finance user is permitted to access
- Logout

### Therapist

- Dashboard
- Work: Assessments, Session Scheduled, Quarterly Reports, Parent Feedback
- Assigned Children when `view_assigned_children` is permitted
- Any shared staff modules explicitly granted through permissions
- Updates: Notifications
- Account: Profile
- Logout

### Child / Parent account

- Dashboard
- Updates: Notifications
- Info: Assessments, Enrollment, Schedule, Parent Feedback, Profile
- Any shared staff modules if permissions were ever granted to the account
- Payments: Upload Fee Slip, Payment History
- Logout

### Approval Discount role

- Updates: Profile
- Shared staff modules exposed by assigned permissions, including High Discount where applicable
- Logout

Pending child, high-discount, and payment-verification counts are rendered dynamically in sidebar badges. Menu visibility is determined with `hasPermission(...)` and role helpers, not with CSS.

## 10. Header / Topbar

The existing topbar is fixed and white, with a 1px `var(--border-soft)` bottom border and shadow `0 1px 8px rgba(17,81,124,.06)`.

- On dashboard routes, the title displays the authenticated user's `full_name`.
- On other routes, the title comes from `@yield('page-title', 'Dashboard')`.
- A mobile hamburger button appears through Bootstrap's `d-lg-none` utility.
- The right side contains notifications and a circular `36px` teal user avatar.
- The user dropdown displays full name plus either child email or role display name.
- Profile routing changes by role; logout is always available.
- No breadcrumb component is present in the inspected main layout.
- No global branch selector or fiscal-year selector exists in the topbar.

## 11. Existing Dashboard Patterns

### Super Admin dashboard

`resources/views/dashboard/super-admin.blade.php` uses:

- Eight responsive stat cards for children, approvals, payment verification, high discount, therapists, assessments, enrollments, and completed sessions.
- Six shared fee summary stat cards.
- Three recent-list cards: children, enrollments, and payments.
- A vertical Quick Actions card.
- A Finance Overview card containing a fee doughnut and monthly bar chart.
- The shared Insights & Trends chart grid.

### Admin dashboard

`resources/views/dashboard/admin.blade.php` uses the same eight-card summary pattern, shared fee summary, Quick Actions, Finance Overview, and Insights & Trends. Card links and quick actions are conditionally available through permissions.

### Finance dashboard

`resources/views/dashboard/finance.blade.php` uses:

- Three top summary cards: expected fees, collected, and pending.
- Four payment-channel/verification cards: cash, online/bank, pending verification amount, and pending verification count.
- Permission-aware Quick Actions.
- A recent payments table.
- Finance Overview and Insights & Trends when finance-report/payment permissions allow them.

### Therapist dashboard

`resources/views/dashboard/therapist.blade.php` uses nine linked stat cards:

- Today's, upcoming, completed, and cancelled assessments.
- Today's, upcoming, completed, and cancelled sessions.
- Assigned children.

Below the cards are two equal-width panels for today's assessments and today's sessions. They use compact bordered list items, status badges, and view/child actions. No charts are present.

### Child / Parent dashboard

`resources/views/dashboard/child.blade.php` uses eight linked stat cards for completed sessions, assessments, enrollment, pending slips, expected fees, paid amount, pending amount, and pending verification amount. Two equal-width panels show recent assessments and recent payments as tables. No charts are present.

### Shared dashboard grid behavior

- Bootstrap rows use `g-3` gutters.
- Super Admin and Admin stat grids use `col-12 col-sm-6 col-md-4 col-xl-3`.
- Finance top cards use three columns at `md`; the second finance row reaches four columns at `xl`.
- Therapist cards use `col-12 col-sm-6 col-xl-4`.
- Child cards use `col-12 col-sm-6 col-xl-3`.
- At `<= 767.98px`, dashboard CSS reduces stat-card padding and icon dimensions.

## 12. Authentication Screens

### Login

The desktop login is a two-panel `.auth-card`:

- Left: branded teal/navy gradient, logo on white, organization name, hardcoded tagline, and hardcoded service pills.
- Right: welcome heading, email/password form, password visibility toggle, remember checkbox, forgot-password link, sign-in button, and conditional registration link.

At `<= 900px`, the left panel is hidden and a compact logo is displayed in the right panel.

### Child registration

The registration screen is a `740px` maximum-width card with a gradient header and four-step form:

1. Personal information.
2. Branch and contact information.
3. Present complaints.
4. Notes and submission.

The form uses two columns above `640px`, then one column. Step circles are `34px`, reduced to `30px` on small screens. Client-side code handles step validation, age calculation, password checks, selected complaint styling, and the conditional Other field.

### Forgot, reset, and closed registration

- Forgot password uses a centered card with an inline `500px` maximum width.
- Reset password uses `.auth-card--single`, whose CSS maximum is `460px`, with page-level inline sizing also present.
- Registration closed uses the single-card pattern, warning icon, dynamic organization/contact values, and a login action.

## 13. Notification UI

### Topbar inbox

- Notification count is dynamic and displays `9+` above nine unread items.
- The dropdown displays the latest five notifications.
- Icons vary by notification module: payments, children, users, assessments, enrollments, sessions, or generic bell.
- Unread rows have a teal left border and tinted background.
- The footer provides Mark all as read and View all notifications actions.
- The `approval_discount` role does not receive the topbar notification dropdown in the main layout.

### Notification page

`resources/views/notifications/index.blade.php` implements:

- All, Unread, and Read tabs.
- Mark all read and Clear read actions.
- Per-page select-all and bulk Read, Unread, and Delete actions.
- Card-like notification rows with checkboxes, message, relative time, and row actions.
- Empty state.
- Laravel pagination.
- A centered Bootstrap confirmation modal for bulk delete.

The page-local style caps content at `52rem`. Notification rows use a `12px` radius and switch from a three-column grid to a two-row mobile layout at `<= 576px`.

## 14. Existing Cards and Panels

### Standard card

`.card-frc` uses:

- White background.
- `16px` radius from `--radius-card`.
- `1px solid var(--border-soft)`.
- `var(--shadow-card)`.
- `24px` padding.

`.card-header-frc` is a flex row with a pale bottom divider. Titles use `.card-title-frc`.

### Stat card

`.stat-card` uses the same card surface and radius, with an icon tile, value, label, and optional hint. Hover translates the card upward by `2px` and changes the shadow to `0 6px 24px rgba(17,81,124,.13)`. Linked cards have a visible teal focus outline.

Existing icon tones are teal, navy, green, orange, red, and purple. Purple is implemented with hardcoded `#f3e8ff` and `#7c3aed`; orange uses `#fff8e6` and `#e08000`.

### Other existing panel types

- `.form-section`: white, 16px radius, pale border, 24px padding.
- Dashboard chart cards: standard FRC cards with fixed chart-height wrappers.
- Therapist dashboard session items: `12px` radius, pale background, pale border.
- Empty state: centered flex column, `60px 20px` padding, optional `64px` pale icon.
- Document cards and upload zones: reusable classes in `public/css/frc.css`, rendered by child-document partials.

## 15. Existing Tables

`.table-frc` is the shared table pattern:

- Width `100%`, collapsed borders.
- Header background `var(--bg-light)`.
- Header padding `12px 16px`, Poppins `13px`, navy, bottom border `2px solid var(--border-soft)`.
- Body padding `12px 16px`, Inter `14px`, bottom border `1px solid #f0f4f8`.
- Hover background `var(--bg-light)`.
- Last row has no bottom border.
- Status badges remain on one line.
- `.text-amount` prevents amount wrapping, but it does not globally right-align amounts.

`.frc-table-wrap` and `.table-responsive` provide horizontal scrolling. Standard wrapped tables have a `640px` minimum width; `.frc-table-wrap--wide` uses `960px`.

Actual list pages place search, select, date, status, and branch filters above the table using Bootstrap grid utilities and `.form-frc`. Representative examples include Assessments, Children, Enrollments, Payments, Therapists, Services, Branches, Notifications, Therapist Sessions, and Finance Reports.

## 16. Existing Pagination

Laravel pagination is configured globally with `Paginator::useBootstrapFive()` in `app/Providers/AppServiceProvider.php`.

The shared `.frc-list-pagination` wrapper provides:

- A top border and pale white gradient background.
- Padding `1rem 1.25rem 1.35rem`.
- Wrapped, centered page links with `6px` gaps.
- `8px` page-link radius.
- `2.35rem` minimum width and height.
- Teal active page, teal-light hover, and a visible teal focus ring.
- Mobile previous/next layout from Laravel's Bootstrap 5 pagination markup.

Several pages also duplicate similar pagination CSS in local `<style>` blocks, including assessments, children, services, branches, therapists, payments, finance reports, and notifications.

## 17. Existing Forms

`.form-frc` is the main form wrapper. Verified values are:

- Direct child sections: `20px` bottom margin.
- Labels: block, `13px`, weight `500`, `8px` bottom margin.
- Inputs/selects/textareas: `10px 14px` padding, `1.5px` pale border, `10px` radius, Inter `14px`, white background.
- Focus: teal border with `0 0 0 3px rgba(22,172,172,.12)`.
- Invalid control: danger border.
- Inline validation: danger `12px`, `4px` top margin.
- Help text: muted `12px`, `4px` top margin.

Existing form implementations include:

- Text, email, password, number, date, time, search, file, select, textarea, checkbox, and radio controls.
- Bootstrap grid-based multi-column forms.
- `.form-section` grouping and `.form-section-title` dividers.
- `.frc-form-actions`, which displays two actions side-by-side and stacks them at `<= 575.98px`.
- Searchable approved-child selectors with selected chips and AJAX results.
- Multi-file child document upload with drag/drop state and removal controls.
- Multi-block Assessment and Enrollment creation partials.
- Schema-driven assessment response fields supporting text, textarea, select, radio, checkbox groups, read-only displays, nested fields, and conditional fields.
- Parent feedback rating tables and form partial.
- Daily progress note form partial used by therapist session completion.

Required markers are currently placed in Blade markup, commonly as an inline red `*`; there is no single global required-marker component.

## 18. Existing Buttons

| Class | Actual Styling | Existing Use |
|---|---|---|
| `.btn-teal` | Teal fill, white text, radius `10px`, padding `8px 20px`, Poppins `14px` | Main create, save, submit, verify, and navigation actions |
| `.btn-navy` | Navy fill, white text, radius `10px`, padding `8px 20px`, Poppins `13px` | Secondary prominent actions |
| `.btn-outline-teal` | Transparent, `1.5px` teal border, padding `7px 18px`, Poppins `13px` | Back, cancel, view, filter, secondary actions |
| `.btn-outline-danger` | Transparent, `1.5px` danger border, padding `7px 18px`, Poppins `13px` | Delete and destructive actions |

`.btn-teal` hover uses `--teal-dark` and moves up `1px`. Disabled teal buttons use `#9ecaca`; the enrollment submit button has an additional disabled override of `#8fb8b8`.

The project also uses Bootstrap button classes in notifications and modals, such as `btn-sm`, `btn-outline-secondary`, and `btn-danger`. Icon-only actions use Font Awesome and generally include `title` or `aria-label` attributes in the inspected implementations.

`public/js/frc-core.js` handles native `window.confirm()` prompts for controls with `data-confirm`; it does not provide a custom global confirmation component.

## 19. Existing Badges and Statuses

`.badge-status` is an inline-flex pill with `4px 12px` padding, `50px` radius, Poppins `12px`, and weight `500`.

| CSS Class | Background | Text | Current Meaning Seen in UI |
|---|---|---|---|
| `.badge-pending` | `#fff8e1` | `#b07d00` | Pending queues/counts |
| `.badge-approved` | `#e8f8ee` | `#1a7a3a` | Approved/positive |
| `.badge-active` | `#e8f9f9` | `--teal-dark` | Active |
| `.badge-rejected` | `#fde8e8` | `#b02a2a` | Rejected |
| `.badge-draft` | `#f0f0f0` | `#555` | Draft; also used for some inactive displays |
| `.badge-publish` | `--teal-light` | `--navy` | Published/scheduled or neutral rating display |
| `.badge-completed` | `#e8f8ee` | `#1a7a3a` | Completed |
| `.badge-cancelled` | `#fde8e8` | `#b02a2a` | Cancelled |
| `.badge-unpaid` | `#fde8e8` | `#b02a2a` | Unpaid |
| `.badge-partial_paid` | `#fff8e1` | `#b07d00` | Partial Paid |
| `.badge-fully_paid` | `#e8f8ee` | `#1a7a3a` | Fully Paid |
| `.badge-pending_verification` | `#fff0e8` | `#8a4000` | Pending Verification |
| `.badge-paid` | `#e8f8ee` | `#1a7a3a` | Paid |
| `.badge-overdue` | `#fde8e8` | `#b02a2a` | Overdue |
| `.badge-pending_super_admin_approval` | `#f0e8ff` | `#5a1a8a` | High-discount approval queue |
| `.badge-session-scheduled` | `#e3f2fd` | `#1565c0` | Scheduled session |
| `.badge-session-in-progress` | `#ede7f6` | `#5e35b1` | Session in progress |
| `.badge-session-completed` | `#e8f8ee` | `#1a7a3a` | Completed session |
| `.badge-session-cancelled` | `#fde8e8` | `#b02a2a` | Cancelled session |
| `.badge-session-no-show` | `#fff3e0` | `#c66900` | No-show session |

Status values originate from backend records. Examples of backend label conversion include Assessment `publish` displayed as Scheduled and Payment `partial_paid` displayed as Partial Paid.

## 20. Alerts, Empty States, and Confirmations

`.alert-frc` uses `14px 18px` padding, `12px` radius, `14px` text, an icon, and a 4px semantic left border.

- Success: background `#e8f8ee`, text `#1a7a3a`.
- Error: background `#fde8e8`, text `#b02a2a`.
- Warning: background `#fff8e1`, text `#b07d00`.
- Info: teal-light background and teal-dark text.

The main layout renders flash success/warning/error alerts and a validation summary. Individual forms also render inline invalid feedback.

`.empty-state` is used on dashboards and list pages. Other pages, especially Notifications, define their own page-local empty-state styling.

## 21. Existing Modals

The project uses Bootstrap modal markup for existing modal workflows. Verified examples include:

- Notification bulk delete confirmation.
- Child approval/rejection workflows.
- Payment rejection/details workflows.
- Enrollment/high-discount workflows.
- Therapist session completion and cancellation.
- Group session actions.

Most standard action modals use `modal-dialog-centered`. Several modal contents use an inline `14px` radius, pale border, and hidden overflow. The notification modal defines a local `12px` radius.

The therapist completion modal uses `modal-lg` and dedicated FRC selectors. Its content radius is `14px`; height is capped to the dynamic viewport. At `<= 575.98px`, it becomes full-screen with zero radius and vertically stacked full-width footer buttons. Between `576px` and `991.98px`, its maximum width is `min(640px, calc(100% - 1.5rem))`.

## 22. Existing Charts

Charts are present only in the Super Admin, Admin, and Finance dashboards through shared partials. Chart.js 4.4.1 is loaded from jsDelivr, not from npm.

Existing chart set:

- Fee status: doughnut, paid versus pending.
- Monthly finance: grouped bars, collected versus enrolled fees.
- Growth trends: lines for enrollments and new children.
- Payment channels: doughnut, cash versus online/bank.
- Children by status: interactive doughnut.
- Collected by branch: vertical bar chart.
- Programmes by enrollments: vertical bar chart.
- Enrollments by status: interactive doughnut.
- Collection rate: line chart.
- Needs attention: bar chart.

Chart defaults use Inter and `#6b7c93`. Primary chart colors include `#16acac`, `#11517c`, `#28a745`, and `#dc3545`. Dashboard year selectors render values from `$stats['chart_years']` and auto-submit on change.

Chart wrappers use explicit heights: tall and horizontal/vertical bar wrappers are `240px`, compact doughnuts are `118px`, medium doughnuts and line charts are `200px`, and alert charts are `180px`. At the dashboard `<= 767.98px` breakpoint, tall wrappers reduce to `220px` and bar wrappers reduce to `200px`.

## 23. Responsive Patterns

The project relies on Bootstrap breakpoints plus component-specific CSS breakpoints.

### Main shell

- `<= 991.98px`: sidebar becomes a drawer, topbar spans full width, page title drops to `15px`, and main content loses the sidebar offset.
- `<= 576px`: main padding becomes `16px`, standard cards become denser, and many list-page headers/actions stack.

### Forms and tables

- Bootstrap grid classes reduce multi-column forms naturally.
- `.frc-form-actions` stacks at `<= 575.98px`.
- Tables preserve a minimum width and scroll horizontally.
- Filter rows commonly wrap at `<= 991.98px` and become full width at `<= 575.98px`.

### Dashboards

- Dashboard stat cards reduce padding, gap, icon size, and label size at `<= 767.98px`.
- Finance overview changes from side-by-side panels to a vertical layout at `<= 991.98px`.
- Dashboard chart heights are reduced on small screens.
- Therapist session rows and child dashboard panel headers stack at `<= 767.98px`.

### Authentication

- Login changes from two panels to one at `<= 900px`.
- Registration switches to one form column at `<= 640px`.

Additional component-specific breakpoints at `480px`, `576px`, `768px`, `992px`, and `1200px` are present for progress-note grids, dashboard charts, document grids, and detail layouts.

## 24. Print, Receipt, and PDF Layouts

### Payment receipt

`public/css/receipt.css` is shared between the browser receipt and DomPDF receipt.

- Screen card: maximum `600px`, padding `28px`, radius `12px`, FRC border and shadow.
- Font: DejaVu Sans through two `@font-face` CDN declarations.
- Header: centered, `2px solid #16acac` divider.
- Organization name: `20px`, navy.
- Receipt amount: `24px`, teal.
- Detail values: right-aligned.
- Page: A4 portrait with `12mm` margins.
- Print/PDF shell: maximum `170mm`, padding `24px`, radius `8px`, no shadow.
- Print hides sidebar, backdrop, topbar, `.no-print`, and global alerts.

Receipt organization/contact data and receipt values are backend-driven through `$frc` and `$receipt`.

### Finance report print view

`resources/views/reports/finance-print.blade.php` defines an A4 landscape browser-print page. It uses Segoe UI/system fonts, `12px` base text, a compact `11px` table, and hides `.no-print` controls.

### Finance report PDF view

`resources/views/reports/finance-pdf.blade.php` uses DejaVu Sans with `9px` body text and `8px` table text. It uses a fixed-layout table and right-aligned `.num` cells.

## 25. Logo and UI Assets

The primary visible image asset is:

- `public/images/logo.png`
- PNG, 2971 x 1465 source pixels.
- File size observed: 204,314 bytes.
- Contains the teal/navy FRC mark, "Healing Hearts, Building Hope" tagline, and "Faizan Rehabilitation Centre" name.

It is used in:

- Sidebar brand area: full available width with maximum height `42px`; collapsed maximum height `34px`.
- Login desktop brand panel: maximum width `240px`, image maximum height `110px`.
- Login mobile brand: image height `52px`, reduced to `46px` at `<= 576px`.
- Registration header: image height `48px`, reduced to `44px` at `<= 640px`.

The image path is static. Its `alt` text uses dynamic organization values with hardcoded fallbacks.

No local custom icon set was found. UI icons come from Font Awesome 6.5.0.

## 26. Actual Reusable Blade Files

| Component/Partial | File Path | Purpose |
|---|---|---|
| Authenticated shell | `resources/views/layouts/app.blade.php` | Sidebar, topbar, notifications, user menu, alerts, content shell |
| Authentication shell | `resources/views/layouts/auth.blade.php` | Standalone auth wrapper and shared auth assets |
| Super Admin sidebar | `resources/views/partials/sidebar/super-admin.blade.php` | Super Admin dashboard and shared staff menu entry point |
| Admin sidebar | `resources/views/partials/sidebar/admin.blade.php` | Admin dashboard, notifications, profile, shared staff modules |
| Finance sidebar | `resources/views/partials/sidebar/finance.blade.php` | Finance dashboard, notifications, profile, shared staff modules |
| Therapist sidebar | `resources/views/partials/sidebar/therapist.blade.php` | Therapist work, updates, account navigation |
| Child sidebar | `resources/views/partials/sidebar/child.blade.php` | Child/parent information and payment navigation |
| Approval Discount sidebar | `resources/views/partials/sidebar/approval-discount.blade.php` | Profile and permission-driven shared modules |
| Shared staff navigation | `resources/views/partials/sidebar/staff-modules.blade.php` | Permission-aware Users, Children, Configuration, Clinical, System, Finance groups |
| Fee summary cards | `resources/views/dashboard/partials/fee-summary-cards.blade.php` | Six reusable fee/payment stat cards |
| Finance overview | `resources/views/dashboard/partials/finance-overview.blade.php` | Fee doughnut and monthly finance bar panel |
| Analytics grid | `resources/views/dashboard/partials/analytics-grid.blade.php` | Shared dashboard insight charts |
| Chart year selector | `resources/views/dashboard/partials/chart-year-select.blade.php` | Dynamic auto-submit year filter |
| Chart scripts | `resources/views/dashboard/partials/chart-scripts.blade.php` | Chart.js loading, data payload, and chart initialization |
| Approved child checkbox field | `resources/views/partials/approved-child-checkboxes-field.blade.php` | Searchable multi-child picker shell |
| Approved child picker script | `resources/views/partials/approved-child-picker-scripts.blade.php` | AJAX child search, chips, selected state, hidden inputs |
| Child documents | `resources/views/partials/child-documents.blade.php` | Existing document list and upload UI |
| Child document scripts | `resources/views/partials/child-documents-scripts.blade.php` | Removal state, file queue, and drag/drop behavior |
| Daily progress form | `resources/views/partials/daily-progress-note-form.blade.php` | Therapist completion-note form fields |
| Daily progress display | `resources/views/partials/daily-progress-note-display.blade.php` | Read-only progress-note output |
| Assessment block | `resources/views/assessments/partials/assessment-block.blade.php` | Repeatable assessment detail group |
| Assessment multi-block script | `resources/views/assessments/partials/assessment-multi-block-scripts.blade.php` | Add/remove assessment blocks and synchronize fields |
| Enrollment block | `resources/views/enrollments/partials/enrollment-block.blade.php` | Repeatable enrollment container |
| Enrollment block fields | `resources/views/enrollments/partials/enrollment-block-fields.blade.php` | Branch, service, therapist, schedule, and fee fields |
| Enrollment form scripts | `resources/views/enrollments/partials/form-scripts.blade.php` | Enrollment dynamic behavior entry point |
| Parent feedback form | `resources/views/child/parent-feedback/_form.blade.php` | Shared create/edit feedback form |
| Assessment response cards | `resources/views/assessment-forms/_cards.blade.php` | Existing assessment form presentation wrapper |
| Assessment fields | `resources/views/assessment-forms/partials/fields.blade.php` | Schema-driven editable fields |
| Assessment response fields | `resources/views/assessment-forms/partials/response-fields.blade.php` | Schema-driven read-only response output |
| Receipt content | `resources/views/payments/partials/receipt-content.blade.php` | Shared browser/PDF receipt body |

These are Blade includes/partials rather than a formal `resources/views/components` component library.

## 27. Existing JavaScript Behavior

### Public shared scripts

- `public/js/frc-sidebar-pref.js`: applies the saved collapsed-sidebar state before first paint.
- `public/js/frc-layout.js`: desktop collapse, mobile sidebar drawer, backdrop, resize, and Escape-key behavior.
- `public/js/frc-core.js`: password visibility, print triggers, native confirmation prompts, CSRF header injection for `fetch`, empty-date filter cleanup, and auto-submit filters.
- `public/js/frc-staff-user-form.js`: shows and requires the branch field only when the selected staff role is Admin.

### Vite resources

- `resources/js/app.js`: imports `resources/js/bootstrap.js`.
- `resources/js/bootstrap.js`: imports Axios and sets `X-Requested-With`; Laravel Echo/Pusher setup remains commented out.

Many feature-specific behaviors are inline Blade scripts pushed through `@stack('scripts')`, including registration steps, charts, child pickers, assessment/enrollment repeatable blocks, notifications, modals, and document uploads.

## 28. Bootstrap and Font Awesome Loading

The authenticated layout loads:

1. Font Awesome 6.5.0 CSS.
2. `public/css/frc.css`.
3. Page-specific pushed styles.
4. Near the end of `<body>`, Bootstrap 5.3.2 CSS.
5. Bootstrap 5.3.2 bundle JavaScript.
6. `public/js/frc-core.js` and `public/js/frc-layout.js`.
7. Page-specific pushed scripts.

The authentication layout loads Font Awesome and `frc.css`, but not Bootstrap.

Bootstrap pagination is selected server-side with `Paginator::useBootstrapFive()`.

## 29. Existing Hardcoded vs Dynamic Values

### Verified dynamic values

- Organization name, short name, tagline, receipt logo text, contact details, bank details, payment instructions, high-discount threshold, child-registration setting, and registration message come from `SettingService` and the settings table, with defaults from `SettingKeys`.
- `$frc` is shared with every view by `AppServiceProvider`.
- User name, role display name, profile route, and avatar initial come from the authenticated user.
- Sidebar sections and actions are permission-driven through `hasPermission(...)` and role checks.
- Pending sidebar counts come from database queries/services.
- Branch names, cities, children, therapists, services, assessment data, enrollment data, payments, sessions, and feedback are rendered from backend objects/collections.
- Dashboard values and chart datasets are supplied through `$stats` or `$portal`.
- Chart year options come from `$stats['chart_years']`.
- Notification count/content comes from `UserNotificationService`.
- Status values come from records, with labels generated by model methods or string formatting.
- Receipt values are supplied in `$receipt`; organization/contact details use `$frc`.

### Verified hardcoded values

- Design colors, dimensions, typography sizes, radii, and shadows in CSS.
- Font Awesome, Bootstrap, Chart.js, Google Fonts, and DejaVu font CDN URLs and versions.
- `public/images/logo.png` path.
- Default organization strings such as `Faizan Rehabilitation Centre`, `Faizan Rehab`, `Management System`, and `FRC` in `SettingKeys` and view fallbacks.
- Login tagline `Care, Support, Growth & Hope` and four service pills.
- UI labels, action text, empty-state messages, and dashboard card names.
- Currency prefix `PKR` in helpers/views/chart formatting and receipt/report output.
- Month abbreviations Jan through Dec in the chart script.
- Chart colors and many page-local fallback colors.
- Status visual mappings in `frc.css` and status label mappings in models.
- Responsive breakpoints and component dimensions.
- Default city pricing JSON in `SettingKeys`; current runtime city names and prices may differ because settings are stored in the database.

### Values not determinable from source alone

The source does not reveal current runtime database values for organization settings, branch names, branch cities, assigned permissions, chart years, dashboard totals, notifications, users, services, or statuses of specific records. Source code shows how those values are loaded and displayed, but active values depend on the database and authenticated user.

## 30. Existing UI Inconsistencies

Only source-verified inconsistencies are listed here.

1. The primary root teal is `#16acac`, but many page-local fallback declarations and the finance print/PDF views use `#008080`.
2. Root success/danger values are `#28a745` and `#dc3545`; finance print/PDF views instead use `#16a34a` and `#dc2626`.
3. The main root border/background tokens are `#d8f1f1` and `#f7ffff`; page-local styles frequently use fallback values such as `#e5eaf0`, `#e2e8f0`, `#f6f9fc`, or `#f8fafc`.
4. The authenticated layout loads custom `frc.css` before Bootstrap CSS, while Bootstrap CSS is inserted near the end of the body. Therefore, Bootstrap is later in the cascade for selectors with comparable specificity.
5. The auth layout does not load Bootstrap, while auth views use Bootstrap utility classes such as `mb-3`, `me-1`, and `d-block`. Those utility definitions are not provided by the auth layout's loaded files unless separately defined in `frc.css`; the inspected FRC stylesheet does not define the full Bootstrap utility set.
6. Pagination styling is partly centralized in `.frc-list-pagination` and partly repeated in page-local `<style>` blocks.
7. Several common visual values are centralized as CSS variables, but Blade and chart scripts also repeat raw hex colors and dimensions inline.
8. Standard modal surface styling is not fully centralized: the notification modal uses a local `12px` radius, several action modals use inline `14px`, and the therapist completion modal has dedicated CSS.
9. Payment model labels include `refunded`, but no `.badge-refunded` rule was found in the central status badge CSS.
10. Inactive staff is displayed with `.badge-draft` in the inspected staff list rather than a dedicated `.badge-inactive` style.
11. `.table-frc td.text-amount` prevents wrapping but does not set `text-align: right`; amount alignment therefore varies by page-specific markup.

This section documents current differences only. It does not change or prescribe fixes for them.

## 31. Reference Boundary

This file describes the current FRC Management Software UI. It does not define any unimplemented module, screen, navigation item, component, or business workflow. When this visual identity is reused elsewhere, the actual values in Sections 3 through 7 may serve as the reference, while the other product's features and business rules remain separate.

## 32. Actual UI Source Files Inspected

The inspection covered 74 distinct project files. Important inspected sources include:

### Core layout and styling

- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/auth.blade.php`
- `public/css/frc.css`
- `public/css/receipt.css`
- `resources/css/app.css`
- `public/images/logo.png`

### Role dashboards and dashboard partials

- `resources/views/dashboard/super-admin.blade.php`
- `resources/views/dashboard/admin.blade.php`
- `resources/views/dashboard/finance.blade.php`
- `resources/views/dashboard/therapist.blade.php`
- `resources/views/dashboard/child.blade.php`
- `resources/views/dashboard/partials/fee-summary-cards.blade.php`
- `resources/views/dashboard/partials/finance-overview.blade.php`
- `resources/views/dashboard/partials/analytics-grid.blade.php`
- `resources/views/dashboard/partials/chart-year-select.blade.php`
- `resources/views/dashboard/partials/chart-scripts.blade.php`

### Sidebar navigation

- `resources/views/partials/sidebar/super-admin.blade.php`
- `resources/views/partials/sidebar/admin.blade.php`
- `resources/views/partials/sidebar/finance.blade.php`
- `resources/views/partials/sidebar/therapist.blade.php`
- `resources/views/partials/sidebar/child.blade.php`
- `resources/views/partials/sidebar/approval-discount.blade.php`
- `resources/views/partials/sidebar/configuration.blade.php`
- `resources/views/partials/sidebar/staff-modules.blade.php`

### Authentication and notifications

- `resources/views/auth/login.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/forgot-password.blade.php`
- `resources/views/auth/reset-password.blade.php`
- `resources/views/auth/register-closed.blade.php`
- `resources/views/notifications/index.blade.php`

### Representative cards, tables, forms, modals, and pagination

- `resources/views/assessments/index.blade.php`
- `resources/views/assessments/create.blade.php`
- `resources/views/children/index.blade.php`
- `resources/views/children/create.blade.php`
- `resources/views/payments/index.blade.php`
- `resources/views/therapist/sessions/index.blade.php`
- `resources/views/settings/edit.blade.php`

### Print and PDF

- `resources/views/payments/receipt.blade.php`
- `resources/views/payments/receipt-pdf.blade.php`
- `resources/views/payments/partials/receipt-content.blade.php`
- `resources/views/reports/finance-print.blade.php`
- `resources/views/reports/finance-pdf.blade.php`

### Reusable view partials

- `resources/views/partials/approved-child-checkboxes-field.blade.php`
- `resources/views/partials/approved-child-picker-scripts.blade.php`
- `resources/views/partials/child-documents.blade.php`
- `resources/views/partials/child-documents-scripts.blade.php`
- `resources/views/partials/daily-progress-note-form.blade.php`
- `resources/views/partials/daily-progress-note-display.blade.php`
- `resources/views/assessments/partials/assessment-block.blade.php`
- `resources/views/assessments/partials/assessment-multi-block-scripts.blade.php`
- `resources/views/enrollments/partials/enrollment-block.blade.php`
- `resources/views/enrollments/partials/enrollment-block-fields.blade.php`
- `resources/views/enrollments/partials/form-scripts.blade.php`
- `resources/views/child/parent-feedback/_form.blade.php`
- `resources/views/assessment-forms/_cards.blade.php`
- `resources/views/assessment-forms/partials/fields.blade.php`
- `resources/views/assessment-forms/partials/response-fields.blade.php`

### JavaScript, build, dependencies, settings, and status sources

- `public/js/frc-core.js`
- `public/js/frc-layout.js`
- `public/js/frc-sidebar-pref.js`
- `public/js/frc-staff-user-form.js`
- `resources/js/app.js`
- `resources/js/bootstrap.js`
- `vite.config.js`
- `package.json`
- `composer.json`
- `app/Providers/AppServiceProvider.php`
- `app/Services/SettingService.php`
- `app/Support/SettingKeys.php`
- `app/Models/Assessment.php`
- `app/Models/Enrollment.php`
- `app/Models/Payment.php`
- `config/app.php`
- `design.md`
