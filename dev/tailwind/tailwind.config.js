/**
 * Tailwind build configuration (development only – the compiled file
 * assets/css/app.css is committed, so the server needs no build step).
 *
 *   npm install -D tailwindcss@3
 *   npx tailwindcss -c dev/tailwind/tailwind.config.js -i dev/tailwind/input.css -o assets/css/app.css --minify
 */
const path = require('path');
const root = path.resolve(__dirname, '../..');

module.exports = {
  content: [
    path.join(root, 'app/Views/**/*.php'),
    path.join(root, 'app/helpers.php'),
    path.join(root, 'app/Domain/Labels.php'),
    path.join(root, 'app/Docx/DocRenderer.php'),
    path.join(root, 'assets/js/*.js'),
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Vazirmatn', 'Tahoma', 'Segoe UI', 'sans-serif'],
        vazir: ['Vazirmatn', 'Tahoma', 'sans-serif'],
      },
      colors: {
        brand: {
          50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa',
          500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a', 950: '#172554',
        },
      },
      boxShadow: {
        soft: '0 1px 2px rgba(15,23,42,.04), 0 4px 16px rgba(15,23,42,.05)',
      },
    },
  },
  plugins: [],
};
