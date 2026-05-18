## 2024-XX-XX - Icon-only buttons lacking ARIA labels
**Learning:** Several icon-only buttons in the application (like in `proxies.twig` and `servers/view.twig`) have `title` attributes but lack proper `aria-label` attributes, which impairs accessibility for screen reader users. `title` attributes are not sufficient for a11y on their own in many cases.
**Action:** Add `aria-label` attributes to icon-only buttons matching their title or providing a clear description of the action.
