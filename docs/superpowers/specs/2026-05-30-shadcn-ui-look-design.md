# Design Specification: Shadcn UI Look-and-Feel Overhaul

## Goal
Redesign the aesthetic of the NK-Core VPN Web Management Panel to match the clean, modern, and minimalist look of **Shadcn UI** and **Shadcn Admin**, without rewriting the frontend in React.

## Proposed Changes

### 1. Typography & Font Standard
*   **UI Text**: Use **Inter** exclusively for all standard user interface elements (including headers, buttons, navigation, and badges). Remove *Space Grotesk* and *Geist Sans* from general UI copy.
*   **Monospace Copy**: Restrict monospace fonts (e.g. `Geist Mono` or browser monospace) strictly to functional technical data:
    *   IP addresses
    *   WireGuard public/private keys
    *   Configuration snippets
    *   Logs

### 2. Design Tokens (`public/css/tokens.css`)

#### Color System HSL Variables
We will replace existing slate/cyber colors with the default Shadcn Zinc HSL values.

```css
:root {
    --background: 0 0% 100%;
    --foreground: 240 10% 3.9%;
    --card: 0 0% 100%;
    --card-foreground: 240 10% 3.9%;
    --popover: 0 0% 100%;
    --popover-foreground: 240 10% 3.9%;
    --primary: 240 5.9% 10%;
    --primary-foreground: 0 0% 98%;
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
    --ring: 240 5.9% 10%;
    
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
    --color-primary-light: hsl(var(--accent));
    
    --color-sidebar: hsl(var(--background));
    --color-sidebar-text: hsl(var(--foreground));
    --color-sidebar-border: hsl(var(--border));
    --color-sidebar-hover: hsl(var(--accent));
}

.dark {
    --background: 240 10% 3.9%;
    --foreground: 0 0% 98%;
    --card: 240 10% 3.9%;
    --card-foreground: 0 0% 98%;
    --popover: 240 10% 3.9%;
    --popover-foreground: 0 0% 98%;
    --primary: 0 0% 98%;
    --primary-foreground: 240 5.9% 10%;
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
    --ring: 240 4.9% 83.9%;

    /* Semantic Status Color HSL Tokens - Dark */
    --success: 142.1 70.6% 45.3%; /* Vibrant Emerald */
    --success-foreground: 144 61% 9%;
    --warning: 37.9 92.1% 50.2%; /* Amber 500 */
    --warning-foreground: 240 5.9% 10%;
    --destructive-status: 346.8 84.1% 50.2%; /* Rose 500 */
    --destructive-status-foreground: 355.6 100% 97.3%;
    --offline: 240 5% 64.9%;
    --offline-foreground: 240 3.7% 15.9%;
}
```

### 3. Tailwind Configuration (`tailwind.config.js`)
Update key sections in `tailwind.config.js` to register new tokens:

```javascript
module.exports = {
  // ...
  theme: {
    extend: {
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
  }
}
```

### 4. Components & Layout Overrides (`public/css/app.css`)

#### Layout & General
*   **Fonts**: Force `h1`, `h2`, `h3`, `h4`, and `.font-brand` to fall back to `'Inter', sans-serif`. Only IPs, keys, code-like blocks, and logs retain `Geist Mono`.
*   **Card Panels (`.panel`)**: Remove card shadow transitions and use a flat, border-focused design:
    ```css
    .panel {
        background: hsl(var(--card));
        color: hsl(var(--card-foreground));
        border: 1px solid hsl(var(--border));
        border-radius: var(--radius);
        box-shadow: none; /* Rely strictly on borders */
    }
    ```

#### Sidebar Navigation
*   **Subtle Active State**: Active sidebar items will be subtly highlighted with an accent color rather than a heavy left-border stripe.
    ```css
    .sidebar-item.active {
        background: hsl(var(--accent));
        color: hsl(var(--accent-foreground));
        border-left: none; /* Remove legacy red/blue border */
        margin-left: 0.75rem;
        padding-left: 1.25rem;
    }
    ```

#### Buttons & Inputs
*   **Buttons**: Remove translates and heavy hover shadows. Ensure border-radius uses `rounded-md` standard.
*   **Inputs**: Apply `border border-input` and default focus ring styling `focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2`.

#### Data Tables
*   Remove all background zebra striping (`even:bg-slate-50/50` or custom classes).
*   Add a subtle hover background to rows.
*   Separate rows using thin borders.
*   Maintain compact padding density for data tables.
    ```css
    table {
        width: 100%;
        border-collapse: collapse;
    }
    tr {
        border-bottom: 1px solid hsl(var(--border));
    }
    tbody tr:hover {
        background: hsl(var(--muted));
    }
    th, td {
        padding: 0.75rem 1rem;
        text-align: left;
    }
    ```

#### Semantic Status Badges
*   Unify state badges (e.g. active, idle, error, warning) to use semantic status colors defined by our CSS variables (`--success`, `--warning`, `--destructive-status`, `--offline`) in HSL.
*   Badges will be rendered using solid subtle tones:
    ```css
    .badge-active {
        background-color: hsla(var(--success), 0.1);
        border: 1px solid hsla(var(--success), 0.2);
        color: hsl(var(--success));
    }
    /* Rest follows same pattern for warning, error, idle, offline statuses */
    ```

## Dark-Mode-First Validation
Because dark mode is the primary theme target for system administrators:
*   Ensure table borders use high-contrast dark lines (`hsl(var(--border))`).
*   Verify telemetry charts (Chart.js charts) synchronize with dark background values.
*   Check form inputs and select field popups have legible contrast against `hsl(var(--card))` in dark mode.

## Verification Plan
*   **Compile CSS**: Run `npm run build` or the corresponding Tailwind compiler to generate `public/css/output.css`.
*   **Manual Inspection**: Visually verify dashboard views, node tables, server management tables, and forms.
