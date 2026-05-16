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
          base: '#0c0e17',
          panel: '#11131c',
          hover: '#1a1c26',
          border: '#232530',
        },
        content: {
          primary: '#f8fafc',
          secondary: '#94a3b8',
          muted: '#475569',
        },
        primary: {
          DEFAULT: '#0ea5e9',
          hover: '#0284c7',
          light: 'rgba(14, 165, 233, 0.15)',
        },
        success: '#10b981',
        warning: '#f59e0b',
        error: '#f43f5e',
      },
      boxShadow: {
        'tactical': '0 1px 2px 0 rgba(0, 0, 0, 0.5)',
      }
    }
  },
  plugins: [],
}
