# FRC Accounting UI System

## Milestone 2 administration patterns

- Administration, FRC Master Data and Settings navigation is permission-aware and hidden when unauthorized.
- Lists use responsive filter bars, paginated accounting tables, status badges and explicit empty states.
- Permission controls come from database permission groups; Blade templates do not contain a static permission list.
- Branch and service pages identify themselves as read-only FRC mirrors and expose only authorized sync actions.
- Settings use grouped cards and a single save action. Integration credentials are excluded from the ordinary settings UI.

This application follows the established FRC visual identity while consolidating it into reusable layouts and centralized assets.

## Design tokens

- Primary teal: `#16acac`
- Navy: `#11517c`
- Light background: `#f7ffff`
- Soft border: `#d8f1f1`
- Heading family: Poppins
- Body family: Inter
- Expanded sidebar: 260px
- Collapsed sidebar: 95px
- Topbar: 64px
- Card radius: 16px
- Input and button radius: 10px

Tokens live in `resources/css/app.css`. New screens must reuse these variables and shared Blade layouts rather than adding page-specific copies.

## Layouts and behavior

- `layouts.guest` provides a focused authentication shell with no patient registration or public sign-up links.
- `layouts.app` provides the responsive application sidebar, topbar, user menu, alerts, page header, and content container.
- Desktop users can collapse the sidebar; the preference is stored in browser local storage.
- Tablet and mobile users receive an off-canvas navigation drawer with backdrop and Escape-key dismissal.
- Navigation for future modules is visibly disabled and is not rendered as a clickable route.

## Components

- KPI cards show an explicit unavailable state until validated financial data exists.
- Surface cards, context strips, badges, alerts, forms, tables, and empty states share centralized styling.
- Motion is restrained and disabled when the user prefers reduced motion.

## Data presentation rules

- Never show an unavailable financial value as zero.
- Never invent transactions, balances, chart data, recent activity, branches, services, or fiscal periods.
- Amounts will use the configured currency and fixed-precision values when the accounting engine exists.
- Branch and service options will come from synchronized FRC API reference data, not Blade or JavaScript constants.
