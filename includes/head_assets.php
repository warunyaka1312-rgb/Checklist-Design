<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(APP_BASE_URL) ?>/assets/favicon.svg?v=1">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  // "Charcoal x Indigo x Slate" theme — re-skin of the die_management design
  // system onto this app's existing Tailwind + Alpine stack (colors/type/
  // shape only; layout and interactivity are unchanged).
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          navy: {
            950: '#0B1220',
            900: '#0F172A',
            800: '#172033',
            700: '#233150',
          },
          steel: {
            50: '#F8FAFC',
            100: '#E9ECF1',
            200: '#E8EDF3',
            300: '#AEB8C8',
            400: '#8993A6',
            500: '#64748B',
            600: '#4E5872',
            700: '#3B4459',
            800: '#2A3244',
            900: '#1C2230',
          },
          accent: {
            50: '#EFF6FF',
            100: '#DBEAFE',
            500: '#1D4ED8',
            600: '#1E40AF',
            700: '#1E3A8A',
          },
          warn: {
            50: '#FFFBEB',
            500: '#F59E0B',
            600: '#B45309',
          },
          status: {
            successBg: '#ECFDF3', successText: '#15803D',
            infoBg: '#EFF6FF', infoText: '#1D4ED8',
            warnBg: '#FFFBEB', warnText: '#B45309',
            mutedBg: '#F1F5F9', mutedText: '#64748B',
            dangerBg: '#FEF2F2', dangerText: '#B91C1C',
          },
        },
        fontFamily: {
          sans: ['"IBM Plex Sans Thai"', 'sans-serif'],
        },
        boxShadow: {
          soft: '0 8px 24px rgba(15, 23, 42, 0.04)',
          softLg: '0 20px 45px rgba(15, 23, 42, 0.12)',
        },
        borderRadius: {
          // Bumps the bare `rounded` utility (buttons, inputs, small chips —
          // used all over every page) from Tailwind's 4px default to the
          // spec's "medium roundness" floor, without touching rounded-lg/
          // xl/2xl/full, which stay at their normal Tailwind scale.
          DEFAULT: '0.5rem',
        },
      },
    },
  };
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  body { font-family: 'IBM Plex Sans Thai', sans-serif; }
  .font-tabular { font-variant-numeric: tabular-nums; }
  [x-cloak] { display: none !important; }

  /* KPI stat card: gradient tile with a large faint watermark icon. */
  .stat-card { position: relative; overflow: hidden; }
  .stat-card .stat-icon { position: absolute; right: 0.75rem; bottom: 0.25rem; opacity: .18; }
  .stat-card .stat-icon svg { width: 3.25rem; height: 3.25rem; }

  /* Status pill: soft background + a small dot in the same color as the text. */
  .status-pill { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.7rem 0.25rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 500; line-height: 1; vertical-align: middle; }
  .status-pill::before { content: ''; width: 0.375rem; height: 0.375rem; border-radius: 999px; background: currentColor; }

  /* DataTables controls (dashboard/report tables): re-skin its default plain
     HTML to match the app's inputs/pagination instead of the stock look. */
  .dataTables_wrapper .dataTables_length select,
  .dataTables_wrapper .dataTables_filter input {
    border: 1px solid #AEB8C8; border-radius: 8px; padding: 0.35rem 0.6rem; font-size: 0.875rem; margin-left: 0.5rem;
  }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter,
  .dataTables_wrapper .dataTables_info {
    color: #64748B; font-size: 0.8125rem;
  }
  /* Prefixed with `html` purely to out-rank DataTables' own same-specificity
     !important rules for these buttons — its stylesheet loads per-page via
     $extraHead, after this block, so on equal specificity it would win the
     cascade tie and silently put its default dark text back on our button
     (that's why the current-page number was unreadable against the blue). */
  html .dataTables_wrapper .dataTables_paginate .paginate_button {
    border-radius: 8px !important; border: 1px solid transparent !important; margin-left: 2px; padding: 0.3rem 0.65rem !important; color: #172033 !important;
  }
  html .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  html .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #1D4ED8 !important; border-color: #1D4ED8 !important; color: #fff !important;
  }
  html .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #EFF6FF !important; border-color: #EFF6FF !important; color: #172033 !important;
  }
  html .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
  html .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
    background: transparent !important; color: #AEB8C8 !important;
  }
  table.dataTable thead th { position: relative; }
  /* Tighter rows for admin master-data tables (DataTables' 8px cell padding
     out-ranks Tailwind's py-* utilities, so it has to be overridden here). */
  html table.dataTable.table-compact tbody td { padding-top: 0.375rem; padding-bottom: 0.375rem; }

  /* Mobile: tables read cramped at full size once the sidebar eats into the
     viewport width. */
  @media (max-width: 640px) {
    table { font-size: .82rem; }
  }

  /* Print: keep the checklist data, drop the chrome around it. */
  @media print {
    header, .no-print { display: none !important; }
    main { padding: 0 !important; overflow: visible !important; }
    body, .h-screen { height: auto !important; overflow: visible !important; }
    .shadow-soft, .shadow-softLg, [class*="shadow-"] { box-shadow: none !important; border: 1px solid #E8EDF3 !important; }
  }
</style>
