module.exports = {
  content: [
    "./templates/**/*.twig",
    "./public/**/*.php",
    "./inc/**/*.php"
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Space Grotesk', 'sans-serif'],
        mono: ['Geist Mono', 'monospace'],
        brand: ['Geist Mono', 'monospace'],
      },
      colors: {
        surface: {
          base: 'var(--color-surface-base)',
          panel: 'var(--color-surface-panel)',
          hover: 'var(--color-surface-hover)',
          border: 'var(--color-surface-border)',
        },
        content: {
          primary: 'var(--color-content-primary)',
          secondary: 'var(--color-content-secondary)',
          muted: 'var(--color-content-muted)',
        },
        primary: {
          DEFAULT: 'var(--color-primary)',
          hover: 'var(--color-primary-hover)',
          light: 'var(--color-primary-light)',
        }
      }
    }
  },
  plugins: [],
}
