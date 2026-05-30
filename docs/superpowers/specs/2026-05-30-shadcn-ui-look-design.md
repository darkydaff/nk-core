# Design Specification: Shadcn UI Look-and-Feel Overhaul

## Goal
Redesign the aesthetic of the NK-Core VPN Web Management Panel to match the clean, modern, and minimalist look of **Shadcn UI** and **Shadcn Admin**, without rewriting the frontend in React.

## Proposed Changes

### 1. Typography
*   Change body and header fonts from a mix of *Space Grotesk* and *Inter* to only **Inter** (already loaded via Google Fonts) for a cleaner and more professional sans-serif layout.
*   Update `app.css` to change the `font-family` assignments for `h1`, `h2`, `h3`, `h4`, and `.font-brand` to `Inter`.

### 2. Design Tokens (`public/css/tokens.css`)
*   Replace old slate/cyber colors with the default Shadcn Zinc HSL values (light and dark modes).
*   Add mapping definitions for existing color custom properties (e.g. `--color-surface-base`, `--color-surface-panel`, etc.) pointing to the new HSL variables. This maintains perfect backwards compatibility with existing Twig templates and custom styles.

### 3. Tailwind Configuration (`tailwind.config.js`)
*   Map the new HSL color variables (`border`, `input`, `ring`, `background`, `foreground`, `primary`, `secondary`, `destructive`, `muted`, `accent`, `popover`, `card`) to standard Tailwind CSS utilities.

### 4. Components & Layout Styles (`public/css/app.css`)
*   **Sidebar**:
    *   Change active state highlight from a left-hand border stripe with sky background to a flat, sleek background highlight (`bg-secondary`/`bg-accent`) matching Shadcn Admin.
    *   Set border to thin `border-border` and remove shadows.
*   **Panels (Cards)**:
    *   Change shadow to a very soft `shadow-sm` and radius to a clean `rounded-md` (0.5rem).
*   **Buttons**:
    *   **Primary**: Flat, high-contrast block shape (`bg-primary text-primary-foreground`) with transition and no translateY hover effects.
    *   **Secondary**: Clean white/dark-zinc fill, matching border (`border-input`), and secondary foreground text.
*   **Inputs**:
    *   Configure focus ring using `ring-2 ring-ring ring-offset-2`.
*   **Badges**:
    *   Clean up state badges to use lighter, less saturated backgrounds with clean text coloring.

## Verification Plan
*   **Compile CSS**: Run the Tailwind compiler to generate `public/css/output.css`.
*   **Manual Inspection**: Visually verify dashboard views (dashboard, servers, settings) to ensure all elements render with the new theme correctly.
