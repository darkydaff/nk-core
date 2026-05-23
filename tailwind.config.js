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
        header: ['Space Grotesk', 'sans-serif'],
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
        },
        success: 'var(--color-status-active)',
        warning: 'var(--color-status-warning)',
        error: 'var(--color-status-error)',
      },
      boxShadow: {
        'tactical': '0 1px 2px 0 rgba(0, 0, 0, 0.5)',
      }
    }
  },
  plugins: [],
}
