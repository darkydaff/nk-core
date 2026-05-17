## 2024-05-24 - Accessibility labels for icon-only buttons
**Learning:** Found several icon-only buttons on the proxies management page lacking `aria-label`s. This is a common accessibility trap where visual meaning (icons like trash, check, pause) does not translate to screen readers. Adding localized or default aria-labels instantly improves screen reader support while matching the visual title.
**Action:** Always ensure that any button containing only an icon has a descriptive `aria-label` and `title`.
