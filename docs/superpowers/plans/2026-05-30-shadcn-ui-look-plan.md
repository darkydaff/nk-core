# Shadcn UI Look-and-Feel Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the NK-Core VPN Web Management Panel interface to match the modern, flat, border-focused, and minimalist look of Shadcn UI and Shadcn Admin, while retaining a branded sky-blue accent and keeping legacy compatibility.

**Architecture:** We will replace custom tokens in `tokens.css` with zinc-based HSL variables + compatibility mappings, update `tailwind.config.js` to map these HSL variables to standard Tailwind classes, and rewrite component and element rules in `app.css` (for headers, cards, tables, active sidebar items, inputs, buttons, focus outlines, and badges).

**Tech Stack:** Tailwind CSS 3.4, Custom CSS, Twig Templates

---

### Task 1: Update Design Tokens in tokens.css

**Files:**
- Modify: `public/css/tokens.css`

- [ ] **Step 1: Replace tokens.css with the new Zinc HSL variables and compatibility layer**
  Overwrite `public/css/tokens.css` to contain:
  ```css
  /* public/css/tokens.css */

  :root {
      /* ─── Neutral Zinc Theme ─── */
      --background: 0 0% 100%;
      --foreground: 240 10% 3.9%;
      --card: 0 0% 100%;
      --card-foreground: 240 10% 3.9%;
      --popover: 0 0% 100%;
      --popover-foreground: 240 10% 3.9%;
      
      /* ─── Branded Primary Color (Sky Blue) ─── */
      --primary: 199 89% 48%; /* Sky 500 */
      --primary-foreground: 0 0% 100%; /* Pure White */
      
      --secondary: 240 4.8% 95.9%;
      --secondary-foreground: 240 5.9% 10%;
      --muted: 240 4.8% 95.9%;
      --muted-foreground: 240 3.8% 46.1%;
      --accent: 240 4.8% 95.9%;
      --accent-foreground: 240 5.9% 10%;
      --destructive: 0 84.2% 60.2%;
      --destructive-foreground: 0 0% 98%;
      --border: 240 5.9% 90%;
      --input: 240 5.9% 90%;
      --ring: 199 89% 48%; /* Matches primary brand */
      
      /* Radius Token */
      --radius: 0.5rem;

      /* Semantic Status Color HSL Tokens */
      --success: 142.1 76.2% 36.3%; /* Emerald 600 */
      --success-foreground: 355.6 100% 97.3%;
      --warning: 37.9 92.1% 50.2%; /* Amber 500 */
      --warning-foreground: 240 5.9% 10%;
      --destructive-status: 346.8 84.1% 50.2%; /* Rose 500 */
      --destructive-status-foreground: 355.6 100% 97.3%;
      --offline: 240 3.8% 46.1%; /* Zinc 500 */
      --offline-foreground: 240 4.8% 95.9%;

      /* Chart HSL Tokens */
      --chart-1: 199 89% 48%; /* Branded Sky */
      --chart-2: 262.1 83.3% 57.8%; /* Violet */
      --chart-3: 346.8 77.2% 49.8%; /* Rose */
      --chart-4: 47.9 95.8% 51.2%; /* Amber */
      --chart-5: 142.1 76.2% 36.3%; /* Emerald */

      /* Legacy Compatibility Layer mappings */
      --color-surface-base: hsl(var(--background));
      --color-surface-panel: hsl(var(--card));
      --color-surface-hover: hsl(var(--accent));
      --color-surface-border: hsl(var(--border));
      --color-content-primary: hsl(var(--foreground));
      --color-content-secondary: hsl(var(--muted-foreground));
      --color-content-muted: hsl(var(--muted-foreground));
      --color-primary: hsl(var(--primary));
      --color-primary-hover: hsl(var(--primary));
      --color-primary-light: hsl(var(--primary) / 0.1);
      
      --color-sidebar: hsl(var(--background));
      --color-sidebar-text: hsl(var(--foreground));
      --color-sidebar-border: hsl(var(--border));
      --color-sidebar-hover: hsl(var(--accent));
  }

  html {
      color-scheme: light dark;
  }

  html.dark {
      --background: 240 10% 3.9%;
      --foreground: 0 0% 98%;
      --card: 240 10% 3.9%;
      --card-foreground: 0 0% 98%;
      --popover: 240 10% 3.9%;
      --popover-foreground: 0 0% 98%;
      
      /* Branded Primary - lightened for dark mode readability */
      --primary: 199 89% 54%; 
      --primary-foreground: 240 10% 3.9%;
      
      --secondary: 240 3.7% 15.9%;
      --secondary-foreground: 0 0% 98%;
      --muted: 240 3.7% 15.9%;
      --muted-foreground: 240 5% 64.9%;
      --accent: 240 3.7% 15.9%;
      --accent-foreground: 0 0% 98%;
      --destructive: 0 62.8% 30.6%;
      --destructive-foreground: 0 0% 98%;
      --border: 240 3.7% 15.9%;
      --input: 240 3.7% 15.9%;
      --ring: 199 89% 54%;

      /* Semantic Status Color HSL Tokens - Dark */
      --success: 142.1 70.6% 45.3%; /* Vibrant Emerald */
      --success-foreground: 144 61% 9%;
      --warning: 37.9 92.1% 50.2%; /* Amber 500 */
      --warning-foreground: 240 5.9% 10%;
      --destructive-status: 346.8 84.1% 50.2%; /* Rose 500 */
      --destructive-status-foreground: 355.6 100% 97.3%;
      --offline: 240 5% 64.9%;
      --offline-foreground: 240 3.7% 15.9%;

      /* Chart HSL Tokens - Dark */
      --chart-1: 199 89% 54%;
      --chart-2: 270.7 91% 65.1%;
      --chart-3: 343.4 79.7% 54.7%;
      --chart-4: 47.9 95.8% 51.2%;
      --chart-5: 142.1 70.6% 45.3%;
      
      color-scheme: dark;
  }
  ```

- [ ] **Step 2: Commit tokens.css changes**
  Run:
  ```bash
  git add public/css/tokens.css
  git commit -m "style: update design tokens to use HSL zinc base with brand accent and chart colors"
  ```

---

### Task 2: Update tailwind.config.js Mappings

**Files:**
- Modify: `tailwind.config.js`

- [ ] **Step 1: Map HSL tokens and offset border-radius values**
  Modify `tailwind.config.js` to map HSL properties and extend radii properties. Replace the existing content with:
  ```javascript
  module.exports = {
    content: [
      "./templates/**/*.twig",
      "./public/**/*.php",
      "./inc/**/*.php",
      "./public/js/**/*.js"
    ],
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: {
          sans: ['Inter', 'sans-serif'],
          mono: ['Geist Mono', 'monospace'],
        },
        borderRadius: {
          lg: "var(--radius)",
          md: "calc(var(--radius) - 2px)",
          sm: "calc(var(--radius) - 4px)"
        },
        colors: {
          border: "hsl(var(--border))",
          input: "hsl(var(--input))",
          ring: "hsl(var(--ring))",
          background: "hsl(var(--background))",
          foreground: "hsl(var(--foreground))",
          primary: {
            DEFAULT: "hsl(var(--primary))",
            foreground: "hsl(var(--primary-foreground))",
          },
          secondary: {
            DEFAULT: "hsl(var(--secondary))",
            foreground: "hsl(var(--secondary-foreground))",
          },
          destructive: {
            DEFAULT: "hsl(var(--destructive))",
            foreground: "hsl(var(--destructive-foreground))",
          },
          muted: {
            DEFAULT: "hsl(var(--muted))",
            foreground: "hsl(var(--muted-foreground))",
          },
          accent: {
            DEFAULT: "hsl(var(--accent))",
            foreground: "hsl(var(--accent-foreground))",
          },
          popover: {
            DEFAULT: "hsl(var(--popover))",
            foreground: "hsl(var(--popover-foreground))",
          },
          card: {
            DEFAULT: "hsl(var(--card))",
            foreground: "hsl(var(--card-foreground))",
          },
          success: {
            DEFAULT: "hsl(var(--success))",
            foreground: "hsl(var(--success-foreground))",
          },
          warning: {
            DEFAULT: "hsl(var(--warning))",
            foreground: "hsl(var(--warning-foreground))",
          },
        }
      }
    },
    plugins: [],
  }
  ```

- [ ] **Step 2: Commit config modifications**
  Run:
  ```bash
  git add tailwind.config.js
  git commit -m "config: map shadcn HSL CSS custom variables and radii offsets in tailwind config"
  ```

---

### Task 3: Overhaul Component Layouts and Element Rules in app.css

**Files:**
- Modify: `public/css/app.css`

- [ ] **Step 1: Apply global focus-visible normalizer, typography limits, clean sidebar states, border-first panels, table grids, and slash HSL badge structures**
  Modify `public/css/app.css` to clean up old design systems:
  *   Define a global `:focus-visible { outline: none; }` normalizer.
  *   Restructure headers (`h1`, `h2`, `h3`, `h4`, and `.font-brand`) to use `'Inter', sans-serif` instead of Space Grotesk.
  *   Update `.sidebar-item` and `.sidebar-item.active` to remove sidebar active layout shifting (use matching padding/margin).
  *   Update `.panel` to remove shadow transitions and use flat HSL border styling.
  *   Define standard table styles (`table`, `tr`, `tbody tr:hover`, `th`, `td`).
  *   Rewrite badges (`.badge-active`, `.badge-error`, `.badge-warning`, `.badge-muted`) using HSL slash-opacity syntax (`hsl(var(...) / opacity)`).
  *   Clean up buttons (`.btn-primary`, `.btn-secondary`, `.btn-action`) to remove scale-ups, translates, and legacy shadows.

  Replace the contents of `public/css/app.css` from line 1 to the end with:
  ```css
  /* public/css/app.css */

  /* Global browser focus normalization */
  :focus-visible {
      outline: none;
  }

  body {
      font-family: 'Inter', sans-serif;
      background-color: var(--color-surface-base);
      color: var(--color-content-primary);
      min-height: 100vh;
      display: flex;
      overflow-x: hidden;
      letter-spacing: -0.011em;
  }

  h1, h2, h3, h4, .font-brand {
      font-family: 'Inter', sans-serif;
  }

  /* ── Sidebar ── */
  .sidebar {
      width: var(--sidebar-width);
      background: var(--color-sidebar);
      color: var(--color-sidebar-text);
      height: 100vh;
      position: fixed;
      left: 0;
      top: 0;
      z-index: 100;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      flex-direction: column;
      border-right: 1px solid var(--color-surface-border);
  }

  .sidebar-item {
      display: flex;
      align-items: center;
      padding: 0.75rem 1rem;
      margin: 0.25rem 0.75rem;
      border-radius: var(--radius);
      color: var(--color-sidebar-text);
      opacity: 0.7;
      transition: background-color, opacity, color 0.2s ease;
      font-size: 0.875rem;
      font-weight: 500;
  }

  .sidebar-item:hover {
      opacity: 1;
      background: var(--color-sidebar-hover);
  }

  .sidebar-item.active {
      opacity: 1;
      background: hsl(var(--accent));
      color: hsl(var(--accent-foreground));
  }

  .sidebar-logo {
      padding: 1.5rem 1.25rem;
      display: flex;
      align-items: center;
      font-weight: 700;
      letter-spacing: -0.025em;
  }

  /* ── Main Content ── */
  .main-wrapper {
      flex: 1;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      transition: width 0.3s ease;
      width: 100%;
  }

  @media (min-width: 1025px) {
      body.has-sidebar .main-wrapper {
          margin-left: var(--sidebar-width);
          width: calc(100% - var(--sidebar-width));
      }
  }

  .top-bar {
      height: 64px;
      background: var(--color-surface-panel);
      border-bottom: 1px solid var(--color-surface-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
      position: sticky;
      top: 0;
      z-index: 90;
  }

  /* ── UI Components (Flat Border-Focused Design) ── */
  .panel {
      background: hsl(var(--card));
      color: hsl(var(--card-foreground));
      border: 1px solid hsl(var(--border));
      border-radius: var(--radius);
      box-shadow: none;
  }

  .btn-primary {
      background: hsl(var(--primary));
      color: hsl(var(--primary-foreground));
      padding: 0.625rem 1.25rem;
      border-radius: var(--radius);
      border: 1px solid transparent;
      font-weight: 500;
      transition: background-color 0.2s ease, border-color 0.2s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
  }

  .btn-primary:hover {
      background: hsl(var(--primary) / 0.9) !important;
  }

  .btn-primary:focus-visible {
      box-shadow: 0 0 0 2px hsl(var(--background)), 0 0 0 4px hsl(var(--ring));
  }

  .bg-primary { background-color: var(--color-primary) !important; }
  .bg-base, .bg-surface, .bg-surface-base { background-color: var(--color-surface-base) !important; }
  .bg-panel, .bg-surface-panel { background-color: var(--color-surface-panel) !important; }
  .bg-surface-hover { background-color: var(--color-surface-hover) !important; }
  .hover\:bg-surface-hover:hover { background-color: var(--color-surface-hover) !important; }
  
  .border-default, .border-surface-border { border-color: var(--color-surface-border) !important; }
  .hover\:border-slate-500:hover { border-color: hsl(var(--border)) !important; }
  
  .bg-status-active { background-color: hsl(var(--success)) !important; }
  .text-primary { color: var(--color-primary) !important; }
  .text-secondary, .text-content-secondary { color: var(--color-content-secondary) !important; }
  .text-muted, .text-content-muted { color: var(--color-content-muted) !important; }
  .text-status-active { color: hsl(var(--success)) !important; }

  .btn-secondary {
      background: hsl(var(--secondary));
      border: 1px solid hsl(var(--border));
      color: hsl(var(--secondary-foreground));
      padding: 0.625rem 1.25rem;
      border-radius: var(--radius);
      font-weight: 500;
      transition: background-color 0.2s ease, border-color 0.2s ease;
  }

  .btn-secondary:hover {
      background: hsl(var(--accent)) !important;
      border-color: hsl(var(--border)) !important;
      color: hsl(var(--accent-foreground)) !important;
  }

  .btn-secondary:focus-visible {
      box-shadow: 0 0 0 2px hsl(var(--background)), 0 0 0 4px hsl(var(--ring));
  }

  /* ── Status Badges (HSL Opacity Slash Syntax) ── */
  .badge-active {
      background-color: hsl(var(--success) / 0.1);
      border: 1px solid hsl(var(--success) / 0.2);
      color: hsl(var(--success));
  }

  .badge-error {
      background-color: hsl(var(--destructive-status) / 0.1);
      border: 1px solid hsl(var(--destructive-status) / 0.2);
      color: hsl(var(--destructive-status));
  }

  .badge-warning {
      background-color: hsl(var(--warning) / 0.1);
      border: 1px solid hsl(var(--warning) / 0.2);
      color: hsl(var(--warning));
  }

  .badge-muted {
      background-color: hsl(var(--offline) / 0.1);
      border: 1px solid hsl(var(--offline) / 0.2);
      color: hsl(var(--offline));
  }

  .badge-primary {
      background-color: hsl(var(--primary) / 0.1);
      border: 1px solid hsl(var(--primary) / 0.2);
      color: hsl(var(--primary));
  }

  /* Telemetry state badges */
  .badge-state {
      padding: 4px 12px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .badge-state.active {
      background-color: hsl(var(--success) / 0.1);
      border: 1px solid hsl(var(--success) / 0.2);
      color: hsl(var(--success));
  }
  .badge-state.idle {
      background-color: hsl(var(--offline) / 0.1);
      border: 1px solid hsl(var(--offline) / 0.2);
      color: hsl(var(--offline));
  }
  .badge-state.backpressure {
      background-color: hsl(var(--destructive-status) / 0.1);
      border: 1px solid hsl(var(--destructive-status) / 0.2);
      color: hsl(var(--destructive-status));
      animation: pulse 2s infinite;
  }

  .input-field {
      background: hsl(var(--background));
      border: 1px solid hsl(var(--input));
      color: hsl(var(--foreground));
      border-radius: var(--radius);
      padding: 0.75rem 1rem;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      appearance: none;
      line-height: 1.5;
      width: 100%;
      font-size: 0.875rem;
  }

  .input-with-icon {
      padding-left: 3.25rem !important;
  }

  .input-field option {
      background: hsl(var(--card));
      color: hsl(var(--card-foreground));
  }

  .input-field:focus-visible {
      border-color: hsl(var(--ring));
      box-shadow: 0 0 0 2px hsl(var(--background)), 0 0 0 4px hsl(var(--ring));
  }

  /* ── Data Tables ── */
  table {
      width: 100%;
      border-collapse: collapse;
  }

  tr {
      border-bottom: 1px solid hsl(var(--border));
  }

  tbody tr {
      transition: background-color 0.15s ease;
  }

  tbody tr:hover {
      background-color: hsl(var(--muted)) !important;
  }

  th, td {
      padding: 0.75rem 1rem;
      text-align: left;
  }

  th {
      font-weight: 500;
      color: hsl(var(--muted-foreground));
      font-size: 0.875rem;
  }

  td {
      color: hsl(var(--foreground));
      font-size: 0.875rem;
  }

  /* ── Animations ── */
  @keyframes slideIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
  }

  .animate-in {
      animation: slideIn 0.4s ease forwards;
  }

  /* ── Responsive ── */
  @media (max-width: 1024px) {
      .sidebar {
          transform: translateX(-100%);
      }
      .sidebar.open {
          transform: translateX(0);
      }
      .main-wrapper {
          margin-left: 0 !important;
          width: 100% !important;
          overflow-x: hidden;
      }
      .sidebar-overlay {
          display: none;
          position: fixed;
          inset: 0;
          background: rgba(0,0,0,0.6);
          z-index: 95;
      }
      .sidebar-overlay.open {
          display: block;
      }
      .top-bar {
          padding: 0 1rem;
          height: 56px;
      }
      .panel {
          padding: 1rem !important;
          border-radius: var(--radius);
      }
      .grid {
          gap: 1rem !important;
      }
  }

  /* Toast Styling managed in JS */
  .toast {
      background: hsl(var(--card));
      border: 1px solid hsl(var(--border));
      color: hsl(var(--foreground));
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
      padding: 1rem 1.25rem;
      border-radius: var(--radius);
      display: flex;
      align-items: center;
      min-width: 300px;
      animation: toastIn 0.3s ease;
  }

  @keyframes toastIn {
      from { opacity: 0; transform: translateX(20px); }
      to { opacity: 1; transform: translateX(0); }
  }

  /* ── Custom Scrollbar ── */
  ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
  }

  ::-webkit-scrollbar-track {
      background: transparent;
  }

  ::-webkit-scrollbar-thumb {
      background-color: hsl(var(--border));
      border: 2px solid transparent;
      background-clip: padding-box;
      border-radius: 10px;
  }

  ::-webkit-scrollbar-thumb:hover {
      background-color: hsl(var(--muted-foreground));
  }

  /* Firefox Support */
  * {
      scrollbar-width: thin;
      scrollbar-color: hsl(var(--border)) transparent;
  }

  /* ─── Client Action Buttons ─── */
  .btn-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.375rem;
      color: hsl(var(--muted-foreground)) !important;
      transition: background-color 0.2s, color 0.2s, transform 0.2s !important;
      background: transparent;
  }

  .btn-action:focus-visible {
      box-shadow: 0 0 0 2px hsl(var(--background)), 0 0 0 4px hsl(var(--ring));
  }

  .btn-action:hover {
      background: hsl(var(--accent)) !important;
      color: hsl(var(--foreground)) !important;
  }

  .btn-action-sync:hover {
      color: hsl(var(--primary)) !important;
  }

  .btn-action-revoke:hover {
      color: hsl(var(--warning)) !important;
  }

  .btn-action-delete:hover {
      color: hsl(var(--destructive)) !important;
  }

  .btn-action-restore {
      color: hsl(var(--success)) !important;
  }

  .btn-action-restore:hover {
      color: hsl(var(--success) / 0.8) !important;
  }

  .btn-edit:focus-visible {
      box-shadow: 0 0 0 2px hsl(var(--background)), 0 0 0 4px hsl(var(--ring));
  }

  .btn-edit {
      background: hsl(var(--card)) !important;
      color: hsl(var(--muted-foreground)) !important;
      border: 1px solid hsl(var(--border));
      padding: 0.25rem 0.5rem;
      border-radius: calc(var(--radius) - 2px);
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      transition: background-color 0.2s, border-color 0.2s, color 0.2s, box-shadow 0.2s !important;
  }

  .btn-edit:hover {
      background: hsl(var(--primary)) !important;
      color: hsl(var(--primary-foreground)) !important;
      border-color: hsl(var(--primary)) !important;
  }

  .proxy-row { content-visibility: auto; }
  .server-card { content-visibility: auto; }
  ```

- [ ] **Step 2: Commit app.css overhaul**
  Run:
  ```bash
  git add public/css/app.css
  git commit -m "style: overhaul app.css component classes with focus-visible reset, tables, border-first panels, and non-shifting sidebar"
  ```

---

### Task 4: Re-compile Tailwind CSS and Verify Output

**Files:**
- Test: `public/css/output.css`

- [ ] **Step 1: Run tailwind compilation**
  Run:
  ```bash
  npx tailwindcss -i ./public/css/input.css -o ./public/css/output.css
  ```
  Expected: Rebuild runs successfully and generates new output code in `output.css`.

- [ ] **Step 2: Commit generated compiled CSS**
  Run:
  ```bash
  git add public/css/output.css
  git commit -m "build: re-compile tailwind output.css with updated utility classes"
  ```
